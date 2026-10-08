<?php

namespace App\Services\Materiales;

use App\Enums\TipoEventoBloqueoMaterial;
use App\Exceptions\ConflictoOperacion;
use App\Models\EventoBloqueoMaterial;
use App\Models\FolioMaterial;
use App\Models\User;
use App\Services\Gerencia\ServicioPanelGerencial;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ServicioVencimientoMaterial
{
    public static function hoyChile(?Carbon $fecha = null): string
    {
        return ($fecha ?? now())->copy()->setTimezone('America/Santiago')->toDateString();
    }

    public static function filtrarVigentes(Builder $consulta, string $columna = 'fecha_vencimiento'): void
    {
        $consulta->where(fn (Builder $fechas) => $fechas->whereNull($columna)
            ->orWhere($columna, '>=', self::hoyChile()));
    }

    public function informacion(FolioMaterial $material): array
    {
        $fecha = $material->fecha_vencimiento?->toDateString();
        $dias = $fecha === null ? null : (int) Carbon::parse(self::hoyChile())
            ->diffInDays(Carbon::parse($fecha), false);
        $ventana = $material->item?->dias_alerta_vencimiento
            ?? max(0, (int) config('materiales.dias_alerta_vencimiento', 30));
        $estado = $material->estaVencido() ? 'vencido'
            : ($dias !== null && $dias <= $ventana ? 'por_vencer' : 'vigente');

        return [
            'fecha' => $fecha,
            'dias_restantes' => $dias,
            'dias_alerta' => $ventana,
            'estado' => $estado,
            'etiqueta' => $estado === 'vencido' ? 'Vencido'
                : ($estado === 'por_vencer' ? "Vence en {$dias} días" : null),
            'bloqueado_por_vencimiento' => (bool) $material->bloqueado_por_vencimiento,
        ];
    }

    public function corregir(FolioMaterial $material, string $operacionId, string $fecha, string $motivo, User $usuario): EventoBloqueoMaterial
    {
        Gate::forUser($usuario)->authorize('gestionar-bloqueos-materiales');
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 5) {
            throw new DomainException('Debes indicar el motivo de la corrección de vencimiento.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)
            || Carbon::parse($fecha)->toDateString() !== $fecha) {
            throw new DomainException('Indica una fecha de vencimiento válida.');
        }

        try {
            return DB::transaction(function () use ($material, $operacionId, $fecha, $motivo, $usuario): EventoBloqueoMaterial {
                $material = FolioMaterial::query()->with('folio.ubicacionActual')->lockForUpdate()->findOrFail($material->folio_id);
                $existente = EventoBloqueoMaterial::query()->where('operacion_id', $operacionId)->first();
                if ($existente) {
                    return $this->validarReintento($existente, $material, $fecha, $motivo, $usuario);
                }
                if (! $material->folio?->activo || (float) $material->cantidad_actual <= 0) {
                    throw new DomainException('Solo se puede corregir el vencimiento de un folio con existencia activa.');
                }
                $anterior = $material->fecha_vencimiento?->toDateString();
                $estadoAnterior = $material->folio->estado_operacional;
                $material->fecha_vencimiento = $fecha;
                if (! $material->estaVencido() && $material->bloqueado_por_vencimiento) {
                    $material->motivo_bloqueo = $material->motivo_bloqueo_previo_vencimiento;
                    $material->motivo_bloqueo_previo_vencimiento = null;
                    $material->bloqueado_por_vencimiento = false;
                }
                $material->save();
                app(ServicioAlmacenMaterial::class)->sincronizarProyeccion($material);
                $material->folio->refresh();
                app(ServicioPanelGerencial::class)->invalidar();

                return EventoBloqueoMaterial::create([
                    'operacion_id' => $operacionId,
                    'folio_id' => $material->folio_id,
                    'tipo' => TipoEventoBloqueoMaterial::FechaCorregida,
                    'estado_anterior' => $estadoAnterior,
                    'estado_resultante' => $material->folio->estado_operacional,
                    'motivo' => $motivo,
                    'user_id' => $usuario->id,
                    'metadatos' => ['fecha_anterior' => $anterior, 'fecha_nueva' => $fecha],
                    'ocurrido_at' => now(),
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existente = EventoBloqueoMaterial::query()->where('operacion_id', $operacionId)->first();
            if ($existente) {
                return $this->validarReintento($existente, $material, $fecha, $motivo, $usuario);
            }
            throw new ConflictoOperacion('La corrección entró en conflicto con otra operación.', previous: $exception);
        }
    }

    private function validarReintento(EventoBloqueoMaterial $evento, FolioMaterial $material, string $fecha, string $motivo, User $usuario): EventoBloqueoMaterial
    {
        if ($evento->folio_id !== $material->folio_id
            || $evento->tipo !== TipoEventoBloqueoMaterial::FechaCorregida
            || $evento->user_id !== $usuario->id
            || $evento->motivo !== $motivo
            || data_get($evento->metadatos, 'fecha_nueva') !== $fecha) {
            throw new ConflictoOperacion('El UUID de corrección ya fue utilizado con otros datos.');
        }

        return $evento;
    }
}
