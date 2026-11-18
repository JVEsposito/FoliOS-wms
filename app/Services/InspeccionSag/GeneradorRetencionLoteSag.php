<?php

namespace App\Services\InspeccionSag;

use App\Models\RetencionLoteSag;
use App\Services\Documentos\DocumentoPdfPlanta;
use App\Services\Existencias\GeneradorLibroXlsx;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

class GeneradorRetencionLoteSag
{
    private function antecedentes(RetencionLoteSag $caso): array
    {
        $planta = json_decode(DB::table('configuracion_contraparte_sag')->where('id', 'planta')->value('datos') ?? '{}', true);
        $eventos = DB::table('eventos_retencion_lote_sag')->where('retencion_id', $caso->id)->orderBy('version')->get();
        $datos = [
            ['Establecimiento', ($planta['nombre_establecimiento'] ?? config('app.name')).' · CSP '.($planta['csp'] ?? '—')],
            ['Cliente', $caso->cliente->codigo.' · '.$caso->cliente->nombre],
            ['Lote de recepción', $caso->numero_lote],
            ['Estado', str_replace('_', ' ', $caso->estado)],
            ['Detección', $caso->fecha_deteccion->format('d-m-Y')],
            ['Observaciones', $caso->observacion],
            ['Informe / antecedente', $caso->referencia_informe ?? '—'],
            ['Destinos restringidos', $caso->todos_destinos ? 'Todos los destinos' : (collect($caso->destinos)->pluck('nombre')->implode(' · ') ?: 'Sin restricciones de destino')],
            ['Resolución', $caso->resolucion ?? 'Pendiente'],
            ['Resultado definitivo', $caso->definitivo ? 'Sí' : 'En seguimiento'],
            ['Segregación física indicada', $caso->instruccion_segregacion ?? 'Sin instrucción de segregación'],
            ['Versión del registro', (string) $caso->version],
        ];
        foreach ($eventos as $evento) {
            $e = json_decode($evento->despues, true);
            $folios = json_decode($evento->folios_afectados, true);
            $datos[] = ['Resolución v'.$evento->version, $evento->created_at.' UTC · '.$evento->usuario_nombre.' · '
                .str_replace('_', ' ', $e['estado']).' · '.($e['resolucion'] ?? $e['observacion'])
                .' · Destinos: '.($e['todos_destinos'] ? 'Todos' : (collect($e['destinos'])->pluck('nombre')->implode(', ') ?: 'Sin restricción'))
                .' · '.count($folios).' folios identificados al registrar'];
            foreach ($folios as $folio) {
                $datos[] = ['Antecedente v'.$evento->version, $folio['folio'].' · '.$folio['cajas_lote'].' cajas del lote · '.$folio['estado'].' · '.($folio['carga'] ?? 'Sin carga')];
            }
        }

        return $datos;
    }

    public function pdf(RetencionLoteSag $caso): string
    {
        $pdf = app(DocumentoPdfPlanta::class);
        $caso->loadMissing('cliente');
        $bloques = $this->antecedentes($caso);
        $bloques[] = ['PALLETS IDENTIFICADOS AL EMITIR', 'Folio · cajas del lote / cajas del pallet · CSG · ubicación · carga'];
        foreach (app(ServicioRetencionesLoteSag::class)->foliosDelLote($caso) as $folio) {
            $bloques[] = [$folio['folio'], $folio['cajas_lote'].' / '.$folio['cajas_pallet'].' cajas · CSG '.$folio['csg']
                .' · '.($folio['ubicacion'] ?: $folio['estado']).' · '.($folio['carga'] ?? 'Sin carga')];
        }
        $bloques[] = ['Responsable / firma', '____________________________________________________'];
        $bloques[] = ['Observaciones de seguimiento', '____________________________________________________'];
        $paginas = [];
        $pagina = '';
        $y = 0;
        $nueva = function () use (&$pagina, &$y, &$paginas, $pdf, $caso): void {
            if ($pagina !== '') {
                $paginas[] = $pagina;
            }
            $pagina = "q 65 0 0 28 28 790 cm /Logo Do Q\n";
            $pagina .= $pdf->texto(112, 806, 13, 'SAG · LOTES RETENIDOS', true);
            $pagina .= $pdf->texto(112, 791, 9, 'Planilla de seguimiento y antecedentes · v'.$caso->version);
            $pagina .= $pdf->texto(28, 768, 8, 'Lote '.$caso->numero_lote.' · '.$caso->cliente->nombre.' · Página '.(count($paginas) + 1), true);
            $pagina .= $pdf->texto(28, 30, 7, 'Emitido '.now()->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i').' · Control interno de planta');
            $y = 744;
        };
        $nueva();
        foreach ($bloques as [$titulo, $texto]) {
            $lineas = $pdf->envolver($titulo.': '.$texto, 535, 8.5);
            foreach ($lineas as $indice => $linea) {
                if ($y < 58) {
                    $nueva();
                }
                $pagina .= $pdf->texto(28, $y, 8.5, $linea, $indice === 0);
                $y -= 12;
            }
            $y -= 5;
        }
        $paginas[] = $pagina;

        return $pdf->generar($paginas);
    }

    public function xlsx(RetencionLoteSag $caso): string
    {
        $caso->loadMissing('cliente');
        $filas = app(ServicioRetencionesLoteSag::class)->foliosDelLote($caso);
        $columnas = collect(['folio' => 'Folio', 'cajas_lote' => 'Cajas del lote', 'cajas_pallet' => 'Cajas del pallet',
            'csg' => 'CSG', 'ubicacion' => 'Ubicación', 'estado' => 'Estado físico', 'carga' => 'Carga'])
            ->map(fn ($titulo, $clave) => ['clave' => $clave, 'titulo' => $titulo, 'ancho' => 24])->values()->all();
        $ruta = app(GeneradorLibroXlsx::class)->generar('SAG · Lote '.$caso->numero_lote, $columnas, $filas, [
            'fecha_corte' => now()->toIso8601String(), 'temporada' => $caso->temporada_id,
            'cliente' => $caso->cliente->nombre, 'usuario' => auth()->user()?->name, 'adicionales' => $this->antecedentes($caso),
        ], 'Pallets del lote');
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo incorporar el logo al reporte.');
        }
        $tipos = $zip->getFromName('[Content_Types].xml');
        $tipos = str_replace('</Types>', '<Default Extension="jpg" ContentType="image/jpeg"/><Override PartName="/xl/drawings/logo.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>', $tipos);
        $zip->addFromString('[Content_Types].xml', $tipos);
        $hoja = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->addFromString('xl/worksheets/sheet2.xml', str_replace('</worksheet>', '<drawing xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rIdLogo"/></worksheet>', $hoja));
        $zip->addFile(resource_path('images/logo-agrorosario.jpg'), 'xl/media/logo-planta.jpg');
        $zip->addFromString('xl/worksheets/_rels/sheet2.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/logo.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/_rels/logo.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo-planta.jpg"/></Relationships>');
        $zip->addFromString('xl/drawings/logo.xml', '<?xml version="1.0"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><xdr:oneCellAnchor><xdr:from><xdr:col>3</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:ext cx="1524000" cy="685800"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="1" name="Logo planta"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rIdImage"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1524000" cy="685800"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
        if (! $zip->close()) {
            throw new RuntimeException('No se pudo finalizar la planilla SAG.');
        }

        return $ruta;
    }
}
