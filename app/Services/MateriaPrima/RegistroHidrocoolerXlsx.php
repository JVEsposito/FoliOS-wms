<?php

namespace App\Services\MateriaPrima;

use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;
use ZipArchive;

class RegistroHidrocoolerXlsx
{
    public function __construct(private readonly RegistroControlHidrocooler $registro) {}

    public function generar(Collection $procesos): string
    {
        return $this->libro($this->registro->paginas($procesos));
    }

    public function generarEnBlanco(): string
    {
        return $this->libro($this->registro->paginas(collect(), true));
    }

    private function libro(array $hojas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'poemp-r3-');
        if ($ruta === false) {
            throw new RuntimeException('No fue posible crear el registro de hidrocooler.');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No fue posible construir el registro de hidrocooler.');
            }
            $tipos = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $sheets = $rels = $areas = '';
            foreach ($hojas as $i => $hoja) {
                $n = $i + 1;
                $tipos .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/drawings/drawing'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
                $sheets .= '<sheet name="POEMP-R3 '.$n.'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
                $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                $areas .= '<definedName name="_xlnm.Print_Area" localSheetId="'.$i.'">&apos;POEMP-R3 '.$n.'&apos;!$A$1:$V$'.($hoja['anexo'] ? 70 : 43).'</definedName>';
                $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $this->hoja($hoja, $n));
                $zip->addFromString('xl/worksheets/_rels/sheet'.$n.'.xml.rels', $this->relaciones('<Relationship Id="logo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$n.'.xml"/>'));
                $zip->addFromString('xl/drawings/drawing'.$n.'.xml', $this->dibujo());
                $zip->addFromString('xl/drawings/_rels/drawing'.$n.'.xml.rels', $this->relaciones('<Relationship Id="imagen" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.jpg"/>'));
            }
            $rels .= '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $zip->addFromString('[Content_Types].xml', $this->xml().'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$tipos.'</Types>');
            $zip->addFromString('_rels/.rels', $this->relaciones('<Relationship Id="libro" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'));
            $zip->addFromString('xl/workbook.xml', $this->xml().'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets><definedNames>'.$areas.'</definedNames></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relaciones($rels));
            $zip->addFromString('xl/styles.xml', $this->estilos());
            $zip->addFile(resource_path('images/logo-agrorosario.jpg'), 'xl/media/logo.jpg');
            if (! $zip->close()) {
                throw new RuntimeException('No fue posible finalizar el registro.');
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

    private function hoja(array $hoja, int $n): string
    {
        $f = $hoja['formato'];
        $rows = $this->fila(1, ['D' => 'REGISTRO CONTROL HIDROCOOLER', 'S' => 'Código: '.$f['codigo']], 24, 2)
            .$this->fila(2, ['S' => 'Versión: '.$f['version']], 20)
            .$this->fila(3, ['D' => $f['localidad'], 'S' => 'Fecha: '.date('d-m-Y', strtotime($f['fecha_vigencia']))], 20);
        $merges = ['A1:C3', 'D1:R2', 'D3:R3', 'S1:V1', 'S2:V2', 'S3:V3'];
        if (! $hoja['anexo']) {
            $rows .= $this->fila(5, array_combine(range('A', 'V'), RegistroControlHidrocooler::COLUMNAS), 44, 2);
            for ($i = 0; $i < RegistroControlHidrocooler::FILAS; $i++) {
                $rows .= $this->fila(6 + $i, array_combine(range('A', 'V'), $hoja['filas'][$i] ?? array_fill(0, 22, '')), 22);
            }
            $rows .= $this->fila(31, ['A' => 'Observaciones'], 16, 2);
            $rows .= $this->fila(32, ['A' => implode("\n", $hoja['notas'])], 80, 4);
            $merges[] = 'A31:V31';
            $merges[] = 'A32:V36';
            $rows .= $this->fila(40, ['A' => 'Jefe de Calidad', 'M' => 'Responsable'], 22, 3);
            $rows .= $this->fila(41, ['A' => '', 'M' => ''], 28, 0);
            array_push($merges, 'A40:J40', 'M40:V40', 'A41:J42', 'M41:V42');
            $last = 43;
        } else {
            $rows .= $this->fila(5, ['A' => 'Observaciones - continuación de hoja '.$hoja['origen']], 22, 2);
            $merges[] = 'A5:V5';
            foreach ($hoja['notas'] as $i => $nota) {
                $row = 6 + $i;
                $rows .= $this->fila($row, ['A' => $nota], 10, 4);
                $merges[] = 'A'.$row.':V'.$row;
            }
            $rows .= $this->fila(68, ['A' => 'Jefe de Calidad', 'M' => 'Responsable'], 20, 3);
            array_push($merges, 'A68:J68', 'M68:V68');
            $last = 70;
        }
        $cols = '';
        foreach ([10, 10, 11, 14, 15, 9, 10, 8, 9, 9, 9, 10, 10, 12, 16, 12, 9, 10, 21, 12, 15, 20] as $i => $ancho) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$ancho.'" customWidth="1"/>';
        }

        return $this->xml().'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:V'.$last.'"/><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="10"/><cols>'.$cols.'</cols><sheetData>'.$rows.'</sheetData><mergeCells count="'.count($merges).'">'.implode('', array_map(fn ($m) => '<mergeCell ref="'.$m.'"/>', $merges)).'</mergeCells><printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.25" bottom="0.25" header="0.1" footer="0.1"/><pageSetup paperSize="8" orientation="landscape" fitToWidth="1" fitToHeight="1"/><headerFooter><oddFooter>&amp;RHoja '.$n.'</oddFooter></headerFooter><drawing r:id="logo"/></worksheet>';
    }

    private function fila(int $numero, array $valores, int $alto, int $estilo = 1): string
    {
        $cells = '';
        foreach ($valores as $col => $valor) {
            $cells .= '<c r="'.$col.$numero.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }

        return '<row r="'.$numero.'" ht="'.$alto.'" customHeight="1">'.$cells.'</row>';
    }

    private function dibujo(): string
    {
        return $this->xml().'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>60000</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>60000</xdr:rowOff></xdr:from><xdr:ext cx="1600000" cy="650000"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="AgroRosario"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="imagen"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1600000" cy="650000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
    }

    private function estilos(): string
    {
        $estilos = '';
        foreach ([[0, 0, 'center', 'center'], [0, 1, 'center', 'center'], [1, 1, 'center', 'center'], [1, 0, 'center', 'center'], [0, 1, 'left', 'top']] as [$fuente, $borde, $horizontal, $vertical]) {
            $estilos .= '<xf numFmtId="0" fontId="'.$fuente.'" fillId="0" borderId="'.$borde.'" xfId="0" applyAlignment="1" applyBorder="1"><alignment wrapText="1" horizontal="'.$horizontal.'" vertical="'.$vertical.'"/></xf>';
        }

        return $this->xml().'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="8"/><name val="Arial"/></font><font><b/><sz val="8"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="2"><border><left/><right/><top/><bottom/></border><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5">'.$estilos.'</cellXfs></styleSheet>';
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
