<?php

namespace App\Services\Validacion;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Cada línea usa un artículo real; únicamente el calibre puede variar dentro del producto. */
class ComposicionCalibresValidacion
{
    public function preparar(array $lineas, object $principal, string $temporadaId, int $cantidad, ?string $fecha): Collection
    {
        $lineas = collect($lineas);
        if ($lineas->isEmpty() || (int) $lineas->sum('cantidad_cajas') !== $cantidad) {
            throw new DomainException(sprintf('La composición por CSG suma %d cajas y el bulto declara %d.', $lineas->sum('cantidad_cajas'), $cantidad));
        }
        $articuloIds = $lineas->map(fn ($linea) => $linea['articulo_validacion_id'] ?? $principal->id)->unique()->values();
        $articulos = DB::table('articulos_validacion')->whereIn('id', $articuloIds)
            ->where('temporada_id', $temporadaId)->where('activo', true)->get()->keyBy('id');
        $origenIds = $lineas->pluck('origen_validacion_id')->unique()->values();
        $origenes = DB::table('origenes_validacion')->whereIn('id', $origenIds)
            ->where('temporada_id', $temporadaId)->where('activo', true)->get()->keyBy('id');
        if ($articulos->count() !== $articuloIds->count() || $origenes->count() !== $origenIds->count()) {
            throw new DomainException('El artículo o el CSG de una línea no pertenece al catálogo activo de la temporada.');
        }
        if (($lineas->first()['articulo_validacion_id'] ?? $principal->id) !== $principal->id) {
            throw new DomainException('El artículo principal debe coincidir con el calibre de la primera línea.');
        }
        $normalizar = fn ($valor): string => mb_strtoupper(trim((string) $valor));
        foreach (['cliente', 'marca'] as $campo) {
            if ($origenes->pluck($campo)->map($normalizar)->unique()->count() !== 1) {
                throw new DomainException('Todos los CSG del bulto deben pertenecer al mismo cliente y marca.');
            }
        }
        foreach ($articulos as $articulo) {
            foreach (['especie', 'variedad', 'envase'] as $campo) {
                $id = $campo === 'variedad' ? 'variedad_validacion_id' : $campo.'_validacion_id';
                if (($articulo->{$id} ?? null) !== ($principal->{$id} ?? null)
                    || $normalizar($articulo->{$campo}) !== $normalizar($principal->{$campo})) {
                    throw new DomainException('Los calibres deben pertenecer a la misma especie, variedad y envase.');
                }
            }
        }
        $combinaciones = DB::table('combinaciones_validacion')->where('temporada_id', $temporadaId)
            ->whereIn('articulo_validacion_id', $articuloIds)->whereIn('origen_validacion_id', $origenIds)
            ->where('activo', true)->get()->keyBy(fn ($fila) => $fila->articulo_validacion_id.'|'.$fila->origen_validacion_id);
        $variedades = DB::table('csg_variedades_validacion')
            ->whereIn('csg_validacion_id', $origenes->pluck('csg_validacion_id')->filter()->unique())
            ->whereIn('variedad_validacion_id', $articulos->pluck('variedad_validacion_id')->filter()->unique())
            ->get()->keyBy(fn ($fila) => $fila->csg_validacion_id.'|'.$fila->variedad_validacion_id);
        $claves = [];

        return $lineas->map(function (array $linea) use ($principal, $articulos, $origenes, $combinaciones, $variedades, $fecha, $normalizar, &$claves): array {
            $articulo = $articulos->get($linea['articulo_validacion_id'] ?? $principal->id);
            $origen = $origenes->get($linea['origen_validacion_id']);
            $combinacion = $combinaciones->get($articulo->id.'|'.$origen->id);
            if (! $combinacion) {
                throw new DomainException('Una de las combinaciones de artículo y CSG no se encuentra habilitada.');
            }
            if ($articulo->variedad_validacion_id !== null && $origen->csg_validacion_id !== null
                && ! $variedades->has($origen->csg_validacion_id.'|'.$articulo->variedad_validacion_id)) {
                throw new DomainException('La variedad seleccionada no está asociada al CSG elegido.');
            }
            $lote = ProyeccionTrazabilidadFolio::normalizarCodigo($linea['lote_materia_prima'] ?? null);
            $proceso = ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso_packing'] ?? null);
            $clave = implode('|', [$normalizar($articulo->calibre), $origen->id, $lote, $proceso]);
            if (isset($claves[$clave])) {
                throw new DomainException('No repitas la misma combinación de CSG, lote y proceso en el mismo calibre.');
            }
            $claves[$clave] = true;

            return [
                'articulo_validacion_id' => $articulo->id,
                'envase_validacion_id' => $articulo->envase_validacion_id,
                'especie' => $articulo->especie, 'variedad' => $articulo->variedad,
                'calibre' => $articulo->calibre, 'envase' => $articulo->envase,
                'cliente' => $origen->cliente, 'marca' => $origen->marca,
                'origen_validacion_id' => $origen->id,
                'combinacion_validacion_id' => $combinacion->id,
                'csg' => $origen->csg, 'predio' => $origen->predio,
                'fecha_embalaje' => $fecha, 'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                ...($lote !== null ? ['lote_materia_prima' => $lote] : []),
                ...($proceso !== null ? ['proceso_packing' => $proceso] : []),
            ];
        })->values();
    }

    public static function resumen(Collection $lineas): string
    {
        $calibres = $lineas->pluck('calibre')->unique()->values();

        return $calibres->count() === 1 ? (string) $calibres->first() : 'MIX';
    }
}
