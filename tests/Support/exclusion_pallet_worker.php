<?php

use App\Models\Dispositivo;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Estiba\ServicioExclusionPallet;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('La prueba de exclusión requiere APP_ENV=testing.');
}
config(['planificador.mode' => 'off', 'planificador.horizon' => 'rolling']);
DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

try {
    DB::transaction(function () use ($argv): void {
        $tarea = TareaMovimiento::findOrFail($argv[3]);
        $exclusion = app(ServicioExclusionPallet::class);
        $exclusion->bloquearFolios($tarea);
        if ($argv[2] === 'publicar') {
            $tarea = TareaMovimiento::create([
                'plan_operacional_id' => $tarea->plan_operacional_id,
                'folio_id' => $tarea->folio_id, 'secuencia' => $tarea->secuencia + 1,
                'tipo_movimiento' => 'ubicacion_inicial', 'estado' => 'pendiente', 'prioridad' => 'normal',
            ]);
        } elseif ($argv[2] === 'reservar') {
            $tarea = TareaMovimiento::query()->lockForUpdate()->findOrFail($tarea->id);
            // Ambos procesos conservan su propio mutex y tarea antes de validar.
            file_put_contents($argv[6], 'listo');
            $limite = microtime(true) + 15;
            while (! file_exists($argv[7])) {
                if (microtime(true) > $limite) {
                    throw new RuntimeException('La segunda tarea no llegó a la barrera de exclusión.');
                }
                usleep(5000);
            }
        }
        app(ServicioReservasTareasMovimiento::class)->asumir(
            $tarea, User::findOrFail($argv[4]), Dispositivo::findOrFail($argv[5]),
        );
    });
    echo json_encode(['estado' => 'asumida']);
} catch (Throwable $error) {
    echo json_encode(['estado' => 'error', 'clase' => $error::class, 'mensaje' => $error->getMessage()]);
    exit(1);
}
