<?php

namespace App\Finanzas\Captura;

use Carbon\CarbonImmutable;

/** Cantidades del momento: los repaletizajes conservan el antes y el después del bulto. */
final class HistorialBultos
{
    private function __construct(private readonly array $cambios) {}

    public static function leer(ContextoCaptura $contexto, array $folioIds): self
    {
        $filas = $contexto->lector->tabla('repaletizaje_detalles', 'd')
            ->join('repaletizajes as r', 'r.id', '=', 'd.repaletizaje_id')
            ->whereIn('d.folio_origen_id', $folioIds)
            ->where('r.estado', 'confirmado')->whereNotNull('r.confirmado_at')
            ->orderBy('r.confirmado_at')->orderBy('r.codigo')->orderBy('r.id')->orderBy('d.orden')
            ->get(['d.folio_origen_id', 'd.cajas_antes', 'd.cajas_despues', 'd.tipo_bulto_antes', 'd.tipo_bulto_despues',
                'r.codigo', 'r.confirmado_at']);
        $cambios = [];
        foreach ($filas as $fila) {
            $cambios[$fila->folio_origen_id][] = [
                'hora' => ContextoCaptura::fecha($fila->confirmado_at),
                'antes' => self::medidas((int) $fila->cajas_antes, $fila->tipo_bulto_antes),
                'despues' => self::medidas((int) $fila->cajas_despues, $fila->tipo_bulto_despues ?? 'saldo'),
                'repaletizaje' => $fila->codigo,
            ];
        }

        return new self($cambios);
    }

    /** Un servicio terminado antes de repaletizar conserva las cantidades previas. */
    public function delServicio(FolioLeido $folio, CarbonImmutable $hora): array
    {
        return $this->en($folio, $hora, incluirCambioEnEseInstante: false);
    }

    /** Tramos [desde, hasta): el término de uno es el inicio del siguiente. */
    public function tramos(FolioLeido $folio, CarbonImmutable $desde, ?CarbonImmutable $hasta): array
    {
        $inicio = $desde;
        $medidas = $this->en($folio, $desde);
        $referencia = null;
        $tramos = [];
        $tramo = function (?CarbonImmutable $fin) use (&$inicio, &$medidas, &$referencia): array {
            return [
                'desde_at' => $inicio->toIso8601String(), 'hasta_at' => $fin?->toIso8601String(),
                'medidas' => $medidas, 'repaletizaje_inicio' => $referencia,
            ];
        };

        foreach ($this->cambios[$folio->id] ?? [] as $cambio) {
            if ($cambio['hora']->lte($desde)) {
                $referencia = $cambio['repaletizaje'];

                continue;
            }
            if ($hasta !== null && $cambio['hora']->gte($hasta)) {
                break;
            }
            if ($cambio['hora']->gt($inicio)) {
                $tramos[] = $tramo($cambio['hora']);
            }
            $inicio = $cambio['hora'];
            $medidas = $cambio['despues'];
            $referencia = $cambio['repaletizaje'];
        }
        if ($hasta === null || $hasta->gt($inicio)) {
            $tramos[] = $tramo($hasta);
        }

        return $tramos;
    }

    private function en(FolioLeido $folio, CarbonImmutable $hora, bool $incluirCambioEnEseInstante = true): array
    {
        foreach ($this->cambios[$folio->id] ?? [] as $cambio) {
            if ($cambio['hora']->gt($hora) || (! $incluirCambioEnEseInstante && $cambio['hora']->eq($hora))) {
                return $cambio['antes'];
            }
        }

        return $folio->medidas();
    }

    private static function medidas(int $cajas, string $tipo): array
    {
        return ['bultos' => 1, 'pallets' => $tipo === 'pallet' ? 1 : 0, 'saldos' => $tipo === 'saldo' ? 1 : 0, 'cajas' => $cajas];
    }
}
