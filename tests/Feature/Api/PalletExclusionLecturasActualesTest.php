<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PalletExclusionLecturasActualesTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_pallets_independientes_pueden_validar_y_reservar_con_ambas_tareas_bloqueadas(): void
    {
        $this->requiereMysql();
        $directorio = sys_get_temp_dir().'/exclusion-independiente-'.bin2hex(random_bytes(8));
        mkdir($directorio);
        $procesos = [];
        try {
            foreach ([1, 2] as $n) {
                [$tarea, $usuario, $tablet] = $this->identidad($n);
                $proceso = $this->proceso('reservar', $tarea, $usuario, $tablet, $directorio.'/ready'.$n, $directorio.'/go');
                $proceso->start();
                $procesos[] = $proceso;
            }
            $limite = microtime(true) + 15;
            while ((! file_exists($directorio.'/ready1') || ! file_exists($directorio.'/ready2')) && microtime(true) < $limite) {
                usleep(5000);
            }
            $this->assertFileExists($directorio.'/ready1');
            $this->assertFileExists($directorio.'/ready2');
            file_put_contents($directorio.'/go', 'continuar');
            foreach ($procesos as $proceso) {
                $proceso->wait();
                $this->assertTrue($proceso->isSuccessful(), $proceso->getOutput().$proceso->getErrorOutput());
                $this->assertSame('asumida', json_decode($proceso->getOutput(), true, flags: JSON_THROW_ON_ERROR)['estado']);
            }
            $this->assertDatabaseCount('reservas_tareas_movimiento', 2);
            $this->assertSame(2, TareaMovimiento::where('estado', 'asumida')->count());
            $this->assertDatabaseCount('movimientos', 0);
        } finally {
            foreach ($procesos as $proceso) {
                if ($proceso->isRunning()) {
                    $proceso->stop();
                }
            }
            foreach (glob($directorio.'/*') as $archivo) {
                unlink($archivo);
            }
            rmdir($directorio);
        }
    }

    public function test_un_snapshot_previo_no_oculta_una_tarea_publicada_y_tomada_despues(): void
    {
        $this->requiereMysql();
        config(['planificador.mode' => 'off', 'planificador.horizon' => 'rolling']);
        [$tarea, $usuario, $tablet] = $this->identidad(1);
        $rival = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $otraTablet = Dispositivo::create(['codigo' => 'EXCLUSION-RIVAL', 'nombre' => 'Rival']);
        DB::beginTransaction();
        try {
            TareaMovimiento::findOrFail($tarea->id); // Fija una lectura anterior a la publicación rival.
            $proceso = $this->proceso('publicar', $tarea, $rival, $otraTablet);
            $proceso->run();
            $this->assertTrue($proceso->isSuccessful(), $proceso->getOutput().$proceso->getErrorOutput());
            try {
                app(ServicioPlanesOperacionales::class)->asumir($tarea, $usuario, $tablet);
                $this->fail('La toma autorizó otro claim del pallet usando un snapshot anterior.');
            } catch (ConflictoOperacion $error) {
                $this->assertStringContainsString('otra labor tomada', $error->getMessage());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('reservas_tareas_movimiento', 1);
        $this->assertSame(1, TareaMovimiento::where('estado', 'asumida')->count());
        $this->assertSame(1, TareaMovimiento::where('estado', 'pendiente')->count());
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_el_dueno_recupera_su_reserva_creada_despues_del_snapshot_sin_duplicarla(): void
    {
        $this->requiereMysql();
        config(['planificador.mode' => 'off', 'planificador.horizon' => 'rolling']);
        [$tarea, $usuario, $tablet] = $this->identidad(1);
        DB::beginTransaction();
        try {
            TareaMovimiento::findOrFail($tarea->id);
            $proceso = $this->proceso('tomar', $tarea, $usuario, $tablet);
            $proceso->run();
            $this->assertTrue($proceso->isSuccessful(), $proceso->getOutput().$proceso->getErrorOutput());
            $retomada = app(ServicioPlanesOperacionales::class)->asumir($tarea, $usuario, $tablet);
            $this->assertSame($usuario->id, $retomada->responsable_user_id);
            $this->assertSame($tablet->id, $retomada->dispositivo_id);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('reservas_tareas_movimiento', 1);
        $this->assertSame(1, TareaMovimiento::where('estado', 'asumida')->count());
        $this->assertDatabaseCount('movimientos', 0);
    }

    private function identidad(int $numero): array
    {
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $tablet = Dispositivo::create(['codigo' => 'EXCLUSION-'.$numero, 'nombre' => 'Tablet '.$numero]);
        $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'EXCLUSION-'.$numero,
            'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()]);
        $plan = PlanOperacional::create(['temporada_id' => $temporada->id, 'tipo' => 'almacenamiento_pallet',
            'estado' => 'programado', 'prioridad' => 'normal', 'titulo' => 'Recepción '.$numero,
            'creado_por_user_id' => $usuario->id, 'programado_at' => now(), 'contexto' => ['planner_horizon' => 'rolling']]);
        $tarea = TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'folio_id' => $folio->id,
            'secuencia' => 1, 'tipo_movimiento' => 'ubicacion_inicial', 'estado' => 'pendiente', 'prioridad' => 'normal']);

        return [$tarea, $usuario, $tablet];
    }

    private function proceso(string $escenario, TareaMovimiento $tarea, User $usuario, Dispositivo $tablet, string ...$barrera): Process
    {
        $proceso = new Process([PHP_BINARY, base_path('tests/Support/exclusion_pallet_worker.php'), base_path(),
            $escenario, $tarea->id, (string) $usuario->id, $tablet->id, ...$barrera], base_path());
        $proceso->setTimeout(25);

        return $proceso;
    }

    private function requiereMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La prueba reproduce bloqueos y snapshots de MySQL.');
        }
    }
}
