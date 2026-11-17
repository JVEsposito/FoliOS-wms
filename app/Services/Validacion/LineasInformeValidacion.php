<?php

namespace App\Services\Validacion;

/**
 * Desglose de un pallet validado para los informes: una línea por CSG y calibre, con sus
 * cajas. Un informe nunca muestra «MIX»; muestra las líneas que el pallet tenga.
 */
final class LineasInformeValidacion
{
    private const RESUMENES = ['MIX', 'MIXTA', 'MIXTO'];

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array{csg: string, calibre: string, cajas: int}>
     */
    public static function de(array $snapshot, int $cajas): array
    {
        $calibreGeneral = self::valor($snapshot['articulo']['calibre'] ?? null);
        $composicion = collect($snapshot['composicion'] ?? [])
            ->filter(fn (mixed $linea): bool => is_array($linea) && (int) ($linea['cantidad_cajas'] ?? 0) > 0);
        if ($composicion->isEmpty()) {
            return [[
                'csg' => self::valor($snapshot['origen']['csg'] ?? null) ?? 'Sin desglose',
                'calibre' => $calibreGeneral ?? 'Sin desglose',
                'cajas' => $cajas,
            ]];
        }

        return $composicion
            ->map(fn (array $linea): array => [
                'csg' => self::valor($linea['csg'] ?? null) ?? 'Sin CSG',
                'calibre' => self::valor($linea['calibre'] ?? null) ?? $calibreGeneral ?? 'Sin calibre',
                'cajas' => (int) $linea['cantidad_cajas'],
            ])
            ->groupBy(fn (array $linea): string => mb_strtoupper($linea['csg']).'|'.mb_strtoupper($linea['calibre']))
            ->map(fn ($grupo): array => [...$grupo->first(), 'cajas' => (int) $grupo->sum('cajas')])
            ->values()
            ->all();
    }

    /** @param  list<array{csg: string, calibre: string, cajas: int}>  $lineas */
    public static function calibreUnico(array $lineas): ?string
    {
        $calibres = collect($lineas)->pluck('calibre')->unique(fn (string $calibre): string => mb_strtoupper($calibre));

        return $calibres->count() === 1 ? $calibres->first() : null;
    }

    /** Un valor real o null: los resúmenes «MIX» no son un dato que se pueda informar. */
    private static function valor(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' || in_array(mb_strtoupper($texto), self::RESUMENES, true) ? null : $texto;
    }
}
