<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
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

class AdministracionCamaraApiTest extends TestCase
{
    use CreaFolioParaUbicar;
    use RefreshDatabase;

    public function test_administrador_edita_nombre_y_amplia_el_plano(): void
    {
        $administrador = $this->administrador();
        $camara = $this->crearCamara(1, 1, 1);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}", [
                'nombre' => 'Cámara norte ampliada',
                'tipo' => 'preparacion',
                'bandas' => 2,
                'posiciones_por_banda' => 3,
                'niveles' => 2,
                'sentido_numeracion_bandas' => 'derecha_a_izquierda',
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Cámara norte ampliada')
            ->assertJsonPath('data.dimensiones.bandas', 2)
            ->assertJsonPath('data.dimensiones.posiciones_por_banda', 3)
            ->assertJsonPath('data.dimensiones.niveles', 2)
            ->assertJsonPath('data.sentido_numeracion_bandas', 'derecha_a_izquierda')
            ->assertJsonPath('data.capacidad.total', 12);

        $camara->refresh();
        $this->assertSame('preparacion', $camara->tipo);
        $this->assertSame('derecha_a_izquierda', $camara->sentido_numeracion_bandas->value);
        $this->assertSame(1, $camara->version_plano);
        $this->assertSame($administrador->id, $camara->actualizado_por_user_id);
        $this->assertDatabaseHas('posiciones', [
            'camara_id' => $camara->id,
            'banda' => 2,
            'posicion' => 3,
            'nivel' => 2,
            'estado' => EstadoPosicion::Activa->value,
        ]);
    }

    public function test_reducir_el_plano_archiva_posiciones_sin_borrar_historial(): void
    {
        $administrador = $this->administrador();
        $camara = $this->crearCamara(1, 3, 1);
        $posicionRetirada = $camara->posiciones()->where('posicion', 3)->firstOrFail();

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}", [
                'nombre' => $camara->nombre,
                'tipo' => $camara->tipo,
                'bandas' => 1,
                'posiciones_por_banda' => 2,
                'niveles' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.capacidad.total', 2)
            ->assertJsonPath('data.capacidad.activas', 2);

        $this->assertSame(3, $camara->posiciones()->count());
        $this->assertSame(
            EstadoPosicion::FueraDeServicio,
            $posicionRetirada->refresh()->estado,
        );
    }

    public function test_no_permite_retirar_una_posicion_ocupada(): void
    {
        $administrador = $this->administrador();
        $camara = $this->crearCamara(1, 2, 1);
        $posicion = $camara->posiciones()->where('posicion', 2)->firstOrFail();
        $this->ubicarFolio($camara, $posicion, 'FOLIO-OCUPADO-01');

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}", [
                'nombre' => $camara->nombre,
                'tipo' => $camara->tipo,
                'bandas' => 1,
                'posiciones_por_banda' => 1,
                'niveles' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertSame(2, $camara->refresh()->posiciones_por_banda);
        $this->assertSame(EstadoPosicion::Activa, $posicion->refresh()->estado);

        $this->actingAs($administrador, 'sanctum')
            ->deleteJson("/api/configuracion/camaras/{$camara->id}")
            ->assertUnprocessable();
        $this->assertSame(EstadoCamara::Activa, $camara->refresh()->estado);
    }

    public function test_desactivar_oculta_la_camara_de_la_operacion_y_permite_reactivarla(): void
    {
        $administrador = $this->administrador();
        $camara = $this->crearCamara(1, 1, 1);

        $this->actingAs($administrador, 'sanctum')
            ->deleteJson("/api/configuracion/camaras/{$camara->id}")
            ->assertOk()
            ->assertJsonPath('data.estado', 'inactiva');

        $this->assertSame(EstadoCamara::Inactiva, $camara->refresh()->estado);
        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/camaras')
            ->assertJsonMissing(['id' => $camara->id]);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}", [
                'nombre' => $camara->nombre,
                'tipo' => $camara->tipo,
                'bandas' => 1,
                'posiciones_por_banda' => 1,
                'niveles' => 1,
                'estado' => 'activa',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'activa');
    }

    public function test_supervisor_no_puede_editar_ni_desactivar_camaras(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camara = $this->crearCamara(1, 1, 1);

        $this->actingAs($supervisor, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$camara->id}", [
                'nombre' => 'Cambio no autorizado',
                'tipo' => 'transito',
                'bandas' => 1,
                'posiciones_por_banda' => 1,
                'niveles' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($supervisor, 'sanctum')
            ->deleteJson("/api/configuracion/camaras/{$camara->id}")
            ->assertForbidden();
    }

    private function administrador(): User
    {
        return User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
    }

    private function crearCamara(int $bandas, int $posiciones, int $niveles): Camara
    {
        $camara = Camara::create([
            'codigo' => 'CAM-01',
            'nombre' => 'Cámara de prueba',
            'tipo' => 'transito',
            'cantidad_bandas' => $bandas,
            'posiciones_por_banda' => $posiciones,
            'cantidad_niveles' => $niveles,
        ]);

        for ($banda = 1; $banda <= $bandas; $banda++) {
            for ($posicion = 1; $posicion <= $posiciones; $posicion++) {
                for ($nivel = 1; $nivel <= $niveles; $nivel++) {
                    Posicion::create([
                        'camara_id' => $camara->id,
                        'banda' => $banda,
                        'posicion' => $posicion,
                        'nivel' => $nivel,
                        'etiqueta' => sprintf('B%02d-P%02d-N%d', $banda, $posicion, $nivel),
                    ]);
                }
            }
        }

        return $camara;
    }

    private function ubicarFolio(Camara $camara, Posicion $posicion, string $folio): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-01',
            'nombre' => 'Tablet de prueba',
        ]);
        $token = $operador
            ->crearTokenParaDispositivo($dispositivo, 'tablet-prueba')
            ->plainTextToken;
        $sesionId = $this->withToken($token)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->folioParaUbicar($folio);

        $this->withToken($token)
            ->postJson('/api/movimientos/ubicar', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => $folio,
                'tipo_bulto' => 'pallet',
                'posicion_destino_id' => $posicion->id,
                'sesion_destino_id' => $sesionId,
                'version_destino_conocida' => 0,
                'generado_dispositivo_at' => now()->toAtomString(),
                'advertencias_confirmadas' => [
                    DetectorAdvertenciasMovimiento::POSICIONES_FONDO_LIBRES,
                ],
            ])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/sesiones/{$sesionId}/cerrar")
            ->assertOk();
    }
}
