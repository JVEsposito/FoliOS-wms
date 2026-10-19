<?php

namespace App\Services\Porteria;

use App\Models\IngresoPorteria;
use App\Services\Documentos\DocumentoPdfPlanta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GeneradorDiarioPorteriaPdf
{
    private const COLUMNAS = ['Hora' => 38, 'Ingreso' => 65, 'Patente' => 58, 'Tipo / motivo' => 47, 'Conductor' => 106, 'Cliente / servicio' => 125, 'Guías' => 90, 'Cantidad' => 51, 'Guardia' => 104, 'Estado' => 110];

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    /** @param Collection<int, IngresoPorteria> $ingresos */
    public function generar(string $fecha, Collection $ingresos): string
    {
        $vigentes = $ingresos->filter(fn (IngresoPorteria $i): bool => $i->anulado_at === null);
        $resumen = $vigentes->count().' ingresos · '.($ingresos->count() - $vigentes->count()).' anulados';
        foreach (['bins', 'bultos'] as $unidad) {
            $resumen .= ' · '.$vigentes->where('unidad', $unidad)->sum('cantidad').' '.$unidad.' declarados';
        }
        $paginas = [];
        $contenido = $this->cabecera($fecha, $resumen);
        $y = 485;
        foreach ($ingresos as $ingreso) {
            $estado = $ingreso->anulado_at ? 'Anulado' : ($ingreso->revisado_at ? 'Revisado por Romana' : 'Pendiente de Romana');
            $motivo = match ($ingreso->motivo) {
                'carga' => 'Con carga', 'vacio' => 'Vacío', default => 'Servicio',
            };
            $celdas = [
                $ingreso->ingreso_at->setTimezone('America/Santiago')->format('H:i'), $ingreso->codigo(),
                $ingreso->patente.($ingreso->patente_carro ? ' / '.$ingreso->patente_carro : ''),
                $ingreso->tipo_camion.' / '.$motivo, $ingreso->nombre_conductor, $ingreso->cliente_nombre,
                implode(', ', $ingreso->guias) ?: 'Sin guía',
                $ingreso->cantidad === null ? 'Sin informar' : $ingreso->cantidad.' '.$ingreso->unidad,
                $ingreso->creado_por_nombre, $estado,
            ];
            $lineas = [];
            foreach (array_values(self::COLUMNAS) as $i => $ancho) {
                $lineas[] = $this->pdf->envolver($celdas[$i], $ancho - 8, 7);
            }
            $notas = array_filter([$ingreso->observacion, $ingreso->motivo_anulacion ? 'Anulación: '.$ingreso->motivo_anulacion : null]);
            $notas = $notas ? $this->pdf->envolver(implode(' · ', $notas), 780, 7) : [];
            $filas = max(array_map('count', $lineas));
            for ($j = 0; $j < $filas; $j++) {
                if ($y - 12 < 46) {
                    $paginas[] = $contenido;
                    $contenido = $this->cabecera($fecha, $resumen);
                    $y = 485;
                    if ($j > 0) {
                        $contenido .= $this->pdf->texto(27, $y - 9, 7, $ingreso->codigo().' · continuación', true);
                        $y -= 16;
                    }
                }
                $x = 24;
                foreach (array_values(self::COLUMNAS) as $i => $ancho) {
                    if (isset($lineas[$i][$j])) {
                        $contenido .= $this->pdf->texto($x + 3, $y - 10, 7, $lineas[$i][$j]);
                    }
                    $x += $ancho;
                }
                $y -= 9;
            }
            foreach ($notas as $nota) {
                if ($y - 12 < 46) {
                    $paginas[] = $contenido;
                    $contenido = $this->cabecera($fecha, $resumen);
                    $y = 485;
                    $contenido .= $this->pdf->texto(27, $y - 9, 7, $ingreso->codigo().' · observaciones (continuación)', true);
                    $y -= 16;
                }
                $contenido .= $this->pdf->texto(27, $y - 10, 7, $nota);
                $y -= 9;
            }
            $y -= 10;
            $contenido .= "0.8 G 24 {$y} m 818 {$y} l S 0 G\n";
        }
        if ($ingresos->isEmpty()) {
            $contenido .= $this->pdf->texto(27, 465, 10, 'Sin ingresos registrados para esta fecha.');
        }
        $paginas[] = $contenido;
        foreach ($paginas as $i => $pagina) {
            $paginas[$i] = $pagina.$this->pdf->texto(24, 25, 7, 'FoliOS · Bitácora de Portería · Fecha y hora de Chile · Hoja '.($i + 1).' de '.count($paginas));
        }

        return $this->pdf->generar($paginas, [842, 595]);
    }

    private function cabecera(string $fecha, string $resumen): string
    {
        $contenido = $this->pdf->texto(24, 566, 15, 'INGRESOS PORTERÍA', true)
            .$this->pdf->texto(24, 548, 10, CarbonImmutable::parse($fecha)->format('d-m-Y').' · '.(config('oficina.planta') ?: 'Planta'))
            .$this->pdf->texto(24, 532, 8, $resumen)
            .$this->pdf->texto(24, 518, 7, 'Cada fila corresponde a una llegada. Una guía puede aparecer en varios camiones. Anulados excluidos de los totales.');
        $x = 24;
        foreach (self::COLUMNAS as $titulo => $ancho) {
            $contenido .= $this->pdf->texto($x + 3, 496, 7, $titulo, true);
            $x += $ancho;
        }

        return $contenido."0 G 24 490 m 818 490 l S\n";
    }
}
