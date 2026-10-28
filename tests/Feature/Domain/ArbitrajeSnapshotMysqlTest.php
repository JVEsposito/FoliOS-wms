<?php

namespace Tests\Feature\Domain;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\CicloArbitrajeManiobras;
use App\Models\ConfirmacionInicioTarea;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Autenticacion\ServicioPinOperacional;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioArbitrajeManiobras;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArbitrajeSnapshotMysqlTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_reutiliza_el_ciclo_y_sus_decisiones_y_confirma_folio_pin_con_un_snapshot_exterior_anterior(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requiere lecturas actuales y snapshots REPEATABLE READ de MySQL.');
        }
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'SNAPSHOT-ARB', 'nombre' => 'Tablet arbitraje', 'activo' => true]);
        $token = $camarero->crearTokenParaDispositivo($dispositivo, 'snapshot-arbitraje')->plainTextToken;
        $camara = Camara::create(['codigo' => 'CAM-SNAPSHOT', 'nombre' => 'Cámara snapshot',
            'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $supervisor);
        $posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => '1000000056',
            'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now()]);
        $planes = app(ServicioPlanesOperacionales::class);
        $plan = $planes->crear($temporada, TipoPlanOperacional::AlmacenamientoPallet, 'Iniciar sin repetir por un ciclo concurrente',
            $supervisor, tareas: [['folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]],
            contexto: ['planner_horizon' => 'rolling']);
        $tarea = $plan->tareas()->sole();
        $planes->asumir($tarea, $camarero, $dispositivo);
        $planes->materializarDestino($tarea->refresh(), $posicion, $camarero, $dispositivo);
        app(ServicioPinOperacional::class)->configurar($camarero, '2580', null);
        // Un estado nuevo todavía no tiene ciclo; no se altera durante la carrera.
        DB::table('maniobras_operacionales')->where('id', $tarea->maniobra_operacional_id)->increment('version');

        $principal = DB::getDefaultConnection();
        $conexion = DB::connection();
        $configuracion = $conexion->getConfig();
        unset($configuracion['name']);
        config(['database.connections.arbitraje_competidor' => $configuracion]);
        $this->assertSame('arbitraje_competidor', DB::connection('arbitraje_competidor')->getName());
        $this->assertNotSame($conexion->getPdo(), DB::connection('arbitraje_competidor')->getPdo());
        $conexion->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $conexion->beginTransaction();
        try {
            $previos = CicloArbitrajeManiobras::count(); // Abre el snapshot exterior.
            DB::setDefaultConnection('arbitraje_competidor');
            try {
                $ganador = app(ServicioArbitrajeManiobras::class)->arbitrar($temporada);
                $this->assertSame($previos + 1, CicloArbitrajeManiobras::count());
                $this->assertCount(1, $ganador->decisiones);
            } finally {
                DB::setDefaultConnection($principal);
            }
            $this->assertNotSame($conexion->getPdo(), DB::connection('arbitraje_competidor')->getPdo());
            $this->assertSame($previos, CicloArbitrajeManiobras::count());
            $this->assertNull(CicloArbitrajeManiobras::where('snapshot_version', $ganador->snapshot_version)->first());

            $reutilizado = app(ServicioArbitrajeManiobras::class)->arbitrar($temporada);
            $this->assertSame($ganador->id, $reutilizado->id);
            $this->assertSame($ganador->decisiones->pluck('id')->all(), $reutilizado->decisiones->pluck('id')->all());
            $this->assertSame($tarea->maniobra_operacional_id, $reutilizado->decisiones->sole()->maniobra_operacional_id);
            // Una lectura actual no reemplaza el snapshot exterior: el inicio
            // HTTP vuelve a recorrer el mismo caso con folio/PIN reales.
            $this->assertNull(CicloArbitrajeManiobras::where('snapshot_version', $ganador->snapshot_version)->first());
            $this->withToken($token)->postJson('/api/tareas-movimiento/'.$tarea->id.'/iniciar', [
                'confirmacion_folio' => '0056', 'pin' => '2580',
            ])->assertOk()->assertJsonPath('data.estado', 'en_proceso');
            $this->assertSame('en_proceso', $tarea->refresh()->estado->value);
            $this->assertSame($camarero->id, $tarea->responsable_user_id);
            $this->assertSame($dispositivo->id, $tarea->dispositivo_id);
            $this->assertSame(ConfirmacionInicioTarea::CONFIRMADA, ConfirmacionInicioTarea::sole()->resultado);
            $this->assertDatabaseCount('movimientos', 0);
            $this->assertSame(1, $conexion->transactionLevel(), 'La recuperación conserva la transacción del inicio.');
            $conexion->commit();
            $this->assertSame(1, CicloArbitrajeManiobras::where('snapshot_version', $ganador->snapshot_version)->count());
            $this->assertSame(1, $ganador->decisiones()->count());
        } finally {
            DB::setDefaultConnection($principal);
            while ($conexion->transactionLevel() > 0) {
                $conexion->rollBack();
            }
            DB::purge('arbitraje_competidor');
        }
    }
}
