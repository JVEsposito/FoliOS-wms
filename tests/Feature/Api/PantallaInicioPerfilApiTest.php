<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PantallaInicioPerfilApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_catalogo_de_perfiles_ofrece_las_pantallas_de_inicio_sin_la_demo(): void
    {
        $respuesta = $this->actingAs($this->administrador(), 'sanctum')
            ->getJson('/api/administracion/perfiles-acceso')
            ->assertOk()
            ->assertJsonFragment([
                'ruta' => '/oficina/romana',
                'nombre' => 'Romana',
                'area' => 'Materia Prima',
                'modulos_relacionados' => ['materia-prima.romana'],
            ])
            ->assertJsonFragment([
                'ruta' => '/oficina/operacion-ahora',
                'nombre' => 'Operación ahora',
                'area' => 'Gerencia',
                'modulos_relacionados' => ['gerencia.panel'],
            ]);

        $pantallas = collect($respuesta->json('pantallas_inicio'))->keyBy('ruta');
        $this->assertFalse($pantallas->has('/oficina/demo'));
        // Un resumen de área se habilita con cualquier módulo de su área.
        $this->assertSame(
            ['consultas.busqueda', 'consultas.sag', 'consultas.productores'],
            $pantallas['/oficina/consultas']['modulos_relacionados'],
        );
    }

    public function test_el_perfil_guarda_su_pantalla_de_inicio_y_el_usuario_parte_ahi(): void
    {
        $this->actingAs($this->administrador(), 'sanctum');

        $perfil = $this->postJson('/api/administracion/perfiles-acceso', [
            'codigo' => 'ROMANA_LOTES',
            'nombre' => 'Romana y lotes',
            'rol_base' => RolUsuario::OperadorRomana->value,
            'modulos' => ['materia-prima.romana', 'materia-prima.digitacion'],
            'modulos_tablet' => [],
            'pantalla_inicio' => '/oficina/materia-prima/lotes',
            'activo' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.pantalla_inicio', '/oficina/materia-prima/lotes')
            ->json('data');

        $usuario = User::factory()->create([
            'rol' => RolUsuario::OperadorRomana,
            'perfil_acceso_id' => $perfil['id'],
        ]);

        // Sin el perfil, el operador de romana partiría en Romana.
        $this->postJson('/api/acceso-oficina', [
            'email' => $usuario->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.inicio', '/oficina/materia-prima/lotes');

        $this->putJson("/api/administracion/perfiles-acceso/{$perfil['id']}", [
            'codigo' => 'ROMANA_LOTES',
            'nombre' => 'Romana y lotes',
            'rol_base' => RolUsuario::OperadorRomana->value,
            'modulos' => ['materia-prima.romana', 'materia-prima.digitacion'],
            'modulos_tablet' => [],
            'pantalla_inicio' => '',
            'activo' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.pantalla_inicio', null)
            ->assertJsonPath('sesiones_revocadas', false);

        $this->assertNull(PerfilAcceso::findOrFail($perfil['id'])->pantalla_inicio);
        $this->postJson('/api/acceso-oficina', [
            'email' => $usuario->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.inicio', '/oficina/romana');
    }

    public function test_rechaza_una_pantalla_desconocida_o_fuera_de_los_modulos_del_perfil(): void
    {
        $this->actingAs($this->administrador(), 'sanctum');
        $datos = [
            'codigo' => 'SOLO_ROMANA',
            'nombre' => 'Solo romana',
            'rol_base' => RolUsuario::OperadorRomana->value,
            'modulos' => ['materia-prima.romana'],
            'modulos_tablet' => [],
            'activo' => true,
        ];

        $this->postJson('/api/administracion/perfiles-acceso', [...$datos, 'pantalla_inicio' => '/oficina/demo'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.pantalla_inicio.0', 'La pantalla de inicio seleccionada no es una oficina de FoliOS.');

        $this->postJson('/api/administracion/perfiles-acceso', [...$datos, 'pantalla_inicio' => 'https://ejemplo.cl'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pantalla_inicio');

        $this->postJson('/api/administracion/perfiles-acceso', [...$datos, 'pantalla_inicio' => '/oficina/materia-prima/lotes'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.pantalla_inicio.0',
                'La pantalla de inicio «Digitación de Lotes» no corresponde a los módulos de oficina del perfil.',
            );

        $this->postJson('/api/administracion/perfiles-acceso', [...$datos, 'pantalla_inicio' => '/oficina/consultas'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pantalla_inicio');

        // El resumen de Materia Prima se habilita con cualquier módulo de su área.
        $this->postJson('/api/administracion/perfiles-acceso', [...$datos, 'pantalla_inicio' => '/oficina/materia-prima'])
            ->assertCreated()
            ->assertJsonPath('data.pantalla_inicio', '/oficina/materia-prima');
    }

    private function administrador(): User
    {
        return User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
    }
}
