<?php

namespace App\Services\Planificador;

use App\Enums\EstadoTareaMovimiento;
use App\Jobs\EjecutarRecalculoPendientePlanificador;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\RetencionOperacionalFolio;
use App\Models\User;
use App\Services\Camaras\ServicioDesocupacionProgramada;
use App\Services\Camaras\ServicioOportunidadReordenamiento;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Retenciones\ServicioPlanSegregacionRetenidos;
use App\Services\Validacion\ServicioPrioridadBufferRepaletizaje;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ServicioRecalculosPendientesPlanificador
{
    public const MAX_INTENTOS_FALLIDOS = 5;

    public const CARGA = 'concentracion_carga';

    public const UBICACION = 'concentracion_movimiento';

    public const SEGREGACION = 'segregacion_movimiento';

    public const REORDENAMIENTO = 'reordenamiento_movimiento';

    public const DESOCUPACION = 'desocupacion_movimiento';

    public const BUFFER_REPA = 'prioridad_buffer_repa';

    private const TABLA = 'recalculos_pendientes_planificador';

    /** @return list<string> */
    public static function tipos(): array
    {
        return [
            self::CARGA,
            self::UBICACION,
            self::SEGREGACION,
            self::REORDENAMIENTO,
            self::DESOCUPACION,
            self::BUFFER_REPA,
        ];
    }

    public function solicitar(string $tipo, string $fuenteId, ?string $objetivoId = null): void
    {
        DB::transaction(function () use ($tipo, $fuenteId, $objetivoId): void {
            $ahora = now();
            $objetivoId ??= $fuenteId;

            // Una sola sentencia evita el interbloqueo de gap locks que podía
            // ocurrir con insertOrIgnore + increment sobre una clave nueva.
            DB::table(self::TABLA)->upsert(
                [[
                    'tipo' => $tipo,
                    'fuente_id' => $fuenteId,
                    'objetivo_id' => $objetivoId,
                    'version_solicitada' => 1,
                    'version_calculada' => 0,
                    'pendiente' => true,
                    'intentos_fallidos' => 0,
                    'solicitado_at' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]],
                ['tipo', 'fuente_id'],
                [
                    'objetivo_id' => $objetivoId,
                    'version_solicitada' => DB::raw('version_solicitada + 1'),
                    'pendiente' => true,
                    'intentos_fallidos' => 0,
                    'solicitado_at' => $ahora,
                    'fallo_at' => null,
                    'descartado_at' => null,
                    'agotado_at' => null,
                    'ultimo_error' => null,
                    'updated_at' => $ahora,
                ],
            );

            // El registro pendiente se confirma con la mutación física. Un fallo
            // al publicar el job no cambia la respuesta al operador: el watchdog
            // vuelve a enviar los pendientes desde la tabla.
            DB::afterCommit(fn () => $this->despachar($tipo, $fuenteId));
        });
    }

    public function despachar(string $tipo, string $fuenteId): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            EjecutarRecalculoPendientePlanificador::dispatch($tipo, $fuenteId);
        } catch (Throwable $error) {
            report($error);
        }
    }

    public function recuperar(int $limite = 200, ?string $tipo = null): int
    {
        if (config('queue.default') === 'sync') {
            return 0;
        }

        $pendientes = DB::table(self::TABLA)
            ->where('pendiente', true)
            ->when($tipo !== null, fn ($consulta) => $consulta->where('tipo', $tipo))
            ->orderBy('ultimo_reenvio_at')
            ->orderBy('id')
            ->limit($limite)
            ->get(['id', 'tipo', 'fuente_id']);

        foreach ($pendientes as $pendiente) {
            $this->despachar($pendiente->tipo, $pendiente->fuente_id);
            DB::table(self::TABLA)->where('id', $pendiente->id)
                ->update(['ultimo_reenvio_at' => now()]);
        }

        return $pendientes->count();
    }

    public function reactivarAgotados(int $limite = 200, ?string $tipo = null): int
    {
        if (config('queue.default') === 'sync') {
            return 0;
        }

        return DB::transaction(function () use ($limite, $tipo): int {
            $ids = DB::table(self::TABLA)
                ->where('pendiente', false)
                ->whereNotNull('agotado_at')
                ->when($tipo !== null, fn ($consulta) => $consulta->where('tipo', $tipo))
                ->orderBy('agotado_at')
                ->orderBy('id')
                ->limit($limite)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            return DB::table(self::TABLA)
                ->whereIn('id', $ids)
                ->where('pendiente', false)
                ->whereNotNull('agotado_at')
                ->update([
                    'pendiente' => true,
                    'intentos_fallidos' => 0,
                    'solicitado_at' => now(),
                    'ultimo_reenvio_at' => null,
                    'fallo_at' => null,
                    'agotado_at' => null,
                    'ultimo_error' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    /** @return array{pendientes: int, atrasados: int, fallidos: int, agotados: int, descartados: int, mas_antiguo_segundos: ?int} */
    public function salud(): array
    {
        $pendientes = DB::table(self::TABLA)
            ->where('pendiente', true);
        $primero = (clone $pendientes)->min('solicitado_at');

        return [
            'pendientes' => (clone $pendientes)->count(),
            'atrasados' => (clone $pendientes)->where('solicitado_at', '<', now()->subMinutes(5))->count(),
            'fallidos' => (clone $pendientes)->whereNotNull('fallo_at')->count(),
            'agotados' => DB::table(self::TABLA)->whereNotNull('agotado_at')->count(),
            'descartados' => DB::table(self::TABLA)->whereNotNull('descartado_at')->count(),
            'mas_antiguo_segundos' => $primero ? (int) max(0, Carbon::parse($primero)->diffInSeconds(now())) : null,
        ];
    }

    public function ejecutar(string $tipo, string $fuenteId): void
    {
        $registro = DB::table(self::TABLA)
            ->where('tipo', $tipo)
            ->where('fuente_id', $fuenteId)
            ->first();
        if (! $registro || ! $registro->pendiente) {
            return;
        }

        try {
            $this->recalcular($tipo, $registro->objetivo_id ?? $fuenteId);
            $this->confirmar($registro);
        } catch (ModelNotFoundException $error) {
            if ($this->fuenteEliminada($tipo, $registro->objetivo_id ?? $fuenteId)) {
                $this->descartar($registro, $error);

                return;
            }

            $this->registrarFallo($registro, $error);
            throw $error;
        } catch (Throwable $error) {
            $this->registrarFallo($registro, $error);
            throw $error;
        }
    }

    private function confirmar(object $registro): void
    {
        $eliminados = DB::table(self::TABLA)
            ->where('id', $registro->id)
            ->where('version_solicitada', $registro->version_solicitada)
            ->delete();
        if ($eliminados === 1) {
            return;
        }

        // Llegó una solicitud nueva durante el cálculo. Conservamos la fila
        // pendiente y solo avanzamos la versión que acaba de ser proyectada.
        DB::table(self::TABLA)
            ->where('id', $registro->id)
            ->where('version_calculada', '<', $registro->version_solicitada)
            ->update([
                'version_calculada' => $registro->version_solicitada,
                'calculado_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function descartar(object $registro, ModelNotFoundException $error): void
    {
        DB::table(self::TABLA)
            ->where('id', $registro->id)
            ->where('version_solicitada', $registro->version_solicitada)
            ->update([
                'pendiente' => false,
                'descartado_at' => now(),
                'fallo_at' => null,
                'ultimo_error' => mb_substr($error->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
    }

    private function registrarFallo(object $registro, Throwable $error): void
    {
        $intentos = ((int) $registro->intentos_fallidos) + 1;
        $agotado = $intentos >= self::MAX_INTENTOS_FALLIDOS;

        DB::table(self::TABLA)
            ->where('id', $registro->id)
            ->where('version_solicitada', $registro->version_solicitada)
            ->update([
                'pendiente' => ! $agotado,
                'intentos_fallidos' => $intentos,
                'fallo_at' => now(),
                'agotado_at' => $agotado ? now() : null,
                'ultimo_error' => mb_substr($error->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
    }

    private function fuenteEliminada(string $tipo, string $objetivoId): bool
    {
        if ($tipo === self::CARGA) {
            return ! Carga::query()->whereKey($objetivoId)->exists();
        }

        if (in_array($tipo, [
            self::UBICACION,
            self::SEGREGACION,
            self::REORDENAMIENTO,
            self::DESOCUPACION,
        ], true)) {
            return ! Movimiento::query()->whereKey($objetivoId)->exists();
        }

        return false;
    }

    private function recalcular(string $tipo, string $fuenteId): void
    {
        if ($tipo === self::BUFFER_REPA) {
            app(ServicioPrioridadBufferRepaletizaje::class)->recalcular($fuenteId);

            return;
        }

        if ($tipo === self::CARGA) {
            $carga = Carga::query()->findOrFail($fuenteId);
            $usuarioId = $carga->actualizada_por_user_id
                ?? $carga->publicada_por_user_id
                ?? $carga->creada_por_user_id;
            $usuario = $usuarioId ? User::query()->findOrFail($usuarioId) : null;
            if ($usuario) {
                app(ServicioPlanConcentracionCarga::class)->sincronizar($carga, $usuario);
                app(ServicioLaboresTurno::class)->actualizarEsperasCarga($carga->id);
            }

            return;
        }

        $movimiento = Movimiento::query()->findOrFail($fuenteId);
        $usuario = User::query()->findOrFail($movimiento->user_id);

        match ($tipo) {
            self::UBICACION => app(ServicioPlanConcentracionCarga::class)
                ->sincronizarTrasMovimiento($movimiento, $usuario),
            self::SEGREGACION => $this->sincronizarSegregacion($movimiento, $usuario),
            self::REORDENAMIENTO => app(ServicioOportunidadReordenamiento::class)
                ->sincronizarTrasMovimiento($movimiento, $usuario),
            self::DESOCUPACION => app(ServicioDesocupacionProgramada::class)
                ->sincronizarTrasMovimiento($movimiento, $usuario),
            default => throw new \LogicException('Tipo de recálculo del planificador desconocido.'),
        };
        if ($tipo === self::UBICACION) {
            $cargaId = CargaFolio::query()->where('folio_id', $movimiento->folio_id)->whereHas('reservaActiva')->value('carga_id');
            if ($cargaId) {
                app(ServicioLaboresTurno::class)->actualizarEsperasCarga($cargaId);
            }
        }
    }

    private function sincronizarSegregacion(Movimiento $movimiento, User $usuario): void
    {
        $ids = RetencionOperacionalFolio::query()
            ->where('bloqueo_folio_id', $movimiento->folio_id)
            ->pluck('id');
        $plan = $movimiento->planOperacional()->first();
        if ($plan?->referencia_tipo === ServicioPlanSegregacionRetenidos::REFERENCIA
            && $plan->referencia_id) {
            $ids->push($plan->referencia_id);
        }

        $posicionIds = array_values(array_filter([
            $movimiento->posicion_origen_id,
            $movimiento->posicion_destino_id,
        ]));
        $posiciones = Posicion::query()->whereIn('id', $posicionIds)->get();
        foreach ($posiciones as $posicion) {
            $ids = $ids->merge(
                RetencionOperacionalFolio::query()
                    ->whereNotNull('bloqueo_folio_id')
                    ->whereHas('folio.ubicacionActual.posicion', fn ($consulta) => $consulta
                        ->where('camara_id', $posicion->camara_id)
                        ->where('banda', $posicion->banda)
                        ->where('nivel', $posicion->nivel))
                    ->pluck('id'),
            );
        }
        if ($posicionIds !== []) {
            $ids = $ids->merge(
                PlanOperacional::query()
                    ->where('referencia_tipo', ServicioPlanSegregacionRetenidos::REFERENCIA)
                    ->whereHas('tareas', fn ($consulta) => $consulta
                        ->whereIn('estado', [
                            EstadoTareaMovimiento::Bloqueada->value,
                            EstadoTareaMovimiento::Pendiente->value,
                            EstadoTareaMovimiento::Asumida->value,
                            EstadoTareaMovimiento::EnProceso->value,
                        ])
                        ->where(function ($extremos) use ($posicionIds): void {
                            $extremos->whereIn('posicion_origen_id', $posicionIds)
                                ->orWhereIn('posicion_destino_id', $posicionIds);
                        }))
                    ->pluck('referencia_id'),
            );
        }

        $retenciones = RetencionOperacionalFolio::query()
            ->whereIn('id', $ids->unique()->values())
            ->get();
        foreach ($retenciones as $retencion) {
            app(ServicioPlanSegregacionRetenidos::class)->sincronizar($retencion, $usuario);
        }
    }
}
