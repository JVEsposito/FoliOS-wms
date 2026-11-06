<?php

namespace App\Services\Envases;

use App\Services\Documentos\DocumentoPdfPlanta;
use Carbon\CarbonImmutable;

/** Estado informativo del cliente: resumen inicial y detalle continuo por documento. */
class GeneradorEstadoCuentaEnvasesPdf
{
    private const LIMITE_INFERIOR = 64;

    private const CUERPO = 8.5;

    private const SECUNDARIO = 8;

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    /** @param array<string, mixed> $estado ServicioCuentaCorrienteEnvases::estadoCuenta() */
    public function generar(array $estado): string
    {
        $pagina = ['paginas' => [], 'contenido' => '', 'y' => 0.0, 'detalle' => false];
        $this->nuevaPagina($estado, $pagina, 'resumen');
        foreach ($estado['tipos'] as $tipo) {
            $detalle = $this->detalleEntregados($tipo['salidas_por_propiedad']);
            $notas = $detalle ? $this->pdf->envolver($detalle, 513, self::SECUNDARIO) : [];
            $nombres = $this->pdf->envolver($tipo['nombre'], 200, self::CUERPO);
            $alto = count($nombres) * 12 + count($notas) * 10 + 2;
            $this->reservar($estado, $pagina, $alto, 'resumen');
            $y = $pagina['y'] - 9;
            foreach ($nombres as $i => $nombre) {
                $pagina['contenido'] .= $this->pdf->texto(36, $y - $i * 12, self::CUERPO, $nombre, true);
            }
            foreach ([300 => $tipo['saldo_inicial'], 370 => $tipo['entradas'], 440 => $tipo['salidas'], 559 => $tipo['saldo_final']] as $x => $valor) {
                $pagina['contenido'] .= $this->derecha($x, $y, self::CUERPO, $this->numero($valor, $x === 300 || $x === 559), $x === 559);
            }
            foreach ($notas as $i => $nota) {
                $pagina['contenido'] .= $this->pdf->texto(42, $y - count($nombres) * 12 - $i * 10, self::SECUNDARIO, $nota);
            }
            $pagina['y'] -= $alto;
        }
        if (! $estado['tipos']) {
            $this->nota($estado, $pagina, 'El cliente no registra movimientos de cuenta en la temporada.');
        }
        $pagina['y'] -= 3;
        foreach ([-1 => 'El cliente debe devolver a la planta: ', 1 => 'La planta debe devolver al cliente: '] as $signo => $leyenda) {
            $pendientes = array_filter($estado['tipos'], fn ($tipo) => $tipo['saldo_final'] * $signo > 0);
            if ($pendientes) {
                $this->nota($estado, $pagina, $leyenda.implode(' · ', array_map(
                    fn ($tipo) => $tipo['nombre'].': '.$this->numero(abs($tipo['saldo_final'])), $pendientes,
                )), true);
            }
        }
        foreach ([
            'Saldo positivo: envases del cliente a cargo de la planta.',
            'Saldo negativo: envases entregados al cliente pendientes de devolución.',
            'Aperturas, ajustes y sus reversas son obligaciones de cuenta; no acreditan movimientos físicos.',
        ] as $nota) {
            $this->nota($estado, $pagina, $nota);
        }
        if ($estado['lineas_pendientes_validacion'] > 0) {
            $this->nota($estado, $pagina, sprintf(
                'Pendientes de validación de la temporada: %s líneas de envases. No incluidas en el saldo.',
                $this->numero($estado['lineas_pendientes_validacion']),
            ), true);
        }

        $pagina['y'] -= 12;
        $this->resumenCsg($estado, $pagina);
        $pagina['y'] -= 12;
        foreach ($this->documentos($estado) as $documento) {
            $this->documento($estado, $pagina, $documento);
        }
        if (! $estado['movimientos']) {
            if ($pagina['y'] - 53 < self::LIMITE_INFERIOR) {
                $this->nuevaPagina($estado, $pagina, 'detalle');
            } else {
                $this->encabezadoDetalle($pagina);
            }
            $this->nota($estado, $pagina, 'Sin movimientos en el período.');
        }
        $pagina['paginas'][] = $pagina['contenido'];
        $total = count($pagina['paginas']);
        foreach ($pagina['paginas'] as $indice => &$contenido) {
            $contenido .= "0.75 G 30 46 m 565 46 l S 0 G\n"
                .$this->pdf->texto(30, 34, 7, 'Documento informativo generado por FoliOS. No es un documento tributario.')
                .$this->derecha(565, 34, 7, sprintf('Hoja %d de %d', $indice + 1, $total));
        }
        unset($contenido);

        return $this->pdf->generar($pagina['paginas']);
    }

    private function resumenCsg(array $estado, array &$pagina): void
    {
        $grupos = $estado['resumen_csg'] ?? ResumenEnvasesPorCsg::generar(array_map(
            fn ($linea, $i) => [
                'movimiento' => 'sin_atribucion', 'tipo_envase' => $linea['tipo_envase'],
                'nombre_envase' => $linea['tipo_envase'], 'orden' => $i,
                'entradas' => $linea['entrada'] ?? 0, 'salidas' => $linea['salida'] ?? 0,
            ], $estado['movimientos'], array_keys($estado['movimientos']),
        ), []);
        $iniciado = false;
        foreach ($grupos as $grupo) {
            $titulo = $grupo['codigo'] === null ? 'Sin CSG asociado'
                : 'CSG '.$grupo['codigo'].(! empty($grupo['nombre']) ? ' · '.$grupo['nombre'] : '');
            $rotulo = $this->pdf->envolver($titulo, 523, self::CUERPO);
            $filas = array_map(fn ($tipo) => [...$tipo, 'textos' => $this->pdf->envolver($tipo['nombre'], 315, self::CUERPO)], $grupo['tipos']);
            $altoRotulo = count($rotulo) * 10 + 2;
            $alto = $altoRotulo + array_sum(array_map(fn ($fila) => count($fila['textos']) * 10.5, $filas));
            $necesario = min($alto, $altoRotulo + 10.5);
            // Mantener juntas las tablas habituales de un CSG cuando caben completas.
            if ($alto <= $pagina['inicio'] - 42 - self::LIMITE_INFERIOR) {
                $necesario = $alto;
            }
            if (! $iniciado) {
                if ($pagina['y'] - $necesario - 42 < self::LIMITE_INFERIOR) {
                    $this->nuevaPagina($estado, $pagina, 'csg');
                } else {
                    $this->encabezadoCsg($pagina);
                }
                $iniciado = true;
            }
            $this->reservar($estado, $pagina, $necesario, 'csg');
            $this->rotuloCsg($estado, $pagina, $rotulo);
            foreach ($filas as $fila) {
                $altoFila = count($fila['textos']) * 10.5;
                if ($pagina['y'] - $altoFila < self::LIMITE_INFERIOR) {
                    $this->nuevaPagina($estado, $pagina, 'csg');
                    $this->rotuloCsg($estado, $pagina, $rotulo);
                }
                foreach ($fila['textos'] as $i => $texto) {
                    $pagina['contenido'] .= $this->pdf->texto(36, $pagina['y'] - 8 - $i * 10.5, self::CUERPO, $texto);
                }
                $pagina['contenido'] .= $this->derecha(440, $pagina['y'] - 8, self::CUERPO, $this->numero($fila['entradas']));
                $pagina['contenido'] .= $this->derecha(559, $pagina['y'] - 8, self::CUERPO, $this->numero($fila['salidas']));
                $pagina['y'] -= $altoFila;
                $pagina['contenido'] .= sprintf("0.88 G 30 %.2F m 565 %.2F l S 0 G\n", $pagina['y'] - 1, $pagina['y'] - 1);
            }
        }
        if (! $iniciado) {
            $this->reservar($estado, $pagina, 53, 'notas');
            $this->encabezadoCsg($pagina);
            $this->nota($estado, $pagina, 'Sin movimientos del período para resumir por CSG.');
        }
        if (array_filter($grupos, fn ($grupo) => $grupo['codigo'] === null)) {
            $pagina['y'] -= 3;
            $this->nota($estado, $pagina, 'Sin CSG asociado: registros sin identificación o sin reparto conciliado. Incluidos en los totales.');
        }
    }

    private function rotuloCsg(array $estado, array &$pagina, array $textos): void
    {
        foreach ($textos as $texto) {
            $this->reservar($estado, $pagina, 12, 'csg');
            $pagina['contenido'] .= $this->fondo($pagina['y'] - 12, 12);
            $pagina['contenido'] .= $this->pdf->texto(36, $pagina['y'] - 9, self::CUERPO, $texto, true);
            $pagina['y'] -= 10;
        }
        $pagina['y'] -= 2;
    }

    private function encabezadoCsg(array &$pagina): void
    {
        $pagina['contenido'] .= $this->pdf->texto(30, $pagina['y'], 10, 'RESUMEN POR CSG', true);
        $pagina['contenido'] .= $this->pdf->texto(30, $pagina['y'] - 12, self::SECUNDARIO, 'Entradas y salidas de cuenta del período · Sin saldo histórico por CSG');
        $pagina['y'] -= 22;
        $pagina['contenido'] .= $this->fondo($pagina['y'] - 15, 17);
        foreach ([['Envase', 36], ['Entradas', 408], ['Salidas', 534]] as [$texto, $x]) {
            $pagina['contenido'] .= $this->pdf->texto($x, $pagina['y'] - 10, self::CUERPO, $texto, true);
        }
        $pagina['y'] -= 20;
    }

    private function documentos(array $estado): array
    {
        if (isset($estado['grupos_documentos'])) {
            return $estado['grupos_documentos'];
        }
        // Compatibilidad con estados anteriores. Solo renglones consecutivos;
        // no se suman cantidades ni se recalculan los saldos.
        $documentos = [];
        $anterior = null;
        foreach ($estado['movimientos'] as $linea) {
            $clave = [$linea['fecha'], $linea['documento'], $linea['movimiento'], $linea['productor'] ?? null];
            if ($clave !== $anterior) {
                $documentos[] = [...$linea, 'lineas' => []];
                $anterior = $clave;
            }
            $documentos[array_key_last($documentos)]['lineas'][] = $linea;
        }

        return $documentos;
    }

    private function documento(array $estado, array &$pagina, array $documento): void
    {
        $productor = isset($documento['productor'])
            ? $this->pdf->envolver($documento['productor'], 523, self::SECUNDARIO) : [];
        $filas = array_map(function (array $linea): array {
            $envase = $this->pdf->envolver($linea['tipo_envase'], 155, self::CUERPO);
            $propiedad = $this->pdf->envolver($linea['propiedad'] === 'Propia' ? 'De la planta' : $linea['propiedad'], 110, self::CUERPO);

            return ['datos' => $linea, 'envase' => $envase, 'propiedad' => $propiedad,
                'alto' => max(count($envase), count($propiedad), 1) * 10.5];
        }, $documento['lineas']);
        $alto = $this->altoRotulo($documento) + count($productor) * 9 + array_sum(array_column($filas, 'alto'));
        $inicioPagina = $pagina['inicio'];
        // Las guías habituales permanecen juntas. Las excepcionalmente largas
        // continúan en la página siguiente con su identidad repetida.
        $necesario = $alto <= $inicioPagina - 42 - self::LIMITE_INFERIOR
            ? $alto : $this->altoRotulo($documento) + 9 + ($filas[0]['alto'] ?? 0);
        if (! $pagina['detalle']) {
            if ($pagina['y'] - $necesario - 42 < self::LIMITE_INFERIOR) {
                $this->nuevaPagina($estado, $pagina, 'detalle');
            } else {
                $this->encabezadoDetalle($pagina);
            }
        }
        $this->reservar($estado, $pagina, $necesario, 'detalle');
        $this->rotulo($pagina, $documento);
        foreach ($productor as $renglon) {
            if ($pagina['y'] - 9 < self::LIMITE_INFERIOR) {
                $this->nuevaPagina($estado, $pagina, 'detalle');
                $this->rotulo($pagina, $documento, true);
            }
            $pagina['contenido'] .= $this->pdf->texto(36, $pagina['y'] - 7, self::SECUNDARIO, $renglon);
            $pagina['y'] -= 9;
        }
        foreach ($filas as $fila) {
            if ($pagina['y'] - $fila['alto'] < self::LIMITE_INFERIOR) {
                $this->nuevaPagina($estado, $pagina, 'detalle');
                $this->rotulo($pagina, $documento, true);
            }
            $this->fila($pagina, $fila);
        }
    }

    private function altoRotulo(array $documento, bool $continuacion = false): float
    {
        [$documentos, $movimientos] = $this->lineasRotulo($documento, $continuacion);

        return max(count($documentos), count($movimientos)) * 10 + 2;
    }

    private function lineasRotulo(array $documento, bool $continuacion): array
    {
        $prefijo = in_array($documento['movimiento'], ['Despacho', 'Recepción con fruta'], true) ? 'Guía N° ' : 'Documento ';

        return [
            $this->pdf->envolver($prefijo.$documento['documento'].($continuacion ? ' (continuación)' : ''), 175, self::CUERPO),
            $this->pdf->envolver($documento['movimiento'], 205, self::SECUNDARIO),
        ];
    }

    private function rotulo(array &$pagina, array $documento, bool $continuacion = false): void
    {
        [$documentos, $movimientos] = $this->lineasRotulo($documento, $continuacion);
        $alto = $this->altoRotulo($documento, $continuacion);
        $pagina['contenido'] .= $this->fondo($pagina['y'] - $alto, $alto);
        foreach ([[$documentos, 36, self::CUERPO, true], [$movimientos, 222, self::SECUNDARIO, false]] as [$textos, $x, $tamano, $negrita]) {
            foreach ($textos as $i => $texto) {
                $pagina['contenido'] .= $this->pdf->texto($x, $pagina['y'] - 10 - $i * 10, $tamano, $texto, $negrita);
            }
        }
        $pagina['contenido'] .= $this->derecha(559, $pagina['y'] - 10, self::SECUNDARIO, $this->fecha($documento['fecha']));
        $pagina['y'] -= $alto;
    }

    private function fila(array &$pagina, array $fila): void
    {
        $y = $pagina['y'] - 8;
        $pagina['contenido'] .= sprintf("0.94 0.98 0.97 rg 484 %.2F 81 %.2F re f 0 g\n", $pagina['y'] - $fila['alto'], $fila['alto']);
        foreach (['envase' => 36, 'propiedad' => 201] as $campo => $x) {
            foreach ($fila[$campo] as $i => $texto) {
                $pagina['contenido'] .= $this->pdf->texto($x, $y - $i * 10.5, self::CUERPO, $texto);
            }
        }
        foreach (['entrada' => 393, 'salida' => 474, 'saldo' => 559] as $campo => $x) {
            $valor = $fila['datos'][$campo];
            $texto = $valor === null ? '-' : $this->numero($valor, $campo === 'saldo');
            $pagina['contenido'] .= $this->derecha($x, $y, self::CUERPO, $texto, $campo === 'saldo');
        }
        $pagina['y'] -= $fila['alto'];
        $pagina['contenido'] .= sprintf("0.88 G 30 %.2F m 565 %.2F l S 0 G\n", $pagina['y'] - 1, $pagina['y'] - 1);
    }

    private function reservar(array $estado, array &$pagina, float $alto, string $seccion): void
    {
        if ($pagina['y'] - $alto < self::LIMITE_INFERIOR) {
            $this->nuevaPagina($estado, $pagina, $seccion);
        }
    }

    private function nuevaPagina(array $estado, array &$pagina, string $seccion): void
    {
        if ($pagina['contenido'] !== '') {
            $pagina['paginas'][] = $pagina['contenido'];
        }
        [$pagina['contenido'], $pagina['y']] = $this->cabecera($estado);
        $pagina['inicio'] = $pagina['y'];
        $pagina['detalle'] = false;
        if ($seccion === 'detalle') {
            $this->encabezadoDetalle($pagina);
        } elseif ($seccion === 'csg') {
            $this->encabezadoCsg($pagina);
        } elseif ($seccion === 'resumen') {
            $pagina['contenido'] .= $this->pdf->texto(30, $pagina['y'], 10, 'RESUMEN EJECUTIVO', true);
            $pagina['y'] -= 14;
            $pagina['contenido'] .= $this->fondo($pagina['y'] - 15, 17);
            foreach ([['Envase', 36], ['Saldo inicial', 249], ['Recibidos', 325], ['Entregados', 390], ['Saldo final', 510]] as [$texto, $x]) {
                $pagina['contenido'] .= $this->pdf->texto($x, $pagina['y'] - 10, self::CUERPO, $texto, true);
            }
            $pagina['y'] -= 19;
        }
    }

    private function encabezadoDetalle(array &$pagina): void
    {
        $pagina['detalle'] = true;
        $pagina['contenido'] .= $this->pdf->texto(30, $pagina['y'], 10, 'DETALLE POR DOCUMENTO', true);
        $pagina['contenido'] .= $this->pdf->texto(30, $pagina['y'] - 12, self::SECUNDARIO, 'Orden cronológico · Saldos independientes por envase');
        $pagina['y'] -= 22;
        $pagina['contenido'] .= $this->fondo($pagina['y'] - 15, 17);
        foreach ([['Envase', 36], ['Propiedad', 201], ['Recibidos', 347], ['Entregados', 426], ['Saldo del envase', 493]] as [$texto, $x]) {
            $pagina['contenido'] .= $this->pdf->texto($x, $pagina['y'] - 10, self::CUERPO, $texto, true);
        }
        $pagina['y'] -= 20;
    }

    private function nota(array $estado, array &$pagina, string $texto, bool $destacada = false): void
    {
        foreach ($this->pdf->envolver($texto, 535, self::SECUNDARIO) as $linea) {
            $this->reservar($estado, $pagina, 11, 'notas');
            $pagina['contenido'] .= $this->pdf->texto(30, $pagina['y'] - 8, self::SECUNDARIO, $linea, $destacada);
            $pagina['y'] -= 11;
        }
    }

    private function cabecera(array $estado): array
    {
        $periodo = match (true) {
            $estado['desde'] && $estado['hasta'] => 'Del '.$this->dia($estado['desde']).' al '.$this->dia($estado['hasta']),
            (bool) $estado['desde'] => 'Desde el '.$this->dia($estado['desde']),
            (bool) $estado['hasta'] => 'Hasta el '.$this->dia($estado['hasta']),
            default => 'Toda la temporada',
        };
        $contenido = "q 60 0 0 30 30 790 cm /Logo Do Q\n"
            .$this->pdf->texto(105, 806, 13, 'ESTADO DE CUENTA DE ENVASES', true);
        $y = 791;
        foreach ($this->pdf->envolver($estado['cliente']['codigo'].' · '.$estado['cliente']['nombre'], 460, 10) as $cliente) {
            $contenido .= $this->pdf->texto(105, $y, 10, $cliente, true);
            $y -= 12;
        }
        $y -= 7;
        foreach ($this->pdf->envolver(sprintf('Temporada %s · %s · Emitido el %s', $estado['temporada']['codigo'], $periodo, $this->fecha($estado['emitido_at'])), 535, self::SECUNDARIO) as $linea) {
            $contenido .= $this->pdf->texto(30, $y, self::SECUNDARIO, $linea);
            $y -= 11;
        }
        $contenido .= sprintf("0.75 G 30 %.2F m 565 %.2F l S 0 G\n", $y + 3, $y + 3);

        return [$contenido, $y - 19];
    }

    private function detalleEntregados(array $porPropiedad): ?string
    {
        if ($porPropiedad['propia'] === 0 && $porPropiedad['arrendada'] === 0) {
            return null;
        }
        $partes = array_filter([
            $porPropiedad['propia'] ? $this->numero($porPropiedad['propia']).' propios de la planta' : null,
            $porPropiedad['arrendada'] ? $this->numero($porPropiedad['arrendada']).' arrendados' : null,
            $porPropiedad['cliente'] ? $this->numero($porPropiedad['cliente']).' del cliente' : null,
        ]);

        return 'Entregados: '.implode(', ', $partes).'.';
    }

    private function fondo(float $y, float $alto): string
    {
        return sprintf("0.92 0.95 0.96 rg 30 %.2F 535 %.2F re f 0 g\n", $y, $alto);
    }

    private function derecha(float $x, float $y, float $tamano, string $texto, bool $negrita = false): string
    {
        $ancho = 0.0;
        foreach (mb_str_split($texto) as $letra) {
            $ancho += $tamano * match (true) {
                ctype_digit($letra) => 0.556,
                $letra === '.' || $letra === ':' || $letra === ' ' => 0.278,
                $letra === '-' => 0.333,
                $letra === '+' => 0.584,
                default => 0.556,
            };
        }

        return $this->pdf->texto($x - $ancho, $y, $tamano, $texto, $negrita);
    }

    private function numero(int $valor, bool $signado = false): string
    {
        return ($signado && $valor > 0 ? '+' : '').number_format($valor, 0, ',', '.');
    }

    private function fecha(?string $fecha): string
    {
        return $fecha ? CarbonImmutable::parse($fecha)->format('d-m-Y H:i') : '-';
    }

    private function dia(string $fecha): string
    {
        return CarbonImmutable::parse($fecha)->format('d-m-Y');
    }
}
