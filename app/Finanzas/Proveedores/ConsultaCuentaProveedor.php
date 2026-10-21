<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\AplicacionProveedor;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\PagoProveedor;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ConsultaCuentaProveedor
{
    public function __construct(private readonly EsquemaFinanzas $esquema) {}

    public function consultar(string $fecha, ?string $proveedor): array
    {
        return $this->esquema->transaccion(function () use ($fecha, $proveedor): array {
            $aplicaciones = AplicacionProveedor::query()->where('fecha', '<=', $fecha)->where(fn ($q) => $q->whereNull('fecha_anulacion')->orWhere('fecha_anulacion', '>', $fecha))->when($proveedor, fn ($q) => $q->where('proveedor_id', $proveedor));
            $porDeuda = (clone $aplicaciones)->selectRaw('documento_id, SUM(monto) as aplicado_deuda')->groupBy('documento_id');
            $porNota = (clone $aplicaciones)->where('credito_tipo', 'nota_credito')->selectRaw('credito_id, SUM(monto) as aplicado_credito')->groupBy('credito_id');
            $porPago = (clone $aplicaciones)->where('credito_tipo', 'pago')->selectRaw('credito_id, SUM(monto) as aplicado_pago')->groupBy('credito_id');
            // Filtra saldos en SQL antes del límite: el historial ya saldado no consume el cupo.
            $documentos = DocumentoCompra::query()->from('fin_documentos_compra as d')->select('d.*')
                ->leftJoinSub($porDeuda, 'abonos', 'abonos.documento_id', '=', 'd.id')->leftJoinSub($porNota, 'notas', 'notas.credito_id', '=', 'd.id')
                ->selectRaw('COALESCE(abonos.aplicado_deuda, 0) as aplicado_deuda, COALESCE(notas.aplicado_credito, 0) as aplicado_credito')
                ->whereNotNull('d.registrado_at')->where('d.tipo', '!=', '52')->where('d.fecha_contable', '<=', $fecha)
                ->where(fn ($q) => $q->whereNull('d.fecha_anulacion')->orWhere('d.fecha_anulacion', '>', $fecha))
                ->when($proveedor, fn ($q) => $q->where('d.proveedor_id', $proveedor))
                ->whereRaw("((d.tipo = '61' AND d.total > COALESCE(notas.aplicado_credito, 0)) OR (d.tipo != '61' AND d.total > COALESCE(abonos.aplicado_deuda, 0)))")
                ->orderBy('d.vencimiento')->orderBy('d.id')->limit(5001)->get();
            $pagos = PagoProveedor::query()->from('fin_pagos_proveedor as p')->select('p.*')->leftJoinSub($porPago, 'ap', 'ap.credito_id', '=', 'p.id')->selectRaw('COALESCE(ap.aplicado_pago, 0) as aplicado_pago')
                ->where('p.fecha', '<=', $fecha)->where(fn ($q) => $q->whereNull('p.fecha_anulacion')->orWhere('p.fecha_anulacion', '>', $fecha))
                ->when($proveedor, fn ($q) => $q->where('p.proveedor_id', $proveedor))->whereRaw('p.monto > COALESCE(ap.aplicado_pago, 0)')->orderBy('p.fecha')->limit(5001)->get();
            if ($documentos->count() > 5000 || $pagos->count() > 5000) {
                throw ValidationException::withMessages(['proveedor_id' => 'La consulta supera 5.000 partidas pendientes. Acote por proveedor; no se muestra un saldo parcial.']);
            }
            $deudas = [];
            $creditos = [];
            $deuda = BigDecimal::zero();
            $notas = BigDecimal::zero();
            $anticipos = BigDecimal::zero();
            $tramos = array_fill_keys(['no_vencido', '1_30', '31_60', '61_90', 'mas_90'], '0');
            foreach ($documentos as $d) {
                $esCredito = $d->tipo === '61';
                $usado = $esCredito ? $d->aplicado_credito : $d->aplicado_deuda;
                $saldo = BigDecimal::of($d->total)->minus($usado);
                if ($saldo->isZero()) {
                    continue;
                }
                $item = ['id' => $d->id, 'proveedor_id' => $d->proveedor_id, 'proveedor_nombre' => $d->proveedor_snapshot['razon_social'], 'tipo' => $d->tipo, 'folio' => $d->folio, 'fecha' => $d->fecha_contable->toDateString(), 'vencimiento' => $d->vencimiento->toDateString(), 'total' => $d->total, 'aplicado' => (string) $usado, 'saldo' => (string) $saldo];
                if ($esCredito) {
                    $notas = $notas->plus($saldo);
                    $creditos[] = [...$item, 'credito_tipo' => 'nota_credito'];
                } else {
                    $dias = (int) CarbonImmutable::parse($d->vencimiento->toDateString())->diffInDays(CarbonImmutable::parse($fecha), false);
                    $tramo = match (true) {
                        $dias <= 0 => 'no_vencido', $dias <= 30 => '1_30', $dias <= 60 => '31_60', $dias <= 90 => '61_90', default => 'mas_90'
                    };
                    $tramos[$tramo] = (string) BigDecimal::of($tramos[$tramo])->plus($saldo);
                    $deuda = $deuda->plus($saldo);
                    $deudas[] = [...$item, 'dias_vencido' => max(0, $dias)];
                }
            }
            foreach ($pagos as $p) {
                $usado = $p->aplicado_pago;
                $saldo = BigDecimal::of($p->monto)->minus($usado);
                if ($saldo->isZero()) {
                    continue;
                }
                $anticipos = $anticipos->plus($saldo);
                $creditos[] = ['id' => $p->id, 'proveedor_id' => $p->proveedor_id, 'proveedor_nombre' => $p->proveedor_snapshot['razon_social'], 'credito_tipo' => 'pago', 'folio' => $p->referencia, 'fecha' => $p->fecha->toDateString(), 'total' => $p->monto, 'aplicado' => (string) $usado, 'saldo' => (string) $saldo];
            }

            return ['fecha_corte' => $fecha, 'moneda' => 'CLP', 'deudas' => $deudas, 'creditos' => $creditos, 'antiguedad' => $tramos,
                'totales' => ['deuda_documentos' => (string) $deuda, 'notas_por_aplicar' => (string) $notas, 'anticipos_disponibles' => (string) $anticipos, 'saldo_cuentas_por_pagar' => (string) $deuda->minus($notas), 'saldo_neto' => (string) $deuda->minus($notas)->minus($anticipos)]];
        });
    }
}
