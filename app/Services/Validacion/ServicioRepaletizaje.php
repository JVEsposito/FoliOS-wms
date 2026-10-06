<?php

namespace App\Services\Validacion;

use App\Enums\CondicionTermicaFolio;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\AutorizacionSagFolio;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Repaletizaje;
use App\Models\RepaletizajeDetalle;
use App\Models\RepaletizajeResultado;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

class ServicioRepaletizaje
{
    public function __construct(
        private readonly MotorTransicionesOperacionales $motorTransiciones,
        private readonly ServicioGeneracionRecepcionRepaletizaje $generadorRecepcion,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(
        array $datos,
        User $usuario,
        ?Dispositivo $dispositivo = null,
    ): Repaletizaje {
        $payload = $this->normalizar($datos);
        $hash = $this->hash($payload);

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Repaletizaje,
            tipo: 'registrar',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            dispositivo: $dispositivo,
            sujetoTipo: Repaletizaje::class,
            referencia: $payload['numero_folio_resultante']
                ?? ($payload['resultados'][0]['numero_folio'] ?? null),
        );
        $accion = function () use (
            $datos,
            $usuario,
            $dispositivo,
            $payload,
            $hash,
        ): Repaletizaje {
            $existente = Repaletizaje::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if (! hash_equals($existente->payload_hash, $hash)) {
                    throw new ConflictoOperacion(
                        'El UUID del repaletizaje ya fue utilizado con datos diferentes.',
                    );
                }

                if ($existente->estado === 'confirmado') {
                    $this->generadorRecepcion->generar($existente, $usuario);
                }

                return $this->cargar($existente);
            }

            if ($payload['modalidad'] !== 'consolidacion') {
                return $this->registrarTransformacion(
                    $datos,
                    $payload,
                    $hash,
                    $usuario,
                    $dispositivo,
                );
            }

            $ids = collect($payload['origenes'])
                ->pluck('folio_id')
                ->sort()
                ->values();
            $folios = Folio::query()
                ->whereIn('id', $ids)
                ->with([
                    'ubicacionActual',
                    'validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
                ])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($folios->count() !== $ids->count()) {
                throw new DomainException('Uno de los folios ya no existe.');
            }

            $temporadaActivaId = app(ServicioTemporadaActiva::class)
                ->buscarConBloqueoCompartido()?->id;
            if (! $temporadaActivaId) {
                throw new ConflictoOperacion(
                    'No existe una temporada activa para registrar el repaletizaje.',
                );
            }

            $folioFueraTemporada = $folios->first(
                fn (Folio $folio): bool => $folio->temporada_id !== $temporadaActivaId,
            );
            if ($folioFueraTemporada) {
                throw new ConflictoOperacion(sprintf(
                    'El folio %s no pertenece a la temporada activa.',
                    $folioFueraTemporada->numero_folio,
                ));
            }

            $origenes = collect($payload['origenes'])->map(function (array $origen) use ($folios): array {
                /** @var Folio $folio */
                $folio = $folios->get($origen['folio_id']);
                $this->validarFolioOperable($folio);
                $cantidadAntes = $this->cantidad($folio);
                $aporte = (int) $origen['cantidad_aportada'];

                if ($aporte > $cantidadAntes) {
                    throw new DomainException(sprintf(
                        'El folio %s solo dispone de %d cajas.',
                        $folio->numero_folio,
                        $cantidadAntes,
                    ));
                }

                [$composicionAntes, $composicionAportada, $composicionDespues] =
                    $this->resolverAporteComposicion($folio, $origen, $aporte);

                return [
                    'folio' => $folio,
                    'cantidad_antes' => $cantidadAntes,
                    'cantidad_aportada' => $aporte,
                    'cantidad_despues' => $cantidadAntes - $aporte,
                    'composicion_antes' => $composicionAntes,
                    'composicion_aportada' => $composicionAportada,
                    'composicion_despues' => $composicionDespues,
                ];
            });

            $this->validarCompatibilidadDura($origenes->pluck('folio'));
            $cantidadResultado = (int) $origenes->sum('cantidad_aportada');
            $this->validarCantidadResultado($payload, $cantidadResultado);

            $folioConservado = null;
            if ($payload['estrategia_folio'] === 'conservar') {
                /** @var Folio|null $folioConservado */
                $folioConservado = $folios->get($payload['folio_conservado_id']);
                if (! $folioConservado) {
                    throw new DomainException(
                        'El folio que se desea conservar no participa en el repaletizaje.',
                    );
                }

                $detalleConservado = $origenes->first(
                    fn (array $origen): bool => $origen['folio']->id === $folioConservado->id,
                );
                if ($detalleConservado['cantidad_aportada'] !== $detalleConservado['cantidad_antes']) {
                    throw new DomainException(
                        'El folio conservado debe aportar la totalidad de sus cajas al resultado.',
                    );
                }
                if ($folioConservado->numero_folio !== $payload['numero_folio_resultante']) {
                    throw new DomainException(
                        'El número resultante debe coincidir con el folio que se conserva.',
                    );
                }
            } else {
                $numeroOcupado = Folio::query()
                    ->where('numero_folio', $payload['numero_folio_resultante'])
                    ->lockForUpdate()
                    ->exists();
                if ($numeroOcupado) {
                    throw new ConflictoOperacion(
                        'El número de folio resultante ya existe en el sistema.',
                    );
                }
            }

            $composicionResultado = $this->agruparComposicion(
                $origenes->flatMap(fn (array $origen): array => $origen['composicion_aportada']),
            );
            $fechaProceso = app(FechaProcesoEtiquetaPt::class)->masAntigua(
                $origenes->map(fn (array $origen) => app(FechaProcesoEtiquetaPt::class)->deFolio($origen['folio']))->all(),
            );
            $especificaciones = $this->especificacionesResultado($origenes->pluck('folio'));
            $especificaciones['csg'] = $this->valorComun($composicionResultado->pluck('csg'));
            $especificaciones['predio'] = $this->valorComun($composicionResultado->pluck('predio'));
            $camposMix = collect($especificaciones)
                ->filter(fn (mixed $valor): bool => $valor === 'MIX')
                ->keys()
                ->values()
                ->when(
                    $composicionResultado->pluck('fecha_embalaje')->unique()->count() > 1,
                    fn (Collection $campos): Collection => $campos->push('fecha_embalaje'),
                )
                ->unique()
                ->values()
                ->all();
            /** @var Folio $primero */
            $primero = $origenes->first()['folio'];
            $condicion = $primero->condicion_termica;
            $tieneUbicacionResultado = $folioConservado?->ubicacionActual !== null
                || ($folioConservado === null && $origenes->contains(
                    fn (array $origen): bool => $origen['cantidad_despues'] === 0
                        && $origen['folio']->ubicacionActual !== null,
                ));
            $estadoResultado = $this->estadoResultado(
                $condicion,
                $tieneUbicacionResultado,
            );
            $habilitacionResultado = $this->habilitacionResultado($condicion);
            $codigo = $this->siguienteCodigo();
            $genealogia = $origenes->map(fn (array $origen): array => [
                'folio_id' => $origen['folio']->id,
                'numero_folio' => $origen['folio']->numero_folio,
                'cajas_aportadas' => $origen['cantidad_aportada'],
                'composicion_aportada' => $origen['composicion_aportada'],
                'especificaciones' => $this->especificaciones($origen['folio']),
            ])->values()->all();
            $snapshotResultado = [
                'especificaciones' => $especificaciones,
                'campos_mix' => $camposMix,
                'advertencias' => collect($camposMix)->map(fn (string $campo): array => [
                    'campo' => $campo,
                    'mensaje' => 'Se está generando un MIX de '.mb_strtoupper($campo).'.',
                ])->values()->all(),
                'composicion' => $composicionResultado->values()->all(),
                'genealogia' => $genealogia,
            ];

            $folioResultado = $folioConservado ?? Folio::create([
                'temporada_id' => $primero->temporada_id,
                'fecha_proceso_pt' => $fechaProceso,
                'numero_folio' => $payload['numero_folio_resultante'],
                'tipo_bulto' => TipoBulto::from($payload['tipo_resultado']),
                'estado_operacional' => $estadoResultado,
                'condicion_termica' => $condicion,
                'habilitacion_almacenamiento' => $habilitacionResultado,
                'fecha_ingreso' => now(),
                'activo' => true,
                'variedad' => $especificaciones['variedad'],
                'calibre' => $especificaciones['calibre'],
                'marca' => $especificaciones['marca'],
                'exportadora' => $especificaciones['cliente'],
                'origen_sistema' => 'repaletizaje',
                'identificador_externo' => $datos['operacion_id'],
                'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
                'datos_externos' => [
                    'especie' => $especificaciones['especie'],
                    'categoria' => $especificaciones['categoria'],
                    'envase' => $especificaciones['envase'],
                    'csg' => $especificaciones['csg'],
                    'csgs' => $composicionResultado->pluck('csg')->unique()->values()->all(),
                    'predio' => $especificaciones['predio'],
                    'fecha_embalaje' => $this->valorComunFecha($composicionResultado),
                    'fechas_embalaje' => $composicionResultado->pluck('fecha_embalaje')
                        ->filter()->unique()->values()->all(),
                    'cuartel' => $especificaciones['cuartel'],
                    'cantidad_cajas' => $cantidadResultado,
                    'repaletizaje_codigo' => $codigo,
                    'campos_mix' => $camposMix,
                    'composicion' => $composicionResultado->values()->all(),
                    'genealogia_repaletizaje' => $genealogia,
                ],
            ]);

            $repa = Repaletizaje::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $hash,
                'codigo' => $codigo,
                'modalidad' => 'consolidacion',
                'tipo_resultado' => $payload['tipo_resultado'],
                'estrategia_folio' => $payload['estrategia_folio'],
                'folio_resultante_id' => $folioResultado->id,
                'folio_conservado_id' => $folioConservado?->id,
                'cantidad_objetivo' => $payload['cantidad_objetivo'],
                'cantidad_resultante' => $cantidadResultado,
                'condicion_termica' => $condicion->value,
                'campos_mix' => $camposMix,
                'snapshot' => $snapshotResultado,
                'estado' => 'confirmado',
                'observacion' => $payload['observacion'],
                'turno' => $payload['turno'],
                'fecha_operacional' => $this->fechaOperacional($payload),
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo?->id,
                'confirmado_at' => now(),
            ]);

            RepaletizajeResultado::create([
                'repaletizaje_id' => $repa->id,
                'folio_id' => $folioResultado->id,
                'orden' => 1,
                'tipo_resultado' => $payload['tipo_resultado'],
                'cantidad_objetivo' => $payload['cantidad_objetivo'],
                'cantidad_resultante' => $cantidadResultado,
                'hereda_ubicacion' => $folioConservado === null,
                'snapshot' => $snapshotResultado,
            ]);

            $ubicacionTransferida = false;
            foreach ($origenes as $indice => $origen) {
                /** @var Folio $folio */
                $folio = $origen['folio'];
                $snapshotAntes = $this->snapshotFolio($folio);
                $esConservado = $folioConservado?->id === $folio->id;

                if ($esConservado) {
                    $folio->fecha_proceso_pt = $fechaProceso;
                    $this->actualizarFolioResultado(
                        $folio,
                        $payload['tipo_resultado'],
                        $cantidadResultado,
                        $estadoResultado,
                        $habilitacionResultado,
                        $especificaciones,
                        $codigo,
                        $camposMix,
                        $composicionResultado->values()->all(),
                        $genealogia,
                    );
                } elseif ($origen['cantidad_despues'] === 0) {
                    if (! $folioConservado && ! $ubicacionTransferida && $folio->ubicacionActual) {
                        $folio->ubicacionActual->update(['folio_id' => $folioResultado->id]);
                        $ubicacionTransferida = true;
                    } else {
                        $folio->ubicacionActual?->delete();
                    }
                    $datosExternos = $folio->datos_externos ?? [];
                    $datosExternos['cantidad_cajas'] = 0;
                    $datosExternos['composicion'] = [];
                    $datosExternos['consumido_en_repaletizaje'] = $codigo;
                    $folio->update([
                        'tipo_bulto' => TipoBulto::Saldo,
                        'estado_operacional' => EstadoOperacionalFolio::Agotado,
                        'activo' => false,
                        'datos_externos' => $datosExternos,
                    ]);
                } else {
                    $datosExternos = $folio->datos_externos ?? [];
                    $datosExternos['cantidad_cajas'] = $origen['cantidad_despues'];
                    $datosExternos['composicion'] = $origen['composicion_despues'];
                    $this->actualizarResumenComposicion($datosExternos);
                    $datosExternos['ultimo_repaletizaje'] = $codigo;
                    $folio->update([
                        'tipo_bulto' => TipoBulto::Saldo,
                        'estado_operacional' => $this->estadoResultado(
                            $condicion,
                            $folio->ubicacionActual !== null,
                        ),
                        'datos_externos' => $datosExternos,
                    ]);
                }

                $folio->refresh();
                RepaletizajeDetalle::create([
                    'repaletizaje_id' => $repa->id,
                    'folio_origen_id' => $folio->id,
                    'orden' => $indice + 1,
                    'es_folio_conservado' => $esConservado,
                    'cajas_antes' => $origen['cantidad_antes'],
                    'cajas_aportadas' => $origen['cantidad_aportada'],
                    'cajas_despues' => $esConservado
                        ? $cantidadResultado
                        : $origen['cantidad_despues'],
                    'tipo_bulto_antes' => $snapshotAntes['atributos']['tipo_bulto'],
                    'tipo_bulto_despues' => $folio->tipo_bulto?->value,
                    'estado_antes' => $snapshotAntes['atributos']['estado_operacional'],
                    'estado_despues' => $folio->estado_operacional?->value,
                    'snapshot_antes' => $snapshotAntes,
                    'snapshot_despues' => $this->snapshotFolio($folio),
                ]);
            }

            if (! $folioConservado) {
                $datosExternos = $folioResultado->datos_externos ?? [];
                $datosExternos['repaletizaje_id'] = $repa->id;
                $folioResultado->update(['datos_externos' => $datosExternos]);
            }

            $this->generadorRecepcion->generar($repa, $usuario);

            $this->refrescarPlanos($repa);

            return $this->cargar($repa->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    /**
     * Registra un cambio de folio 1→1 o una división física 1→2.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $payload
     */
    private function registrarTransformacion(
        array $datos,
        array $payload,
        string $hash,
        User $usuario,
        ?Dispositivo $dispositivo,
    ): Repaletizaje {
        $origenPayload = $payload['origenes'][0];
        $folio = Folio::query()
            ->whereKey($origenPayload['folio_id'])
            ->with('ubicacionActual')
            ->lockForUpdate()
            ->firstOrFail();

        $temporadaActivaId = app(ServicioTemporadaActiva::class)->buscarConBloqueoCompartido()?->id;
        if (! $temporadaActivaId || $folio->temporada_id !== $temporadaActivaId) {
            throw new ConflictoOperacion('El folio no pertenece a la temporada activa.');
        }
        $this->validarFolioTransformable($folio);

        $cantidadAntes = $this->cantidad($folio);
        if ((int) $origenPayload['cantidad_aportada'] !== $cantidadAntes) {
            throw new DomainException('El cambio o división debe consumir la totalidad del folio original.');
        }
        $composicionAntes = collect($this->composicionFolio($folio))->keyBy('clave');
        if ((int) $composicionAntes->sum('cantidad_cajas') !== $cantidadAntes) {
            throw new DomainException('La composición del folio no coincide con su total. Requiere regularización.');
        }

        $resultados = collect($payload['resultados'])->map(function (array $resultado) use (
            $payload,
            $composicionAntes,
        ): array {
            $composicion = $payload['modalidad'] === 'cambio_folio'
                ? $composicionAntes->values()
                : collect($resultado['composicion'] ?? [])->map(function (array $linea) use ($composicionAntes): array {
                    $origen = $composicionAntes->get($linea['clave']);
                    if (! $origen) {
                        throw new DomainException('Una composición seleccionada ya no existe en el folio original.');
                    }

                    return [...$origen, 'cantidad_cajas' => (int) $linea['cantidad_cajas']];
                });
            if ($composicion->pluck('clave')->duplicates()->isNotEmpty()) {
                throw new DomainException('Un resultado contiene composiciones repetidas.');
            }
            $cantidad = (int) $resultado['cantidad_resultante'];
            if ((int) $composicion->sum('cantidad_cajas') !== $cantidad) {
                throw new DomainException('La composición de cada resultado debe sumar su cantidad de cajas.');
            }
            $this->validarCantidadResultado($resultado, $cantidad);

            return [
                ...$resultado,
                'numero_folio' => mb_strtoupper(trim((string) $resultado['numero_folio'])),
                'cantidad_objetivo' => isset($resultado['cantidad_objetivo'])
                    ? (int) $resultado['cantidad_objetivo']
                    : null,
                'cantidad_resultante' => $cantidad,
                'composicion' => $composicion->values()->all(),
            ];
        })->values();

        if ($resultados->pluck('numero_folio')->duplicates()->isNotEmpty()) {
            throw new DomainException('Los folios resultantes deben ser diferentes.');
        }
        if ((int) $resultados->sum('cantidad_resultante') !== $cantidadAntes) {
            throw new DomainException('Los resultados deben distribuir el 100% de las cajas del folio original.');
        }
        $distribucion = $resultados->flatMap(fn (array $resultado): array => $resultado['composicion'])
            ->groupBy('clave')->map(fn (Collection $lineas): int => (int) $lineas->sum('cantidad_cajas'));
        foreach ($composicionAntes as $clave => $linea) {
            if (($distribucion[$clave] ?? 0) !== (int) $linea['cantidad_cajas']) {
                throw new DomainException('Cada CSG y fecha debe distribuirse completamente entre los resultados.');
            }
        }
        if (Folio::query()->whereIn('numero_folio', $resultados->pluck('numero_folio'))->lockForUpdate()->exists()) {
            throw new ConflictoOperacion('Uno de los números de folio resultantes ya existe.');
        }

        $codigo = $this->siguienteCodigo();
        $snapshotAntes = $this->snapshotFolio($folio);
        $genealogia = [[
            'folio_id' => $folio->id,
            'numero_folio' => $folio->numero_folio,
            'cajas_aportadas' => $cantidadAntes,
            'composicion_aportada' => $composicionAntes->values()->all(),
            'especificaciones' => $this->especificaciones($folio),
        ]];
        $foliosResultado = $resultados->map(function (array $resultado, int $indice) use (
            $folio,
            $codigo,
            $datos,
            $genealogia,
        ): array {
            $datosExternos = array_merge($folio->datos_externos ?? [], [
                'cantidad_cajas' => $resultado['cantidad_resultante'],
                'composicion' => $resultado['composicion'],
                'repaletizaje_codigo' => $codigo,
                'genealogia_repaletizaje' => $genealogia,
            ]);
            $this->actualizarResumenComposicion($datosExternos);
            $nuevo = Folio::create([
                'temporada_id' => $folio->temporada_id,
                'numero_folio' => $resultado['numero_folio'],
                'tipo_bulto' => TipoBulto::from($resultado['tipo_resultado']),
                'condicion_sag_id' => $folio->condicion_sag_id,
                'estado_operacional' => $this->estadoResultado(
                    $folio->condicion_termica,
                    $indice === 0 && $folio->ubicacionActual !== null,
                ),
                'condicion_termica' => $folio->condicion_termica,
                'habilitacion_almacenamiento' => $this->habilitacionResultado(
                    $folio->condicion_termica,
                ),
                'fuente_habilitacion_almacenamiento' => $folio->fuente_habilitacion_almacenamiento,
                'habilitado_almacenamiento_at' => $folio->habilitado_almacenamiento_at,
                'habilitado_almacenamiento_por_user_id' => $folio->habilitado_almacenamiento_por_user_id,
                'fecha_ingreso' => $folio->fecha_ingreso,
                'fecha_proceso_pt' => app(FechaProcesoEtiquetaPt::class)->deFolio($folio),
                'activo' => true,
                'variedad' => $folio->variedad,
                'calibre' => $folio->calibre,
                'marca' => $folio->marca,
                'exportadora' => $folio->exportadora,
                'origen_sistema' => 'repaletizaje',
                'identificador_externo' => $datos['operacion_id'].':'.($indice + 1),
                'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
                'datos_externos' => $datosExternos,
            ]);
            $this->heredarAutorizacionesSag($folio, $nuevo);

            return [...$resultado, 'folio' => $nuevo];
        });

        /** @var Folio $primero */
        $primero = $foliosResultado->first()['folio'];
        $snapshotRepa = [
            'modalidad' => $payload['modalidad'],
            'genealogia' => $genealogia,
            'resultados' => $foliosResultado->map(fn (array $resultado): array => [
                'folio_id' => $resultado['folio']->id,
                'numero_folio' => $resultado['folio']->numero_folio,
                'tipo_resultado' => $resultado['tipo_resultado'],
                'cantidad_resultante' => $resultado['cantidad_resultante'],
                'composicion' => $resultado['composicion'],
            ])->values()->all(),
            'advertencias' => [],
        ];
        $repa = Repaletizaje::create([
            'operacion_id' => $datos['operacion_id'],
            'payload_hash' => $hash,
            'codigo' => $codigo,
            'modalidad' => $payload['modalidad'],
            'tipo_resultado' => $payload['modalidad'] === 'division' ? 'division' : $resultados->first()['tipo_resultado'],
            'estrategia_folio' => 'nuevo',
            'folio_resultante_id' => $primero->id,
            'folio_conservado_id' => null,
            'cantidad_objetivo' => null,
            'cantidad_resultante' => $cantidadAntes,
            'condicion_termica' => $folio->condicion_termica->value,
            'campos_mix' => [],
            'snapshot' => $snapshotRepa,
            'estado' => 'confirmado',
            'observacion' => $payload['observacion'],
            'turno' => $payload['turno'],
            'fecha_operacional' => $this->fechaOperacional($payload),
            'user_id' => $usuario->id,
            'dispositivo_id' => $dispositivo?->id,
            'confirmado_at' => now(),
        ]);

        foreach ($foliosResultado as $indice => $resultado) {
            RepaletizajeResultado::create([
                'repaletizaje_id' => $repa->id,
                'folio_id' => $resultado['folio']->id,
                'orden' => $indice + 1,
                'tipo_resultado' => $resultado['tipo_resultado'],
                'cantidad_objetivo' => $resultado['cantidad_objetivo'],
                'cantidad_resultante' => $resultado['cantidad_resultante'],
                'hereda_ubicacion' => $indice === 0,
                'snapshot' => ['composicion' => $resultado['composicion']],
            ]);
            $externos = $resultado['folio']->datos_externos;
            $externos['repaletizaje_id'] = $repa->id;
            $resultado['folio']->update(['datos_externos' => $externos]);
        }

        if ($folio->ubicacionActual) {
            $folio->ubicacionActual->update(['folio_id' => $primero->id]);
        }
        $externosOrigen = $folio->datos_externos ?? [];
        $externosOrigen['cantidad_cajas'] = 0;
        $externosOrigen['composicion'] = [];
        $externosOrigen['consumido_en_repaletizaje'] = $codigo;
        $folio->update([
            'tipo_bulto' => TipoBulto::Saldo,
            'estado_operacional' => EstadoOperacionalFolio::Agotado,
            'activo' => false,
            'datos_externos' => $externosOrigen,
        ]);
        $folio->refresh();
        RepaletizajeDetalle::create([
            'repaletizaje_id' => $repa->id,
            'folio_origen_id' => $folio->id,
            'orden' => 1,
            'es_folio_conservado' => false,
            'cajas_antes' => $cantidadAntes,
            'cajas_aportadas' => $cantidadAntes,
            'cajas_despues' => 0,
            'tipo_bulto_antes' => $snapshotAntes['atributos']['tipo_bulto'],
            'tipo_bulto_despues' => $folio->tipo_bulto->value,
            'estado_antes' => $snapshotAntes['atributos']['estado_operacional'],
            'estado_despues' => $folio->estado_operacional->value,
            'snapshot_antes' => $snapshotAntes,
            'snapshot_despues' => $this->snapshotFolio($folio),
        ]);

        $this->generadorRecepcion->generar($repa, $usuario);

        $this->refrescarPlanos($repa);

        return $this->cargar($repa->refresh());
    }

    public function anular(
        Repaletizaje $repaletizaje,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): Repaletizaje {
        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'repaletizaje.anular',
            usuario: $usuario,
            payload: [
                'repaletizaje_id' => $repaletizaje->id,
                'motivo' => trim($motivo),
            ],
            operacionId: $operacionId,
            sujetoTipo: Repaletizaje::class,
            sujetoId: (string) $repaletizaje->id,
            referencia: $repaletizaje->codigo,
        );
        $accion = function () use (
            $repaletizaje,
            $operacionId,
            $motivo,
            $usuario,
        ): Repaletizaje {
            $repa = Repaletizaje::query()
                ->with(['detalles.folioOrigen', 'folioResultante', 'resultados.folio'])
                ->lockForUpdate()
                ->findOrFail($repaletizaje->id);

            if ($repa->estado === 'anulado') {
                if ($repa->operacion_anulacion_id !== $operacionId) {
                    throw new ConflictoOperacion(
                        'El repaletizaje ya fue anulado con otra operación.',
                    );
                }

                return $this->cargar($repa);
            }

            if (Repaletizaje::query()
                ->where('operacion_anulacion_id', $operacionId)
                ->where('id', '!=', $repa->id)
                ->exists()) {
                throw new ConflictoOperacion('El UUID de anulación ya fue utilizado.');
            }

            $folioIds = $repa->detalles
                ->pluck('folio_origen_id')
                ->merge($repa->resultados->pluck('folio_id'))
                ->push($repa->folio_resultante_id)
                ->unique();
            $folios = Folio::query()
                ->whereIn('id', $folioIds)
                ->with([
                    'ubicacionActual',
                    'validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
                ])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $participacionPosterior = RepaletizajeDetalle::query()
                ->whereIn('folio_origen_id', $folioIds)
                ->whereHas('repaletizaje', function ($consulta) use ($repa): void {
                    $consulta
                        ->where('id', '!=', $repa->id)
                        ->where('estado', 'confirmado')
                        ->where('codigo', '>', $repa->codigo);
                })
                ->exists();
            if ($participacionPosterior) {
                throw new ConflictoOperacion(
                    'No se puede anular porque uno de sus folios ya participa en un repaletizaje posterior.',
                );
            }

            foreach ($folios as $folio) {
                if ($folio->asignacionCargaActual()->exists()
                    || $folio->reservaCargaActual()->exists()
                    || $folio->movimientos()->where('created_at', '>', $repa->confirmado_at)->exists()
                    || $folio->procesosPrefrio()->where('created_at', '>', $repa->confirmado_at)->exists()) {
                    throw new ConflictoOperacion(
                        'No se puede anular porque uno de los folios posee movimientos posteriores.',
                    );
                }
            }

            $this->generadorRecepcion->cancelarPorAnulacion($repa, $usuario);

            foreach ($folios as $folio) {
                $folio->ubicacionActual?->delete();
            }

            foreach ($repa->detalles as $detalle) {
                /** @var Folio $folio */
                $folio = $folios->get($detalle->folio_origen_id);
                $snapshot = $detalle->snapshot_antes;
                $folio->update([...$snapshot['atributos'], 'fecha_proceso_pt' => $snapshot['atributos']['fecha_proceso_pt'] ?? null]);
                $this->restaurarUbicacion($folio, $snapshot['ubicacion'] ?? null);
            }

            if ($repa->estrategia_folio === 'nuevo') {
                $resultadoIds = $repa->resultados->pluck('folio_id');
                if ($resultadoIds->isEmpty()) {
                    $resultadoIds->push($repa->folio_resultante_id);
                }
                $resultadoIds->each(function (string $folioId) use ($folios): void {
                    $folios->get($folioId)?->update([
                        'estado_operacional' => EstadoOperacionalFolio::Anulado,
                        'activo' => false,
                    ]);
                });
                AutorizacionSagFolio::query()
                    ->whereIn('folio_id', $resultadoIds)
                    ->where('activa', true)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (AutorizacionSagFolio $autorizacion): bool => $autorizacion->update([
                        'activa' => false,
                        'motivo_revocacion' => 'Repaletizaje anulado',
                        'revocado_por_user_id' => $usuario->id,
                        'revocado_at' => now(),
                    ]));
            }

            $repa->update([
                'estado' => 'anulado',
                'operacion_anulacion_id' => $operacionId,
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => now(),
                'motivo_anulacion' => trim($motivo),
            ]);

            $this->refrescarPlanos($repa);

            return $this->cargar($repa->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    public function cargar(Repaletizaje $repaletizaje): Repaletizaje
    {
        return $repaletizaje->load([
            'folioResultante',
            'folioConservado',
            'resultados.folio',
            'detalles.folioOrigen',
            'usuario:id,name',
            'dispositivo:id,codigo,nombre',
            'anuladoPor:id,name',
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function normalizar(array $datos): array
    {
        return [
            'modalidad' => $datos['modalidad'] ?? 'consolidacion',
            'tipo_resultado' => $datos['tipo_resultado'] ?? null,
            'estrategia_folio' => $datos['estrategia_folio'] ?? null,
            'numero_folio_resultante' => mb_strtoupper(
                trim((string) ($datos['numero_folio_resultante'] ?? '')),
            ),
            'folio_conservado_id' => $datos['folio_conservado_id'] ?? null,
            'cantidad_objetivo' => isset($datos['cantidad_objetivo'])
                ? (int) $datos['cantidad_objetivo']
                : null,
            'origenes' => collect($datos['origenes'])->map(fn (array $origen): array => [
                'folio_id' => $origen['folio_id'],
                'cantidad_aportada' => (int) $origen['cantidad_aportada'],
                'composicion' => collect($origen['composicion'] ?? [])->map(
                    fn (array $linea): array => [
                        'clave' => (string) $linea['clave'],
                        'cantidad_aportada' => (int) $linea['cantidad_aportada'],
                    ],
                )->values()->all(),
            ])->values()->all(),
            'resultados' => collect($datos['resultados'] ?? [])->map(fn (array $resultado): array => [
                'numero_folio' => mb_strtoupper(trim((string) $resultado['numero_folio'])),
                'tipo_resultado' => $resultado['tipo_resultado'],
                'cantidad_objetivo' => isset($resultado['cantidad_objetivo'])
                    ? (int) $resultado['cantidad_objetivo']
                    : null,
                'cantidad_resultante' => (int) $resultado['cantidad_resultante'],
                'composicion' => collect($resultado['composicion'] ?? [])->map(
                    fn (array $linea): array => [
                        'clave' => (string) $linea['clave'],
                        'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                    ],
                )->values()->all(),
            ])->values()->all(),
            'observacion' => filled($datos['observacion'] ?? null)
                ? trim((string) $datos['observacion'])
                : null,
            'turno' => filled($datos['turno'] ?? null)
                ? mb_strtoupper(trim((string) $datos['turno']))
                : null,
            // Solo lo que envía el cliente forma parte del hash; la fecha por
            // defecto se calcula al crear, para que un reintento no cambie de hash.
            'fecha_operacional' => $datos['fecha_operacional'] ?? null,
        ];
    }

    /**
     * Fecha del registro RRPL-01: la declarada por el turno o, si no, el día
     * operacional en curso.
     *
     * @param  array<string, mixed>  $payload
     */
    private function fechaOperacional(array $payload): string
    {
        return $payload['fecha_operacional']
            ?? now(config('app.operational_timezone'))->toDateString();
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new DomainException(
                'No fue posible preparar la operación de repaletizaje.',
                previous: $exception,
            );
        }
    }

    private function validarFolioOperable(Folio $folio): void
    {
        if (! $folio->activo || $folio->tipo_bulto !== TipoBulto::Saldo) {
            throw new DomainException(
                "El folio {$folio->numero_folio} no es un saldo activo.",
            );
        }
        if ($this->cantidad($folio) < 1) {
            throw new DomainException(
                "El folio {$folio->numero_folio} no posee cajas disponibles.",
            );
        }
        if (! in_array($folio->condicion_termica, [
            CondicionTermicaFolio::PendientePrefrio,
            CondicionTermicaFolio::PrefrioAprobado,
        ], true)) {
            throw new DomainException(
                "El folio {$folio->numero_folio} posee un estado térmico transitorio o retenido.",
            );
        }
        if ($folio->asignacionCargaActual()->exists() || $folio->reservaCargaActual()->exists()) {
            throw new ConflictoOperacion(
                "El folio {$folio->numero_folio} está reservado o asignado a una carga.",
            );
        }
        if ($folio->procesosPrefrio()
            ->whereIn('estado', ['cargado', 'en_proceso'])
            ->exists()) {
            throw new ConflictoOperacion(
                "El folio {$folio->numero_folio} participa en un proceso de prefrío activo.",
            );
        }
    }

    private function estadoResultado(
        CondicionTermicaFolio $condicion,
        bool $tieneUbicacion,
    ): EstadoOperacionalFolio {
        if ($condicion === CondicionTermicaFolio::PendientePrefrio) {
            return EstadoOperacionalFolio::PendientePrefrio;
        }

        return $tieneUbicacion
            ? EstadoOperacionalFolio::Disponible
            : EstadoOperacionalFolio::PendienteUbicacion;
    }

    private function habilitacionResultado(
        CondicionTermicaFolio $condicion,
    ): HabilitacionAlmacenamientoFolio {
        return $condicion === CondicionTermicaFolio::PendientePrefrio
            ? HabilitacionAlmacenamientoFolio::NoHabilitado
            : HabilitacionAlmacenamientoFolio::Habilitado;
    }

    private function validarFolioTransformable(Folio $folio): void
    {
        if (! $folio->activo || ! in_array($folio->tipo_bulto, [TipoBulto::Pallet, TipoBulto::Saldo], true)) {
            throw new DomainException("El folio {$folio->numero_folio} no es un pallet o saldo activo.");
        }
        if ($this->cantidad($folio) < 1) {
            throw new DomainException("El folio {$folio->numero_folio} no posee cajas disponibles.");
        }
        if (! in_array($folio->condicion_termica, [
            CondicionTermicaFolio::PendientePrefrio,
            CondicionTermicaFolio::PrefrioAprobado,
        ], true)) {
            throw new DomainException("El folio {$folio->numero_folio} posee un estado térmico transitorio o retenido.");
        }
        if ($folio->asignacionCargaActual()->exists() || $folio->reservaCargaActual()->exists()) {
            throw new ConflictoOperacion("El folio {$folio->numero_folio} está reservado o asignado a una carga.");
        }
        if ($folio->procesosPrefrio()->whereIn('estado', ['cargado', 'en_proceso'])->exists()) {
            throw new ConflictoOperacion("El folio {$folio->numero_folio} participa en un proceso de prefrío activo.");
        }
    }

    /** @param Collection<int, Folio> $folios */
    private function validarCompatibilidadDura(Collection $folios): void
    {
        $campos = [
            'cliente' => $folios->map(fn (Folio $folio): mixed => $folio->exportadora),
            'especie' => $folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['especie'] ?? null,
            ),
            'marca' => $folios->map(fn (Folio $folio): mixed => $folio->marca),
            'estado térmico' => $folios->map(
                fn (Folio $folio): mixed => $folio->condicion_termica?->value,
            ),
        ];

        foreach ($campos as $nombre => $valores) {
            $unicos = $valores
                ->map(fn (mixed $valor): string => mb_strtoupper(trim((string) $valor)))
                ->unique();
            if ($unicos->contains('') || $unicos->count() !== 1) {
                throw new DomainException(
                    "No se puede mezclar diferente {$nombre} en un repaletizaje.",
                );
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function validarCantidadResultado(array $payload, int $cantidad): void
    {
        if ($payload['tipo_resultado'] === 'pallet'
            && $cantidad !== $payload['cantidad_objetivo']) {
            throw new DomainException(sprintf(
                'El pallet debe completar exactamente %d cajas; la selección aporta %d.',
                $payload['cantidad_objetivo'],
                $cantidad,
            ));
        }

        if ($payload['tipo_resultado'] === 'saldo'
            && $payload['cantidad_objetivo'] !== null
            && $cantidad >= $payload['cantidad_objetivo']) {
            throw new DomainException(
                'Un saldo consolidado debe quedar bajo la capacidad del pallet completo.',
            );
        }
    }

    /** @param Collection<int, Folio> $folios */
    private function especificacionesResultado(Collection $folios): array
    {
        return [
            'cliente' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->exportadora,
            )),
            'especie' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['especie'] ?? null,
            )),
            'marca' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->marca,
            )),
            'variedad' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->variedad,
            )),
            'calibre' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->calibre,
            )),
            'envase' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['envase'] ?? null,
            )),
            'categoria' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['categoria'] ?? null,
            )),
            'csg' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['csg'] ?? null,
            )),
            'predio' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['predio'] ?? null,
            )),
            'cuartel' => $this->valorComun($folios->map(
                fn (Folio $folio): mixed => $folio->datos_externos['cuartel'] ?? null,
            )),
        ];
    }

    private function valorComun(Collection $valores): ?string
    {
        $limpios = $valores->map(
            fn (mixed $valor): ?string => filled($valor) ? trim((string) $valor) : null,
        );
        $normalizados = $limpios
            ->map(fn (?string $valor): string => mb_strtoupper((string) $valor))
            ->unique();

        return $normalizados->count() === 1 ? $limpios->first() : 'MIX';
    }

    private function especificaciones(Folio $folio): array
    {
        return [
            'cliente' => $folio->exportadora,
            'especie' => $folio->datos_externos['especie'] ?? null,
            'marca' => $folio->marca,
            'variedad' => $folio->variedad,
            'calibre' => $folio->calibre,
            'envase' => $folio->datos_externos['envase'] ?? null,
            'categoria' => $folio->datos_externos['categoria'] ?? null,
            'csg' => $folio->datos_externos['csg'] ?? null,
            'predio' => $folio->datos_externos['predio'] ?? null,
            'cuartel' => $folio->datos_externos['cuartel'] ?? null,
            'condicion_termica' => $folio->condicion_termica?->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $origen
     * @return array{array<int, array<string, mixed>>, array<int, array<string, mixed>>, array<int, array<string, mixed>>}
     */
    private function resolverAporteComposicion(
        Folio $folio,
        array $origen,
        int $aporte,
    ): array {
        $antes = collect($this->composicionFolio($folio))->keyBy('clave');
        $solicitada = collect($origen['composicion'] ?? []);
        if ((int) $antes->sum('cantidad_cajas') !== $this->cantidad($folio)) {
            throw new DomainException(sprintf(
                'La composición registrada del folio %s no coincide con su total de cajas. Requiere regularización.',
                $folio->numero_folio,
            ));
        }

        if ($solicitada->isEmpty()) {
            if ($antes->count() > 1 && $aporte < $this->cantidad($folio)) {
                throw new DomainException(sprintf(
                    'El folio %s posee más de un CSG o fecha. Indica cuántas cajas aporta cada composición.',
                    $folio->numero_folio,
                ));
            }

            $restante = $aporte;
            $solicitada = $antes->values()->map(function (array $linea) use (&$restante): array {
                $cantidad = min($restante, (int) $linea['cantidad_cajas']);
                $restante -= $cantidad;

                return [
                    'clave' => $linea['clave'],
                    'cantidad_aportada' => $cantidad,
                ];
            })->filter(fn (array $linea): bool => $linea['cantidad_aportada'] > 0);
        }

        if ((int) $solicitada->sum('cantidad_aportada') !== $aporte) {
            throw new DomainException(sprintf(
                'La composición seleccionada del folio %s no coincide con su aporte total.',
                $folio->numero_folio,
            ));
        }
        if ($solicitada->pluck('clave')->duplicates()->isNotEmpty()) {
            throw new DomainException(sprintf(
                'La composición del folio %s contiene líneas repetidas.',
                $folio->numero_folio,
            ));
        }

        $aportadas = collect();
        $despues = $antes->map(function (array $linea) use (
            $solicitada,
            $folio,
            $aportadas,
        ): array {
            $seleccion = $solicitada->firstWhere('clave', $linea['clave']);
            $cantidad = (int) ($seleccion['cantidad_aportada'] ?? 0);
            if ($cantidad > (int) $linea['cantidad_cajas']) {
                throw new DomainException(sprintf(
                    'La composición %s del folio %s solo dispone de %d cajas.',
                    $linea['csg'],
                    $folio->numero_folio,
                    (int) $linea['cantidad_cajas'],
                ));
            }
            if ($cantidad > 0) {
                $aportadas->push([...$linea, 'cantidad_cajas' => $cantidad]);
            }

            return [
                ...$linea,
                'cantidad_cajas' => (int) $linea['cantidad_cajas'] - $cantidad,
            ];
        });

        $desconocidas = $solicitada->pluck('clave')->diff($antes->keys());
        if ($desconocidas->isNotEmpty()) {
            throw new DomainException(
                "La composición indicada para {$folio->numero_folio} ya no se encuentra disponible.",
            );
        }

        return [
            $antes->values()->all(),
            $aportadas->values()->all(),
            $despues->filter(fn (array $linea): bool => $linea['cantidad_cajas'] > 0)
                ->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function composicionFolio(Folio $folio): array
    {
        $datos = $folio->datos_externos ?? [];
        $fechaPredeterminada = filled($datos['fecha_embalaje'] ?? null)
            ? (string) $datos['fecha_embalaje']
            : $this->fechaValidacionFolio($folio);
        $lineas = collect(app(ComposicionEtiquetaPt::class)->lineas($folio))
            ->filter(fn (mixed $linea): bool => is_array($linea)
                && array_key_exists('cantidad_cajas', $linea)
                && array_key_exists('csg', $linea))
            ->map(function (array $linea) use ($fechaPredeterminada): array {
                if (! filled($linea['fecha_embalaje'] ?? null)) {
                    $linea['fecha_embalaje'] = $fechaPredeterminada;
                }

                return $this->normalizarLineaComposicion($linea);
            });

        if ($lineas->isEmpty()) {
            $lineas->push($this->normalizarLineaComposicion([
                'origen_validacion_id' => null,
                'csg' => $datos['csg'] ?? 'SIN CSG',
                'predio' => $datos['predio'] ?? null,
                'fecha_embalaje' => $fechaPredeterminada,
                'cantidad_cajas' => $this->cantidad($folio),
            ]));
        }

        return $this->agruparComposicion($lineas)->values()->all();
    }

    private function fechaValidacionFolio(Folio $folio): ?string
    {
        $folio->loadMissing(
            'validacionPallet:id,folio_id,generado_dispositivo_at,recibido_servidor_at',
        );
        $fecha = $folio->validacionPallet?->generado_dispositivo_at
            ?? $folio->validacionPallet?->recibido_servidor_at
            ?? $folio->fecha_ingreso;

        return $fecha?->setTimezone(config('app.operational_timezone'))->toDateString();
    }

    /** @param array<string, mixed> $linea */
    private function normalizarLineaComposicion(array $linea): array
    {
        $normalizada = [
            'origen_validacion_id' => $linea['origen_validacion_id'] ?? null,
            'csg' => filled($linea['csg'] ?? null) ? trim((string) $linea['csg']) : 'SIN CSG',
            'predio' => filled($linea['predio'] ?? null) ? trim((string) $linea['predio']) : null,
            'fecha_embalaje' => filled($linea['fecha_embalaje'] ?? null)
                ? (string) $linea['fecha_embalaje']
                : null,
            'cantidad_cajas' => max(0, (int) ($linea['cantidad_cajas'] ?? 0)),
        ];
        // El lote de materia prima y el proceso de packing viajan con sus cajas a los
        // folios resultantes; sin ellos la trazabilidad se cortaría en el repaletizaje.
        foreach (['lote_materia_prima', 'proceso_packing', 'combinacion_validacion_id', 'articulo_validacion_id', 'envase_validacion_id', 'especie', 'variedad', 'envase', 'envase_codigo', 'cliente'] as $campo) {
            if (filled($linea[$campo] ?? null)) {
                $normalizada[$campo] = (string) $linea[$campo];
            }
        }
        $normalizada['clave'] = self::claveComposicion($normalizada);

        return $normalizada;
    }

    /** @param Collection<int, array<string, mixed>> $lineas */
    private function agruparComposicion(Collection $lineas): Collection
    {
        return $lineas
            ->map(fn (array $linea): array => $this->normalizarLineaComposicion($linea))
            ->groupBy(fn (array $linea): string => $linea['clave'])
            ->map(function (Collection $grupo): array {
                $primera = $grupo->first();
                $primera['cantidad_cajas'] = (int) $grupo->sum('cantidad_cajas');

                return $primera;
            })
            ->filter(fn (array $linea): bool => $linea['cantidad_cajas'] > 0)
            ->values();
    }

    /** @param array<string, mixed> $linea */
    public static function claveComposicion(array $linea): string
    {
        $partes = [
            mb_strtoupper(trim((string) ($linea['csg'] ?? ''))),
            mb_strtoupper(trim((string) ($linea['predio'] ?? ''))),
            (string) ($linea['fecha_embalaje'] ?? ''),
        ];
        // Solo se agregan cuando existen, para conservar la clave de las líneas anteriores.
        if (filled($linea['lote_materia_prima'] ?? null) || filled($linea['proceso_packing'] ?? null)) {
            $partes[] = mb_strtoupper(trim((string) ($linea['lote_materia_prima'] ?? '')));
            $partes[] = mb_strtoupper(trim((string) ($linea['proceso_packing'] ?? '')));
        }

        if (filled($linea['variedad'] ?? null) || filled($linea['envase'] ?? null)) {
            $partes[] = mb_strtoupper((string) ($linea['variedad'] ?? ''));
            $partes[] = (string) ($linea['envase_validacion_id'] ?? $linea['envase'] ?? '');
        }

        return hash('sha256', implode('|', $partes));
    }

    /** @param array<string, mixed> $datosExternos */
    private function actualizarResumenComposicion(array &$datosExternos): void
    {
        $composicion = collect($datosExternos['composicion'] ?? []);
        $datosExternos['csg'] = $this->valorComun($composicion->pluck('csg'));
        $datosExternos['csgs'] = $composicion->pluck('csg')->unique()->values()->all();
        $datosExternos['predio'] = $this->valorComun($composicion->pluck('predio'));
        $datosExternos['fecha_embalaje'] = $this->valorComunFecha($composicion);
        $datosExternos['fechas_embalaje'] = $composicion->pluck('fecha_embalaje')
            ->filter()->unique()->values()->all();
        $camposMix = collect($datosExternos['campos_mix'] ?? [])
            ->reject(fn (string $campo): bool => in_array(
                $campo,
                ['csg', 'predio', 'fecha_embalaje'],
                true,
            ));
        if ($datosExternos['csg'] === 'MIX') {
            $camposMix->push('csg');
        }
        if ($datosExternos['predio'] === 'MIX') {
            $camposMix->push('predio');
        }
        if ($composicion->pluck('fecha_embalaje')->unique()->count() > 1) {
            $camposMix->push('fecha_embalaje');
        }
        $datosExternos['campos_mix'] = $camposMix->unique()->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $composicion */
    private function valorComunFecha(Collection $composicion): ?string
    {
        $fechas = $composicion->pluck('fecha_embalaje')->unique();

        return $fechas->count() === 1 ? $fechas->first() : null;
    }

    private function cantidad(Folio $folio): int
    {
        return max(0, (int) ($folio->datos_externos['cantidad_cajas'] ?? 0));
    }

    private function actualizarFolioResultado(
        Folio $folio,
        string $tipoResultado,
        int $cantidad,
        EstadoOperacionalFolio $estado,
        HabilitacionAlmacenamientoFolio $habilitacion,
        array $especificaciones,
        string $codigo,
        array $camposMix,
        array $composicion,
        array $genealogia,
    ): void {
        $datosExternos = array_merge($folio->datos_externos ?? [], [
            'especie' => $especificaciones['especie'],
            'categoria' => $especificaciones['categoria'],
            'envase' => $especificaciones['envase'],
            'csg' => $especificaciones['csg'],
            'csgs' => collect($composicion)->pluck('csg')->unique()->values()->all(),
            'predio' => $especificaciones['predio'],
            'fecha_embalaje' => $this->valorComunFecha(collect($composicion)),
            'fechas_embalaje' => collect($composicion)->pluck('fecha_embalaje')
                ->filter()->unique()->values()->all(),
            'cuartel' => $especificaciones['cuartel'],
            'cantidad_cajas' => $cantidad,
            'repaletizaje_codigo' => $codigo,
            'campos_mix' => $camposMix,
            'composicion' => $composicion,
            'genealogia_repaletizaje' => $genealogia,
        ]);

        $folio->update([
            'tipo_bulto' => TipoBulto::from($tipoResultado),
            'estado_operacional' => $estado,
            'habilitacion_almacenamiento' => $habilitacion,
            'activo' => true,
            'variedad' => $especificaciones['variedad'],
            'calibre' => $especificaciones['calibre'],
            'marca' => $especificaciones['marca'],
            'exportadora' => $especificaciones['cliente'],
            'origen_sistema' => 'repaletizaje',
            'identificador_externo' => $codigo,
            'datos_externos' => $datosExternos,
        ]);
    }

    private function heredarAutorizacionesSag(Folio $origen, Folio $resultado): void
    {
        $origen->autorizacionesSagActivas()->get()->each(
            fn (AutorizacionSagFolio $autorizacion) => AutorizacionSagFolio::create([
                'folio_id' => $resultado->id,
                'tipo_aprobacion' => $autorizacion->tipo_aprobacion,
                'tipo_destino' => $autorizacion->tipo_destino,
                'pais_id' => $autorizacion->pais_id,
                'bloque_mercado_id' => $autorizacion->bloque_mercado_id,
                'resultado_origen_id' => $autorizacion->resultado_origen_id,
                'destino_snapshot' => $autorizacion->destino_snapshot,
                'miembros_snapshot' => $autorizacion->miembros_snapshot,
                'activa' => true,
                'aprobado_por_user_id' => $autorizacion->aprobado_por_user_id,
                'aprobado_at' => $autorizacion->aprobado_at,
            ]),
        );
    }

    /**
     * Una repa cambia qué folio ocupa cada posición: las cámaras involucradas
     * suben su versión de plano para que tablets y oficina lo vuelvan a leer.
     */
    private function refrescarPlanos(Repaletizaje $repa): void
    {
        $repa->loadMissing(['detalles', 'resultados']);
        $folioIds = $repa->detalles->pluck('folio_origen_id')
            ->merge($repa->resultados->pluck('folio_id'))
            ->push($repa->folio_resultante_id)
            ->filter()
            ->unique();
        $camaraIds = $repa->detalles
            ->map(fn (RepaletizajeDetalle $detalle): ?string => $detalle->snapshot_antes['ubicacion']['camara_id'] ?? null)
            ->merge(UbicacionActual::query()->whereIn('folio_id', $folioIds)->pluck('camara_id'))
            ->filter()
            ->unique()
            ->values();

        if ($camaraIds->isNotEmpty()) {
            Camara::query()->whereKey($camaraIds->all())->increment('version_plano');
        }
    }

    private function snapshotFolio(Folio $folio): array
    {
        $folio->loadMissing('ubicacionActual');

        return [
            'atributos' => [
                'tipo_bulto' => $folio->tipo_bulto?->value,
                'estado_operacional' => $folio->estado_operacional?->value,
                'condicion_termica' => $folio->condicion_termica?->value,
                'habilitacion_almacenamiento' => $folio->habilitacion_almacenamiento?->value,
                'activo' => $folio->activo,
                'variedad' => $folio->variedad,
                'calibre' => $folio->calibre,
                'marca' => $folio->marca,
                'exportadora' => $folio->exportadora,
                'origen_sistema' => $folio->origen_sistema,
                'identificador_externo' => $folio->identificador_externo,
                'datos_externos' => $folio->datos_externos,
                'fecha_proceso_pt' => $folio->fecha_proceso_pt?->toDateString(),
            ],
            'especificaciones' => $this->especificaciones($folio),
            'ubicacion' => $folio->ubicacionActual ? [
                'id' => $folio->ubicacionActual->id,
                'camara_id' => $folio->ubicacionActual->camara_id,
                'posicion_id' => $folio->ubicacionActual->posicion_id,
                'movimiento_id' => $folio->ubicacionActual->movimiento_id,
                'ubicado_at' => $folio->ubicacionActual->ubicado_at?->toDateTimeString(),
                'created_at' => $folio->ubicacionActual->created_at?->toDateTimeString(),
                'updated_at' => $folio->ubicacionActual->updated_at?->toDateTimeString(),
            ] : null,
        ];
    }

    private function restaurarUbicacion(Folio $folio, ?array $ubicacion): void
    {
        if (! $ubicacion) {
            return;
        }

        UbicacionActual::create([
            'id' => $ubicacion['id'],
            'folio_id' => $folio->id,
            'camara_id' => $ubicacion['camara_id'],
            'posicion_id' => $ubicacion['posicion_id'],
            'movimiento_id' => $ubicacion['movimiento_id'],
            'ubicado_at' => $ubicacion['ubicado_at'],
            'created_at' => $ubicacion['created_at'] ?? now(),
            'updated_at' => now(),
        ]);
    }

    private function siguienteCodigo(): string
    {
        $anio = (int) now()->format('Y');
        DB::table('secuencias_repaletizajes')->insertOrIgnore([
            'anio' => $anio,
            'ultimo_numero' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secuencia = DB::table('secuencias_repaletizajes')
            ->where('anio', $anio)
            ->lockForUpdate()
            ->first();
        $numero = ((int) $secuencia->ultimo_numero) + 1;
        DB::table('secuencias_repaletizajes')
            ->where('anio', $anio)
            ->update([
                'ultimo_numero' => $numero,
                'updated_at' => now(),
            ]);

        return sprintf('REPA-%d-%06d', $anio, $numero);
    }
}
