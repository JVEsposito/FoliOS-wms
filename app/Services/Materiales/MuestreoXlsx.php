<?php

namespace App\Services\Materiales;

use App\Enums\EstadoRecepcionMaterial;
use App\Models\DetalleRecepcionMaterial;
use App\Models\RecepcionMaterial;
use RuntimeException;
use Throwable;
use ZipArchive;

class MuestreoXlsx
{
    private const FILAS_EN_BLANCO = 10;

    public function generar(RecepcionMaterial $recepcion): string
    {
        $recepcion->loadMissing([
            'proveedor',
            'creadoPor',
            'confirmadoPor',
            'detalles.item',
            'detalles.bultos',
        ]);

        [$condicion, $porcentaje] = $this->condicionProveedor($recepcion);

        return $this->generarLibro($recepcion, $condicion, $porcentaje);
    }

    public function generarEnBlanco(): string
    {
        return $this->generarLibro(null, null, null);
    }

    private function generarLibro(
        ?RecepcionMaterial $recepcion,
        ?string $condicion,
        ?float $porcentaje,
    ): string {
        $ruta = $this->rutaTemporal('estiba-muestreo-', '.xlsx');
        $zip = null;

        try {
            $zip = new ZipArchive;
            if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No fue posible construir el registro de muestreo.');
            }

            $titulo = 'Registro de muestreo de recepción de materiales'
                .($recepcion === null ? ' en blanco' : '');
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('docProps/app.xml', $this->appProperties());
            $zip->addFromString('docProps/core.xml', $this->coreProperties($titulo));
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString(
                'xl/worksheets/sheet1.xml',
                $this->sheet($recepcion, $condicion, $porcentaje),
            );

            if ($zip->close() === false) {
                throw new RuntimeException('No fue posible finalizar el registro de muestreo.');
            }
            $zip = null;

            return $ruta;
        } catch (Throwable $excepcion) {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
            if (is_file($ruta)) {
                unlink($ruta);
            }

            throw $excepcion;
        }
    }

    /** @return array{0:string,1:float} */
    private function condicionProveedor(RecepcionMaterial $recepcion): array
    {
        $anteriores = RecepcionMaterial::query()
            ->where('proveedor_material_id', $recepcion->proveedor_material_id)
            ->where('id', '!=', $recepcion->id)
            ->where('estado', EstadoRecepcionMaterial::Confirmada->value)
            ->when(
                $recepcion->confirmado_at,
                fn ($consulta) => $consulta->where('confirmado_at', '<', $recepcion->confirmado_at),
            );

        if ($anteriores->doesntExist()) {
            return ['nuevo', 0.10];
        }

        $conDiferencias = $anteriores
            ->whereHas('detalles', fn ($detalles) => $detalles
                ->where('cantidad_rechazada', '>', 0))
            ->exists();

        return $conDiferencias
            ? ['diferencias', 0.20]
            : ['satisfactorio', 0.05];
    }

    private function sheet(
        ?RecepcionMaterial $recepcion,
        ?string $condicion,
        ?float $porcentaje,
    ): string {
        $detalles = ($recepcion?->detalles ?? collect())->values();
        $cantidadFilas = $recepcion === null
            ? self::FILAS_EN_BLANCO
            : max(4, $detalles->count());
        $filaInicio = 12;
        $filaFin = $filaInicio + $cantidadFilas - 1;
        $filaResultado = $filaFin + 2;
        $filaObservaciones = $filaResultado + 6;
        $filaFirmas = $filaObservaciones + 4;
        $ultimaFila = $filaFirmas + 2;

        $filas = [];
        $filas[] = $this->row(2, [
            $this->inlineCell('C2', 'Registro de muestreo de recepción de materiales', 1),
        ], 27);
        $filas[] = $this->row(5, [
            $this->inlineCell('C5', 'Datos Generales', 2),
            $this->inlineCell('H5', 'Condición del proveedor', 5),
            $this->inlineCell('J5', 'Muestreo', 5),
        ], 22);

        $fecha = $recepcion === null
            ? ''
            : ($recepcion->fecha_documento?->format('d-m-Y') ?? '—');
        $proveedor = collect([
            $recepcion?->proveedor?->codigo,
            $recepcion === null
                ? null
                : ($recepcion->proveedor?->nombre ?? 'Proveedor no disponible'),
        ])->filter()->implode(' · ');
        $recepcionista = $recepcion === null
            ? ''
            : ($recepcion->confirmadoPor?->name
                ?? $recepcion->creadoPor?->name
                ?? '—');
        $condiciones = [
            ['nuevo', 'Proveedor nuevo', 0.10],
            ['satisfactorio', 'Proveedor con desempeño satisfactorio', 0.05],
            ['diferencias', 'Proveedor con diferencias anteriores', 0.20],
        ];
        $generales = [
            ['Fecha', $fecha],
            ['Proveedor', $proveedor],
            ['Guía de Despacho N°', $recepcion?->numero_guia_despacho ?? ''],
            ['Recepcionista', $recepcionista],
        ];

        foreach ($generales as $indice => [$etiqueta, $valor]) {
            $fila = 6 + $indice;
            $celdas = [
                $this->inlineCell("C{$fila}", $etiqueta, 3),
                $this->inlineCell("D{$fila}", (string) $valor, 4),
            ];
            if (isset($condiciones[$indice])) {
                [$clave, $nombre, $tasa] = $condiciones[$indice];
                $marca = $clave === $condicion ? '☒' : '☐';
                $celdas[] = $this->inlineCell("H{$fila}", "{$marca} {$nombre}", 4);
                $celdas[] = $this->numberCell("J{$fila}", $tasa, 9);
            }
            $filas[] = $this->row($fila, $celdas, $indice === 2 ? 27 : 21);
        }

        $filas[] = $this->row(11, [
            $this->inlineCell('C11', 'Material', 6),
            $this->inlineCell('D11', 'Cantidad según Guía', 6),
            $this->inlineCell('E11', 'Unidad de Embalaje', 6),
            $this->inlineCell('F11', 'Cantidad de Bultos', 6),
            $this->inlineCell('G11', '% Muestreo', 6),
            $this->inlineCell('H11', 'Bultos Muestreados', 6),
            $this->inlineCell('I11', 'Conforme (Sí/No)', 6),
            $this->inlineCell('J11', 'Observaciones', 6),
        ], 34);

        for ($indice = 0; $indice < $cantidadFilas; $indice++) {
            $fila = $filaInicio + $indice;
            /** @var DetalleRecepcionMaterial|null $detalle */
            $detalle = $detalles->get($indice);
            if ($detalle === null) {
                $filas[] = $this->emptyMaterialRow($fila);

                continue;
            }

            $porcentajeAplicado = $porcentaje ?? 0.0;
            $bultos = $detalle->bultos;
            $muestreados = $bultos->isEmpty()
                ? 0
                : (int) ceil($bultos->count() * $porcentajeAplicado);
            $material = collect([
                $detalle->item?->codigo,
                $detalle->item?->nombre ?? 'Ítem no disponible',
            ])->filter()->implode(' · ');
            $filas[] = $this->row($fila, [
                $this->inlineCell("C{$fila}", $material, 7),
                $detalle->cantidad_documental === null ? $this->inlineCell("D{$fila}", 'Pendiente', 7) : $this->numberCell("D{$fila}", (float) $detalle->cantidad_documental, 8),
                $this->inlineCell("E{$fila}", $this->unidadEmbalaje($detalle), 7),
                $this->numberCell("F{$fila}", $bultos->count(), 8),
                $this->numberCell("G{$fila}", $porcentajeAplicado, 9),
                $this->numberCell("H{$fila}", $muestreados, 8),
                $this->inlineCell("I{$fila}", '', 7),
                $this->inlineCell("J{$fila}", (string) ($detalle->observacion ?? ''), 7),
            ], 29);
        }

        $filas[] = $this->row($filaResultado, [
            $this->inlineCell("C{$filaResultado}", 'Resultado del Muestreo', 10),
        ], 24);
        $filas[] = $this->row($filaResultado + 2, [
            $this->inlineCell('C'.($filaResultado + 2), '☐ Recepción Conforme', 0),
        ]);
        $filas[] = $this->row($filaResultado + 3, [
            $this->inlineCell('C'.($filaResultado + 3), '☐ Recepción Conforme con Observaciones', 0),
        ]);
        $filas[] = $this->row($filaResultado + 4, [
            $this->inlineCell('C'.($filaResultado + 4), '☐ Recepción No Conforme', 0),
        ]);
        $filas[] = $this->row($filaObservaciones, [
            $this->inlineCell("C{$filaObservaciones}", 'Observaciones:', 10),
        ]);
        $filas[] = $this->row($filaFirmas, [
            $this->inlineCell("C{$filaFirmas}", 'Elaboró', 2),
            $this->inlineCell("F{$filaFirmas}", 'Revisó', 2),
        ]);
        $filas[] = $this->row($filaFirmas + 1, [
            $this->inlineCell('C'.($filaFirmas + 1), 'Recepcionista', 0),
            $this->inlineCell('F'.($filaFirmas + 1), 'Supervisor de Bodega', 0),
        ]);
        $filas[] = $this->row($filaFirmas + 2, [
            $this->inlineCell('C'.($filaFirmas + 2), 'Firma', 0),
            $this->inlineCell('F'.($filaFirmas + 2), 'Firma', 0),
        ]);

        $mergeCells = [
            'C2:J3',
            'C5:F5',
            'D6:F6',
            'D7:F7',
            'D8:F8',
            'D9:F9',
            'H5:I5',
            'H6:I6',
            'H7:I7',
            'H8:I8',
            "C{$filaObservaciones}:J{$filaObservaciones}",
        ];
        $merges = implode('', array_map(
            fn (string $referencia): string => '<mergeCell ref="'.$referencia.'"/>',
            $mergeCells,
        ));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .'<dimension ref="C2:J'.$ultimaFila.'"/>'
            .'<sheetViews><sheetView showGridLines="0" tabSelected="1" workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr baseColWidth="10" defaultRowHeight="18"/>'
            .'<cols>'
            .'<col min="3" max="3" width="28" customWidth="1"/>'
            .'<col min="4" max="4" width="20" customWidth="1"/>'
            .'<col min="5" max="5" width="20" customWidth="1"/>'
            .'<col min="6" max="6" width="17" customWidth="1"/>'
            .'<col min="7" max="7" width="14" customWidth="1"/>'
            .'<col min="8" max="8" width="15" customWidth="1"/>'
            .'<col min="9" max="9" width="18" customWidth="1"/>'
            .'<col min="10" max="10" width="24" customWidth="1"/>'
            .'</cols><sheetData>'.implode('', $filas).'</sheetData>'
            .'<mergeCells count="'.count($mergeCells).'">'.$merges.'</mergeCells>'
            .'<pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>'
            .'</worksheet>';
    }

    private function unidadEmbalaje(DetalleRecepcionMaterial $detalle): string
    {
        $cantidades = $detalle->bultos
            ->map(fn ($bulto): float => round((float) $bulto->cantidad, 3))
            ->filter(fn (float $cantidad): bool => $cantidad > 0)
            ->unique()
            ->sortDesc()
            ->values();

        if ($cantidades->isEmpty()) {
            return '—';
        }

        $principal = $this->numero($cantidades->first());
        $texto = "{$principal} {$detalle->unidad_medida}/bulto";
        if ($cantidades->count() > 1) {
            $restos = $cantidades->slice(1)->map(fn (float $valor): string => $this->numero($valor));
            $texto .= ' · saldos '.$restos->implode(', ');
        }

        return $texto;
    }

    private function emptyMaterialRow(int $fila): string
    {
        $celdas = [];
        foreach (range('C', 'J') as $columna) {
            $celdas[] = $this->inlineCell($columna.$fila, '', 7);
        }

        return $this->row($fila, $celdas, 29);
    }

    /** @param array<int, string> $celdas */
    private function row(int $fila, array $celdas, ?float $alto = null): string
    {
        $atributos = $alto === null ? '' : ' ht="'.$this->numero($alto).'" customHeight="1"';

        return '<row r="'.$fila.'"'.$atributos.'>'.implode('', $celdas).'</row>';
    }

    private function inlineCell(string $referencia, string $valor, int $estilo): string
    {
        return '<c r="'.$referencia.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'
            .$this->xml($valor).'</t></is></c>';
    }

    private function numberCell(string $referencia, float|int $valor, int $estilo): string
    {
        return '<c r="'.$referencia.'" s="'.$estilo.'"><v>'.$this->numero($valor).'</v></c>';
    }

    private function numero(float|int $valor): string
    {
        $numero = number_format((float) $valor, 3, '.', '');

        return rtrim(rtrim($numero, '0'), '.');
    }

    private function xml(string $valor): string
    {
        return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function rutaTemporal(string $prefijo, string $extension): string
    {
        $ruta = tempnam(sys_get_temp_dir(), $prefijo);
        if ($ruta === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal.');
        }
        if (is_file($ruta)) {
            unlink($ruta);
        }

        return $ruta.$extension;
    }

    private function contentTypes(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>
XML;
    }

    private function rootRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function appProperties(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <Application>FoliOS</Application>
    <DocSecurity>0</DocSecurity>
    <ScaleCrop>false</ScaleCrop>
    <HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Hojas de cálculo</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>
    <TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Registro de muestreo</vt:lpstr></vt:vector></TitlesOfParts>
</Properties>
XML;
    }

    private function coreProperties(string $titulo): string
    {
        $ahora = now()->utc()->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>'.$this->xml($titulo).'</dc:title><dc:creator>FoliOS</dc:creator>'
            .'<cp:lastModifiedBy>FoliOS</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$ahora.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$ahora.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function workbook(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>
    <sheets><sheet name="Registro de muestreo" sheetId="1" r:id="rId1"/></sheets>
    <calcPr calcId="191029" fullCalcOnLoad="1" forceFullCalc="1"/>
</workbook>
XML;
    }

    private function workbookRelationships(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>
XML;
    }

    private function styles(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.###"/></numFmts>
    <fonts count="4">
        <font><sz val="11"/><name val="Aptos Narrow"/><family val="2"/></font>
        <font><b/><sz val="11"/><name val="Aptos Narrow"/><family val="2"/></font>
        <font><sz val="22"/><name val="Aptos Narrow"/><family val="2"/></font>
        <font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos Narrow"/><family val="2"/></font>
    </fonts>
    <fills count="4">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FF185C2B"/><bgColor indexed="64"/></patternFill></fill>
        <fill><patternFill patternType="solid"><fgColor rgb="FFB7E1A1"/><bgColor indexed="64"/></patternFill></fill>
    </fills>
    <borders count="2">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border><left style="thin"><color rgb="FF333333"/></left><right style="thin"><color rgb="FF333333"/></right><top style="thin"><color rgb="FF333333"/></top><bottom style="thin"><color rgb="FF333333"/></bottom><diagonal/></border>
    </borders>
    <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
    <cellXfs count="11">
        <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
        <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="9" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>
    </cellXfs>
    <cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>
</styleSheet>
XML;
    }
}
