<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoCamionRomana;
use App\Enums\TipoRecepcionRomana;
use App\Models\RecepcionRomana;
use DomainException;

class GeneradorAvisoReciboPdf
{
    public function generar(RecepcionRomana $recepcion): string
    {
        if ($recepcion->estado !== EstadoRecepcionRomana::Cerrado) {
            throw new DomainException('El Aviso de Recibo solo está disponible para recepciones cerradas.');
        }
        $recepcion->loadMissing('detallesEnvases', 'salidasEnvases', 'pesajesEnvases');
        $esPesajeEnvases = $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaPesajeEnvases;
        $esSoloEnvases = $recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases;
        $envases = $recepcion->detallesEnvases
            ->map(function ($detalle) use ($recepcion): string {
                $linea = ($recepcion->modo_salida_envases !== null ? $detalle->cantidad_validada : $detalle->cantidad_declarada).' '.ucfirst($detalle->tipo_envase->value);
                if ($recepcion->modo_salida_envases !== null && $detalle->tara_unitaria_salida !== null) {
                    $linea .= ' (tara/u '.$this->peso($detalle->tara_unitaria_salida).' kg)';
                }

                return $linea;
            })
            ->implode(' · ');
        if ($esPesajeEnvases) {
            $lecturas = $recepcion->pesajesEnvases->whereNull('anulado_at')->count();
            $envases .= sprintf(
                ' · tara/u %s kg · %d/%d pesados · %d tanda(s)',
                $this->peso($recepcion->tara_unitaria_envase),
                $recepcion->cantidad_envases_pesados,
                $recepcion->cantidad_envases_declarados,
                $lecturas,
            );
        }

        $lineas = [
            ['N° recepción', $recepcion->numero_recepcion],
            ['Ingreso', $recepcion->ingreso_at?->format('d-m-Y H:i')],
            [$esSoloEnvases
                ? 'Cierre documental'
                : ($esPesajeEnvases ? 'Cierre de pesaje' : 'Salida / destare'), $recepcion->salida_at?->format('d-m-Y H:i')],
            ['Cliente', $recepcion->cliente_nombre_snapshot],
            ['Código cliente', $recepcion->cliente_codigo_snapshot ?: 'Sin código externo'],
            ['Tipo recepción', match ($recepcion->tipo_recepcion) {
                TipoRecepcionRomana::FrutaPesajeEnvases => 'Fruta con pesaje acumulativo de envases',
                TipoRecepcionRomana::SoloEnvases => 'Solo envases · sin registro de kilos',
                default => 'Fruta con envases',
            }],
            ['Servicio / concepto', ucfirst($recepcion->concepto_envases?->value ?? $recepcion->tipo_servicio->value)],
            ['Guía de despacho', $recepcion->numero_guia_despacho],
            ...($recepcion->modo_salida_envases !== null ? [
                ['Guía de salida', $recepcion->numero_guia_salida ?: 'Sin salida de envases'],
                ['Salida de envases', $recepcion->salidasEnvases->where('cantidad', '>', 0)
                    ->map(fn ($salida): string => $salida->cantidad.' '.ucfirst($salida->tipo_envase->value))
                    ->implode(' · ') ?: 'Ninguno'],
            ] : []),
            [$recepcion->modo_salida_envases !== null ? 'Envases validados' : 'Envases declarados', $envases],
            ['Patente camión', $recepcion->patente_camion],
            ['Tipo de camión', match ($recepcion->tipo_camion) {
                TipoCamionRomana::Termo => 'Camión termo',
                TipoCamionRomana::Plano => 'Camión plano',
                null => 'No informado',
            }],
            ['Patente carro', $recepcion->patente_carro ?: 'No informada'],
            ['Conductor', $recepcion->nombre_conductor],
            ['RUT conductor', $recepcion->rut_conductor],
        ];
        if ($esSoloEnvases) {
            $lineas[] = ['PESAJE', 'No aplica para recepción exclusiva de envases'];
        } elseif ($esPesajeEnvases) {
            $lineas[] = ['Bruto acumulado', $this->peso($recepcion->peso_bruto).' kg'];
            $lineas[] = ['Tara acumulada', $this->peso($recepcion->peso_tara).' kg'];
            $lineas[] = ['PESO NETO / PROMEDIO', $this->peso($recepcion->peso_neto).' kg · '
                .$this->peso($recepcion->peso_neto_por_envase).' kg/envase'];
        } else {
            $lineas[] = ['Peso bruto', $this->peso($recepcion->peso_bruto).' kg'];
            $lineas[] = $recepcion->modo_salida_envases !== null || $recepcion->salida_sin_envases
                ? ['Tara camión / diferencia envases',
                    $this->peso($recepcion->peso_tara).' / '
                    .$this->peso($recepcion->peso_tara_envases).' kg']
                : ['Peso tara camión', $this->peso($recepcion->peso_tara).' kg'];
            $lineas[] = ['PESO NETO', $this->peso($recepcion->peso_neto).' kg'];
        }
        $inicioPesos = $esSoloEnvases ? count($lineas) - 1 : count($lineas) - 3;
        $indiceNeto = count($lineas) - 1;

        $contenido = $this->encabezado($recepcion);
        $contenido .= "0.15 0.72 0.70 RG 42 752 m 553 752 l S\n";
        $contenido .= $this->texto(42, 726, 11, 'Antecedentes contractuales de ingreso al frigorífico', true);

        $y = 695;
        foreach ($lineas as $indice => [$etiqueta, $valor]) {
            if ($indice === $inicioPesos) {
                $contenido .= '0.92 0.96 0.97 rg 38 '.($y - 9)." 519 31 re f\n";
            }
            if ($indice === $indiceNeto) {
                $contenido .= '0.08 0.50 0.48 rg 38 '.($y - 12)." 519 36 re f\n";
            }
            $color = $indice === $indiceNeto ? '1 1 1' : '0.15 0.20 0.23';
            $contenido .= $this->texto(48, $y, 9, (string) $etiqueta, $indice === $indiceNeto, $color);
            $contenido .= $this->texto(235, $y, $indice === $indiceNeto ? 13 : 10, (string) $valor, true, $color);
            $y -= $indice >= $inicioPesos ? 35 : 25;
        }

        $contenido .= $this->texto(42, 195, 9, 'Observación de ingreso', true);
        $contenido .= $this->texto(42, 178, 9, $recepcion->observacion ?: 'Sin observaciones.');
        $contenido .= $this->texto(42, 150, 9, 'Observación de cierre', true);
        $contenido .= $this->texto(42, 133, 9, $recepcion->observacion_cierre ?: 'Sin observaciones.');
        $contenido .= "0.65 0.70 0.72 RG 42 122 m 240 122 l S 355 122 m 553 122 l S\n";
        $contenido .= $this->texto(78, 105, 8, 'Operador de romana');
        $contenido .= $this->texto(403, 105, 8, 'Transportista');
        $contenido .= $this->texto(42, 52, 7, 'Documento generado por FoliOS. Los pesos corresponden a los registros cerrados de la romana.');

        return $this->documento($contenido);
    }

    public function generarEnBlanco(): string
    {
        $lineas = [
            'N° recepción',
            'Ingreso',
            'Salida / destare',
            'Cliente',
            'Código cliente',
            'Tipo recepción',
            'Servicio / concepto',
            'Guía de despacho',
            'Envases declarados',
            'Patente camión',
            'Tipo de camión',
            'Patente carro',
            'Conductor',
            'RUT conductor',
            'Peso bruto',
            'Tara camión / envases',
            'PESO NETO',
        ];
        $inicioPesos = count($lineas) - 3;
        $indiceNeto = count($lineas) - 1;

        $contenido = $this->encabezado();
        $contenido .= "0.15 0.72 0.70 RG 42 752 m 553 752 l S\n";
        $contenido .= $this->texto(42, 726, 11, 'Antecedentes contractuales de ingreso al frigorífico', true);

        $y = 695;
        foreach ($lineas as $indice => $etiqueta) {
            if ($indice === $inicioPesos) {
                $contenido .= '0.92 0.96 0.97 rg 38 '.($y - 9)." 519 31 re f\n";
            }
            if ($indice === $indiceNeto) {
                $contenido .= '0.08 0.50 0.48 rg 38 '.($y - 12)." 519 36 re f\n";
            }
            $color = $indice === $indiceNeto ? '1 1 1' : '0.15 0.20 0.23';
            $contenido .= $this->texto(48, $y, 9, $etiqueta, $indice === $indiceNeto, $color);
            $contenido .= $color.' RG 235 '.($y - 3).' m 545 '.($y - 3)." l S\n";
            $y -= $indice >= $inicioPesos ? 35 : 25;
        }

        $contenido .= $this->texto(42, 222, 9, 'Observación de ingreso', true);
        $contenido .= "0.65 0.70 0.72 RG 42 202 m 553 202 l S\n";
        $contenido .= $this->texto(42, 180, 9, 'Observación de cierre', true);
        $contenido .= "0.65 0.70 0.72 RG 42 160 m 553 160 l S\n";
        $contenido .= "0.65 0.70 0.72 RG 42 122 m 240 122 l S 355 122 m 553 122 l S\n";
        $contenido .= $this->texto(78, 105, 8, 'Operador de romana');
        $contenido .= $this->texto(403, 105, 8, 'Transportista');
        $contenido .= $this->texto(42, 52, 7, 'Formulario en blanco generado por FoliOS para contingencia, trazabilidad y auditoría.');

        return $this->documento($contenido);
    }

    private function encabezado(?RecepcionRomana $recepcion = null): string
    {
        $fecha = $recepcion === null
            ? ''
            : ($recepcion->salida_at?->format('d-m-Y')
                ?? $recepcion->ingreso_at?->format('d-m-Y')
                ?? now()->format('d-m-Y'));

        $contenido = "0.12 0.16 0.18 RG 0.75 w\n";
        $contenido .= "42 770 511 60 re S\n";
        $contenido .= "182 770 m 182 830 l S\n";
        $contenido .= "420 770 m 420 830 l S\n";
        $contenido .= "480 770 m 480 830 l S\n";
        $contenido .= "420 790 m 553 790 l S\n";
        $contenido .= "420 810 m 553 810 l S\n";

        $contenido .= $this->texto(87, 811, 20, 'AR', true, '0.20 0.55 0.16');
        $contenido .= $this->texto(81, 802, 6, 'AGRO ROSARIO', true, '0.20 0.55 0.16');
        $contenido .= $this->texto(49, 784, 5, 'LOCALIDAD: RENGO, CARRETERA 5 SUR, KM 108,');
        $contenido .= $this->texto(67, 776, 5, 'ROSARIO, COMUNA DE RENGO');

        $contenido .= $this->texto(220, 806, 14, 'REGISTRO DE PESAJE', false, '0.05 0.05 0.05');
        $contenido .= $this->texto(277, 784, 14, 'ROMANA', false, '0.05 0.05 0.05');

        $contenido .= $this->texto(426, 817, 7, 'CODIGO', false, '0.05 0.05 0.05');
        $contenido .= $this->texto(486, 817, 7, 'POR DEFINIR', false, '0.05 0.05 0.05');
        $contenido .= $this->texto(426, 797, 7, 'VERSION', false, '0.05 0.05 0.05');
        $contenido .= $this->texto(511, 797, 8, '0', false, '0.05 0.05 0.05');
        $contenido .= $this->texto(426, 777, 7, 'FECHA', false, '0.05 0.05 0.05');
        $contenido .= $this->texto(495, 777, 7, $fecha, false, '0.05 0.05 0.05');

        return $contenido;
    }

    private function peso(mixed $valor): string
    {
        return number_format((float) $valor, 3, ',', '.');
    }

    private function texto(
        float $x,
        float $y,
        int $tamano,
        string $texto,
        bool $negrita = false,
        string $color = '0.15 0.20 0.23',
    ): string {
        $texto = function_exists('iconv')
            ? (iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto) ?: $texto)
            : $texto;
        $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $texto);
        $fuente = $negrita ? 'F2' : 'F1';

        return sprintf("%s rg BT /%s %d Tf %.2F %.2F Td (%s) Tj ET\n", $color, $fuente, $tamano, $x, $y, $texto);
    }

    private function documento(string $contenido): string
    {
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}endstream",
        ];

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($pdf);
            $numero = $indice + 1;
            $pdf .= "{$numero} 0 obj\n{$objeto}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objetos) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objetos) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
