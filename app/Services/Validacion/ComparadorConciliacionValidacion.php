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
