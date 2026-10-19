<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FolioLeido;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Captura\HistorialBultos;
use App\Finanzas\Models\Hecho;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Cada folio aprobado en un proceso de prefrío. Si una corrección lo deja en reproceso,
 * el hecho queda anulado y el nuevo proceso aprobado es otro hecho.
 */
final class Prefrio extends FuenteTabla
{
    public function clave(): string
    {
        return 'prefrio';
    }

    public function nombre(): string
    {
        return 'Prefrío';
    }

    public function descripcion(): string
    {
        return 'Pallet o saldo aprobado en un proceso de prefrío.';
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
        return 'pf.id';
    }

    protected function columnasCambio(): array
    {
        return ['pf.updated_at', 'p.updated_at'];
    }

    protected function consulta(ContextoCaptura $contexto): Builder
    {
        return $contexto->lector->tabla('procesos_prefrio_folios', 'pf')
            ->join('procesos_prefrio as p', 'p.id', '=', 'pf.proceso_prefrio_id')
            ->leftJoin('tuneles_prefrio as t', 't.id', '=', 'p.tunel_prefrio_id')
            ->select([
                'pf.id', 'pf.folio_id', 'pf.estado', 'pf.temperatura_inicial', 'pf.temperatura_final', 'pf.updated_at',
                'p.codigo', 'p.temporada_id', 'p.iniciado_at', 'p.pendiente_verificacion_at', 'p.finalizado_at',
                't.codigo as tunel',
            ]);
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        $folios = FolioLeido::leer($contexto, $filas->pluck('folio_id')->all());
        $historial = HistorialBultos::leer($contexto, array_keys($folios));

        foreach ($filas as $fila) {
            $folio = $folios[$fila->folio_id] ?? null;
            if ($folio === null) {
                continue;
            }

            $iniciado = ContextoCaptura::fecha($fila->iniciado_at);
            $enfriado = ContextoCaptura::fecha($fila->pendiente_verificacion_at);
            $ocurrido = ContextoCaptura::fecha($fila->finalizado_at) ?? $enfriado ?? ContextoCaptura::fecha($fila->updated_at);
            $medidas = $historial->delServicio($folio, $ocurrido);

            yield new HechoCapturado(
                origenId: $fila->id,
                estado: $fila->estado === 'aprobado' ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                ocurridoAt: $ocurrido,
                clienteId: $folio->clienteId,
                clienteNombre: $folio->clienteNombre,
                temporadaId: $fila->temporada_id ?? $folio->temporadaId,
                temporadaCodigo: $contexto->temporadaCodigo($fila->temporada_id ?? $folio->temporadaId),
                referencia: $folio->numero,
                medidas: $medidas,
                datos: [
                    ...$folio->datos(),
                    'tipo_bulto' => $medidas['pallets'] ? 'pallet' : 'saldo',
                    'proceso' => $fila->codigo,
                    'tunel' => $fila->tunel,
                    'minutos_enfriado' => $iniciado && $enfriado ? (int) $iniciado->diffInMinutes($enfriado) : null,
                    'temperatura_inicial' => $fila->temperatura_inicial === null ? null : (float) $fila->temperatura_inicial,
                    'temperatura_final' => $fila->temperatura_final === null ? null : (float) $fila->temperatura_final,
                ],
            );
        }
    }
}
