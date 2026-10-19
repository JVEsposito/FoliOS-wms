<?php

namespace App\Services\Porteria;

use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\IngresoPorteria;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioIngresosPorteria
{
    public function registrar(array $datos, User $usuario): IngresoPorteria
    {
        $operacion = $datos['operacion_id'];
        $payload = $this->campos($datos);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($operacion, $payload, $hash, $usuario): IngresoPorteria {
                User::query()->whereKey($usuario->id)->lockForUpdate()->firstOrFail();
                if ($existente = IngresoPorteria::query()->where('operacion_id', $operacion)->first()) {
                    return $this->reintento($existente, $hash, $usuario);
                }
                $cliente = $this->cliente($payload);
                $ingreso = IngresoPorteria::query()->create([
                    ...$payload, ...$cliente,
                    'operacion_id' => $operacion, 'payload_hash' => $hash,
                    'ingreso_at' => now(), 'creado_por_user_id' => $usuario->id, 'creado_por_nombre' => $usuario->name,
                ]);
                $this->evento($ingreso, 'registrado', ['registro' => $this->presentar($ingreso)], $usuario);

                return $ingreso;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $error) {
            $existente = IngresoPorteria::query()->where('operacion_id', $operacion)->first();
            if (! $existente) {
                throw $error;
            }

            return $this->reintento($existente, $hash, $usuario);
        }
    }

    private function reintento(IngresoPorteria $ingreso, string $hash, User $usuario): IngresoPorteria
    {
        if ((int) $ingreso->creado_por_user_id !== (int) $usuario->id || ! hash_equals($ingreso->payload_hash, $hash)) {
            throw new ConflictoOperacion('Este intento de registro ya fue utilizado con otros datos. Revise el ingreso antes de crear otro.');
        }

        return $ingreso;
    }

    public function corregir(IngresoPorteria $ingreso, array $datos, User $usuario): IngresoPorteria
    {
        return DB::transaction(function () use ($ingreso, $datos, $usuario): IngresoPorteria {
            $ingreso = IngresoPorteria::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->exigirVersion($ingreso, $datos['version']);
            $antes = $this->presentar($ingreso);
            $payload = $this->campos($datos);
            $cliente = $this->cliente($payload);
            $ingreso->fill([...$payload, ...$cliente, 'version' => $ingreso->version + 1])->save();
            $this->evento($ingreso, 'corregido', ['antes' => $antes, 'despues' => $this->presentar($ingreso), 'motivo' => $datos['motivo_correccion']], $usuario);

            return $ingreso;
        }, attempts: 3);
    }

    public function anular(IngresoPorteria $ingreso, int $version, string $motivo, User $usuario): IngresoPorteria
    {
        return DB::transaction(function () use ($ingreso, $version, $motivo, $usuario): IngresoPorteria {
            $ingreso = IngresoPorteria::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->exigirVersion($ingreso, $version);
            $ingreso->forceFill(['anulado_at' => now(), 'motivo_anulacion' => $motivo, 'version' => $ingreso->version + 1])->save();
            $this->evento($ingreso, 'anulado', ['motivo' => $motivo], $usuario);

            return $ingreso;
        }, attempts: 3);
    }

    private function exigirVersion(IngresoPorteria $ingreso, int $version): void
    {
        if ($ingreso->anulado_at !== null || $ingreso->version !== $version) {
            throw new ConflictoOperacion('El ingreso cambió o está anulado. Actualice la lista antes de continuar.');
        }
    }

    private function campos(array $datos): array
    {
        return array_intersect_key([...array_fill_keys(['cliente_id', 'cliente_otro', 'patente_carro', 'cantidad', 'unidad', 'observacion'], null), ...$datos], array_flip([
            'cliente_id', 'cliente_otro', 'motivo', 'patente', 'patente_carro', 'tipo_camion', 'nombre_conductor', 'guias', 'cantidad', 'unidad', 'observacion',
        ]));
    }

    private function cliente(array &$payload): array
    {
        $otro = $payload['cliente_otro'];
        unset($payload['cliente_otro']);
        $cliente = $payload['cliente_id'] ? Cliente::query()->whereKey($payload['cliente_id'])->firstOrFail() : null;

        return ['cliente_nombre' => $cliente?->nombre ?? $otro, 'cliente_es_otro' => $cliente === null];
    }

    public function evento(IngresoPorteria $ingreso, string $accion, array $datos, User $usuario): void
    {
        $ingreso->eventos()->create(['accion' => $accion, 'datos' => $datos, 'user_id' => $usuario->id, 'user_nombre' => $usuario->name, 'created_at' => now()]);
    }

    public function presentar(IngresoPorteria $ingreso): array
    {
        return [
            'id' => $ingreso->id, 'codigo' => $ingreso->codigo(), 'cliente_id' => $ingreso->cliente_id,
            'cliente_nombre' => $ingreso->cliente_nombre, 'cliente_es_otro' => $ingreso->cliente_es_otro,
            'motivo' => $ingreso->motivo, 'patente' => $ingreso->patente, 'patente_carro' => $ingreso->patente_carro,
            'tipo_camion' => $ingreso->tipo_camion, 'nombre_conductor' => $ingreso->nombre_conductor,
            'guias' => $ingreso->guias, 'cantidad' => $ingreso->cantidad, 'unidad' => $ingreso->unidad,
            'observacion' => $ingreso->observacion, 'ingreso_at' => $ingreso->ingreso_at->toAtomString(),
            'creado_por_nombre' => $ingreso->creado_por_nombre,
            'revisado_at' => $ingreso->revisado_at?->toAtomString(), 'revisado_por_nombre' => $ingreso->revisado_por_nombre,
            'anulado_at' => $ingreso->anulado_at?->toAtomString(), 'motivo_anulacion' => $ingreso->motivo_anulacion,
            'version' => $ingreso->version,
        ];
    }
}
