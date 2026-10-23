<?php

namespace App\Services\Materiales;

use Illuminate\Validation\ValidationException;

class GeometriaCapacidadMaterial
{
    public static function mm(float|string|int $metros): int
    {
        return (int) round((float) $metros * 1000);
    }

    public function normalizarAlmacen(array $d): array
    {
        $largo = self::mm($d['largo_m']);
        $ancho = self::mm($d['ancho_m']);
        $altura = self::mm($d['altura_util_m']);
        $zonas = [];
        $nombres = [];
        $ids = [];
        foreach ($d['zonas'] as $i => $z) {
            $zona = ['id' => strtolower($z['id']), 'nombre' => trim($z['nombre']),
                'x_mm' => self::mm($z['x_m']), 'y_mm' => self::mm($z['y_m']),
                'largo_mm' => self::mm($z['largo_m']), 'ancho_mm' => self::mm($z['ancho_m']),
                'altura_util_mm' => self::mm($z['altura_util_m']),
                'modo' => $z['modo'] ?? 'piso',
                'max_niveles' => (int) $z['max_niveles'], 'permite_giro' => (bool) $z['permite_giro'],
                'limite_pallets' => isset($z['limite_pallets']) ? (int) $z['limite_pallets'] : null,
                'peso_maximo_kg' => $z['peso_maximo_kg'] ?? null,
                'tipo_pallet_ids' => array_values(array_unique(array_map('strtolower', $z['tipo_pallet_ids'])))];
            if (in_array($zona['id'], $ids, true)) {
                $this->error("zonas.$i.id", 'Cada zona debe tener una identificación distinta.');
            }
            $ids[] = $zona['id'];
            if ($zona['modo'] === 'rack') {
                foreach (['largo', 'ancho', 'altura'] as $campo) {
                    if (! isset($z['hueco_'.$campo.'_m'])) {
                        $this->error("zonas.$i", 'Los racks requieren las tres medidas útiles de cada hueco.');
                    }
                    $zona['hueco_'.$campo.'_mm'] = self::mm($z['hueco_'.$campo.'_m']);
                }
                if (! $zona['limite_pallets'] || $zona['hueco_largo_mm'] > $zona['largo_mm'] || $zona['hueco_ancho_mm'] > $zona['ancho_mm'] || $zona['hueco_altura_mm'] > $zona['altura_util_mm']
                    || $zona['limite_pallets'] * $zona['hueco_largo_mm'] * $zona['hueco_ancho_mm'] * $zona['hueco_altura_mm'] > $zona['largo_mm'] * $zona['ancho_mm'] * $zona['altura_util_mm']) {
                    $this->error("zonas.$i", 'Declara los huecos físicos del rack y verifica que sus medidas y volumen no superen los de la zona.');
                }
            }
            $nombre = mb_strtolower($zona['nombre']);
            if (in_array($nombre, $nombres, true)) {
                $this->error("zonas.$i.nombre", 'Los nombres de las zonas deben ser distintos.');
            }
            $nombres[] = $nombre;
            if ($largo < $zona['x_mm'] + $zona['largo_mm'] || $ancho < $zona['y_mm'] + $zona['ancho_mm'] || $zona['altura_util_mm'] > $altura) {
                $this->error("zonas.$i", 'La zona debe quedar dentro del largo, ancho y altura útil del almacén.');
            }
            foreach ($zonas as $otra) {
                if ($zona['x_mm'] < $otra['x_mm'] + $otra['largo_mm'] && $otra['x_mm'] < $zona['x_mm'] + $zona['largo_mm']
                    && $zona['y_mm'] < $otra['y_mm'] + $otra['ancho_mm'] && $otra['y_mm'] < $zona['y_mm'] + $zona['ancho_mm']) {
                    $this->error("zonas.$i", 'Las zonas útiles no pueden superponerse.');
                }
            }
            $zonas[] = $zona;
        }

        return ['largo_mm' => $largo, 'ancho_mm' => $ancho, 'altura_util_mm' => $altura, 'zonas' => $zonas];
    }

    /** Referencia de piso o huecos declarados; no representa espacio libre. */
    public function simular(array $config, array $tipo, array $carga): array
    {
        $zonas = [];
        foreach ($config['zonas'] as $z) {
            $motivos = [];
            if (! $tipo['activo'] || ! in_array(strtolower($tipo['id']), array_map('strtolower', $z['tipo_pallet_ids']), true)) {
                $motivos[] = 'Tipo de pallet no habilitado en esta zona.';
            }
            $rack = ($z['modo'] ?? 'piso') === 'rack';
            $largo = $rack ? $z['hueco_largo_mm'] : $z['largo_mm'];
            $ancho = $rack ? $z['hueco_ancho_mm'] : $z['ancho_mm'];
            $normal = intdiv($largo, $carga['largo_mm']) * intdiv($ancho, $carga['ancho_mm']);
            $giro = $z['permite_giro'] ? intdiv($largo, $carga['ancho_mm']) * intdiv($ancho, $carga['largo_mm']) : 0;
            $piso = $rack ? (max($normal, $giro) > 0 ? $z['limite_pallets'] : 0) : max($normal, $giro);
            // Cada hueco de rack admite un pallet: sus niveles son soportados
            // por la estructura y no se infieren del apilado permitido al pallet.
            $niveles = $rack ? min(1, intdiv($z['hueco_altura_mm'], $carga['altura_total_mm']))
                : min($z['max_niveles'], $tipo['max_niveles'], $carga['max_niveles'], intdiv($z['altura_util_mm'], $carga['altura_total_mm']));
            if ($piso === 0 || $niveles === 0) {
                $motivos[] = 'La carga no cabe por sus dimensiones o altura.';
            }
            $limitesPeso = array_filter([$z['peso_maximo_kg'], $tipo['peso_maximo_kg']], fn ($v) => $v !== null);
            $pesoPendiente = count($limitesPeso) > 0 && $carga['peso_unitario_kg'] === null;
            if (! $pesoPendiente && $limitesPeso && (float) $carga['peso_unitario_kg'] > min($limitesPeso)) {
                $motivos[] = 'El peso unitario supera el límite de la zona o del tipo de pallet.';
            }
            $pallets = min($piso * $niveles, $z['limite_pallets'] ?? PHP_INT_MAX);
            $zonas[] = ['id' => $z['id'], 'nombre' => $z['nombre'],
                'estado' => $motivos ? 'incompatible' : ($pesoPendiente ? 'pendiente_peso' : 'referencia'),
                'modo' => $rack ? 'rack' : 'piso', 'motivos' => $motivos, 'puestos' => $piso, 'niveles' => $niveles,
                'pallets_referencia' => $motivos ? 0 : $pallets,
                'orientacion' => $giro > $normal ? 'girada' : 'normal',
                'superficie_util_m2' => round($z['largo_mm'] * $z['ancho_mm'] / 1e6, 3),
                'volumen_util_m3' => round($z['largo_mm'] * $z['ancho_mm'] * $z['altura_util_mm'] / 1e9, 3)];
        }

        return ['zonas' => $zonas,
            'pallets_referencia' => array_sum(array_column(array_filter($zonas, fn ($z) => $z['estado'] === 'referencia'), 'pallets_referencia')),
            'aviso' => 'Referencia para un solo formato: retícula uniforme a piso o huecos declarados de rack. No descuenta ocupación ni reservas, no mezcla orientaciones y no autoriza una ubicación física.'];
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}
