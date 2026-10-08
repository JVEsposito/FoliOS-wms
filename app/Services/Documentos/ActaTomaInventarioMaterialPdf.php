<?php

namespace App\Services\Documentos;

class ActaTomaInventarioMaterialPdf
{
    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    public function generar(array $datos): string
    {
        $bloques = [];
        $lineas = [];
        foreach ($datos['diferencias'] as $fila) {
            $texto = $fila['folio'].' · '.$fila['camara'].' '.$fila['posicion'].' · '.$fila['item'].' · Esperado '.$fila['esperado'].' / contado '.$fila['contado'].' '.$fila['unidad'].' · '.str_replace('_', ' ', $fila['tipo']);
            $lineas = $this->pdf->envolver($texto, 535, 8);
            if ($fila['accion']) {
                $lineas = array_merge($lineas, $this->pdf->envolver('Acción: '.str_replace('_', ' ', $fila['accion']).' · '.$fila['motivo'].($fila['ajuste_id'] ? ' · Ajuste aplicado: '.$fila['ajuste_id'] : '').($fila['posicion_destino_id'] ? ' · Posición destino: '.$fila['posicion_destino_id'] : ''), 535, 8));
            }
            $bloques[] = [...$lineas, ''];
        }
        foreach ($datos['totales_categorias'] as $total) {
            $bloques[] = $this->pdf->envolver('Total '.$total['categoria'].' ('.$total['unidad'].'): esperado '.$total['esperado'].'; contado '.$total['contado'].'; diferencia '.$total['diferencia'], 535, 8);
        }
        $resumen = ['Toma: '.$datos['id'].' · '.$datos['estado'], 'Alcance: '.implode(', ', $datos['camaras']).' · Categoría: '.($datos['categoria'] ?: 'Todas'),
            'Abierta: '.($datos['abierta_at'] ?? '—').' · '.$datos['abierta_por'], 'Revisada: '.($datos['revisada_at'] ?? '—').' · '.($datos['revisada_por'] ?? '—'),
            'Aprobada: '.($datos['aprobada_at'] ?? '—').' · '.($datos['aprobada_por'] ?? '—'),
            'Exactitud por presencia: '.($datos['exactitud_presencia_pct'] ?? '—').'% · por cantidad: '.($datos['exactitud_cantidad_pct'] ?? '—').'%'];
        $cabecera = [];
        foreach ($resumen as $texto) {
            $cabecera = array_merge($cabecera, $this->pdf->envolver($texto, 535, 8));
        }
        $hojas = [];
        $pagina = [];
        foreach ([[...$cabecera, ''], ...$bloques] as $bloque) {
            foreach (array_chunk($bloque, 53) as $parte) {
                if ($pagina && count($pagina) + count($parte) > 53) {
                    $hojas[] = $pagina;
                    $pagina = [];
                }
                $pagina = [...$pagina, ...$parte];
            }
        }
        $hojas[] = $pagina;
        $paginas = [];
        foreach ($hojas as $indice => $filas) {
            $contenido = "q 60 0 0 30 30 790 cm /Logo Do Q\n".$this->pdf->texto(105, 805, 13, 'ACTA DE TOMA DE INVENTARIO DE MATERIALES', true);
            $contenido .= $this->pdf->texto(30, 772, 8, 'Toma '.$datos['id'].' · '.$datos['estado']);
            foreach ($filas as $n => $texto) {
                $contenido .= $this->pdf->texto(30, 750 - $n * 12, 8, $texto);
            }
            $contenido .= "50 80 m 255 80 l S 335 80 m 540 80 l S\n".$this->pdf->texto(80, 63, 9, 'Supervisor de materiales').$this->pdf->texto(390, 63, 9, 'Administrador');
            $contenido .= $this->pdf->texto(30, 25, 8, 'Hoja '.($indice + 1).' de '.count($hojas));
            $paginas[] = $contenido;
        }

        return $this->pdf->generar($paginas);
    }
}
