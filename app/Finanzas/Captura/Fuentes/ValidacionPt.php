<?php

namespace App\Finanzas\Captura\Fuentes;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Captura\FuenteTabla;
use App\Finanzas\Captura\HechoCapturado;
use App\Finanzas\Models\Hecho;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Cada pallet o saldo aprobado en Validación PT, con las cajas que quedaron validadas.
 * Una corrección crea una versión nueva; una anulación lo deja anulado.
 */
final class ValidacionPt extends FuenteTabla
{
    public function clave(): string
    {
        return 'validacion_pt';
    }

    public function nombre(): string
    {
        return 'Pallets validados';
    }

    public function descripcion(): string
    {
        return 'Pallet o saldo aprobado en Validación PT, con su folio.';
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
        return 'v.id';
    }

    protected function columnasCambio(): array
    {
        return ['v.updated_at'];
    }

    protected function consulta(ContextoCaptura $contexto): Builder
    {
        return $contexto->lector->tabla('validaciones_pallet', 'v')
            ->leftJoin('origenes_validacion as o', 'o.id', '=', 'v.origen_validacion_id')
            ->leftJoin('clientes_validacion as cv', 'cv.id', '=', 'o.cliente_validacion_id')
            ->leftJoin('articulos_validacion as a', 'a.id', '=', 'v.articulo_validacion_id')
            ->leftJoin('categorias_validacion as cat', 'cat.id', '=', 'v.categoria_validacion_id')
            ->select([
                'v.id', 'v.numero_folio', 'v.tipo_bulto', 'v.cantidad_cajas', 'v.temporada_id', 'v.estado',
                'v.resultado', 'v.folio_id', 'v.generado_dispositivo_at', 'v.linea_proceso', 'v.turno',
                'o.cliente as cliente_origen', 'o.marca', 'o.csg', 'cv.cliente_id',
                'a.especie', 'a.variedad', 'a.calibre', 'a.envase', 'cat.nombre as categoria',
            ]);
    }

    protected function mapear(ContextoCaptura $contexto, Collection $filas): iterable
    {
        foreach ($filas as $fila) {
            [$clienteId, $clienteNombre] = $contexto->cliente($fila->cliente_id, $fila->cliente_origen);
            $aprobada = $fila->estado === 'aceptada' && $fila->resultado === 'aprobado' && $fila->folio_id !== null;

            yield new HechoCapturado(
                origenId: $fila->id,
                estado: $aprobada ? Hecho::ESTADO_VIGENTE : Hecho::ESTADO_ANULADO,
                ocurridoAt: ContextoCaptura::fecha($fila->generado_dispositivo_at),
                clienteId: $clienteId,
                clienteNombre: $clienteNombre,
                temporadaId: $fila->temporada_id,
                temporadaCodigo: $contexto->temporadaCodigo($fila->temporada_id),
                referencia: $fila->numero_folio,
                medidas: [
                    'bultos' => 1,
                    'pallets' => $fila->tipo_bulto === 'pallet' ? 1 : 0,
                    'saldos' => $fila->tipo_bulto === 'saldo' ? 1 : 0,
                    'cajas' => (int) $fila->cantidad_cajas,
                ],
                datos: [
                    'folio_id' => $fila->folio_id,
                    'tipo_bulto' => $fila->tipo_bulto,
                    'marca' => $fila->marca,
                    'csg' => $fila->csg,
                    'especie' => $fila->especie,
                    'variedad' => $fila->variedad,
                    'calibre' => $fila->calibre,
                    'envase' => $fila->envase,
                    'categoria' => $fila->categoria,
                    'linea' => $fila->linea_proceso === null ? null : (int) $fila->linea_proceso,
                    'turno' => $fila->turno,
                ],
            );
        }
    }
}
