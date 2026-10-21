<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\Captura\LectorOperacion;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DirectorioProveedores
{
    public function __construct(private readonly LectorOperacion $lector, private readonly RegistroCompra $registro) {}

    public function materiales(string $buscar): array
    {
        return $this->lector->tabla('proveedores_materiales')->where('activo', true)
            ->when($buscar !== '', fn ($q) => $q->where(fn ($q) => $q->where('nombre', 'like', '%'.$buscar.'%')->orWhere('codigo', 'like', '%'.$buscar.'%')))
            ->orderBy('nombre')->limit(50)->get(['id', 'codigo', 'nombre'])->map(fn ($p) => (array) $p)->all();
    }

    public function guardar(array $datos, User $usuario, ?ProveedorFinanciero $proveedor = null): ProveedorFinanciero
    {
        $rut = strtoupper(preg_replace('/[.\s-]/', '', $datos['rut']));
        if ((int) substr($rut, 0, -1) < 1) {
            throw ValidationException::withMessages(['rut' => 'Ingrese un RUT válido del proveedor.']);
        }
        $rut = ltrim(substr($rut, 0, -1), '0').'-'.substr($rut, -1);

        return $this->registro->exclusivo('directorio', function () use ($datos, $rut, $usuario, $proveedor): ProveedorFinanciero {
            $actual = $proveedor ? ProveedorFinanciero::query()->lockForUpdate()->findOrFail($proveedor->id) : null;
            if ($actual && $actual->version !== $datos['version']) {
                throw ValidationException::withMessages(['version' => 'La ficha cambió. Vuelva a consultarla.']);
            }
            if (ProveedorFinanciero::query()->where('rut', $rut)->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->exists()) {
                throw ValidationException::withMessages(['rut' => 'Ese RUT ya tiene ficha financiera. Utilice la existente.']);
            }
            if ($actual && $actual->rut !== $rut) {
                throw ValidationException::withMessages(['rut' => 'El RUT identifica al proveedor y no se puede cambiar. Corrija los demás antecedentes o cree el proveedor correcto.']);
            }
            $material = $datos['proveedor_material_id'] ?? null;
            if ($actual && $actual->proveedor_material_id !== null && $actual->proveedor_material_id !== $material) {
                throw ValidationException::withMessages(['proveedor_material_id' => 'El vínculo con Materiales es permanente.']);
            }
            if ($material !== null) {
                if (! $this->lector->tabla('proveedores_materiales')->where('id', $material)->exists()) {
                    throw ValidationException::withMessages(['proveedor_material_id' => 'El proveedor no existe en Materiales.']);
                }
                if (ProveedorFinanciero::query()->where('proveedor_material_id', $material)->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->exists()) {
                    throw ValidationException::withMessages(['proveedor_material_id' => 'Ya existe una ficha financiera vinculada a ese proveedor de Materiales.']);
                }
            }
            $anterior = $actual?->toArray();
            $campos = collect($datos)->except(['version', 'motivo'])->all();
            $campos['rut'] = $rut;
            $campos['version'] = ($actual?->version ?? 0) + 1;
            if ($actual) {
                $actual->update($campos);
            } else {
                $actual = ProveedorFinanciero::query()->create(['id' => (string) Str::uuid(), ...$campos]);
            }
            $this->registro->evento('proveedor', $actual->id, $anterior ? 'actualizado' : 'creado', ['antes' => $anterior, 'despues' => $actual->toArray(), 'motivo' => $datos['motivo'] ?? null], $usuario);

            return $actual;
        });
    }
}
