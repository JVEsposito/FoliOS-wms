<?php

namespace App\Services\MateriaPrima;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoSubloteRetornoPacking;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\EntregaFrutaProceso;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\PersonalAccessToken;
use App\Models\RetornoPacking;
use App\Models\SubloteRetornoPacking;
use App\Models\TipoResultadoPacking;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Secuencias\ServicioSecuenciaDocumento;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class ServicioRetornoPacking
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly ServicioSecuenciaDocumento $secuencias,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(
        EntregaFrutaProceso $entrega,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $payload = $this->payload($datos, $entrega->id);
        $hash = $this->hash($payload);

        try {
            return DB::transaction(function () use (
                $entrega,
                $datos,
                $usuario,
                $payload,
                $hash,
            ): LoteMateriaPrima {
                $existente = RetornoPacking::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existente) {
                    return $this->resolverRetornoRepetido(
                        $existente,
                        $entrega,
                        $payload,
                        $hash,
                    );
                }
                $this->asegurarOperacionDisponible($datos['operacion_id']);

                $entregaIds = collect($payload['entregas'])
                    ->pluck('entrega_fruta_proceso_id')
                    ->sort()
                    ->values();
                $entregas = EntregaFrutaProceso::query()
                    ->whereIn('id', $entregaIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                if ($entregas->count() !== $entregaIds->count()) {
                    throw ValidationException::withMessages([
                        'entregas' => 'Una de las entregas seleccionadas ya no existe.',
                    ]);
                }

                $loteIds = $entregas->pluck('lote_materia_prima_id')->unique()->sort()->values();
                $lotes = collect();
                foreach ($loteIds as $loteId) {
                    $lote = $this->loteActivo($loteId);
                    $lotes->put($lote->id, $lote);
                }

                foreach ($payload['entregas'] as $origen) {
                    /** @var EntregaFrutaProceso $entregaOrigen */
                    $entregaOrigen = $entregas->get($origen['entrega_fruta_proceso_id']);
                    if ($entregaOrigen->anulado_at !== null) {
                        throw new ConflictoOperacion(
                            'No se puede retornar una entrega anulada.',
                        );
                    }
                    if ($this->entregaCerrada($entregaOrigen->id)) {
                        throw new ConflictoOperacion(sprintf(
                            'El retorno del viaje %s ya fue cerrado por Packing.',
                            $entregaOrigen->numero_orden,
                        ));
                    }
                }

                /** @var EntregaFrutaProceso $entregaPrincipal */
                $entregaPrincipal = $entregas->get($entrega->id);
                $origenPrincipal = collect($payload['entregas'])->firstWhere(
                    'entrega_fruta_proceso_id',
                    $entregaPrincipal->id,
                );
                $lotePrincipal = $lotes->get($entregaPrincipal->lote_materia_prima_id);
                $tipos = $this->tiposResultado($payload['resultados']);
                $token = $usuario->currentAccessToken();
                $retorno = RetornoPacking::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $hash,
                    'numero' => sprintf(
                        'RP-%06d',
                        $this->secuencias->reservarSiguiente('retornos_packing'),
                    ),
                    'entrega_fruta_proceso_id' => $entregaPrincipal->id,
                    'cierra_entrega' => (bool) $origenPrincipal['cierra_entrega'],
                    'observacion' => $payload['observacion'],
                    'registrado_por_user_id' => $usuario->id,
                    'dispositivo_id' => $token instanceof PersonalAccessToken
                        ? $token->dispositivo_id
                        : null,
                    'registrado_at' => now(),
                ]);
                $retorno->entregas()->attach(
                    collect($payload['entregas'])->mapWithKeys(
                        fn (array $origen): array => [
                            $origen['entrega_fruta_proceso_id'] => [
                                'cierra_entrega' => (bool) $origen['cierra_entrega'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ],
                        ],
                    )->all(),
                );

                $detalleEvento = [];
                foreach ($payload['resultados'] as $resultado) {
                    $tipo = $tipos->get($resultado['tipo_resultado_packing_id']);
                    $nombre = $this->nombreResultado($tipo, $resultado);
                    $sublote = SubloteRetornoPacking::create([
                        'retorno_packing_id' => $retorno->id,
                        'tipo_resultado_packing_id' => $tipo->id,
                        'numero_sublote' => sprintf(
                            '%s-%06d',
                            strtoupper($tipo->prefijo_sublote),
                            $this->secuencias->reservarSiguiente('sublotes_packing'),
                        ),
                        'nombre_resultado' => $nombre,
                        'cantidad_bins' => $resultado['cantidad_bins'],
                        'kilos_netos' => $resultado['kilos_netos'],
                        'estado' => EstadoSubloteRetornoPacking::PendienteUbicacion,
                    ]);
                    $detalleEvento[] = [
                        'sublote_id' => $sublote->id,
                        'numero_sublote' => $sublote->numero_sublote,
                        'resultado' => $nombre,
                        'cantidad_bins' => $sublote->cantidad_bins,
                        'kilos_netos' => $sublote->kilos_netos,
                    ];
                }

                $this->registrarEvento(
                    $lotePrincipal,
                    'retorno_packing_registrado',
                    $usuario,
                    $datos['operacion_id'],
                    [
                        'retorno_id' => $retorno->id,
                        'numero' => $retorno->numero,
                        'entrega_id' => $entregaPrincipal->id,
                        'cierra_entrega' => $retorno->cierra_entrega,
                        'entregas' => collect($payload['entregas'])->map(
                            function (array $origen) use ($entregas, $lotes): array {
                                /** @var EntregaFrutaProceso $entregaOrigen */
                                $entregaOrigen = $entregas->get(
                                    $origen['entrega_fruta_proceso_id'],
                                );
                                $loteOrigen = $lotes->get(
                                    $entregaOrigen->lote_materia_prima_id,
                                );

                                return [
                                    'entrega_id' => $entregaOrigen->id,
                                    'lote_id' => $loteOrigen->id,
                                    'numero_lote' => $loteOrigen->numero_lote,
                                    'numero_orden' => $entregaOrigen->numero_orden,
                                    'cierra_entrega' => (bool) $origen['cierra_entrega'],
                                ];
                            },
                        )->values()->all(),
                        'resultados' => $detalleEvento,
                    ],
                );

                return $this->cargarLote($lotePrincipal);
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $existente = RetornoPacking::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if (! $existente) {
                throw $excepcion;
            }

            return $this->resolverRetornoRepetido(
                $existente,
                $entrega,
                $payload,
                $hash,
            );
        }
    }

    public function ubicar(
        SubloteRetornoPacking $sublote,
        string $operacionId,
        string $camaraId,
        ?string $observacion,
        User $usuario,
    ): LoteMateriaPrima {
        return DB::transaction(function () use (
            $sublote,
            $operacionId,
            $camaraId,
            $observacion,
            $usuario,
        ): LoteMateriaPrima {
            $sublote = SubloteRetornoPacking::query()
                ->lockForUpdate()
                ->findOrFail($sublote->id);
            $retorno = RetornoPacking::query()
                ->lockForUpdate()
                ->findOrFail($sublote->retorno_packing_id);
            $entrega = EntregaFrutaProceso::query()
                ->lockForUpdate()
                ->findOrFail($retorno->entrega_fruta_proceso_id);
            $lote = $this->loteActivo($entrega->lote_materia_prima_id);

            if ($sublote->operacion_ubicacion_id !== null) {
                if ($sublote->operacion_ubicacion_id !== $operacionId) {
                    throw new ConflictoOperacion('El sublote ya fue ubicado con otra operación.');
                }

                return $this->cargarLote($lote);
            }
            $this->asegurarOperacionDisponible($operacionId);
            if ($retorno->anulado_at !== null
                || $sublote->estado !== EstadoSubloteRetornoPacking::PendienteUbicacion) {
                throw new ConflictoOperacion('El sublote ya no se encuentra pendiente de ubicación.');
            }

            $camara = Camara::query()
                ->where('contenido', ContenidoCamara::MateriaPrima->value)
                ->where('estado', EstadoCamara::Activa->value)
                ->lockForUpdate()
                ->find($camaraId);
            if (! $camara) {
                throw ValidationException::withMessages([
                    'camara_id' => 'Selecciona una cámara activa exclusiva de materia prima.',
                ]);
            }

            $token = $usuario->currentAccessToken();
            $sublote->update([
                'estado' => EstadoSubloteRetornoPacking::UbicadoCamara,
                'camara_id' => $camara->id,
                'operacion_ubicacion_id' => $operacionId,
                'ubicado_por_user_id' => $usuario->id,
                'dispositivo_ubicacion_id' => $token instanceof PersonalAccessToken
                    ? $token->dispositivo_id
                    : null,
                'ubicado_at' => now(),
                'observacion_ubicacion' => filled($observacion)
                    ? trim((string) $observacion)
                    : null,
            ]);
            $this->registrarEvento(
                $lote,
                'sublote_packing_ubicado',
                $usuario,
                $operacionId,
                [
                    'retorno_id' => $retorno->id,
                    'sublote_id' => $sublote->id,
                    'numero_sublote' => $sublote->numero_sublote,
                    'camara_id' => $camara->id,
                    'camara_codigo' => $camara->codigo,
                ],
            );

            return $this->cargarLote($lote);
        }, attempts: 3);
    }

    public function anular(
        RetornoPacking $retorno,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): LoteMateriaPrima {
        return DB::transaction(function () use (
            $retorno,
            $operacionId,
            $motivo,
            $usuario,
        ): LoteMateriaPrima {
            $retorno = RetornoPacking::query()
                ->with(['entregas.lote', 'resultados'])
                ->lockForUpdate()
                ->findOrFail($retorno->id);
            $entregaPrincipal = $retorno->entregas->firstWhere(
                'id',
                $retorno->entrega_fruta_proceso_id,
            ) ?? EntregaFrutaProceso::query()
                ->lockForUpdate()
                ->findOrFail($retorno->entrega_fruta_proceso_id);
            $lote = $this->loteActivo($entregaPrincipal->lote_materia_prima_id);

            if ($retorno->anulado_at !== null) {
                if ($retorno->operacion_anulacion_id !== $operacionId) {
                    throw new ConflictoOperacion('El retorno ya fue anulado con otra operación.');
                }

                return $this->cargarLote($lote);
            }
            $this->asegurarOperacionDisponible($operacionId);
            if (! $this->puedeAnular($retorno, $usuario)) {
                abort(403, 'No puedes anular este retorno o uno de sus sublotes ya fue ubicado.');
            }

            $retorno->update([
                'operacion_anulacion_id' => $operacionId,
                'anulado_por_user_id' => $usuario->id,
                'anulado_at' => now(),
                'motivo_anulacion' => trim($motivo),
            ]);
            $retorno->resultados()->update([
                'estado' => EstadoSubloteRetornoPacking::Anulado->value,
                'updated_at' => now(),
            ]);
            $this->registrarEvento(
                $lote,
                'retorno_packing_anulado',
                $usuario,
                $operacionId,
                [
                    'retorno_id' => $retorno->id,
                    'numero' => $retorno->numero,
                    'entrega_id' => $entregaPrincipal->id,
                    'entregas' => $retorno->entregas->map(
                        fn (EntregaFrutaProceso $entrega): array => [
                            'entrega_id' => $entrega->id,
                            'lote_id' => $entrega->lote_materia_prima_id,
                            'numero_orden' => $entrega->numero_orden,
                            'cierra_entrega' => (bool) $entrega->pivot->cierra_entrega,
                        ],
                    )->values()->all(),
                    'motivo' => trim($motivo),
                ],
            );

            return $this->cargarLote($lote);
        }, attempts: 3);
    }

    public function puedeRegistrar(EntregaFrutaProceso $entrega, User $usuario): bool
    {
        return $entrega->anulado_at === null
            && $this->alcance->puedeEntregarFrutaProceso($usuario)
            && ! $entrega->retornos
                ->whereNull('anulado_at')
                ->contains(fn (RetornoPacking $retorno): bool => (bool) (
                    $retorno->pivot?->cierra_entrega ?? $retorno->cierra_entrega
                ));
    }

    public function puedeUbicar(SubloteRetornoPacking $sublote, User $usuario): bool
    {
        return $this->alcance->puedeEntregarFrutaProceso($usuario)
            && $sublote->estado === EstadoSubloteRetornoPacking::PendienteUbicacion
            && $sublote->retorno?->anulado_at === null;
    }

    public function puedeAnular(
        RetornoPacking $retorno,
        User $usuario,
        ?string $ultimoRetornoVigenteId = null,
    ): bool {
        if ($retorno->anulado_at !== null
            || $retorno->resultados->contains(
                fn (SubloteRetornoPacking $sublote): bool => (
                    $sublote->estado === EstadoSubloteRetornoPacking::UbicadoCamara
                ),
            )) {
            return false;
        }
        if ($this->alcance->puedeCorregirEntregasFrutaProceso($usuario)) {
            return true;
        }
        if (! $this->alcance->puedeEntregarFrutaProceso($usuario)
            || $retorno->registrado_por_user_id !== $usuario->id) {
            return false;
        }

        $entregaIds = $retorno->relationLoaded('entregas')
            ? $retorno->entregas->pluck('id')
            : $retorno->entregas()->pluck('entregas_fruta_proceso.id');
        if ($entregaIds->isEmpty()) {
            $entregaIds = collect([$retorno->entrega_fruta_proceso_id]);
        }

        foreach ($entregaIds as $indice => $entregaId) {
            $ultimoId = $entregaIds->count() === 1 && $indice === 0
                ? $ultimoRetornoVigenteId
                : null;
            $ultimoId ??= RetornoPacking::query()
                ->whereHas('entregas', fn ($consulta) => $consulta
                    ->where('entregas_fruta_proceso.id', $entregaId))
                ->whereNull('retornos_packing.anulado_at')
                ->latest('retornos_packing.registrado_at')
                ->latest('retornos_packing.created_at')
                ->latest('retornos_packing.id')
                ->value('retornos_packing.id');
            if ($ultimoId !== $retorno->id) {
                return false;
            }
        }

        return true;
    }

    public function entregaTieneRetornos(EntregaFrutaProceso $entrega): bool
    {
        return $entrega->retornos()
            ->whereNull('retornos_packing.anulado_at')
            ->exists();
    }

    /** @param array<int, array<string, mixed>> $resultados */
    private function tiposResultado(array $resultados): Collection
    {
        $ids = collect($resultados)
            ->pluck('tipo_resultado_packing_id')
            ->unique()
            ->values();
        $tipos = TipoResultadoPacking::query()
            ->whereIn('id', $ids)
            ->where('activo', true)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($tipos->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'resultados' => 'Uno de los tipos de resultado ya no está disponible.',
            ]);
        }

        return $tipos;
    }

    /** @param array<string, mixed> $resultado */
    private function nombreResultado(
        TipoResultadoPacking $tipo,
        array $resultado,
    ): string {
        $personalizado = filled($resultado['nombre_resultado'] ?? null)
            ? trim((string) $resultado['nombre_resultado'])
            : null;
        if ($tipo->codigo === 'otro' && $personalizado === null) {
            throw ValidationException::withMessages([
                'resultados' => 'Especifica el nombre cuando el resultado es Otro.',
            ]);
        }

        return $personalizado ?? $tipo->nombre;
    }

    /** @param array<string, mixed> $payload */
    private function resolverRetornoRepetido(
        RetornoPacking $retorno,
        EntregaFrutaProceso $entrega,
        array $payload,
        string $hash,
    ): LoteMateriaPrima {
        $coincide = hash_equals($retorno->payload_hash, $hash);
        if (! $coincide && count($payload['entregas']) === 1) {
            $origen = $payload['entregas'][0];
            $coincide = hash_equals($retorno->payload_hash, $this->hash([
                'cierra_entrega' => (bool) $origen['cierra_entrega'],
                'observacion' => $payload['observacion'],
                'resultados' => $payload['resultados'],
            ]));
        }
        if ($retorno->entrega_fruta_proceso_id !== $entrega->id || ! $coincide) {
            throw new ConflictoOperacion(
                'El identificador de retorno ya fue utilizado con datos diferentes.',
            );
        }

        return $this->cargarLote($retorno->entrega->lote);
    }

    private function entregaCerrada(string $entregaId): bool
    {
        return DB::table('retorno_packing_entregas as origen')
            ->join('retornos_packing as retorno', 'retorno.id', '=', 'origen.retorno_packing_id')
            ->where('origen.entrega_fruta_proceso_id', $entregaId)
            ->where('origen.cierra_entrega', true)
            ->whereNull('retorno.anulado_at')
            ->lockForUpdate()
            ->exists();
    }

    private function asegurarOperacionDisponible(string $operacionId): void
    {
        if (EventoLoteMateriaPrima::query()
            ->where('operacion_id', $operacionId)
            ->lockForUpdate()
            ->exists()) {
            throw new ConflictoOperacion(
                'El identificador ya fue utilizado con otra operación de materia prima.',
            );
        }
    }

    private function loteActivo(string $loteId): LoteMateriaPrima
    {
        return LoteMateriaPrima::query()
            ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
            ->lockForUpdate()
            ->findOrFail($loteId);
    }

    /** @param array<string, mixed> $datos */
    private function payload(array $datos, string $entregaPrincipalId): array
    {
        $entregas = collect($datos['entregas'] ?? [])
            ->map(fn (array $origen): array => [
                'entrega_fruta_proceso_id' => (string) $origen['entrega_fruta_proceso_id'],
                'cierra_entrega' => (bool) $origen['cierra_entrega'],
            ]);
        if ($entregas->isEmpty()) {
            $entregas = collect([[
                'entrega_fruta_proceso_id' => $entregaPrincipalId,
                'cierra_entrega' => (bool) $datos['cierra_entrega'],
            ]]);
        }
        if (! $entregas->contains(
            fn (array $origen): bool => (
                $origen['entrega_fruta_proceso_id'] === $entregaPrincipalId
            ),
        )) {
            throw ValidationException::withMessages([
                'entregas' => 'El retorno debe incluir el viaje desde el que fue abierto.',
            ]);
        }

        return [
            'entregas' => $entregas
                ->sortBy('entrega_fruta_proceso_id')
                ->values()
                ->all(),
            'observacion' => filled($datos['observacion'] ?? null)
                ? trim((string) $datos['observacion'])
                : null,
            'resultados' => collect($datos['resultados'])
                ->map(fn (array $resultado): array => [
                    'tipo_resultado_packing_id' => $resultado['tipo_resultado_packing_id'],
                    'nombre_resultado' => filled($resultado['nombre_resultado'] ?? null)
                        ? trim((string) $resultado['nombre_resultado'])
                        : null,
                    'cantidad_bins' => (int) $resultado['cantidad_bins'],
                    'kilos_netos' => filled($resultado['kilos_netos'] ?? null)
                        ? round((float) $resultado['kilos_netos'], 3)
                        : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'payload' => 'El retorno contiene datos que no pueden procesarse.',
            ]);
        }
    }

    /** @param array<string, mixed> $datos */
    private function registrarEvento(
        LoteMateriaPrima $lote,
        string $tipo,
        User $usuario,
        string $operacionId,
        array $datos,
    ): void {
        EventoLoteMateriaPrima::create([
            'lote_materia_prima_id' => $lote->id,
            'operacion_id' => $operacionId,
            'tipo' => $tipo,
            'estado_anterior' => $lote->estado->value,
            'estado_nuevo' => $lote->estado->value,
            'user_id' => $usuario->id,
            'ocurrido_at' => now(),
            'datos' => $datos,
        ]);
    }

    private function cargarLote(LoteMateriaPrima $lote): LoteMateriaPrima
    {
        return $lote->fresh([
            'temporada',
            'cliente',
            'recepcion',
            'asignacionCamara.camara',
            'entregasProceso.entregadoPor',
            'entregasProceso.anuladoPor',
            'entregasProceso.dispositivo',
            'entregasProceso.retornos.registradoPor',
            'entregasProceso.retornos.anuladoPor',
            'entregasProceso.retornos.dispositivo',
            'entregasProceso.retornos.entregas.lote',
            'entregasProceso.retornos.resultados.tipoResultado',
            'entregasProceso.retornos.resultados.camara',
            'entregasProceso.retornos.resultados.ubicadoPor',
            'entregasProceso.retornos.resultados.dispositivoUbicacion',
        ]);
    }
}
