<?php

namespace App\Services\Envases;

use App\Services\Documentos\DocumentoPdfPlanta;
use Carbon\CarbonImmutable;

/**
 * Estado de cuenta de envases de un cliente, para enviárselo: resumen por tipo de envase
 * y detalle de movimientos con saldo. Documento informativo, no tributario.
 */
class GeneradorEstadoCuentaEnvasesPdf
{
    private const MARGEN = 30;

    private const LIMITE_INFERIOR = 64;

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    /** @param array<string, mixed> $estado ServicioCuentaCorrienteEnvases::estadoCuenta() */
    public function generar(array $estado): string
    {
        $paginas = [];
        $contenido = $this->cabecera($estado);
        $y = 742;

        $contenido .= $this->pdf->texto(self::MARGEN, $y, 10, 'RESUMEN POR TIPO DE ENVASE', true);
        $y -= 18;
        $contenido .= $this->fondo($y - 4, 16);
        $contenido .= $this->pdf->texto(self::MARGEN + 6, $y, 8, 'Envase', true);
        foreach ([['Saldo inicial', 300], ['Recibidos', 370], ['Entregados', 440], ['Saldo final', 560]] as [$titulo, $x]) {
            $contenido .= $this->derecha($x, $y, 8, $titulo, true);
        }
        $y -= 18;
        foreach ($estado['tipos'] as $tipo) {
            $contenido .= $this->pdf->texto(self::MARGEN + 6, $y, 9, $tipo['nombre'], true);
            $contenido .= $this->derecha(300, $y, 9, $this->numero($tipo['saldo_inicial']));
            $contenido .= $this->derecha(370, $y, 9, (string) $tipo['entradas']);
            $contenido .= $this->derecha(440, $y, 9, (string) $tipo['salidas']);
            $contenido .= $this->derecha(560, $y, 9, $this->numero($tipo['saldo_final']), true);
            $detalle = $this->detalleEntregados($tipo['salidas_por_propiedad']);
            if ($detalle) {
                $y -= 11;
                $contenido .= $this->pdf->texto(self::MARGEN + 14, $y, 7, $detalle);
            }
            $y -= 16;
        }
        if (! $estado['tipos']) {
            $contenido .= $this->pdf->texto(self::MARGEN + 6, $y, 9, 'El cliente no registra movimientos de cuenta en la temporada.');
            $y -= 16;
        }

        $y -= 4;
        foreach ([
            'Saldo positivo: envases del cliente a cargo de la planta.',
            'Saldo negativo: envases entregados al cliente pendientes de devolución.',
        ] as $leyenda) {
            $contenido .= $this->pdf->texto(self::MARGEN, $y, 8, $leyenda);
            $y -= 11;
        }
        if ($estado['lineas_pendientes_validacion'] > 0) {
            $contenido .= $this->pdf->texto(self::MARGEN, $y, 8, sprintf(
                'Hay %d %s de envases en recepciones pendientes de validación; se incluirán al validarse.',
                $estado['lineas_pendientes_validacion'],
                $estado['lineas_pendientes_validacion'] === 1 ? 'línea' : 'líneas',
            ));
            $y -= 11;
        }

        $y -= 14;
        $contenido .= $this->pdf->texto(self::MARGEN, $y, 10, 'DETALLE DE MOVIMIENTOS', true);
        $y -= 18;
        $contenido .= $this->encabezadoDetalle($y);
        $y -= 16;

        foreach ($estado['movimientos'] as $linea) {
            if ($y < self::LIMITE_INFERIOR) {
                $paginas[] = $contenido;
                $contenido = $this->cabecera($estado);
                $y = 742;
                $contenido .= $this->encabezadoDetalle($y);
                $y -= 16;
            }
            $contenido .= $this->pdf->texto(self::MARGEN + 2, $y, 7.5, $this->fecha($linea['fecha']));
            $contenido .= $this->pdf->texto(106, $y, 7.5, $this->cortar((string) $linea['documento'], 17));
            $contenido .= $this->pdf->texto(190, $y, 7.5, $this->cortar($linea['movimiento'], 27));
            $contenido .= $this->pdf->texto(318, $y, 7.5, $this->cortar($linea['tipo_envase'], 15));
            $contenido .= $this->pdf->texto(392, $y, 7.5, $linea['propiedad']);
            $contenido .= $this->derecha(480, $y, 7.5, $linea['entrada'] !== null ? (string) $linea['entrada'] : '');
            $contenido .= $this->derecha(522, $y, 7.5, $linea['salida'] !== null ? (string) $linea['salida'] : '');
            $contenido .= $this->derecha(560, $y, 7.5, $this->numero($linea['saldo']), true);
            $y -= 13;
        }
        if (! $estado['movimientos']) {
            $contenido .= $this->pdf->texto(self::MARGEN + 2, $y, 8, 'Sin movimientos en el período.');
        }
        $paginas[] = $contenido;

        $total = count($paginas);
        foreach ($paginas as $indice => $pagina) {
            $paginas[$indice] = $pagina
                ."0.75 G 30 46 m 565 46 l S 0 G\n"
                .$this->pdf->texto(self::MARGEN, 34, 7, 'Documento informativo generado por FoliOS. No es un documento tributario.')
                .$this->derecha(565, 34, 7, sprintf('Hoja %d de %d', $indice + 1, $total));
        }

        return $this->pdf->generar($paginas);
    }

    /** @param array<string, mixed> $estado */
    private function cabecera(array $estado): string
    {
        $periodo = match (true) {
            $estado['desde'] && $estado['hasta'] => 'Del '.$this->dia($estado['desde']).' al '.$this->dia($estado['hasta']),
            (bool) $estado['desde'] => 'Desde el '.$this->dia($estado['desde']),
            (bool) $estado['hasta'] => 'Hasta el '.$this->dia($estado['hasta']),
            default => 'Toda la temporada',
        };

        return "q 60 0 0 30 30 790 cm /Logo Do Q\n"
            .$this->pdf->texto(105, 806, 13, 'ESTADO DE CUENTA DE ENVASES', true)
            .$this->pdf->texto(105, 791, 10, $estado['cliente']['codigo'].' · '.$estado['cliente']['nombre'], true)
            .$this->pdf->texto(self::MARGEN, 772, 8, sprintf(
                'Temporada %s · %s · Emitido el %s',
                $estado['temporada']['codigo'],
                $periodo,
                $this->fecha($estado['emitido_at']),
            ))
            ."0.75 G 30 764 m 565 764 l S 0 G\n";
    }

    private function encabezadoDetalle(float $y): string
    {
        $contenido = $this->fondo($y - 4, 16);
        foreach ([['Fecha', self::MARGEN + 2], ['Guía N°', 106], ['Movimiento', 190], ['Envase', 318], ['Propiedad', 392]] as [$titulo, $x]) {
            $contenido .= $this->pdf->texto($x, $y, 7.5, $titulo, true);
        }
        foreach ([['Entrada', 480], ['Salida', 522], ['Saldo', 560]] as [$titulo, $x]) {
            $contenido .= $this->derecha($x, $y, 7.5, $titulo, true);
        }

        return $contenido;
    }

    /** @param array<string, int> $porPropiedad */
    private function detalleEntregados(array $porPropiedad): ?string
    {
        if ($porPropiedad['propia'] === 0 && $porPropiedad['arrendada'] === 0) {
            return null;
        }
        $partes = array_filter([
            $porPropiedad['propia'] ? $porPropiedad['propia'].' propios de la planta' : null,
            $porPropiedad['arrendada'] ? $porPropiedad['arrendada'].' arrendados' : null,
            $porPropiedad['cliente'] ? $porPropiedad['cliente'].' del cliente' : null,
        ]);

        return 'Entregados: '.implode(', ', $partes).'.';
    }

    private function fondo(float $y, float $alto): string
    {
        return sprintf("0.92 0.95 0.96 rg 30 %.2F 535 %.2F re f 0 g\n", $y, $alto);
    }

    private function derecha(float $x, float $y, float $tamano, string $texto, bool $negrita = false): string
    {
        // Helvetica: dígitos y signos miden ~0,556 em; las letras se aproximan igual.
        $ancho = mb_strlen($texto) * $tamano * ($negrita ? 0.6 : 0.556);

        return $texto === '' ? '' : $this->pdf->texto($x - $ancho, $y, $tamano, $texto, $negrita);
    }

    private function numero(int $valor): string
    {
        return $valor > 0 ? '+'.$valor : (string) $valor;
    }

    private function fecha(?string $fecha): string
    {
        return $fecha ? CarbonImmutable::parse($fecha)->format('d-m-Y H:i') : '—';
    }

    private function dia(string $fecha): string
    {
        return CarbonImmutable::parse($fecha)->format('d-m-Y');
    }

    private function cortar(string $texto, int $maximo): string
    {
        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo - 1).'…' : $texto;
    }
}
