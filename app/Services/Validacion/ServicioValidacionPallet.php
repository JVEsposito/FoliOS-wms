<?php

namespace App\Services\Validacion;

use App\Enums\CondicionTermicaFolio;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioValidacionPallet
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ProteccionFolioAnulado $proteccionFolioAnulado,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     * @return array{ValidacionPallet, bool, bool}
     */
    public function registrar(array $datos, User $usuario, Dispositivo $dispositivo): array
    {
        $payload = $this->normalizarPayload($datos);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Validacion,
            tipo: 'pallet.registrar',
            usuario: $usuario,
            payload: $payload,
            operacionId: $datos['operacion_id'],
            dispositivo: $dispositivo,
            sujetoTipo: ValidacionPallet::class,
            referencia: $payload['numero_folio'],
            ocurridoAt: CarbonImmutable::parse($datos['generado_dispositivo_at']),
        );
        $accion = function () use ($datos, $payload, $payloadHash, $usuario, $dispositivo): array {
            $existente = ValidacionPallet::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->lockForUpdate()
                ->first();

            if ($existente) {
                if ($existente->user_id !== $usuario->id
                    || $existente->dispositivo_id !== $dispositivo->id
                    || ! hash_equals($existente->payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion('El UUID de la validación ya fue utilizado con datos diferentes.');
                }

                return [$this->cargar($existente), false, $existente->estado === EstadoValidacionPallet::Conflicto];
            }

            $resultado = ResultadoValidacionPallet::from($datos['resultado']);
            if ($resultado === ResultadoValidacionPallet::Rechazado
                && ! $this->alcance->puedeRechazarPallets($usuario)) {
                throw new OperacionNoAutorizada(
                    'El rechazo definitivo requiere supervisor de frío o administrador.',
                );
            }

            $temporada = app(ServicioTemporadaActiva::class)->buscar(bloquear: true);
            if (! $temporada || $temporada->id !== $datos['temporada_id']) {
                throw new DomainException('La temporada no existe o no se encuentra activa.');
            }

            $articulo = DB::table('articulos_validacion')
                ->where('id', $datos['articulo_validacion_id'])
                ->where('temporada_id', $temporada->id)
                ->where('activo', true)
                ->first();
            $composicionSolicitada = collect($payload['composicion']);
            $origenIds = $composicionSolicitada
                ->pluck('origen_validacion_id')
                ->unique()
                ->values();
            $origenes = DB::table('origenes_validacion')
                ->whereIn('id', $origenIds)
                ->where('temporada_id', $temporada->id)
                ->where('activo', true)
                ->get()
                ->keyBy('id');
            $categoria = DB::table('categorias_validacion')
                ->where('id', $datos['categoria_validacion_id'])
                ->where('temporada_id', $temporada->id)
                ->where('activo', true)
                ->first();

            if (! $articulo || $origenes->count() !== $origenIds->count() || ! $categoria) {
                throw new DomainException('El artículo, el origen o la categoría no pertenecen al catálogo activo de la temporada.');
            }

            $clientes = $origenes->pluck('cliente')->map(
                fn (mixed $valor): string => mb_strtoupper(trim((string) $valor)),
            )->unique();
            $marcas = $origenes->pluck('marca')->map(
                fn (mixed $valor): string => mb_strtoupper(trim((string) $valor)),
            )->unique();
            if ($clientes->count() !== 1 || $marcas->count() !== 1) {
                throw new DomainException(
                    'Todos los CSG del bulto deben pertenecer al mismo cliente y marca.',
                );
            }

            $combinaciones = DB::table('combinaciones_validacion')
                ->where('temporada_id', $temporada->id)
                ->where('articulo_validacion_id', $articulo->id)
                ->whereIn('origen_validacion_id', $origenIds)
                ->where('activo', true)
                ->get()
                ->keyBy('origen_validacion_id');

            if ($combinaciones->count() !== $origenIds->count()) {
                throw new DomainException(
                    'Una de las combinaciones de artículo y CSG no se encuentra habilitada.',
                );
            }

            // La proyección puede quedar anticuada entre la descarga del catálogo y el registro.
            // Verificamos la asociación vigente en los maestros antes de aceptar el pallet.
            if ($articulo->variedad_validacion_id !== null) {
                foreach ($origenes as $origenValidacion) {
                    if ($origenValidacion->csg_validacion_id !== null
                        && ! DB::table('csg_variedades_validacion')
                            ->where('csg_validacion_id', $origenValidacion->csg_validacion_id)
                            ->where('variedad_validacion_id', $articulo->variedad_validacion_id)
                            ->exists()) {
                        throw new DomainException('La variedad seleccionada no está asociada al CSG elegido.');
                    }
                }
            }

            $totalComposicion = (int) $composicionSolicitada->sum('cantidad_cajas');
            if ($totalComposicion !== (int) $datos['cantidad_cajas']) {
                throw new DomainException(sprintf(
                    'La composición por CSG suma %d cajas y el bulto declara %d.',
                    $totalComposicion,
                    (int) $datos['cantidad_cajas'],
                ));
            }

            $composicion = $composicionSolicitada->map(function (array $linea) use (
                $origenes,
                $combinaciones,
                $payload,
            ): array {
                $origen = $origenes->get($linea['origen_validacion_id']);
                $combinacion = $combinaciones->get($linea['origen_validacion_id']);
                $lote = $linea['lote_materia_prima'] ?? null;
                $proceso = $linea['proceso_packing'] ?? null;

                return [
                    'origen_validacion_id' => $origen->id,
                    'combinacion_validacion_id' => $combinacion->id,
                    'csg' => $origen->csg,
                    'predio' => $origen->predio,
                    'fecha_embalaje' => $payload['fecha_embalaje'],
                    'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                    ...($lote !== null ? ['lote_materia_prima' => $lote] : []),
                    ...($proceso !== null ? ['proceso_packing' => $proceso] : []),
                ];
            })->values();
            $origen = $origenes->get($composicion->first()['origen_validacion_id']);
            $combinacion = $combinaciones->get($origen->id);
            $csgResumen = $this->valorComun($composicion->pluck('csg'));
            $predioResumen = $this->valorComun($composicion->pluck('predio'));

            $numeroFolio = $payload['numero_folio'];
            DB::table('secuencias_validacion_folio')->insertOrIgnore([
                'numero_folio' => $numeroFolio,
                'ultimo_intento' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $secuencia = DB::table('secuencias_validacion_folio')
                ->where('numero_folio', $numeroFolio)
                ->lockForUpdate()
                ->first();
            $numeroIntento = ((int) $secuencia->ultimo_intento) + 1;
            DB::table('secuencias_validacion_folio')
                ->where('numero_folio', $numeroFolio)
                ->update(['ultimo_intento' => $numeroIntento, 'updated_at' => now()]);

            $folioExistente = Folio::query()
                ->where('numero_folio', $numeroFolio)
                ->lockForUpdate()
                ->first();
            $folioLiberadoPorAnulacion = $folioExistente !== null
                && $this->proteccionFolioAnulado->esAnuladoPorValidacion($folioExistente);
            $decisionFinalPrevia = ValidacionPallet::query()
                ->where('numero_folio', $numeroFolio)
                ->whereIn('resultado', [
                    ResultadoValidacionPallet::Aprobado->value,
                    ResultadoValidacionPallet::Rechazado->value,
                ])
                ->where('estado', EstadoValidacionPallet::Aceptada->value)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();
            $hayConflicto = ($folioExistente !== null && ! $folioLiberadoPorAnulacion)
                || $decisionFinalPrevia !== null;

            $snapshot = [
                'temporada' => ['codigo' => $temporada->codigo, 'nombre' => $temporada->nombre],
                'articulo' => [
                    'especie' => $articulo->especie,
                    'variedad' => $articulo->variedad,
                    'calibre' => $articulo->calibre,
                    'envase' => $articulo->envase,
                ],
                'origen' => [
                    'cliente' => $origen->cliente,
                    'marca' => $origen->marca,
                    'csg' => $csgResumen,
                    'predio' => $predioResumen,
                ],
                'fecha_embalaje' => $payload['fecha_embalaje'],
                'composicion' => $composicion->all(),
                'categoria' => [
                    'id' => $categoria->id,
                    'nombre' => $categoria->nombre,
                    'codigo_externo' => $categoria->codigo_externo,
                ],
                'jornada' => [
                    'linea_proceso' => (int) $datos['linea_proceso'],
                    'turno' => $datos['turno'],
                ],
                'combinacion' => [
                    'id' => $combinacion->id,
                    'codigo_externo' => $combinacion->codigo_externo,
                ],
                'payload' => $payload,
            ];

            $validacion = ValidacionPallet::create([
                'operacion_id' => $datos['operacion_id'],
                'payload_hash' => $payloadHash,
                'numero_folio' => $numeroFolio,
                'numero_intento' => $numeroIntento,
                'tipo_bulto' => $datos['tipo_bulto'],
                'cantidad_cajas' => $datos['cantidad_cajas'],
                'linea_proceso' => $datos['linea_proceso'],
                'turno' => $datos['turno'],
                'temporada_id' => $temporada->id,
                'articulo_validacion_id' => $articulo->id,
                'origen_validacion_id' => $origen->id,
                'categoria_validacion_id' => $categoria->id,
                'resultado' => $resultado,
                'estado' => $hayConflicto ? EstadoValidacionPallet::Conflicto : EstadoValidacionPallet::Aceptada,
                'motivo' => $datos['motivo'] ?? null,
                'observacion' => $datos['observacion'] ?? null,
                'catalogo_version_dispositivo' => $datos['catalogo_version'],
                'catalogo_version_servidor' => $temporada->version_catalogo,
                'snapshot' => $snapshot,
                'user_id' => $usuario->id,
                'dispositivo_id' => $dispositivo->id,
                'validacion_conflicto_id' => $decisionFinalPrevia?->id,
                'generado_dispositivo_at' => CarbonImmutable::parse($datos['generado_dispositivo_at']),
                'recibido_servidor_at' => now(),
            ]);

            if ($resultado === ResultadoValidacionPallet::Aprobado && ! $hayConflicto) {
                $atributosFolio = [
                    'temporada_id' => $temporada->id,
                    'numero_folio' => $numeroFolio,
                    'tipo_bulto' => TipoBulto::from($datos['tipo_bulto']),
                    'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
                    'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
                    'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
                    'fecha_ingreso' => now(),
                    'activo' => true,
                    'variedad' => $articulo->variedad,
                    'calibre' => $articulo->calibre,
                    'marca' => $origen->marca,
                    'exportadora' => $origen->cliente,
                    'origen_sistema' => 'validacion',
                    'identificador_externo' => $datos['operacion_id'],
                    'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
                    'datos_externos' => [
                        'especie' => $articulo->especie,
                        'categoria' => $categoria->nombre,
                        'envase' => $articulo->envase,
                        'csg' => $csgResumen,
                        'csgs' => $composicion->pluck('csg')->unique()->values()->all(),
                        'predio' => $predioResumen,
                        'fecha_embalaje' => $payload['fecha_embalaje'],
                        'fechas_embalaje' => $payload['fecha_embalaje']
                            ? [$payload['fecha_embalaje']]
                            : [],
                        'composicion' => $composicion->all(),
                        'cantidad_cajas' => $datos['cantidad_cajas'],
                        'validacion_id' => $validacion->id,
                        'combinacion_validacion_id' => $combinacion->id,
                    ],
                ];
                $folio = $folioLiberadoPorAnulacion
                    ? $this->proteccionFolioAnulado->reactivarDesdeNuevaValidacion(
                        $folioExistente,
                        $atributosFolio,
                    )
                    : Folio::create($atributosFolio);
                $validacion->update(['folio_id' => $folio->id]);
            }

            return [$this->cargar($validacion->refresh()), true, $hayConflicto];
        };

        return $this->motorTransiciones->ejecutar($comando, $accion);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function normalizarPayload(array $datos): array
    {
        $generadoDispositivoAt = CarbonImmutable::parse($datos['generado_dispositivo_at']);

        return [
            'numero_folio' => mb_strtoupper(trim((string) $datos['numero_folio'])),
            'tipo_bulto' => $datos['tipo_bulto'],
            'cantidad_cajas' => (int) $datos['cantidad_cajas'],
            'linea_proceso' => (int) $datos['linea_proceso'],
            'turno' => $datos['turno'],
            'temporada_id' => $datos['temporada_id'],
            'catalogo_version' => (int) $datos['catalogo_version'],
            'articulo_validacion_id' => $datos['articulo_validacion_id'],
            'origen_validacion_id' => $datos['origen_validacion_id'],
            'fecha_embalaje' => filled($datos['fecha_embalaje'] ?? null)
                ? (string) $datos['fecha_embalaje']
                : $generadoDispositivoAt->setTimezone(config('app.operational_timezone'))->toDateString(),
            'composicion' => collect($datos['composicion'] ?? [[
                'origen_validacion_id' => $datos['origen_validacion_id'],
                'cantidad_cajas' => (int) $datos['cantidad_cajas'],
            ]])->map(fn (array $linea): array => array_filter([
                'origen_validacion_id' => $linea['origen_validacion_id'],
                'cantidad_cajas' => (int) $linea['cantidad_cajas'],
                // Solo se incluyen si vienen informados: así el hash de las PDA anteriores
                // no cambia y sus reintentos siguen siendo idempotentes.
                'lote_materia_prima' => ProyeccionTrazabilidadFolio::normalizarCodigo($linea['lote_materia_prima'] ?? null),
                'proceso_packing' => ProyeccionTrazabilidadFolio::normalizarCodigo($linea['proceso_packing'] ?? null),
            ], fn (mixed $valor): bool => $valor !== null))->values()->all(),
            'categoria_validacion_id' => $datos['categoria_validacion_id'],
            'resultado' => $datos['resultado'],
            'motivo' => $datos['motivo'] ?? null,
            'observacion' => $datos['observacion'] ?? null,
            'generado_dispositivo_at' => $generadoDispositivoAt->toAtomString(),
        ];
    }

    private function cargar(ValidacionPallet $validacion): ValidacionPallet
    {
        return $validacion->load([
            'folio:id,numero_folio,estado_operacional,condicion_termica,habilitacion_almacenamiento,activo',
            'usuario:id,name',
            'dispositivo:id,codigo,nombre',
            'conflictoCon:id,numero_folio,numero_intento,resultado',
        ]);
    }

    private function valorComun(Collection $valores): ?string
    {
        $limpios = $valores->map(
            fn (mixed $valor): ?string => filled($valor) ? trim((string) $valor) : null,
        );
        $unicos = $limpios
            ->map(fn (?string $valor): string => mb_strtoupper((string) $valor))
            ->unique();

        return $unicos->count() === 1 ? $limpios->first() : 'MIX';
    }
}
