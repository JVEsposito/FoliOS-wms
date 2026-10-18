<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\Hecho;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Cada recepción aceptada de fruta embalada en otra planta (RRFE-01), con sus pallets. */
final class RecepcionFrutaEmbalada extends FuenteTabla
{
    public function clave(): string
    {
        return 'recepcion_embalada';
    }

    public function nombre(): string
    {
        return 'Recepción de fruta embalada';
    }

    public function descripcion(): string
    {
        return 'Recepción aceptada de pallets embalados en otra planta.';
    }

    public function medidas(): array
    {
        return [
            'recepciones' => ['nombre' => 'Recepciones', 'unidad' => 'recepción'],
            'bultos' => ['nombre' => 'Bultos (pallets y saldos)', 'unidad' => 'bulto'],
            'pallets' => ['nombre' => 'Pallets completos', 'unidad' => 'pallet'],
            'saldos' => ['nombre' => 'Saldos', 'unidad' => 'saldo'],
            'cajas' => ['nombre' => 'Cajas', 'unidad' => 'caja'],
        ];
    }

    protected function columnaId(): string
    {
        return 'r.id';
    }

    protected function columnasCambio(): array
    {
        return ['r.updated_at'];
    }

    protected function consulta(ContextoCaptura $contexto): Builder
    {
        return $contexto->lector->tabla('recepciones_fruta_embalada', 'r')
            ->leftJoin('plantas_origen as p', 'p.id', '=', 'r.planta_origen_id')
            ->select([
                'r.id', 'r.temporada_id', 'r.cliente_id', 'r.numero_guia', 'r.servicio', 'r.llega_con_prefrio',
                'r.recepcion_at', 'r.estado', 'p.nombre as planta_origen',
            ]);
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        $pallets = $contexto->lector->tabla('recepciones_fruta_embalada_pallets')
            ->whereIn('recepcion_fruta_embalada_id', $filas->pluck('id'))
            ->groupBy('recepcion_fruta_embalada_id')
            ->selectRaw("recepcion_fruta_embalada_id as id, COUNT(*) as bultos, SUM(tipo_bulto = 'pallet') as pallets, SUM(tipo_bulto = 'saldo') as saldos, SUM(cantidad_cajas) as cajas")
            ->get()
            ->keyBy('id');

        foreach ($filas as $fila) {
            [$clienteId, $clienteNombre] = $contexto->cliente($fila->cliente_id);
            $totales = $pallets->get($fila->id);

            yield new HechoCapturado(
                origenId: $fila->id,
                estado: $fila->estado === 'aceptada' ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                ocurridoAt: ContextoCaptura::fecha($fila->recepcion_at),
                clienteId: $clienteId,
                clienteNombre: $clienteNombre,
                temporadaId: $fila->temporada_id,
                temporadaCodigo: $contexto->temporadaCodigo($fila->temporada_id),
                referencia: $fila->numero_guia,
                medidas: [
                    'recepciones' => 1,
                    'bultos' => (int) ($totales->bultos ?? 0),
                    'pallets' => (int) ($totales->pallets ?? 0),
                    'saldos' => (int) ($totales->saldos ?? 0),
                    'cajas' => (int) ($totales->cajas ?? 0),
                ],
                datos: [
                    'guia' => $fila->numero_guia,
                    'servicio' => $fila->servicio,
                    'llega_con_prefrio' => (bool) $fila->llega_con_prefrio,
                    'planta_origen' => $fila->planta_origen,
                ],
            );
        }
    }
}
