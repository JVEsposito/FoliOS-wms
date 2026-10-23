<?php

namespace App\Services\Integraciones;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Api\ConexionExternaController;
use App\Http\Controllers\Api\ConfiguracionStockOrigenController;
use App\Http\Controllers\Api\ExploracionOrigenController;
use App\Jobs\EjecutarOperacionIntegracion;
use App\Models\ConexionExterna;
use App\Models\OperacionIntegracion;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class ServicioOperacionesIntegracion
{
    private const CONTROLADORES = [
        'probar' => [ConexionExternaController::class, 'probar'],
        'estructura' => [ExploracionOrigenController::class, 'estructura'],
        'columnas' => [ExploracionOrigenController::class, 'columnas'],
        'muestra' => [ExploracionOrigenController::class, 'muestra'],
        'mapeo_muestra' => [ConfiguracionStockOrigenController::class, 'simular'],
        'mapeo_guardar' => [ConfiguracionStockOrigenController::class, 'guardar'],
    ];

    public function diferir(Request $request, string $tipo, ConexionExterna $conexion, array $entrada): ?JsonResponse
    {
        if (! str_contains(strtolower((string) $request->header('Prefer')), 'respond-async')) {
            return null; // Compatibilidad de API; las pantallas actuales siempre solicitan la cola.
        }

        return $this->aceptada($this->solicitar($tipo, $conexion, $entrada, $request->user()));
    }

    public function solicitarStock(?User $usuario = null): OperacionIntegracion
    {
        $lector = app(ServicioLecturaStockOrigen::class);
        $config = $lector->configuracion();
        if (! $config['habilitada'] || ! $config['conexion']) {
            throw new DomainException('La lectura de stock no está configurada o está deshabilitada.');
        }

        return $this->solicitar('stock', $config['conexion'], ['fuente_hash' => $lector->huellaConfiguracion($config)], $usuario);
    }

    public function solicitar(string $tipo, ConexionExterna $conexion, array $entrada, ?User $usuario): OperacionIntegracion
    {
        if ($tipo !== 'stock' && ! isset(self::CONTROLADORES[$tipo])) {
            throw new DomainException('Operación de integración no admitida.');
        }
        if ($usuario) {
            Gate::forUser($usuario)->authorize($tipo === 'stock' ? 'consultar-validaciones-pallet' : 'administrar-accesos');
        } elseif ($tipo !== 'stock') {
            throw new DomainException('La operación requiere un usuario autorizado.');
        }
        $fuente = $entrada['fuente_hash'] ?? null;
        $clave = hash('sha256', json_encode([$tipo, $conexion->id, $conexion->version, $tipo === 'stock' ? null : $usuario->id, $entrada], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tipo, $conexion, $entrada, $usuario, $fuente, $clave): OperacionIntegracion {
            $vigente = ConexionExterna::lockForUpdate()->findOrFail($conexion->id);
            if (! $vigente->activa || $vigente->version !== $conexion->version) {
                throw new ConflictoOperacion('La conexión cambió. Actualiza antes de solicitar otra consulta.');
            }
            $anterior = OperacionIntegracion::where('clave_activa', $clave)->first();
            if ($anterior) {
                return $anterior;
            }
            $operacion = OperacionIntegracion::create([
                'tipo' => $tipo, 'conexion_externa_id' => $conexion->id, 'version_conexion' => $conexion->version,
                'user_id' => $usuario?->id, 'fuente_hash' => $fuente, 'clave_activa' => $clave,
                'estado' => 'en_cola', 'fase' => 'Esperando el proceso de integración', 'entrada' => $entrada,
            ]);
            // Esta conexión usa la misma BD operacional: fila y job se confirman juntos.
            Bus::dispatch(new EjecutarOperacionIntegracion($operacion->id));

            return $operacion;
        }, 3);
    }

    public function aceptada(OperacionIntegracion $operacion): JsonResponse
    {
        return response()->json(['operacion' => $this->recurso($operacion),
            'message' => 'Consulta en cola. Puedes seguir utilizando Oficina.'], 202,
            ['Cache-Control' => 'private, no-store', 'Location' => '/api/integraciones/operaciones/'.$operacion->id, 'Preference-Applied' => 'respond-async']);
    }

    public function recurso(OperacionIntegracion $op): array
    {
        $datos = [...$op->only('id', 'tipo', 'estado', 'fase'), 'creada_at' => $op->created_at->toAtomString(),
            'iniciada_at' => $op->iniciada_at?->toAtomString(), 'terminada_at' => $op->terminada_at?->toAtomString(),
            'url' => '/api/integraciones/operaciones/'.$op->id];
        if ($op->resultado_hasta?->isFuture()) {
            $datos['respuesta'] = $op->resultado;
        } elseif (! in_array($op->estado, ['en_cola', 'en_curso'], true)) {
            $datos['respuesta'] = ['codigo_http' => 410, 'contenido' => ['message' => 'El resultado temporal venció. Solicita una consulta nueva.']];
        }

        return $datos;
    }

    public function ejecutar(string $id): void
    {
        $op = DB::transaction(function () use ($id) {
            $op = OperacionIntegracion::lockForUpdate()->find($id);
            if (! $op || $op->estado !== 'en_cola') {
                return null;
            }
            $op->update(['estado' => 'en_curso', 'fase' => 'Consultando el servidor de origen', 'iniciada_at' => now()]);

            return $op;
        }, 3);
        if (! $op) {
            return;
        }
        try {
            $conexion = ConexionExterna::findOrFail($op->conexion_externa_id);
            if (! $conexion->activa || $conexion->version !== $op->version_conexion) {
                throw new ConflictoOperacion('La conexión cambió mientras esperaba. No se ejecutó la selección anterior.');
            }
            $usuario = $op->user_id ? User::find($op->user_id) : null;
            if ($op->user_id && (! $usuario || ! Gate::forUser($usuario)->allows($op->tipo === 'stock' ? 'consultar-validaciones-pallet' : 'administrar-accesos'))) {
                $this->terminar($op, 403, ['message' => 'El usuario ya no tiene permiso para esta operación.']);

                return;
            }
            if ($op->tipo === 'stock') {
                $lector = app(ServicioLecturaStockOrigen::class);
                if ($lector->huellaConfiguracion($lector->configuracion()) !== $op->fuente_hash) {
                    throw new ConflictoOperacion('La fuente cambió mientras esperaba. Solicita una lectura nueva.');
                }
                $lectura = $lector->leer($usuario, $op->id);
                $codigo = $lectura?->estado === 'correcta' ? 200 : ($lectura ? 422 : 409);
                $contenido = ['message' => $lectura?->estado === 'correcta' ? "Stock leído: {$lectura->pallets} pallets."
                    : ($lectura?->mensaje ?? 'Otra lectura está en curso. Se conserva la última copia válida.'),
                    'lectura_id' => $lectura?->id, 'causa' => $lectura?->causa];
            } else {
                $request = Request::create('/api/administracion/conexiones-externas/interna', $op->tipo === 'mapeo_guardar' ? 'PUT' : 'POST', [], [], [],
                    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($op->entrada, JSON_THROW_ON_ERROR));
                $request->setUserResolver(fn () => $usuario);
                $request->attributes->set('integracion_id', $op->id);
                [$clase, $metodo] = self::CONTROLADORES[$op->tipo];
                $response = app()->call([app($clase), $metodo], ['request' => $request, 'conexionExterna' => $conexion]);
                $codigo = $response->getStatusCode();
                $contenido = $response->getData(true);
            }
            $this->terminar($op, $codigo, $contenido);
        } catch (Throwable $e) {
            $codigo = $e instanceof ConflictoOperacion ? 409 : 422;
            $contenido = $e instanceof ConflictoOperacion || $e instanceof DomainException
                ? ['message' => $e->getMessage()]
                : ($e instanceof ValidationException ? ['message' => 'La selección ya no es válida. Revisa sus campos.', 'errors' => $e->errors()]
                    : ['message' => ConectorMySqlLectura::diagnosticar($e)['mensaje']]);
            $this->terminar($op, $codigo, $contenido);
        }
    }

    private function terminar(OperacionIntegracion $op, int $codigo, array $contenido): void
    {
        OperacionIntegracion::whereKey($op->id)->where('estado', 'en_curso')->update([
            'estado' => $codigo < 400 ? 'correcta' : ($codigo === 409 ? 'obsoleta' : 'fallida'),
            'fase' => $codigo < 400 ? 'Consulta finalizada' : 'Consulta no publicada; revisa el resultado',
            'entrada' => null, 'clave_activa' => null, 'terminada_at' => now(),
            // El cast se aplica explícitamente: Query Builder no cifra arrays.
            'resultado' => encrypt(json_encode(['codigo_http' => $codigo, 'contenido' => $contenido], JSON_THROW_ON_ERROR), false),
            'resultado_hasta' => now()->addMinutes(5),
        ]);
    }

    public function exigirVigente(?string $id): void
    {
        if ($id && ! OperacionIntegracion::whereKey($id)->where('estado', 'en_curso')->lockForUpdate()->first(['id'])) {
            throw new ConflictoOperacion('La operación venció o fue interrumpida. No se publicó su resultado.');
        }
    }

    public function interrumpir(string $id): void
    {
        OperacionIntegracion::whereKey($id)->whereIn('estado', ['en_cola', 'en_curso'])->update([
            'estado' => 'fallida', 'fase' => 'Proceso interrumpido. Solicita otra consulta.', 'entrada' => null,
            'resultado' => null, 'resultado_hasta' => null, 'clave_activa' => null, 'terminada_at' => now(),
        ]);
    }

    public function limpiar(): void
    {
        OperacionIntegracion::whereIn('estado', ['en_cola', 'en_curso'])->where('updated_at', '<', now()->subMinutes(15))
            ->update(['estado' => 'fallida', 'fase' => 'La consulta no terminó. Revisa el proceso de integración.',
                'entrada' => null, 'resultado' => null, 'clave_activa' => null, 'terminada_at' => now()]);
        OperacionIntegracion::where('resultado_hasta', '<=', now())->update(['resultado' => null]);
        OperacionIntegracion::whereNotIn('estado', ['en_cola', 'en_curso'])->where('created_at', '<', now()->subDays(7))->delete();
    }
}
