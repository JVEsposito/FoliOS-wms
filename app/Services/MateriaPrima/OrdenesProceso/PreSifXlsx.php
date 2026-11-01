<?php

namespace App\Services\MateriaPrima\OrdenesProceso;

use RuntimeException;
use Throwable;
use ZipArchive;

/** PRE-SIF en Excel con el diseño de la planilla de planta: encabezado, lotes, colación y total. */
class PreSifXlsx
{
    private const ESTILO_NORMAL = 1;

    private const ESTILO_NEGRITA = 2;

    private const ESTILO_TITULO = 3;

    private const ESTILO_SEPARADOR = 4;

    /** @param array<string, mixed> $planilla */
    public function generar(array $planilla): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'pre-sif-');
        if ($ruta === false) {
            throw new RuntimeException('No fue posible crear la PRE-SIF.');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No fue posible construir la PRE-SIF.');
            }
            $zip->addFromString('[Content_Types].xml', $this->xml().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
            $zip->addFromString('_rels/.rels', $this->relaciones('<Relationship Id="libro" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'));
            $zip->addFromString('xl/workbook.xml', $this->xml().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="PRE-SIF" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relaciones('<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'));
            $zip->addFromString('xl/styles.xml', $this->estilos());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->hoja($planilla));
            if (! $zip->close()) {
                throw new RuntimeException('No fue posible finalizar la PRE-SIF.');
            }

            return $ruta;
        } catch (Throwable $error) {
            if ($zip->status === ZipArchive::ER_OK) {
                @$zip->close();
            }
            @unlink($ruta);
            throw $error;
        }
    }

    /** @param array<string, mixed> $planilla */
    private function hoja(array $planilla): string
    {
        $e = $planilla['encabezado'];
        $ultima = $this->columna(count(PlanillaPreSif::COLUMNAS));
        $filas = $this->fila(1, ['A' => $e['titulo']], self::ESTILO_TITULO, 22)
            .$this->fila(2, ['A' => 'ORDEN:', 'B' => $e['orden'], 'P' => 'FECHA:', 'T' => $e['fecha']], self::ESTILO_NEGRITA)
            .$this->fila(3, ['A' => 'VERSIÓN:', 'B' => $e['version'], 'P' => 'CSP:', 'T' => $e['csp']], self::ESTILO_NEGRITA)
            .$this->fila(4, ['A' => 'TURNO:', 'B' => $e['turno'], 'P' => 'HORARIO INICIO:', 'T' => $e['horario_inicio']], self::ESTILO_NEGRITA)
            .$this->fila(5, ['A' => 'LÍNEA:', 'B' => $e['linea'], 'P' => 'VUELTA DE COLACIÓN:', 'T' => $e['vuelta_colacion']], self::ESTILO_NEGRITA);
        $encabezados = [];
        foreach (PlanillaPreSif::COLUMNAS as $i => $titulo) {
            $encabezados[$this->columna($i + 1)] = $titulo;
        }
        $filas .= $this->fila(7, $encabezados, self::ESTILO_NEGRITA, null);
        $merges = ['A1:'.$ultima.'1', 'B2:D2', 'B3:D3', 'B4:D4', 'B5:D5'];

        $numero = 8;
        $colacion = $planilla['colacion'];
        foreach ($planilla['filas'] as $indice => $valores) {
            if ($colacion && $colacion['despues_de'] === $indice) {
                $filas .= $this->fila($numero, ['A' => $colacion['texto']], self::ESTILO_SEPARADOR);
                $merges[] = "A{$numero}:{$ultima}{$numero}";
                $numero++;
            }
            $celdas = [];
            foreach ($valores as $i => $valor) {
                $celdas[$this->columna($i + 1)] = $valor;
            }
            // Sin alto fijo: Excel ajusta la fila al texto envuelto.
            $filas .= $this->fila($numero++, $celdas, self::ESTILO_NORMAL, null);
        }
        if ($colacion && $colacion['despues_de'] >= count($planilla['filas'])) {
            $filas .= $this->fila($numero, ['A' => $colacion['texto']], self::ESTILO_SEPARADOR);
            $merges[] = "A{$numero}:{$ultima}{$numero}";
            $numero++;
        }
        $t = $planilla['totales'];
        $filas .= $this->fila($numero, ['C' => 'TOTAL', 'L' => $t['bins'], 'M' => $t['totes'], 'N' => $t['kilos']], self::ESTILO_NEGRITA);

        $columnas = '';
        foreach (PlanillaPreSif::ANCHOS as $i => $ancho) {
            $columnas .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$ancho.'" customWidth="1"/>';
        }

        return $this->xml().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:'.$ultima.$numero.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>'.$columnas.'</cols><sheetData>'.$filas.'</sheetData><mergeCells count="'.count($merges).'">'.implode('', array_map(fn (string $m): string => '<mergeCell ref="'.$m.'"/>', $merges)).'</mergeCells><pageMargins left="0.25" right="0.25" top="0.3" bottom="0.3" header="0.1" footer="0.1"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
    }

    /** @param array<string, string|int|float|null> $valores */
    private function fila(int $numero, array $valores, int $estilo, ?int $alto = 18): string
    {
        $celdas = '';
        foreach ($valores as $columna => $valor) {
            $ref = $columna.$numero;
            if ($valor === null || $valor === '') {
                $celdas .= '<c r="'.$ref.'" s="'.$estilo.'"/>';
            } elseif (is_int($valor) || is_float($valor)) {
                $celdas .= '<c r="'.$ref.'" s="'.$estilo.'"><v>'.$valor.'</v></c>';
            } else {
                $celdas .= '<c r="'.$ref.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
        }

        return $alto === null
            ? '<row r="'.$numero.'">'.$celdas.'</row>'
            : '<row r="'.$numero.'" ht="'.$alto.'" customHeight="1">'.$celdas.'</row>';
    }

    private function columna(int $numero): string
    {
        $nombre = '';
        while ($numero > 0) {
            $resto = ($numero - 1) % 26;
            $nombre = chr(65 + $resto).$nombre;
            $numero = intdiv($numero - 1, 26);
        }

        return $nombre;
    }

    private function estilos(): string
    {
        // 0 por defecto, 1 normal con borde, 2 negrita con borde, 3 título, 4 separador de colación.
        $xfs = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>';

        return $this->xml().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="9"/><name val="Arial"/></font><font><b/><sz val="9"/><name val="Arial"/></font><font><b/><sz val="13"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7E6E6"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5">'.$xfs.'</cellXfs></styleSheet>';
    }

    private function relaciones(string $relaciones): string
    {
        return $this->xml().'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relaciones.'</Relationships>';
    }

    private function xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }
}
