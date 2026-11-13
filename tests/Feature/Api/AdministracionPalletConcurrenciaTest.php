<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\TestCase;

class AdministracionPalletConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;
    use EjecutaSolicitudesConcurrentes;

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

    public function test_dos_administradores_no_designan_ni_corrigen_dos_pallets_hacia_la_misma_posicion(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $token = $admin->createToken('oficina-admin', ['oficina'])->plainTextToken;
        $this->actingAs($admin, 'sanctum');
        $camara = Camara::create(['codigo' => 'CAM-ADMIN-RACE', 'nombre' => 'Cámara de carrera', 'contenido' => 'productos', 'estado' => 'activa',
            'cantidad_bandas' => 2, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        foreach ([1, 2] as $band) {
            BandaOperacional::create(['camara_id' => $camara->id, 'numero' => $band, 'modo' => 'operativa', 'usos_permitidos' => ['transito_pt']]);
            $posiciones[$band] = Posicion::create(['camara_id' => $camara->id, 'banda' => $band, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B0'.$band.'-P01-N1']);
        }
        foreach ([1 => 'designar_destino', 2 => 'corregir_ubicacion'] as $band => $action) {
            $requests = [];
            foreach ([1, 2] as $n) {
                $folio = Folio::create(['numero_folio' => "ADMIN-RACE-{$band}-{$n}", 'tipo_bulto' => 'pallet', 'estado_operacional' => 'pendiente_ubicacion', 'fecha_ingreso' => now(), 'activo' => true]);
                $data = $this->getJson('/api/intervenciones-planificador/pallets?folio='.$folio->numero_folio)->assertOk()->json('data');
                $requests[] = ['POST', '/api/intervenciones-planificador/pallets/'.$folio->id, $token, [
                    'accion' => $action, 'operacion_id' => (string) Str::uuid(), 'revision' => $data['revision'], 'motivo' => 'Asignación simultánea desde dos oficinas.',
                    'camara_id' => $camara->id, 'banda' => $band, 'posicion' => 1, 'nivel' => 1,
                    'version_plano_destino' => $camara->refresh()->version_plano, 'confirmacion_fisica' => true,
                ]];
            }
            $responses = $this->solicitudesConcurrentes($requests);
            $codes = array_column($responses, 'status');
            sort($codes);
            $this->assertSame([200, 409], $codes, json_encode($responses));
        }
        $this->assertSame(1, TareaMovimiento::where('posicion_destino_id', $posiciones[1]->id)->where('estado', 'pendiente')->count());
        $this->assertSame(1, UbicacionActual::where('posicion_id', $posiciones[2]->id)->count());
        $this->assertSame(1, Movimiento::where('tipo_movimiento', 'correccion_administrativa')->count());
    }
}
