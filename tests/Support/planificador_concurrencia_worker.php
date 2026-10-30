<?php

use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Cargas\ServicioAcopioCarga;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Planificador\ServicioLaboresTurno;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Proceso aislado de prueba: usa exclusivamente la base de datos de testing del padre.
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('El worker de concurrencia requiere APP_ENV=testing.');
}
$entrada = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
$indice = (int) $argv[3];
$identidad = $entrada['identidades'][$indice];
$directorio = dirname($argv[2]);
config(['planificador.mode' => $entrada['modo'] ?? 'guided', 'planificador.compute' => 'server',
    'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true,
    'planificador.frontier_max' => 4, 'verificaciones.habilitada' => false]);
$usuario = User::findOrFail($identidad['usuario']);
$tablet = Dispositivo::findOrFail($identidad['tablet']);
$planes = app(ServicioPlanesOperacionales::class);
$etapa = 'inicio';
$eventos = [];
$paso = null;
function barrera(string $directorio, string $nombre, int $indice): void
{
    file_put_contents($directorio.'/'.$nombre.'-'.$indice, 'listo');
    $limite = microtime(true) + 30;
    while (count(glob($directorio.'/'.$nombre.'-*')) < 3) {
        if (microtime(true) > $limite) {
            throw new RuntimeException('Venció barrera '.$nombre);
        }
        usleep(5000);
    }
}
try {
    if ($entrada['escenario'] === 'iniciar') {
        $paso = TareaMovimiento::findOrFail($identidad['tarea']);
        barrera($directorio, 'iniciar', $indice);
        try {
            $planes->iniciar($paso, $usuario, $tablet);
            echo json_encode(['estado' => 'iniciada']);
        } catch (ConflictoOperacion $error) {
            echo json_encode(['estado' => 'conflicto', 'mensaje' => $error->getMessage()]);
        }
        exit(0);
    }
    if ($entrada['escenario'] === 'crear_tarea') {
        $folio = Folio::findOrFail($entrada['folio']);
        barrera($directorio, 'crear', $indice);
        try {
            $planes->crear(Temporada::findOrFail($entrada['temporada']), TipoPlanOperacional::AlmacenamientoPallet,
                'Publicación concurrente '.$indice, $usuario, [['folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]],
                contexto: ['planner_horizon' => 'rolling']);
            echo json_encode(['estado' => 'creada']);
        } catch (ConflictoOperacion $error) {
            echo json_encode(['estado' => 'conflicto', 'mensaje' => $error->getMessage()]);
        }
        exit(0);
    }
    if ($entrada['escenario'] === 'pallet_repetido') {
        // Cada proceso comienza con una lectura anterior a la toma rival.
        DB::beginTransaction();
        $paso = TareaMovimiento::findOrFail($identidad['tarea']);
        barrera($directorio, 'lectura', $indice);
        try {
            $planes->asumir($paso, $usuario, $tablet);
            DB::commit();
            echo json_encode(['estado' => 'asumida']);
        } catch (DomainException $error) {
            DB::rollBack();
            echo json_encode(['estado' => 'conflicto', 'mensaje' => $error->getMessage()]);
        }
        exit(0);
    }
    $token = $usuario->crearTokenParaDispositivo($tablet, 'auditoria-tres')->plainTextToken;
    barrera($directorio, 'inicio', $indice);
    $tareas = TareaMovimiento::where('plan_operacional_id', $identidad['plan'])->orderBy('secuencia')->get();
    foreach ($tareas as $n => $paso) {
        $inicio = microtime(true);
        $etapa = 'asumir';
        $paso = $planes->asumir($paso, $usuario, $tablet);
        if ($n % 9 === 2) {
            $etapa = 'liberar_antes_de_iniciar';
            $planes->liberar($paso, $usuario, $tablet);
            $paso = $planes->asumir($paso->refresh(), $usuario, $tablet);
            $eventos[] = ['evento' => 'liberacion_y_retoma', 'tarea' => $paso->id];
        }
        if ($n % 11 === 3) {
            $etapa = 'vencer_lease';
            DB::table('reservas_tareas_movimiento')->where('bloqueo_tarea_id', $paso->id)
                ->update(['vence_at' => now()->subMinute()]);
            app(ServicioReservasTareasMovimiento::class)->expirarVencidas();
            $paso = $planes->asumir($paso->refresh(), $usuario, $tablet);
            $eventos[] = ['evento' => 'lease_recuperado', 'tarea' => $paso->id];
        }
        $reserva = $paso->reservaActiva;
        if (! $reserva || ! $reserva->bloqueo_posicion_id) {
            throw new RuntimeException('El servidor no reservó destino al tomar');
        }
        if ($n % 5 === 0) {
            // Otro camarero no puede iniciar, renovar ni liberar este pallet.
            $rival = $entrada['identidades'][($indice + 1) % 3];
            $otro = User::findOrFail($rival['usuario']);
            $otraTablet = Dispositivo::findOrFail($rival['tablet']);
            foreach (['iniciar', 'renovar', 'liberar'] as $accion) {
                try {
                    $planes->{$accion}($paso, $otro, $otraTablet);
                    throw new RuntimeException('Aceptó '.$accion.' de otro camarero');
                } catch (ConflictoOperacion $esperado) {
                    $eventos[] = ['evento' => 'intrusion_rechazada', 'accion' => $accion, 'tarea' => $paso->id];
                }
            }
        }
        if ($n % 7 === 0) {
            $etapa = 'recuperar_respuesta';
            foreach ([1, 2] as $_) {
                DB::transaction(fn () => app(ServicioManiobrasOperacionales::class)
                    ->resolverDestinoServidor($paso->refresh(), $usuario, $tablet), attempts: 3);
                if ($paso->refresh()->reservaActiva->id !== $reserva->id) {
                    throw new RuntimeException('Duplicó reserva al recuperar respuesta');
                }
            }
        }
        $etapa = 'bandeja';
        $disponibles = app(ServicioLaboresTurno::class)->ordenar(
            TareaMovimiento::with('planOperacional')->whereIn('estado', ['pendiente', 'asumida'])->get(), $usuario->id,
        );
        if ($disponibles->contains(fn ($t) => $t->plan_operacional_id !== $identidad['plan'])) {
            throw new RuntimeException('La bandeja mezcló otra labor durante una labor activa');
        }
        if ($n % 10 === 0) {
            $etapa = 'bandeja_http';
            $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
            $request = Request::create('/api/tareas-movimiento?asignacion=mias&per_page=50', 'GET', [], [], [],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
            $response = $kernel->handle($request);
            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Bandeja HTTP: '.$response->getContent());
            }
            if (! in_array($paso->id, array_column($json['data'], 'id'), true)) {
                throw new RuntimeException('La API ocultó el paso propio en ejecución');
            }
            foreach ($json['data'] as $item) {
                $registro = TareaMovimiento::findOrFail($item['id']);
                if ($registro->responsable_user_id !== $usuario->id || $registro->dispositivo_id !== $tablet->id) {
                    throw new RuntimeException('La API de tareas propias devolvió una tarea ajena');
                }
            }
            $kernel->terminate($request, $response);
            $rival = $entrada['identidades'][($indice + 1) % 3];
            $ajena = TareaMovimiento::where('plan_operacional_id', $rival['plan'])->firstOrFail();
            $request = Request::create('/api/tareas-movimiento/'.$ajena->id.'/estado-propio', 'GET', [], [], [],
                ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
            $response = $kernel->handle($request);
            if ($response->getStatusCode() !== 404) {
                throw new RuntimeException('Consultó el estado propio de una tarea ajena: '.$response->getStatusCode());
            }
            $kernel->terminate($request, $response);
            $eventos[] = ['evento' => 'bandeja_http_verificada', 'tarea' => $paso->id];
        }
        // Mantiene los tres claims en vuelo en cada ronda, antes de confirmar movimientos.
        if ($entrada['sincronizar_rondas'] ?? true) {
            barrera($directorio, 'ronda-'.$n, $indice);
        }
        usleep((($n + $indice * 3) % 7) * 1000);
        $etapa = 'sesiones';
        $sesiones = app(ServicioSesionEstiba::class);
        $destino = $paso->posicionDestino;
        $sesionDestino = $sesiones->abrirParaTarea($destino->camara, $usuario, $tablet, $paso);
        $sesionOrigen = $paso->camara_origen_id
            ? $sesiones->abrirParaTarea($paso->camaraOrigen, $usuario, $tablet, $paso) : null;
        $etapa = 'iniciar';
        $paso = $planes->iniciar($paso, $usuario, $tablet);
        $movimientos = app(ServicioMovimientoEstiba::class);
        $uuid = (string) Str::uuid();
        $fecha = now()->toImmutable();
        $version = $destino->camara->refresh()->version_plano;
        $etapa = 'confirmar';
        if ($sesionOrigen) {
            $movimientos->mover(operacionId: $uuid, folio: $paso->folio, posicionDestino: $destino,
                sesionOrigen: $sesionOrigen, sesionDestino: $sesionDestino, usuario: $usuario, dispositivo: $tablet,
                versionOrigenConocida: $paso->camaraOrigen->refresh()->version_plano, versionDestinoConocida: $version,
                generadoDispositivoAt: $fecha, tareaMovimiento: $paso);
        } else {
            $parametros = ['operacionId' => $uuid, 'numeroFolio' => $paso->folio->numero_folio,
                'tipoBulto' => $paso->folio->tipo_bulto, 'posicionDestino' => $destino, 'sesionDestino' => $sesionDestino,
                'usuario' => $usuario, 'dispositivo' => $tablet, 'versionDestinoConocida' => $version,
                'generadoDispositivoAt' => $fecha, 'tareaMovimiento' => $paso];
            $confirmado = $movimientos->ubicar(...$parametros);
            if ($n % 6 === 0 && $movimientos->ubicar(...$parametros)->id !== $confirmado->id) {
                throw new RuntimeException('El reenvío duplicó el movimiento físico');
            }
        }
        $eventos[] = ['evento' => 'movimiento', 'tarea' => $paso->id, 'folio' => $paso->folio_id,
            'banda' => $destino->banda, 'profundidad' => $destino->posicion, 'nivel' => $destino->nivel,
            'duracion_ms' => round((microtime(true) - $inicio) * 1000, 1)];
    }
    app(ServicioLaboresTurno::class)->contexto(
        Temporada::findOrFail($entrada['temporada']), $usuario,
    );
    echo json_encode(['estado' => 'completado', 'eventos' => $eventos], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $diagnostico = null;
    try {
        if ($paso) {
            $diagnostico = ['tarea' => $paso->refresh()->only(['id', 'estado', 'camara_destino_id', 'posicion_destino_id', 'contexto']),
                'acopio' => app(ServicioAcopioCarga::class)->paraFolio($paso->folio_id, $paso->id),
                'bandas_protegidas' => DB::table('reservas_bandas_maniobra')->whereNotNull('clave_bloqueo')->get()];
        }
    } catch (Throwable) {
        // El diagnóstico no puede ocultar el error original.
    }
    echo json_encode(['estado' => 'error', 'etapa' => $etapa, 'tarea' => $paso?->id,
        'tipo' => get_class($error), 'mensaje' => $error->getMessage(), 'eventos' => $eventos,
        'traza' => $error->getTraceAsString(), 'diagnostico' => $diagnostico], JSON_THROW_ON_ERROR);
    exit(1);
}
