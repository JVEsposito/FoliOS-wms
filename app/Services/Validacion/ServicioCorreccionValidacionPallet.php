<?php

namespace App\Services\Validacion;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Exceptions\ConflictoOperacion;
use App\Models\CorreccionValidacionPallet;
use App\Models\Folio;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use DomainException;
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

            $temporada = DB::table('temporadas')
                ->where('id', $validacionBloqueada->temporada_id)
                ->lockForUpdate()
                ->first();
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

            $anteriores = $this->datosAuditables($validacionBloqueada, $folio);
            $snapshot = $validacionBloqueada->snapshot ?? [];
            $composicionAnterior = collect($snapshot['composicion'] ?? []);
            $composicion = array_key_exists('composicion', $payload)
                ? $this->composicionSolicitada($validacionBloqueada, $articulo, $payload, $snapshot, $composicionAnterior)
                : null;
            if ($composicion === null && $composicionAnterior->count() > 1
                && ($payload['origen_validacion_id'] !== $validacionBloqueada->origen_validacion_id
                    || $payload['cantidad_cajas'] !== $validacionBloqueada->cantidad_cajas)) {
                throw new DomainException(
                    'Este bulto posee varios CSG. La corrección simple no puede cambiar su origen ni su total de cajas.',
                );
            }
            $combinacionesComposicion = $composicion === null && $composicionAnterior->count() > 1
                ? DB::table('combinaciones_validacion')
                    ->where('temporada_id', $validacionBloqueada->temporada_id)
                    ->where('articulo_validacion_id', $articulo->id)
                    ->whereIn(
                        'origen_validacion_id',
                        $composicionAnterior->pluck('origen_validacion_id'),
                    )
                    ->where('activo', true)
                    ->get()
                    ->keyBy('origen_validacion_id')
                : collect();
            if ($composicion === null && $composicionAnterior->count() > 1
                && $combinacionesComposicion->count()
                    !== $composicionAnterior->pluck('origen_validacion_id')->unique()->count()) {
                throw new DomainException(
                    'El artículo corregido no está habilitado para todos los CSG del bulto.',
                );
            }
            $composicion ??= $composicionAnterior->count() > 1
                ? $composicionAnterior->map(function (array $linea) use (
                    $combinacionesComposicion,
                ): array {
                    $linea['combinacion_validacion_id'] = $combinacionesComposicion
                        ->get($linea['origen_validacion_id'])->id;

                    return $linea;
                })
                : collect([[
                    'origen_validacion_id' => $origen->id,
                    'combinacion_validacion_id' => $combinacion->id,
                    'csg' => $origen->csg,
                    'predio' => $origen->predio,
                    'fecha_embalaje' => $snapshot['fecha_embalaje'] ?? null,
                    'cantidad_cajas' => $payload['cantidad_cajas'],
                    // La corrección simple no cambia la trazabilidad a materia prima.
                    ...collect($composicionAnterior->first() ?? [])
                        ->only(['lote_materia_prima', 'proceso_packing'])
                        ->all(),
                ]]);
            $composicion = $composicion->map(fn (array $linea): array => [
                ...$linea, 'articulo_validacion_id' => $articulo->id,
                'envase_validacion_id' => $articulo->envase_validacion_id,
                'especie' => $articulo->especie, 'variedad' => $articulo->variedad, 'envase' => $articulo->envase,
                'cliente' => $origen->cliente,
            ]);
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
                'calibre' => $articulo->calibre,
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
            $snapshot['ultima_correccion'] = [
                'operacion_id' => $datos['operacion_id'],
                'motivo' => $payload['motivo_correccion'],
                'corregido_por_user_id' => $usuario->id,
                'corregido_at' => now()->toAtomString(),
            ];

            $validacionBloqueada->forceFill([
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
                'tipo_bulto' => $payload['tipo_bulto'],
                'variedad' => $articulo->variedad,
                'calibre' => $articulo->calibre,
                'marca' => $origen->marca,
                'exportadora' => $origen->cliente,
                'datos_externos' => [
                    ...$datosExternos,
                    'especie' => $articulo->especie,
                    'categoria' => $categoria->nombre,
                    'envase' => $articulo->envase,
                    'csg' => $csgResumen,
                    'csgs' => $composicion->pluck('csg')->unique()->values()->all(),
                    'predio' => $predioResumen,
                    'composicion' => $composicion->values()->all(),
                    'cantidad_cajas' => $payload['cantidad_cajas'],
                    'combinacion_validacion_id' => $combinacion->id,
                ],
            ])->save();

            $nuevos = $this->datosAuditables(
                $validacionBloqueada->refresh(),
                $folio->refresh(),
            );

            CorreccionValidacionPallet::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $payloadHash,
                'validacion_pallet_id' => $validacionBloqueada->id,
                'folio_id' => $folio->id,
                'corregido_por_user_id' => $usuario->id,
                'datos_anteriores' => $anteriores,
                'datos_nuevos' => $nuevos,
                'motivo' => $payload['motivo_correccion'],
                'corregido_at' => now(),
            ]);

            app(ServicioConciliacionValidacion::class)->registrar($validacionBloqueada->refresh(), 'correccion_autorizada');

            return $this->cargar($validacionBloqueada->refresh());
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
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
        return [
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
        $lineas = collect($payload['composicion']);
        $origenIds = $lineas->pluck('origen_validacion_id')->unique()->values();
        $origenes = DB::table('origenes_validacion')
            ->whereIn('id', $origenIds)
            ->where('temporada_id', $validacion->temporada_id)
            ->where('activo', true)
            ->get()
            ->keyBy('id');
        if ($origenes->count() !== $origenIds->count()) {
            throw new DomainException('Uno de los CSG no pertenece al catálogo activo de la temporada.');
        }
        $clave = fn (mixed $valor): string => mb_strtoupper(trim((string) $valor));
        if ($origenes->pluck('cliente')->map($clave)->unique()->count() !== 1
            || $origenes->pluck('marca')->map($clave)->unique()->count() !== 1) {
            throw new DomainException('Todos los CSG del bulto deben pertenecer al mismo cliente y marca.');
        }
        $combinaciones = DB::table('combinaciones_validacion')
            ->where('temporada_id', $validacion->temporada_id)
            ->where('articulo_validacion_id', $articulo->id)
            ->whereIn('origen_validacion_id', $origenIds)
            ->where('activo', true)
            ->get()
            ->keyBy('origen_validacion_id');
        if ($combinaciones->count() !== $origenIds->count()) {
            throw new DomainException('El artículo corregido no está habilitado para todos los CSG del bulto.');
        }
        if ($articulo->variedad_validacion_id !== null) {
            foreach ($origenes as $origen) {
                if ($origen->csg_validacion_id !== null
                    && ! DB::table('csg_variedades_validacion')
                        ->where('csg_validacion_id', $origen->csg_validacion_id)
                        ->where('variedad_validacion_id', $articulo->variedad_validacion_id)
                        ->exists()) {
                    throw new DomainException("La variedad {$articulo->variedad} no está autorizada en el CSG {$origen->csg}.");
                }
            }
        }
        $total = (int) $lineas->sum('cantidad_cajas');
        if ($total !== $payload['cantidad_cajas']) {
            throw new DomainException(sprintf(
                'La composición por CSG suma %d cajas y el bulto declara %d.',
                $total,
                $payload['cantidad_cajas'],
            ));
        }
        $fechaEmbalaje = $snapshot['fecha_embalaje'] ?? ($composicionAnterior->first()['fecha_embalaje'] ?? null);

        return $lineas->map(function (array $linea) use ($origenes, $combinaciones, $fechaEmbalaje): array {
            $origen = $origenes->get($linea['origen_validacion_id']);

            return [
                'origen_validacion_id' => $origen->id,
                'combinacion_validacion_id' => $combinaciones->get($origen->id)->id,
                'csg' => $origen->csg,
                'predio' => $origen->predio,
                'fecha_embalaje' => $fechaEmbalaje,
                'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                ...(isset($linea['lote_materia_prima']) ? ['lote_materia_prima' => $linea['lote_materia_prima']] : []),
                ...(isset($linea['proceso_packing']) ? ['proceso_packing' => $linea['proceso_packing']] : []),
            ];
        })->values();
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
                'tipo_bulto' => $validacion->tipo_bulto,
                'cantidad_cajas' => $validacion->cantidad_cajas,
                'linea_proceso' => $validacion->linea_proceso,
                'turno' => $validacion->turno,
                'articulo_validacion_id' => $validacion->articulo_validacion_id,
                'origen_validacion_id' => $validacion->origen_validacion_id,
                'categoria_validacion_id' => $validacion->categoria_validacion_id,
                'catalogo_version_servidor' => $validacion->catalogo_version_servidor,
                'snapshot' => $validacion->snapshot,
            ],
            'folio' => [
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
