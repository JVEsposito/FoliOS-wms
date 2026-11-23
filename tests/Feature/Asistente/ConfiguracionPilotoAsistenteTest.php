<?php

namespace Tests\Feature\Asistente;

use App\Enums\RolUsuario;
use App\Models\ConfiguracionAsistente;
use App\Models\LlamadaIaAsistente;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Pantalla de configuración: topes editables, piloto de usuarios y consumo del mes. */
class ConfiguracionPilotoAsistenteTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        $this->prepararAsistente();
        config(['asistente.anthropic.api_key' => 'clave-de-prueba', 'asistente.topes.mensual_usd' => 20, 'asistente.topes.diario_usuario' => 50]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_la_pantalla_existe_y_esta_en_el_menu_de_administracion(): void
    {
        $this->get('/oficina/administracion/asistente')
            ->assertOk()
            ->assertSee('Configuración del asistente')
            ->assertSee('assistantSettingsApp', false);
    }

    public function test_guarda_topes_propios_y_piloto(): void
    {
        $piloto = $this->usuarioAsistente(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', [
            'margen_desviaciones' => 2, 'rangos' => [], 'modo' => 'mixto',
            'tope_mensual_usd' => 5, 'limite_diario_usuario' => 30, 'usuarios_piloto' => [$piloto->id],
        ])->assertOk()
            ->assertJsonPath('data.modo', 'mixto')
            ->assertJsonPath('data.ia.tope_mensual_usd', 5)
            ->assertJsonPath('data.ia.tope_mensual_propio', true)
            ->assertJsonPath('data.ia.limite_diario_usuario', 30)
            ->assertJsonPath('data.ia.usuarios_piloto', [$piloto->id]);

        // Vacío vuelve al valor del .env.
        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', [
            'margen_desviaciones' => 2, 'rangos' => [], 'tope_mensual_usd' => null,
        ])->assertOk()->assertJsonPath('data.ia.tope_mensual_usd', 20)->assertJsonPath('data.ia.tope_mensual_propio', false);
    }

    public function test_valida_topes_y_usuarios_del_piloto(): void
    {
        $base = ['margen_desviaciones' => 2, 'rangos' => []];
        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', [...$base, 'tope_mensual_usd' => 500])->assertJsonValidationErrors('tope_mensual_usd');
        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', [...$base, 'limite_diario_usuario' => 0])->assertJsonValidationErrors('limite_diario_usuario');
        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', [...$base, 'usuarios_piloto' => [999999]])->assertJsonValidationErrors('usuarios_piloto.0');
    }

    public function test_fuera_del_piloto_responde_sin_ia_y_sin_aviso(): void
    {
        $piloto = $this->usuarioAsistente(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);
        ConfiguracionAsistente::actual()->update(['modo' => 'mixto', 'usuarios_piloto' => [$piloto->id]]);
        Http::fake();

        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => 'cuanta fruta nos trajo exportadora andina el jueves'])
            ->assertOk()->assertJsonPath('data.modo', 'sin_ia')->assertJsonMissingPath('data.aviso');

        Http::assertNothingSent();
    }

    public function test_el_tope_propio_manda_sobre_el_del_servidor(): void
    {
        ConfiguracionAsistente::actual()->update(['modo' => 'mixto', 'tope_mensual_usd' => 1]);
        LlamadaIaAsistente::query()->create(['user_id' => $this->administrador->id, 'modelo' => 'claude-haiku-5-5', 'estado' => 'ok', 'costo_usd' => 0.9995, 'created_at' => now()->subHour()]);
        Http::fake();

        $aviso = $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => 'cuanta fruta nos trajo exportadora andina el jueves'])->json('data.aviso');

        $this->assertStringContainsString('tope de gasto del mes', $aviso);
        Http::assertNothingSent();
    }

    public function test_consumo_del_mes_por_dia_y_por_usuario(): void
    {
        foreach ([[0.001, now()->subHour()], [0.002, now()->subDays(2)], [0.5, now()->subMonths(2)]] as [$costo, $cuando]) {
            LlamadaIaAsistente::query()->create(['user_id' => $this->administrador->id, 'modelo' => 'claude-haiku-5-5', 'estado' => 'ok', 'costo_usd' => $costo, 'created_at' => $cuando]);
        }

        $this->actingAs($this->administrador, 'sanctum')->getJson('/api/asistente/consumo')
            ->assertOk()
            ->assertJsonPath('data.usd', 0.003)
            ->assertJsonPath('data.llamadas', 2)
            ->assertJsonCount(2, 'data.por_dia')
            ->assertJsonPath('data.por_usuario.0.llamadas', 2);
    }

    public function test_candidatos_al_piloto_son_quienes_pueden_usar_el_asistente(): void
    {
        $conModulo = $this->usuarioAsistente(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);
        $sinModulo = $this->usuarioAsistente(RolUsuario::SupervisorFrio, []);

        $ids = array_column($this->actingAs($this->administrador, 'sanctum')->getJson('/api/asistente/usuarios')->assertOk()->json('data'), 'id');

        $this->assertContains($conModulo->id, $ids);
        $this->assertNotContains($sinModulo->id, $ids);
        $this->actingAs($conModulo, 'sanctum')->getJson('/api/asistente/usuarios')->assertForbidden();
        $this->actingAs($conModulo, 'sanctum')->getJson('/api/asistente/consumo')->assertForbidden();
    }
}
