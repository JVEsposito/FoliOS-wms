<?php

namespace App\Services\Verificaciones;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Gerencia\ServicioPanelGerencial;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioResolverIncidenciaVerificacion
{
    public function resolver(IncidenciaVerificacionUbicacion $incidencia, array $datos, User $user): IncidenciaVerificacionUbicacion
    {
        $contenido = $incidencia->camara->contenido;
        $rol = $contenido === ContenidoCamara::Materiales ? RolUsuario::SupervisorMateriales : RolUsuario::SupervisorFrio;
        abort_unless($user->activo && in_array($user->rol, [RolUsuario::Administrador, $rol], true)
            && app(AlcanceOperacionalUsuario::class)->puedeSupervisarCamara($user, $contenido), 403);
        $motivo = trim($datos['motivo'] ?? '');
        if ($motivo === '' || ! in_array($datos['tipo_resolucion'] ?? null, ['reubicado', 'error_de_conteo', 'otro'], true)) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo y tipo de resolución.']);
        }
        $hash = hash('sha256', json_encode([$datos['tipo_resolucion'], $motivo], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($incidencia, $datos, $user, $motivo, $hash) {
            $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
            $incidencia = IncidenciaVerificacionUbicacion::whereKey($incidencia->id)->lockForUpdate()->firstOrFail();
            if ($incidencia->temporada_id !== $temporada->id) {
                throw new ConflictoOperacion('La incidencia no pertenece a la temporada activa.');
            }
            if ($incidencia->resolucion_operacion_id === $datos['operacion_id']) {
                if ($incidencia->resolucion_payload_hash !== $hash || $incidencia->resuelto_por_user_id !== $user->id) {
                    throw new ConflictoOperacion('La resolución repetida contiene otros datos.');
                }

                return $incidencia;
            }
            if ($incidencia->estado !== 'abierta' || IncidenciaVerificacionUbicacion::where('resolucion_operacion_id', $datos['operacion_id'])->exists()) {
                throw new ConflictoOperacion('La incidencia ya está resuelta o la operación está usada.');
            }
            $incidencia->update(['estado' => 'resuelta', 'resuelta_at' => now(), 'resuelto_por_user_id' => $user->id,
                'resolucion' => $motivo, 'tipo_resolucion' => $datos['tipo_resolucion'], 'resolucion_operacion_id' => $datos['operacion_id'], 'resolucion_payload_hash' => $hash]);
            DB::afterCommit(fn () => app(ServicioPanelGerencial::class)->invalidar());

            return $incidencia;
        }, 3);
    }
}
