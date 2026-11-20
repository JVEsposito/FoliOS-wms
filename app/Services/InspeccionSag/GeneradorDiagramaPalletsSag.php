<?php

namespace App\Services\InspeccionSag;

use App\Services\Documentos\DocumentoPdfPlanta;
use App\Services\Documentos\MetricasHelvetica;

/**
 * PDF del diagrama de presentación de pallets en A4 o A3 horizontal. Es neutral: no lleva
 * nombre ni logo de la planta, solo el CSP que se indique en el diagrama.
 */
class GeneradorDiagramaPalletsSag
{
    public const PAPELES = ['a4' => [842, 595], 'a3' => [1191, 842]];

    private const MARGEN = 28;

    private const PRIMARIO = '0.063 0.184 0.263';

    private const GRIS = '0.36 0.41 0.45';

    private const FONDO = '0.945 0.957 0.965';

    private const BORDE = '0.78 0.82 0.85';

    private const CAJA = '0.42 0.48 0.53';

    private const MARCA = '1 0.824 0.122';

    private const MARCA_BORDE = '0.478 0.353 0';

    private const SEPARACION_X = 16;

    private const SEPARACION_Y = 14;

    /** Tres líneas de rótulo sobre cada pallet y el piso bajo la grilla. */
    private const ROTULO = 31;

    private const PISO = 5;

    private const ANCHO_MINIMO_PALLET = 96;

    private const CELDA_MAXIMA = 30;

    public function __construct(
        private readonly DocumentoPdfPlanta $pdf,
        private readonly MetricasHelvetica $metricas,
        private readonly NumeracionDiagramaPallets $numeracion,
    ) {}

    /** @param  array<string, mixed>  $contenido  Contenido normalizado por ServicioDiagramasPalletsSag::contenido. */
    public function pdf(array $contenido, string $papel, bool $mostrarNumeros, ?string $temporada, ?string $emisor): string
    {
        [$ancho, $alto] = self::PAPELES[$papel];
        $numeracion = $this->numeracion->numerar($contenido['pallets']);
        $marcadas = array_flip($contenido['cajas_marcadas']);
        $hoja = ['ancho' => $ancho, 'alto' => $alto, 'contenido' => $contenido, 'temporada' => $temporada, 'emisor' => $emisor,
            'emitido' => now()->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i'), 'total' => $numeracion['total']];
        $cuerpo = $this->cuerpo($hoja);
        $celda = $this->celda($numeracion, $ancho - 2 * self::MARGEN, $cuerpo['alto']);
        $paginas = [];
        foreach ($this->paginar($numeracion, $celda, $ancho - 2 * self::MARGEN, $cuerpo['alto']) as $filas) {
            $c = '';
            $y = $cuerpo['arriba'];
            foreach ($filas as $fila) {
                $piso = $y - $fila['alto'] + self::PISO;
                foreach ($fila['pallets'] as $ubicado) {
                    $c .= $this->pallet($numeracion['rangos'][$ubicado['indice']], self::MARGEN + $ubicado['x'], $piso, $celda, $marcadas, $mostrarNumeros);
                }
                $y -= $fila['alto'] + self::SEPARACION_Y;
            }
            $paginas[] = $c;
        }
        foreach ($this->resumen($numeracion, $contenido['cajas_marcadas'], $hoja, $cuerpo) as $pagina) {
            $paginas[] = $pagina;
        }
        $total = count($paginas);
        foreach ($paginas as $indice => &$pagina) {
            $pagina = $this->encabezado($hoja, $numeracion).$pagina.$this->pie($hoja, $indice + 1, $total);
        }

        return $this->pdf->generar($paginas, [$ancho, $alto], conLogo: false);
    }

    public static function titulo(array $contenido): string
    {
        return 'DIAGRAMA '.mb_strtoupper($contenido['tipo']).(filled($contenido['numero'] ?? null) ? ' N.º '.$contenido['numero'] : '');
    }

    /** @return array{arriba: float, abajo: float, alto: float} */
    private function cuerpo(array $hoja): array
    {
        $arriba = $hoja['alto'] - self::MARGEN - 84;
        $abajo = self::MARGEN + 24;

        return ['arriba' => $arriba, 'abajo' => $abajo, 'alto' => $arriba - $abajo];
    }

    /**
     * La celda más grande que no agrega hojas respecto de la celda legible mínima.
     * Con números de 4 cifras la celda mínima deja el texto en 6 pt.
     */
    private function celda(array $numeracion, float $ancho, float $alto): float
    {
        $tope = self::CELDA_MAXIMA;
        foreach ($numeracion['rangos'] as $rango) {
            $tope = min($tope, $ancho / $rango['base'], ($alto - self::ROTULO - self::PISO) / $rango['corridas']);
        }
        $legible = max(11.0, strlen((string) $numeracion['total']) * 0.556 * 6 + 3);
        $minima = min($legible, $tope);
        $hojas = count($this->paginar($numeracion, $minima, $ancho, $alto));
        for ($celda = $tope; $celda > $minima; $celda -= 0.25) {
            if (count($this->paginar($numeracion, $celda, $ancho, $alto)) <= $hojas) {
                return $celda;
            }
        }

        return $minima;
    }

    /**
     * Filas de pallets por hoja. En una fila los pallets se apoyan en el mismo piso para que
     * se vean sus diferencias de base y altura.
     *
     * @return list<list<array{alto: float, pallets: list<array{indice: int, x: float}>}>>
     */
    private function paginar(array $numeracion, float $celda, float $ancho, float $alto): array
    {
        $filas = [];
        $fila = null;
        foreach ($numeracion['rangos'] as $indice => $rango) {
            $anchoPallet = max($rango['base'] * $celda, self::ANCHO_MINIMO_PALLET);
            $altoPallet = $rango['corridas'] * $celda + self::ROTULO + self::PISO;
            if ($fila === null || $ancho < $fila['ancho'] + self::SEPARACION_X + $anchoPallet) {
                if ($fila !== null) {
                    $filas[] = $fila;
                }
                $fila = ['ancho' => -self::SEPARACION_X, 'alto' => 0.0, 'pallets' => []];
            }
            $fila['pallets'][] = ['indice' => $indice, 'x' => $fila['ancho'] + self::SEPARACION_X];
            $fila['ancho'] += self::SEPARACION_X + $anchoPallet;
            $fila['alto'] = max($fila['alto'], $altoPallet);
        }
        if ($fila !== null) {
            $filas[] = $fila;
        }
        $paginas = [];
        $actual = [];
        $usado = 0.0;
        foreach ($filas as $fila) {
            $necesario = $fila['alto'] + ($actual === [] ? 0 : self::SEPARACION_Y);
            if ($actual !== [] && $usado + $necesario > $alto) {
                $paginas[] = $actual;
                $actual = [];
                $usado = 0.0;
                $necesario = $fila['alto'];
            }
            $actual[] = $fila;
            $usado += $necesario;
        }
        if ($actual !== []) {
            $paginas[] = $actual;
        }

        return $paginas;
    }

    private function pallet(array $rango, float $x, float $piso, float $celda, array $marcadas, bool $mostrarNumeros): string
    {
        $corridas = $this->numeracion->corridas($rango);
        $arriba = $piso + count($corridas) * $celda;
        $cantidad = count(array_filter(range($rango['inicio'], $rango['fin']), fn (int $n): bool => isset($marcadas[$n])));
        $anchoRotulo = max($rango['base'] * $celda, self::ANCHO_MINIMO_PALLET);
        $c = $this->texto($x, $arriba + 23, 7.5, $this->metricas->recortar('PALLET '.$rango['numero'].($rango['folio'] ? ' · '.$rango['folio'] : ''), $anchoRotulo, 7.5, true), true, self::PRIMARIO);
        $c .= $this->texto($x, $arriba + 14, 6.3, 'Base '.$rango['base'].' × '.$rango['corridas'].' corridas · '.$rango['total'].' cajas', false, self::GRIS);
        $c .= $this->texto($x, $arriba + 5, 6.3, 'Cajas '.$rango['inicio'].'–'.$rango['fin'].($cantidad ? ' · '.$cantidad.' marcadas' : ''), $cantidad > 0, self::GRIS);
        $digitosMaximos = strlen((string) $rango['fin']);
        $fuente = min(8.5, $celda * 0.5, ($celda - 2.4) / ($digitosMaximos * 0.556));
        $c .= sprintf("q %s RG 0.45 w\n", self::CAJA);
        foreach ($corridas as $fila => $cajas) {
            foreach ($cajas as $columna => $numero) {
                $cx = $x + $columna * $celda;
                $cy = $arriba - ($fila + 1) * $celda;
                if (isset($marcadas[$numero])) {
                    $c .= sprintf("q %s rg %s RG 1.3 w %.2F %.2F %.2F %.2F re B Q\n", self::MARCA, self::MARCA_BORDE, $cx + 0.4, $cy + 0.4, $celda - 0.8, $celda - 0.8);
                } else {
                    // El número anterior dejó su color de relleno: cada caja vuelve a blanco.
                    $c .= sprintf("1 g %.2F %.2F %.2F %.2F re B\n", $cx, $cy, $celda, $celda);
                }
                if ($mostrarNumeros) {
                    $texto = (string) $numero;
                    $negrita = isset($marcadas[$numero]);
                    $tx = $cx + ($celda - $this->metricas->ancho($texto, $fuente, $negrita)) / 2;
                    $c .= $this->texto($tx, $cy + ($celda - $fuente * 0.72) / 2, $fuente, $texto, $negrita, '0.075 0.125 0.165');
                }
            }
        }
        $c .= "Q\n";

        return $c.sprintf("q %s RG 2.2 w %.2F %.2F m %.2F %.2F l S Q\n", self::PRIMARIO, $x - 3, $piso - 2.5, $x + $rango['base'] * $celda + 3, $piso - 2.5);
    }

    /**
     * Resumen por pallet con las cajas marcadas y su ubicación: c = corrida desde abajo,
     * p = posición desde la izquierda.
     *
     * @return list<string>
     */
    private function resumen(array $numeracion, array $marcas, array $hoja, array $cuerpo): array
    {
        $columnas = [['Pallet', 0, 48], ['Folio', 48, 92], ['Base × corridas', 140, 84], ['Última corrida', 224, 66],
            ['Cajas', 290, 44], ['Rango', 334, 72], ['Marcadas', 406, 0]];
        $ancho = $hoja['ancho'] - 2 * self::MARGEN;
        $anchoMarcas = $ancho - 406 - 8;
        $porPallet = array_fill(0, count($numeracion['rangos']), []);
        foreach ($marcas as $numero) {
            $ubicacion = $this->numeracion->ubicar($numero, $numeracion);
            $porPallet[$ubicacion['pallet']][] = $numero.' (c'.$ubicacion['corrida'].' p'.$ubicacion['posicion'].')';
        }
        $paginas = [];
        $c = '';
        $y = $cuerpo['arriba'];
        $cabecera = function () use (&$c, &$y, $columnas, $ancho): void {
            $c .= $this->texto(self::MARGEN, $y - 10, 9.5, 'RESUMEN DE PALLETS Y CAJAS MARCADAS', true, self::PRIMARIO)
                .sprintf("q %s RG 0.6 w %.2F %.2F m %.2F %.2F l S Q\n", self::PRIMARIO, self::MARGEN, $y - 15, self::MARGEN + $ancho, $y - 15);
            $y -= 22;
            $c .= sprintf("%s rg %.2F %.2F %.2F %.2F re f 0 g\n", self::FONDO, self::MARGEN, $y - 15, $ancho, 15);
            foreach ($columnas as [$titulo, $x]) {
                $c .= $this->texto(self::MARGEN + $x + 4, $y - 10.5, 6.5, mb_strtoupper($titulo), true, self::GRIS);
            }
            $y -= 15;
        };
        $cabecera();
        foreach ($numeracion['rangos'] as $indice => $rango) {
            $lineas = $this->envolver($porPallet[$indice] === [] ? '—' : implode(', ', $porPallet[$indice]), $anchoMarcas, 7.5);
            $altoFila = max(15, 5 + count($lineas) * 9.5);
            if ($y - $altoFila < $cuerpo['abajo']) {
                $paginas[] = $c;
                $c = '';
                $y = $cuerpo['arriba'];
                $cabecera();
            }
            $valores = ['Pallet '.$rango['numero'], $rango['folio'] ?? '—', $rango['base'].' × '.$rango['corridas'],
                $rango['ultima'] ? $rango['ultima'].' cajas' : 'Completa', (string) $rango['total'], $rango['inicio'].'–'.$rango['fin']];
            foreach ($valores as $i => $valor) {
                $c .= $this->texto(self::MARGEN + $columnas[$i][1] + 4, $y - 10.5, 7.5, $this->metricas->recortar($valor, $columnas[$i][2] - 8, 7.5), $i === 0);
            }
            foreach ($lineas as $n => $linea) {
                $c .= $this->texto(self::MARGEN + 410, $y - 10.5 - $n * 9.5, 7.5, $linea, $porPallet[$indice] !== []);
            }
            $y -= $altoFila;
            $c .= sprintf("q %s RG 0.4 w %.2F %.2F m %.2F %.2F l S Q\n", self::BORDE, self::MARGEN, $y, self::MARGEN + $ancho, $y);
        }
        $c .= $this->texto(self::MARGEN + 4, $y - 12, 7.5, 'Total: '.count($numeracion['rangos']).' pallets · '.$numeracion['total']
            .' cajas numeradas del 1 al '.$numeracion['total'].' · '.count($marcas).' cajas marcadas.', true);
        $c .= $this->texto(self::MARGEN + 4, $y - 23, 6.5, 'c = corrida contada desde abajo · p = posición contada desde la izquierda.', false, self::GRIS);
        $paginas[] = $c;

        return $paginas;
    }

    private function encabezado(array $hoja, array $numeracion): string
    {
        $d = $hoja['contenido'];
        $arriba = $hoja['alto'] - self::MARGEN;
        $ancho = $hoja['ancho'] - 2 * self::MARGEN;
        $derecha = self::MARGEN + $ancho;
        $emitido = 'Emitido el '.$hoja['emitido'];
        $subtitulo = implode(' · ', array_filter(['Presentación de pallets para inspección',
            $d['csp'] ? 'CSP '.$d['csp'] : null, $this->temporada($hoja['temporada'])]));
        $c = $this->texto(self::MARGEN, $arriba - 14, 15, self::titulo($d), true, self::PRIMARIO);
        $c .= $this->texto(self::MARGEN, $arriba - 28, 8, $subtitulo, false, self::GRIS);
        $c .= $this->texto($derecha - $this->metricas->ancho($emitido, 7.5), $arriba - 14, 7.5, $emitido, false, self::GRIS);
        $c .= sprintf("q %s RG 1.1 w %.2F %.2F m %.2F %.2F l S Q\n", self::PRIMARIO, self::MARGEN, $arriba - 36, $derecha, $arriba - 36);
        $fichas = [['Folio', $d['folio']], ['Fecha', $d['fecha'] ? implode('-', array_reverse(explode('-', $d['fecha']))) : null],
            ['Lote', $d['lote']], ['Especie', $d['especie']], ['Exportadora', $d['exportadora']],
            ['Pallets / cajas', count($numeracion['rangos']).' / '.$numeracion['total']], ['Cajas marcadas', (string) count($d['cajas_marcadas'])]];
        $anchoFicha = $ancho / count($fichas);
        foreach ($fichas as $i => [$etiqueta, $valor]) {
            $x = self::MARGEN + $i * $anchoFicha;
            $c .= sprintf("q %s rg %s RG 0.5 w %.2F %.2F %.2F %.2F re B Q\n", self::FONDO, self::BORDE, $x, $arriba - 72, $anchoFicha - 5, 30);
            $c .= $this->texto($x + 6, $arriba - 52, 5.8, mb_strtoupper($etiqueta), true, self::GRIS);
            $c .= $this->texto($x + 6, $arriba - 65, 8.5, $this->metricas->recortar($valor ?: '—', $anchoFicha - 17, 8.5));
        }

        return $c;
    }

    /** «Temporada 2026-2027» se deja tal cual; un código como «TEMP-2627» se antecede con la palabra. */
    private function temporada(?string $nombre): ?string
    {
        if (blank($nombre)) {
            return null;
        }

        return str_starts_with(mb_strtolower($nombre), 'temporada') ? $nombre : 'Temporada '.$nombre;
    }

    private function pie(array $hoja, int $pagina, int $total): string
    {
        $derecha = $hoja['ancho'] - self::MARGEN;
        $numero = 'Página '.$pagina.' de '.$total;

        return sprintf("q %s RG 0.5 w %.2F %.2F m %.2F %.2F l S Q\n", self::BORDE, self::MARGEN, self::MARGEN + 12, $derecha, self::MARGEN + 12)
            .$this->texto(self::MARGEN, self::MARGEN + 2, 6.5, 'Diagrama de presentación interno generado por FoliOS. No constituye aprobación del SAG ni del USDA.'
                .($hoja['emisor'] ? ' Emitido por '.$hoja['emisor'].'.' : ''), false, self::GRIS)
            .$this->texto($derecha - $this->metricas->ancho($numero, 6.5), self::MARGEN + 2, 6.5, $numero, false, self::GRIS);
    }

    /** @return list<string> */
    private function envolver(string $texto, float $ancho, float $tamano): array
    {
        $lineas = [];
        $linea = '';
        foreach (explode(' ', $texto) as $palabra) {
            $candidata = $linea === '' ? $palabra : $linea.' '.$palabra;
            if ($linea !== '' && $this->metricas->ancho($candidata, $tamano) > $ancho) {
                $lineas[] = $linea;
                $candidata = $palabra;
            }
            $linea = $candidata;
        }
        $lineas[] = $linea;

        return $lineas;
    }

    private function texto(float $x, float $y, float $tamano, string $texto, bool $negrita = false, ?string $color = null): string
    {
        $salida = $this->pdf->texto($x, $y, $tamano, $texto, $negrita);

        // DocumentoPdfPlanta pinta en negro («0 g»); el color cambia solo para este texto.
        return $color ? $color.' rg'.substr($salida, 3) : $salida;
    }
}
