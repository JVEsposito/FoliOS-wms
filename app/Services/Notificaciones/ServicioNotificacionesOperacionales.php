<?php

namespace App\Services\Notificaciones;

use App\Enums\AudienciaNotificacionOperacional;
use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Enums\SeveridadNotificacionOperacional;
use App\Enums\TipoNotificacionOperacional;
use App\Models\DespachoMaterial;
use App\Models\LecturaNotificacionOperacional;
use App\Models\NotificacionOperacional;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ServicioNotificacionesOperacionales
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
    ) {}

    public function consultaVisibles(User $usuario): Builder
    {
        $areas = collect($this->alcance->contenidosVisibles($usuario))
            ->map(fn ($contenido): string => $contenido->value)
            ->all();

        return NotificacionOperacional::query()
            ->where(function (Builder $consulta): void {
                $consulta
                    ->whereHas('carga.temporada', fn (Builder $temporada): Builder => $temporada
                        ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->orWhereHas('despachoMaterial.temporada', fn (Builder $temporada): Builder => $temporada
                        ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->orWhereHas('folio.temporada', fn (Builder $temporada): Builder => $temporada
                        ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->orWhereHas('incidencia.asignacion.carga.temporada', fn (Builder $temporada): Builder => $temporada
                        ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->orWhereHas('recepcionRomana.temporada', fn (Builder $temporada): Builder => $temporada
                        ->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id))
                    ->orWhere(function (Builder $sinProceso): void {
                        $sinProceso
                            ->whereNull('carga_id')
                            ->whereNull('despacho_material_id')
                            ->whereNull('recepcion_romana_id')
                            ->whereNull('folio_id')
                            ->whereNull('incidencia_carga_folio_id');
                    });
            })
            ->where(function (Builder $consulta) use ($usuario, $areas): void {
                $consulta
                    ->where(function (Builder $porUsuario) use ($usuario): void {
                        $porUsuario
                            ->where('audiencia_tipo', AudienciaNotificacionOperacional::Usuario->value)
                            ->where('audiencia_valor', (string) $usuario->id);
                    })
                    ->orWhere(function (Builder $porRol) use ($usuario): void {
                        $porRol
                            ->where('audiencia_tipo', AudienciaNotificacionOperacional::Rol->value)
                            ->where('audiencia_valor', $usuario->rol->value);
                    })
                    ->when($areas !== [], function (Builder $consultaConAreas) use ($areas): void {
                        $consultaConAreas->orWhere(function (Builder $porArea) use ($areas): void {
                            $porArea
                                ->where('audiencia_tipo', AudienciaNotificacionOperacional::Area->value)
                                ->whereIn('audiencia_valor', $areas);
                        });
                    });
            });
    }

    public function cantidadNoLeidas(User $usuario): int
    {
        return $this->consultaVisibles($usuario)
            ->whereDoesntHave(
                'lecturas',
                fn (Builder $lectura): Builder => $lectura
                    ->where('user_id', $usuario->id)
                    ->whereNotNull('leida_at'),
            )
            ->count();
    }

    public function notificarDespachoMaterialCreado(
        DespachoMaterial $despacho,
    ): NotificacionOperacional {
        $cantidadItems = $despacho->detalles()->count();

        return NotificacionOperacional::query()->firstOrCreate(
            ['clave' => "despacho-material:{$despacho->id}:area:materiales"],
            [
                'tipo' => TipoNotificacionOperacional::DespachoMaterialCreado,
                'audiencia_tipo' => AudienciaNotificacionOperacional::Area,
                'audiencia_valor' => ContenidoCamara::Materiales->value,
                'severidad' => SeveridadNotificacionOperacional::Informativa,
                'titulo' => "Nuevo despacho {$despacho->codigo}",
                'mensaje' => sprintf(
                    '%d %s para %s. Revisa las reservas FIFO y prepara el retiro.',
                    $cantidadItems,
                    $cantidadItems === 1 ? 'ítem solicitado' : 'ítems solicitados',
                    $despacho->destino_nombre,
                ),
                'despacho_material_id' => $despacho->id,
                'datos' => [
                    'destino' => $despacho->destino_nombre,
                    'centro_costo' => $despacho->destino_centro_costo,
                    'cantidad_items' => $cantidadItems,
                ],
            ],
        );
    }

    public function notificarRecepcionRomanaCreada(
        RecepcionRomana $recepcion,
    ): NotificacionOperacional {
        $recepcion->loadMissing('detallesEnvases');
        $envases = $recepcion->detallesEnvases
            ->map(fn ($detalle): string => "{$detalle->cantidad_declarada} {$detalle->tipo_envase->value}")
            ->implode(', ');

        return NotificacionOperacional::query()->firstOrCreate(
            ['clave' => "recepcion-romana:{$recepcion->id}:rol:validador-mp"],
            [
                'tipo' => TipoNotificacionOperacional::RecepcionRomanaCreada,
                'audiencia_tipo' => AudienciaNotificacionOperacional::Rol,
                'audiencia_valor' => RolUsuario::ValidadorMp->value,
                'severidad' => SeveridadNotificacionOperacional::Informativa,
                'titulo' => "Nueva recepción {$recepcion->numero_recepcion}",
                'mensaje' => sprintf(
                    '%s · guía %s · %s. Disponible para Validación MP.',
                    $recepcion->cliente_nombre_snapshot,
                    $recepcion->numero_guia_despacho,
                    $envases,
                ),
                'recepcion_romana_id' => $recepcion->id,
                'datos' => [
                    'numero_recepcion' => $recepcion->numero_recepcion,
                    'tipo_recepcion' => $recepcion->tipo_recepcion->value,
                    'cliente' => $recepcion->cliente_nombre_snapshot,
                    'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                    'patente_camion' => $recepcion->patente_camion,
                    'ingreso_at' => $recepcion->ingreso_at?->toAtomString(),
                    'envases' => $recepcion->detallesEnvases->map(fn ($detalle): array => [
                        'tipo_envase' => $detalle->tipo_envase->value,
                        'cantidad_declarada' => $detalle->cantidad_declarada,
                    ])->values()->all(),
                ],
            ],
        );
    }

    public function marcarLeida(
        NotificacionOperacional $notificacion,
        User $usuario,
    ): LecturaNotificacionOperacional {
        return $this->registrarEstado($notificacion, $usuario, false);
    }

    public function confirmar(
        NotificacionOperacional $notificacion,
        User $usuario,
    ): LecturaNotificacionOperacional {
        return $this->registrarEstado($notificacion, $usuario, true);
    }

    private function registrarEstado(
        NotificacionOperacional $notificacion,
        User $usuario,
        bool $confirmar,
    ): LecturaNotificacionOperacional {
        return DB::transaction(function () use ($notificacion, $usuario, $confirmar): LecturaNotificacionOperacional {
            $visible = $this->consultaVisibles($usuario)
                ->whereKey($notificacion->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lectura = LecturaNotificacionOperacional::query()
                ->where('notificacion_operacional_id', $visible->id)
                ->where('user_id', $usuario->id)
                ->lockForUpdate()
                ->first() ?? new LecturaNotificacionOperacional([
                    'notificacion_operacional_id' => $visible->id,
                    'user_id' => $usuario->id,
                ]);

            if ($lectura->leida_at === null) {
                $lectura->leida_at = now();
            }
            if ($confirmar) {
                if ($lectura->confirmada_at === null) {
                    $lectura->confirmada_at = now();
                }
            }
            $lectura->save();

            return $lectura->refresh();
        }, attempts: 3);
    }
}
