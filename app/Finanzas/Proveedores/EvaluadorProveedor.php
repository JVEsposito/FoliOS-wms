<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\Models\EvaluacionProveedor;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EvaluadorProveedor
{
    public function __construct(private readonly RegistroCompra $registro) {}

    public function crear(array $d, User $usuario): EvaluacionProveedor
    {
        $peso = 0;
        $puntos = 0;
        foreach ($d['criterios'] as &$c) {
            $c['peso'] = (int) $c['peso'];
            $c['nota'] = (int) $c['nota'];
            $peso += $c['peso'];
            $puntos += $c['peso'] * $c['nota'];
        }
        unset($c);
        if ($peso !== 100) {
            throw ValidationException::withMessages(['criterios' => 'Los pesos de los criterios deben sumar 100%.']);
        }
        $hash = $this->registro->hash($d);

        return $this->registro->exclusivo('evaluaciones', function () use ($d, $hash, $puntos, $usuario): EvaluacionProveedor {
            if ($previa = EvaluacionProveedor::query()->where('operacion_id', $d['operacion_id'])->first()) {
                if (! hash_equals($previa->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['operacion_id' => 'Ese identificador ya se usó con otra evaluación.']);
                }

                return $previa;
            }
            ProveedorFinanciero::query()->findOrFail($d['proveedor_id']);
            $e = EvaluacionProveedor::query()->create(['id' => (string) Str::uuid(), ...$d, 'payload_hash' => $hash, 'resultado' => (string) BigDecimal::of($puntos)->dividedBy(100, 2), 'user_id' => $usuario->id, 'user_nombre' => $usuario->name]);
            $this->registro->evento('evaluacion', $e->id, 'evaluado', ['resultado' => $e->resultado, 'conclusion' => $e->conclusion], $usuario);

            return $e;
        });
    }

    public function anular(EvaluacionProveedor $e, string $motivo, User $usuario): EvaluacionProveedor
    {
        return $this->registro->exclusivo('evaluaciones', function () use ($e, $motivo, $usuario): EvaluacionProveedor {
            $e = EvaluacionProveedor::query()->lockForUpdate()->findOrFail($e->id);
            if (! $e->anulado_at) {
                $e->update(['anulado_at' => now(), 'motivo_anulacion' => $motivo]);
                $this->registro->evento('evaluacion', $e->id, 'anulada', ['motivo' => $motivo], $usuario);
            }

            return $e;
        });
    }
}
