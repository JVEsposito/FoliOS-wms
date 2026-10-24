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
}
