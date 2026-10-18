<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FolioLeido;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\Hecho;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Cada pallet o saldo despachado en una carga. */
final class Despacho extends FuenteTabla
{
    public function clave(): string
    {
        return 'despacho';
    }

    public function nombre(): string
    {
        return 'Pallets despachados';
    }

    public function descripcion(): string
    {
        return 'Pallet o saldo que salió de la planta en una carga.';
    }

    public function medidas(): array
    {
        return [
            'bultos' => ['nombre' => 'Bultos (pallets y saldos)', 'unidad' => 'bulto'],
            'pallets' => ['nombre' => 'Pallets completos', 'unidad' => 'pallet'],
            'saldos' => ['nombre' => 'Saldos', 'unidad' => 'saldo'],
            'cajas' => ['nombre' => 'Cajas', 'unidad' => 'caja'],
        ];
    }

    protected function columnaId(): string
    {
        return 'cf.id';
    }

    protected function columnasCambio(): array
    {
        return ['cf.updated_at'];
    }

    protected function consulta(ContextoCaptura $contexto): Builder
    {
        return $contexto->lector->tabla('carga_folios', 'cf')
            ->join('cargas as c', 'c.id', '=', 'cf.carga_id')
            ->select([
                'cf.id', 'cf.folio_id', 'cf.estado', 'cf.finalizado_at', 'cf.updated_at',
                'c.id as carga_id', 'c.codigo as carga', 'c.numero_orden_externa', 'c.modalidad_salida',
                'c.patente', 'c.temporada_id',
            ]);
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        $folios = FolioLeido::leer($contexto, $filas->pluck('folio_id')->all());

        foreach ($filas as $fila) {
            $folio = $folios[$fila->folio_id] ?? null;
            if ($folio === null) {
                continue;
            }

            $temporada = $fila->temporada_id ?? $folio->temporadaId;

            yield new HechoCapturado(
                origenId: $fila->id,
                estado: $fila->estado === 'despachado' ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                ocurridoAt: ContextoCaptura::fecha($fila->finalizado_at) ?? ContextoCaptura::fecha($fila->updated_at),
                clienteId: $folio->clienteId,
                clienteNombre: $folio->clienteNombre,
                temporadaId: $temporada,
                temporadaCodigo: $contexto->temporadaCodigo($temporada),
                referencia: $folio->numero,
                medidas: $folio->medidas(),
                datos: [
                    ...$folio->datos(),
                    'carga_id' => $fila->carga_id,
                    'carga' => $fila->carga,
                    'orden_externa' => $fila->numero_orden_externa,
                    'modalidad_salida' => $fila->modalidad_salida,
                    'patente' => $fila->patente,
                ],
            );
        }
    }
}
