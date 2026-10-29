<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioDestinosServidor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DestinosServidorLecturaConcurrenteTest extends TestCase
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

    public function test_la_preparacion_lee_la_ocupacion_confirmada_por_otra_conexion_despues_del_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Reproduce REPEATABLE READ de MySQL.');
        }
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'server',
            'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $camara = Camara::create(['codigo' => 'CAM-RR', 'nombre' => 'Prueba de concurrencia', 'contenido' => 'productos',
            'estado' => 'activa', 'cantidad_bandas' => 1, 'posiciones_por_banda' => 2, 'cantidad_niveles' => 1,
            'creado_por_user_id' => $supervisor->id, 'actualizado_por_user_id' => $supervisor->id]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $supervisor);
        $posiciones = collect([1, 2])->map(fn ($p) => Posicion::create(['camara_id' => $camara->id,
            'banda' => 1, 'posicion' => $p, 'nivel' => 1, 'etiqueta' => 'B01-P0'.$p.'-N1']));
        $folios = collect([1, 2])->map(fn ($i) => Folio::create(['temporada_id' => $temporada->id,
            'numero_folio' => 'SERVIDOR-RR-'.$i, 'tipo_bulto' => 'pallet', 'fecha_ingreso' => now(), 'activo' => true]));
        $plan = app(ServicioPlanesOperacionales::class)->crear($temporada, TipoPlanOperacional::RecepcionTunel,
            'Recibir pallet', $supervisor, [['folio_id' => $folios[1]->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]],
            contexto: ['planner_horizon' => 'rolling']);
        $tarea = $plan->tareas()->sole();
        config(['database.connections.destino_concurrente' => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection('destino_concurrente');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame(0, UbicacionActual::count());
            $otra->table('ubicaciones_actuales')->insert(['id' => (string) Str::uuid(), 'folio_id' => $folios[0]->id,
                'camara_id' => $camara->id, 'posicion_id' => $posiciones[0]->id, 'ubicado_at' => now(),
                'created_at' => now(), 'updated_at' => now()]);
            $this->assertSame(0, UbicacionActual::count(), 'La lectura ordinaria todavía tiene el snapshot anterior.');
            app(ServicioDestinosServidor::class)->preparar($tarea->maniobraOperacional, $operador);
            $this->assertSame($posiciones[1]->id, $tarea->refresh()->posicion_destino_id);
            $this->assertDatabaseCount('movimientos', 0);
        } finally {
            DB::rollBack();
            DB::purge('destino_concurrente');
        }
    }
}
