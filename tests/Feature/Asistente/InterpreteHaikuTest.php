<?php

namespace Tests\Feature\Asistente;

use App\Models\ConfiguracionAsistente;
use App\Models\LlamadaIaAsistente;
use App\Models\PreguntaAsistente;
use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\EvaluadorBancoAsistente;
use App\Services\Asistente\Haiku\HerramientasHaiku;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/**
 * Haiku como intérprete, con respuestas grabadas: nunca se llama a la API real. Se
 * prueba que solo salen fichas, que FoliOS valida antes de ejecutar y que cualquier
 * falla o tope deja el asistente sin IA con su aviso.
 */
class InterpreteHaikuTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    private const DUDOSA = 'cuanta fruta nos trajo exportadora andina el jueves';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        $this->prepararAsistente();
        config(['asistente.anthropic.api_key' => 'clave-de-prueba', 'asistente.topes.mensual_usd' => 20, 'asistente.topes.diario_usuario' => 50]);
        ConfiguracionAsistente::actual()->update(['modo' => ConfiguracionAsistente::MODO_MIXTO]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_las_reglas_seguras_no_llaman_a_haiku(): void
    {
        Http::fake();

        $this->preguntar('kilos recibidos hoy')->assertJsonPath('data.modo', 'sin_ia')->assertJsonPath('data.consulta', 'kilos-recepciones');

        Http::assertNothingSent();
    }

    public function test_en_modo_sin_ia_nunca_llama(): void
    {
        ConfiguracionAsistente::actual()->update(['modo' => ConfiguracionAsistente::MODO_SIN_IA]);
        Http::fake();

        $this->preguntar(self::DUDOSA)->assertJsonPath('data.tipo', 'sugerencias')->assertJsonMissingPath('data.aviso');

        Http::assertNothingSent();
    }

    public function test_haiku_elige_la_consulta_y_foli_o_s_valida_y_ejecuta(): void
    {
        $this->haikuResponde('consultar', $this->consultar(['consulta' => 'kilos-recepciones', 'periodo_tipo' => 'jornada', 'desde' => '2026-11-19', 'cliente' => 'CLIENTE_1']));

        $this->preguntar(self::DUDOSA)
            ->assertJsonPath('data.tipo', 'respuesta')
            ->assertJsonPath('data.modo', 'haiku')
            ->assertJsonPath('data.consulta', 'kilos-recepciones')
            ->assertJsonPath('data.entendido.fichas.0', 'Jornada 19-11-2026')
            ->assertJsonPath('data.entendido.fichas.1', 'Exportadora Andina');

        Http::assertSent(function (Request $pedido): bool {
            $cuerpo = $pedido->data();
            $texto = $cuerpo['messages'][0]['content'];
            $herramientas = json_encode($cuerpo['tools']);

            return $pedido->hasHeader('x-api-key', 'clave-de-prueba')
                && $pedido->hasHeader('anthropic-version', '2023-06-01')
                && $cuerpo['model'] === 'claude-haiku-5-5'
                && $cuerpo['tool_choice'] === ['type' => 'any']
                && str_contains($texto, '[CLIENTE_1]')
                && ! str_contains(mb_strtolower($texto), 'andina')
                && ! str_contains(mb_strtolower($herramientas), 'andina');
        });
        $llamada = LlamadaIaAsistente::query()->sole();
        $this->assertSame('kilos-recepciones', $llamada->consulta);
        $this->assertSame(0.000093, $llamada->costo_usd);
        $this->assertSame('haiku', PreguntaAsistente::query()->latest('created_at')->value('interprete'));
    }

    public function test_una_ficha_inventada_no_se_ejecuta(): void
    {
        $this->haikuResponde('consultar', $this->consultar(['consulta' => 'kilos-recepciones', 'periodo_tipo' => 'jornada', 'desde' => '2026-11-19', 'cliente' => 'CLIENTE_9']));

        $respuesta = $this->preguntar(self::DUDOSA)->assertJsonPath('data.tipo', 'sugerencias')->assertJsonPath('data.modo', 'haiku');

        $this->assertStringContainsString('no pude comprobar sus datos', $respuesta->json('data.mensaje'));
        $this->assertSame('kilos-recepciones', $respuesta->json('data.sugerencias.0.clave'));
    }

    public function test_fechas_imposibles_o_futuras_no_se_ejecutan(): void
    {
        foreach ([['desde' => '2026-02-31'], ['desde' => '2026-12-24'], ['periodo_tipo' => 'turno', 'desde' => '2026-11-21', 'turno' => 'ninguno']] as $cambio) {
            $this->haikuResponde('consultar', $this->consultar(['consulta' => 'kilos-recepciones', 'periodo_tipo' => 'jornada', ...$cambio]));

            $this->preguntar(self::DUDOSA)->assertJsonPath('data.tipo', 'sugerencias');
        }
    }

    public function test_una_consulta_fuera_del_catalogo_no_se_ejecuta(): void
    {
        $this->haikuResponde('consultar', $this->consultar(['consulta' => 'sueldos-del-personal']));

        $this->preguntar(self::DUDOSA)->assertJsonPath('data.tipo', 'sugerencias')->assertJsonPath('data.resultado', null);
    }

    public function test_la_aclaracion_ofrece_las_opciones_de_haiku(): void
    {
        $this->haikuResponde('pedir_aclaracion', ['opciones' => [['consulta' => 'envases-recibidos'], ['consulta' => 'kilos-recepciones'], ['consulta' => 'no-existe']]]);

        $respuesta = $this->preguntar(self::DUDOSA)->assertJsonPath('data.tipo', 'sugerencias')->assertJsonPath('data.mensaje', '¿Cuál de estas consultas quieres?');

        $this->assertSame(['envases-recibidos', 'kilos-recepciones'], array_column($respuesta->json('data.sugerencias'), 'clave'));
    }

    public function test_sin_consulta_explica_el_motivo(): void
    {
        $this->haikuResponde('sin_consulta', ['motivo' => 'comparacion']);

        $this->preguntar(self::DUDOSA)->assertJsonPath('data.mensaje', 'Todavía no comparo periodos. Puedo mostrar cada periodo por separado:');
    }

    public function test_con_el_tope_del_mes_responde_sin_ia_y_avisa(): void
    {
        $this->llamadaPrevia(19.999);
        Http::fake();

        $respuesta = $this->preguntar(self::DUDOSA)->assertJsonPath('data.modo', 'sin_ia');

        $this->assertStringContainsString('tope de gasto del mes', $respuesta->json('data.aviso'));
        Http::assertNothingSent();
    }

    public function test_con_el_limite_diario_responde_sin_ia_y_avisa(): void
    {
        config(['asistente.topes.diario_usuario' => 2]);
        $this->llamadaPrevia(0.0001);
        $this->llamadaPrevia(0.0001);
        Http::fake();

        $this->assertStringContainsString('límite diario', $this->preguntar(self::DUDOSA)->json('data.aviso'));
        Http::assertNothingSent();
    }

    public function test_sin_red_responde_sin_ia_y_registra_la_falla(): void
    {
        Http::fake(fn () => throw new ConnectionException('sin red'));

        $respuesta = $this->preguntar(self::DUDOSA)->assertJsonPath('data.modo', 'sin_ia')->assertJsonPath('data.tipo', 'sugerencias');

        $this->assertStringContainsString('no hay conexión', $respuesta->json('data.aviso'));
        $this->assertSame(LlamadaIaAsistente::ERROR, LlamadaIaAsistente::query()->sole()->estado);
    }

    public function test_un_error_de_la_api_tambien_cae_a_sin_ia(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $this->assertStringContainsString('Haiku no respondió bien', $this->preguntar(self::DUDOSA)->json('data.aviso'));
    }

    public function test_una_pregunta_sensible_no_sale_de_la_planta(): void
    {
        Http::fake();

        $respuesta = $this->preguntar('cuantas horas extra hizo el turno b');

        $this->assertStringContainsString('no salió de la planta', $respuesta->json('data.aviso'));
        Http::assertNothingSent();
    }

    public function test_las_herramientas_cumplen_los_limites_de_la_api(): void
    {
        $permitidas = app(CatalogoConsultasAsistente::class)->disponiblesPara($this->administrador);
        $definicion = app(HerramientasHaiku::class)->definir($permitidas);

        $this->assertLessThanOrEqual(20, count($definicion['tools']));
        foreach ($definicion['tools'] as $herramienta) {
            $this->assertTrue($herramienta['strict']);
            $this->assertFalse($herramienta['input_schema']['additionalProperties']);
            // Sin parámetros opcionales: la API admite 24 por pedido entre todas las herramientas estrictas.
            $this->assertSame(array_keys($herramienta['input_schema']['properties']), $herramienta['input_schema']['required']);
        }
        $this->assertSame(
            array_map(fn ($c) => $c->clave(), $permitidas),
            $definicion['tools'][0]['input_schema']['properties']['consulta']['enum'],
        );
        $this->assertStringNotContainsString('Exportadora Andina', json_encode($definicion, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['type' => 'ephemeral'], $definicion['system'][0]['cache_control']);
    }

    public function test_configuracion_activa_el_modo_mixto_solo_con_clave(): void
    {
        $cuerpo = ['margen_desviaciones' => 2, 'rangos' => [], 'modo' => 'mixto'];
        config(['asistente.anthropic.api_key' => null]);
        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', $cuerpo)->assertUnprocessable()->assertJsonValidationErrors('modo');

        config(['asistente.anthropic.api_key' => 'clave-de-prueba']);
        $this->actingAs($this->administrador, 'sanctum')->putJson('/api/asistente/configuracion', $cuerpo)
            ->assertOk()
            ->assertJsonPath('data.modo', 'mixto')
            ->assertJsonPath('data.ia.disponible', true)
            ->assertJsonPath('data.ia.tope_mensual_usd', 20);
    }

    public function test_evaluar_el_banco_con_haiku_cuenta_llamadas_y_sigue_sin_falsas(): void
    {
        $this->haikuResponde('sin_consulta', ['motivo' => 'dato_no_disponible']);

        $informe = app(EvaluadorBancoAsistente::class)->evaluar($this->administrador, conHaiku: true);

        $this->assertSame(0, $informe['falsas']);
        $this->assertSame($informe['preguntas'] + $informe['preguntas_de_mas'], $informe['llamadas_haiku'] + 2, 'Las dos preguntas sensibles no salen.');
        $this->assertGreaterThan(0, $informe['costo_usd']);
    }

    private function preguntar(string $texto)
    {
        return $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => $texto])->assertOk();
    }

    /** @param  array<string, mixed>  $entrada */
    private function haikuResponde(string $herramienta, array $entrada): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_prueba', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_prueba', 'name' => $herramienta, 'input' => $entrada]],
            'usage' => ['input_tokens' => 150, 'output_tokens' => 60, 'cache_read_input_tokens' => 4800, 'cache_creation_input_tokens' => 0],
        ])]);
    }

    /** @param  array<string, string>  $entrada */
    private function consultar(array $entrada): array
    {
        return [...['periodo_tipo' => 'actual', 'desde' => '', 'hasta' => '', 'turno' => 'ninguno', 'cliente' => '', 'csg' => '', 'especie' => 'todas', 'agrupar_por' => 'nada'], ...$entrada];
    }

    private function llamadaPrevia(float $costo): void
    {
        LlamadaIaAsistente::query()->create([
            'user_id' => $this->administrador->id, 'modelo' => 'claude-haiku-5-5', 'estado' => 'ok', 'costo_usd' => $costo, 'created_at' => now()->subHour(),
        ]);
    }
}
