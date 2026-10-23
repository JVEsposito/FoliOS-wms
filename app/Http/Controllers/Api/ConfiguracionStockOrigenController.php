<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Controller;
use App\Models\ConexionExterna;
use App\Models\ConfiguracionOrigen;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ExploradorMySql;
use App\Services\Integraciones\MapeoStockOrigen;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConfiguracionStockOrigenController extends Controller
{
    public function index(): JsonResponse
    {
        $perfil = ConfiguracionOrigen::find(ConfiguracionOrigen::STOCK);

        return $this->respuesta([
            'version' => $perfil?->version ?? 0, 'conexion_id' => $perfil?->conexion_externa_id,
            'ajustes' => $perfil?->ajustes, 'campos' => MapeoStockOrigen::CAMPOS,
        ]);
    }

    public function simular(Request $request, ExploradorMySql $explorador, MapeoStockOrigen $mapeo, ConectorMySqlLectura $conector): JsonResponse
    {
        return $this->ejecutar($request, $explorador, $mapeo, function ($datos, $conexion, $consulta) use ($conector, $mapeo, $request): array {
            $filas = $conector->consultar($conexion->credenciales, $consulta[0], $consulta[1],
                ConectorMySqlLectura::segundosMuestra($request->attributes->has('integracion_id'), config('integraciones.consultas.muestra_segundos', 60)));
            $mapeo->validarFilas($filas);
            $this->vigente($conexion->fresh(), $datos['version_conexion']);

            return ['columnas' => array_keys(MapeoStockOrigen::CAMPOS), 'filas' => $filas];
        }, true);
    }

    public function guardar(Request $request, ExploradorMySql $explorador, MapeoStockOrigen $mapeo): JsonResponse
    {
        return $this->ejecutar($request, $explorador, $mapeo, function ($datos, $conexion) use ($request): array {
            return DB::transaction(function () use ($datos, $conexion, $request): array {
                $perfil = ConfiguracionOrigen::query()->whereKey(ConfiguracionOrigen::STOCK)->lockForUpdate()->first();
                $this->vigente(ConexionExterna::query()->whereKey($conexion->id)->lockForUpdate()->first(), $datos['version_conexion']);
                if (($perfil?->version ?? 0) !== $datos['version_conocida']) {
                    throw new ConflictoOperacion('La configuración cambió. Vuelva a abrir el mapeo antes de guardar.');
                }
                app(ServicioOperacionesIntegracion::class)->exigirVigente($request->attributes->get('integracion_id'));
                $perfil ??= new ConfiguracionOrigen(['clave' => ConfiguracionOrigen::STOCK]);
                $perfil->fill([
                    'conexion_externa_id' => $conexion->id, 'ajustes' => $datos['ajustes'],
                    'version' => $datos['version_conocida'] + 1, 'actualizado_por_user_id' => $request->user()->id,
                ])->save();
                $conexion->eventos()->create([
                    'user_id' => $request->user()->id, 'accion' => 'mapeo_stock_guardado',
                    'campos_modificados' => ['fuente', 'campos', 'filtros', 'habilitada'],
                ]);

                return ['version' => $perfil->version];
            }, 3);
        });
    }

    private function ejecutar(Request $request, ExploradorMySql $explorador, MapeoStockOrigen $mapeo, callable $operacion, bool $muestra = false): JsonResponse
    {
        $datos = $request->validate([
            'conexion_id' => ['required', 'uuid'], 'version_conexion' => ['required', 'integer', 'min:1'],
            'version_conocida' => ['required', 'integer', 'min:0'],
            'ajustes' => ['required', 'array:tabla,campos,filtros,habilitada'],
            'ajustes.tabla' => ['required', 'string', 'max:64'], 'ajustes.habilitada' => ['required', 'boolean'],
            'ajustes.campos' => ['required', 'array', 'max:28'], 'ajustes.campos.*' => ['nullable', 'string', 'max:64'],
            'ajustes.campos.Pallet' => ['required', 'string', 'max:64'], 'ajustes.campos.cantidad' => ['required', 'string', 'max:64'],
            'ajustes.filtros' => ['present', 'array', 'max:8'], 'ajustes.filtros.*' => ['required', 'array:columna,valor'],
            'ajustes.filtros.*.columna' => ['required', 'string', 'max:64'], 'ajustes.filtros.*.valor' => ['present', 'string', 'max:180'],
        ]);
        $datos['version_conexion'] = (int) $datos['version_conexion'];
        $datos['version_conocida'] = (int) $datos['version_conocida'];
        $datos['ajustes']['habilitada'] = (bool) $datos['ajustes']['habilitada'];
        $conexion = ConexionExterna::findOrFail($datos['conexion_id']);
        $this->vigente($conexion, $datos['version_conexion']);
        if ($aceptada = app(ServicioOperacionesIntegracion::class)->diferir($request, $muestra ? 'mapeo_muestra' : 'mapeo_guardar', $conexion, $datos)) {
            return $aceptada;
        }
        try {
            $columnas = $explorador->columnas($conexion->credenciales, $datos['ajustes']['tabla']);
            $consulta = $mapeo->consulta($datos['ajustes'], $columnas, $muestra);

            return $this->respuesta($operacion($datos, $conexion, $consulta));
        } catch (ConflictoOperacion $error) {
            throw $error;
        } catch (Throwable $error) {
            $mensaje = $error instanceof DomainException ? $error->getMessage() : ConectorMySqlLectura::diagnosticar($error)['mensaje'];

            return response()->json(['message' => $mensaje], 422, ['Cache-Control' => 'private, no-store']);
        }
    }

    private function vigente(?ConexionExterna $conexion, int $version): void
    {
        if (! $conexion || ! $conexion->activa || $conexion->version !== $version) {
            throw new ConflictoOperacion('La conexión cambió o está inactiva. Actualice el listado y vuelva a explorar.');
        }
    }

    private function respuesta(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, no-store']);
    }
}
