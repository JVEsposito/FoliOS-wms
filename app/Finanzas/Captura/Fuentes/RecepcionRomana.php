<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\Hecho;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/** Cada recepción cerrada en la romana: kilos netos, envases y tipo de servicio. */
final class RecepcionRomana extends FuenteTabla
{
    public function clave(): string
    {
        return 'romana_recepcion';
    }

    public function nombre(): string
    {
        return 'Recepciones en romana';
    }

    public function descripcion(): string
    {
        return 'Recepción cerrada en la romana (después del destare).';
    }

    public function medidas(): array
    {
        return [
            'recepciones' => ['nombre' => 'Recepciones', 'unidad' => 'recepción'],
            'kilos_netos' => ['nombre' => 'Kilos netos', 'unidad' => 'kg'],
            'envases' => ['nombre' => 'Envases declarados', 'unidad' => 'envase'],
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
        return $contexto->lector->tabla('recepciones_romana', 'r')
            ->leftJoin('especies_validacion as e', 'e.id', '=', 'r.especie_validacion_id')
            ->select([
                'r.id', 'r.numero_recepcion', 'r.temporada_id', 'r.temporada_codigo_snapshot', 'r.cliente_id',
                'r.cliente_nombre_snapshot', 'r.tipo_recepcion', 'r.tipo_servicio', 'r.estado', 'r.peso_neto',
                'r.cantidad_envases_declarados', 'r.tipo_envase_declarado', 'r.numero_guia_despacho',
                'r.patente_camion', 'r.ingreso_at', 'r.salida_at', 'e.nombre as especie',
            ]);
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        foreach ($filas as $fila) {
            [$clienteId, $clienteNombre] = $contexto->cliente($fila->cliente_id, $fila->cliente_nombre_snapshot);

            // Solo una recepción cerrada es un hecho; si se reabre, deja de serlo.
            yield new HechoCapturado(
                origenId: $fila->id,
                estado: $fila->estado === 'cerrado' ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                ocurridoAt: ContextoCaptura::fecha($fila->ingreso_at),
                clienteId: $clienteId,
                clienteNombre: $clienteNombre,
                temporadaId: $fila->temporada_id,
                temporadaCodigo: $fila->temporada_codigo_snapshot ?? $contexto->temporadaCodigo($fila->temporada_id),
                referencia: $fila->numero_recepcion,
                medidas: [
                    'recepciones' => 1,
                    'kilos_netos' => round((float) $fila->peso_neto, 3),
                    'envases' => (int) $fila->cantidad_envases_declarados,
                ],
                datos: [
                    'tipo_recepcion' => $fila->tipo_recepcion,
                    'tipo_servicio' => $fila->tipo_servicio,
                    'especie' => $fila->especie,
                    'tipo_envase' => $fila->tipo_envase_declarado,
                    'guia' => $fila->numero_guia_despacho,
                    'patente' => $fila->patente_camion,
                    'salida_at' => ContextoCaptura::fecha($fila->salida_at)?->toIso8601String(),
                ],
            );
        }
    }
}
