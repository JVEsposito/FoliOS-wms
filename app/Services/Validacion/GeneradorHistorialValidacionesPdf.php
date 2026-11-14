<?php

namespace App\Services\Validacion;

use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Models\Temporada;
use App\Models\ValidacionPallet;
use App\Services\Documentos\DocumentoPdfPlanta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GeneradorHistorialValidacionesPdf
{
    private const MARGEN = 24;

    private const COLUMNAS = [
        ['Folio', 110],
        ['Cliente / marca', 157],
        ['Artículo', 156],
        ['CSG', 85],
        ['Cajas', 42],
        ['Resultado', 107],
        ['Validador / jornada', 137],
    ];

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    /** @param Collection<int, ValidacionPallet> $validaciones */
    public function generar(Collection $validaciones, Temporada $temporada, string $fecha, string $cliente): string
    {
        [$encabezado, $inicio] = $this->encabezado($temporada, $fecha, $cliente, $validaciones->count());
        $paginas = [];
        $contenido = $encabezado;
        $y = $inicio;
        foreach ($validaciones as $validacion) {
            $celdas = [];
            foreach ($this->valores($validacion) as $indice => $valor) {
                $celdas[] = $this->pdf->envolver($valor, self::COLUMNAS[$indice][1] - 10, 8);
            }
            $alto = 8 + 10 * max(array_map('count', $celdas));
            if ($y - $alto < 39) {
                $paginas[] = $contenido;
                $contenido = $encabezado;
                $y = $inicio;
            }

            $x = self::MARGEN + 4;
            foreach ($celdas as $indice => $lineas) {
                foreach ($lineas as $numero => $linea) {
                    $contenido .= $this->pdf->texto($x, $y - $numero * 10, 8, $linea, $indice === 0);
                }
                $x += self::COLUMNAS[$indice][1];
            }
            $y -= $alto;
            $separador = $y + 7;
            $contenido .= "0.87 G 24 {$separador} m 818 {$separador} l S 0 G\n";
        }
        $paginas[] = $contenido;

        $total = count($paginas);
        foreach ($paginas as $indice => $pagina) {
            $paginas[$indice] = $pagina.$this->pdf->texto(750, 23, 8, sprintf('Hoja %d de %d', $indice + 1, $total));
        }

        return $this->pdf->generar($paginas, [842, 595]);
    }

    /** @return array{string, int} */
    private function encabezado(Temporada $temporada, string $fecha, string $cliente, int $cantidad): array
    {
        $contenido = "q 64 0 0 32 24 549 cm /Logo Do Q\n";
        $contenido .= $this->pdf->texto(105, 567, 15, 'VALIDACIONES DE PALLETS · PT', true);
        $contenido .= $this->pdf->texto(105, 550, 9, sprintf(
            'Temporada %s · Fecha de jornada %s · %d registros',
            $temporada->codigo,
            CarbonImmutable::parse($fecha)->format('d-m-Y'),
            $cantidad,
        ));
        $y = 531;
        foreach ($this->pdf->envolver('Cliente: '.$cliente, 780, 10) as $linea) {
            $contenido .= $this->pdf->texto(self::MARGEN + 4, $y, 10, $linea, true);
            $y -= 13;
        }

        $fondo = $y - 15;
        $contenido .= "0.91 0.94 0.95 rg 24 {$fondo} 794 17 re f 0 g\n";
        $x = self::MARGEN + 4;
        foreach (self::COLUMNAS as [$titulo, $ancho]) {
            $contenido .= $this->pdf->texto($x, $y - 9, 8, $titulo, true);
            $x += $ancho;
        }

        return [$contenido, $y - 26];
    }

    /** @return list<string> */
    private function valores(ValidacionPallet $validacion): array
    {
        $origen = $validacion->snapshot['origen'] ?? [];
        $articulo = $validacion->snapshot['articulo'] ?? [];
        $resultado = match ($validacion->resultado) {
            ResultadoValidacionPallet::Aprobado => 'Aprobado',
            ResultadoValidacionPallet::Observado => 'Observado',
            ResultadoValidacionPallet::Rechazado => 'Rechazo definitivo',
        };
        $resultado = match ($validacion->estado) {
            EstadoValidacionPallet::Aceptada => $resultado,
            EstadoValidacionPallet::Conflicto => 'Conflicto · '.$resultado,
            EstadoValidacionPallet::Anulada => 'Anulada · '.$resultado,
        };
        $hora = $validacion->generado_dispositivo_at?->setTimezone(config('app.operational_timezone'))->format('H:i');

        $turno = $validacion->turnoOperativo();
        if ($turno) {
            $hora = $validacion->generado_dispositivo_at?->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i:s');
        }

        return [
            $validacion->numero_folio,
            implode(' · ', array_filter([$origen['cliente'] ?? 'Sin origen', $origen['marca'] ?? null])),
            implode(' · ', array_filter([
                trim(implode(' ', array_filter([$articulo['especie'] ?? null, $articulo['variedad'] ?? null, $articulo['calibre'] ?? null]))),
                $articulo['envase'] ?? null,
                $validacion->snapshot['categoria']['nombre'] ?? null,
            ])) ?: 'Sin artículo',
            (string) ($origen['csg'] ?? '—'),
            (string) $validacion->cantidad_cajas,
            $resultado,
            ($validacion->usuario?->name ?? 'Sin usuario')."\n".implode(' · ', array_filter([
                $hora,
                $validacion->linea_proceso ? 'Línea '.$validacion->linea_proceso : null,
                ($turno ?? $validacion->turno) ? 'Turno '.($turno ?? $validacion->turno) : null,
                $turno && $validacion->turno && $turno !== $validacion->turno ? 'Declarado '.$validacion->turno : null,
            ])),
        ];
    }
}
