<?php

namespace App\Services\Romana;

use App\Enums\TipoEnvaseRomana;
use App\Models\RecepcionRomana;
use App\Services\Documentos\DocumentoPdfPlanta;
use App\Services\Documentos\ServicioFormatosRegistro;
use Carbon\CarbonImmutable;

class GeneradorControlEnvasesPdf
{
    public function __construct(private readonly DocumentoPdfPlanta $pdf, private readonly ServicioFormatosRegistro $formatos) {}

    public function generar(RecepcionRomana $recepcion, string $tipo): string
    {
        $recepcion->loadMissing('validacionesMp.segmentos.csg.productor', 'validacionesMp.validador', 'cerradoPor');
        $inspeccion = $recepcion->inspeccionesEnvases()->where('tipo', $tipo)->firstOrFail();
        $inspeccion->load('usuario', 'items');
        $productores = $recepcion->validacionesMp->flatMap(fn ($v) => $v->segmentos)->map(fn ($s) => $s->csg?->productor?->razon_social ?? $s->csg?->predio ?? $s->csg_snapshot)->filter()->unique()->implode(' · ');
        $zona = config('app.operational_timezone');
        $valores = [
            'FECHA' => $recepcion->ingreso_at?->timezone($zona)->format('d-m-Y'),
            'HORA ENTRADA' => $recepcion->ingreso_at?->timezone($zona)->format('H:i'),
            'TIPO DE CAMION' => ucfirst($recepcion->tipo_camion?->value ?? ''),
            'HORA DESCARGA' => $recepcion->validado_at?->timezone($zona)->format('H:i'),
            'HORA SALIDA' => $recepcion->salida_at?->timezone($zona)->format('H:i'),
            'N° GUIA' => $tipo === 'despacho' ? $recepcion->numero_guia_salida : $recepcion->numero_guia_despacho,
            'EXPORTADORA' => $recepcion->cliente_nombre_snapshot, 'PRODUCTOR' => $productores,
            'CHOFER' => $recepcion->nombre_conductor, 'R.U.T CHOFER' => $recepcion->rut_conductor,
            'PATENTE' => $recepcion->patente_camion, 'CARRO' => $recepcion->patente_carro,
        ];
        $controles = array_map(fn ($c) => $tipo === 'despacho' ? 'No aplica' : ($inspeccion->$c ? 'Sí' : 'No'), ServicioInspeccionEnvases::CONTROLES);
        $notas = $inspeccion->items->filter(fn ($i) => filled($i->nota))->map(fn ($i) => TipoEnvaseRomana::from($i->tipo_envase)->etiqueta().': '.$i->nota)->implode('; ');
        $observacion = trim(implode("\n", array_filter([$inspeccion->observacion, $notas])));

        return $this->renderizar($inspeccion->formato, $tipo, $valores, $inspeccion->items->keyBy('tipo_envase')->toArray(), $controles, $observacion, ($tipo === 'despacho' ? $recepcion->cerradoPor?->name : $recepcion->validacionesMp->first(fn ($v) => $v->validada_at !== null)?->validador?->name) ?? $inspeccion->usuario->name);
    }

    public function generarEnBlanco(): string
    {
        return $this->renderizar($this->formatos->vigente('RC-02'), null, [], [], ['', '', ''], '', '');
    }

    private function encabezado(array $formato): string
    {
        $s = "0.2 G 0.6 w 42 741 511 66 re S\n192 741 m 192 807 l S 421 741 m 421 807 l S\nq 78 0 0 51.62 78 749 cm /Logo Do Q\n";
        $s .= $this->t(214, 772, 12, 'CONTROL DE ENVASES', true);
        foreach ([['CODIGO', $formato['codigo']], ['VERSION', $formato['version']], ['FECHA', CarbonImmutable::parse($formato['fecha_vigencia'])->format('d-m-Y')]] as $n => [$etiqueta,$valor]) {
            $y = 807 - ($n + 1) * 22;
            if ($n < 2) {
                $s .= "421 {$y} m 553 {$y} l S\n";
            }
            $s .= $this->t(428, $y + 8, 7, $etiqueta).$this->t(478, $y + 8, 8, $valor);
        }
        foreach ($this->pdf->envolver('LOCALIDAD: '.mb_strtoupper($formato['localidad']), 500, 7) as $n => $linea) {
            $s .= $this->t(45, 729 - $n * 9, 7, $linea);
        }

        return $s;
    }

    private function renderizar(array $formato, ?string $tipo, array $valores, array $items, array $controles, string $observacion, string $usuario): string
    {
        $s = $this->encabezado($formato);
        $y = 708;
        $paginas = [];
        // Disposición de la planilla: datos de transporte arriba, inspección abajo.
        foreach ([['FECHA', 'HORA ENTRADA', 'TIPO DE CAMION'], ['HORA DESCARGA', 'HORA SALIDA', 'N° GUIA'], ['EXPORTADORA', 'PRODUCTOR']] as $campos) {
            $ancho = 511 / count($campos);
            $alto = 27;
            foreach ($campos as $c) {
                $alto = max($alto, 15 + count($this->pdf->envolver((string) ($valores[$c] ?? ''), $ancho - 12, 8)) * 10);
            }
            if ($y - $alto < 60) {
                $paginas[] = $s;
                $s = $this->encabezado($formato);
                $y = 708;
            }
            foreach ($campos as $n => $campo) {
                $x = 42 + $n * $ancho;
                $s .= $this->rect($x, $y - $alto, $ancho, $alto).$this->t($x + 5, $y - 10, 7, $campo.':', true);
                foreach ($this->pdf->envolver((string) ($valores[$campo] ?? ''), $ancho - 12, 8) as $i => $linea) {
                    $s .= $this->t($x + 5, $y - 21 - $i * 10, 8, $linea);
                }
            } $y -= $alto;
        }
        if ($y < 505) {
            $paginas[] = $s;
            $s = $this->encabezado($formato);
            $y = 708;
        }
        $s .= $this->rect(42, $y - 77, 255, 77).$this->rect(297, $y - 77, 256, 77);
        $s .= $this->t(48, $y - 13, 8, 'DETALLE').$this->t(116, $y - 13, 8, 'MARQUE CON UNA X', true);
        foreach (['recepcion' => 'RECEPCION', 'despacho' => 'DESPACHO'] as $op => $nombre) {
            $z = $y - ($op === 'recepcion' ? 34 : 60);
            $s .= $this->t(70, $z, 9, $nombre.':').$this->rect(206, $z - 2, 13, 13);
            if ($tipo === $op) {
                $s .= $this->t(209, $z + 1, 9, 'X', true);
            }
        }
        foreach (['CHOFER', 'R.U.T CHOFER'] as $i => $campo) {
            $s .= $this->t(303, $y - 13 - $i * 23, 7, $campo.':', true);
            foreach ($this->pdf->envolver((string) ($valores[$campo] ?? ''), 244, 8) as $n => $linea) {
                $s .= $this->t(303, $y - 24 - $i * 23 - $n * 9, 8, $linea);
            }
        }
        $s .= $this->t(303, $y - 70, 7, 'PATENTE: '.($valores['PATENTE'] ?? '')).$this->t(437, $y - 70, 7, 'CARRO: '.($valores['CARRO'] ?? ''));
        $y -= 77;
        $columnas = [42, 224, 296, 403, 553];
        foreach (['ENVASE / ARTICULO', 'CANTIDAD', 'LIMPIEZA (SI / NO)', 'CONDICION'] as $n => $c) {
            $s .= $this->rect($columnas[$n], $y - 24, $columnas[$n + 1] - $columnas[$n], 24).$this->t($columnas[$n] + 5, $y - 15, 7, $c, true);
        }
        $y -= 24;
        foreach (TipoEnvaseRomana::cases() as $envase) {
            $fila = $items[$envase->value] ?? null;
            $textos = [mb_strtoupper($envase->etiqueta()), $fila ? (string) $fila['cantidad'] : '', $fila ? ($fila['limpieza'] ? 'Sí' : 'No') : '', $fila ? ucfirst($fila['condicion']) : ''];
            foreach ($textos as $n => $texto) {
                $s .= $this->rect($columnas[$n], $y - 26, $columnas[$n + 1] - $columnas[$n], 26).$this->t($columnas[$n] + 5, $y - 17, 8, $texto);
            }
            $y -= 26;
        }
        foreach (['COINCIDE ESPECIE Y VARIEDAD', 'COINCIDE CANTIDAD DE BINS', 'BINS BIEN ETIQUETADOS'] as $n => $control) {
            $s .= $this->rect(42, $y - 24, 511, 24).$this->t(48, $y - 15, 8, $control).$this->t(410, $y - 15, 8, $controles[$n]);
            $y -= 24;
        }
        $lineas = $this->pdf->envolver($observacion, 322, 8);
        $nombres = $this->pdf->envolver($usuario, 400, 9);
        // Nunca corta observaciones ni nombres; continúa con el mismo encabezado.
        $capacidad = max(1, (int) floor(($y - 100 - count($nombres) * 11) / 11));
        $primera = array_splice($lineas, 0, $capacidad);
        $alto = max(64, 25 + count($primera) * 11);
        $s .= $this->rect(42, $y - $alto, 350, $alto).$this->rect(392, $y - $alto, 161, $alto).$this->t(48, $y - 13, 8, 'OBSERVACIONES:', true).$this->t(403, $y - 13, 8, 'FIRMA CHOFER:', true);
        foreach ($primera as $n => $linea) {
            $s .= $this->t(48, $y - 28 - $n * 11, 8, $linea);
        }
        $y -= $alto + 18;
        if ($y - 15 - count($nombres) * 11 < 42) {
            $paginas[] = $s;
            $s = $this->encabezado($formato);
            $y = 700;
        }
        $s .= $this->t(48, $y, 8, 'RECEPCIONADO POR:', true);
        foreach ($nombres as $n => $linea) {
            $s .= $this->t(152, $y - $n * 11, 9, $linea);
        }
        $paginas[] = $s;
        while ($lineas) {
            $s = $this->encabezado($formato).$this->t(48, 703, 9, 'OBSERVACIONES (CONTINUACION)', true);
            foreach (array_splice($lineas, 0, 57) as $n => $linea) {
                $s .= $this->t(48, 684 - $n * 11, 8, $linea);
            }
            $paginas[] = $s;
        }

        return $this->pdf->generar($paginas);
    }

    private function t(float $x, float $y, float $tamano, string $texto, bool $negrita = false): string
    {
        return $this->pdf->texto($x, $y, $tamano, $texto, $negrita);
    }

    private function rect(float $x, float $y, float $ancho, float $alto): string
    {
        return sprintf("0.3 G 0.5 w %.2F %.2F %.2F %.2F re S\n", $x, $y, $ancho, $alto);
    }
}
