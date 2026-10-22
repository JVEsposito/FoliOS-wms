<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\Captura\CapturaEnCurso;
use App\Finanzas\Captura\Fuentes\CargosDeclarados;
use App\Finanzas\Captura\ServicioCapturaHechos;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\EventoCargo;
use App\Finanzas\Models\Lectura;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestorCargos
{
    public function __construct(private readonly EsquemaFinanzas $esquema, private readonly CatalogoServicios $servicios, private readonly ClientesOperacion $clientes, private readonly ServicioCapturaHechos $captura) {}

    public function crear(array $datos, User $usuario): CargoAcordado
    {
        $servicio = $this->servicios->buscar($datos['servicio']);
        if (($servicio['fuente'] ?? null) !== CargosDeclarados::CLAVE) {
            throw ValidationException::withMessages(['servicio' => 'Cree o elija un concepto en modalidad acordada/manual. Así su cantidad no se vuelve a cobrar por captura automática.']);
        }
        if ($datos['fecha'] < $servicio['vigente_desde'] || ($servicio['vigente_hasta'] !== null && $datos['fecha'] > $servicio['vigente_hasta'])) {
            throw ValidationException::withMessages(['fecha' => 'La fecha del cargo debe estar dentro de la vigencia del concepto.']);
        }
        $cliente = $this->clientes->buscar($datos['cliente_id']);
        if ($cliente === null) {
            throw ValidationException::withMessages(['cliente_id' => 'El cliente no existe.']);
        }
        if ($datos['base_cobro'] === 'ejecucion' && $datos['estado_ejecucion'] !== 'realizado') {
            throw ValidationException::withMessages(['base_cobro' => 'Para cobrar sin ejecución, indique acuerdo, compromiso o disponibilidad como base del cobro.']);
        }
        $datos['cantidad'] = Precio::normalizar($datos['cantidad']);
        if ($datos['cantidad'] === '0') {
            throw ValidationException::withMessages(['cantidad' => 'La cantidad debe ser distinta de cero; un valor negativo registra un abono.']);
        }
        ksort($datos);
        $hash = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
        $candado = 'cargo-'.$datos['operacion_id'];
        if (! $this->esquema->tomarCandado($candado)) {
            throw ValidationException::withMessages(['cargo' => 'Este cargo se está guardando. Reintente con los mismos datos.']);
        }
        try {
            return $this->esquema->transaccion(function () use ($datos, $hash, $cliente, $usuario): CargoAcordado {
                // Un mismo identificador HTTP o de RRHH no crea dos cargos al reintentar.
                $existente = CargoAcordado::query()->where('operacion_id', $datos['operacion_id'])->lockForUpdate()->first();
                if ($existente !== null) {
                    if (! hash_equals($existente->payload_hash, $hash)) {
                        throw ValidationException::withMessages(['operacion_id' => 'Ese identificador ya se usó con otros datos.']);
                    }

                    return $existente;
                }
                $cargo = CargoAcordado::query()->create(['id' => (string) Str::uuid(), ...$datos, 'payload_hash' => $hash, 'cliente_nombre' => $cliente['nombre']]);
                $this->evento($cargo, 'creado', $datos, $usuario);

                return $cargo;
            });
        } finally {
            $this->esquema->liberarCandado($candado);
        }
    }

    public function aprobar(CargoAcordado $cargo, User $usuario): CargoAcordado
    {
        return $this->esquema->transaccion(function () use ($cargo, $usuario): CargoAcordado {
            $cargo = CargoAcordado::query()->lockForUpdate()->findOrFail($cargo->id);
            if ($cargo->anulado_at !== null) {
                throw ValidationException::withMessages(['cargo' => 'El cargo está anulado.']);
            }
            if ($cargo->aprobado_at === null) {
                $this->servicios->recargar();
                $concepto = $this->servicios->buscar($cargo->servicio);
                $fecha = $cargo->fecha->toDateString();
                if ($fecha < $concepto['vigente_desde'] || ($concepto['vigente_hasta'] !== null && $fecha > $concepto['vigente_hasta'])) {
                    throw ValidationException::withMessages(['cargo' => 'El concepto ya no cubre la fecha del borrador. Cree otro cargo con el concepto vigente.']);
                }
                $cargo->update(['aprobado_at' => now()]);
                $this->evento($cargo, 'aprobado', [], $usuario);
                $this->capturar($usuario);
            }

            return $cargo;
        });
    }

    public function anular(CargoAcordado $cargo, string $motivo, User $usuario): CargoAcordado
    {
        return $this->esquema->transaccion(function () use ($cargo, $motivo, $usuario): CargoAcordado {
            $cargo = CargoAcordado::query()->lockForUpdate()->findOrFail($cargo->id);
            if ($cargo->anulado_at !== null) {
                return $cargo;
            }
            $cargo->update(['anulado_at' => now(), 'motivo_anulacion' => $motivo]);
            $this->evento($cargo, 'anulado', ['motivo' => $motivo], $usuario);
            if ($cargo->aprobado_at !== null) {
                $this->capturar($usuario);
            }

            return $cargo;
        });
    }

    private function capturar(User $usuario): void
    {
        try {
            $lectura = $this->captura->capturar(Lectura::ORIGEN_MANUAL, $usuario, [CargosDeclarados::CLAVE]);
        } catch (CapturaEnCurso) {
            throw ValidationException::withMessages(['cargo' => 'Hay una captura en curso. El cambio no se guardó; reintente en unos segundos.']);
        }
        if ($lectura->estado !== Lectura::ESTADO_COMPLETADA) {
            throw ValidationException::withMessages(['cargo' => 'No se pudo registrar el hecho financiero. El cambio no se guardó; intente nuevamente.']);
        }
    }

    private function evento(CargoAcordado $cargo, string $accion, array $datos, User $usuario): void
    {
        EventoCargo::query()->create(['cargo_id' => $cargo->id, 'accion' => $accion, 'datos' => $datos, 'user_id' => $usuario->id, 'user_nombre' => $usuario->name, 'created_at' => now()]);
    }
}
