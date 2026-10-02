<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Services\Documentos\ServicioFormatosRegistro;
use Carbon\CarbonImmutable;

class GeneradorAvisoReciboPdf
{
    private const CAMPOS = [
        'N° recepción', 'Ingreso', 'Salida / destare', 'Cliente', 'Código cliente',
        'Tipo recepción', 'Servicio / concepto', 'Guía de despacho', 'Envases declarados',
        'Patente camión', 'Patente carro', 'Conductor', 'RUT conductor',
        'Peso bruto', 'Tara camión / envases', 'PESO NETO',
    ];

    public function __construct(private readonly ServicioFormatosRegistro $formatos) {}

    public function generar(RecepcionRomana $recepcion): string
    {
        $recepcion->loadMissing('detallesEnvases', 'cerradoPor');
        $cerrada = $recepcion->estado === EstadoRecepcionRomana::Cerrado;
        // La salida de envases pertenece a otro documento: RPR-01 declara el ingreso.
        $envases = $recepcion->detallesEnvases
            ->sortBy(fn ($detalle): int => $detalle->tipo_envase->orden())
            ->map(fn ($detalle): string => $detalle->cantidad_declarada.' '.$detalle->tipo_envase->etiqueta())
            ->implode(' · ');
        $valores = [
            $recepcion->numero_recepcion,
            $recepcion->ingreso_at?->format('d-m-Y H:i'),
            $cerrada ? $recepcion->salida_at?->format('d-m-Y H:i') : null,
            $recepcion->cliente_nombre_snapshot,
            $recepcion->cliente_codigo_snapshot,
            match ($recepcion->tipo_recepcion) {
                TipoRecepcionRomana::FrutaPesajeEnvases => 'Fruta con pesaje acumulativo de envases',
                TipoRecepcionRomana::SoloEnvases => 'Solo envases',
                default => 'Fruta con envases',
            },
            ucfirst($recepcion->concepto_envases?->value ?? $recepcion->tipo_servicio?->value ?? ''),
            $recepcion->numero_guia_despacho,
            $envases,
            $recepcion->patente_camion,
            $recepcion->patente_carro,
            $recepcion->nombre_conductor,
            $recepcion->rut_conductor,
            $this->peso($recepcion->peso_bruto),
            $cerrada && $recepcion->peso_tara !== null
                ? $this->peso((float) $recepcion->peso_tara + (float) ($recepcion->peso_tara_envases ?? 0)) : '',
            $cerrada ? $this->peso($recepcion->peso_neto) : '',
        ];

        return $this->renderizar(
            $this->formatos->paraRomana($recepcion), $valores,
            [$recepcion->observacion, $recepcion->observacion_cierre],
            [$cerrada ? $recepcion->cerradoPor?->name : null, $recepcion->nombre_conductor, null],
        );
    }

    public function generarEnBlanco(): string
    {
        return $this->renderizar($this->formatos->vigente('RPR-01'), array_fill(0, 16, ''), ['', ''], ['', '', '']);
    }

    /** @param array<string, string> $formato
     * @param  array<int, string|null>  $valores
     * @param  array<int, string|null>  $observaciones
     * @param  array<int, string|null>  $firmantes
     */
    private function renderizar(array $formato, array $valores, array $observaciones, array $firmantes): string
    {
        [$contenido, $y] = $this->encabezado($formato);
        $paginas = [];
        $contenido .= $this->texto(42, $y - 25, 10, 'Antecedentes contractuales de ingreso', true);
        $y -= 42;
        foreach (self::CAMPOS as $indice => $etiqueta) {
            $lineas = $this->envolver((string) $valores[$indice], 322, 9);
            $alto = max($indice >= 13 ? 28 : 23, count($lineas) * 12 + 10);
            $this->continuarSiNecesario($formato, $paginas, $contenido, $y, $alto);
            if ($indice === 15) {
                $contenido .= '0.94 g 42 '.($y - $alto).' 511 '.$alto." re f\n";
            }
            $contenido .= '0.3 G 0.5 w 42 '.($y - $alto).' 511 '.$alto." re S\n";
            $contenido .= '216 '.($y - $alto).' m 216 '.$y." l S\n";
            $contenido .= $this->texto(48, $y - 16, 9, $etiqueta, $indice >= 13);
            foreach ($lineas as $fila => $linea) {
                $contenido .= $this->texto(224, $y - 16 - $fila * 12, 9, $linea, $indice === 15);
            }
            $y -= $alto;
        }
        $y -= 16;
        foreach (['Observación de ingreso', 'Observación de cierre'] as $indice => $etiqueta) {
            $lineas = $this->envolver((string) $observaciones[$indice], 499, 9);
            $alto = max(43, count($lineas) * 12 + 24);
            $this->continuarSiNecesario($formato, $paginas, $contenido, $y, $alto);
            $contenido .= $this->texto(42, $y - 10, 9, $etiqueta, true);
            $contenido .= '0.3 G 42 '.($y - $alto).' 511 '.($alto - 18)." re S\n";
            foreach ($lineas as $fila => $linea) {
                $contenido .= $this->texto(48, $y - 30 - $fila * 12, 9, $linea);
            }
            $y -= $alto + 12;
        }
        $nombres = array_map(fn ($nombre): array => $this->envolver((string) $nombre, 150, 8), $firmantes);
        $altoFirmas = max(90, 69 + (max(array_map('count', $nombres)) - 1) * 10);
        $this->continuarSiNecesario($formato, $paginas, $contenido, $y, $altoFirmas);
        $y -= 32;
        foreach (['Operador de romana', 'Transportista', 'Jefe Frigorífico'] as $indice => $etiqueta) {
            $x = 42 + $indice * 176;
            $contenido .= "0.3 G {$x} {$y} m ".($x + 159)." {$y} l S\n";
            $contenido .= $this->texto($x + 4, $y - 15, 9, $etiqueta);
            foreach ($nombres[$indice] as $fila => $linea) {
                $contenido .= $this->texto($x + 4, $y - 29 - $fila * 10, 8, $linea);
            }
        }
        $paginas[] = $contenido;

        return $this->documento($paginas);
    }

    /** @param array<string, string> $formato
     * @return array{string, float}
     */
    private function encabezado(array $formato): array
    {
        $localidad = $this->envolver('LOCALIDAD: '.mb_strtoupper($formato['localidad']), 140, 5.5);
        $alto = max(90, 63 + count($localidad) * 7);
        $y = 807 - $alto;
        $contenido = "0.2 G 0.75 w 42 {$y} 511 {$alto} re S\n";
        $contenido .= "192 {$y} m 192 807 l S 416 {$y} m 416 807 l S\n";
        $contenido .= "q 82 0 0 54.26 76 750 cm /Logo Do Q\n";
        foreach ($localidad as $fila => $linea) {
            $contenido .= $this->texto(47, $y + 6 + (count($localidad) - 1 - $fila) * 7, 5.5, $linea);
        }
        $centro = $y + $alto / 2;
        $contenido .= $this->texto(216, $centro + 6, 12, 'REGISTRO DE PESAJE');
        $contenido .= $this->texto(274, $centro - 15, 12, 'ROMANA');
        $fecha = CarbonImmutable::parse($formato['fecha_vigencia'])->format('d-m-Y');
        foreach ([['CODIGO', $formato['codigo']], ['VERSION', $formato['version']], ['FECHA', $fecha]] as $fila => [$etiqueta, $valor]) {
            $tope = 807 - $fila * $alto / 3;
            $base = $tope - $alto / 3;
            $contenido .= "477 {$base} m 477 {$tope} l S\n";
            if ($fila < 2) {
                $contenido .= "416 {$base} m 553 {$base} l S\n";
            }
            $contenido .= $this->texto(422, $base + $alto / 6 - 3, 7, $etiqueta);
            foreach ($this->envolver($valor, 65, 7) as $linea => $texto) {
                $contenido .= $this->texto(483, $base + $alto / 6 - 3 - $linea * 8, 7, $texto);
            }
        }

        return [$contenido, (float) $y];
    }

    /** @param array<string, string> $formato
     * @param  array<int, string>  $paginas
     */
    private function continuarSiNecesario(array $formato, array &$paginas, string &$contenido, float &$y, float $alto): void
    {
        if ($y - $alto < 42) {
            $paginas[] = $contenido;
            [$contenido, $y] = $this->encabezado($formato);
            $y -= 24;
        }
    }

    /** @return array<int, string> */
    private function envolver(string $texto, float $ancho, float $tamano): array
    {
        $lineas = [];
        foreach (preg_split('/\R/u', $texto) ?: [''] as $parrafo) {
            $linea = '';
            foreach (preg_split('/\s+/u', trim($parrafo)) ?: [''] as $palabra) {
                if ($linea !== '' && $this->anchoTexto($linea.' '.$palabra, $tamano) > $ancho) {
                    $lineas[] = $linea;
                    $linea = '';
                }
                // Divide también un folio o una observación sin espacios.
                foreach (preg_split('//u', $palabra, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
                    if ($this->anchoTexto($linea.$caracter, $tamano) > $ancho) {
                        $lineas[] = $linea;
                        $linea = '';
                    }
                    $linea .= $caracter;
                }
                $linea .= ' ';
            }
            $lineas[] = trim($linea);
        }

        return $lineas;
    }

    private function anchoTexto(string $texto, float $tamano): float
    {
        $ancho = 0;
        foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            // Anchos conservadores para no recortar texto en Helvetica.
            $ancho += $tamano * (match (true) {
                str_contains('@%', $caracter) => 1.05,
                str_contains('MWmw', $caracter) => 0.95,
                str_contains(' ilI.,:;', $caracter) => 0.3,
                preg_match('/\p{Lu}/u', $caracter) => 0.78,
                default => 0.62,
            });
        }

        return $ancho;
    }

    private function peso(mixed $valor): string
    {
        return $valor === null ? '' : number_format((float) $valor, 3, ',', '.').' kg';
    }

    private function texto(float $x, float $y, float $tamano, string $texto, bool $negrita = false): string
    {
        $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto) ?: $texto;
        $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $texto);
        $fuente = $negrita ? 'F2' : 'F1';

        return sprintf("0 g BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $fuente, $tamano, $x, $y, $texto);
    }

    /** @param array<int, string> $paginas */
    private function documento(array $paginas): string
    {
        $logo = resource_path('images/logo-agrorosario.jpg');
        $imagen = file_get_contents($logo);
        [$ancho, $alto] = getimagesize($logo);
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($indice): string => (6 + $indice * 2).' 0 R', array_keys($paginas))).'] /Count '.count($paginas).' >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Type /XObject /Subtype /Image /Width '.$ancho.' /Height '.$alto.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($imagen)." >>\nstream\n{$imagen}\nendstream",
        ];
        foreach ($paginas as $indice => $contenido) {
            $objetos[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents '.(7 + $indice * 2).' 0 R >>';
            $objetos[] = '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}endstream";
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($pdf);
            $numero = $indice + 1;
            $pdf .= "{$numero} 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
