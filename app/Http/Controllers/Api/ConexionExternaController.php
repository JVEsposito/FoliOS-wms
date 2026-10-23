<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarConexionExternaRequest;
use App\Models\ConexionExterna;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

class ConexionExternaController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->responder(['data' => ConexionExterna::query()
            ->with('actualizadoPor:id,name')->orderBy('nombre')->get()
            ->map(fn (ConexionExterna $conexion): array => $this->recurso($conexion))]);
    }

    public function store(GuardarConexionExternaRequest $request): JsonResponse
    {
        return $this->responder(['data' => $this->recurso($this->guardar($request))], 201);
    }

    public function update(GuardarConexionExternaRequest $request, ConexionExterna $conexionExterna): JsonResponse
    {
        return $this->responder(['data' => $this->recurso($this->guardar($request, $conexionExterna))]);
    }

    public function probar(Request $request, ConexionExterna $conexionExterna, ConectorMySqlLectura $conector): JsonResponse
    {
        $datos = $request->validate(['version_conocida' => ['required', 'integer', 'min:1']]);
        $this->comprobarVersion($conexionExterna, (int) $datos['version_conocida']);
        if (! $conexionExterna->activa) {
            throw new DomainException('Activa la conexión antes de probarla.');
        }

        if ($aceptada = app(ServicioOperacionesIntegracion::class)->diferir($request, 'probar', $conexionExterna, $datos)) {
            return $aceptada;
        }
        $correcta = false;
        $diagnostico = null;
        try {
            $conector->probar($conexionExterna->credenciales);
            $correcta = true;
        } catch (Throwable $error) {
            // Los errores del controlador contienen servidor, usuario y DSN: no se registran ni
            // se devuelven; solo su causa (red, credenciales, base, controlador).
            $diagnostico = ConectorMySqlLectura::diagnosticar($error);
        }

        $conexion = DB::transaction(function () use ($conexionExterna, $datos, $correcta, $request): ConexionExterna {
            $conexion = ConexionExterna::query()->lockForUpdate()->findOrFail($conexionExterna->id);
            $this->comprobarVersion($conexion, (int) $datos['version_conocida']);
            app(ServicioOperacionesIntegracion::class)->exigirVigente($request->attributes->get('integracion_id'));
            $conexion->forceFill([
                'ultima_prueba_estado' => $correcta ? 'correcta' : 'fallida',
                'ultima_prueba_at' => now(),
            ])->save();
            $conexion->eventos()->create([
                'user_id' => $request->user()->id,
                'accion' => $correcta ? 'prueba_correcta' : 'prueba_fallida',
                'campos_modificados' => [],
            ]);

            return $conexion->load('actualizadoPor:id,name');
        });

        return $this->responder([
            'data' => $this->recurso($conexion),
            'message' => $correcta
                ? 'Conexión comprobada desde el servidor de FoliOS. La consulta de lectura respondió correctamente.'
                : 'No se pudo comprobar la conexión. '.$diagnostico['mensaje'],
            'causa' => $diagnostico['causa'] ?? null,
        ], $correcta ? 200 : 422);
    }

    private function guardar(#[SensitiveParameter] GuardarConexionExternaRequest $request, ?ConexionExterna $conexion = null): ConexionExterna
    {
        return DB::transaction(function () use ($request, $conexion): ConexionExterna {
            $datos = $request->validated();
            if ($conexion) {
                $conexion = ConexionExterna::query()->lockForUpdate()->findOrFail($conexion->id);
                $this->comprobarVersion($conexion, (int) $datos['version_conocida']);
            }

            $credenciales = $conexion?->credenciales ?? [];
            $campos = [];
            foreach (['host', 'puerto', 'usuario', 'base_datos', 'password'] as $campo) {
                if ($campo === 'password' && ($datos[$campo] ?? '') === '') {
                    continue;
                }
                $valor = $campo === 'puerto' ? (int) $datos[$campo] : $datos[$campo];
                if (($credenciales[$campo] ?? null) !== $valor) {
                    $campos[] = $campo;
                }
                $credenciales[$campo] = $valor;
            }
            foreach (['nombre', 'activa'] as $campo) {
                if ($conexion?->{$campo} !== ($campo === 'activa' ? (bool) $datos[$campo] : $datos[$campo])) {
                    $campos[] = $campo;
                }
            }

            $nueva = $conexion === null;
            $conexion ??= new ConexionExterna;
            $conexion->fill([
                'nombre' => $datos['nombre'],
                'activa' => $datos['activa'],
                'credenciales' => $credenciales,
                'version' => $nueva ? 1 : $conexion->version + 1,
                'ultima_prueba_estado' => null,
                'ultima_prueba_at' => null,
                'creado_por_user_id' => $nueva ? $request->user()->id : $conexion->creado_por_user_id,
                'actualizado_por_user_id' => $request->user()->id,
            ])->save();
            $conexion->eventos()->create([
                'user_id' => $request->user()->id,
                'accion' => $nueva ? 'creada' : 'actualizada',
                'campos_modificados' => $campos,
            ]);

            return $conexion->load('actualizadoPor:id,name');
        });
    }

    private function comprobarVersion(ConexionExterna $conexion, int $version): void
    {
        if ($conexion->version !== $version) {
            throw new ConflictoOperacion('La conexión cambió. Actualiza el listado antes de continuar.');
        }
    }

    private function responder(array $datos, int $estado = 200): JsonResponse
    {
        return response()->json($datos, $estado, ['Cache-Control' => 'private, no-store']);
    }

    private function recurso(#[SensitiveParameter] ConexionExterna $conexion): array
    {
        $credenciales = $conexion->credenciales;

        return [
            ...$conexion->only('id', 'nombre', 'activa', 'version', 'ultima_prueba_estado'),
            'motor' => 'mysql',
            ...array_intersect_key($credenciales, array_flip(['host', 'puerto', 'usuario', 'base_datos'])),
            'password_configurada' => filled($credenciales['password'] ?? null),
            'ultima_prueba_at' => $conexion->ultima_prueba_at?->toAtomString(),
            'actualizado_por' => $conexion->actualizadoPor?->name,
        ];
    }
}
