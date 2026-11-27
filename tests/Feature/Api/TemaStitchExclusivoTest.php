<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\CatalogoTemasOficina;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TemaStitchExclusivoTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/oficina/tema-personal/personal-stitch';

    public function test_solo_las_dos_cuentas_asignadas_reciben_stitch_y_su_css_privado(): void
    {
        Storage::fake('local');
        $perfil = PerfilAcceso::where('predeterminado', true)->where('rol_base', RolUsuario::SupervisorFrio)->firstOrFail();
        $cuentas = User::factory()->count(2)->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        $ajeno = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'perfil_acceso_id' => $perfil->id]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->getJson(self::RUTA)->assertUnauthorized();
        $this->artisan('oficina:tema-stitch', ['usuarios' => $cuentas->pluck('email')->all()])->assertSuccessful();

        // Incluso un perfil que contiene un valor antiguo/manipulado no puede ampliar la asignación personal.
        $perfil->update(['temas_oficina' => ['personal-stitch']]);
        foreach ($cuentas as $cuenta) {
            Sanctum::actingAs($cuenta->fresh());
            $this->getJson('/api/oficina/contexto')->assertOk()
                ->assertJsonPath('data.temas_oficina', ['personal-stitch'])
                ->assertJsonPath('data.temas_oficina_personales.personal-stitch.nombre', 'Stitch · Experimento 626')
                ->assertJsonMissingPath('data.temas_oficina_personales.personal-stitch.usuario_ids');
            $css = $this->get(self::RUTA)->assertOk()
                ->assertHeader('Content-Type', 'text/css; charset=UTF-8')
                ->assertHeader('Cache-Control', 'no-store, private')->getContent();
            $this->assertStringContainsString('data:image/png;base64,', $css);
            $this->assertStringContainsString('.estiba-office-theme-art', $css);
            $this->assertStringContainsString('[data-folios-masthead]', $css);
            $this->assertStringNotContainsString('__FOLIOS_TEMA_', $css);
        }
        foreach ([$ajeno, $administrador] as $cuenta) {
            Sanctum::actingAs($cuenta->fresh());
            $this->getJson('/api/oficina/contexto')->assertOk()
                ->assertJsonMissingPath('data.temas_oficina_personales.personal-stitch');
            $this->get(self::RUTA)->assertNotFound();
        }
        $this->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonMissingPath('catalogo_temas.personal-stitch');
        $this->patchJson("/api/administracion/perfiles-acceso/{$perfil->id}/temas", ['temas_oficina' => ['personal-stitch']])
            ->assertUnprocessable();
    }

    public function test_la_asignacion_se_conserva_si_cambia_el_correo_y_se_revoca_al_reasignarla(): void
    {
        Storage::fake('local');
        $anterior = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $nuevo = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->artisan('oficina:tema-stitch', ['usuarios' => [$anterior->email]])->assertSuccessful();
        $anterior->update(['email' => 'correo-cambiado@folios.test']);
        Sanctum::actingAs($anterior->fresh());
        $this->get(self::RUTA)->assertOk();

        $this->artisan('oficina:tema-stitch', ['usuarios' => [(string) $nuevo->id]])->assertSuccessful();
        $this->get(self::RUTA)->assertNotFound();
        Sanctum::actingAs($nuevo);
        $this->get(self::RUTA)->assertOk();
        $nuevo->update(['activo' => false]);
        $this->assertSame([], app(CatalogoTemasOficina::class)->preferencias($nuevo->fresh())['temas_oficina']);
    }

    public function test_sin_asignacion_o_con_ids_invalidos_stitch_no_se_comparte_por_perfil(): void
    {
        $disco = Storage::fake('local');
        $perfil = PerfilAcceso::where('protegido', true)->where('rol_base', RolUsuario::Administrador)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador, 'perfil_acceso_id' => $perfil->id]);
        $perfil->update(['temas_oficina' => ['personal-stitch']]);
        Sanctum::actingAs($usuario->fresh());
        foreach ([null, [], 'todos', [null], ['no-es-id'], [0], [-1], [true], ['01'], ['id' => $usuario->id]] as $ids) {
            $disco->put('temas-oficina/catalogo.json', json_encode([
                'personal-stitch' => ['nombre' => 'Stitch', 'color' => '#081a32', 'usuario_ids' => $ids],
            ]));
            $this->get(self::RUTA)->assertNotFound();
        }
        $disco->delete('temas-oficina/catalogo.json');
        $this->get(self::RUTA)->assertNotFound();
    }

    public function test_instalar_y_retirar_preserva_los_otros_temas_y_respalda_el_catalogo(): void
    {
        $disco = Storage::fake('local');
        $existente = ['personal-anterior' => ['nombre' => 'Anterior', 'color' => '#123456']];
        $contenido = json_encode($existente);
        $disco->put('temas-oficina/catalogo.json', $contenido);
        $perfil = PerfilAcceso::where('protegido', true)->where('rol_base', RolUsuario::Administrador)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador, 'perfil_acceso_id' => $perfil->id]);
        $perfilAntes = $usuario->perfilAcceso->toArray();
        $usuario->createToken('sesion-vigente');

        $this->artisan('oficina:tema-stitch', ['usuarios' => [$usuario->email, (string) $usuario->id]])->assertSuccessful();
        $catalogo = json_decode($disco->get('temas-oficina/catalogo.json'), true);
        $this->assertSame($existente['personal-anterior'], $catalogo['personal-anterior']);
        $this->assertSame([(string) $usuario->id], $catalogo['personal-stitch']['usuario_ids']);
        $respaldos = $disco->files('temas-oficina/respaldos');
        $this->assertCount(1, $respaldos);
        $this->assertSame($contenido, $disco->get($respaldos[0]));
        $this->assertSame($perfilAntes, $usuario->perfilAcceso->fresh()->toArray());
        $this->assertSame(1, $usuario->tokens()->count());

        $this->artisan('oficina:tema-stitch', ['--retirar' => true])->assertSuccessful();
        $this->assertSame($existente, json_decode($disco->get('temas-oficina/catalogo.json'), true));
        Sanctum::actingAs($usuario);
        $this->get(self::RUTA)->assertNotFound();
    }

    public function test_una_cuenta_invalida_no_cambia_ninguna_asignacion(): void
    {
        $disco = Storage::fake('local');
        $disco->put('temas-oficina/catalogo.json', '{}');
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $inactivo = User::factory()->create(['activo' => false]);
        $this->artisan('oficina:tema-stitch', ['usuarios' => [$usuario->email, $inactivo->email]])->assertFailed();
        $this->artisan('oficina:tema-stitch', ['usuarios' => ['no-existe@folios.test']])->assertFailed();
        $this->artisan('oficina:tema-stitch')->assertFailed();
        $this->assertSame('{}', $disco->get('temas-oficina/catalogo.json'));
    }

    public function test_un_catalogo_corrupto_no_se_sobrescribe(): void
    {
        $disco = Storage::fake('local');
        $disco->put('temas-oficina/catalogo.json', '{incompleto');
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->artisan('oficina:tema-stitch', ['usuarios' => [$usuario->email]])->assertFailed();
        $this->assertSame('{incompleto', $disco->get('temas-oficina/catalogo.json'));
    }
}
