<?php

namespace App\Services\MateriaPrima;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\AsignacionCamaraLoteMateriaPrima;
use App\Models\EntregaFrutaProceso;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class ServicioFrutaProceso
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(
        LoteMateriaPrima $lote,
        array $datos,
        User $usuario,
    ): LoteMateriaPrima {
        $payload = $this->payload($datos);
        $hash = $this->hash($payload);

        try {
            return DB::transaction(function () use ($lote, $datos, $usuario, $hash): LoteMateriaPrima {
                $existente = EntregaFrutaProceso::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->first();
                if ($existente) {
                    return $this->resolverEntregaRepetida($existente, $lote, $hash);
                }
                if (EventoLoteMateriaPrima::query()
                    ->where('operacion_id', $datos['operacion_id'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ConflictoOperacion(
                        'El identificador de entrega ya fue utilizado con otra operación.',
                    );
                }

                $lote = LoteMateriaPrima::query()
                    ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->lockForUpdate()
                    ->findOrFail($lote->id);
                if (! in_array($lote->estado, [
                    EstadoLoteMateriaPrima::DisponibleProceso,
                    EstadoLoteMateriaPrima::AsignadoCamara,
                    EstadoLoteMateriaPrima::EntregaParcialProceso,
                ], true)) {
                    throw new ConflictoOperacion(
                        'El lote debe estar disponible desde Hidrocooler o cámara y mantener bins disponibles.',
                    );
                }
                if ($lote->envase_primario !== TipoEnvaseRomana::Bins) {
                    throw ValidationException::withMessages([
                        'lote' => 'Fruta a proceso solo admite lotes cuyo envase primario sea bins.',
                    ]);
                }

                $asignacion = AsignacionCamaraLoteMateriaPrima::query()
                    ->where('lote_materia_prima_id', $lote->id)
                    ->lockForUpdate()
                    ->first();
                $esSalidaDirecta = ! $asignacion
                    && $lote->hidrocooler()
                        ->where('destino_salida', 'proceso')
                        ->exists();
                if (! $asignacion && ! $esSalidaDirecta) {
                    throw new ConflictoOperacion(
                        'El lote no posee una asignación vigente a cámara ni una salida directa aprobada.',
                    );
                }

                $entregados = $this->cantidadEntregada($lote);
                $total = $lote->cantidad_envases_primarios;
                $disponibles = max(0, $total - $entregados);
                $cantidad = (int) $datos['cantidad_envases'];
                if ($cantidad > $disponibles) {
                    throw ValidationException::withMessages([
                        'cantidad_envases' => "Solo quedan {$disponibles} bins disponibles en el lote.",
                    ]);
                }

                $saldoPosterior = $disponibles - $cantidad;
                $token = $usuario->currentAccessToken();
                $entrega = EntregaFrutaProceso::create([
                    'operacion_id' => $datos['operacion_id'],
                    'payload_hash' => $hash,
                    'lote_materia_prima_id' => $lote->id,
                    'asignacion_camara_lote_id' => $asignacion?->id,
                    'camara_id' => $asignacion?->camara_id,
                    'cantidad_envases' => $cantidad,
                    'kilos_enviados' => filled($datos['kilos_enviados'] ?? null)
                        ? round((float) $datos['kilos_enviados'], 3)
                        : null,
                    'saldo_anterior' => $disponibles,
                    'saldo_posterior' => $saldoPosterior,
                    'linea_proceso' => trim((string) $datos['linea_proceso']),
                    'turno' => strtoupper(trim((string) $datos['turno'])),
                    'numero_orden' => trim((string) $datos['numero_orden']),
                    'observacion' => filled($datos['observacion'] ?? null)
                        ? trim((string) $datos['observacion'])
                        : null,
                    'entregado_por_user_id' => $usuario->id,
                    'dispositivo_id' => $token instanceof PersonalAccessToken
                        ? $token->dispositivo_id
                        : null,
                    'entregado_at' => now(),
                ]);

                $estadoAnterior = $lote->estado;
                $estadoNuevo = $saldoPosterior === 0
                    ? EstadoLoteMateriaPrima::EntregadoProceso
                    : EstadoLoteMateriaPrima::EntregaParcialProceso;
                $lote->update([
                    'estado' => $estadoNuevo,
                    'version' => $lote->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                $this->registrarEvento(
                    $lote,
                    'fruta_entregada_proceso',
                    $usuario,
                    $datos['operacion_id'],
                    $estadoAnterior,
                    $estadoNuevo,
                    [
                        'entrega_id' => $entrega->id,
                        'cantidad_envases' => $cantidad,
                        'kilos_enviados' => $entrega->kilos_enviados,
                        'saldo_anterior' => $disponibles,
                        'saldo_posterior' => $saldoPosterior,
                        'linea_proceso' => $entrega->linea_proceso,
                        'turno' => $entrega->turno,
                        'numero_orden' => $entrega->numero_orden,
                        'camara_id' => $asignacion?->camara_id,
                        'origen_operacional' => $esSalidaDirecta
                            ? 'hidrocooler_directo'
                            : 'camara_materia_prima',
                    ],
                );

                return $this->cargarLote($lote);
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $existente = EntregaFrutaProceso::query()
                ->where('operacion_id', $datos['operacion_id'])
                ->first();
            if (! $existente) {
                throw $excepcion;
            }

            return $this->resolverEntregaRepetida($existente, $lote, $hash);
        }
    }

    public function anular(
        EntregaFrutaProceso $entrega,
        string $operacionId,
        string $motivo,
        User $usuario,
    ): LoteMateriaPrima {
        try {
            return DB::transaction(function () use (
                $entrega,
                $operacionId,
                $motivo,
                $usuario,
            ): LoteMateriaPrima {
                $entrega = EntregaFrutaProceso::query()
                    ->lockForUpdate()
                    ->findOrFail($entrega->id);
                $lote = LoteMateriaPrima::query()
                    ->whereHas('temporada', fn ($consulta) => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->lockForUpdate()
                    ->findOrFail($entrega->lote_materia_prima_id);

                if ($entrega->anulado_at !== null) {
                    if ($entrega->operacion_anulacion_id !== $operacionId) {
                        throw new ConflictoOperacion('La entrega ya fue anulada con otra operación.');
                    }

                    return $this->cargarLote($lote);
                }
                $operacionUsada = EntregaFrutaProceso::query()
                    ->where('operacion_anulacion_id', $operacionId)
                    ->where('id', '!=', $entrega->id)
                    ->exists();
                if ($operacionUsada) {
                    throw new ConflictoOperacion(
                        'El identificador de anulación ya fue utilizado en otra entrega.',
                    );
                }
                if (EventoLoteMateriaPrima::query()
                    ->where('operacion_id', $operacionId)
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ConflictoOperacion(
                        'El identificador de anulación ya fue utilizado con otra operación.',
                    );
                }

                $this->asegurarPuedeAnular($entrega, $lote, $usuario);
                $estadoAnterior = $lote->estado;
                $entrega->update([
                    'operacion_anulacion_id' => $operacionId,
                    'anulado_por_user_id' => $usuario->id,
                    'anulado_at' => now(),
                    'motivo_anulacion' => trim($motivo),
                ]);

                $entregados = $this->cantidadEntregada($lote);
                $tieneCamara = AsignacionCamaraLoteMateriaPrima::query()
                    ->where('lote_materia_prima_id', $lote->id)
                    ->exists();
                $estadoNuevo = $entregados === 0
                    ? ($tieneCamara
                        ? EstadoLoteMateriaPrima::AsignadoCamara
                        : EstadoLoteMateriaPrima::DisponibleProceso)
                    : EstadoLoteMateriaPrima::EntregaParcialProceso;
                $lote->update([
                    'estado' => $estadoNuevo,
                    'version' => $lote->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
                $this->registrarEvento(
                    $lote,
                    'entrega_fruta_proceso_anulada',
                    $usuario,
                    $operacionId,
                    $estadoAnterior,
                    $estadoNuevo,
                    [
                        'entrega_id' => $entrega->id,
                        'cantidad_envases' => $entrega->cantidad_envases,
                        'motivo' => trim($motivo),
                        'cantidad_entregada_vigente' => $entregados,
                        'saldo_vigente' => $lote->cantidad_envases_primarios - $entregados,
                    ],
                );

                return $this->cargarLote($lote);
            }, attempts: 3);
        } catch (QueryException $excepcion) {
            $existente = EntregaFrutaProceso::query()
                ->where('operacion_anulacion_id', $operacionId)
                ->first();
            if (! $existente) {
                throw $excepcion;
            }
            if ($existente->id !== $entrega->id) {
                throw new ConflictoOperacion(
                    'El identificador de anulación ya fue utilizado en otra entrega.',
                );
            }

            return $this->cargarLote($existente->lote);
        }
    }

    public function puedeAnular(
        EntregaFrutaProceso $entrega,
        LoteMateriaPrima $lote,
        User $usuario,
        ?string $ultimaEntregaVigenteId = null,
    ): bool {
        if ($entrega->anulado_at !== null) {
            return false;
        }
        if ($entrega->retornos()->whereNull('anulado_at')->exists()) {
            return false;
        }
        if ($this->alcance->puedeCorregirEntregasFrutaProceso($usuario)) {
            return true;
        }
        if (! $this->alcance->puedeEntregarFrutaProceso($usuario)
            || $entrega->entregado_por_user_id !== $usuario->id
            || $lote->estado === EstadoLoteMateriaPrima::EntregadoProceso) {
            return false;
        }

        $ultimaEntregaVigenteId ??= EntregaFrutaProceso::query()
            ->where('lote_materia_prima_id', $lote->id)
            ->whereNull('anulado_at')
            ->latest('entregado_at')
            ->latest('created_at')
            ->latest('id')
            ->value('id');

        return $ultimaEntregaVigenteId === $entrega->id;
    }

    private function asegurarPuedeAnular(
        EntregaFrutaProceso $entrega,
        LoteMateriaPrima $lote,
        User $usuario,
    ): void {
        if (! $this->puedeAnular($entrega, $lote, $usuario)) {
            abort(403, 'No puedes anular esta entrega. Debe ser tu último viaje abierto y no puede tener retornos de Packing.');
        }
    }

    private function cantidadEntregada(LoteMateriaPrima $lote): int
    {
        return (int) EntregaFrutaProceso::query()
            ->where('lote_materia_prima_id', $lote->id)
            ->whereNull('anulado_at')
            ->sum('cantidad_envases');
    }

    private function resolverEntregaRepetida(
        EntregaFrutaProceso $entrega,
        LoteMateriaPrima $lote,
        string $hash,
    ): LoteMateriaPrima {
        if ($entrega->lote_materia_prima_id !== $lote->id
            || ! hash_equals($entrega->payload_hash, $hash)) {
            throw new ConflictoOperacion(
                'El identificador de entrega ya fue utilizado con datos diferentes.',
            );
        }

        return $this->cargarLote($entrega->lote);
    }

    /** @param array<string, mixed> $datos */
    private function payload(array $datos): array
    {
        return [
            'cantidad_envases' => (int) $datos['cantidad_envases'],
            'kilos_enviados' => filled($datos['kilos_enviados'] ?? null)
                ? round((float) $datos['kilos_enviados'], 3)
                : null,
            'linea_proceso' => trim((string) $datos['linea_proceso']),
            'turno' => strtoupper(trim((string) $datos['turno'])),
            'numero_orden' => trim((string) $datos['numero_orden']),
            'observacion' => filled($datos['observacion'] ?? null)
                ? trim((string) $datos['observacion'])
                : null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'payload' => 'La entrega contiene datos que no pueden procesarse.',
            ]);
        }
    }

    /** @param array<string, mixed> $datos */
    private function registrarEvento(
        LoteMateriaPrima $lote,
        string $tipo,
        User $usuario,
        string $operacionId,
        EstadoLoteMateriaPrima $estadoAnterior,
        EstadoLoteMateriaPrima $estadoNuevo,
        array $datos,
    ): void {
        EventoLoteMateriaPrima::create([
            'lote_materia_prima_id' => $lote->id,
            'operacion_id' => $operacionId,
            'tipo' => $tipo,
            'estado_anterior' => $estadoAnterior->value,
            'estado_nuevo' => $estadoNuevo->value,
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
            'entregasProceso.retornos.resultados.tipoResultado',
            'entregasProceso.retornos.resultados.camara',
            'entregasProceso.retornos.resultados.ubicadoPor',
            'entregasProceso.retornos.resultados.dispositivoUbicacion',
        ]);
    }
}
