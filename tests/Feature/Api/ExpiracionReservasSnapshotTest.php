<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpiracionReservasSnapshotTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_el_barrido_no_libera_una_tarea_iniciada_despues_de_su_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requiere dos conexiones y lecturas actuales de MySQL.');
        }
        config(['planificador.mode' => 'off', 'planificador.generacion_automatica' => false]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $tablet = Dispositivo::create(['codigo' => 'LEASE-SNAPSHOT', 'nombre' => 'Tablet de prueba']);
        $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'LEASE-SNAPSHOT',
            'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()]);
        $plan = PlanOperacional::create(['temporada_id' => $temporada->id, 'tipo' => 'almacenamiento_pallet',
            'estado' => 'en_ejecucion', 'prioridad' => 'normal', 'titulo' => 'Reserva de prueba',
            'creado_por_user_id' => $usuario->id, 'programado_at' => now()]);
        $tarea = TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'folio_id' => $folio->id,
            'tipo_movimiento' => 'retiro', 'secuencia' => 1, 'estado' => 'asumida',
            'responsable_user_id' => $usuario->id, 'dispositivo_id' => $tablet->id]);
        $reserva = ReservaTareaMovimiento::create(['tarea_movimiento_id' => $tarea->id,
            'bloqueo_tarea_id' => $tarea->id, 'estado' => 'activa', 'user_id' => $usuario->id,
            'dispositivo_id' => $tablet->id, 'reservada_at' => now()->subMinutes(20),
            'renovada_at' => now()->subMinutes(20), 'vence_at' => now()->subMinute()]);
        config(['database.connections.lease_concurrente' => config('database.connections.'.DB::getDefaultConnection())]);
        $otraConexion = DB::connection('lease_concurrente');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame('asumida', DB::table('tareas_movimiento')->where('id', $tarea->id)->value('estado'));
            // Otra transacción inicia el movimiento después de la lectura del barrido.
            $otraConexion->table('tareas_movimiento')->where('id', $tarea->id)->update(['estado' => 'en_proceso']);
            $this->assertSame(0, app(ServicioReservasTareasMovimiento::class)->expirarVencidas());
            $this->assertSame('activa', $otraConexion->table('reservas_tareas_movimiento')->where('id', $reserva->id)->value('estado'));
            $this->assertSame($tarea->id, $otraConexion->table('reservas_tareas_movimiento')->where('id', $reserva->id)->value('bloqueo_tarea_id'));
        } finally {
            DB::rollBack();
            DB::purge('lease_concurrente');
        }
        $this->assertSame('en_proceso', DB::table('tareas_movimiento')->where('id', $tarea->id)->value('estado'));
        // La misma reserva sí vence cuando su tarea queda realmente sin movimiento.
        DB::table('tareas_movimiento')->where('id', $tarea->id)->update(['estado' => 'asumida']);
        $this->assertSame(1, app(ServicioReservasTareasMovimiento::class)->expirarVencidas());
        $this->assertDatabaseHas('reservas_tareas_movimiento', ['id' => $reserva->id, 'estado' => 'expirada', 'bloqueo_tarea_id' => null]);
        $this->assertDatabaseHas('tareas_movimiento', ['id' => $tarea->id, 'estado' => 'pendiente', 'responsable_user_id' => null]);
    }
}
