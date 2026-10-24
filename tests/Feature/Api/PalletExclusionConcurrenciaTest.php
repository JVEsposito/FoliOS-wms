<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PalletExclusionConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_dos_tablets_con_snapshot_previo_no_toman_tareas_distintas_del_mismo_pallet(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La prueba requiere bloqueos MySQL.');
        }
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'PAL-CONCURRENTE', 'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()]);
        $identidades = collect([1, 2])->map(function (int $n) use ($temporada, $folio): array {
            $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
            $dispositivo = Dispositivo::create(['codigo' => 'RACE-'.$n, 'nombre' => 'Tablet '.$n]);
            $plan = PlanOperacional::create(['temporada_id' => $temporada->id, 'tipo' => 'almacenamiento_pallet',
                'estado' => 'programado', 'prioridad' => 'normal', 'titulo' => 'Labor '.$n, 'creado_por_user_id' => $usuario->id,
                'programado_at' => now(), 'contexto' => ['planner_horizon' => 'rolling']]);
            $tarea = TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'secuencia' => 1, 'tipo_movimiento' => 'ubicacion_inicial',
                'estado' => 'pendiente', 'prioridad' => 'normal', 'folio_id' => $folio->id]);

            return [$tarea->id, (string) $usuario->id, $dispositivo->id];
        });
        $dir = sys_get_temp_dir().'/pallet-exclusion-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $worker = $dir.'/worker.php';
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['planificador.mode' => 'off', 'planificador.horizon' => 'rolling']);
Illuminate\Support\Facades\DB::beginTransaction();
$tarea = App\Models\TareaMovimiento::findOrFail($argv[2]);
file_put_contents($argv[5], 'snapshot');
$deadline = microtime(true) + 15;
while (!file_exists($argv[6]) && microtime(true) < $deadline) usleep(10000);
try {
    app(App\Services\Estiba\ServicioPlanesOperacionales::class)->asumir($tarea,
        App\Models\User::findOrFail($argv[3]), App\Models\Dispositivo::findOrFail($argv[4]));
    Illuminate\Support\Facades\DB::commit();
    print json_encode(['estado' => 'asumida']);
} catch (DomainException $e) {
    Illuminate\Support\Facades\DB::rollBack();
    print json_encode(['estado' => 'conflicto', 'mensaje' => $e->getMessage()]);
}
PHP);
        $procesos = [];
        try {
            foreach ($identidades as $n => $identidad) {
                $p = new Process([PHP_BINARY, $worker, base_path(), ...$identidad, $dir.'/ready'.$n, $dir.'/go'], base_path());
                $p->setTimeout(30);
                $p->start();
                $procesos[] = $p;
            }
            $deadline = microtime(true) + 15;
            while ((! file_exists($dir.'/ready0') || ! file_exists($dir.'/ready1')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            file_put_contents($dir.'/go', 'go');
            $estados = [];
            foreach ($procesos as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
                $estados[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR)['estado'];
            }
            sort($estados);
            $this->assertSame(['asumida', 'conflicto'], $estados);
            $this->assertDatabaseCount('reservas_tareas_movimiento', 1);
            $this->assertSame(1, TareaMovimiento::query()->where('estado', 'asumida')->count());
            $this->assertSame(1, TareaMovimiento::query()->where('estado', 'pendiente')->count());
            $this->assertDatabaseCount('movimientos', 0);
        } finally {
            foreach ($procesos as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
