<?php

namespace App\Services\Validacion;

use App\Services\Documentos\DocumentoPdfPlanta;
use App\Services\Materiales\GeneradorEtiquetaMaterialPdf;
use DomainException;

class GeneradorEtiquetaPtPdf extends GeneradorEtiquetaMaterialPdf
{
    public function generarPt(array $etiquetas, string $tipo, ?int $copias = null): string
    {
        $copias ??= $tipo === 'planta' ? 4 : 1;
        if (! in_array($tipo, ['folio', 'ventana', 'planta'], true) || $etiquetas === [] || $copias < 1 || $copias > 10) {
            throw new DomainException('Selecciona etiquetas, formato y cantidad de copias válidos.');
        }

        return $this->generar(
            array_map(fn ($etiqueta) => [...$etiqueta, 'tipo_etiqueta' => $tipo], $etiquetas),
            ['ancho_mm' => $tipo === 'planta' ? 107 : 100,
                'alto_mm' => $tipo === 'planta' ? 74 : ($tipo === 'folio' ? 50 : 200),
                'orientacion' => $tipo === 'ventana' ? 'vertical' : 'horizontal'],
            $copias,
        );
    }

    protected function pagina(array $etiqueta, float $ancho, float $alto): string
    {
        if ($etiqueta['tipo_etiqueta'] === 'planta') {
            return $this->paginaPlanta($etiqueta, $ancho, $alto);
        }
        $ventana = $etiqueta['tipo_etiqueta'] === 'ventana';
        $margen = 12;
        $util = $ancho - 2 * $margen;
        $numero = (string) $etiqueta['numero_folio'];
        $tamanoFolio = min($ventana ? 30 : 22, $util / max(1, strlen($numero)) / 0.67);
        if ($tamanoFolio < 8) {
            throw new DomainException('El folio es demasiado largo para imprimirlo legiblemente.');
        }
        $titulo = 'FoliOS · PT · '.strtoupper($etiqueta['tipo_bulto']);
        if ($etiqueta['estado_operacional'] === 'bloqueado') {
            $titulo .= ' · BLOQUEADO';
        }
        $contenido = $this->texto($margen, $alto - 16, $ventana ? 11 : 8, $titulo);
        $contenido .= $this->texto($margen, $alto - ($ventana ? 53 : 40), $tamanoFolio, $numero, true);
        $barcodeY = $alto - ($ventana ? 130 : 73);
        $contenido .= $this->codigoBarras($numero, $margen, $barcodeY, $util, $ventana ? 55 : 24, true);
        $y = $barcodeY - ($ventana ? 22 : 14);
        $tamanoDetalle = $ventana ? 12 : 8;
        $lineas = [
            $etiqueta['especie'].' · '.$etiqueta['variedad'].' · Calibre '.$etiqueta['calibre'],
            $etiqueta['cantidad_cajas'].' cajas · '.$etiqueta['envase'].' · '.$etiqueta['categoria'],
        ];
        if ($ventana) {
            $lineas = [...$lineas,
                'Cliente: '.$etiqueta['cliente'], 'Marca: '.$etiqueta['marca'],
                'Temporada: '.$etiqueta['temporada'],
                'Estado: '.str_replace('_', ' ', $etiqueta['estado_operacional']),
                'Línea '.$etiqueta['linea_proceso'].' · Turno '.$etiqueta['turno'],
                'Validador: '.$etiqueta['validador'],
            ];
            foreach ($etiqueta['composicion'] as $segmento) {
                $lineas[] = 'CSG '.$segmento['csg'].' · '.($segmento['predio'] ?? '')
                    .' · '.$segmento['cantidad_cajas'].' cajas · Emb. '.($segmento['fecha_embalaje'] ?? '—');
                if (filled($segmento['lote_materia_prima'] ?? null)) {
                    $lineas[] = 'Lote MP: '.$segmento['lote_materia_prima'];
                }
                if (filled($segmento['proceso_packing'] ?? null)) {
                    $lineas[] = 'Proceso packing: '.$segmento['proceso_packing'];
                }
            }
            if ($etiqueta['composicion'] === []) {
                $lineas[] = 'CSG '.$etiqueta['csg'].' · '.$etiqueta['predio'].' · Emb. '.($etiqueta['fecha_embalaje'] ?? '—');
            }
        } else {
            $lineas[] = 'CSG '.$etiqueta['csg'].' · '.$etiqueta['cliente'].' · '.$etiqueta['marca'];
            $lineas[] = 'Emb. '.($etiqueta['fecha_embalaje'] ?? '—').' · '.$etiqueta['temporada'];
        }
        $envolver = new DocumentoPdfPlanta;
        foreach ($lineas as $indice => $linea) {
            foreach ($envolver->envolver($linea, $util, $tamanoDetalle) as $parte) {
                if ($y < $margen) {
                    throw new DomainException('La información no cabe en la etiqueta. Selecciona ventana o revisa la composición del pallet.');
                }
                $contenido .= $this->texto($margen, $y, $tamanoDetalle, $parte, $indice < 2);
                $y -= $ventana ? 16 : 10;
            }
            if ($ventana) {
                $y -= 4;
            }
        }

        return $contenido;
    }

    private function paginaPlanta(array $etiqueta, float $ancho, float $alto): string
    {
        $margen = 9;
        $util = $ancho - 2 * $margen;
        $contenido = $this->textoAjustado($margen, $alto - 18, $util - 88, 11, (string) $etiqueta['cliente'], true);
        $contenido .= $this->texto($ancho - 90, $alto - 18, 9, $etiqueta['origen'] === 'repaletizaje' ? 'REPALETIZADO' : 'PROCESO', true);
        $contenido .= $this->textoAjustado($margen, $alto - 48, $util, 25, (string) $etiqueta['envase_codigo'], true);
        $contenido .= $this->textoAjustado($margen, $alto - 64, $util, 12, (string) $etiqueta['envase'], true);
        $fecha = $etiqueta['fecha_proceso'] ? implode('-', array_reverse(explode('-', $etiqueta['fecha_proceso']))) : '—';
        foreach ([
            [$margen, $alto - 80, 'ESPECIE', $etiqueta['especie'], 167],
            [$margen + 177, $alto - 80, 'F. PROCESO', $fecha, $util - 177],
            [$margen, $alto - 108, 'VARIEDAD', $etiqueta['variedad'], 131],
            [$margen + 141, $alto - 108, 'KILOS NETOS', $etiqueta['kilos_netos'] === null ? '—' : number_format((float) $etiqueta['kilos_netos'], 2, ',', ''), 79],
            [$margen + 230, $alto - 108, 'CAJAS', (string) $etiqueta['cantidad_cajas'], $util - 230],
        ] as [$x, $y, $titulo, $valor, $espacio]) {
            $contenido .= $this->texto($x, $y, 7, $titulo);
            $contenido .= $this->textoAjustado($x, $y - 14, $espacio, 12, (string) $valor, true);
        }
        $contenido .= $this->texto($margen, 61, 8, 'PALLET', true);
        $contenido .= $this->textoAjustado($margen + 41, 59, $util - 41, 21, (string) $etiqueta['numero_folio'], true);
        $contenido .= $this->codigoBarras((string) $etiqueta['numero_folio'], $margen, 10, $util, 39, true);

        return $contenido;
    }

    private function textoAjustado(float $x, float $y, float $ancho, float $tamano, string $texto, bool $negrita): string
    {
        $tamano = min($tamano, $ancho / max(1, mb_strlen($texto)) / 0.67);
        if ($tamano < 6) {
            throw new DomainException('La información no cabe legiblemente en la etiqueta de planta. Revisa el nombre o código del envase.');
        }

        return $this->texto($x, $y, $tamano, $texto, $negrita);
    }
}
