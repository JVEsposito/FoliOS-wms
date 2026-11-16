<?php

namespace App\Services\InspeccionSag;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoFolioInspeccionSag;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoTransicionOperacional;
use App\Enums\ResultadoInspeccionSag;
use App\Enums\TipoAprobacionSag;
use App\Enums\TipoBulto;
use App\Enums\TipoDestinoSag;
use App\Enums\TipoLoteInspeccionSag;
use App\Exceptions\ConflictoOperacion;
use App\Models\AutorizacionSagFolio;
use App\Models\BloqueMercado;
use App\Models\Cliente;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\Pais;
use App\Models\ResultadoDestinoInspeccionSag;
use App\Models\TransicionOperacional;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Closure;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ServicioInspeccionSag
{
    public function __construct(
        private readonly ServicioEstadoSagFolio $estadoSag,
        private readonly ServicioCorrelativoInspeccionSag $correlativos,
        private readonly ServicioPreparacionFisicaSag $preparacionFisica,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
    ) {}

    /** @param array<string, mixed> $datos */
    public function crear(array $datos, User $usuario): LoteInspeccionSag
    {
        $operacionId = $datos['operacion_id'] ?? (string) Str::uuid();
        $payload = Arr::except($datos, ['operacion_id']);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $accion = function () use (
            $datos,
            $usuario,
            $operacionId,
            $hash,
        ): LoteInspeccionSag {
            $existente = LoteInspeccionSag::query()->where('operacion_id', $operacionId)->first();

            if ($existente) {
                if (! hash_equals($existente->payload_hash, $hash)) {
                    throw new DomainException('La operación ya fue utilizada con datos diferentes.');
                }

                $this->preparacionFisica->sincronizar($existente, $usuario);

                return $this->cargar($existente);
            }

            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true)
                ?? throw new DomainException('No existe una temporada activa.');
            $folioIds = array_values(array_unique($datos['folios']));

            if (($datos['cantidad_solicitada'] ?? count($folioIds)) !== count($folioIds)) {
                throw new DomainException('La cantidad solicitada debe coincidir con los pallets seleccionados.');
            }

            $folios = $this->consultaFoliosElegibles()
                ->whereIn('id', $folioIds)
                ->with('validacionPallet.origen.clienteCatalogo.cliente')
                ->lockForUpdate()
                ->get();

            if ($folios->count() !== count($folioIds)) {
                throw new ConflictoOperacion('Uno o más pallets ya no están disponibles para esta inspección.');
            }

            $clientes = $folios
                ->map(fn (Folio $folio): ?Cliente => $folio->validacionPallet?->origen?->clienteCatalogo?->cliente)
                ->filter()
                ->unique('id')
                ->values();

            if ($clientes->count() !== 1) {
                throw new DomainException(
                    'Todos los pallets del lote deben pertenecer al mismo cliente/exportadora.',
                );
            }

            $cliente = $clientes->first();

            $destinos = $this->resolverDestinos($datos['destinos']);
            $correlativo = $this->correlativos->siguiente($cliente);
            $tipo = TipoLoteInspeccionSag::from($datos['tipo']);
            $automatico = $tipo->apruebaAutomaticamente();
            $ahora = now();
            $lote = LoteInspeccionSag::query()->create([
                'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id,
                'codigo' => $correlativo['codigo'],
                'numero_correlativo' => $correlativo['numero'],
                'numero_inspeccion_sag' => $datos['numero_inspeccion_sag'] ?? null,
                'operacion_id' => $operacionId,
                'payload_hash' => $hash,
                'tipo' => $tipo,
                'estado' => $automatico
                    ? EstadoLoteInspeccionSag::Finalizado
                    : EstadoLoteInspeccionSag::Preparacion,
                'cantidad_solicitada' => count($folioIds),
                'referencia_correo' => $datos['referencia_correo'] ?? null,
                'observacion' => $datos['observacion'] ?? null,
                'creado_por_user_id' => $usuario->id,
                'iniciado_por_user_id' => $automatico ? $usuario->id : null,
                'finalizado_por_user_id' => $automatico ? $usuario->id : null,
                'iniciado_at' => $automatico ? $ahora : null,
                'finalizado_at' => $automatico ? $ahora : null,
            ]);

            $destinosCreados = collect($destinos)->map(fn (array $destino) => $lote->destinos()->create($destino));

            foreach ($folios as $folio) {
                $asignacion = $lote->folios()->create([
                    'folio_id' => $folio->id,
                    'estado' => $automatico
                        ? EstadoFolioInspeccionSag::Resuelto
                        : EstadoFolioInspeccionSag::Pendiente,
                    'estado_sag_anterior' => $this->estadoSag->resumir($folio),
                    'resuelto_por_user_id' => $automatico ? $usuario->id : null,
                    'resuelto_at' => $automatico ? $ahora : null,
                ]);

                foreach ($destinosCreados as $destino) {
                    $resultado = $asignacion->resultados()->create([
                        'destino_lote_inspeccion_sag_id' => $destino->id,
                        'resultado' => $automatico
                            ? ResultadoInspeccionSag::Aprobado
                            : ResultadoInspeccionSag::Pendiente,
                        'tipo_aprobacion' => $automatico
                            ? $tipo->aprobacionPredeterminada()
                            : null,
                        'resuelto_por_user_id' => $automatico ? $usuario->id : null,
                        'resuelto_at' => $automatico ? $ahora : null,
                    ]);

                    if ($automatico) {
                        $this->registrarAutorizacion(
                            $resultado,
                            $tipo->aprobacionPredeterminada(),
                            $usuario,
                            $ahora,
                        );
                    }
                }
            }

            $this->preparacionFisica->sincronizar($lote, $usuario);

            return $this->cargar($lote);
        };

        return $this->transicionar(
            'lote.crear',
            $usuario,
            $payload,
            $accion,
            operacionId: $operacionId,
        );
    }

    /** Agrega o retira miembros sin reemplazar la selección de otro operador. */
    public function actualizarFolios(LoteInspeccionSag $lote, array $datos, User $usuario): LoteInspeccionSag
    {
        $operacionId = $datos['operacion_id'];
        $agregar = collect($datos['agregar'] ?? [])->unique()->sort()->values();
        $quitar = collect($datos['quitar'] ?? [])->unique()->sort()->values();
        if ($agregar->isEmpty() && $quitar->isEmpty()) {
            throw new DomainException('Indica al menos un pallet para agregar o quitar.');
        }
        if ($agregar->intersect($quitar)->isNotEmpty()) {
            throw new DomainException('Un pallet no puede agregarse y quitarse en la misma operación.');
        }

        return $this->transicionar('lote.editar_folios', $usuario,
            ['lote_id' => $lote->id, 'agregar' => $agregar->all(), 'quitar' => $quitar->all()],
            function () use ($lote, $agregar, $quitar, $usuario, $operacionId): LoteInspeccionSag {
                // El motor reejecuta la acción para rehidratar un UUID ya aplicado.
                if (TransicionOperacional::where('operacion_id', $operacionId)
                    ->where('tipo', 'lote.editar_folios')->where('estado', EstadoTransicionOperacional::Aplicada)->exists()) {
                    return $this->cargar($lote->refresh());
                }
                $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);
                $lote = LoteInspeccionSag::lockForUpdate()->findOrFail($lote->id);
                if ($lote->temporada_id !== $temporada?->id) {
                    throw new ConflictoOperacion('El lote no pertenece a la temporada activa.');
                }
                $this->exigirEstado($lote, [EstadoLoteInspeccionSag::Preparacion]);
                $miembros = $lote->folios()->with('resultados')->lockForUpdate()->get();
                $actuales = $miembros->pluck('folio_id');
                if ($quitar->diff($actuales)->isNotEmpty()) {
                    throw new ConflictoOperacion('Uno de los pallets ya no pertenece a este lote. Actualiza el detalle.');
                }
                $nuevos = $agregar->diff($actuales);
                $total = $actuales->diff($quitar)->count() + $nuevos->count();
                if ($total < 1 || $total > 1000) {
                    throw new DomainException('El lote debe conservar entre 1 y 1000 pallets.');
                }
                if ($miembros->flatMap->resultados->contains(fn ($r) => $r->resultado !== ResultadoInspeccionSag::Pendiente)) {
                    throw new ConflictoOperacion('El lote ya posee resultados y no puede cambiar sus pallets.');
                }
                if ($nuevos->isEmpty() && $quitar->isEmpty()) {
                    return $this->cargar($lote);
                }
                // Mismo mutex que la creación: no asignar un folio a dos inspecciones simultáneas.
                Folio::whereIn('id', $actuales->merge($nuevos))->orderBy('id')->lockForUpdate()->get();
                $folios = $this->consultaFoliosElegibles()->whereIn('id', $nuevos)
                    ->with('validacionPallet.origen.clienteCatalogo.cliente')->lockForUpdate()->get();
                if ($folios->count() !== $nuevos->count()) {
                    throw new ConflictoOperacion('Uno o más pallets ya no están disponibles para esta inspección.');
                }
                if ($folios->contains(fn ($f) => $f->validacionPallet?->origen?->clienteCatalogo?->cliente?->id !== $lote->cliente_id)) {
                    throw new DomainException('Los pallets agregados deben pertenecer al mismo cliente/exportadora del lote.');
                }
                if ($lote->planPreparacion?->estado->esFinal()) {
                    throw new ConflictoOperacion('La preparación física ya está cerrada y requiere revisión antes de editar el lote.');
                }
                // Libera reservas y cancela sólo trabajo reversible; una maniobra iniciada rechaza toda la edición.
                $this->preparacionFisica->liberar($lote, $usuario, 'Ajuste de pallets del lote SAG en preparación.');
                foreach ($miembros->whereIn('folio_id', $quitar) as $miembro) {
                    foreach ($miembro->resultados as $resultado) {
                        $resultado->delete();
                    }
                    $miembro->delete();
                }
                $destinos = $lote->destinos()->get();
                foreach ($folios as $folio) {
                    $miembro = $lote->folios()->create(['folio_id' => $folio->id,
                        'estado' => EstadoFolioInspeccionSag::Pendiente, 'estado_sag_anterior' => $this->estadoSag->resumir($folio)]);
                    foreach ($destinos as $destino) {
                        $miembro->resultados()->create(['destino_lote_inspeccion_sag_id' => $destino->id,
                            'resultado' => ResultadoInspeccionSag::Pendiente]);
                    }
                }
                $lote->update(['cantidad_solicitada' => $total]);
                $this->preparacionFisica->sincronizar($lote, $usuario);
                $this->preparacionFisica->restaurarFolios($quitar, $usuario, $lote->temporada_id);
                app(ServicioExistenciasSag::class)->sincronizar($quitar->all(), 'edicion_lote', $lote->codigo);

                return $this->cargar($lote);
            }, $lote, $operacionId);
    }

    public function iniciar(LoteInspeccionSag $lote, User $usuario): LoteInspeccionSag
    {
        $accion = function () use ($lote, $usuario): LoteInspeccionSag {
            $lote = LoteInspeccionSag::query()->lockForUpdate()->findOrFail($lote->id);
            $this->exigirEstado($lote, [EstadoLoteInspeccionSag::Preparacion]);
            $lote->update([
                'estado' => EstadoLoteInspeccionSag::EnInspeccion,
                'iniciado_por_user_id' => $usuario->id,
                'iniciado_at' => now(),
            ]);
            $this->preparacionFisica->completar($lote, $usuario);

            return $this->cargar($lote);
        };

        return $this->transicionar(
            'lote.iniciar',
            $usuario,
            ['lote_id' => $lote->id],
            $accion,
            $lote,
        );
    }

    /** @param array<string, mixed> $datos */
    public function resolver(
        LoteInspeccionSag $lote,
        ResultadoDestinoInspeccionSag $resultado,
        array $datos,
        User $usuario,
    ): LoteInspeccionSag {
        $accion = function () use ($lote, $resultado, $datos, $usuario): LoteInspeccionSag {
            $lote = LoteInspeccionSag::query()->lockForUpdate()->findOrFail($lote->id);
            $this->exigirEstado($lote, [
                EstadoLoteInspeccionSag::EnInspeccion,
                EstadoLoteInspeccionSag::ResultadoParcial,
            ]);
            $resultado = ResultadoDestinoInspeccionSag::query()
                ->with(['asignacion', 'destino'])
                ->lockForUpdate()
                ->findOrFail($resultado->id);

            if ($resultado->asignacion->lote_inspeccion_sag_id !== $lote->id) {
                throw new DomainException('El resultado no pertenece al lote indicado.');
            }

            $decision = ResultadoInspeccionSag::from($datos['resultado']);
            $tipoAprobacionSolicitado = isset($datos['tipo_aprobacion'])
                ? TipoAprobacionSag::from($datos['tipo_aprobacion'])
                : null;
            $tipoAprobacion = $lote->tipo->aprobacionPredeterminada()
                ?? $tipoAprobacionSolicitado;

            if ($decision === ResultadoInspeccionSag::Aprobado && $tipoAprobacion === null) {
                throw new DomainException(
                    'En un cambio de mercado, la aprobación debe indicar AO, AU o AF.',
                );
            }

            if ($decision === ResultadoInspeccionSag::Aprobado
                && $tipoAprobacionSolicitado !== null
                && $lote->tipo->aprobacionPredeterminada() !== null
                && $tipoAprobacionSolicitado !== $lote->tipo->aprobacionPredeterminada()) {
                throw new DomainException(
                    'El tipo de aprobación no corresponde al tipo de inspección seleccionado.',
                );
            }

            $resultado->update([
                'resultado' => $decision,
                'tipo_aprobacion' => $decision === ResultadoInspeccionSag::Aprobado
                    ? $tipoAprobacion
                    : null,
                'observacion' => $datos['observacion'] ?? null,
                'resuelto_por_user_id' => $usuario->id,
                'resuelto_at' => now(),
            ]);

            if ($decision === ResultadoInspeccionSag::Aprobado) {
                $this->registrarAutorizacion($resultado, $tipoAprobacion, $usuario);
            }

            $asignacion = $resultado->asignacion;
            if (! $asignacion->resultados()->where('resultado', ResultadoInspeccionSag::Pendiente)->exists()) {
                $asignacion->update([
                    'estado' => EstadoFolioInspeccionSag::Resuelto,
                    'resuelto_por_user_id' => $usuario->id,
                    'resuelto_at' => now(),
                ]);
            }

            $lote->update(['estado' => EstadoLoteInspeccionSag::ResultadoParcial]);

            return $this->cargar($lote);
        };

        return $this->transicionar(
            'destino.resolver',
            $usuario,
            [
                'lote_id' => $lote->id,
                'resultado_destino_id' => $resultado->id,
                'datos' => $datos,
            ],
            $accion,
            $lote,
        );
    }

    public function finalizar(LoteInspeccionSag $lote, User $usuario): LoteInspeccionSag
    {
        $accion = function () use ($lote, $usuario): LoteInspeccionSag {
            $lote = LoteInspeccionSag::query()->lockForUpdate()->findOrFail($lote->id);
            $this->exigirEstado($lote, [
                EstadoLoteInspeccionSag::EnInspeccion,
                EstadoLoteInspeccionSag::ResultadoParcial,
            ]);

            if ($lote->folios()->whereHas('resultados', fn ($consulta) => $consulta
                ->where('resultado', ResultadoInspeccionSag::Pendiente))->exists()) {
                throw new DomainException('Aún existen destinos pendientes de resolución.');
            }

            $lote->update([
                'estado' => EstadoLoteInspeccionSag::Finalizado,
                'finalizado_por_user_id' => $usuario->id,
                'finalizado_at' => now(),
            ]);
            $this->preparacionFisica->liberar(
                $lote,
                $usuario,
                'Inspección SAG finalizada.',
            );

            return $this->cargar($lote);
        };

        return $this->transicionar(
            'lote.finalizar',
            $usuario,
            ['lote_id' => $lote->id],
            $accion,
            $lote,
        );
    }

    public function cancelar(LoteInspeccionSag $lote, User $usuario): LoteInspeccionSag
    {
        $accion = function () use ($lote, $usuario): LoteInspeccionSag {
            $lote = LoteInspeccionSag::query()->lockForUpdate()->findOrFail($lote->id);

            if (! $lote->estado->esActivo()) {
                throw new DomainException('El lote SAG ya está cerrado.');
            }

            $lote->update([
                'estado' => EstadoLoteInspeccionSag::Cancelado,
                'cancelado_por_user_id' => $usuario->id,
                'cancelado_at' => now(),
            ]);
            $this->preparacionFisica->liberar(
                $lote,
                $usuario,
                'Lote de inspección SAG cancelado.',
                cancelarObjetivo: true,
            );

            return $this->cargar($lote);
        };

        return $this->transicionar(
            'lote.cancelar',
            $usuario,
            ['lote_id' => $lote->id],
            $accion,
            $lote,
        );
    }

    /** @param array<string, mixed> $payload */
    private function transicionar(
        string $tipo,
        User $usuario,
        array $payload,
        Closure $accion,
        ?LoteInspeccionSag $lote = null,
        ?string $operacionId = null,
    ): mixed {
        return $this->motorTransiciones->ejecutar(
            new ComandoTransicionOperacional(
                dominio: DominioTransicionOperacional::Sag,
                tipo: $tipo,
                usuario: $usuario,
                payload: $payload,
                operacionId: $operacionId,
                sujetoTipo: LoteInspeccionSag::class,
                sujetoId: $lote ? (string) $lote->id : null,
                referencia: $lote?->codigo,
            ),
            function () use ($accion): LoteInspeccionSag {
                $resultado = $accion();
                app(ServicioExistenciasSag::class)->sincronizar($resultado->folios()->pluck('folio_id')->all(), $resultado->tipo->value, $resultado->numero_inspeccion_sag ?: $resultado->codigo);

                return $resultado;
            },
        );
    }

    public function cargar(LoteInspeccionSag $lote): LoteInspeccionSag
    {
        return $lote->load([
            'temporada:id,codigo,nombre',
            'cliente:id,codigo,nombre,codigo_folio_materiales',
            'creadoPor:id,name',
            'planPreparacion',
            'destinos',
            'folios.folio.ubicacionActual.camara',
            'folios.folio.ubicacionActual.posicion',
            'folios.folio.ultimoPrefrio.proceso.tunel',
            'folios.folio.ultimoPrefrio.posicion',
            'folios.folio.validacionPallet.origen.csgCatalogo',
            'folios.folio.autorizacionesSagActivas',
            'folios.folio.inspeccionesSag.lote',
            'folios.resultados.destino',
        ]);
    }

    private function consultaFoliosElegibles()
    {
        $estadosTerminales = [
            EstadoOperacionalFolio::Anulado,
            EstadoOperacionalFolio::RetiradoDefinitivo,
            EstadoOperacionalFolio::Despachado,
            EstadoOperacionalFolio::Agotado,
        ];
        $estadosLoteActivos = collect(EstadoLoteInspeccionSag::cases())
            ->filter->esActivo()
            ->map->value
            ->all();

        return Folio::query()
            ->where('activo', true)
            ->where('tipo_bulto', TipoBulto::Pallet)
            ->whereNotIn('estado_operacional', $estadosTerminales)
            ->whereDoesntHave('material')
            ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->whereDoesntHave('inspeccionesSag.lote', fn ($consulta) => $consulta
                ->whereIn('estado', $estadosLoteActivos));
    }

    private function registrarAutorizacion(
        ResultadoDestinoInspeccionSag $resultado,
        TipoAprobacionSag $tipoAprobacion,
        User $usuario,
    ): void {
        $resultado->loadMissing(['asignacion', 'destino']);
        $destino = $resultado->destino;

        AutorizacionSagFolio::query()->firstOrCreate([
            'folio_id' => $resultado->asignacion->folio_id,
            'tipo_aprobacion' => $tipoAprobacion,
            'tipo_destino' => $destino->tipo_destino,
            'pais_id' => $destino->pais_id,
            'bloque_mercado_id' => $destino->bloque_mercado_id,
            'activa' => true,
        ], [
            'resultado_origen_id' => $resultado->id,
            'destino_snapshot' => $destino->destino_snapshot,
            'miembros_snapshot' => $destino->miembros_snapshot,
            'aprobado_por_user_id' => $usuario->id,
            'aprobado_at' => $resultado->resuelto_at ?? now(),
        ]);
    }

    /**
     * @param  array<int, array{tipo: string, id: string}>  $seleccionados
     * @return array<int, array<string, mixed>>
     */
    private function resolverDestinos(array $seleccionados): array
    {
        $seleccionados = collect($seleccionados)
            ->unique(fn (array $destino): string => $destino['tipo'].'|'.$destino['id'])
            ->values();

        if ($seleccionados->isEmpty()) {
            throw new DomainException('Selecciona al menos un destino para la inspección.');
        }

        $bloques = BloqueMercado::query()
            ->with(['paises' => fn ($consulta) => $consulta->where('paises.activo', true)])
            ->whereIn('id', $seleccionados->where('tipo', TipoDestinoSag::Bloque->value)->pluck('id'))
            ->where('activo', true)
            ->get()
            ->keyBy('id');
        $idsPaisesCubiertos = $bloques->flatMap->paises->pluck('id')->unique();
        $paises = Pais::query()
            ->whereIn('id', $seleccionados->where('tipo', TipoDestinoSag::Pais->value)->pluck('id'))
            ->whereNotIn('id', $idsPaisesCubiertos)
            ->where('activo', true)
            ->get()
            ->keyBy('id');

        $resultado = [];
        foreach ($seleccionados as $seleccionado) {
            if ($seleccionado['tipo'] === TipoDestinoSag::Bloque->value) {
                $bloque = $bloques->get($seleccionado['id'])
                    ?? throw new DomainException('Uno de los bloques de mercado no está disponible.');
                $resultado[] = [
                    'tipo_destino' => TipoDestinoSag::Bloque,
                    'bloque_mercado_id' => $bloque->id,
                    'pais_id' => null,
                    'destino_snapshot' => ['codigo' => $bloque->codigo, 'nombre' => $bloque->nombre],
                    'miembros_snapshot' => $bloque->paises->map(fn (Pais $pais): array => [
                        'iso_alpha2' => $pais->iso_alpha2,
                        'nombre' => $pais->nombre_es,
                    ])->values()->all(),
                ];
            } elseif (! $idsPaisesCubiertos->contains($seleccionado['id'])) {
                $pais = $paises->get($seleccionado['id'])
                    ?? throw new DomainException('Uno de los países no está disponible.');
                $resultado[] = [
                    'tipo_destino' => TipoDestinoSag::Pais,
                    'pais_id' => $pais->id,
                    'bloque_mercado_id' => null,
                    'destino_snapshot' => ['codigo' => $pais->iso_alpha2, 'nombre' => $pais->nombre_es],
                    'miembros_snapshot' => null,
                ];
            }
        }

        return $resultado;
    }

    /** @param array<int, EstadoLoteInspeccionSag> $estados */
    private function exigirEstado(LoteInspeccionSag $lote, array $estados): void
    {
        if (! in_array($lote->estado, $estados, true)) {
            throw new DomainException('El estado actual del lote no permite esta acción.');
        }
    }
}
