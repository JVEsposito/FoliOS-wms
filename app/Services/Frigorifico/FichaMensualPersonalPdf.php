<?php

namespace App\Services\Frigorifico;

use App\Models\AusenciaFrigorifico;
use App\Services\Documentos\DocumentoPdfPlanta;
use Carbon\CarbonImmutable;

/**
 * Ficha mensual día a día del control interno de personal de Frigorífico (A4 vertical).
 *
 * Se imprime para revisarla con el trabajador cuando pide contrastar sus horas con lo que
 * registró RRHH. No contiene ni consulta datos de RRHH.
 */
class FichaMensualPersonalPdf
{
    private const COLUMNAS = ['Día' => 58, 'Ingreso' => 44, 'Salida' => 58, 'Colación' => 42, 'Base' => 38, 'Trabajado' => 48, 'Extra' => 40, 'Observación' => 207];

    private const MARGEN = 30;

    private const ANCHO = 535;

    private const LIMITE_INFERIOR = 50;

    /** Alto que ocupan los totales, el criterio y la revisión con firmas al final de la ficha. */
    private const ALTO_CIERRE = 222;

    private const DIAS = [1 => 'lun', 2 => 'mar', 3 => 'mié', 4 => 'jue', 5 => 'vie', 6 => 'sáb', 7 => 'dom'];

    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(private readonly DocumentoPdfPlanta $pdf) {}

    /** @param list<array> $fichas resultado de ControlPersonal::ficha(), una por persona */
    public function generar(array $fichas, string $usuario): string
    {
        $generado = CarbonImmutable::now(config('app.operational_timezone', 'America/Santiago'))->format('d-m-Y H:i');
        $paginas = [];
        foreach ($fichas as $ficha) {
            $propias = $this->ficha($ficha);
            foreach ($propias as $i => $contenido) {
                $paginas[] = $contenido.$this->pdf->texto(self::MARGEN, 26, 7, sprintf(
                    'FoliOS · Control interno de personal · %s · Generado por %s el %s · Hoja %d de %d',
                    $ficha['persona']->nombre, $usuario, $generado, $i + 1, count($propias),
                ));
            }
        }

        return $this->pdf->generar($paginas);
    }

    /** @return list<string> páginas de una persona, sin pie */
    private function ficha(array $ficha): array
    {
        $paginas = [];
        $contenido = $this->cabecera($ficha, false);
        $y = $this->cabeceraTabla($contenido, 712);
        foreach ($ficha['dias'] as $dia) {
            foreach ($this->filasDia($dia, $ficha['persona']->sector) as $indice => $fila) {
                $lineas = $this->pdf->envolver($fila['observacion'], self::COLUMNAS['Observación'] - 8, 7);
                $alto = max(1, count($lineas)) * 8.5 + 4;
                if ($y - $alto < self::LIMITE_INFERIOR) {
                    $paginas[] = $contenido;
                    $contenido = $this->cabecera($ficha, true);
                    $y = $this->cabeceraTabla($contenido, 740);
                }
                if ($dia['dia_semana'] >= 6) {
                    $contenido .= sprintf("0.93 g %.2F %.2F %.2F %.2F re f 0 g\n", self::MARGEN, $y - $alto, self::ANCHO, $alto);
                }
                $celdas = [$indice === 0 ? $this->dia($dia) : '', $fila['ingreso'], $fila['salida'], $fila['colacion'], $fila['base'], $fila['trabajado'], $fila['extra']];
                $x = self::MARGEN;
                foreach (array_values(self::COLUMNAS) as $c => $ancho) {
                    if ($c < count($celdas) && $celdas[$c] !== '') {
                        $contenido .= $this->texto($x + 3, $y - 9, 7.5, $celdas[$c], $fila['negrita'] && $c === 1, $fila['tenue'] && $c > 0);
                    }
                    $x += $ancho;
                }
                foreach ($lineas as $l => $linea) {
                    $contenido .= $this->texto($x - self::COLUMNAS['Observación'] + 3, $y - 9 - $l * 8.5, 7, $linea, false, $fila['tenue']);
                }
                $y -= $alto;
                $contenido .= sprintf("0.8 G %.2F %.2F m %.2F %.2F l S 0 G\n", self::MARGEN, $y, self::MARGEN + self::ANCHO, $y);
            }
        }
        if ($y - self::ALTO_CIERRE < self::LIMITE_INFERIOR) {
            $paginas[] = $contenido;
            $contenido = $this->cabecera($ficha, true);
            $y = 740;
        }
        $paginas[] = $contenido.$this->cierre($ficha, $y - 18);

        return $paginas;
    }

    private function cabecera(array $ficha, bool $continuacion): string
    {
        $p = $ficha['persona'];
        [$anio, $mes] = array_map('intval', explode('-', $ficha['mes']));
        $periodo = self::MESES[$mes].' '.$anio;
        $c = "q 60 0 0 30 30 790 cm /Logo Do Q\n"
            .$this->pdf->texto(105, 806, 13, 'FICHA MENSUAL DE JORNADAS', true)
            .$this->pdf->texto(105, 792, 8, 'Control interno de Frigorífico · '.(config('oficina.planta') ?: 'Planta').($continuacion ? ' · continuación' : ''))
            .$this->pdf->texto(450, 806, 11, $periodo, true);
        if ($continuacion) {
            return $c.$this->pdf->texto(self::MARGEN, 768, 9, $p->nombre, true);
        }
        $datos = array_filter(['Identificador: '.($p->identificador ?: 'sin registro'), 'Sector: '.$p->sector, $p->funcion ? 'Función: '.$p->funcion : null,
            'Jornada base actual: '.$this->duracion($p->jornada_base_minutos)]);

        return $c.$this->pdf->texto(self::MARGEN, 764, 11, $p->nombre, true)
            .$this->pdf->texto(self::MARGEN, 750, 8, implode(' · ', $datos))
            .$this->pdf->texto(self::MARGEN, 736, 7, 'Una fila por día del mes. Fines de semana sombreados. La salida marcada +1 termina al día siguiente. Las jornadas y ausencias anuladas no se muestran.');
    }

    private function cabeceraTabla(string &$contenido, float $y): float
    {
        $contenido .= sprintf("0.85 g %.2F %.2F %.2F 16 re f 0 g\n", self::MARGEN, $y - 16, self::ANCHO);
        $x = self::MARGEN;
        foreach (self::COLUMNAS as $titulo => $ancho) {
            $contenido .= $this->pdf->texto($x + 3, $y - 11, 7.5, $titulo, true);
            $x += $ancho;
        }

        return $y - 16;
    }

    /** @return list<array{ingreso: string, salida: string, colacion: string, base: string, trabajado: string, extra: string, observacion: string, negrita: bool, tenue: bool}> */
    private function filasDia(array $dia, string $sectorPersona): array
    {
        $filas = [];
        if ($a = $dia['ausencia']) {
            // El periodo y la observación se escriben una sola vez: el primer día de la ausencia dentro del mes.
            $primerDia = $dia['fecha'] === $a['desde'] || str_ends_with($dia['fecha'], '-01');
            $periodo = $a['desde'] === $a['hasta'] ? '' : 'Periodo '.$this->fecha($a['desde']).' al '.$this->fecha($a['hasta']).'. ';
            $filas[] = ['ingreso' => AusenciaFrigorifico::TIPOS[$a['tipo']], 'salida' => '', 'colacion' => '', 'base' => '', 'trabajado' => '', 'extra' => '',
                'observacion' => $primerDia ? trim($periodo.($a['observacion'] ?? '')) : '', 'negrita' => true, 'tenue' => false];
        }
        foreach ($dia['jornadas'] as $j) {
            $salida = '—';
            if ($j['salida']) {
                $salto = (int) CarbonImmutable::createFromFormat('!Y-m-d', substr($j['ingreso'], 0, 10), 'UTC')
                    ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', substr($j['salida'], 0, 10), 'UTC'));
                $salida = substr($j['salida'], 11, 5).($salto > 0 ? ' +'.$salto : '');
            }
            $filas[] = ['ingreso' => substr($j['ingreso'], 11, 5), 'salida' => $j['salida'] ? $salida : 'Sin salida',
                'colacion' => $this->duracion($j['colacion_minutos']), 'base' => $this->duracion($j['jornada_base_minutos']),
                'trabajado' => $this->duracion($j['minutos_trabajados']), 'extra' => $this->duracion($j['minutos_extra']),
                'observacion' => implode(' · ', array_filter([$j['sector'] !== $sectorPersona ? 'Sector: '.$j['sector'] : null, $j['observacion'] ?? null])),
                'negrita' => false, 'tenue' => false];
        }
        if ($filas === []) {
            $filas[] = ['ingreso' => '—', 'salida' => '', 'colacion' => '', 'base' => '', 'trabajado' => '', 'extra' => '', 'observacion' => 'Sin registro', 'negrita' => false, 'tenue' => true];
        }

        return $filas;
    }

    private function cierre(array $ficha, float $y): string
    {
        $r = $ficha['resumen'] ?? ['jornadas_cerradas' => 0, 'pendientes' => 0, 'minutos_trabajados' => 0, 'minutos_extra' => 0, 'ausencias' => []];
        $ausencias = [];
        foreach (AusenciaFrigorifico::TIPOS as $tipo => $nombre) {
            if (($r['ausencias'][$tipo] ?? 0) > 0) {
                $ausencias[] = $nombre.': '.$r['ausencias'][$tipo].' '.($r['ausencias'][$tipo] === 1 ? 'día' : 'días');
            }
        }
        $c = $this->pdf->texto(self::MARGEN, $y, 10, 'Totales del mes', true);
        $totales = [['Jornadas cerradas', (string) $r['jornadas_cerradas']], ['Salidas pendientes', (string) $r['pendientes']],
            ['Trabajado', $this->duracion($r['minutos_trabajados'])], ['Horas extra', $this->duracion($r['minutos_extra'])]];
        foreach ($totales as $i => [$etiqueta, $valor]) {
            $x = self::MARGEN + $i * 134;
            $c .= sprintf("0.6 G %.2F %.2F 126 30 re S 0 G\n", $x, $y - 40)
                .$this->pdf->texto($x + 6, $y - 20, 7, $etiqueta)
                .$this->pdf->texto($x + 6, $y - 34, 12, $valor, true);
        }
        $y -= 56;
        $c .= $this->pdf->texto(self::MARGEN, $y, 8, 'Ausencias: '.($ausencias === [] ? 'sin ausencias registradas' : implode(' · ', $ausencias)).'.');
        $criterio = 'Horas extra internas = permanencia - colación - jornada base del día, con mínimo cero. Las jornadas que cruzan medianoche se imputan completas al día de ingreso. '
            .'Este es el registro interno del supervisor: no es registro de asistencia ni liquidación de remuneraciones. Sirve para contrastarlo con lo informado por RRHH.';
        foreach ($this->pdf->envolver($criterio, self::ANCHO, 7) as $i => $linea) {
            $c .= $this->texto(self::MARGEN, $y - 14 - $i * 9, 7, $linea, false, true);
        }
        $y -= 50;
        $c .= $this->pdf->texto(self::MARGEN, $y, 9, 'Revisión con el trabajador', true)
            .$this->pdf->texto(self::MARGEN, $y - 16, 8, 'Diferencias observadas:');
        foreach ([0, 1] as $i) {
            $linea = $y - 22 - $i * 16;
            $c .= sprintf("0.6 G %.2F %.2F m %.2F %.2F l S 0 G\n", self::MARGEN + ($i === 0 ? 102 : 0), $linea, self::MARGEN + self::ANCHO, $linea);
        }
        $firma = $y - 82;
        foreach (['Supervisor', 'Trabajador'] as $i => $rol) {
            $x = self::MARGEN + $i * 280;
            $c .= sprintf("0 G %.2F %.2F m %.2F %.2F l S\n", $x, $firma, $x + 220, $firma)
                .$this->pdf->texto($x, $firma - 11, 8, $rol.' · nombre, firma y fecha');
        }

        return $c;
    }

    private function texto(float $x, float $y, float $tamano, string $texto, bool $negrita, bool $tenue): string
    {
        $linea = $this->pdf->texto($x, $y, $tamano, $texto, $negrita);

        return $tenue ? preg_replace('/^0 g BT/', '0.45 g BT', $linea) : $linea;
    }

    private function dia(array $dia): string
    {
        return self::DIAS[$dia['dia_semana']].' '.substr($dia['fecha'], 8, 2).'-'.substr($dia['fecha'], 5, 2);
    }

    private function fecha(string $fecha): string
    {
        return implode('-', array_reverse(explode('-', $fecha)));
    }

    private function duracion(?int $minutos): string
    {
        return $minutos === null ? '—' : sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60);
    }
}
