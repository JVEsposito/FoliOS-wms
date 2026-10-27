<?php

namespace App\Services\Estiba;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoSesionEstiba;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoMovimiento;
use App\Exceptions\CamaraEnUso;
use App\Exceptions\ConflictoOperacion;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\BloqueoCamara;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\ReservaTareaMovimiento;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ServicioSesionEstiba
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
    ) {}

    public function abrir(Camara $camara, User $usuario, Dispositivo $dispositivo): SesionEstiba
    {
        try {
            return DB::transaction(function () use ($camara, $usuario, $dispositivo): SesionEstiba {
                $camaraBloqueada = Camara::query()->lockForUpdate()->findOrFail($camara->id);
                $usuarioBloqueado = User::query()->lockForUpdate()->findOrFail($usuario->id);
                $dispositivoBloqueado = Dispositivo::query()
                    ->lockForUpdate()
                    ->findOrFail($dispositivo->id);

                $this->validarParticipantes(
                    $camaraBloqueada,
                    $usuarioBloqueado,
                    $dispositivoBloqueado,
                );

                $camaraOcupada = BloqueoCamara::query()
                    ->whereKey($camaraBloqueada->id)
                    ->exists()
                    || SesionEstiba::query()
                        ->where('camara_id', $camaraBloqueada->id)
                        ->where('estado', EstadoSesionEstiba::Abierta->value)
                        ->whereNull('tarea_movimiento_id')
                        ->exists();

                if ($camaraOcupada) {
                    throw new CamaraEnUso('La cámara ya está siendo modificada por otra sesión.');
                }

                $sesion = SesionEstiba::create([
                    'camara_id' => $camaraBloqueada->id,
                    'user_id' => $usuarioBloqueado->id,
                    'dispositivo_id' => $dispositivoBloqueado->id,
                    'estado' => EstadoSesionEstiba::Abierta,
                    'version_inicial' => $camaraBloqueada->version_plano,
                    'iniciada_at' => now(),
                    'ultima_actividad_at' => now(),
                ]);

                BloqueoCamara::create([
                    'camara_id' => $camaraBloqueada->id,
                    'sesion_estiba_id' => $sesion->id,
                    'adquirido_at' => now(),
                ]);

                return $sesion->load('bloqueo');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw new CamaraEnUso(
                'La cámara ya está siendo modificada por otra sesión.',
                previous: $exception,
            );
        }
    }

    /** La autorización pertenece a un paso concreto; nunca concede edición manual. */
    public function abrirParaTarea(Camara $camara, User $usuario, Dispositivo $dispositivo, TareaMovimiento $tarea): SesionEstiba
    {
        return DB::transaction(function () use ($camara, $usuario, $dispositivo, $tarea): SesionEstiba {
            $camara = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $tarea = TareaMovimiento::query()->with('planOperacional.temporada')->lockForUpdate()->findOrFail($tarea->id);
            $this->validarParticipantes($camara, $usuario, $dispositivo);
            $reserva = ReservaTareaMovimiento::query()->where('bloqueo_tarea_id', $tarea->id)->lockForUpdate()->first();
            if ($camara->contenido !== ContenidoCamara::Productos || ! $tarea->maniobra_operacional_id
                || ! $tarea->planOperacional?->temporada?->activa
                || $tarea->planOperacional->estado !== EstadoPlanOperacional::EnEjecucion
                || ! in_array($tarea->estado, [EstadoTareaMovimiento::Asumida, EstadoTareaMovimiento::EnProceso], true)
                || $tarea->responsable_user_id !== $usuario->id || $tarea->dispositivo_id !== $dispositivo->id
                || ! in_array($camara->id, [$tarea->camara_origen_id, $tarea->camara_destino_id], true)
                || ! $reserva || $reserva->user_id !== $usuario->id || $reserva->dispositivo_id !== $dispositivo->id
                || ($tarea->estado !== EstadoTareaMovimiento::EnProceso && $reserva->vence_at?->lte(now()))) {
                throw new ConflictoOperacion('La sesión requiere una tarea propia vigente en esta cámara.');
            }
            if ($tarea->estado !== EstadoTareaMovimiento::EnProceso
                && (($tarea->planOperacional->contexto['planner_horizon'] ?? config('planificador.horizon')) !== 'rolling'
                    || config('planificador.mode') !== 'guided' || config('planificador.compute') !== 'tablet')) {
                throw new ConflictoOperacion('La sesión de tarea requiere una labor dirigida rolling.');
            }
            if ($tarea->tipo_movimiento !== TipoMovimiento::Retiro && $reserva->bloqueo_posicion_id !== $tarea->posicion_destino_id) {
                throw new ConflictoOperacion('Confirma y reserva el destino antes de abrir la sesión de tarea.');
            }
            app(ServicioManiobrasOperacionales::class)->validarPasoActual($tarea);
            $clave = $tarea->id.':'.$camara->id;
            $existente = SesionEstiba::query()->where('clave_tarea_activa', $clave)->first();
            if ($existente) {
                if ($existente->user_id !== $usuario->id || $existente->dispositivo_id !== $dispositivo->id) {
                    throw new ConflictoOperacion('La sesión de tarea pertenece a otro operador o dispositivo.');
                }

                return $existente;
            }
            $sesion = SesionEstiba::create([
                'camara_id' => $camara->id, 'user_id' => $usuario->id, 'dispositivo_id' => $dispositivo->id,
                'tarea_movimiento_id' => $tarea->id, 'clave_tarea_activa' => $clave,
                'estado' => EstadoSesionEstiba::Abierta, 'version_inicial' => $camara->version_plano,
                'iniciada_at' => now(), 'ultima_actividad_at' => now(),
            ]);
            $posiciones = Posicion::query()->where('camara_id', $camara->id)
                ->whereIn('id', array_filter([$tarea->posicion_origen_id, $tarea->posicion_destino_id]))
                ->orderBy('banda')->orderBy('nivel')->get();
            foreach ($posiciones as $posicion) {
                app(ServicioRecursosEstibaDirigida::class)->validarAcceso($posicion, $tarea, $posicion->id === $tarea->posicion_destino_id);
                $claveBanda = implode(':', [$camara->id, $posicion->banda, $posicion->nivel]);
                $ocupada = ReservaBandaManiobra::query()->where('clave_bloqueo', $claveBanda)->lockForUpdate()->first();
                if ($ocupada && $ocupada->maniobra_operacional_id !== $tarea->maniobra_operacional_id) {
                    throw new ConflictoOperacion('La banda está comprometida por otra maniobra; las demás bandas pueden seguir trabajando.');
                }
                if (! $ocupada) {
                    $camara->increment('revision_reservas');
                    ReservaBandaManiobra::create([
                        'maniobra_operacional_id' => $tarea->maniobra_operacional_id, 'sesion_estiba_id' => $sesion->id,
                        'camara_id' => $camara->id, 'banda' => $posicion->banda, 'nivel' => $posicion->nivel,
                        'clave_bloqueo' => $claveBanda, 'reservada_at' => now(),
                    ]);
                }
            }

            return $sesion;
        }, attempts: 3);
    }

    /** Se ejecuta dentro de la transacción de cierre/liberación de la tarea. */
    public function cerrarDeTarea(TareaMovimiento $tarea): void
    {
        if ($tarea->estado === EstadoTareaMovimiento::EnProceso) {
            return;
        }
        foreach (SesionEstiba::query()->where('tarea_movimiento_id', $tarea->id)
            ->where('estado', EstadoSesionEstiba::Abierta)->orderBy('id')->lockForUpdate()->get() as $sesion) {
            $sesion->update(['estado' => EstadoSesionEstiba::Cerrada, 'clave_tarea_activa' => null,
                'cerrada_at' => now(), 'ultima_actividad_at' => now(),
                'version_final' => Camara::whereKey($sesion->camara_id)->value('version_plano'),
                'motivo_cierre' => 'Cierre automático al finalizar o liberar la tarea.']);
            $this->liberarBandasSesion($sesion);
        }
    }

    private function liberarBandasSesion(SesionEstiba $sesion): void
    {
        ReservaBandaManiobra::query()->where('sesion_estiba_id', $sesion->id)->whereNull('liberada_at')
            ->get()->each(fn ($reserva) => $reserva->update(['clave_bloqueo' => null, 'liberada_at' => now(),
                'motivo_liberacion' => 'Fin de sesión de tarea.']));
        Camara::query()->whereKey($sesion->camara_id)->increment('revision_reservas');
    }

    public function cerrar(
        SesionEstiba $sesion,
        User $usuario,
        ?string $motivo = null,
    ): SesionEstiba {
        return DB::transaction(function () use ($sesion, $usuario, $motivo): SesionEstiba {
            $sesionBloqueada = SesionEstiba::query()->lockForUpdate()->findOrFail($sesion->id);
            $camara = Camara::query()->lockForUpdate()->findOrFail($sesionBloqueada->camara_id);
            $usuarioBloqueado = User::query()->lockForUpdate()->findOrFail($usuario->id);

            if ($sesionBloqueada->estado !== EstadoSesionEstiba::Abierta) {
                if ($sesionBloqueada->tarea_movimiento_id && $sesionBloqueada->user_id === $usuario->id) {
                    return $sesionBloqueada;
                }
                throw new DomainException('La sesión de estiba ya se encuentra cerrada.');
            }
            if ($sesionBloqueada->tarea_movimiento_id && $sesionBloqueada->tareaMovimiento?->estado === EstadoTareaMovimiento::EnProceso) {
                throw new ConflictoOperacion('Completa o regulariza el pallet en movimiento antes de cerrar su sesión.');
            }

            if (! $usuarioBloqueado->activo
                || $sesionBloqueada->user_id !== $usuarioBloqueado->id) {
                throw new OperacionNoAutorizada(
                    'El cierre normal solo puede realizarlo el dueño de la sesión.',
                );
            }

            $bloqueo = BloqueoCamara::query()
                ->where('camara_id', $camara->id)
                ->where('sesion_estiba_id', $sesionBloqueada->id)
                ->lockForUpdate()
                ->first();

            if (! $bloqueo && ! $sesionBloqueada->tarea_movimiento_id) {
                throw new DomainException('La sesión no posee el bloqueo de la cámara.');
            }

            $sesionBloqueada->update([
                'estado' => EstadoSesionEstiba::Cerrada,
                'clave_tarea_activa' => null,
                'version_final' => $camara->version_plano,
                'cerrada_at' => now(),
                'ultima_actividad_at' => now(),
                'cierre_forzado_por_user_id' => null,
                'motivo_cierre' => $motivo,
            ]);

            $bloqueo?->delete();
            $this->liberarBandasSesion($sesionBloqueada);

            return $sesionBloqueada->refresh();
        }, attempts: 3);
    }

    public function cerrarForzosamente(
        SesionEstiba $sesion,
        User $usuario,
        string $motivo,
        bool $desdeAdministracion = false,
    ): SesionEstiba {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 3) {
            throw new DomainException('El cierre forzoso requiere un motivo válido.');
        }

        return DB::transaction(function () use ($sesion, $usuario, $motivo, $desdeAdministracion): SesionEstiba {
            $sesionBloqueada = SesionEstiba::query()->lockForUpdate()->findOrFail($sesion->id);
            $camara = Camara::query()->lockForUpdate()->findOrFail($sesionBloqueada->camara_id);
            $usuarioBloqueado = User::query()->lockForUpdate()->findOrFail($usuario->id);

            if ($sesionBloqueada->estado !== EstadoSesionEstiba::Abierta) {
                if ($sesionBloqueada->tarea_movimiento_id && $sesionBloqueada->user_id === $usuario->id) {
                    return $sesionBloqueada;
                }
                throw new DomainException('La sesión de estiba ya se encuentra cerrada.');
            }
            if ($sesionBloqueada->tarea_movimiento_id && $sesionBloqueada->tareaMovimiento?->estado === EstadoTareaMovimiento::EnProceso) {
                throw new ConflictoOperacion('Completa o regulariza el pallet en movimiento antes de cerrar su sesión.');
            }

            $autorizado = $desdeAdministracion
                ? $this->alcance->puedeAdministrarAccesos($usuarioBloqueado)
                : $this->alcance->puedeCerrarSesionForzosamente($usuarioBloqueado, $camara);

            if (! $autorizado) {
                throw new OperacionNoAutorizada(
                    'El usuario no puede cerrar forzosamente sesiones de esta área.',
                );
            }

            $bloqueo = BloqueoCamara::query()
                ->where('camara_id', $camara->id)
                ->where('sesion_estiba_id', $sesionBloqueada->id)
                ->lockForUpdate()
                ->first();

            if (! $bloqueo && ! $sesionBloqueada->tarea_movimiento_id) {
                throw new DomainException('La sesión no posee el bloqueo de la cámara.');
            }

            $sesionBloqueada->update([
                'estado' => EstadoSesionEstiba::CierreForzado,
                'clave_tarea_activa' => null,
                'version_final' => $camara->version_plano,
                'cerrada_at' => now(),
                'ultima_actividad_at' => now(),
                'cierre_forzado_por_user_id' => $usuarioBloqueado->id,
                'motivo_cierre' => $motivo,
            ]);

            $bloqueo?->delete();
            $this->liberarBandasSesion($sesionBloqueada);

            return $sesionBloqueada->refresh();
        }, attempts: 3);
    }

    private function validarParticipantes(
        Camara $camara,
        User $usuario,
        Dispositivo $dispositivo,
    ): void {
        if ($camara->estado !== EstadoCamara::Activa) {
            throw new DomainException('La cámara no se encuentra activa.');
        }

        if (! $this->alcance->puedeOperarCamara($usuario, $camara)) {
            throw new OperacionNoAutorizada(
                'El usuario no está autorizado para operar esta cámara.',
            );
        }

        if (! $dispositivo->activo) {
            throw new OperacionNoAutorizada('El dispositivo no se encuentra activo.');
        }
    }
}
