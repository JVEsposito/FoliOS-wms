<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemasPerfilApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_administrador_configura_temas_incluso_en_el_perfil_protegido_sin_revocar_sesiones(): void
    {
        $perfil = PerfilAcceso::where('protegido', true)->where('rol_base', RolUsuario::Administrador)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador, 'perfil_acceso_id' => $perfil->id]);
        $sesion = $this->postJson('/api/acceso-oficina', ['email' => $usuario->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('usuario.temas_oficina', ['dark-princess', 'light-garden', 'dark-bit', 'dark-bit-neon']);
        $this->withToken($sesion->json('token'));
        $ruta = "/api/administracion/perfiles-acceso/{$perfil->id}/temas";

        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonPath('puede_editar_temas', true)
            ->assertJsonPath('catalogo_temas.dark-princess', 'Princesa Estelar')
            ->assertJsonPath('catalogo_temas.light-garden', 'Jardín Encantado');
        $this->patchJson($ruta, [])->assertUnprocessable();
        $this->patchJson($ruta, ['temas_oficina' => ['otro']])->assertUnprocessable();
        $this->patchJson($ruta, ['temas_oficina' => ['dark-princess', 'dark-princess']])->assertUnprocessable();
        $this->patchJson($ruta, ['temas_oficina' => [], 'activo' => false])->assertOk()
            ->assertJsonPath('data.temas_oficina', [])->assertJsonPath('data.activo', true);
        app('auth')->forgetGuards();
        $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonPath('data.temas_oficina', []);
        $this->assertSame(1, $usuario->tokens()->count());
        $this->patchJson($ruta, ['temas_oficina' => null])->assertOk()->assertJsonPath('data.temas_oficina', null);
        app('auth')->forgetGuards();
        $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonPath('data.temas_oficina', ['dark-princess', 'light-garden', 'dark-bit', 'dark-bit-neon']);
    }

    public function test_el_perfil_habilita_un_tema_para_todas_sus_cuentas_sin_darles_permiso_de_edicion(): void
    {
        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $ruta = "/api/administracion/perfiles-acceso/{$perfil->id}/temas";
        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]));
        $this->patchJson($ruta, ['temas_oficina' => ['dark-rose']])->assertUnprocessable();
        $this->patchJson($ruta, ['temas_oficina' => ['dark-bit', 'light-garden']])
            ->assertOk()->assertJsonPath('data.temas_oficina', ['light-garden', 'dark-bit']);

        $usuarios = User::factory()->count(2)->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        foreach ($usuarios as $usuario) {
            Sanctum::actingAs($usuario);
            $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonPath('data.temas_oficina', ['light-garden', 'dark-bit']);
            $this->patchJson($ruta, ['temas_oficina' => []])->assertForbidden();
        }
    }

    public function test_el_tema_local_se_entrega_solo_a_la_cuenta_autorizada_y_si_lo_permite_su_perfil(): void
    {
        $disco = Storage::fake('local');
        $disco->put('temas-oficina/catalogo.json', json_encode([
            'personal-prueba' => ['correo' => 'personal@folios.test', 'nombre' => 'Tema de prueba', 'color' => '#123456'],
            'personal-sin-archivo' => ['correo' => 'personal@folios.test', 'nombre' => 'Sin archivo', 'color' => '#123456'],
        ]));
        $disco->put('temas-oficina/personal-prueba.css', ':root { --background: #123456; }');
        $ruta = '/api/oficina/tema-personal/personal-prueba';
        $this->getJson($ruta)->assertUnauthorized();

        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $autorizado = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id, 'email' => 'personal@folios.test']);
        Sanctum::actingAs($autorizado);
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina', ['personal-prueba'])
            ->assertJsonPath('data.temas_oficina_personales.personal-prueba.nombre', 'Tema de prueba')
            ->assertJsonMissingPath('data.temas_oficina_personales.personal-prueba.correo');
        $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');

        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]));
        $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonPath('data.temas_oficina', []);
        $this->getJson($ruta)->assertNotFound();

        $perfil->update(['temas_oficina' => []]);
        Sanctum::actingAs($autorizado->fresh());
        $this->getJson($ruta)->assertNotFound();
    }
}
