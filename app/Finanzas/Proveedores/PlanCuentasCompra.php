<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\Models\CuentaCompra;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PlanCuentasCompra
{
    public function __construct(private readonly RegistroCompra $registro) {}

    public function inicializar(User $usuario): void
    {
        $this->registro->exclusivo('plan', function () use ($usuario): void {
            foreach (config('finanzas-compras.cuentas_iniciales') as $cuenta) {
                CuentaCompra::query()->firstOrCreate(['codigo' => $cuenta['codigo']], $cuenta);
            }
            $this->registro->evento('plan', 'compras', 'inicializado', [], $usuario);
        });
    }

    public function guardar(array $datos, User $usuario): CuentaCompra
    {
        return $this->registro->exclusivo('plan', function () use ($datos, $usuario): CuentaCompra {
            $cuenta = CuentaCompra::query()->lockForUpdate()->find($datos['codigo']);
            if ($cuenta !== null && $cuenta->tipo !== $datos['tipo']) {
                throw ValidationException::withMessages(['tipo' => 'El tipo de una cuenta existente no se puede cambiar.']);
            }
            foreach (['iva', 'pasivo', 'anticipos'] as $rol) {
                if ($datos['codigo'] === config('finanzas-compras.'.$rol) && ($datos['tipo'] !== ($rol === 'anticipos' ? 'activo' : $rol) || ! $datos['activa'])) {
                    throw ValidationException::withMessages(['codigo' => 'La cuenta de control debe conservar su tipo y permanecer activa.']);
                }
            }
            $cuenta = CuentaCompra::query()->updateOrCreate(['codigo' => $datos['codigo']], $datos);
            $this->registro->evento('cuenta', $cuenta->codigo, 'configurada', $datos, $usuario);

            return $cuenta;
        });
    }

    public function buscar(string $codigo, array $tipos): CuentaCompra
    {
        $cuenta = CuentaCompra::query()->find($codigo);
        if ($cuenta === null || ! $cuenta->activa || ! in_array($cuenta->tipo, $tipos, true)) {
            throw ValidationException::withMessages(['cuenta' => 'Seleccione una cuenta activa del tipo correcto. Inicialice o revise el plan de cuentas.']);
        }

        return $cuenta;
    }
}
