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
            ->assertOk()->assertJsonPath('usuario.temas_oficina', ['dark-princess', 'light-garden', 'dark-bit', 'dark-bit-neon', 'personal-majora-link']);
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
        $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonPath('data.temas_oficina', ['dark-princess', 'light-garden', 'dark-bit', 'dark-bit-neon', 'personal-majora-link']);
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

    public function test_ultima_noche_incluye_su_ilustracion_y_respeta_la_asignacion_por_perfil(): void
    {
        Storage::fake('local');
        $ruta = '/api/oficina/tema-personal/personal-majora-link';
        $this->getJson($ruta)->assertUnauthorized();

        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        Sanctum::actingAs($supervisor);
        $this->get($ruta)->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]));
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina_personales.personal-majora-link.nombre', 'Majora y Link · Última noche')
            ->assertJsonPath('data.temas_oficina_personales.personal-majora-link.color', '#15101d')
            ->assertJsonPath('data.temas_oficina_personales.personal-majora-link.claro', false);
        $css = $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString('[data-office-personal-theme="personal-majora-link"]', $css);
        $this->assertStringContainsString('data:image/webp;base64,UklGR', $css);
        $this->assertStringNotContainsString('__FOLIOS_TEMA_WEBP__', $css);
        $this->patchJson("/api/administracion/perfiles-acceso/{$perfil->id}/temas", ['temas_oficina' => ['personal-majora-link']])->assertOk();

        Sanctum::actingAs($supervisor->fresh());
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina', ['personal-majora-link']);
        $this->assertSame($css, $this->get($ruta)->assertOk()->getContent());
    }

    public function test_el_tema_local_se_entrega_por_correo_o_por_asignacion_de_perfil(): void
    {
        $disco = Storage::fake('local');
        $disco->put('temas-oficina/catalogo.json', json_encode([
            'personal-prueba' => ['correo' => 'personal@folios.test', 'nombre' => 'Tema de prueba', 'color' => '#123456'],
            'personal-por-perfil' => ['nombre' => 'Tema por perfil', 'color' => '#abcdef'],
            'personal-sin-archivo' => ['correo' => 'personal@folios.test', 'nombre' => 'Sin archivo', 'color' => '#123456'],
        ]));
        $disco->put('temas-oficina/personal-prueba.css', ':root { --background: #123456; }');
        $disco->put('temas-oficina/personal-por-perfil.css', ':root { --background: #abcdef; }');
        $ruta = '/api/oficina/tema-personal/personal-prueba';
        $rutaPorPerfil = '/api/oficina/tema-personal/personal-por-perfil';
        $this->getJson($ruta)->assertUnauthorized();

        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $autorizado = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id, 'email' => 'personal@folios.test']);
        $porPerfil = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        $sinPerfil = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => null]);
        Sanctum::actingAs($autorizado);
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina', ['personal-prueba'])
            ->assertJsonPath('data.temas_oficina_personales.personal-prueba.nombre', 'Tema de prueba')
            ->assertJsonMissingPath('data.temas_oficina_personales.personal-prueba.correo');
        $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get($rutaPorPerfil)->assertNotFound();

        Sanctum::actingAs($porPerfil);
        $this->getJson('/api/oficina/contexto')->assertOk()->assertJsonPath('data.temas_oficina', []);
        $this->getJson($ruta)->assertNotFound();
        $this->getJson($rutaPorPerfil)->assertNotFound();

        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $rutaAsignacion = "/api/administracion/perfiles-acceso/{$perfil->id}/temas";
        Sanctum::actingAs($administrador);
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonPath('catalogo_temas.personal-por-perfil', 'Tema por perfil')
            ->assertJsonMissingPath('catalogo_temas.personal-sin-archivo');
        $this->patchJson($rutaAsignacion, ['temas_oficina' => ['personal-prueba', 'personal-por-perfil']])->assertOk();

        Sanctum::actingAs($porPerfil->fresh());
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina', ['personal-prueba', 'personal-por-perfil'])
            ->assertJsonPath('data.temas_oficina_personales.personal-por-perfil.nombre', 'Tema por perfil')
            ->assertJsonMissingPath('data.temas_oficina_personales.personal-prueba.correo');
        $this->get($ruta)->assertOk();
        $this->get($rutaPorPerfil)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');

        Sanctum::actingAs($sinPerfil);
        $this->getJson($ruta)->assertNotFound();
        $this->getJson($rutaPorPerfil)->assertNotFound();

        Sanctum::actingAs($administrador);
        $this->patchJson($rutaAsignacion, ['temas_oficina' => []])->assertOk();
        Sanctum::actingAs($porPerfil->fresh());
        $this->getJson($rutaPorPerfil)->assertNotFound();
        Sanctum::actingAs($autorizado->fresh());
        $this->getJson($ruta)->assertNotFound();

        Sanctum::actingAs($administrador);
        $this->patchJson($rutaAsignacion, ['temas_oficina' => null])->assertOk();
        Sanctum::actingAs($autorizado->fresh());
        $this->get($ruta)->assertOk();
        $this->getJson($rutaPorPerfil)->assertNotFound();
    }

    public function test_cronicas_del_cristal_entrega_arte_y_fuente_solo_al_perfil_habilitado(): void
    {
        Storage::fake('local');
        $tema = 'personal-cronicas-cristal';
        $ruta = '/api/oficina/tema-personal/'.$tema;
        $this->getJson($ruta)->assertUnauthorized();

        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        Sanctum::actingAs($supervisor);
        $this->get($ruta)->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]));
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonPath('catalogo_temas.'.$tema, 'Crónicas del Cristal');
        $this->patchJson("/api/administracion/perfiles-acceso/{$perfil->id}/temas", ['temas_oficina' => [$tema]])->assertOk();

        Sanctum::actingAs($supervisor->fresh());
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina', [$tema])
            ->assertJsonPath('data.temas_oficina_personales.'.$tema.'.color', '#0b1025')
            ->assertJsonPath('data.temas_oficina_personales.'.$tema.'.claro', false);
        $css = $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString('data:image/webp;base64,UklGR', $css);
        $this->assertStringContainsString('data:font/woff2;base64,d09GMg', $css);
        $this->assertStringNotContainsString('__FOLIOS_TEMA_', $css);
    }

    public function test_los_estilos_de_planta_requieren_ilustracion_y_se_asignan_por_perfil(): void
    {
        $disco = Storage::fake('local');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        $claves = ['personal-gundam', 'personal-omegamon-x', 'personal-mugiwara'];
        $webp = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAgA0JaQAA3AA/vuUAAA=';

        Sanctum::actingAs($administrador);
        $disco->put('temas-oficina/personal-gundam.webp', 'imagen incompleta');
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonMissingPath('catalogo_temas.personal-gundam')
            ->assertJsonMissingPath('catalogo_temas.personal-omegamon-x')
            ->assertJsonMissingPath('catalogo_temas.personal-mugiwara');

        foreach ($claves as $clave) {
            $disco->put("temas-oficina/{$clave}.webp", base64_decode($webp, true));
        }
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonPath('catalogo_temas.personal-gundam', 'Gundam RX-78-2')
            ->assertJsonPath('catalogo_temas.personal-omegamon-x', 'Omegamon X')
            ->assertJsonPath('catalogo_temas.personal-mugiwara', 'Mugiwara');

        Sanctum::actingAs($usuario);
        foreach ($claves as $clave) {
            $this->get("/api/oficina/tema-personal/{$clave}")->assertNotFound();
        }

        $disco->put('temas-oficina/catalogo.json', json_encode([
            'personal-omegamon-x' => ['nombre' => 'Tema previo', 'color' => '#123456', 'correo' => 'otro@folios.test'],
        ]));
        $disco->put('temas-oficina/personal-omegamon-x.css', ':root { --estilo-anterior: 1; }');

        Sanctum::actingAs($administrador);
        $this->patchJson("/api/administracion/perfiles-acceso/{$perfil->id}/temas", ['temas_oficina' => $claves])->assertOk();

        Sanctum::actingAs($usuario->fresh());
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina_personales.personal-gundam.claro', true)
            ->assertJsonPath('data.temas_oficina_personales.personal-omegamon-x.nombre', 'Omegamon X')
            ->assertJsonPath('data.temas_oficina_personales.personal-mugiwara.color', '#071c28')
            ->assertJsonMissingPath('data.temas_oficina_personales.personal-omegamon-x.correo')
            ->assertJsonMissingPath('data.temas_oficina_personales.personal-omegamon-x.ilustracion');

        foreach ($claves as $clave) {
            $respuesta = $this->get("/api/oficina/tema-personal/{$clave}")->assertOk()
                ->assertHeader('Content-Type', 'text/css; charset=UTF-8');
            $this->assertStringContainsString('data:image/webp;base64,'.$webp, $respuesta->getContent());
            $this->assertStringNotContainsString('__FOLIOS_TEMA_WEBP__', $respuesta->getContent());
            $this->assertStringNotContainsString('--estilo-anterior', $respuesta->getContent());
        }
    }

    public function test_nerv_sirve_el_png_original_y_el_fondo_opcional_al_perfil_habilitado(): void
    {
        $disco = Storage::fake('local');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAYAAAAGCAIAAABvrngfAAAAFUlEQVQI12P8z3qNARUwMWAAagoBANKnAeZyRjDUAAAAAElFTkSuQmCC';
        $webp = 'UklGRhwAAABXRUJQVlA4TBAAAAAvB4AAAAcQxS9y/wMR0f8A';
        $ruta = '/api/oficina/tema-personal/personal-nerv';

        Sanctum::actingAs($administrador);
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonMissingPath('catalogo_temas.personal-nerv');

        $disco->put('temas-oficina/personal-nerv.png', base64_decode($png, true));
        $disco->put('temas-oficina/personal-nerv-eva.webp', base64_decode($webp, true));
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonPath('catalogo_temas.personal-nerv', 'NERV');

        Sanctum::actingAs($usuario);
        $this->get($ruta)->assertNotFound();

        Sanctum::actingAs($administrador);
        $this->patchJson("/api/administracion/perfiles-acceso/{$perfil->id}/temas", ['temas_oficina' => ['personal-nerv']])->assertOk();
        Sanctum::actingAs($usuario->fresh());
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.temas_oficina_personales.personal-nerv.color', '#0c090a');
        $css = $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString('--nerv-emblema: url("data:image/png;base64,'.$png.'");', $css);
        $this->assertStringContainsString('--nerv-fondo: url("data:image/webp;base64,'.$webp.'");', $css);
        $this->assertStringNotContainsString('__FOLIOS_TEMA_', $css);

        $disco->delete('temas-oficina/personal-nerv-eva.webp');
        $css = $this->get($ruta)->assertOk()->getContent();
        $this->assertStringContainsString('data:image/png;base64,'.$png, $css);
        $this->assertStringContainsString('--nerv-fondo: none;', $css);
    }

    public function test_el_tema_instalado_con_css_antiguo_reutiliza_su_ilustracion_en_la_cabecera_actual(): void
    {
        $disco = Storage::fake('local');
        $disco->put('temas-oficina/catalogo.json', json_encode([
            'personal-omegamon-x' => ['nombre' => 'Omegamon X', 'color' => '#123456', 'correo' => 'personal@folios.test'],
        ]));
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAwAAAAICAIAAABChommAAAAFUlEQVQY02NMb/nAQAgwEVQx7BUBACt/AeuO+uroAAAAAElFTkSuQmCC';
        $webp = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAgA0JaQAA3AA/vuUAAA=';
        $cssAnterior = <<<CSS
            :root { --estilo-anterior: 1; --icono: url("data:image/webp;base64,{$webp}"); }
            .estiba-office-signature::after { background-image: url("data:image/png;base64,{$png}"); }
            CSS;
        $disco->put('temas-oficina/personal-omegamon-x.css', $cssAnterior);
        $ruta = '/api/oficina/tema-personal/personal-omegamon-x';

        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => null]));
        $this->get($ruta)->assertNotFound();

        Sanctum::actingAs(User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => null, 'email' => 'personal@folios.test',
        ]));
        $css = $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8')->getContent();
        $this->assertStringContainsString('--omega-hero: url("data:image/png;base64,'.$png.'");', $css);
        $this->assertStringContainsString('.operation-now-command__title', $css);
        $this->assertStringNotContainsString('data:image/webp;base64,'.$webp, $css);
        $this->assertStringNotContainsString('--estilo-anterior', $css);

        $disco->put('temas-oficina/personal-omegamon-x.webp', base64_decode($webp, true));
        $css = $this->get($ruta)->assertOk()->getContent();
        $this->assertStringContainsString('data:image/webp;base64,'.$webp, $css);
        $this->assertStringNotContainsString($png, $css);
    }
}
