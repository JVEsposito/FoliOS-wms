<?php

namespace App\Services\Validacion;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\CorreccionValidacionPallet;
use App\Models\Folio;
use App\Models\PartidaNacionalPt;
use App\Models\RepaletizajeDetalle;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioCorreccionValidacionPallet
{
    public function __construct(
        private readonly MotorTransicionesOperacionales $motorTransiciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function corregir(
        ValidacionPallet $validacion,
        array $datos,
        User $usuario,
    ): ValidacionPallet {
        $payload = $this->normalizarPayload($datos);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'validacion.corregir',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            sujetoTipo: ValidacionPallet::class,
            sujetoId: (string) $validacion->id,
            referencia: $validacion->numero_folio,
        );
        $accion = function () use (
            $validacion,
            $datos,
            $payload,
            $payloadHash,
            $usuario,
        ): ValidacionPallet {
            $correccionExistente = CorreccionValidacionPallet::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($correccionExistente) {
                if ($correccionExistente->validacion_pallet_id !== $validacion->id
                    || $correccionExistente->corregido_por_user_id !== $usuario->id
                    || ! hash_equals($correccionExistente->payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de la corrección ya fue utilizado con datos diferentes.',
                    );
                }

                return $this->cargar(
                    ValidacionPallet::query()->findOrFail($validacion->id),
                );
            }

            $validacionBloqueada = ValidacionPallet::query()
                ->whereKey($validacion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $folio = $validacionBloqueada->folio_id
                ? Folio::query()
                    ->whereKey($validacionBloqueada->folio_id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $this->asegurarCorregible($validacionBloqueada, $folio);
            $anteriores = $this->datosAuditables($validacionBloqueada, $folio);
            $snapshot = $validacionBloqueada->snapshot ?? [];
            $snapshot['ultima_correccion'] = [
                'operacion_id' => $datos['operacion_id'],
                'motivo' => $payload['motivo_correccion'],
                'corregido_por_user_id' => $usuario->id,
                'corregido_at' => now()->toAtomString(),
            ];

            if ($payload['solo_fechas'] ?? false) {
                $this->aplicarFechas($validacionBloqueada, $folio, $payload, $snapshot);

                return $this->auditar($validacionBloqueada, $folio, $usuario, $datos['operacion_id'], $payloadHash, $payload['motivo_correccion'], $anteriores);
            }

            if (PartidaNacionalPt::query()->where('folio_origen_id', $folio->id)->lockForUpdate()->exists()) {
                throw new ConflictoOperacion('El folio tiene cajas separadas para mercado nacional. La corrección de producto podría reponer cajas ya retiradas.');
            }

            $temporada = DB::table('temporadas')
                ->where('id', $validacionBloqueada->temporada_id)
                ->lockForUpdate()
                ->first();
            $numeroFolio = $payload['numero_folio'] ?? $validacionBloqueada->numero_folio;
            $numeroIntento = $validacionBloqueada->numero_intento;
            $cambiaNumero = $numeroFolio !== $validacionBloqueada->numero_folio;
            if ($cambiaNumero) {
                if (! $this->puedeCambiarNumeroFolio($folio)) {
                    throw new ConflictoOperacion('Solo se puede cambiar el número de un folio activo que siga en la planta (no despachado, anulado ni agotado).');
                }
                $numeroIntento = $this->reservarIntentoParaNuevoNumero($folio, $numeroFolio);
            }
            $articulo = DB::table('articulos_validacion')
                ->where('id', $datos['articulo_validacion_id'])
                ->where('temporada_id', $validacionBloqueada->temporada_id)
                ->where('activo', true)
                ->first();
            $origen = DB::table('origenes_validacion')
                ->where('id', $payload['origen_validacion_id'])
                ->where('temporada_id', $validacionBloqueada->temporada_id)
                ->where('activo', true)
                ->first();
            $categoria = DB::table('categorias_validacion')
                ->where('id', $datos['categoria_validacion_id'])
                ->where('temporada_id', $validacionBloqueada->temporada_id)
                ->where('activo', true)
                ->first();

            if (! $temporada || ! $articulo || ! $origen || ! $categoria) {
                throw new DomainException(
                    'El artículo, el origen o la categoría no pertenecen al catálogo activo de la temporada.',
                );
            }

            $combinacion = DB::table('combinaciones_validacion')
                ->where('temporada_id', $validacionBloqueada->temporada_id)
                ->where('articulo_validacion_id', $articulo->id)
                ->where('origen_validacion_id', $origen->id)
                ->where('activo', true)
                ->first();

            if (! $combinacion) {
                throw new DomainException(
                    'La combinación de artículo y origen no se encuentra habilitada.',
                );
            }

            $composicionAnterior = collect($snapshot['composicion'] ?? []);
            if (! array_key_exists('composicion', $payload)) {
                if ($composicionAnterior->count() > 1
                    && ($payload['origen_validacion_id'] !== $validacionBloqueada->origen_validacion_id
                        || $payload['cantidad_cajas'] !== $validacionBloqueada->cantidad_cajas)) {
                    throw new DomainException('Este bulto posee varios CSG. La corrección simple no puede cambiar su origen ni su total de cajas.');
                }
                $mixto = $composicionAnterior->pluck('articulo_validacion_id')->filter()->unique()->count() > 1;
                if ($mixto && $articulo->id !== $validacionBloqueada->articulo_validacion_id) {
                    throw new DomainException('Para cambiar el producto de un pallet con varios calibres, envía el detalle de cada línea.');
                }
                $payload['composicion'] = $composicionAnterior->count() > 1
                    ? $composicionAnterior->map(fn (array $linea): array => [
                        ...$linea,
                        'articulo_validacion_id' => $mixto ? $linea['articulo_validacion_id'] : $articulo->id,
                    ])->all()
                    : [[
                        ...collect($composicionAnterior->first() ?? [])->only(['lote_materia_prima', 'proceso_packing'])->all(),
                        'origen_validacion_id' => $origen->id,
                        'cantidad_cajas' => $payload['cantidad_cajas'],
                    ]];
            }
            $composicion = $this->composicionSolicitada($validacionBloqueada, $articulo, $payload, $snapshot, $composicionAnterior);
            $calibreResumen = ComposicionCalibresValidacion::resumen($composicion);
            $csgResumen = $composicion->pluck('csg')->unique()->count() === 1
                ? $composicion->first()['csg']
                : 'MIX';
            $predioResumen = $composicion->pluck('predio')->map(
                fn (mixed $valor): string => mb_strtoupper(trim((string) $valor)),
            )->unique()->count() === 1
                ? $composicion->first()['predio']
                : 'MIX';
            $snapshot['articulo'] = [
                'especie' => $articulo->especie,
                'variedad' => $articulo->variedad,
                'calibre' => $calibreResumen,
                'envase' => $articulo->envase,
            ];
            $snapshot['origen'] = [
                'cliente' => $origen->cliente,
                'marca' => $origen->marca,
                'csg' => $csgResumen,
                'predio' => $predioResumen,
            ];
            $snapshot['composicion'] = $composicion->values()->all();
            $snapshot['categoria'] = [
                'id' => $categoria->id,
                'nombre' => $categoria->nombre,
                'codigo_externo' => $categoria->codigo_externo,
            ];
            $snapshot['jornada'] = [
                'linea_proceso' => $payload['linea_proceso'],
                'turno' => $payload['turno'],
            ];
            $snapshot['combinacion'] = [
                'id' => $combinacion->id,
                'codigo_externo' => $combinacion->codigo_externo,
            ];
            $validacionBloqueada->forceFill([
                'numero_folio' => $numeroFolio,
                'numero_intento' => $numeroIntento,
                'tipo_bulto' => $payload['tipo_bulto'],
                'cantidad_cajas' => $payload['cantidad_cajas'],
                'linea_proceso' => $payload['linea_proceso'],
                'turno' => $payload['turno'],
                'articulo_validacion_id' => $articulo->id,
                'origen_validacion_id' => $origen->id,
                'categoria_validacion_id' => $categoria->id,
                'catalogo_version_servidor' => (int) $temporada->version_catalogo,
                'snapshot' => $snapshot,
            ])->save();

            $datosExternos = $folio->datos_externos ?? [];
            $folio->forceFill([
                ...($cambiaNumero ? ['numero_folio' => $numeroFolio] : []),
                'tipo_bulto' => $payload['tipo_bulto'],
                'variedad' => $articulo->variedad,
                'calibre' => $calibreResumen,
                'marca' => $origen->marca,
                'exportadora' => $origen->cliente,
                'datos_externos' => [
                    ...$datosExternos,
                    'especie' => $articulo->especie,
                    'categoria' => $categoria->nombre,
                    'envase' => $articulo->envase,
                    'csg' => $csgResumen,
                    'csgs' => $composicion->pluck('csg')->unique()->values()->all(),
                    'calibres' => $composicion->pluck('calibre')->unique()->values()->all(),
                    'predio' => $predioResumen,
                    'composicion' => $composicion->values()->all(),
                    'cantidad_cajas' => $payload['cantidad_cajas'],
                    'combinacion_validacion_id' => $combinacion->id,
                ],
            ]);

            try {
                $folio->save();
            } catch (UniqueConstraintViolationException $error) {
                if (! $folio->isDirty('numero_folio')) {
                    throw $error;
                }

                throw new ConflictoOperacion('El número de folio indicado ya está registrado.');
            }

            if (isset($payload['fecha_hora_validacion']) || isset($payload['fecha_embalaje'])) {
                $this->aplicarFechas($validacionBloqueada, $folio, $payload, $snapshot);
            }
            if ($folio->tipo_bulto === TipoBulto::Saldo) {
                // Corregido a saldo: el planificador suelta la salida de túnel o el ingreso que tuviera pendiente.
                $folioId = $folio->id;
                DB::afterCommit(fn () => app(ServicioDepuracionIngresosCamara::class)->depurarFolio($folioId, $usuario));
            }

            return $this->auditar($validacionBloqueada, $folio, $usuario, $datos['operacion_id'], $payloadHash, $payload['motivo_correccion'], $anteriores);
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    /**
     * El número se corrige mientras el bulto siga en la planta, también después de prefrío:
     * todo lo operativo apunta al folio por su id, y el número anterior queda en la auditoría
     * de la corrección y en la copia de la validación. Hay que reimprimir la etiqueta.
     */
    public function puedeCambiarNumeroFolio(Folio $folio): bool
    {
        return $folio->activo && ! in_array($folio->estado_operacional, [
            EstadoOperacionalFolio::Despachado,
            EstadoOperacionalFolio::Anulado,
            EstadoOperacionalFolio::RetiradoDefinitivo,
            EstadoOperacionalFolio::Agotado,
        ], true);
    }

    private function reservarIntentoParaNuevoNumero(Folio $folio, string $numeroFolio): int
    {
        // Usa el mismo bloqueo que el registro de Validación PT. El contador anterior
        // conserva sus intentos, incluso si se registra otro bulto con ese número.
        $ahora = now();
        DB::table('secuencias_validacion_folio')->insertOrIgnore([
            'numero_folio' => $numeroFolio,
            'ultimo_intento' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        $secuencia = DB::table('secuencias_validacion_folio')
            ->where('numero_folio', $numeroFolio)
            ->lockForUpdate()
            ->first();

        $folioOcupado = Folio::query()->where('numero_folio', $numeroFolio)
            ->whereKeyNot($folio->id)->lockForUpdate()->first(['id']);
        $decisionPrevia = ValidacionPallet::query()->where('numero_folio', $numeroFolio)
            ->where('estado', EstadoValidacionPallet::Aceptada)
            ->whereIn('resultado', [ResultadoValidacionPallet::Aprobado, ResultadoValidacionPallet::Rechazado])
            ->lockForUpdate()->first(['id']);
        if ($folioOcupado || $decisionPrevia) {
            throw new ConflictoOperacion('El número de folio indicado ya está registrado o tiene una validación definitiva.');
        }

        $intento = ((int) $secuencia->ultimo_intento) + 1;
        DB::table('secuencias_validacion_folio')->where('numero_folio', $numeroFolio)
            ->update(['ultimo_intento' => $intento, 'updated_at' => $ahora]);

        return $intento;
    }

    private function aplicarFechas(ValidacionPallet $validacion, Folio $folio, array $payload, array $snapshot): void
    {
        $zona = config('app.operational_timezone');
        $validadaAt = $validacion->generado_dispositivo_at;
        if (isset($payload['fecha_hora_validacion'])) {
            $horaLocal = $payload['fecha_hora_validacion'];
            $validadaAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $horaLocal, $zona);
            if ($validadaAt->format('Y-m-d\TH:i') !== $horaLocal || $validadaAt->gt($validacion->recibido_servidor_at)) {
                throw new DomainException('La hora de validación debe ser válida en la zona de la planta y anterior o igual a la recepción del servidor.');
            }
        }

        $fechaAnterior = $snapshot['fecha_embalaje'] ?? null;
        $fecha = $payload['fecha_embalaje'] ?? $fechaAnterior;
        $limite = min(
            $validadaAt->copy()->setTimezone($zona)->toDateString(),
            $validacion->recibido_servidor_at->copy()->setTimezone($zona)->toDateString(),
        );
        if ($fecha !== null && $fecha > $limite) {
            throw new DomainException("La fecha de embalaje debe ser {$limite} o anterior. Corrige también la fecha de embalaje.");
        }

        if ($fecha !== $fechaAnterior) {
            // Una repa confirmada conserva la composición usada al armarla. Su anulación
            // devuelve los orígenes a ese punto y permite corregirlos sin romper la genealogía.
            $detalle = RepaletizajeDetalle::query()->where('folio_origen_id', $folio->id)
                ->whereHas('repaletizaje', fn ($query) => $query->where('estado', 'confirmado'))
                ->with('repaletizaje:id,codigo')
                ->first();
            if ($detalle) {
                throw new ConflictoOperacion("Este folio participó en {$detalle->repaletizaje->codigo}. Anula ese repaletizaje antes de corregir el embalaje.");
            }

            $snapshot['fecha_embalaje'] = $fecha;
            if (isset($snapshot['composicion'])) {
                $snapshot['composicion'] = $this->fecharComposicion($snapshot['composicion'], $fecha);
            }
            $externos = $folio->datos_externos ?? [];
            $externos['fecha_embalaje'] = $fecha;
            $externos['fechas_embalaje'] = [$fecha];
            if (isset($externos['composicion'])) {
                $externos['composicion'] = $this->fecharComposicion($externos['composicion'], $fecha);
            }
            $folio->forceFill(['datos_externos' => $externos])->save();
        }

        $validacion->forceFill(['generado_dispositivo_at' => $validadaAt->copy()->utc(), 'snapshot' => $snapshot])->save();
    }

    private function fecharComposicion(array $lineas, string $fecha): array
    {
        return array_map(fn (array $linea): array => [...$linea, 'fecha_embalaje' => $fecha], $lineas);
    }

    private function auditar(
        ValidacionPallet $validacion,
        Folio $folio,
        User $usuario,
        string $operacionId,
        string $payloadHash,
        string $motivo,
        array $anteriores,
    ): ValidacionPallet {
        $nuevos = $this->datosAuditables($validacion->refresh(), $folio->refresh());

        CorreccionValidacionPallet::create([
            'operacion_id' => $operacionId,
            'payload_hash' => $payloadHash,
            'validacion_pallet_id' => $validacion->id,
            'folio_id' => $folio->id,
            'corregido_por_user_id' => $usuario->id,
            'datos_anteriores' => $anteriores,
            'datos_nuevos' => $nuevos,
            'motivo' => $motivo,
            'corregido_at' => now(),
        ]);

        app(ServicioConciliacionValidacion::class)->registrar($validacion, 'correccion_autorizada');

        return $this->cargar($validacion->refresh());
    }

    private function asegurarCorregible(
        ValidacionPallet $validacion,
        ?Folio $folio,
    ): void {
        if ($validacion->estado !== EstadoValidacionPallet::Aceptada
            || $validacion->resultado !== ResultadoValidacionPallet::Aprobado
            || ! $folio) {
            throw new ConflictoOperacion(
                'Solo se pueden corregir validaciones aprobadas que conserven su folio asociado.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function normalizarPayload(array $datos): array
    {
        $fechas = array_intersect_key($datos, array_flip(['fecha_hora_validacion', 'fecha_embalaje']));
        if ($datos['solo_fechas'] ?? false) {
            return ['solo_fechas' => true, 'motivo_correccion' => $datos['motivo_correccion'], ...$fechas];
        }

        return [
            ...(isset($datos['numero_folio']) ? ['numero_folio' => $datos['numero_folio']] : []),
            ...$fechas,
            'tipo_bulto' => $datos['tipo_bulto'],
            'cantidad_cajas' => (int) $datos['cantidad_cajas'],
            'linea_proceso' => (int) $datos['linea_proceso'],
            'turno' => $datos['turno'],
            'articulo_validacion_id' => $datos['articulo_validacion_id'],
            // Con composición, el origen principal es el CSG de la primera línea.
            'origen_validacion_id' => isset($datos['composicion'])
                ? $datos['composicion'][0]['origen_validacion_id']
                : $datos['origen_validacion_id'],
            'categoria_validacion_id' => $datos['categoria_validacion_id'],
            'motivo_correccion' => $datos['motivo_correccion'],
            // Solo si viene: así el hash de las correcciones de un CSG no cambia.
            ...(isset($datos['composicion']) ? ['composicion' => collect($datos['composicion'])
                ->map(fn (array $linea): array => array_filter([
                    'articulo_validacion_id' => $linea['articulo_validacion_id'] ?? null,
                    'origen_validacion_id' => $linea['origen_validacion_id'],
                    'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                    'lote_materia_prima' => ProyeccionTrazabilidadFolio::normalizarCodigo($linea['lote_materia_prima'] ?? null),
                    'proceso_packing' => ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso_packing'] ?? null),
                ], fn (mixed $valor): bool => $valor !== null))
                ->values()->all()] : []),
        ];
    }

    /**
     * Reemplaza la composición completa del bulto con las mismas reglas que la PDA: CSG del
     * catálogo activo de la temporada, un solo cliente y marca, artículo habilitado y variedad
     * autorizada en cada CSG, y la suma de cajas igual al total del bulto. La fecha de
     * embalaje se conserva.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $snapshot
     * @return Collection<int, array<string, mixed>>
     */
    private function composicionSolicitada(
        ValidacionPallet $validacion,
        object $articulo,
        array $payload,
        array $snapshot,
        Collection $composicionAnterior,
    ): Collection {
        $fecha = $snapshot['fecha_embalaje'] ?? ($composicionAnterior->first()['fecha_embalaje'] ?? null);

        try {
            return app(ComposicionCalibresValidacion::class)->preparar(
                $payload['composicion'], $articulo, $validacion->temporada_id, $payload['cantidad_cajas'], $fecha,
            );
        } catch (DomainException $error) {
            if ($error->getMessage() === 'Una de las combinaciones de artículo y CSG no se encuentra habilitada.') {
                throw new DomainException('El artículo corregido no está habilitado para todos los CSG del bulto.');
            }

            throw $error;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function datosAuditables(
        ValidacionPallet $validacion,
        Folio $folio,
    ): array {
        return [
            'validacion' => [
                'numero_folio' => $validacion->numero_folio,
                'numero_intento' => $validacion->numero_intento,
                'tipo_bulto' => $validacion->tipo_bulto,
                'cantidad_cajas' => $validacion->cantidad_cajas,
                'linea_proceso' => $validacion->linea_proceso,
                'turno' => $validacion->turno,
                'articulo_validacion_id' => $validacion->articulo_validacion_id,
                'origen_validacion_id' => $validacion->origen_validacion_id,
                'categoria_validacion_id' => $validacion->categoria_validacion_id,
                'catalogo_version_servidor' => $validacion->catalogo_version_servidor,
                'generado_dispositivo_at' => $validacion->generado_dispositivo_at?->toAtomString(),
                'snapshot' => $validacion->snapshot,
            ],
            'folio' => [
                'numero_folio' => $folio->numero_folio,
                'activo' => $folio->activo,
                'estado_operacional' => $folio->estado_operacional->value,
                'condicion_termica' => $folio->condicion_termica->value,
                'tipo_bulto' => $folio->tipo_bulto->value,
                'variedad' => $folio->variedad,
                'calibre' => $folio->calibre,
                'marca' => $folio->marca,
                'exportadora' => $folio->exportadora,
                'datos_externos' => $folio->datos_externos,
            ],
        ];
    }

    private function cargar(ValidacionPallet $validacion): ValidacionPallet
    {
        return $validacion->load([
            'temporada:id,codigo,nombre,activa',
            'folio:id,numero_folio,estado_operacional,condicion_termica,activo',
            'usuario:id,name',
            'dispositivo:id,codigo,nombre',
            'conflictoCon:id,numero_folio,numero_intento,resultado',
            'correcciones.corregidoPor:id,name',
        ]);
    }
}
