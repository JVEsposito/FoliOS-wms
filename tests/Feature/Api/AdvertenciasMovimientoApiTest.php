<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Posicion;
use App\Models\User;
use App\Services\Estiba\DetectorAdvertenciasMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreaFolioParaUbicar;
use Tests\TestCase;

class AdvertenciasMovimientoApiTest extends TestCase
{
    use CreaFolioParaUbicar;
    use RefreshDatabase;

    public function test_pregunta_antes_de_dejar_una_posicion_libre_hacia_el_fondo(): void
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-01',
            'nombre' => 'Tablet de prueba',
            'activo' => true,
        ]);
        $token = $usuario->crearTokenParaDispositivo(
            $dispositivo,
            'tablet-prueba',
        )->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-01',
            'nombre' => 'Cámara 01',
        ]);
        Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $destino = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $sesion = $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data');
        $this->folioParaUbicar('FOLIO-ADVERTENCIA');
        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => 'FOLIO-ADVERTENCIA',
            'tipo_bulto' => 'pallet',
            'posicion_destino_id' => $destino->id,
            'sesion_destino_id' => $sesion['id'],
            'version_destino_conocida' => 0,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];

        $this->withToken($token)
            ->postJson('/api/movimientos/ubicar', $payload)
            ->assertConflict()
            ->assertJsonPath('codigo', 'confirmacion_requerida')
            ->assertJsonPath(
                'advertencias.0.codigo',
                DetectorAdvertenciasMovimiento::POSICIONES_FONDO_LIBRES,
            );

        $this->assertDatabaseCount('ubicaciones_actuales', 0);
        $this->assertDatabaseCount('operaciones_sincronizacion', 0);

        $payload['advertencias_confirmadas'] = [
            DetectorAdvertenciasMovimiento::POSICIONES_FONDO_LIBRES,
        ];

        $this->withToken($token)
            ->postJson('/api/movimientos/ubicar', $payload)
            ->assertOk()
            ->assertJsonPath(
                'data.advertencias_confirmadas.0.codigo',
                DetectorAdvertenciasMovimiento::POSICIONES_FONDO_LIBRES,
            );

        $this->assertDatabaseHas('ubicaciones_actuales', [
            'posicion_id' => $destino->id,
        ]);
    }
}
