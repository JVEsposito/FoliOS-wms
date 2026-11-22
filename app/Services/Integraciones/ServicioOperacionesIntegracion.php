<?php

namespace App\Services\Integraciones;

use App\Exceptions\ConflictoOperacion;
use App\Http\Controllers\Api\ConexionExternaController;
use App\Http\Controllers\Api\ConfiguracionStockOrigenController;
use App\Http\Controllers\Api\ExploracionOrigenController;
use App\Jobs\EjecutarOperacionIntegracion;
use App\Models\ConexionExterna;
use App\Models\LecturaStockOrigen;
use App\Models\OperacionIntegracion;
use App\Models\User;
use App\Services\Validacion\ServicioPrecargaValidacionOrigen;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ServicioOperacionesIntegracion
{
    private const PAUSAS_STOCK_MINUTOS = [15, 30, 60];

    public static function permiso(string $tipo): string
    {
        return match ($tipo) {
            'stock' => 'consultar-validaciones-pallet',
            'precarga' => 'validar-pallets',
            'estandares' => 'administrar-estandares-materiales',
            default => 'administrar-accesos',
        };
    }

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

    public function solicitarEstandares(User $usuario): OperacionIntegracion
    {
        $lector = app(ServicioLecturaEstandaresOrigen::class);
        $config = $lector->configuracion();
        if (! $config['habilitada'] || ! $config['conexion'] || ! $config['temporada_origen']) {
            throw new DomainException('Configura y habilita primero la lectura de estándares de planta.');
        }

        return $this->solicitar('estandares', $config['conexion'], ['fuente_hash' => $lector->huella($config)], $usuario);
    }

    public function solicitar(string $tipo, ConexionExterna $conexion, array $entrada, ?User $usuario): OperacionIntegracion
    {
        if (! in_array($tipo, ['stock', 'precarga', 'estandares'], true) && ! isset(self::CONTROLADORES[$tipo])) {
            throw new DomainException('Operación de integración no admitida.');
        }
        if ($usuario) {
            Gate::forUser($usuario)->authorize(self::permiso($tipo));
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
            if ($tipo === 'stock' && ! $usuario && $fuente !== null) {
                $this->comprobarPausaStock($fuente);
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

    private function comprobarPausaStock(string $fuente): void
    {
        $recientes = OperacionIntegracion::where('tipo', 'stock')->where('fuente_hash', $fuente)
            ->whereIn('estado', ['correcta', 'fallida'])->whereNotNull('terminada_at')
            ->latest()->orderByDesc('id')->limit(count(self::PAUSAS_STOCK_MINUTOS))
            ->get(['estado', 'terminada_at']);
        $fallos = $recientes->takeUntil(fn (OperacionIntegracion $op) => $op->estado === 'correcta')->count();
        if ($fallos > 0) {
            $reanudar = $recientes->first()->terminada_at->addMinutes(self::PAUSAS_STOCK_MINUTOS[$fallos - 1]);
            if ($reanudar->isFuture()) {
                throw new DomainException('Lectura programada en pausa tras fallos de origen. Próximo intento a partir de '.$reanudar->toIso8601String().'.');
            }
        }
    }

    public function aceptada(OperacionIntegracion $operacion): JsonResponse
    {
        return response()->json(['operacion' => $this->recurso($operacion),
            'message' => $operacion->tipo === 'precarga' ? 'Consultando los datos de packing para este folio.' : 'Consulta en cola. Puedes seguir utilizando Oficina.'], 202,
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
            if (($op->tipo === 'precarga' && ! $usuario)
                || ($op->user_id && (! $usuario || ! Gate::forUser($usuario)->allows(self::permiso($op->tipo))))) {
                $this->terminar($op, 403, ['message' => 'El usuario ya no tiene permiso para esta operación.']);

                return;
            }
            if ($op->tipo === 'precarga') {
                $lector = app(ServicioLecturaStockOrigen::class);
                $precarga = app(ServicioPrecargaValidacionOrigen::class);
                if ($lector->huellaConfiguracion($lector->configuracion()) !== $op->fuente_hash) {
                    throw new ConflictoOperacion('La fuente de packing cambió. Escanea nuevamente con la configuración vigente.');
                }
                $entrada = $op->entrada;
                $precarga->comprobarFolioNuevo($entrada['numero_folio'], $entrada['temporada_id']);
                $lectura = $lector->leerPallet($entrada['numero_folio'], $usuario, $op->id);
                if (! $lectura) {
                    throw new ConflictoOperacion('Otra lectura de packing está en curso. Reintenta la consulta en unos segundos.');
                }
                $precarga->comprobarFolioNuevo($entrada['numero_folio'], $entrada['temporada_id']);
                $codigo = $lectura->estado === 'correcta' ? 200 : 422;
                $contenido = $codigo === 200
                    ? ['data' => $lectura->pallets > 0 ? $precarga->paraFolio($entrada['numero_folio'])
                        : ['disponible' => false, 'motivo' => 'Packing no tiene este folio en la bodega y filtros configurados. Puedes completar la validación manualmente.']]
                    : ['message' => 'No se pudo consultar packing. '.$lectura->mensaje, 'causa' => $lectura->causa];
            } elseif ($op->tipo === 'stock') {
                $lector = app(ServicioLecturaStockOrigen::class);
                if ($lector->huellaConfiguracion($lector->configuracion()) !== $op->fuente_hash) {
                    throw new ConflictoOperacion('La fuente cambió mientras esperaba. Solicita una lectura nueva.');
                }
                $lectura = $lector->leer($usuario, $op->id);
                $codigo = $lectura?->estado === 'correcta' ? 200 : ($lectura ? 422 : 409);
                $contenido = ['message' => $lectura?->estado === 'correcta' ? "Stock leído: {$lectura->pallets} pallets."
                    : ($lectura?->mensaje ?? 'Otra lectura está en curso. Se conserva la última copia válida.'),
                    'lectura_id' => $lectura?->id, 'causa' => $lectura?->causa];
            } elseif ($op->tipo === 'estandares') {
                $lector = app(ServicioLecturaEstandaresOrigen::class);
                if ($lector->huella($lector->configuracion()) !== $op->fuente_hash) {
                    throw new ConflictoOperacion('La configuración de estándares cambió mientras esperaba. Solicita una lectura nueva.');
                }
                $lectura = $lector->leer($usuario, $op->id);
                $codigo = $lectura->estado === 'correcta' ? 200 : 422;
                $contenido = ['message' => $lectura->estado === 'correcta'
                    ? "Estándares leídos: {$lectura->estandares} de {$lectura->filas} filas."
                    : 'No se pudo leer la vista de estándares. '.$lectura->mensaje, 'lectura_id' => $lectura->id];
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
        $actualizadas = OperacionIntegracion::whereKey($op->id)->where('estado', 'en_curso')->update([
            'estado' => $codigo < 400 ? 'correcta' : ($codigo === 409 ? 'obsoleta' : 'fallida'),
            'fase' => $codigo < 400 ? 'Consulta finalizada' : 'Consulta no publicada; revisa el resultado',
            'entrada' => null, 'clave_activa' => null, 'terminada_at' => now(),
            // El cast se aplica explícitamente: Query Builder no cifra arrays.
            'resultado' => encrypt(json_encode(['codigo_http' => $codigo, 'contenido' => $contenido], JSON_THROW_ON_ERROR), false),
            'resultado_hasta' => now()->addMinutes(5),
        ]);
        if ($actualizadas && $codigo >= 400 && $codigo !== 409) {
            Log::warning('Falló una consulta de integración', [
                'operacion_id' => $op->id, 'tipo' => $op->tipo, 'conexion_id' => $op->conexion_externa_id,
                'codigo_http' => $codigo, 'causa' => $contenido['causa'] ?? null,
            ]);
        }
    }

    public function exigirVigente(?string $id): void
    {
        if ($id && ! OperacionIntegracion::whereKey($id)->where('estado', 'en_curso')->lockForUpdate()->first(['id'])) {
            throw new ConflictoOperacion('La operación venció o fue interrumpida. No se publicó su resultado.');
        }
    }

    public function interrumpir(string $id): void
    {
        $actualizadas = OperacionIntegracion::whereKey($id)->whereIn('estado', ['en_cola', 'en_curso'])->update([
            'estado' => 'fallida', 'fase' => 'Proceso interrumpido. Solicita otra consulta.', 'entrada' => null,
            'resultado' => null, 'resultado_hasta' => null, 'clave_activa' => null, 'terminada_at' => now(),
        ]);
        if ($actualizadas) {
            Log::warning('El worker interrumpió una consulta de integración', ['operacion_id' => $id]);
        }
    }

    public function limpiar(): void
    {
        $ahora = now();
        $vencidas = OperacionIntegracion::whereIn('estado', ['en_cola', 'en_curso'])->where('updated_at', '<', $ahora->copy()->subMinutes(15))
            ->update(['estado' => 'fallida', 'fase' => 'La consulta no terminó. Revisa el proceso de integración.',
                'entrada' => null, 'resultado' => null, 'resultado_hasta' => null, 'clave_activa' => null, 'terminada_at' => $ahora]);
        if ($vencidas) {
            Log::warning('Vencieron consultas de integración sin terminar', ['cantidad' => $vencidas]);
        }

        LecturaStockOrigen::where('estado', 'en_curso')
            ->whereIn('operacion_integracion_id', OperacionIntegracion::where('estado', 'fallida')->select('id'))
            ->update(['estado' => 'fallida', 'causa' => 'operacion_interrumpida',
                'mensaje' => 'La lectura se interrumpió. Se conserva la última copia válida.', 'terminada_at' => $ahora]);
        OperacionIntegracion::where('resultado_hasta', '<=', $ahora)->update(['resultado' => null, 'resultado_hasta' => null]);
        OperacionIntegracion::whereNotIn('estado', ['en_cola', 'en_curso'])->where('created_at', '<', $ahora->copy()->subDays(7))->delete();
    }
}
