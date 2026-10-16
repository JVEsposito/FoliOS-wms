<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\GuiaDespachoEnvase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class GeneradorGuiaDespachoEnvasesPdf
{
    public function generar(GuiaDespachoEnvase $guia): string
    {
        $guia->loadMissing([
            'temporada',
            'cliente',
            'detalles.movimientoOrigen.cliente',
            'creadoPor',
            'confirmadoPor',
            'canceladoPor',
        ]);
        $snapshot = $guia->documento_snapshot ?: $this->snapshotBorrador($guia);
        $salidaConfirmada = in_array($guia->estado, [
            EstadoGuiaDespachoEnvase::Confirmada,
            EstadoGuiaDespachoEnvase::Anulada,
        ], true);
        $estado = $salidaConfirmada
            ? EstadoGuiaDespachoEnvase::Confirmada->value
            : $guia->estado->value;
        $esBorrador = ! $salidaConfirmada;
        $reservaActiva = $guia->estado === EstadoGuiaDespachoEnvase::Borrador;
        $esHistoricoReconstruido = $salidaConfirmada && (
            ! $guia->documento_snapshot
            || ($snapshot['historico_reconstruido'] ?? false) === true
        );

        $contenido = "0.08 0.16 0.20 rg 0 770 595 72 re f\n";
        $contenido .= $this->texto(38, 810, 20, 'FoliOS', true, '1 1 1');
        $contenido .= $this->texto(38, 785, 11, 'GUÍA DE DESPACHO INTERNA DE ENVASES', false, '0.75 0.92 0.94');
        $contenido .= $this->texto(414, 807, 12, (string) $snapshot['numero'], true, '1 1 1');
        $contenido .= $this->texto(414, 786, 8, 'DOCUMENTO NO TRIBUTARIO', false, '0.75 0.92 0.94');
        if ($esBorrador) {
            $contenido .= $this->texto(360, 742, 18, strtoupper($estado), true, '0.84 0.38 0.15');
        } else {
            $contenido .= $this->texto(390, 742, 12, 'SALIDA CONFIRMADA', true, '0.08 0.50 0.48');
        }
        $contenido .= "0.15 0.72 0.70 RG 38 728 m 557 728 l S\n";

        $guiaEmitida = $guia->numero_guia_despacho ?? ($snapshot['numero_guia_despacho'] ?? null);
        $lineas = [
            ['Guía de despacho', $guiaEmitida ? 'N° '.$guiaEmitida.' (sistema de facturación)' : ($esBorrador ? 'Se registra al confirmar la salida' : 'No registrada')],
            ['Temporada', $snapshot['temporada']['codigo'].' · '.$snapshot['temporada']['nombre']],
            ['Cliente destino', $snapshot['cliente']['codigo'].' · '.$snapshot['cliente']['nombre']],
            ['Salida física', $this->fecha($snapshot['salida_at'] ?? null)],
            ['Confirmación sistema', $this->fecha($snapshot['confirmado_at'] ?? null)],
            ['Patente', $snapshot['patente_camion'] ?: 'No informada'],
            ['Conductor', $snapshot['conductor']['nombre'] ?: 'No informado'],
            ['RUT conductor', $snapshot['conductor']['rut'] ?: 'No informado'],
        ];
        $y = 700;
        foreach ($lineas as [$etiqueta, $valor]) {
            $contenido .= $this->texto(42, $y, 9, (string) $etiqueta, true);
            $contenido .= $this->texto(190, $y, 9, $this->cortar((string) $valor, 70));
            $y -= 22;
        }

        $contenido .= '0.92 0.96 0.97 rg 38 495 519 28 re f'."\n";
        $contenido .= $this->texto(44, 504, 9, 'TIPO / PROPIEDAD', true);
        $contenido .= $this->texto(270, 504, 9, 'CANTIDAD', true);
        $contenido .= $this->texto(350, 504, 9, 'EFECTO CUENTA', true);
        $contenido .= $this->texto(455, 504, 9, 'EXISTENCIA', true);

        $resumen = collect($snapshot['detalles'])
            ->groupBy(fn (array $linea): string => $linea['tipo_envase'].'|'.$linea['propiedad'])
            ->map(function (Collection $lineas, string $clave) use ($esBorrador, $reservaActiva): array {
                [$tipo, $propiedad] = explode('|', $clave, 2);
                $cantidad = (int) $lineas->sum('cantidad');

                return [
                    'etiqueta' => ucfirst($tipo).' · '.$this->propiedad($propiedad),
                    'cantidad' => $cantidad,
                    'cuenta' => $esBorrador ? 'Sin movimiento' : '-'.$cantidad,
                    'existencia' => $esBorrador
                        ? ($reservaActiva ? 'Reserva '.$cantidad : 'Sin movimiento')
                        : '-'.$cantidad,
                ];
            })
            ->values();
        $y = 472;
        foreach ($resumen as $linea) {
            $contenido .= $this->texto(44, $y, 9, $linea['etiqueta'], true);
            $contenido .= $this->texto(280, $y, 10, (string) $linea['cantidad'], true);
            $contenido .= $this->texto(365, $y, 9, $linea['cuenta']);
            $contenido .= $this->texto(472, $y, 9, $linea['existencia']);
            $y -= 25;
        }

        $contenido .= $this->texto(42, 382, 9, 'TRAZABILIDAD DE ORIGEN', true);
        $origenes = collect($snapshot['detalles'])
            ->groupBy('origen')
            ->map(fn (Collection $lineas, string $origen): string => sprintf(
                '%s · %d unidades',
                $origen ?: 'Origen no informado',
                $lineas->sum('cantidad'),
            ))
            ->values()
            ->take(8);
        $y = 362;
        foreach ($origenes as $origen) {
            $contenido .= $this->texto(48, $y, 8, '• '.$this->cortar($origen, 95));
            $y -= 18;
        }

        $contenido .= $this->texto(42, 194, 9, 'OBSERVACIÓN', true);
        $contenido .= $this->texto(42, 176, 8, $this->cortar($snapshot['observacion'] ?: 'Sin observaciones.', 105));
        $contenido .= $this->texto(42, 147, 8, 'Preparó: '.($snapshot['creado_por'] ?: 'No informado'));
        $contenido .= $this->texto(300, 147, 8, 'Confirmó: '.($snapshot['confirmado_por'] ?: 'Pendiente'));
        $contenido .= "0.65 0.70 0.72 RG 42 106 m 240 106 l S 355 106 m 553 106 l S\n";
        $contenido .= $this->texto(88, 89, 8, 'Entrega conforme');
        $contenido .= $this->texto(410, 89, 8, 'Recepción conforme');
        if ($guia->documento_hash) {
            $contenido .= $this->texto(42, 54, 7, 'Integridad: '.$guia->documento_hash);
        }
        $contenido .= $this->texto(
            42,
            39,
            7,
            $reservaActiva
                ? 'BORRADOR: reserva existencia, pero no afecta la cuenta corriente ni acredita salida física.'
                : ($esBorrador
                    ? 'DOCUMENTO CANCELADO: la reserva fue liberada sin afectar existencia ni cuenta corriente.'
                    : ($esHistoricoReconstruido
                        ? 'RESPALDO HISTÓRICO: salida confirmada antes del versionado documental; datos reconstruidos desde el registro conservado.'
                        : 'Documento operacional interno generado desde un registro confirmado e inmutable de FoliOS.')),
        );

        return $this->documento($contenido);
    }

    public function generarComprobanteAnulacion(GuiaDespachoEnvase $guia): string
    {
        if ($guia->estado !== EstadoGuiaDespachoEnvase::Anulada) {
            throw new ConflictoOperacion('El comprobante de anulación solo existe para una guía anulada.');
        }
        $guia->loadMissing(['cliente', 'anuladoPor']);

        $contenido = "0.30 0.08 0.10 rg 0 770 595 72 re f\n";
        $contenido .= $this->texto(38, 810, 20, 'FoliOS', true, '1 1 1');
        $contenido .= $this->texto(38, 785, 11, 'COMPROBANTE DE ANULACIÓN Y REVERSA', false, '1 0.82 0.82');
        $contenido .= $this->texto(414, 807, 12, $guia->numero, true, '1 1 1');
        $contenido .= $this->texto(42, 716, 24, 'GUÍA ANULADA', true, '0.72 0.10 0.14');
        $contenido .= $this->texto(42, 665, 10, 'Cliente', true);
        $contenido .= $this->texto(190, 665, 10, ($guia->cliente_codigo_snapshot ?: $guia->cliente->codigo).' · '.($guia->cliente_nombre_snapshot ?: $guia->cliente->nombre));
        $contenido .= $this->texto(42, 630, 10, 'Salida original', true);
        $contenido .= $this->texto(190, 630, 10, $this->fecha($guia->salida_at?->toAtomString()));
        $contenido .= $this->texto(42, 595, 10, 'Anulada el', true);
        $contenido .= $this->texto(190, 595, 10, $this->fecha($guia->anulado_at?->toAtomString()));
        $contenido .= $this->texto(42, 560, 10, 'Autorizó', true);
        $contenido .= $this->texto(190, 560, 10, $guia->anuladoPor?->name ?: 'No informado');
        $contenido .= $this->texto(42, 512, 10, 'Motivo', true);
        $contenido .= $this->texto(42, 486, 10, $this->cortar($guia->motivo_anulacion ?: 'Sin motivo informado.', 105));
        $contenido .= '0.96 0.91 0.92 rg 38 385 519 70 re f'."\n";
        $contenido .= $this->texto(52, 424, 11, 'La existencia y la cuenta corriente fueron restituidas mediante', true, '0.45 0.08 0.10');
        $contenido .= $this->texto(52, 402, 11, 'movimientos compensatorios. El documento original no fue eliminado.', true, '0.45 0.08 0.10');
        $contenido .= $this->texto(42, 70, 7, 'Documento operacional interno · no tributario.');

        return $this->documento($contenido);
    }

    /** @return array<string, mixed> */
    private function snapshotBorrador(GuiaDespachoEnvase $guia): array
    {
        return [
            'numero' => $guia->numero,
            'temporada' => [
                'codigo' => $guia->temporada_codigo_snapshot ?: $guia->temporada->codigo,
                'nombre' => $guia->temporada_nombre_snapshot ?: $guia->temporada->nombre,
            ],
            'cliente' => [
                'codigo' => $guia->cliente_codigo_snapshot ?: $guia->cliente->codigo,
                'nombre' => $guia->cliente_nombre_snapshot ?: $guia->cliente->nombre,
            ],
            'salida_at' => $guia->salida_at?->toAtomString(),
            'confirmado_at' => null,
            'patente_camion' => $guia->patente_camion,
            'conductor' => [
                'rut' => $guia->rut_conductor,
                'nombre' => $guia->nombre_conductor,
            ],
            'observacion' => $guia->observacion,
            'detalles' => $guia->detalles->map(fn ($detalle): array => [
                'tipo_envase' => $detalle->tipo_envase->value,
                'cantidad' => $detalle->cantidad,
                'propiedad' => $detalle->propiedad->value,
                'origen' => $detalle->origen_snapshot,
            ])->values()->all(),
            'creado_por' => $guia->creadoPor?->name,
            'confirmado_por' => null,
        ];
    }

    private function propiedad(string $propiedad): string
    {
        return match ($propiedad) {
            'propia' => 'Propia',
            'arrendada' => 'Arrendada',
            'cliente' => 'Del cliente',
            default => ucfirst($propiedad),
        };
    }

    private function fecha(?string $fecha): string
    {
        // Las fechas se guardan en UTC; el documento muestra la hora de planta.
        return $fecha
            ? CarbonImmutable::parse($fecha)->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i')
            : 'Pendiente';
    }

    private function cortar(string $texto, int $maximo): string
    {
        return mb_strlen($texto) > $maximo
            ? mb_substr($texto, 0, $maximo - 1).'…'
            : $texto;
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
