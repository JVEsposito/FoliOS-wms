<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\ConexionExterna;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ExploradorMySql;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ExploracionOrigenController extends Controller
{
    public function estructura(Request $request, ConexionExterna $conexionExterna, ExploradorMySql $explorador): JsonResponse
    {
        return $this->ejecutar($request, $conexionExterna, [], fn () => $explorador->objetos($conexionExterna->credenciales), 'estructura_consultada');
    }

    public function columnas(Request $request, ConexionExterna $conexionExterna, ExploradorMySql $explorador): JsonResponse
    {
        return $this->ejecutar($request, $conexionExterna, ['tabla' => ['required', 'string', 'max:64']],
            fn ($datos) => $explorador->columnas($conexionExterna->credenciales, $datos['tabla']), 'columnas_consultadas');
    }

    public function muestra(Request $request, ConexionExterna $conexionExterna, ExploradorMySql $explorador): JsonResponse
    {
        return $this->ejecutar($request, $conexionExterna, [
            'tabla' => ['required', 'string', 'max:64'], 'columnas' => ['required', 'array', 'min:1', 'max:40'],
            'columnas.*' => ['required', 'string', 'max:64', 'distinct'], 'limite' => ['required', 'integer', 'between:1,20'],
            'filtros' => ['present', 'array', 'max:8'], 'filtros.*' => ['required', 'array:columna,valor'],
            'filtros.*.columna' => ['required', 'string', 'max:64'], 'filtros.*.valor' => ['present', 'string', 'max:180'],
        ], fn ($datos) => $explorador->muestra($conexionExterna->credenciales, $datos['tabla'], $datos['columnas'], $datos['filtros'], $datos['limite']), 'muestra_consultada');
    }

    private function ejecutar(Request $request, ConexionExterna $conexion, array $reglas, callable $leer, string $accion): JsonResponse
    {
        $datos = $request->validate(['version_conocida' => ['required', 'integer', 'min:1'], ...$reglas]);
        $this->vigente($conexion, (int) $datos['version_conocida']);
        $tipo = ['estructura_consultada' => 'estructura', 'columnas_consultadas' => 'columnas', 'muestra_consultada' => 'muestra'][$accion];
        if ($aceptada = app(ServicioOperacionesIntegracion::class)->diferir($request, $tipo, $conexion, $datos)) {
            return $aceptada;
        }
        try {
            $resultado = $leer($datos);
        } catch (Throwable $error) {
            $diagnostico = $error instanceof DomainException
                ? ['causa' => 'seleccion_invalida', 'mensaje' => $error->getMessage()]
                : ConectorMySqlLectura::diagnosticar($error);

            return response()->json(['message' => $diagnostico['mensaje'], 'causa' => $diagnostico['causa']], 422, ['Cache-Control' => 'private, no-store']);
        }
        $this->vigente($conexion->fresh(), (int) $datos['version_conocida']);
        $conexion->eventos()->create(['user_id' => $request->user()->id, 'accion' => $accion, 'campos_modificados' => []]);

        return response()->json(['data' => $resultado], 200, ['Cache-Control' => 'private, no-store']);
    }

    private function vigente(?ConexionExterna $conexion, int $version): void
    {
        if (! $conexion || $conexion->version !== $version || ! $conexion->activa) {
            throw new ConflictoOperacion('La conexión cambió o está inactiva. Actualice el listado antes de explorar.');
        }
    }
}
