<?php

namespace App\Services\MateriaPrima\OrdenesProceso;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoOrdenProcesoMp;
use App\Enums\OrigenVersionOrdenProcesoMp;
use App\Enums\TipoCierreOrdenProcesoMp;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TurnoProceso;
use App\Exceptions\ConflictoOperacion;
use App\Models\EntregaFrutaProceso;
use App\Models\LineaProceso;
use App\Models\LoteMateriaPrima;
use App\Models\LoteVersionOrdenMp;
use App\Models\OrdenProcesoMp;
use App\Models\ProductorCsg;
use App\Models\ReservaLoteOrdenProcesoMp;
use App\Models\User;
use App\Models\VersionOrdenProcesoMp;
use App\Services\Secuencias\ServicioSecuenciaDocumento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Crea, versiona y cancela órdenes de proceso de materia prima.
 *
 * Orden de bloqueo, igual en toda operación que toque órdenes: primero la orden, después
 * los lotes ordenados por id, después sus reservas. Las reservas y las entregas de un lote
 * solo se escriben con su lote bloqueado.
 *
 * Antes de bloquear los lotes solo hay lecturas con bloqueo: la primera lectura simple abre
 * el snapshot REPEATABLE READ, y si ocurre después de bloquear los lotes ve todas las
 * entregas confirmadas de esos lotes sin bloquear filas de entregas. Así esta clase no
 * compite con Retornos de Packing, que bloquea entregas antes que lotes.
 */
class ServicioOrdenProcesoMp
{
    /** Fracción exportable con que la PRE-SIF estima cajas desde los kilos (versión V2). */
    public const FRACCION_EXPORTABLE_CAJAS = 0.9;

    private const ESTADOS_LOTE_PROGRAMABLES = [
        EstadoLoteMateriaPrima::DisponibleProceso,
        EstadoLoteMateriaPrima::AsignadoCamara,
        EstadoLoteMateriaPrima::EntregaParcialProceso,
    ];

    public function __construct(
        private readonly CalculadoraHorariosOrdenProceso $horarios,
        private readonly ServicioSecuenciaDocumento $secuencias,
        private readonly ServicioTemporadaActiva $temporadas,
    ) {}

    /** @param array<string, mixed> $datos */
    public function crear(array $datos, User $usuario): OrdenProcesoMp
    {
        $hash = $this->hash($datos);

        try {
            return DB::transaction(function () use ($datos, $usuario, $hash): OrdenProcesoMp {
                $existente = OrdenProcesoMp::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existente) {
                    return $this->resolverRepetida($existente->payload_hash, $hash, $existente);
                }

                $temporada = $this->temporadas->buscarConBloqueoCompartido();
                if (! $temporada) {
                    throw new ConflictoOperacion('No hay una temporada activa para programar proceso.');
                }
                $linea = LineaProceso::query()->lockForUpdate()->findOrFail($datos['linea_proceso_id']);
                if (! $linea->activa) {
                    throw ValidationException::withMessages([
                        'linea_proceso_id' => "La {$linea->nombre} está inactiva.",
                    ]);
                }

                $correlativo = $this->secuencias->reservarSiguiente('ordenes_proceso_mp');
                $orden = OrdenProcesoMp::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $hash,
                    'temporada_id' => $temporada->id,
                    'correlativo' => $correlativo,
                    'codigo' => sprintf('OP-%06d', $correlativo),
                    'linea_proceso_id' => $linea->id,
                    'fecha' => $datos['fecha'],
                    'turno_inicio' => TurnoProceso::desdeCodigo($datos['turno_inicio']),
                    'estado' => EstadoOrdenProcesoMp::Programada,
                    'numero_version_vigente' => 1,
                    'version' => 1,
                    'creado_por_user_id' => $usuario->id,
                ]);

                $this->publicar($orden, $linea, $datos, $usuario, 1, OrigenVersionOrdenProcesoMp::Digitador, $datos['operacion_id'], $hash);
                $creada = $this->cargar($orden);
                $creada->wasRecentlyCreated = true;

                return $creada;
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $existente = OrdenProcesoMp::query()->where('operacion_id', $datos['operacion_id'])->first();
            if (! $existente) {
                throw $excepcion;
            }

            return $this->resolverRepetida($existente->payload_hash, $hash, $existente);
        }
    }

    /** @param array<string, mixed> $datos */
    public function publicarVersion(OrdenProcesoMp $orden, array $datos, User $usuario): OrdenProcesoMp
    {
        $hash = $this->hash($datos);

        try {
            return DB::transaction(function () use ($orden, $datos, $usuario, $hash): OrdenProcesoMp {
                $orden = OrdenProcesoMp::query()->lockForUpdate()->findOrFail($orden->id);
                $repetida = VersionOrdenProcesoMp::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();
                if ($repetida) {
                    if ($repetida->orden_proceso_mp_id !== $orden->id) {
                        throw new ConflictoOperacion('El identificador de versión ya fue usado en otra orden.');
                    }

                    return $this->resolverRepetida($repetida->payload_hash, $hash, $orden);
                }
                $this->exigirAbierta($orden);
                if ($orden->version !== (int) $datos['version']) {
                    throw new ConflictoOperacion(
                        "Otra persona publicó la v{$orden->numero_version_vigente} de {$orden->codigo}. Revisa la versión vigente antes de publicar.",
                    );
                }

                $linea = LineaProceso::query()->sharedLock()->findOrFail($orden->linea_proceso_id);
                $numero = $orden->numero_version_vigente + 1;
                $this->publicar($orden, $linea, $datos, $usuario, $numero, OrigenVersionOrdenProcesoMp::Digitador, $datos['operacion_id'], $hash);

                return $this->cargar($orden);
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $repetida = VersionOrdenProcesoMp::query()->where('operacion_id', $datos['operacion_id'])->first();
            if (! $repetida || $repetida->orden_proceso_mp_id !== $orden->id) {
                throw $excepcion;
            }

            return $this->resolverRepetida($repetida->payload_hash, $hash, $orden);
        }
    }

    public function cancelar(OrdenProcesoMp $orden, int $versionConocida, string $motivo, User $usuario): OrdenProcesoMp
    {
        return DB::transaction(function () use ($orden, $versionConocida, $motivo, $usuario): OrdenProcesoMp {
            $orden = OrdenProcesoMp::query()->lockForUpdate()->findOrFail($orden->id);
            if ($orden->estado === EstadoOrdenProcesoMp::Terminada
                && $orden->tipo_cierre === TipoCierreOrdenProcesoMp::Cancelacion
                && $orden->motivo_cancelacion === trim($motivo)) {
                return $this->cargar($orden);
            }
            $this->exigirAbierta($orden);
            if ($orden->version !== $versionConocida) {
                throw new ConflictoOperacion("{$orden->codigo} cambió mientras la revisabas. Actualiza antes de cancelar.");
            }

            $this->bloquearLotes(collect($orden->lote_ids_vigentes ?? []));
            ReservaLoteOrdenProcesoMp::query()
                ->where('orden_proceso_mp_id', $orden->id)
                ->update(['bins_pendientes' => 0, 'updated_at' => now()]);

            $orden->update([
                'estado' => EstadoOrdenProcesoMp::Terminada,
                'tipo_cierre' => TipoCierreOrdenProcesoMp::Cancelacion,
                'motivo_cancelacion' => trim($motivo),
                'cerrado_por_user_id' => $usuario->id,
                'cerrado_at' => now(),
                'version' => $orden->version + 1,
            ]);

            return $this->cargar($orden);
        }, attempts: 3);
    }

    /**
     * Lotes que el digitador puede agregar: temporada activa, envase primario bins, en cámara
     * o disponibles desde Hidrocooler, con bins libres después de lo comprometido en otras órdenes.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lotesProgramables(?string $excluirOrdenId = null, ?string $buscar = null): Collection
    {
        $temporada = $this->temporadas->buscar();
        if (! $temporada) {
            return collect();
        }

        $lotes = LoteMateriaPrima::query()
            ->with(['cliente:id,nombre', 'asignacionCamara.camara:id,codigo,nombre'])
            ->where('temporada_id', $temporada->id)
            ->where('envase_primario', TipoEnvaseRomana::Bins->value)
            ->whereIn('estado', array_map(fn (EstadoLoteMateriaPrima $estado): string => $estado->value, self::ESTADOS_LOTE_PROGRAMABLES))
            ->when(filled($buscar), fn ($consulta) => $consulta->where(fn ($q) => $q
                ->where('numero_lote', 'like', '%'.trim((string) $buscar).'%')
                ->orWhere('csg_snapshot', 'like', '%'.trim((string) $buscar).'%')))
            ->orderBy('numero_lote')
            ->limit(300)
            ->get();
        $ids = $lotes->pluck('id');
        $entregados = $this->entregadosPorLote($ids);
        $comprometidos = ReservaLoteOrdenProcesoMp::query()
            ->whereIn('lote_materia_prima_id', $ids)
            ->whereHas('orden', fn ($consulta) => $consulta->whereIn('estado', EstadoOrdenProcesoMp::abiertas()))
            ->when($excluirOrdenId, fn ($consulta) => $consulta->where('orden_proceso_mp_id', '!=', $excluirOrdenId))
            ->selectRaw('lote_materia_prima_id, SUM(bins_pendientes) as pendientes')
            ->groupBy('lote_materia_prima_id')
            ->pluck('pendientes', 'lote_materia_prima_id');
        $ubicaciones = $this->ultimaUbicacionPorCsg($lotes->pluck('csg_snapshot')->unique()->values());
        $productores = ProductorCsg::query()
            ->whereIn('codigo', $lotes->pluck('csg_snapshot')->unique())
            ->pluck('razon_social', 'codigo');

        return $lotes->map(function (LoteMateriaPrima $lote) use ($entregados, $comprometidos, $ubicaciones, $productores): array {
            $saldo = max(0, $lote->cantidad_envases_primarios - (int) ($entregados[$lote->id] ?? 0));
            $comprometido = (int) ($comprometidos[$lote->id] ?? 0);

            return [
                'id' => $lote->id,
                'numero_lote' => $lote->numero_lote,
                'cliente' => ['id' => $lote->cliente_id, 'nombre' => $lote->cliente?->nombre],
                'especie' => $lote->especie_snapshot,
                'variedad' => $lote->variedad_snapshot,
                'fecha_cosecha' => $lote->fecha_cosecha?->toDateString(),
                'csg' => $lote->csg_snapshot,
                'productor' => $productores[$lote->csg_snapshot] ?? null,
                'comuna' => $ubicaciones[$lote->csg_snapshot]['comuna'] ?? null,
                'provincia' => $ubicaciones[$lote->csg_snapshot]['provincia'] ?? null,
                'bins_lote' => $lote->cantidad_envases_primarios,
                'totes_lote' => $lote->cantidad_envases_secundarios,
                'kilos_netos' => (float) $lote->kilos_netos_confirmados,
                'saldo_bins' => $saldo,
                'bins_comprometidos' => $comprometido,
                'bins_libres' => max(0, $saldo - $comprometido),
                'camara' => $lote->asignacionCamara?->camara?->only(['id', 'codigo', 'nombre']),
            ];
        })->filter(fn (array $lote): bool => $lote['bins_libres'] > 0)->values();
    }

    /** @param array<string, mixed> $datos */
    private function publicar(
        OrdenProcesoMp $orden,
        LineaProceso $linea,
        array $datos,
        User $usuario,
        int $numero,
        OrigenVersionOrdenProcesoMp $origen,
        string $operacionId,
        string $hash,
    ): VersionOrdenProcesoMp {
        $filas = array_values($datos['lotes'] ?? []);
        if ($filas === []) {
            throw ValidationException::withMessages(['lotes' => 'La orden necesita al menos un lote.']);
        }
        $ids = array_column($filas, 'lote_materia_prima_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lotes' => 'Un lote solo puede aparecer una vez en la orden.']);
        }

        $loteIds = collect($ids)->merge($orden->lote_ids_vigentes ?? [])->unique()->values();
        $lotes = $this->bloquearLotes($loteIds)->load('cliente:id,nombre')->keyBy('id');
        $reservas = ReservaLoteOrdenProcesoMp::query()
            ->whereIn('lote_materia_prima_id', $loteIds)
            ->lockForUpdate()
            ->with('orden:id,estado')
            ->get();
        $propias = $reservas->where('orden_proceso_mp_id', $orden->id)->keyBy('lote_materia_prima_id');
        $otras = $reservas
            ->where('orden_proceso_mp_id', '!=', $orden->id)
            ->filter(fn (ReservaLoteOrdenProcesoMp $reserva): bool => (bool) $reserva->orden?->estado?->abierta())
            ->groupBy('lote_materia_prima_id')
            ->map(fn (Collection $grupo): int => (int) $grupo->sum('bins_pendientes'));
        $entregados = $this->entregadosPorLote($loteIds);

        foreach ($propias as $loteId => $reserva) {
            if (! in_array($loteId, $ids, true) && $reserva->bins_entregados > 0) {
                $numeroLote = $lotes[$loteId]?->numero_lote ?? $loteId;
                throw ValidationException::withMessages([
                    'lotes' => "El lote {$numeroLote} ya tiene {$reserva->bins_entregados} bins entregados en esta orden y no se puede quitar.",
                ]);
            }
        }

        $temporadaId = $orden->temporada_id;
        $productores = ProductorCsg::query()
            ->whereIn('codigo', $lotes->pluck('csg_snapshot')->unique())
            ->pluck('razon_social', 'codigo');
        $preparadas = [];
        foreach ($filas as $indice => $fila) {
            $lote = $lotes[$fila['lote_materia_prima_id']] ?? null;
            $campo = "lotes.{$indice}";
            if (! $lote || $lote->temporada_id !== $temporadaId) {
                throw ValidationException::withMessages(["{$campo}.lote_materia_prima_id" => 'El lote no pertenece a la temporada de la orden.']);
            }
            $bins = (int) $fila['bins_programados'];
            $entregadosOrden = (int) ($propias[$lote->id]->bins_entregados ?? 0);
            if ($bins < $entregadosOrden) {
                throw ValidationException::withMessages([
                    "{$campo}.bins_programados" => "El lote {$lote->numero_lote} ya tiene {$entregadosOrden} bins entregados en esta orden.",
                ]);
            }
            $pendiente = $bins - $entregadosOrden;
            if ($pendiente > 0) {
                if ($lote->envase_primario !== TipoEnvaseRomana::Bins) {
                    throw ValidationException::withMessages(["{$campo}.lote_materia_prima_id" => "El lote {$lote->numero_lote} no viene en bins."]);
                }
                if (! in_array($lote->estado, self::ESTADOS_LOTE_PROGRAMABLES, true)) {
                    throw ValidationException::withMessages([
                        "{$campo}.lote_materia_prima_id" => "El lote {$lote->numero_lote} no está disponible para proceso.",
                    ]);
                }
                $saldo = max(0, $lote->cantidad_envases_primarios - (int) ($entregados[$lote->id] ?? 0));
                $libres = $saldo - (int) ($otras[$lote->id] ?? 0);
                if ($pendiente > $libres) {
                    throw ValidationException::withMessages([
                        "{$campo}.bins_programados" => "El lote {$lote->numero_lote} tiene {$saldo} bins en cámara y ".max(0, $libres).' libres después de otras órdenes abiertas.',
                    ]);
                }
            }

            $proporcion = $lote->cantidad_envases_primarios > 0 ? $bins / $lote->cantidad_envases_primarios : 0;
            $kilos = round((float) $lote->kilos_netos_confirmados * $proporcion, 3);
            $kilosPorCaja = filled($fila['kilos_por_caja'] ?? null) ? round((float) $fila['kilos_por_caja'], 3) : null;
            $preparadas[] = [
                'lote' => $lote,
                'fila' => $fila,
                'bins' => $bins,
                'entregados' => $entregadosOrden,
                'pendiente' => $pendiente,
                'kilos' => $kilos,
                'totes' => $lote->cantidad_envases_secundarios !== null
                    ? (int) round($lote->cantidad_envases_secundarios * $proporcion)
                    : null,
                'kilos_por_caja' => $kilosPorCaja,
                'cajas' => $kilosPorCaja ? round($kilos * self::FRACCION_EXPORTABLE_CAJAS / $kilosPorCaja, 1) : null,
            ];
        }

        $horarios = $this->horarios->calcular(
            CarbonImmutable::parse($orden->fecha->toDateString()),
            (string) $datos['hora_inicio'],
            $datos['colacion_desde'] ?? null,
            $datos['colacion_hasta'] ?? null,
            (float) $linea->rendimiento_kg_hora,
            array_column($preparadas, 'kilos'),
        );

        $version = VersionOrdenProcesoMp::create([
            'orden_proceso_mp_id' => $orden->id,
            'numero' => $numero,
            'operacion_id' => $operacionId,
            'payload_hash' => $hash,
            'origen' => $origen,
            'hora_inicio' => $datos['hora_inicio'],
            'colacion_desde' => $datos['colacion_desde'] ?? null,
            'colacion_hasta' => $datos['colacion_hasta'] ?? null,
            'rendimiento_kg_hora' => $linea->rendimiento_kg_hora,
            'motivo_cambio' => filled($datos['motivo_cambio'] ?? null) ? trim((string) $datos['motivo_cambio']) : null,
            'creada_por_user_id' => $usuario->id,
            'creada_at' => now(),
        ]);

        foreach ($preparadas as $indice => $item) {
            /** @var LoteMateriaPrima $lote */
            $lote = $item['lote'];
            $fila = $item['fila'];
            LoteVersionOrdenMp::create([
                'version_orden_proceso_mp_id' => $version->id,
                'lote_materia_prima_id' => $lote->id,
                'secuencia' => $indice + 1,
                'numero_proceso' => $this->texto($fila['numero_proceso'] ?? null),
                'bins_programados' => $item['bins'],
                'totes_programados' => $item['totes'],
                'kilos_programados' => $item['kilos'],
                'embalaje' => $this->texto($fila['embalaje'] ?? null),
                'kilos_por_caja' => $item['kilos_por_caja'],
                'cajas_estimadas' => $item['cajas'],
                'mercado_destino' => $this->texto($fila['mercado_destino'] ?? null),
                'inicio_estimado' => $horarios[$indice]['inicio'],
                'termino_estimado' => $horarios[$indice]['termino'],
                'cliente_id' => $lote->cliente_id,
                'cliente_snapshot' => (string) ($lote->cliente?->nombre ?? ''),
                'numero_lote_snapshot' => $lote->numero_lote,
                'especie_snapshot' => $lote->especie_snapshot,
                'variedad_snapshot' => $lote->variedad_snapshot,
                'fecha_cosecha_snapshot' => $lote->fecha_cosecha,
                'csg_snapshot' => $lote->csg_snapshot,
                'productor_snapshot' => $productores[$lote->csg_snapshot] ?? null,
                'comuna' => $this->texto($fila['comuna'] ?? null),
                'provincia' => $this->texto($fila['provincia'] ?? null),
            ]);

            ReservaLoteOrdenProcesoMp::query()->updateOrCreate(
                ['orden_proceso_mp_id' => $orden->id, 'lote_materia_prima_id' => $lote->id],
                [
                    'bins_programados' => $item['bins'],
                    'bins_entregados' => $item['entregados'],
                    'bins_pendientes' => $item['pendiente'],
                ],
            );
        }

        ReservaLoteOrdenProcesoMp::query()
            ->where('orden_proceso_mp_id', $orden->id)
            ->whereNotIn('lote_materia_prima_id', $ids)
            ->delete();

        $completa = collect($preparadas)->every(fn (array $item): bool => $item['pendiente'] === 0);
        $orden->update([
            'version_vigente_id' => $version->id,
            'numero_version_vigente' => $numero,
            'lote_ids_vigentes' => array_values($ids),
            'version' => $numero === 1 ? 1 : $orden->version + 1,
            ...($completa ? [
                'estado' => EstadoOrdenProcesoMp::Terminada,
                'tipo_cierre' => TipoCierreOrdenProcesoMp::Completa,
                'cerrado_por_user_id' => $usuario->id,
                'cerrado_at' => now(),
            ] : []),
        ]);

        return $version;
    }

    /**
     * Bloquea los lotes en orden de id. Es la única forma en que esta clase toma lotes.
     *
     * @param  Collection<int, string>  $loteIds
     * @return EloquentCollection<int, LoteMateriaPrima>
     */
    public function bloquearLotes(Collection $loteIds): EloquentCollection
    {
        if ($loteIds->isEmpty()) {
            return new EloquentCollection;
        }

        return LoteMateriaPrima::query()
            ->whereKey($loteIds->unique()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Bins entregados vigentes por lote, sin bloquear entregas. Dentro de una transacción solo
     * es actual si se llama con los lotes ya bloqueados (ver la nota de la clase).
     *
     * @param  Collection<int, string>  $loteIds
     * @return Collection<string, int>
     */
    private function entregadosPorLote(Collection $loteIds): Collection
    {
        if ($loteIds->isEmpty()) {
            return collect();
        }

        return EntregaFrutaProceso::query()
            ->whereIn('lote_materia_prima_id', $loteIds)
            ->whereNull('anulado_at')
            ->get(['lote_materia_prima_id', 'cantidad_envases'])
            ->groupBy('lote_materia_prima_id')
            ->map(fn (Collection $grupo): int => (int) $grupo->sum('cantidad_envases'));
    }

    /**
     * Comuna y provincia no existen en los maestros: se proponen desde la última orden con el
     * mismo CSG para que el digitador no las escriba en cada PRE-SIF.
     *
     * @param  Collection<int, string>  $csgs
     * @return array<string, array{comuna: string|null, provincia: string|null}>
     */
    private function ultimaUbicacionPorCsg(Collection $csgs): array
    {
        if ($csgs->isEmpty()) {
            return [];
        }

        return LoteVersionOrdenMp::query()
            ->whereIn('csg_snapshot', $csgs)
            ->where(fn ($consulta) => $consulta->whereNotNull('comuna')->orWhereNotNull('provincia'))
            ->orderByDesc('created_at')
            ->get(['csg_snapshot', 'comuna', 'provincia'])
            ->unique('csg_snapshot')
            ->mapWithKeys(fn (LoteVersionOrdenMp $fila): array => [
                $fila->csg_snapshot => ['comuna' => $fila->comuna, 'provincia' => $fila->provincia],
            ])
            ->all();
    }

    private function exigirAbierta(OrdenProcesoMp $orden): void
    {
        if (! $orden->estado->abierta()) {
            throw new ConflictoOperacion("{$orden->codigo} está terminada y ya no admite cambios.");
        }
    }

    private function resolverRepetida(string $hashGuardado, string $hash, OrdenProcesoMp $orden): OrdenProcesoMp
    {
        if (! hash_equals($hashGuardado, $hash)) {
            throw new ConflictoOperacion('El identificador de operación ya fue usado con datos diferentes.');
        }

        return $this->cargar($orden);
    }

    /** @param array<string, mixed> $datos */
    private function hash(array $datos): string
    {
        $payload = $datos;
        unset($payload['operacion_id']);

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw ValidationException::withMessages(['payload' => 'La orden contiene datos que no pueden procesarse.']);
        }
    }

    private function texto(mixed $valor): ?string
    {
        return filled($valor) ? trim((string) $valor) : null;
    }

    public function cargar(OrdenProcesoMp $orden): OrdenProcesoMp
    {
        return $orden->fresh([
            'linea',
            'creadoPor:id,name',
            'cerradoPor:id,name',
            'versionVigente.lotes',
            'versionVigente.creadaPor:id,name',
            'versiones.creadaPor:id,name',
            'reservas',
        ]);
    }
}
