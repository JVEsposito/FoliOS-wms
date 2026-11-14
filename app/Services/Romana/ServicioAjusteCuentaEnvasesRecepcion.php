<?php

namespace App\Services\Romana;

use App\Enums\ConceptoEnvasesRomana;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * Ajuste de administración que corrige solo la cuenta corriente de envases de una recepción validada.
 *
 * No toca guías, lo declarado, Validación MP, lotes, destare ni kilos, por eso sirve aunque los lotes ya
 * tengan entregas a proceso. El administrador indica lo recibido y lo entregado que son correctos por
 * envase; el servicio deja un ajuste de cuenta (sin efecto en la existencia física) por envase y lado,
 * fechado junto a la recepción para que el estado de cuenta lo muestre en su lugar.
 *
 * Cada envase y lado tiene a lo más un ajuste vigente, contra lo registrado: al ajustar de nuevo, el
 * anterior se reversa y se reemplaza. Así el estado de cuenta muestra una sola diferencia con su motivo.
 */
class ServicioAjusteCuentaEnvasesRecepcion
{
    public const DOCUMENTO = 'ajuste_cuenta_recepcion';

    private const RECIBIDOS = [TipoMovimientoEnvase::RecepcionFruta, TipoMovimientoEnvase::RecepcionArriendo,
        TipoMovimientoEnvase::RecepcionCompra, TipoMovimientoEnvase::DevolucionEnvases];

    private const ENTREGADOS = [TipoMovimientoEnvase::SalidaRecepcionFruta, TipoMovimientoEnvase::ReversionSalidaRecepcionFruta];

    /** Movimientos que corrigen otro de la recepción y cuentan en su mismo lado. */
    private const VINCULADOS = [TipoMovimientoEnvase::CorreccionCantidad, TipoMovimientoEnvase::CorreccionPropiedad,
        TipoMovimientoEnvase::CorreccionCuenta, TipoMovimientoEnvase::AnulacionRecepcion, TipoMovimientoEnvase::ReversionAjusteCuenta];

    /** Lo que Oficina muestra antes de ajustar. */
    public function estado(RecepcionRomana $recepcion): array
    {
        $recepcion->loadMissing(['detallesEnvases', 'salidasEnvases']);
        $movimientos = $this->movimientos($recepcion);
        $cuenta = $this->cuenta($movimientos);
        $vigentes = $this->vigentes($movimientos);
        $porLado = $this->porLado($vigentes);
        $cerrada = $recepcion->estado === EstadoRecepcionRomana::Cerrado;
        $conciliada = $this->conciliada($movimientos);
        $tipos = collect([
            ...$recepcion->detallesEnvases->map(fn ($d): string => $d->tipo_envase->value),
            ...$recepcion->salidasEnvases->map(fn ($s): string => $s->tipo_envase->value),
            ...array_keys($cuenta['recibidos']), ...array_keys($cuenta['entregados']),
        ])->unique()->sortBy(fn (string $tipo): int => TipoEnvaseRomana::from($tipo)->orden())->values();
        $productores = $this->productores($recepcion);

        return [
            'disponible' => $this->impedimento($recepcion) === null && $this->mueveCuenta($recepcion),
            'motivo_no_disponible' => $this->impedimento($recepcion)
                ?? ($this->mueveCuenta($recepcion) ? null : 'La compra de envases propios no mueve la cuenta del cliente: no hay saldo que ajustar.'),
            'cerrada' => $cerrada,
            'entregados_editables' => $cerrada && ! $conciliada,
            'salida_conciliada' => $conciliada,
            'productores' => $productores,
            'exige_productor' => count($productores) > 1,
            'tipos' => $tipos->map(function (string $tipo) use ($recepcion, $cuenta, $porLado): array {
                $detalle = $recepcion->detallesEnvases->first(fn ($d): bool => $d->tipo_envase->value === $tipo);
                $salida = $recepcion->salidasEnvases->first(fn ($s): bool => $s->tipo_envase->value === $tipo);
                $recibidos = $cuenta['recibidos'][$tipo] ?? 0;
                $entregados = $cuenta['entregados'][$tipo] ?? 0;

                return [
                    'tipo_envase' => $tipo, 'nombre' => TipoEnvaseRomana::from($tipo)->etiqueta(),
                    'declarados' => (int) ($detalle?->cantidad_declarada ?? 0), 'validados' => (int) ($detalle?->cantidad_validada ?? 0),
                    'salida_destare' => (int) ($salida?->cantidad ?? 0),
                    // Lo que muestra la cuenta hoy y lo que mostraría sin ajustes de administración.
                    'recibidos_cuenta' => $recibidos, 'entregados_cuenta' => $entregados,
                    'recibidos_registro' => $recibidos - ($porLado['recibidos'][$tipo] ?? 0),
                    'entregados_registro' => $entregados - ($porLado['entregados'][$tipo] ?? 0),
                ];
            })->all(),
            'ajustes' => $this->historial($movimientos),
        ];
    }

    public function ajustar(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        Gate::forUser($usuario)->authorize('corregir-recepciones-validadas');
        $payload = $this->normalizar($datos);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->with(['detallesEnvases', 'salidasEnvases'])->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->payload_hash !== $hash || $evento->tipo !== TipoEventoRomana::AjusteCuentaEnvases) {
                    throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
                }

                return $recepcion;
            }
            if ($impedimento = $this->impedimento($recepcion, bloquear: true)) {
                throw new ConflictoOperacion($impedimento);
            }
            if (! $this->mueveCuenta($recepcion)) {
                throw new ConflictoOperacion('La compra de envases propios no mueve la cuenta del cliente: no hay saldo que ajustar.');
            }
            if ($recepcion->version !== $payload['version']) {
                throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de ajustar la cuenta.');
            }
            $movimientos = $this->movimientos($recepcion, bloquear: true);
            $cuenta = $this->cuenta($movimientos);
            $vigentes = $this->vigentes($movimientos);
            $porLado = $this->porLado($vigentes);
            $cerrada = $recepcion->estado === EstadoRecepcionRomana::Cerrado;
            $conciliada = $this->conciliada($movimientos);

            $cambios = [];
            foreach ($payload['envases'] as $i => $envase) {
                if (! TipoEnvaseRomana::tryFrom($envase['tipo_envase'])) {
                    throw ValidationException::withMessages(["envases.{$i}.tipo_envase" => "El tipo de envase {$envase['tipo_envase']} no existe."]);
                }
                foreach (['recibidos', 'entregados'] as $lado) {
                    $objetivo = $envase[$lado];
                    $actual = $cuenta[$lado][$envase['tipo_envase']] ?? 0;
                    if ($objetivo === null || $objetivo === $actual) {
                        continue;
                    }
                    $nombre = TipoEnvaseRomana::from($envase['tipo_envase'])->etiqueta();
                    if ($lado === 'entregados' && ! $cerrada) {
                        throw ValidationException::withMessages(["envases.{$i}.entregados" => 'La recepción aún no tiene destare: solo se ajustan los recibidos.']);
                    }
                    if ($lado === 'entregados' && $conciliada) {
                        throw new ConflictoOperacion("La salida de esta recepción está conciliada con un despacho: corrige los entregados de {$nombre} en ese despacho.");
                    }
                    $cambios[] = ['tipo' => $envase['tipo_envase'], 'nombre' => $nombre, 'lado' => $lado, 'actual' => $actual, 'correcto' => $objetivo,
                        'registro' => $actual - ($porLado[$lado][$envase['tipo_envase']] ?? 0)];
                }
            }
            if ($cambios === []) {
                throw new ConflictoOperacion('El ajuste no presenta cambios respecto de la cuenta.');
            }
            $productores = $this->productores($recepcion);
            $productor = $this->productorRecibidos($productores, $payload['productor_csg'], collect($cambios)->contains('lado', 'recibidos'));

            $ahora = CarbonImmutable::now();
            $contexto = ['operacion_id' => $datos['operacion_id'], 'motivo' => $payload['motivo'], 'usuario_id' => $usuario->id,
                'usuario' => $usuario->name, 'registrado_at' => $ahora->toAtomString()];
            $registrados = [];
            foreach ($cambios as $cambio) {
                // Un solo ajuste vigente por envase y lado: el anterior se reversa en su misma fecha.
                foreach ($vigentes->where('lado', $cambio['lado'])->where('tipo', $cambio['tipo']) as $vigente) {
                    $this->reversar($vigente['movimiento'], $vigente['neto'], $usuario, $contexto, $hash);
                }
                $diferencia = $cambio['correcto'] - $cambio['registro'];
                // Los entregados siguen a la salida del destare: el CSG elegido en Romana o el único.
                $movimiento = $diferencia === 0 ? null : $this->registrar($recepcion, $movimientos, $cambio, $diferencia,
                    $cambio['lado'] === 'recibidos' ? $productor : $this->productorSalida($recepcion), $usuario, $contexto, $hash);
                $registrados[] = ['tipo_envase' => $cambio['tipo'], 'lado' => $cambio['lado'], 'registro' => $cambio['registro'],
                    'anterior' => $cambio['actual'], 'correcto' => $cambio['correcto'], 'diferencia' => $diferencia, 'movimiento_id' => $movimiento?->id];
            }
            $recepcion->update(['version' => $recepcion->version + 1]);
            EventoRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'recepcion_romana_id' => $recepcion->id,
                'tipo' => TipoEventoRomana::AjusteCuentaEnvases, 'estado_anterior' => $recepcion->estado, 'estado_nuevo' => $recepcion->estado,
                'user_id' => $usuario->id, 'ocurrido_at' => $ahora,
                'datos' => ['motivo' => $payload['motivo'], 'resumen' => $this->texto($cambios), 'productor' => $productor, 'ajustes' => $registrados],
            ]);

            return $recepcion->refresh();
        }, attempts: 3);
    }

    /**
     * Ajustes vigentes de la recepción por lado y envase (en unidades de ese lado). La corrección completa
     * los usa para no corregir dos veces lo mismo.
     *
     * @return array{recibidos: array<string, int>, entregados: array<string, int>}
     */
    public function ajustesVigentes(RecepcionRomana $recepcion): array
    {
        return $this->porLado($this->vigentes($this->movimientos($recepcion)));
    }

    /** Si la corrección completa cambia un envase ya ajustado solo en cuenta, pide reversar primero ese ajuste. */
    public function asegurarSinDobleCorreccion(RecepcionRomana $recepcion, array $antes, array $despues): void
    {
        $vigentes = $this->ajustesVigentes($recepcion);
        $porTipo = fn (array $lista, string $campo): array => collect($lista)->mapWithKeys(fn (array $e): array => [$e['tipo_envase'] => (int) $e[$campo]])->all();
        $cambios = [
            'recibidos' => [$porTipo($antes['envases'], 'cantidad_validada'), $porTipo($despues['envases'], 'cantidad_validada')],
            'entregados' => [$porTipo($antes['destare']['salida_envases'] ?? [], 'cantidad'), $porTipo($despues['destare']['salida_envases'] ?? [], 'cantidad')],
        ];
        foreach ($cambios as $lado => [$previo, $nuevo]) {
            foreach ($vigentes[$lado] as $tipo => $ajuste) {
                if ($ajuste !== 0 && ($previo[$tipo] ?? 0) !== ($nuevo[$tipo] ?? 0)) {
                    $nombre = TipoEnvaseRomana::from($tipo)->etiqueta();
                    throw new ConflictoOperacion("{$nombre} tiene un ajuste de cuenta de administración en los {$lado} de esta recepción ("
                        .($ajuste > 0 ? '+' : '').$ajuste.'). Para no corregir dos veces, vuelve a dejar la cuenta como lo registrado en «Solo cuenta de envases» antes de cambiar ese conteo.');
                }
            }
        }
    }

    /** Correcciones de cantidad o propiedad desde Cuenta Envases: no sobre una recepción con ajuste vigente. */
    public function asegurarSinAjustes(RecepcionRomana $recepcion, ?string $tipo = null): void
    {
        $vigentes = $this->ajustesVigentes($recepcion);
        foreach ($vigentes as $porTipo) {
            foreach ($porTipo as $tipoAjustado => $ajuste) {
                if ($ajuste !== 0 && ($tipo === null || $tipo === $tipoAjustado)) {
                    throw new ConflictoOperacion('La recepción tiene un ajuste de cuenta de administración en '.TipoEnvaseRomana::from($tipoAjustado)->etiqueta()
                        .'. Corrígela desde Romana, en «Corrección de recepción validada» → «Solo cuenta de envases».');
                }
            }
        }
    }

    /** Línea para las observaciones del RPR-01: lo ajustado solo en la cuenta, con su motivo. */
    public function resumen(RecepcionRomana $recepcion): ?string
    {
        $movimientos = $this->movimientos($recepcion);
        $vigentes = $this->vigentes($movimientos);
        if ($vigentes->isEmpty()) {
            return null;
        }
        $zona = (string) config('app.operational_timezone');

        return $vigentes->groupBy(fn (array $v): string => $v['movimiento']->datos['ajuste_recepcion']['operacion_id'] ?? $v['movimiento']->id)
            ->map(function (Collection $grupo) use ($zona): string {
                $datos = $grupo->first()['movimiento']->datos['ajuste_recepcion'];
                $fecha = CarbonImmutable::parse($datos['registrado_at'])->setTimezone($zona)->format('d-m-Y');
                $partes = $grupo->sortBy(fn (array $v): int => TipoEnvaseRomana::from($v['tipo'])->orden())
                    ->map(fn (array $v): string => TipoEnvaseRomana::from($v['tipo'])->etiqueta().' '.$v['lado'].' '
                        .$v['movimiento']->datos['ajuste_recepcion']['registro'].' → '.$v['movimiento']->datos['ajuste_recepcion']['correcto'])->implode('; ');

                return "Cuenta de envases ajustada por administración ({$fecha}): {$partes}. Motivo: {$datos['motivo']}";
            })->implode("\n");
    }

    private function normalizar(array $datos): array
    {
        return [
            'version' => (int) $datos['version_conocida'],
            'motivo' => trim((string) $datos['motivo']),
            'productor_csg' => filled($datos['productor_csg'] ?? null) ? trim((string) $datos['productor_csg']) : null,
            'envases' => collect($datos['envases'])->map(fn ($e): array => ['tipo_envase' => (string) $e['tipo_envase'],
                'recibidos' => isset($e['recibidos']) ? (int) $e['recibidos'] : null,
                'entregados' => isset($e['entregados']) ? (int) $e['entregados'] : null])
                ->sortBy('tipo_envase')->values()->all(),
        ];
    }

    private function impedimento(RecepcionRomana $recepcion, bool $bloquear = false): ?string
    {
        return match (true) {
            $recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->eliminada_at !== null => 'La recepción está anulada o eliminada.',
            $recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada => 'La recepción aún no está validada: todavía no mueve la cuenta de envases.',
            $recepcion->temporada_id !== app(ServicioTemporadaActiva::class)->buscar(bloquear: $bloquear)?->id => 'Solo se ajustan recepciones de la temporada activa.',
            default => null,
        };
    }

    private function mueveCuenta(RecepcionRomana $recepcion): bool
    {
        return ! ($recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases && $recepcion->concepto_envases === ConceptoEnvasesRomana::Compra);
    }

    /** Movimientos de la recepción y los que los corrigen, aunque no lleven la recepción. */
    private function movimientos(RecepcionRomana $recepcion, bool $bloquear = false): Collection
    {
        $movimientos = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->orderBy('ocurrido_at')->orderBy('id')->get()->keyBy('id');
        for ($nivel = 0; $nivel < 3; $nivel++) {
            $vinculados = MovimientoEnvase::query()->whereIn('movimiento_origen_id', $movimientos->keys())
                ->whereNotIn('id', $movimientos->keys())
                ->whereIn('tipo_movimiento', array_map(fn ($t) => $t->value, self::VINCULADOS))
                ->when($bloquear, fn ($q) => $q->lockForUpdate())->get();
            if ($vinculados->isEmpty()) {
                break;
            }
            $movimientos = $movimientos->union($vinculados->keyBy('id'));
        }

        return $movimientos;
    }

    private function lado(MovimientoEnvase $movimiento, Collection $porId, int $profundidad = 0): ?string
    {
        if (! empty($movimiento->datos['ajuste_recepcion']['lado']) && $movimiento->tipo_movimiento === TipoMovimientoEnvase::AjusteCuenta) {
            return $movimiento->datos['ajuste_recepcion']['lado'];
        }
        if (in_array($movimiento->tipo_movimiento, self::RECIBIDOS, true)) {
            return 'recibidos';
        }
        if (in_array($movimiento->tipo_movimiento, self::ENTREGADOS, true)) {
            return 'entregados';
        }
        // La conciliación cuenta en los entregados solo si compensa la salida de esta recepción.
        if ($movimiento->tipo_movimiento === TipoMovimientoEnvase::ConciliacionSalida) {
            return $porId->has($movimiento->datos['movimiento_conciliado_id'] ?? '') ? 'entregados' : null;
        }
        if ($profundidad < 4 && in_array($movimiento->tipo_movimiento, self::VINCULADOS, true) && $porId->has($movimiento->movimiento_origen_id ?? '')) {
            return $this->lado($porId[$movimiento->movimiento_origen_id], $porId, $profundidad + 1);
        }

        return null;
    }

    /** @return array{recibidos: array<string, int>, entregados: array<string, int>} en unidades de cada lado */
    private function cuenta(Collection $movimientos): array
    {
        $cuenta = ['recibidos' => [], 'entregados' => []];
        foreach ($movimientos as $movimiento) {
            $lado = $this->lado($movimiento, $movimientos);
            if ($lado === null) {
                continue;
            }
            $impacto = $movimiento->cantidad * $movimiento->signo_cuenta;
            $tipo = $movimiento->tipo_envase->value;
            $cuenta[$lado][$tipo] = ($cuenta[$lado][$tipo] ?? 0) + ($lado === 'recibidos' ? $impacto : -$impacto);
        }

        return $cuenta;
    }

    /** @return Collection<int, array{movimiento: MovimientoEnvase, neto: int, lado: string, tipo: string}> ajustes no reversados */
    private function vigentes(Collection $movimientos): Collection
    {
        return $movimientos->filter(fn (MovimientoEnvase $m): bool => $m->tipo_movimiento === TipoMovimientoEnvase::AjusteCuenta
            && ! empty($m->datos['ajuste_recepcion']['lado']))
            ->map(fn (MovimientoEnvase $ajuste): array => [
                'movimiento' => $ajuste, 'lado' => $ajuste->datos['ajuste_recepcion']['lado'], 'tipo' => $ajuste->tipo_envase->value,
                'neto' => $ajuste->cantidad * $ajuste->signo_cuenta + $movimientos->where('movimiento_origen_id', $ajuste->id)
                    ->sum(fn (MovimientoEnvase $m): int => $m->cantidad * $m->signo_cuenta),
            ])->filter(fn (array $v): bool => $v['neto'] !== 0)->values();
    }

    /** @return array{recibidos: array<string, int>, entregados: array<string, int>} */
    private function porLado(Collection $vigentes): array
    {
        $porLado = ['recibidos' => [], 'entregados' => []];
        foreach ($vigentes as $vigente) {
            $unidades = $vigente['lado'] === 'recibidos' ? $vigente['neto'] : -$vigente['neto'];
            $porLado[$vigente['lado']][$vigente['tipo']] = ($porLado[$vigente['lado']][$vigente['tipo']] ?? 0) + $unidades;
        }

        return $porLado;
    }

    private function conciliada(Collection $movimientos): bool
    {
        return $movimientos->contains(fn (MovimientoEnvase $m): bool => $m->tipo_movimiento === TipoMovimientoEnvase::ConciliacionSalida);
    }

    /** @return list<array{codigo: string, nombre: ?string}> productores a los que se atribuyen los envases de la recepción */
    private function productores(RecepcionRomana $recepcion): array
    {
        if ($recepcion->esDevolucionEnvases()) {
            $productor = $recepcion->datosDevolucion()['productor'] ?? null;

            return empty($productor['codigo']) ? [] : [['codigo' => (string) $productor['codigo'], 'nombre' => $productor['nombre'] ?? null]];
        }

        return array_map(fn (array $p): array => ['codigo' => $p['codigo'], 'nombre' => $p['nombre']],
            app(ProductoresRecepcion::class)->opciones($recepcion));
    }

    private function productorSalida(RecepcionRomana $recepcion): ?array
    {
        $salida = app(ProductoresRecepcion::class)->salida($recepcion);

        return $salida === null ? null : ['codigo' => $salida['codigo'], 'nombre' => $salida['nombre'] ?? null];
    }

    private function productorRecibidos(array $productores, ?string $codigo, bool $cambiaRecibidos): ?array
    {
        if (count($productores) <= 1) {
            return $productores[0] ?? null;
        }
        $elegido = collect($productores)->firstWhere('codigo', $codigo);
        if ($cambiaRecibidos && $elegido === null) {
            throw ValidationException::withMessages(['productor_csg' => 'La recepción tiene varios CSG: indica a cuál productor corresponde la diferencia de recibidos.']);
        }

        return $elegido;
    }

    private function registrar(RecepcionRomana $recepcion, Collection $movimientos, array $cambio, int $diferencia, ?array $productor, User $usuario, array $contexto, string $hash): MovimientoEnvase
    {
        $originales = $movimientos->filter(fn (MovimientoEnvase $m): bool => in_array($m->tipo_movimiento,
            $cambio['lado'] === 'recibidos' ? self::RECIBIDOS : [TipoMovimientoEnvase::SalidaRecepcionFruta], true));
        $mismoTipo = $originales->filter(fn (MovimientoEnvase $m): bool => $m->tipo_envase->value === $cambio['tipo']);
        // La propiedad del envase en esa recepción; un envase nuevo toma la de la recepción.
        $propiedad = $mismoTipo->sortByDesc(fn (MovimientoEnvase $m): int => $m->cantidad)->first()?->propiedad
            ?? $recepcion->propiedadEnvase($cambio['tipo']);
        // Junto a lo registrado: un segundo después del ingreso o de la salida, en un solo documento por lado.
        $base = $originales->max('ocurrido_at')
            ?? ($cambio['lado'] === 'recibidos' ? $recepcion->ingreso_at : $recepcion->salida_at) ?? $contexto['registrado_at'];
        $fecha = ($base instanceof DateTimeInterface ? CarbonImmutable::instance($base) : CarbonImmutable::parse($base))->addSecond();
        $signo = ($diferencia > 0 ? 1 : -1) * ($cambio['lado'] === 'recibidos' ? 1 : -1);

        return MovimientoEnvase::create([
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "ajuste-cuenta-recepcion:{$contexto['operacion_id']}:{$cambio['tipo']}:{$cambio['lado']}")->toString(),
            'temporada_id' => $recepcion->temporada_id, 'cliente_id' => $recepcion->cliente_id, 'recepcion_romana_id' => $recepcion->id,
            'documento_tipo' => self::DOCUMENTO, 'documento_id' => $recepcion->id,
            'numero_documento' => $cambio['lado'] === 'recibidos' ? $recepcion->numero_guia_despacho : $recepcion->numero_guia_salida,
            'tipo_movimiento' => TipoMovimientoEnvase::AjusteCuenta, 'tipo_envase' => $cambio['tipo'], 'propiedad' => $propiedad,
            'cantidad' => abs($diferencia), 'signo_cuenta' => $signo, 'signo_existencia' => 0, 'ocurrido_at' => $fecha,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $usuario->id,
            'datos' => [
                'ajuste_cuenta' => ['motivo' => $contexto['motivo'], 'registrado_at' => $contexto['registrado_at'], 'payload_hash' => $hash],
                'ajuste_recepcion' => [...$contexto, 'lado' => $cambio['lado'], 'numero_recepcion' => $recepcion->numero_recepcion,
                    'registro' => $cambio['registro'], 'correcto' => $cambio['correcto'], 'diferencia' => $diferencia, 'productor' => $productor],
            ],
        ]);
    }

    private function reversar(MovimientoEnvase $ajuste, int $neto, User $usuario, array $contexto, string $hash): void
    {
        MovimientoEnvase::create([
            'operacion_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "reversa-ajuste-recepcion:{$contexto['operacion_id']}:{$ajuste->id}")->toString(),
            'temporada_id' => $ajuste->temporada_id, 'cliente_id' => $ajuste->cliente_id, 'recepcion_romana_id' => $ajuste->recepcion_romana_id,
            'documento_tipo' => self::DOCUMENTO, 'documento_id' => $ajuste->documento_id, 'numero_documento' => $ajuste->numero_documento,
            'tipo_movimiento' => TipoMovimientoEnvase::ReversionAjusteCuenta, 'tipo_envase' => $ajuste->tipo_envase, 'propiedad' => $ajuste->propiedad,
            'cantidad' => abs($neto), 'signo_cuenta' => $neto > 0 ? -1 : 1, 'signo_existencia' => 0,
            // En la misma fecha del ajuste que reemplaza: los saldos de períodos anteriores quedan con el ajuste nuevo.
            'movimiento_origen_id' => $ajuste->id, 'ocurrido_at' => $ajuste->ocurrido_at,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $usuario->id,
            'datos' => ['ajuste_cuenta' => ['motivo' => 'Reemplazado por un nuevo ajuste de la recepción: '.$contexto['motivo'],
                'registrado_at' => $contexto['registrado_at'], 'payload_hash' => $hash],
                'reemplaza_ajuste_recepcion' => ['operacion_id' => $contexto['operacion_id'], 'usuario' => $contexto['usuario']]],
        ]);
    }

    /** @return list<array<string, mixed>> ajustes de la recepción, del más reciente al más antiguo */
    private function historial(Collection $movimientos): array
    {
        $vigentes = $this->vigentes($movimientos)->keyBy(fn (array $v): string => $v['movimiento']->id);

        return $movimientos->filter(fn (MovimientoEnvase $m): bool => $m->tipo_movimiento === TipoMovimientoEnvase::AjusteCuenta
            && ! empty($m->datos['ajuste_recepcion']['lado']))
            ->sortByDesc(fn (MovimientoEnvase $m): string => $m->datos['ajuste_recepcion']['registrado_at'] ?? '')
            ->map(fn (MovimientoEnvase $m): array => [
                'id' => $m->id, 'tipo_envase' => $m->tipo_envase->value, 'nombre' => $m->tipo_envase->etiqueta(),
                'lado' => $m->datos['ajuste_recepcion']['lado'], 'registro' => $m->datos['ajuste_recepcion']['registro'],
                'correcto' => $m->datos['ajuste_recepcion']['correcto'], 'diferencia' => $m->datos['ajuste_recepcion']['diferencia'],
                'motivo' => $m->datos['ajuste_recepcion']['motivo'], 'usuario' => $m->datos['ajuste_recepcion']['usuario'] ?? null,
                'registrado_at' => $m->datos['ajuste_recepcion']['registrado_at'] ?? null,
                'productor' => $m->datos['ajuste_recepcion']['productor'] ?? null, 'vigente' => $vigentes->has($m->id),
            ])->values()->all();
    }

    private function texto(array $cambios): string
    {
        return collect($cambios)->map(fn (array $c): string => "{$c['nombre']} {$c['lado']} {$c['actual']} → {$c['correcto']}")->implode('; ');
    }
}
