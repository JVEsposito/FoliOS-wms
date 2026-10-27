<?php

namespace App\Http\Controllers\Api;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\CerrarSesionEstibaForzosamenteRequest;
use App\Http\Requests\CerrarSesionEstibaRequest;
use App\Http\Resources\SesionEstibaResource;
use App\Models\Camara;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Services\Autenticacion\ContextoOperacional;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Verificaciones\ServicioVerificacionesUbicacion;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SesionEstibaController extends Controller
{
    public function store(
        Request $request,
        Camara $camara,
        ContextoOperacional $contexto,
        ServicioSesionEstiba $servicio,
    ): Response {
        [$usuario, $dispositivo] = $contexto->obtener($request);
        $datos = $request->validate(['tarea_movimiento_id' => ['sometimes', 'required', 'uuid', 'exists:tareas_movimiento,id']]);
        $sesion = isset($datos['tarea_movimiento_id'])
            ? $servicio->abrirParaTarea($camara, $usuario, $dispositivo, TareaMovimiento::findOrFail($datos['tarea_movimiento_id']))
            : $servicio->abrir($camara, $usuario, $dispositivo);
        $sesion->load('usuario', 'dispositivo');
        if (config('verificaciones.habilitada') && $usuario->rol === RolUsuario::CamareroFrio) {
            try {
                app(ServicioVerificacionesUbicacion::class)->actual($usuario, $dispositivo);
            } catch (\Throwable $error) {
                // El conteo no impide abrir la cámara ni ejecutar labores.
                report($error);
            }
        }

        return (new SesionEstibaResource($sesion))
            ->response()
            ->setStatusCode(201);
    }

    public function cerrar(
        CerrarSesionEstibaRequest $request,
        SesionEstiba $sesion,
        ContextoOperacional $contexto,
        ServicioSesionEstiba $servicio,
    ): SesionEstibaResource {
        [$usuario] = $contexto->obtener($request);
        $sesionCerrada = $servicio
            ->cerrar($sesion, $usuario, $request->validated('motivo'))
            ->load('usuario', 'dispositivo');

        return new SesionEstibaResource($sesionCerrada);
    }

    public function cerrarForzosamente(
        CerrarSesionEstibaForzosamenteRequest $request,
        SesionEstiba $sesion,
        ServicioSesionEstiba $servicio,
    ): SesionEstibaResource {
        $sesionCerrada = $servicio
            ->cerrarForzosamente(
                $sesion,
                $request->user(),
                $request->validated('motivo'),
            )
            ->load('usuario', 'dispositivo');

        return new SesionEstibaResource($sesionCerrada);
    }
}
