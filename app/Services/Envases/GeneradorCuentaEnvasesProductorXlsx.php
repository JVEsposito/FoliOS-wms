<?php

namespace App\Services\Envases;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

final class GeneradorCuentaEnvasesProductorXlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const TIPOS = ['I' => 'bins', 'J' => 'totes', 'K' => 'esponjas'];

    public function generar(array $grupos): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'folios-envases-');
        if ($ruta === false) {
            throw new RuntimeException('No fue posible preparar el Excel de envases.');
        }

        try {
            if (! File::copy(resource_path('templates/envases/cuenta-por-productor.xlsx'), $ruta)) {
                throw new RuntimeException('No fue posible copiar la plantilla de envases.');
            }
            $libro = new ZipArchive;
            if ($libro->open($ruta) !== true) {
                throw new RuntimeException('No fue posible abrir la plantilla de envases.');
            }
            try {
                $hoja = 'xl/worksheets/sheet1.xml';
                $xml = $this->completar((string) $libro->getFromName($hoja), $grupos);
                if (! $libro->addFromString($hoja, $xml)) {
                    throw new RuntimeException('No fue posible escribir la hoja de envases.');
                }
            } finally {
                $guardado = $libro->close();
            }
            if (! $guardado) {
                throw new RuntimeException('No fue posible finalizar el Excel de envases.');
            }
        } catch (Throwable $e) {
            File::delete($ruta);

            throw $e;
        }

        return $ruta;
    }

    private function completar(string $xml, array $grupos): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', self::NS);
        $datos = $xpath->query('/s:worksheet/s:sheetData')->item(0);
        $combinadas = $xpath->query('/s:worksheet/s:mergeCells')->item(0);
        $plantillas = [];
        // La plantilla tiene cuatro filas vacías con los estilos reutilizados en la exportación.
        foreach (['productor' => 7, 'movimiento' => 8, 'subtotal' => 9, 'total' => 11] as $nombre => $fila) {
            $plantillas[$nombre] = $datos->removeChild($xpath->query("./s:row[@r='{$fila}']", $datos)->item(0));
        }

        $fila = 7;
        $total = array_fill_keys(array_keys(self::TIPOS), 0);
        foreach ($grupos as $grupo) {
            $cabecera = $this->fila($datos, $plantillas['productor'], $fila++, [
                'A' => $grupo['codigo'], 'B' => $grupo['nombre'] ?? ($grupo['codigo'] === null ? 'Sin productor asociado' : null),
            ]);
            $cabecera->setAttribute('ht', '30');
            $primera = $fila;
            $subtotal = array_fill_keys(array_keys(self::TIPOS), 0);
            foreach ($grupo['movimientos'] as $movimiento) {
                // Fecha de planta a medianoche, como serial numérico de Excel.
                $fecha = (int) (strtotime(substr($movimiento['fecha'], 0, 10).'T00:00:00Z') / 86400) + 25569;
                $valores = [
                    'C' => $movimiento['movimiento'], 'D' => $fecha, 'E' => (string) $movimiento['guia'],
                    'F' => $movimiento['chofer'], 'G' => $movimiento['patente_camion'], 'H' => $movimiento['patente_rampla'],
                ];
                foreach (self::TIPOS as $columna => $tipo) {
                    $valores[$columna] = $movimiento[$tipo] ?? null;
                    $subtotal[$columna] += $movimiento[$tipo] ?? 0;
                }
                $this->fila($datos, $plantillas['movimiento'], $fila++, $valores);
            }
            $this->totales($datos, $combinadas, $plantillas['subtotal'], $fila, $subtotal, 'TOTAL PRODUCTOR', $primera);
            foreach ($subtotal as $columna => $cantidad) {
                $total[$columna] += $cantidad;
            }
            $fila += 2;
        }
        // SUBTOTAL omite otros SUBTOTAL en el rango y cuenta el detalle una sola vez.
        $this->totales($datos, $combinadas, $plantillas['total'], $fila, $total, 'TOTAL GENERAL', 7);
        $combinadas->setAttribute('count', (string) $combinadas->getElementsByTagNameNS(self::NS, 'mergeCell')->length);
        $xpath->query('/s:worksheet/s:dimension')->item(0)?->setAttribute('ref', 'A1:K'.$fila);

        return $doc->saveXML();
    }

    private function fila(DOMElement $datos, DOMElement $plantilla, int $numero, array $valores, array $formulas = []): DOMElement
    {
        $fila = $plantilla->cloneNode(true);
        $fila->setAttribute('r', (string) $numero);
        $doc = $datos->ownerDocument;
        foreach ($fila->childNodes as $celda) {
            if (! $celda instanceof DOMElement) {
                continue;
            }
            $columna = $celda->getAttribute('r')[0];
            $celda->setAttribute('r', $columna.$numero);
            $valor = $valores[$columna] ?? null;
            if (isset($formulas[$columna])) {
                $celda->appendChild($doc->createElementNS(self::NS, 'f', $formulas[$columna]));
            }
            if (is_int($valor)) {
                $celda->appendChild($doc->createElementNS(self::NS, 'v', (string) $valor));
            } elseif ($valor !== null && $valor !== '') {
                $celda->setAttribute('t', 'inlineStr');
                $texto = $doc->createElementNS(self::NS, 't');
                $texto->setAttribute('xml:space', 'preserve');
                $texto->appendChild($doc->createTextNode((string) $valor));
                $celda->appendChild($doc->createElementNS(self::NS, 'is'))->appendChild($texto);
            }
        }

        return $datos->appendChild($fila);
    }

    private function totales(DOMElement $datos, DOMElement $combinadas, DOMElement $plantilla, int $numero, array $cantidades, string $titulo, int $primera): void
    {
        $formulas = [];
        $ultima = $numero - 1;
        if ($primera <= $ultima) {
            foreach (array_keys($cantidades) as $columna) {
                $formulas[$columna] = "SUBTOTAL(9,{$columna}{$primera}:{$columna}{$ultima})";
            }
        }
        $this->fila($datos, $plantilla, $numero, ['A' => $titulo, ...$cantidades], $formulas);
        $rango = $datos->ownerDocument->createElementNS(self::NS, 'mergeCell');
        $rango->setAttribute('ref', "A{$numero}:H{$numero}");
        $combinadas->appendChild($rango);
    }
}
