<?php

namespace App\Services\Operacion;

use App\Enums\EstadoTareaMovimiento;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Dispositivo;
use App\Models\ManiobraOperacional;
use App\Models\PresenciaCamarero;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioDisponibilidadCamareros
{
    public const AUSENCIA_SEGUNDOS = 75;

    public function latido(User $usuario, Dispositivo $dispositivo, ?int $tokenId): void
    {
        $temporada = app(ServicioTemporadaActiva::class)->obtener();
        DB::transaction(function () use ($usuario, $dispositivo, $tokenId, $temporada): void {
            // El usuario es el mutex: cambiar de tablet no lo convierte en dos personas libres.
            User::query()->whereKey($usuario->id)->lockForUpdate()->firstOrFail();
            $presencia = PresenciaCamarero::query()->firstOrCreate(['user_id' => $usuario->id], [
                'dispositivo_id' => $dispositivo->id, 'temporada_id' => $temporada->id,
                'estado' => 'desconectado', 'ultima_actividad_at' => now(),
            ]);
            $presencia->fill(['dispositivo_id' => $dispositivo->id, 'temporada_id' => $temporada->id,
                'personal_access_token_id' => $tokenId, 'ultima_actividad_at' => now(), 'desconectada_at' => null]);
            $this->actualizarEstado($presencia);
        }, attempts: 3);
    }

    public function actualizarUsuario(int $usuarioId): void
    {
        DB::transaction(function () use ($usuarioId): void {
            $presencia = PresenciaCamarero::query()->where('user_id', $usuarioId)->lockForUpdate()->first();
            if ($presencia) {
                $this->actualizarEstado($presencia);
            }
        }, attempts: 3);
    }

    public function desconectar(int $tokenId): void
    {
        PresenciaCamarero::query()->where('personal_access_token_id', $tokenId)
            ->update(['desconectada_at' => now(), 'estado' => 'desconectado', 'disponible_desde' => null]);
    }

    private function actualizarEstado(PresenciaCamarero $presencia): void
    {
        $anterior = $presencia->estado;
        $tareas = TareaMovimiento::query()->where('responsable_user_id', $presencia->user_id)
            ->whereIn('estado', ['asumida', 'en_proceso', 'bloqueada'])
            ->whereHas('planOperacional', fn ($q) => $q->where('temporada_id', $presencia->temporada_id))
            ->get(['estado', 'tipo_movimiento', 'posicion_destino_id']);
        $maniobras = ManiobraOperacional::query()->where('responsable_user_id', $presencia->user_id)
            ->whereIn('estado', ['en_ejecucion', 'pausada_discrepancia', 'pausada_supervision'])
            ->whereHas('planOperacional', fn ($q) => $q->where('temporada_id', $presencia->temporada_id));
        $custodia = CustodiaTemporalManiobra::query()->where('estado', 'activa')
            ->whereHas('maniobraOperacional', fn ($q) => $q->where('responsable_user_id', $presencia->user_id)
                ->whereHas('planOperacional', fn ($p) => $p->where('temporada_id', $presencia->temporada_id)))->exists();
        $conectado = $presencia->desconectada_at === null && $presencia->ultima_actividad_at->gt(now()->subSeconds(self::AUSENCIA_SEGUNDOS));
        $estado = ! $conectado ? 'desconectado'
            : ($tareas->contains('estado', EstadoTareaMovimiento::EnProceso) ? 'trabajando'
                : ($custodia || (clone $maniobras)->whereIn('estado', ['pausada_discrepancia', 'pausada_supervision'])->exists() ? 'esperando'
                    : ($tareas->isNotEmpty() ? 'trabajando' : ($maniobras->exists() ? 'esperando' : 'disponible'))));
        $cambioDisponible = $estado === 'disponible' && $anterior !== 'disponible';
        $presencia->estado = $estado;
        if ($cambioDisponible) {
            $presencia->disponible_desde = now();
            // Reconectar o entrar por primera vez no simula haber terminado labores.
            $presencia->aviso_disponible_id = in_array($anterior, ['trabajando', 'esperando'], true) ? (string) Str::uuid() : null;
        } elseif ($estado !== 'disponible') {
            $presencia->disponible_desde = null;
        }
        $presencia->save();
    }

    /** Lectura pura: la pérdida de latidos se calcula sin escribir desde el GET. */
    public function consultar(Temporada $temporada): array
    {
        $actividad = collect(app(ServicioOperacionAhora::class)->camareros($temporada))
            ->groupBy('usuario.id')->map(fn ($filas) => $filas->firstWhere('tarea_actual', '!=', null) ?? $filas->first());
        $filas = PresenciaCamarero::query()->where('temporada_id', $temporada->id)
            ->whereHas('usuario', fn ($q) => $q->where('activo', true))
            ->whereHas('dispositivo', fn ($q) => $q->where('activo', true))
            ->with(['usuario:id,name', 'dispositivo:id,codigo,nombre'])->get();
        $camareros = $filas->map(function (PresenciaCamarero $presencia) use ($actividad): array {
            $fresco = $presencia->desconectada_at === null && $presencia->ultima_actividad_at->gt(now()->subSeconds(self::AUSENCIA_SEGUNDOS));
            $estado = $fresco ? $presencia->estado : 'desconectado';
            if ($fresco && $estado === 'disponible' && ($actividad->get($presencia->user_id)['tarea_actual'] ?? false)) {
                $estado = 'trabajando';
            }

            return [...($actividad->get($presencia->user_id) ?? [
                'usuario' => ['id' => $presencia->user_id, 'nombre' => $presencia->usuario->name],
                'dispositivo' => ['id' => $presencia->dispositivo_id, 'codigo' => $presencia->dispositivo->codigo, 'nombre' => $presencia->dispositivo->nombre],
                'sesion' => null, 'ubicacion_actual' => ['tipo' => 'sin_ubicacion', 'camara' => null], 'tarea_actual' => null,
            ]), 'estado_presencia' => $estado, 'ultima_actividad_at' => $presencia->ultima_actividad_at->toAtomString(),
                'disponible_desde' => $estado === 'disponible' ? $presencia->disponible_desde?->toAtomString() : null,
                'aviso_disponible_id' => $estado === 'disponible' ? $presencia->aviso_disponible_id : null];
        })->values()->all();
        // Clientes previos sin latido conservan sus tareas visibles, sin declararlos libres.
        $ids = collect($camareros)->pluck('usuario.id');
        foreach ($actividad as $id => $operador) {
            if (! $ids->contains($id)) {
                $camareros[] = [...$operador, 'estado_presencia' => 'sin_confirmar', 'disponible_desde' => null, 'aviso_disponible_id' => null];
            }
        }

        return ['generado_at' => now()->toAtomString(), 'temporada_id' => $temporada->id,
            'actualizacion_sugerida_segundos' => 5, 'ausencia_segundos' => self::AUSENCIA_SEGUNDOS,
            'disponibles' => collect($camareros)->where('estado_presencia', 'disponible')->count(), 'camareros' => $camareros];
    }
}
