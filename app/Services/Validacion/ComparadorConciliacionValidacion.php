<?php

namespace App\Services\Validacion;

use Illuminate\Support\Str;

/** Compara comprobación física y planta; nunca consulta ni cambia inventario. */
class ComparadorConciliacionValidacion
{
    public const CAMPOS = ['cajas' => 'Cajas', 'marca' => 'Marca', 'calibre' => 'Calibre', 'csg' => 'Cajas por CSG'];

    public function comparar(array $validado, ?array $planta): array
    {
        $resultado = [];
        foreach (self::CAMPOS as $campo => $etiqueta) {
            $fisico = $validado[$campo] ?? null;
            $origen = $planta[$campo] ?? null;
            $homologado = in_array($campo, ['marca', 'calibre'], true) ? ($planta[$campo.'_homologado'] ?? null) : $origen;
            $estado = 'por_revisar';
            if (($planta['presente'] ?? false) && $this->completo($campo, $fisico, $validado['cajas'] ?? null) && $this->completo($campo, $origen, $planta['cajas'] ?? null)) {
                if (in_array($campo, $planta['mezclados'] ?? [], true)) {
                    $estado = 'diferente';
                } elseif ($homologado !== null) {
                    $estado = $this->iguales($campo, $fisico, $homologado) ? 'coincide' : 'diferente';
                }
            }
            // Una mezcla legítima se compara por sus cajas, sin declarar diferencia solo por ser MIX.
            if ($campo === 'calibre' && (isset($validado['calibres']) || isset($planta['calibres']))) {
                $a = $validado['calibres'] ?? $this->porCalibre([['calibre' => $fisico, 'cajas' => $validado['cajas'] ?? 0]], 'cajas');
                $b = $planta['calibres'] ?? $this->porCalibre([['calibre' => $planta['calibre_homologado'] ?? null, 'cajas' => $planta['cajas'] ?? 0]], 'cajas');
                $estado = 'por_revisar';
                if (($planta['presente'] ?? false) && $a !== [] && $b !== []
                    && array_sum($a) === ($validado['cajas'] ?? null) && array_sum($b) === ($planta['cajas'] ?? null)) {
                    $estado = $this->iguales('csg', $a, $b) ? 'coincide' : 'diferente';
                }
                $texto = fn (array $valores): string => collect($valores)->map(fn ($cajas, $calibre) => $calibre.': '.$cajas)->implode(' · ');
                $fisico = $a !== [] ? $texto($a) : $fisico;
                $origen = $b !== [] ? $texto($b) : $origen;
                $homologado = $b !== [] ? $texto($b) : null;
            }
            $resultado[$campo] = ['campo' => $campo, 'etiqueta' => $etiqueta, 'estado' => $estado,
                'planta' => $origen, 'planta_homologada' => $homologado, 'validado' => $fisico,
                'diferencia' => $campo === 'cajas' && $estado !== 'por_revisar' ? $fisico - $origen : null];
        }

        return $resultado;
    }

    public function datosValidacion(array $snapshot, int $cajas): array
    {
        $composicion = $snapshot['composicion'] ?? [];
        if ($composicion === [] && filled($snapshot['origen']['csg'] ?? null) && $snapshot['origen']['csg'] !== 'MIX') {
            $composicion = [['csg' => $snapshot['origen']['csg'], 'cantidad_cajas' => $cajas]];
        }

        return ['cajas' => $cajas, 'marca' => $snapshot['origen']['marca'] ?? null,
            'calibre' => $snapshot['articulo']['calibre'] ?? null,
            ...(($snapshot['articulo']['calibre'] ?? null) === 'MIX'
                ? ['calibres' => $this->porCalibre($composicion, 'cantidad_cajas')]
                : []),
            'csg' => $this->porCsg($composicion, 'cantidad_cajas')];
    }

    public function porCsg(array $lineas, string $cantidad): array
    {
        $resultado = [];
        foreach ($lineas as $linea) {
            $csg = trim((string) ($linea['csg'] ?? ''));
            $resultado[$csg] = ($resultado[$csg] ?? 0) + (int) ($linea[$cantidad] ?? 0);
        }
        ksort($resultado, SORT_STRING);

        return $resultado;
    }

    public function porCalibre(array $lineas, string $cantidad, ?string $base = null): array
    {
        $resultado = [];
        foreach ($lineas as $linea) {
            $calibre = Str::upper(Str::squish((string) ($linea['calibre'] ?? $base)));
            if (in_array($calibre, ['', 'MIX', 'MIXTO'], true) || (int) ($linea[$cantidad] ?? 0) < 1) {
                return [];
            }
            $resultado[$calibre] = ($resultado[$calibre] ?? 0) + (int) $linea[$cantidad];
        }
        ksort($resultado, SORT_STRING);

        return $resultado;
    }

    public function iguales(string $campo, mixed $a, mixed $b): bool
    {
        if ($campo === 'csg' && is_array($a) && is_array($b)) {
            ksort($a, SORT_STRING);
            ksort($b, SORT_STRING);
        }
        if (in_array($campo, ['marca', 'calibre'], true)) {
            return Str::upper(Str::squish(Str::ascii((string) $a))) === Str::upper(Str::squish(Str::ascii((string) $b)));
        }

        return $a === $b;
    }

    private function completo(string $campo, mixed $valor, ?int $cajas): bool
    {
        if ($campo === 'cajas') {
            return is_int($valor) && $valor > 0;
        }
        if ($campo === 'csg') {
            return is_array($valor) && $valor !== [] && array_sum($valor) === $cajas
                && collect(array_keys($valor))->every(fn ($codigo) => ! in_array(trim((string) $codigo), ['', 'SIN CSG', 'MIX'], true))
                && collect($valor)->every(fn ($cantidad) => $cantidad > 0);
        }

        return filled($valor);
    }
}
