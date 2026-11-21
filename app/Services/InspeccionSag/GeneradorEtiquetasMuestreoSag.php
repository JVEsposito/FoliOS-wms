<?php

namespace App\Services\InspeccionSag;

use App\Services\Documentos\DocumentoPdfPlanta;
use Illuminate\Validation\ValidationException;

class GeneradorEtiquetasMuestreoSag
{
    public function __construct(
        private readonly DocumentoPdfPlanta $pdf,
        private readonly NumeracionDiagramaPallets $numeracion,
    ) {}

    public function generar(array $diagrama, string $formato): string
    {
        if ($diagrama['cajas_marcadas'] === [] || blank($diagrama['exportadora'])) {
            throw ValidationException::withMessages(['etiquetas' => 'Selecciona al menos una caja e ingresa la exportadora.']);
        }

        [$anchoPagina, $altoPagina, $columnas, $filas, $margen] = $formato === 'a4'
            ? [595, 842, 2, 7, 18]
            : [283.46, 141.73, 1, 1, 0];
        $ancho = ($anchoPagina - 2 * $margen) / $columnas;
        $alto = ($altoPagina - 2 * $margen) / $filas;
        $numeracion = $this->numeracion->numerar($diagrama['pallets']);
        $fuenteEmpresa = 9;
        $empresa = $this->pdf->envolver($diagrama['exportadora'], $ancho - 22, $fuenteEmpresa);
        if (count($empresa) > 4) {
            $fuenteEmpresa = 7.5;
            $empresa = $this->pdf->envolver($diagrama['exportadora'], $ancho - 22, $fuenteEmpresa);
        }
        $paginas = [];
        foreach (array_chunk($diagrama['cajas_marcadas'], $columnas * $filas) as $cajas) {
            $pagina = '';
            foreach ($cajas as $indice => $caja) {
                $x = $margen + $ancho * ($indice % $columnas);
                $arriba = $altoPagina - $margen - $alto * intdiv($indice, $columnas);
                if ($formato === 'a4') {
                    $pagina .= sprintf("0.6 G 0.4 w %.2F %.2F %.2F %.2F re S\n", $x, $arriba - $alto, $ancho, $alto);
                }
                $ubicacion = $this->numeracion->ubicar($caja, $numeracion);
                $pallet = $numeracion['rangos'][$ubicacion['pallet']];
                $identificacion = 'Pallet '.$pallet['numero'].($pallet['folio'] ? ' · '.$pallet['folio'] : '');
                $pagina .= $this->pdf->texto($x + 11, $arriba - 13, 8, 'MUESTRA '.strtoupper($diagrama['tipo']), true);
                foreach ($empresa as $linea => $texto) {
                    $pagina .= $this->pdf->texto($x + 11, $arriba - 26 - $linea * 10, $fuenteEmpresa, $texto, true);
                }
                $pagina .= $this->pdf->texto($x + 11, $arriba - $alto + 30, 22, 'Caja '.$caja, true);
                $pagina .= $this->pdf->texto($x + 11, $arriba - $alto + 16, 8, $identificacion);
                if (filled($diagrama['lote'])) {
                    $lote = $this->pdf->envolver('Lote '.$diagrama['lote'], $ancho - 22, 8)[0];
                    $pagina .= $this->pdf->texto($x + 11, $arriba - $alto + 6, 8, $lote);
                }
            }
            $paginas[] = $pagina;
        }

        return $this->pdf->generar($paginas, [$anchoPagina, $altoPagina], conLogo: false);
    }
}
