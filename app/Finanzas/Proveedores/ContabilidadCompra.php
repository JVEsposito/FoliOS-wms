<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\LineaAsientoCompra;
use App\Finanzas\Models\PeriodoCompra;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Diario auxiliar de proveedores: asientos atómicos, balanceados e inmutables. */
final class ContabilidadCompra
{
    public function __construct(private readonly PlanCuentasCompra $plan) {}

    public function periodoAbierto(string $fecha): void
    {
        // El mismo candado de mes se comparte con el cierre; siempre dentro de la transacción.
        $mes = substr($fecha, 0, 7);
        PeriodoCompra::query()->insertOrIgnore(['mes' => $mes, 'created_at' => now(), 'updated_at' => now()]);
        if (PeriodoCompra::query()->lockForUpdate()->findOrFail($mes)->cerrado_at !== null) {
            throw ValidationException::withMessages(['fecha_contable' => 'Ese mes de compras está cerrado. Use un período abierto o solicite su reapertura.']);
        }
    }

    public function registrar(string $proveedor, string $tipo, string $origen, string $fecha, string $glosa, array $lineas, User $usuario, ?string $reversa = null): AsientoCompra
    {
        $this->periodoAbierto($fecha);
        $debe = BigDecimal::zero();
        $haber = BigDecimal::zero();
        $normalizadas = [];
        foreach ($lineas as $linea) {
            $d = BigDecimal::of($linea['debe']);
            $h = BigDecimal::of($linea['haber']);
            if ($d->isNegative() || $h->isNegative() || ($d->isPositive() === $h->isPositive())) {
                throw ValidationException::withMessages(['asiento' => 'Cada línea debe tener un monto positivo en debe o haber.']);
            }
            $cuenta = $reversa ? null : $this->plan->buscar($linea['cuenta'], [$linea['cuenta_tipo']]);
            $normalizadas[] = [...$linea, 'cuenta_nombre' => $cuenta?->nombre ?? $linea['cuenta_nombre']];
            $debe = $debe->plus($d);
            $haber = $haber->plus($h);
        }
        if (! $debe->isPositive() || ! $debe->isEqualTo($haber)) {
            throw ValidationException::withMessages(['asiento' => 'El asiento debe cuadrar en debe y haber.']);
        }
        $asiento = AsientoCompra::query()->create(['id' => (string) Str::uuid(), 'proveedor_id' => $proveedor, 'origen_tipo' => $tipo, 'origen_id' => $origen, 'fecha' => $fecha, 'glosa' => $glosa, 'reversa_de_id' => $reversa, 'user_id' => $usuario->id, 'user_nombre' => $usuario->name, 'created_at' => now()]);
        foreach ($normalizadas as $linea) {
            LineaAsientoCompra::query()->create(['asiento_id' => $asiento->id, ...$linea]);
        }

        return $asiento->load('lineas');
    }

    public function reversar(AsientoCompra $asiento, string $fecha, string $motivo, User $usuario): AsientoCompra
    {
        if ($fecha < $asiento->fecha->toDateString()) {
            throw ValidationException::withMessages(['fecha_contable' => 'El reverso no puede preceder al asiento original.']);
        }
        $lineas = $asiento->lineas()->get()->map(fn ($l) => ['cuenta' => $l->cuenta, 'cuenta_nombre' => $l->cuenta_nombre, 'cuenta_tipo' => $l->cuenta_tipo, 'descripcion' => $l->descripcion, 'ceco' => $l->ceco, 'debe' => $l->haber, 'haber' => $l->debe])->all();

        return $this->registrar($asiento->proveedor_id, 'reversion', $asiento->id, $fecha, $motivo, $lineas, $usuario, $asiento->id);
    }
}
