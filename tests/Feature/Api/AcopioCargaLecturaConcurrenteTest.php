<?php

namespace Tests\Feature\Api;

use App\Exceptions\ConflictoOperacion;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\ReservaCargaFolio;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Cargas\ServicioAcopioCarga;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Cargas\ServicioTareasCarga;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AcopioCargaLecturaConcurrenteTest extends TestCase
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

    public function test_lectura_actual_detecta_asignacion_cambiada_en_otra_conexion_aunque_el_snapshot_sea_anterior(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La prueba reproduce REPEATABLE READ de MySQL.');
        }
        $usuario = User::factory()->create();
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cargas = collect(['A', 'B'])->map(fn (string $codigo): Carga => Carga::create([
            'temporada_id' => $temporada->id, 'codigo' => 'CAR-RR-'.$codigo, 'estado' => 'pendiente',
            'creada_por_user_id' => $usuario->id, 'actualizada_por_user_id' => $usuario->id,
        ]));
        config(['database.connections.acopio_concurrente' => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection('acopio_concurrente');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            foreach ([false, true] as $teniaAsignacion) {
                $folio = Folio::create([
                    'temporada_id' => $temporada->id, 'numero_folio' => 'RR-'.(int) $teniaAsignacion,
                    'tipo_bulto' => 'pallet', 'fecha_ingreso' => now(), 'activo' => true,
                ]);
                $asignaciones = $cargas->map(fn (Carga $carga): CargaFolio => CargaFolio::create([
                    'carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => 'pendiente',
                    'asignado_por_user_id' => $usuario->id, 'asignado_at' => now(),
                ]));
                if ($teniaAsignacion) {
                    ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignaciones[0]->id]);
                }
                DB::beginTransaction();
                try {
                    $anterior = ReservaCargaFolio::query()->find($folio->id)?->carga_folio_id;
                    // La conexión de Oficina confirma el cambio después de abrir el snapshot de la tablet.
                    $otra->table('reservas_carga_folio')->updateOrInsert(['folio_id' => $folio->id], [
                        'carga_folio_id' => $asignaciones[1]->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->assertSame($anterior, ReservaCargaFolio::query()->find($folio->id)?->carga_folio_id);
                    try {
                        app(ServicioAcopioCarga::class)->paraFolio($folio->id, bloquear: true);
                        $this->fail('Se autorizó un destino con la asignación anterior del snapshot.');
                    } catch (ConflictoOperacion $error) {
                        $this->assertStringContainsString('asignación de carga cambió', $error->getMessage());
                    }
                } finally {
                    DB::rollBack();
                }
                $this->assertSame($asignaciones[1]->id, ReservaCargaFolio::query()->findOrFail($folio->id)->carga_folio_id);
            }
            $this->assertDatabaseCount('movimientos', 0);
            $this->assertDatabaseCount('reservas_tareas_movimiento', 0);
        } finally {
            DB::purge('acopio_concurrente');
        }
    }

    public function test_consultas_de_una_carga_no_bloquean_asignaciones_de_otra_carga(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requiere los bloqueos de filas de MySQL.');
        }
        config(['planificador.mode' => 'guided', 'planificador.generacion_automatica' => true]);
        $usuario = User::factory()->create();
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cargas = collect(['PROPIA', 'AJENA'])->map(fn (string $codigo): Carga => Carga::create([
            'temporada_id' => $temporada->id, 'codigo' => 'CAR-AISLADA-'.$codigo, 'estado' => 'pendiente',
            'creada_por_user_id' => $usuario->id, 'actualizada_por_user_id' => $usuario->id,
        ]));
        $asignaciones = $cargas->map(function (Carga $carga) use ($temporada, $usuario): CargaFolio {
            $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => $carga->codigo,
                'tipo_bulto' => 'pallet', 'fecha_ingreso' => now(), 'activo' => true]);
            $asignacion = CargaFolio::create(['carga_id' => $carga->id, 'folio_id' => $folio->id,
                'estado' => 'pendiente', 'asignado_por_user_id' => $usuario->id, 'asignado_at' => now()]);
            ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);

            return $asignacion;
        });
        config(['database.connections.acopio_aislado' => config('database.connections.'.DB::getDefaultConnection())]);
        $otra = DB::connection('acopio_aislado');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $otra->beginTransaction();
        try {
            $otra->table('carga_folios')->where('id', $asignaciones[1]->id)->lockForUpdate()->first();
            foreach ([
                fn () => app(ServicioAcopioCarga::class)->paraFolio($asignaciones[0]->folio_id, bloquear: true),
                fn () => app(ServicioTareasCarga::class)->sincronizar($cargas[0]),
                fn () => app(ServicioPlanConcentracionCarga::class)->sincronizar($cargas[0], $usuario),
            ] as $consultar) {
                DB::transaction(function () use ($consultar, $otra, $asignaciones): void {
                    $consultar();
                    try {
                        $otra->table('carga_folios')->where('id', $asignaciones[0]->id)->lock('for update nowait')->first();
                        $this->fail('La consulta debe conservar la exclusión sobre su propia asignación.');
                    } catch (QueryException $error) {
                        $this->assertSame(3572, (int) $error->errorInfo[1]);
                    }
                });
            }
            $this->assertDatabaseCount('carga_folios', 2);
            $this->assertDatabaseCount('movimientos', 0);
        } finally {
            $otra->rollBack();
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            DB::purge('acopio_aislado');
        }
    }
}
