<?php

namespace Tests\Feature\Office;

use App\Enums\RolUsuario;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContextoOficinaTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_de_folios_aparece_en_la_navegacion_de_oficina(): void
    {
        $this->get('/oficina/envases/cuenta-corriente')->assertOk()
            ->assertSee('Versión '.config('app.product_version'));
    }

    public function test_contexto_requiere_sesion(): void
    {
        $this->getJson('/api/oficina/contexto')->assertUnauthorized();
    }

    public function test_usuario_inactivo_no_recibe_contexto(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => false]));
        $this->getJson('/api/oficina/contexto')->assertForbidden();
    }

    public function test_contexto_no_inventa_planta_ni_crea_temporada(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]));
        config(['oficina.planta' => null]);
        Temporada::query()->update(['activa' => false]);
        $before = Temporada::count();
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.planta', null)->assertJsonPath('data.temporada', null);
        $this->assertSame($before, Temporada::count());
    }

    public function test_contexto_devuelve_solo_la_temporada_activa_y_planta_configurada(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]));
        config(['oficina.planta' => 'Planta de prueba']);
        Temporada::create(['codigo' => 'ANTERIOR', 'nombre' => 'Anterior', 'fecha_inicio' => '2025-01-01', 'activa' => false]);
        Temporada::create(['codigo' => 'ACTUAL', 'nombre' => 'Actual', 'fecha_inicio' => '2026-01-01', 'activa' => true]);
        $this->getJson('/api/oficina/contexto')->assertOk()
            ->assertJsonPath('data.planta', 'Planta de prueba')
            ->assertJsonPath('data.temporada.codigo', 'ACTUAL')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
