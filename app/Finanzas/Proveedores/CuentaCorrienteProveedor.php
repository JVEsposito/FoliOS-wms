<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\Models\AplicacionProveedor;
use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\PagoProveedor;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CuentaCorrienteProveedor
{
    public function __construct(private readonly RegistroCompra $registro, private readonly PlanCuentasCompra $plan, private readonly ContabilidadCompra $contabilidad) {}

    public function pagar(array $d, User $usuario): PagoProveedor
    {
        $d['monto'] = (string) BigDecimal::of($d['monto']);
        $d['referencia'] = mb_strtoupper(trim($d['referencia']));
        if (mb_strlen($d['referencia']) > 180) {
            throw ValidationException::withMessages(['referencia' => 'La referencia normalizada supera 180 caracteres.']);
        }
        $d['aplicaciones'] ??= [];
        foreach ($d['aplicaciones'] as &$a) {
            $a['monto'] = (string) BigDecimal::of($a['monto']);
        }
        unset($a);
        $hash = $this->registro->hash($d);

        return $this->registro->exclusivo('pagos', function () use ($d, $hash, $usuario): PagoProveedor {
            if ($previo = PagoProveedor::query()->where('operacion_id', $d['operacion_id'])->first()) {
                if (! hash_equals($previo->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['operacion_id' => 'Ese identificador de pago ya se usó con otros datos.']);
                }

                return $previo;
            }
            $p = ProveedorFinanciero::query()->lockForUpdate()->findOrFail($d['proveedor_id']);
            if ($p->pagos_bloqueados) {
                throw ValidationException::withMessages(['proveedor_id' => 'Este proveedor tiene los pagos bloqueados. Revise su ficha antes de registrar un nuevo desembolso.']);
            }
            $clave = hash('sha256', $d['cuenta'].'|'.$d['referencia']);
            if (PagoProveedor::query()->where('referencia_activa', $clave)->exists()) {
                throw ValidationException::withMessages(['referencia' => 'Ya hay un pago vigente con esa referencia en la cuenta. Revíselo antes de volver a ingresarlo.']);
            }
            $ultima = PagoProveedor::query()->where('cuenta', $d['cuenta'])->where('referencia', $d['referencia'])->max('fecha_anulacion');
            if ($ultima && $d['fecha'] < $ultima) {
                throw ValidationException::withMessages(['fecha' => 'La corrección del pago no puede preceder a su reversión anterior.']);
            }
            $banco = $this->plan->buscar($d['cuenta'], ['tesoreria']);
            $anticipo = $this->plan->buscar(config('finanzas-compras.anticipos'), ['activo']);
            $pago = PagoProveedor::query()->create(['id' => (string) Str::uuid(), ...collect($d)->except(['aplicaciones', 'confirmado'])->all(), 'payload_hash' => $hash, 'proveedor_snapshot' => ['rut' => $p->rut, 'razon_social' => $p->razon_social], 'referencia_activa' => $clave]);
            $this->contabilidad->registrar($p->id, 'pago', $pago->id, $d['fecha'], 'Pago registrado: '.$d['referencia'], [
                $this->linea($anticipo, $d['monto'], '0', 'Anticipo / pago por aplicar'),
                $this->linea($banco, '0', $d['monto'], 'Desembolso informado'),
            ], $usuario);
            foreach ($d['aplicaciones'] as $a) {
                $this->aplicarInterno(['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $p->id, 'credito_tipo' => 'pago', 'credito_id' => $pago->id, 'documento_id' => $a['documento_id'], 'fecha' => $d['fecha'], 'monto' => $a['monto'], 'respaldo' => 'Aplicación al registrar pago '.$d['referencia']], $usuario);
            }
            $this->registro->evento('pago', $pago->id, 'registrado', ['monto' => $d['monto'], 'referencia' => $d['referencia']], $usuario);

            return $pago;
        });
    }

    public function aplicar(array $datos, User $usuario): AplicacionProveedor
    {
        return $this->registro->exclusivo('proveedor-'.$datos['proveedor_id'], function () use ($datos, $usuario): AplicacionProveedor {
            ProveedorFinanciero::query()->lockForUpdate()->findOrFail($datos['proveedor_id']);

            return $this->aplicarInterno($datos, $usuario);
        });
    }

    private function aplicarInterno(array $d, User $usuario): AplicacionProveedor
    {
        $d['monto'] = (string) BigDecimal::of($d['monto']);
        $hash = $this->registro->hash($d);
        if ($previa = AplicacionProveedor::query()->where('operacion_id', $d['operacion_id'])->first()) {
            if (! hash_equals($previa->payload_hash, $hash)) {
                throw ValidationException::withMessages(['operacion_id' => 'Ese identificador de aplicación ya se usó con otros datos.']);
            }

            return $previa;
        }
        $factura = DocumentoCompra::query()->lockForUpdate()->findOrFail($d['documento_id']);
        $credito = $d['credito_tipo'] === 'pago' ? PagoProveedor::query()->lockForUpdate()->findOrFail($d['credito_id']) : DocumentoCompra::query()->lockForUpdate()->findOrFail($d['credito_id']);
        if ($factura->proveedor_id !== $d['proveedor_id'] || $credito->proveedor_id !== $d['proveedor_id'] || ! in_array($factura->tipo, ['33', '34', '56', 'gasto'], true) || ! $factura->registrado_at || $factura->anulado_at || $credito->anulado_at) {
            throw ValidationException::withMessages(['documento_id' => 'Seleccione deuda y crédito vigentes del mismo proveedor.']);
        }
        if ($d['credito_tipo'] === 'nota_credito' && ($credito->tipo !== '61' || ! $credito->registrado_at || $credito->referencia_id !== $factura->id)) {
            throw ValidationException::withMessages(['credito_id' => 'La nota de crédito debe corresponder a la factura que ajusta.']);
        }
        $fechaCredito = $d['credito_tipo'] === 'pago' ? $credito->fecha : $credito->fecha_contable;
        if ($d['fecha'] < $factura->fecha_contable->toDateString() || $d['fecha'] < $fechaCredito->toDateString()) {
            throw ValidationException::withMessages(['fecha' => 'La aplicación no puede preceder a la deuda ni al crédito.']);
        }
        $usado = AplicacionProveedor::query()->whereNull('anulado_at')->where('credito_tipo', $d['credito_tipo'])->where('credito_id', $credito->id)->sum('monto');
        $abonado = AplicacionProveedor::query()->whereNull('anulado_at')->where('documento_id', $factura->id)->sum('monto');
        $totalCredito = $d['credito_tipo'] === 'pago' ? $credito->monto : $credito->total;
        if (BigDecimal::of($d['monto'])->plus($usado)->isGreaterThan($totalCredito) || BigDecimal::of($d['monto'])->plus($abonado)->isGreaterThan($factura->total)) {
            throw ValidationException::withMessages(['monto' => 'La aplicación supera el crédito disponible o el saldo del documento.']);
        }
        $ultima = AplicacionProveedor::query()->where(fn ($q) => $q->where('documento_id', $factura->id)->orWhere(fn ($q) => $q->where('credito_tipo', $d['credito_tipo'])->where('credito_id', $credito->id)))->max('fecha_anulacion');
        if ($ultima && $d['fecha'] < $ultima) {
            throw ValidationException::withMessages(['fecha' => 'La fecha debe ser posterior o igual a la última desaplicación de la deuda o crédito.']);
        }
        $this->contabilidad->periodoAbierto($d['fecha']);
        $aplicacion = AplicacionProveedor::query()->create(['id' => (string) Str::uuid(), ...$d, 'payload_hash' => $hash]);
        if ($d['credito_tipo'] === 'pago') {
            $pasivo = $this->plan->buscar(config('finanzas-compras.pasivo'), ['pasivo']);
            $anticipo = $this->plan->buscar(config('finanzas-compras.anticipos'), ['activo']);
            $this->contabilidad->registrar($d['proveedor_id'], 'aplicacion', $aplicacion->id, $d['fecha'], 'Aplicación de pago a documento '.$factura->folio, [$this->linea($pasivo, $d['monto'], '0', 'Pago aplicado'), $this->linea($anticipo, '0', $d['monto'], 'Anticipo aplicado')], $usuario);
        }
        $this->registro->evento('aplicacion', $aplicacion->id, 'aplicada', $d, $usuario);

        return $aplicacion;
    }

    public function desaplicar(AplicacionProveedor $a, string $fecha, string $motivo, User $u): AplicacionProveedor
    {
        return $this->registro->exclusivo('proveedor-'.$a->proveedor_id, function () use ($a, $fecha, $motivo, $u): AplicacionProveedor {
            ProveedorFinanciero::query()->lockForUpdate()->findOrFail($a->proveedor_id);
            $a = AplicacionProveedor::query()->lockForUpdate()->findOrFail($a->id);
            if ($a->anulado_at) {
                return $a;
            }
            if ($fecha < $a->fecha->toDateString()) {
                throw ValidationException::withMessages(['fecha' => 'El reverso no puede preceder a la aplicación.']);
            }
            $this->contabilidad->periodoAbierto($fecha);
            if ($a->credito_tipo === 'pago') {
                $this->contabilidad->reversar(AsientoCompra::query()->where('origen_tipo', 'aplicacion')->where('origen_id', $a->id)->firstOrFail(), $fecha, $motivo, $u);
            }
            $a->update(['anulado_at' => now(), 'fecha_anulacion' => $fecha, 'motivo_anulacion' => $motivo]);
            $this->registro->evento('aplicacion', $a->id, 'desaplicada', ['fecha' => $fecha, 'motivo' => $motivo], $u);

            return $a;
        });
    }

    public function anularPago(PagoProveedor $pago, string $fecha, string $motivo, User $u): PagoProveedor
    {
        return $this->registro->exclusivo('proveedor-'.$pago->proveedor_id, function () use ($pago, $fecha, $motivo, $u): PagoProveedor {
            ProveedorFinanciero::query()->lockForUpdate()->findOrFail($pago->proveedor_id);
            $pago = PagoProveedor::query()->lockForUpdate()->findOrFail($pago->id);
            if ($pago->anulado_at) {
                return $pago;
            }
            if (AplicacionProveedor::query()->where('credito_tipo', 'pago')->where('credito_id', $pago->id)->whereNull('anulado_at')->exists()) {
                throw ValidationException::withMessages(['pago' => 'Desaplique los abonos vigentes antes de revertir el pago.']);
            }
            $ultima = AplicacionProveedor::query()->where('credito_tipo', 'pago')->where('credito_id', $pago->id)->max('fecha_anulacion');
            if ($ultima && $fecha < $ultima) {
                throw ValidationException::withMessages(['fecha' => 'La reversión debe ser posterior o igual a sus desaplicaciones.']);
            }
            $this->contabilidad->reversar(AsientoCompra::query()->where('origen_tipo', 'pago')->where('origen_id', $pago->id)->firstOrFail(), $fecha, $motivo, $u);
            $pago->update(['anulado_at' => now(), 'fecha_anulacion' => $fecha, 'motivo_anulacion' => $motivo, 'referencia_activa' => null]);
            $this->registro->evento('pago', $pago->id, 'anulado', ['fecha' => $fecha, 'motivo' => $motivo], $u);

            return $pago;
        });
    }

    private function linea($cuenta, string $debe, string $haber, string $texto): array
    {
        return ['cuenta' => $cuenta->codigo, 'cuenta_nombre' => $cuenta->nombre, 'cuenta_tipo' => $cuenta->tipo, 'descripcion' => $texto, 'ceco' => null, 'debe' => $debe, 'haber' => $haber];
    }
}
