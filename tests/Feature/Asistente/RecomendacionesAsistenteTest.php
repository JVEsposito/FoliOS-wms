<?php

namespace Tests\Feature\Asistente;

use App\Enums\EstadoProcesoPrefrio;
use App\Enums\RolUsuario;
use App\Models\ConfiguracionAsistente;
use App\Models\LlamadaIaAsistente;
use App\Models\ProcesoPrefrio;
use App\Models\RecomendacionAsistente;
use App\Models\TunelPrefrio;
use App\Services\Asistente\Consultas\AnulacionesValidacion;
use App\Services\Asistente\Haiku\RecomendadorHaiku;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/**
 * Recomendaciones a pedido: solo si el usuario las pide, siempre con el aviso de FoliOS,
 * con los datos que ya vio y con cada cifra verificada. Respuestas de Haiku grabadas.
 */
class RecomendacionesAsistenteTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
        config(['asistente.anthropic.api_key' => 'clave-de-prueba', 'asistente.topes.mensual_usd' => 20, 'asistente.topes.diario_usuario' => 50]);
        ConfiguracionAsistente::actual()->update(['modo' => ConfiguracionAsistente::MODO_MIXTO]);
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-1', 'nombre' => 'Túnel 1', 'capacidad_posiciones' => 20, 'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo', 'estado_tecnico' => 'operativo', 'version_configuracion' => 1,
            'creado_por_user_id' => $this->administrador->id,
        ]);
        ProcesoPrefrio::create([
            'temporada_id' => $this->temporada->id, 'codigo' => 'PF-1', 'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'PF-1'), 'tunel_prefrio_id' => $tunel->id, 'estado' => EstadoProcesoPrefrio::EnProceso,
            'setpoint' => -1.5, 'duracion_objetivo_minutos' => 240, 'version' => 1,
            'creado_por_user_id' => $this->administrador->id, 'iniciado_at' => now()->subMinutes(300),
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_solo_se_ofrece_en_modo_mixto_sobre_una_respuesta_que_la_admite(): void
    {
        $this->preguntar('que tuneles van atrasados ahora')->assertJsonPath('data.consulta', 'prefrio-en-curso')->assertJsonPath('data.recomendable', true);
        $this->preguntar('kilos recibidos hoy')->assertJsonPath('data.recomendable', false);

        ConfiguracionAsistente::actual()->update(['modo' => ConfiguracionAsistente::MODO_SIN_IA]);
        $this->preguntar('que tuneles van atrasados ahora')->assertJsonPath('data.recomendable', false);
    }

    public function test_pedirla_en_la_pregunta_indica_el_boton(): void
    {
        Http::fake();

        $aviso = $this->preguntar('que me recomiendas con los tuneles atrasados')->assertJsonPath('data.consulta', 'prefrio-en-curso')->json('data.aviso');

        $this->assertStringContainsString('Pedir recomendación', $aviso);
        Http::assertNothingSent();
    }

    public function test_recomendacion_verificada_se_muestra_con_el_aviso_fijo(): void
    {
        $this->haikuRecomienda('Revisa primero PF-1 en el Túnel 1: lleva 300 minutos, 60 sobre su objetivo de 240.');

        $this->recomendar()
            ->assertOk()
            ->assertJsonPath('data.estado', 'mostrada')
            ->assertJsonPath('data.aviso', RecomendadorHaiku::AVISO)
            ->assertJsonPath('data.basada_en', ['Minutos', 'Sobre el objetivo (min)']);

        Http::assertSent(fn (Request $pedido): bool => $pedido->data()['tool_choice'] === ['type' => 'tool', 'name' => 'recomendar']
            && str_contains($pedido->data()['messages'][0]['content'], 'Túnel 1: posiciones'));
        $registro = RecomendacionAsistente::query()->sole();
        $this->assertSame('mostrada', $registro->estado);
        $this->assertSame('prefrio-en-curso', $registro->consulta);
        $this->assertNotNull($registro->llamada_ia_id);
        $this->assertSame('recomendar', LlamadaIaAsistente::query()->sole()->herramienta);
    }

    public function test_una_cifra_que_no_esta_en_los_datos_descarta_la_recomendacion(): void
    {
        $this->haikuRecomienda('Baja el setpoint y en 15 minutos PF-1 estará listo.');

        $respuesta = $this->recomendar()->assertJsonPath('data.estado', 'rechazada')->assertJsonPath('data.texto', null);

        $this->assertStringContainsString('15', $respuesta->json('data.motivo'));
        $this->assertSame('rechazada', RecomendacionAsistente::query()->sole()->estado);
        $this->assertSame('Baja el setpoint y en 15 minutos PF-1 estará listo.', RecomendacionAsistente::query()->sole()->respuesta);
    }

    public function test_sin_el_reconocimiento_de_haiku_no_se_muestra(): void
    {
        $this->haikuRecomienda('Revisa PF-1.', reconocimiento: 'Hazlo ya.');

        $this->recomendar()->assertJsonPath('data.estado', 'rechazada');
    }

    public function test_en_modo_sin_ia_no_hay_recomendaciones(): void
    {
        ConfiguracionAsistente::actual()->update(['modo' => ConfiguracionAsistente::MODO_SIN_IA]);
        Http::fake();

        $this->recomendar()->assertUnprocessable()->assertJsonPath('codigo', 'regla_de_negocio');
        Http::assertNothingSent();
    }

    public function test_sin_permiso_de_la_consulta_da_403(): void
    {
        $sinPermiso = $this->usuarioAsistente(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->actingAs($sinPermiso, 'sanctum')->postJson('/api/asistente/recomendaciones/prefrio-en-curso')->assertForbidden();
    }

    public function test_una_consulta_sin_datos_no_llama_a_haiku(): void
    {
        Http::fake();

        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/recomendaciones/tiempos-cargas')
            ->assertOk()->assertJsonPath('data.estado', 'no_disponible');
        Http::assertNothingSent();
    }

    public function test_los_datos_enviados_no_llevan_clientes_ni_texto_libre(): void
    {
        $resultado = new ResultadoConsulta(ResultadoConsulta::RESPONDIDA, 'Folios anulados', '2 anulaciones', [ResultadoConsulta::cifra('Anulaciones', 2)],
            [['clave' => 'folio', 'titulo' => 'Folio', 'tipo' => 'folio'], ['clave' => 'cliente', 'titulo' => 'Cliente'], ['clave' => 'motivo', 'titulo' => 'Motivo'], ['clave' => 'por', 'titulo' => 'Anulado por']],
            [['folio' => '6000000001', 'cliente' => 'Exportadora Andina', 'motivo' => 'Llamar a Juan', 'por' => 'Juan Pérez'], ['folio' => '6000000002', 'cliente' => 'Exportadora Andina', 'motivo' => 'x', 'por' => 'Ana']]);

        $datos = app(RecomendadorHaiku::class)->datos(app(AnulacionesValidacion::class), $resultado);

        $this->assertSame([['Folio' => '6000000001', 'Cliente' => 'CLIENTE_1'], ['Folio' => '6000000002', 'Cliente' => 'CLIENTE_1']], $datos['filas']);
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Andina', $json);
        $this->assertStringNotContainsString('Juan', $json);
    }

    public function test_la_verificacion_lee_cifras_escritas_en_espanol(): void
    {
        $recomendador = app(RecomendadorHaiku::class);
        $datos = ['cifras' => [['valor' => 9.5], ['valor' => 1234]], 'capacidades' => [['nombre' => 'Túnel 3: posiciones', 'valor' => 20]]];

        $this->assertSame([], $recomendador->numerosAjenos('Neto de 9,5 kg, 1.234 cajas y el Túnel 3 con 20 posiciones.', $datos));
        $this->assertSame(['7', '30'], $recomendador->numerosAjenos('Faltan 7 cajas y un 30 % más.', $datos));
    }

    private function preguntar(string $texto)
    {
        return $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => $texto])->assertOk();
    }

    private function recomendar()
    {
        return $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/recomendaciones/prefrio-en-curso');
    }

    private function haikuRecomienda(string $texto, string $reconocimiento = RecomendadorHaiku::RECONOCIMIENTO): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_prueba', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_prueba', 'name' => 'recomendar', 'input' => [
                'recomendacion' => $texto, 'basada_en' => ['Minutos', 'Sobre el objetivo (min)'], 'reconocimiento' => $reconocimiento,
            ]]],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 80],
        ])]);
    }
}
