<?php

namespace App\Services\Prefrio;

use App\Services\Planillas\PlantillaXlsx;
use DOMDocument;
use DOMXPath;

/**
 * Llena la plantilla oficial POEMP R-41 «Registro de prefrío».
 *
 * Cada hoja trae 10 profundidades por lado (izquierda impares, derecha pares, desde el
 * fondo) y 10 lecturas. Un túnel más profundo o un proceso con más lecturas continúa en
 * otra hoja con el mismo encabezado.
 */
class GeneradorRegistroPrefrioR41 extends PlantillaXlsx
{
    public const PROFUNDIDADES_POR_HOJA = 10;

    public const LECTURAS_POR_HOJA = 10;

    private const PRIMERA_FILA_PALLETS = 12;

    /** Encabezado: celda de cada dato. */
    private const ENCABEZADO = [
        'codigo' => 'D5', 'numero_proceso' => 'D6', 'pallets' => 'D9',
        'fecha' => 'J5', 'supervisor' => 'J6', 'servicio' => 'J7', 'especie' => 'J8', 'variedad' => 'J9',
        'hora_inicio' => 'U5', 'hora_inversion' => 'U6', 'hora_fin' => 'U7', 'tiempo_total' => 'U8',
    ];

    /** Primera columna de cada lectura en un bloque de cinco; cada una ocupa cuatro columnas. */
    private const COLUMNAS_LECTURA = ['D', 'H', 'L', 'P', 'T'];

    /** Bloques de lecturas: fila del título y filas de ventilador, medio y puerta. */
    private const BLOQUES = [
        ['titulo' => 23, 'sectores' => ['ventilador' => 26, 'medio' => 27, 'puerta' => 28]],
        ['titulo' => 30, 'sectores' => ['ventilador' => 33, 'medio' => 34, 'puerta' => 35]],
    ];

    private const DESHIELOS = ['D', 'F', 'H'];

    private const OBSERVACIONES = ['A41', 'A42', 'A43'];

    protected function plantilla(): string
    {
        return 'templates/prefrio/poemp-r-41.xlsx';
    }

    protected function codigo(): string
    {
        return 'POEMP R-41';
    }

    protected function nombreHoja(): string
    {
        return 'REGISTRO PREFRIO';
    }

    /** @param  array<string, mixed>  $registro  Detalle de {@see RegistroPrefrioR41::detalle()}. */
    public function generar(array $registro): string
    {
        $hojas = $this->hojas($registro);

        return $this->generarHojas($hojas, fn (DOMDocument $documento, DOMXPath $xpath, int $indice) => $this->completar($documento, $xpath, $registro, $indice, $hojas));
    }

    /** @param  array<string, mixed>  $registro */
    public function hojas(array $registro): int
    {
        $profundidad = max([(int) ($registro['profundidades'] ?? 1), ...array_map(
            fn (array $lado): int => max(array_keys($lado) ?: [0]),
            array_values($registro['posiciones'] ?? []),
        )]);

        return max(1, (int) ceil($profundidad / self::PROFUNDIDADES_POR_HOJA), (int) ceil(count($registro['lecturas'] ?? []) / self::LECTURAS_POR_HOJA));
    }

    /** @param  array<string, mixed>  $registro */
    private function completar(DOMDocument $documento, DOMXPath $xpath, array $registro, int $indice, int $total): void
    {
        if ($total > 1) {
            $this->texto($documento, $xpath, 'E1', sprintf('REGISTRO DE PREFRÍO · Hoja %d de %d', $indice + 1, $total));
        }
        foreach (self::ENCABEZADO as $clave => $celda) {
            $this->texto($documento, $xpath, $celda, $registro[$clave] ?? null);
        }
        $this->texto($documento, $xpath, 'D7', $this->grados($registro['temperatura_objetivo'] ?? null));
        $this->texto($documento, $xpath, 'D8', $this->grados($registro['setpoint'] ?? null));

        for ($fila = 0; $fila < self::PROFUNDIDADES_POR_HOJA; $fila++) {
            $profundidad = $indice * self::PROFUNDIDADES_POR_HOJA + $fila + 1;
            $r = self::PRIMERA_FILA_PALLETS + $fila;
            foreach ([['izquierda', 'B', 'C', 'E', 'H'], ['derecha', 'V', 'T', 'Q', 'M']] as [$lado, $nro, $calibre, $embalaje, $folio]) {
                $grupo = collect($registro['posiciones'][$lado][$profundidad] ?? []);
                $this->numero($documento, $xpath, $nro.$r, $profundidad);
                $this->texto($documento, $xpath, $calibre.$r, $grupo->pluck('calibre')->filter()->unique()->implode(' / ') ?: null);
                $this->texto($documento, $xpath, $embalaje.$r, $grupo->pluck('embalaje')->filter()->unique()->implode(' / ') ?: null);
                $this->texto($documento, $xpath, $folio.$r, $grupo->pluck('folio')->filter()->implode(' / ') ?: null);
            }
        }

        $lecturas = array_values($registro['lecturas'] ?? []);
        for ($espacio = 0; $espacio < self::LECTURAS_POR_HOJA; $espacio++) {
            $numero = $indice * self::LECTURAS_POR_HOJA + $espacio;
            $lectura = $lecturas[$numero] ?? null;
            $bloque = self::BLOQUES[intdiv($espacio, 5)];
            $columna = self::COLUMNAS_LECTURA[$espacio % 5];
            $columnas = $this->columnas($columna);
            $this->texto($documento, $xpath, $columna.$bloque['titulo'], ($lectura['final'] ?? false) ? ' Final:' : ' '.($numero + 1).':');
            $this->texto($documento, $xpath, $columnas[1].$bloque['titulo'], $lectura['hora'] ?? null);
            foreach ($bloque['sectores'] as $sector => $fila) {
                foreach (array_keys(RegistroPrefrioR41::PUNTOS) as $orden => $punto) {
                    $valor = $lectura['sectores'][$sector][$punto] ?? null;
                    $this->numero($documento, $xpath, $columnas[$orden].$fila, is_numeric($valor) ? round((float) $valor, 1) : null);
                }
            }
        }

        foreach (self::DESHIELOS as $orden => $columna) {
            $deshielo = ($registro['deshielos'] ?? [])[$orden] ?? null;
            $this->texto($documento, $xpath, $columna.'37', $deshielo['fecha'] ?? null);
            $this->texto($documento, $xpath, $columna.'38', $deshielo['hora'] ?? null);
        }

        $notas = [sprintf('FoliOS %s · túnel %s · estado %s.', $registro['codigo'] ?? '', $registro['tunel'] ?? '', str_replace('_', ' ', (string) ($registro['estado'] ?? '')))];
        foreach ($lecturas as $orden => $lectura) {
            // Lecturas de una sola temperatura (tablets anteriores al R-41).
            if ($lectura['sectores'] === null && $lectura['temperatura'] !== null) {
                $notas[] = sprintf('Lectura %d (%s): %s sin sector.', $orden + 1, $lectura['hora'], $this->grados($lectura['temperatura']));
            }
        }
        $lineas = $this->lineas(implode(' ', [...$notas, ...($registro['observaciones'] ?? [])]), 125, count(self::OBSERVACIONES));
        foreach (self::OBSERVACIONES as $orden => $celda) {
            $this->texto($documento, $xpath, $celda, $lineas[$orden] ?? null);
        }
        $this->texto($documento, $xpath, 'H47', $registro['supervisor'] ?? null);
        $this->texto($documento, $xpath, 'S47', $registro['jefe_frio'] ?? null);
    }

    /** @return array<int, string> La columna y las tres siguientes. */
    private function columnas(string $columna): array
    {
        $base = ord($columna);

        return [chr($base), chr($base + 1), chr($base + 2), chr($base + 3)];
    }

    private function grados(mixed $valor): ?string
    {
        return is_numeric($valor) ? number_format((float) $valor, 1, ',', '').' °C' : null;
    }
}
