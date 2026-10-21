<?php

namespace App\Services\InspeccionSag;

use App\Models\DocumentoSag;
use App\Models\ResponsableContraparteSag;
use App\Services\Documentos\DocumentoPdfPlanta;
use App\Services\Documentos\ServicioFormatosRegistro;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

class GeneradorDocumentoSag
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function datos(?DocumentoSag $documento, string $tipo): array
    {
        $formato = $documento?->formato_snapshot ?? app(ServicioFormatosRegistro::class)->vigente(ServicioDocumentosSag::FORMATOS[$tipo]);
        $configuracion = DB::table('configuracion_contraparte_sag')->where('id', 'planta')->first();
        $planta = $documento?->planta_snapshot ?? ($configuracion ? json_decode($configuracion->datos, true) : []);
        $responsable = $documento?->responsable_snapshot ?? ($documento ? ResponsableContraparteSag::find($documento->responsable_id)?->toArray() : []);
        $detalle = $documento?->detalle ?? [];
        $encabezado = $documento?->encabezado ?? [];
        $filas = [];
        if ($tipo === 'repaletizaje') {
            $columnas = ['Tipo actividad', 'N° actividad', 'Folio original', 'Envases originales', 'Folio repaletizado', 'Envases resultantes'];
            $origenes = $detalle['originales'] ?? [];
            $resultados = $detalle['resultados'] ?? [];
            for ($i = 0; $i < max(count($origenes), count($resultados)); $i++) {
                $o = $origenes[$i] ?? [];
                $r = $resultados[$i] ?? [];
                $filas[] = [$o['tipo_actividad'] ?? '', $o['numero_actividad'] ?? '', $o['folio'] ?? '', $o['cantidad'] ?? '', $r['folio'] ?? '', $r['cantidad'] ?? ''];
            }
            $total = collect($resultados)->sum('cantidad');
            $capacidad = 32;
        } elseif ($tipo === 'cambio_mercado') {
            $columnas = ['Tipo actividad', 'N° actividad', 'Folio', 'N° lote', 'Producto', 'Cantidad envases', 'Tipo envase', 'Cantidad producto', 'Unidad', 'Destino aprobado', 'Nuevo destino'];
            foreach ($detalle['lineas'] ?? [] as $l) {
                $filas[] = [$l['tipo_actividad'], $l['numero_actividad'], $l['folio'], $l['lote'], 'FRUTA FRESCA: '.$l['especie'], $l['cantidad'], 'CAJAS', $l['kilos'] ?? '', 'KILOGRAMOS', $l['mercados_anteriores'], $l['nuevo_destino']];
            }
            $total = collect($detalle['lineas'] ?? [])->sum('cantidad');
            $capacidad = 20;
        } else {
            $columnas = ['Tipo actividad', 'N° actividad', 'Folio', 'Especie', 'Condición', 'Producto', 'Cantidad envases', 'Tipo envase', 'Causal de anulación'];
            foreach ($detalle['lineas'] ?? [] as $l) {
                $filas[] = [$l['tipo_actividad'], $l['numero_actividad'], $l['folio'], $l['especie'], 'FRESCO', $l['producto'], $l['cantidad'], 'CAJAS', $l['causal']];
            }
            $total = collect($detalle['lineas'] ?? [])->sum('cantidad');
            $capacidad = 20;
        }

        return [
            'tipo' => $tipo, 'formato' => $formato, 'filas' => $filas, 'columnas' => $columnas, 'capacidad' => $capacidad,
            'campos' => [
                'codigo' => $formato['codigo'], 'version' => $formato['version'], 'vigencia' => $formato['fecha_vigencia'],
                'numero' => $documento?->numero ?? '', 'fecha' => $documento?->fecha_actividad->format('d-m-Y') ?? '',
                'estado' => $documento ? ($documento->estado === 'borrador' ? 'BORRADOR' : 'EMITIDO') : 'EN BLANCO',
                'establecimiento' => $planta['nombre_establecimiento'] ?? '', 'csp' => $planta['csp'] ?? '',
                'oficina_sag' => $planta['oficina_sag'] ?? '',
                'especie' => collect($detalle['originales'] ?? $detalle['lineas'] ?? [])->pluck('especie')->filter()->unique()->implode(', '),
                'solicitud' => $detalle['solicitud'] ?? $encabezado['solicitud'] ?? '',
                'certificados' => $encabezado['certificados'] ?? '', 'devueltos' => $encabezado['certificados_devueltos'] ?? '',
                'asignados' => $encabezado['certificados_asignados'] ?? '', 'total' => $documento ? $total : '',
                'observaciones' => $encabezado['observaciones'] ?? '', 'responsable' => $responsable['nombre'] ?? '',
                'cargo' => $responsable['cargo'] ?? '', 'nota' => $encabezado['nota'] ?? '',
                'programa' => $encabezado['programa'] ?? '', 'motivo' => $encabezado['motivo'] ?? '',
                'total_kilos' => $documento ? collect($detalle['lineas'] ?? [])->sum(fn ($l) => (int) round(($l['kilos'] ?? 0) * 10000)) / 10000 : '',
            ],
        ];
    }

    public function xlsx(?DocumentoSag $documento, string $tipo): string
    {
        $datos = $this->datos($documento, $tipo);
        $ruta = tempnam(sys_get_temp_dir(), 'sag-documento-');
        if ($ruta === false || ! copy(resource_path('templates/sag/'.$tipo.'-01.xlsx'), $ruta)) {
            throw new RuntimeException('No se pudo preparar la planilla SAG.');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($ruta) !== true) {
                throw new RuntimeException('No se pudo abrir la plantilla SAG.');
            }
            $plantilla = $zip->getFromName('xl/worksheets/sheet1.xml');
            $paginas = array_chunk($datos['filas'], $datos['capacidad']) ?: [[]];
            $libro = $this->xml($zip->getFromName('xl/workbook.xml'));
            $xp = new DOMXPath($libro);
            $sheets = $xp->query('//*[local-name()="sheets"]')->item(0);
            while ($sheets->firstChild) {
                $sheets->removeChild($sheets->firstChild);
            }
            foreach (iterator_to_array($xp->query('//*[local-name()="definedNames"]')) as $nodo) {
                $nodo->parentNode->removeChild($nodo);
            }
            $areas = $libro->createElementNS(self::NS, 'definedNames');
            $libro->documentElement->appendChild($areas);
            $relaciones = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $tipos = $this->xml($zip->getFromName('[Content_Types].xml'));
            foreach (iterator_to_array((new DOMXPath($tipos))->query('//*[local-name()="Override" and contains(@PartName,"/xl/worksheets/")]')) as $nodo) {
                $nodo->parentNode->removeChild($nodo);
            }
            foreach ($paginas as $i => $filas) {
                $n = $i + 1;
                $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $this->llenar($plantilla, $datos, $filas, $n, count($paginas)));
                $sheet = $libro->createElementNS(self::NS, 'sheet');
                $sheet->setAttribute('name', 'Página '.$n);
                $sheet->setAttribute('sheetId', (string) $n);
                $sheet->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', 'sheet'.$n);
                $sheets->appendChild($sheet);
                $area = $libro->createElementNS(self::NS, 'definedName', "'Página {$n}'!\$A\$1:\$".chr(64 + count($datos['columnas'])).'$'.(14 + $datos['capacidad'] + 12));
                $area->setAttribute('name', '_xlnm.Print_Area');
                $area->setAttribute('localSheetId', (string) $i);
                $areas->appendChild($area);
                $relaciones .= '<Relationship Id="sheet'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                $override = $tipos->createElementNS($tipos->documentElement->namespaceURI, 'Override');
                $override->setAttribute('PartName', '/xl/worksheets/sheet'.$n.'.xml');
                $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml');
                $tipos->documentElement->appendChild($override);
            }
            $relaciones .= '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="strings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';
            $zip->addFromString('xl/_rels/workbook.xml.rels', $relaciones);
            $zip->addFromString('xl/workbook.xml', $libro->saveXML());
            $zip->addFromString('[Content_Types].xml', $tipos->saveXML());
            $zip->close();

            return $ruta;
        } catch (Throwable $error) {
            @$zip->close();
            @unlink($ruta);
            throw $error;
        }
    }

    private function llenar(string $plantilla, array $datos, array $filas, int $pagina, int $paginas): string
    {
        $xml = $this->xml($plantilla);
        $xp = new DOMXPath($xml);
        $campos = [...$datos['campos'], 'pagina' => $pagina, 'paginas' => $paginas];
        $tokens = [];
        foreach ($campos as $clave => $valor) {
            $tokens['{{'.$clave.'}}'] = (string) $valor;
        }
        foreach ($xp->query('//*[local-name()="c"]') as $celda) {
            $valor = $xp->query('./*[local-name()="v"]', $celda)->item(0);
            if ($valor && str_contains($valor->textContent, '{{')) {
                $this->valor($xml, $celda, strtr($valor->textContent, $tokens));
            }
        }
        foreach ($filas as $i => $fila) {
            foreach ($fila as $j => $valor) {
                $direccion = chr(65 + $j).(14 + $i);
                $celda = $xp->query('//*[local-name()="c" and @r="'.$direccion.'"]')->item(0);
                $this->valor($xml, $celda, $valor);
            }
        }
        $pr = $xml->createElementNS(self::NS, 'sheetPr');
        $fit = $xml->createElementNS(self::NS, 'pageSetUpPr');
        $fit->setAttribute('fitToPage', '1');
        $pr->appendChild($fit);
        $xml->documentElement->insertBefore($pr, $xml->documentElement->firstChild);
        $margen = $xml->createElementNS(self::NS, 'pageMargins');
        foreach (['left' => '.25', 'right' => '.25', 'top' => '.3', 'bottom' => '.3', 'header' => '.1', 'footer' => '.1'] as $k => $v) {
            $margen->setAttribute($k, $v);
        }
        $xml->documentElement->appendChild($margen);
        $setup = $xml->createElementNS(self::NS, 'pageSetup');
        foreach (['paperSize' => '9', 'orientation' => count($datos['columnas']) > 6 ? 'landscape' : 'portrait', 'fitToWidth' => '1', 'fitToHeight' => '1'] as $k => $v) {
            $setup->setAttribute($k, $v);
        }
        $xml->documentElement->appendChild($setup);

        return $xml->saveXML();
    }

    private function valor(DOMDocument $xml, DOMElement $celda, mixed $valor): void
    {
        while ($celda->firstChild) {
            $celda->removeChild($celda->firstChild);
        }
        if (is_int($valor) || is_float($valor)) {
            $celda->removeAttribute('t');
        } else {
            $celda->setAttribute('t', 'str');
        }
        $v = $xml->createElementNS(self::NS, 'v');
        $v->appendChild($xml->createTextNode((string) $valor));
        $celda->appendChild($v);
    }

    private function xml(string $contenido): DOMDocument
    {
        $xml = new DOMDocument;
        $xml->loadXML($contenido, LIBXML_NONET);

        return $xml;
    }

    public function pdf(?DocumentoSag $documento, string $tipo): string
    {
        $datos = $this->datos($documento, $tipo);
        $pdf = app(DocumentoPdfPlanta::class);
        $ancho = count($datos['columnas']) > 6 ? 842 : 595;
        $alto = $ancho === 842 ? 595 : 842;
        $columna = ($ancho - 48) / count($datos['columnas']);
        $tamano = $ancho === 842 ? 7 : 8;
        $paginas = [];
        $filas = $datos['filas'] ?: array_fill(0, $datos['capacidad'], array_fill(0, count($datos['columnas']), ''));
        $bloques = [];
        $actual = [];
        $altura = 0;
        foreach ($filas as $fila) {
            $lineas = array_map(fn ($v) => $pdf->envolver((string) $v, $columna - 8, $tamano), $fila);
            $h = max(14, max(array_map('count', $lineas)) * ($tamano + 2) + 4);
            if ($actual !== [] && ($altura + $h > $alto - 280 || count($actual) >= $datos['capacidad'])) {
                $bloques[] = $actual;
                $actual = [];
                $altura = 0;
            }
            $actual[] = ['lineas' => $lineas, 'alto' => $h];
            $altura += $h;
        }
        $bloques[] = $actual;
        $observaciones = $pdf->envolver(($tipo === 'cambio_mercado' ? 'Motivo: '.$datos['campos']['motivo'].'. ' : '').'Observaciones: '.$datos['campos']['observaciones'].' '.$datos['campos']['nota'], $ancho - 48, 8);
        $anexos = array_chunk(array_slice($observaciones, 3), (int) floor(($alto - 140) / 12));
        $totalPaginas = count($bloques) + count($anexos);
        foreach ($bloques as $i => $bloque) {
            $c = $datos['campos'];
            $titulo = match ($tipo) {
                'repaletizaje' => 'REPALETIZAJE', 'cambio_mercado' => 'CAMBIO DE MERCADO', default => 'ANULACIÓN DE PRODUCTOS APROBADOS Y DEVOLUCIÓN DE CERTIFICADOS'
            };
            $p = $pdf->texto(24, $alto - 30, 11, $titulo, true);
            foreach ([$c['codigo'].' · Versión de planta '.$c['version'].' · Vigencia '.$c['vigencia'],
                'N° '.$c['numero'].' · Fecha '.$c['fecha'].' · '.$c['estado'].' · Hoja '.($i + 1).' de '.$totalPaginas,
                'Establecimiento: '.$c['establecimiento'].' · CSP '.$c['csp'],
                'Oficina SAG: '.$c['oficina_sag'].' · Especie: '.$c['especie'],
                match ($tipo) {
                    'repaletizaje' => 'Solicitud: '.$c['solicitud'], 'cambio_mercado' => 'Programa: '.$c['programa'], default => 'Certificados que anula o devuelve: '.$c['certificados']
                }] as $j => $texto) {
                $p .= $pdf->texto(24, $alto - 48 - $j * 15, 8, $texto);
            }
            $y = $alto - 145;
            foreach ($datos['columnas'] as $j => $nombre) {
                foreach ($pdf->envolver($nombre, $columna - 8, $tamano) as $k => $linea) {
                    $p .= $pdf->texto(28 + $j * $columna, $y - $k * ($tamano + 2), $tamano, $linea, true);
                }
            }
            $y -= 35;
            foreach ($bloque as $fila) {
                foreach ($fila['lineas'] as $j => $lineas) {
                    foreach ($lineas as $k => $linea) {
                        $p .= $pdf->texto(28 + $j * $columna, $y - 12 - $k * ($tamano + 2), $tamano, $linea);
                    }
                    $p .= sprintf("0.3 w %.2F %.2F %.2F %.2F re S\n", 24 + $j * $columna, $y - $fila['alto'], $columna, $fila['alto']);
                }
                $y -= $fila['alto'];
            }
            $p .= $pdf->texto(24, 110, 9, 'Total documento: '.$c['total'].' envases'.($tipo === 'cambio_mercado' ? ' · '.$c['total_kilos'].' kg' : '').' · Devueltos: '.$c['devueltos'].' · Asignados: '.$c['asignados']);
            foreach (array_slice($observaciones, 0, 3) as $k => $linea) {
                $p .= $pdf->texto(24, 94 - $k * 10, 8, $linea);
            }
            $p .= $pdf->texto(24, 48, 8, $c['responsable'].' · '.$c['cargo']);
            $p .= $pdf->texto(24, 30, 8, 'Firma contraparte: ____________________       Nombre, cargo y firma de quien recibe / resuelve: ____________________');
            $paginas[] = $p;
        }
        foreach ($anexos as $anexo) {
            $p = $pdf->texto(24, $alto - 30, 11, 'ANEXO DE OBSERVACIONES · '.$datos['campos']['codigo'], true);
            $p .= $pdf->texto(24, $alto - 48, 8, 'N° '.$datos['campos']['numero'].' · Hoja '.(count($paginas) + 1).' de '.$totalPaginas);
            foreach ($anexo as $k => $linea) {
                $p .= $pdf->texto(24, $alto - 75 - $k * 12, 8, $linea);
            }
            $p .= $pdf->texto(24, 48, 8, $datos['campos']['responsable']);
            $p .= $pdf->texto(24, 30, 8, 'Firma contraparte: ____________________       Nombre, cargo y firma de quien recibe / resuelve: ____________________');
            $paginas[] = $p;
        }

        return $pdf->generar($paginas, [$ancho, $alto]);
    }
}
