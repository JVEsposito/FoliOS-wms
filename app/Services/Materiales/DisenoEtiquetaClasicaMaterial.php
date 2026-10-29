<?php

namespace App\Services\Materiales;

use DomainException;

/** Diseño apaisado de 200 × 100 mm, independiente del tamaño físico del cabezal. */
final class DisenoEtiquetaClasicaMaterial
{
    public const CODIGO = 'clasica_bodega';

    public const VERSION = 1;

    public static function admitePerfil(array $perfil): bool
    {
        $medidas = [(float) $perfil['ancho_mm'], (float) $perfil['alto_mm']];
        sort($medidas);

        return abs($medidas[0] - 100) < 0.01 && abs($medidas[1] - 200) < 0.01;
    }

    public static function validarPerfil(array $perfil): void
    {
        if (! self::admitePerfil($perfil)) {
            throw new DomainException('El diseño Clásica bodega requiere una etiqueta de 200 × 100 mm. Selecciona un perfil de ese tamaño.');
        }
    }

    /** Coordenadas en mm, con origen arriba a la izquierda. */
    public static function disponer(array $datos): array
    {
        if (! preg_match('/^[\x20-\x7E]+$/D', $datos['folio'])) {
            throw new DomainException('El folio contiene caracteres que Code 128 no puede representar.');
        }
        $textos = [];
        $agregar = function (string $nombre, string $valor, float $x, float $y, float $ancho, float $alto, float $maximo, bool $centrado = false, bool $negrita = false) use (&$textos): void {
            if (! str_starts_with($nombre, 'Folio')) {
                $valor = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($valor))) ?: '—';
            }
            $tamano = min($maximo, ($ancho * 0.96) / DisenoTarjaArmado::anchoRelativo($valor, $negrita));
            if ($tamano < 2.1) {
                throw new DomainException('El campo '.$nombre.' es demasiado largo para esta etiqueta. Revisa el dato o utiliza el diseño Actual.');
            }
            $textos[] = compact('nombre', 'valor', 'x', 'y', 'ancho', 'alto', 'tamano', 'centrado', 'negrita');
        };

        $agregar('Cliente', $datos['cliente'], 33, 4, 116, 20, 10, true);
        $agregar('Fecha recepción', $datos['fecha'], 153, 4, 42, 6, 4.4);
        $agregar('Guía', 'N.º GUÍA: '.$datos['guia'], 153, 10.5, 42, 6, 4);
        $agregar('Folio', $datos['folio'], 153, 17, 42, 6, 4.5, false, true);
        $agregar('Proveedor', 'PROVEEDOR: '.$datos['proveedor'], 5, 26, 190, 10, 5.4);

        $descripcion = $datos['codigo'].' · '.$datos['descripcion'];
        $lineas = self::envolver($descripcion, 190, 5.4);
        foreach ($lineas as $i => $linea) {
            $agregar('Ítem '.($i + 1), $linea, 5, 38 + ($i * 6), 190, 6, 5.4);
        }

        $agregar('Cantidad título', 'CANTIDAD RECIBIDA', 5, 55, 45, 7, 3.8);
        $agregar('Unidad', $datos['unidad'], 5, 63, 45, 12, 6, false, true);
        $agregar('Cantidad', $datos['cantidad'], 51, 53, 143, 25, 23, true, true);

        $bloqueo = ($datos['bloqueado'] ?? false) ? 'BLOQUEADO · '.($datos['motivo_bloqueo'] ?: 'REVISAR EN FOLIOS') : '';
        if ($bloqueo !== '') {
            // La condición de bloqueo siempre es visible; el motivo completo queda en el trabajo.
            $agregar('Bloqueo', mb_strimwidth($bloqueo, 0, 100, '…'), 5, 78, 190, 5, 3, false, true);
        }
        $agregar('Folio escaneable', $datos['folio'], 122, 87, 73, 7, 5, true, true);

        return [
            'lineas' => [
                [3, 3, 197, 3], [197, 3, 197, 97], [197, 97, 3, 97], [3, 97, 3, 3],
                [3, 25, 197, 25], [31, 3, 31, 25], [151, 3, 151, 25],
                [151, 10, 197, 10], [151, 16.5, 197, 16.5],
                [3, 37, 197, 37], [3, 52, 197, 52], [3, 84, 197, 84],
            ],
            'textos' => $textos,
            'logo' => ['x' => 5, 'y' => 7, 'ancho' => 24, 'alto' => 14],
            'codigo' => ['valor' => $datos['folio'], 'x' => 8, 'y' => 86, 'ancho' => 107, 'alto' => 9],
        ];
    }

    /** Hasta dos líneas; el ajuste de tamaño nunca oculta ni recorta el ítem. */
    private static function envolver(string $texto, float $ancho, float $tamano): array
    {
        $texto = mb_strtoupper(trim($texto));
        if (DisenoTarjaArmado::anchoRelativo($texto, false) * $tamano <= $ancho * 0.96) {
            return [$texto];
        }
        $palabras = preg_split('/\s+/u', $texto) ?: [$texto];
        $mejor = null;
        for ($i = 1; $i < count($palabras); $i++) {
            $lineas = [implode(' ', array_slice($palabras, 0, $i)), implode(' ', array_slice($palabras, $i))];
            $peso = max(array_map(fn ($linea) => DisenoTarjaArmado::anchoRelativo($linea, false), $lineas));
            if ($mejor === null || $peso < $mejor[0]) {
                $mejor = [$peso, $lineas];
            }
        }

        return $mejor[1] ?? [$texto];
    }
}
