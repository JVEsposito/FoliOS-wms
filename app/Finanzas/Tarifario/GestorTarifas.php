<?php

namespace App\Finanzas\Tarifario;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\EventoTarifa;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\Tarifa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Altas, cambios y anulaciones de precios, siempre con historial. Dos reglas protegen la
 * prefactura: los precios de un mismo servicio y unidad no se superponen, y un servicio no se
 * cobra a la vez por bulto y por pallet o saldo (un bulto es un pallet o un saldo).
 */
final class GestorTarifas
{
    private const SIN_TERMINO = '9999-12-31';

    public function __construct(
        private readonly EsquemaFinanzas $esquema,
        private readonly CatalogoServicios $servicios,
        private readonly ClientesOperacion $clientes,
    ) {}

    /**
     * @param  array{cliente_id?: ?string, servicio: string, unidad: string, moneda: string, precio: string, vigente_desde: string, vigente_hasta?: ?string, nota?: ?string, cerrar_anterior?: bool}  $datos
     */
    public function crear(array $datos, User $usuario): Tarifa
    {
        if (! $this->servicios->existe($datos['servicio'], $datos['unidad'])) {
            throw ValidationException::withMessages(['unidad' => 'Esa unidad de cobro no corresponde al servicio.']);
        }
        $this->exigirMonedaActiva($datos['moneda']);
        $clienteId = $datos['cliente_id'] ?? null;
        $cliente = $clienteId === null ? null : $this->clientes->buscar($clienteId);
        if ($clienteId !== null && $cliente === null) {
            throw ValidationException::withMessages(['cliente_id' => 'El cliente no existe en la operación.']);
        }
        $desde = $datos['vigente_desde'];
        $hasta = $datos['vigente_hasta'] ?? null;

        return $this->esquema->transaccion(function () use ($datos, $usuario, $clienteId, $cliente, $desde, $hasta): Tarifa {
            $vecinas = $this->vecinas($clienteId, $datos['servicio']);
            if ($datos['cerrar_anterior'] ?? false) {
                $this->cerrarAnteriores($vecinas, $datos['unidad'], $desde, $hasta, $usuario);
            }
            $this->exigirSinCruces($vecinas, $datos['unidad'], $desde, $hasta);

            $tarifa = Tarifa::query()->create([
                'cliente_id' => $clienteId,
                'cliente_nombre' => $cliente['nombre'] ?? null,
                'servicio' => $datos['servicio'],
                'unidad' => $datos['unidad'],
                'moneda' => $datos['moneda'],
                'precio' => $datos['precio'],
                'vigente_desde' => $desde,
                'vigente_hasta' => $hasta,
                'nota' => $datos['nota'] ?? null,
                'actualizada_por_user_id' => $usuario->id,
                'actualizada_por_nombre' => $usuario->name,
            ]);
            $this->registrar($tarifa, EventoTarifa::CREADA, $this->foto($tarifa), null, $usuario);

            return $tarifa;
        });
    }

    /**
     * El cliente, el servicio y la unidad no cambian: para eso se anula y se crea otro precio.
     *
     * @param  array{moneda: string, precio: string, vigente_desde: string, vigente_hasta?: ?string, nota?: ?string}  $datos
     */
    public function modificar(Tarifa $tarifa, array $datos, User $usuario): Tarifa
    {
        return $this->esquema->transaccion(function () use ($tarifa, $datos, $usuario): Tarifa {
            $tarifa = Tarifa::query()->lockForUpdate()->findOrFail($tarifa->id);
            if ($tarifa->anulada()) {
                throw ValidationException::withMessages(['tarifa' => 'Un precio anulado no se puede editar: cree uno nuevo.']);
            }
            if ($datos['moneda'] !== $tarifa->moneda) {
                $this->exigirMonedaActiva($datos['moneda']);
            }
            $desde = $datos['vigente_desde'];
            $hasta = $datos['vigente_hasta'] ?? null;
            $vecinas = $this->vecinas($tarifa->cliente_id, $tarifa->servicio)
                ->reject(fn (Tarifa $otra): bool => $otra->id === $tarifa->id);
            $this->exigirSinCruces($vecinas, $tarifa->unidad, $desde, $hasta);

            $antes = $this->foto($tarifa);
            $tarifa->fill([
                'moneda' => $datos['moneda'],
                'precio' => $datos['precio'],
                'vigente_desde' => $desde,
                'vigente_hasta' => $hasta,
                'nota' => $datos['nota'] ?? null,
            ]);
            $cambios = $this->diferencias($antes, $this->foto($tarifa));
            if ($cambios === []) {
                return $tarifa;
            }

            $tarifa->forceFill(['actualizada_por_user_id' => $usuario->id, 'actualizada_por_nombre' => $usuario->name])->save();
            $this->registrar($tarifa, EventoTarifa::MODIFICADA, $cambios, null, $usuario);

            return $tarifa;
        });
    }

    public function anular(Tarifa $tarifa, string $motivo, User $usuario): Tarifa
    {
        return $this->esquema->transaccion(function () use ($tarifa, $motivo, $usuario): Tarifa {
            $tarifa = Tarifa::query()->lockForUpdate()->findOrFail($tarifa->id);
            if ($tarifa->anulada()) {
                throw ValidationException::withMessages(['tarifa' => 'El precio ya estaba anulado.']);
            }

            $tarifa->forceFill([
                'anulada_at' => now(),
                'motivo_anulacion' => $motivo,
                'actualizada_por_user_id' => $usuario->id,
                'actualizada_por_nombre' => $usuario->name,
            ])->save();
            $this->registrar($tarifa, EventoTarifa::ANULADA, [], $motivo, $usuario);

            return $tarifa;
        });
    }

    /**
     * Precios activos del mismo cliente (o de la tarifa general) y servicio, bloqueados hasta
     * terminar: dos altas simultáneas no pueden dejar vigencias cruzadas.
     *
     * @return Collection<int, Tarifa>
     */
    private function vecinas(?string $clienteId, string $servicio): Collection
    {
        return Tarifa::query()
            ->where('servicio', $servicio)
            ->when(
                $clienteId === null,
                fn (Builder $consulta) => $consulta->whereNull('cliente_id'),
                fn (Builder $consulta) => $consulta->where('cliente_id', $clienteId),
            )
            ->whereNull('anulada_at')
            ->orderBy('vigente_desde')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Pone término el día anterior a los precios que regían al empezar el nuevo y que este
     * reemplaza: la misma unidad o una que cobraría dos veces (bulto frente a pallet o saldo).
     *
     * @param  Collection<int, Tarifa>  $vecinas
     */
    private function cerrarAnteriores(Collection $vecinas, string $unidad, string $desde, ?string $hasta, User $usuario): void
    {
        $reemplazables = [$unidad, ...$this->servicios->excluyentes($unidad)];
        $cierre = CarbonImmutable::parse($desde)->subDay()->toDateString();

        foreach ($vecinas as $anterior) {
            $inicio = $anterior->vigente_desde->toDateString();
            $termino = $anterior->vigente_hasta?->toDateString();
            $rigeAlEmpezar = $inicio < $desde && ($termino === null || $termino >= $desde);
            // Un precio nuevo con término no deja sin precio los días que el anterior cubría después.
            $loReemplaza = $hasta === null || ($termino !== null && $termino <= $hasta);
            if (! in_array($anterior->unidad, $reemplazables, true) || ! $rigeAlEmpezar || ! $loReemplaza) {
                continue;
            }

            $anterior->forceFill([
                'vigente_hasta' => $cierre,
                'actualizada_por_user_id' => $usuario->id,
                'actualizada_por_nombre' => $usuario->name,
            ])->save();
            $this->registrar(
                $anterior,
                EventoTarifa::CERRADA,
                ['vigente_hasta' => ['antes' => $termino, 'despues' => $cierre]],
                'Reemplazado por un precio nuevo desde el '.self::dia($desde).'.',
                $usuario,
            );
        }
    }

    /** @param  Collection<int, Tarifa>  $vecinas */
    private function exigirSinCruces(Collection $vecinas, string $unidad, string $desde, ?string $hasta): void
    {
        foreach ($vecinas as $otra) {
            $misma = $otra->unidad === $unidad;
            if (! $misma && ! in_array($otra->unidad, $this->servicios->excluyentes($unidad), true)) {
                continue;
            }

            $inicio = $otra->vigente_desde->toDateString();
            $termino = $otra->vigente_hasta?->toDateString();
            if ($inicio > ($hasta ?? self::SIN_TERMINO) || ($termino ?? self::SIN_TERMINO) < $desde) {
                continue;
            }

            $vigencia = 'vigente desde el '.self::dia($inicio).($termino === null ? ' sin término' : ' hasta el '.self::dia($termino));
            $nombre = $this->servicios->nombreUnidad($otra->servicio, $otra->unidad);

            throw ValidationException::withMessages(['vigente_desde' => $misma
                ? "Se cruza con otro precio {$nombre}, {$vigencia}. Ajuste las fechas o ponga término a ese precio."
                : "Se cruza con el precio {$nombre}, {$vigencia}: cobrar por bulto y, a la vez, por pallet o saldo cobraría dos veces el mismo bulto."]);
        }
    }

    private function exigirMonedaActiva(string $codigo): void
    {
        if (! Moneda::query()->whereKey($codigo)->where('activa', true)->exists()) {
            throw ValidationException::withMessages(['moneda' => 'La moneda no existe o está inactiva.']);
        }
    }

    /** @return array<string, ?string> */
    private function foto(Tarifa $tarifa): array
    {
        return [
            'cliente' => $tarifa->general() ? 'Tarifa general' : $tarifa->cliente_nombre,
            'servicio' => $this->servicios->nombreServicio($tarifa->servicio),
            'unidad' => $this->servicios->nombreUnidad($tarifa->servicio, $tarifa->unidad),
            'moneda' => $tarifa->moneda,
            'precio' => Precio::normalizar((string) $tarifa->precio),
            'vigente_desde' => $tarifa->vigente_desde->toDateString(),
            'vigente_hasta' => $tarifa->vigente_hasta?->toDateString(),
            'nota' => $tarifa->nota,
        ];
    }

    /**
     * @param  array<string, ?string>  $antes
     * @param  array<string, ?string>  $despues
     * @return array<string, array{antes: ?string, despues: ?string}>
     */
    private function diferencias(array $antes, array $despues): array
    {
        $cambios = [];
        foreach ($despues as $campo => $valor) {
            if (($antes[$campo] ?? null) !== $valor) {
                $cambios[$campo] = ['antes' => $antes[$campo] ?? null, 'despues' => $valor];
            }
        }

        return $cambios;
    }

    /** @param  array<string, mixed>  $cambios */
    private function registrar(Tarifa $tarifa, string $accion, array $cambios, ?string $motivo, User $usuario): void
    {
        EventoTarifa::query()->create([
            'tarifa_id' => $tarifa->id,
            'accion' => $accion,
            'cambios' => $cambios,
            'motivo' => $motivo,
            'user_id' => $usuario->id,
            'user_nombre' => $usuario->name,
            'created_at' => now(),
        ]);
    }

    private static function dia(string $fecha): string
    {
        return CarbonImmutable::parse($fecha)->format('d-m-Y');
    }
}
