<?php

namespace Tests\Feature\Asistente;

use App\Models\Cliente;
use App\Models\ConfiguracionAsistente;
use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\EvaluadorBancoAsistente;
use App\Services\Asistente\Haiku\CoberturaConsultaHaiku;
use App\Services\Asistente\Haiku\FichasAsistente;
use App\Services\Asistente\Haiku\InterpreteHaiku;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\ReconocedorEntidades;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

class CoberturaHaikuTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 12:00', 'America/Santiago'));
        $this->prepararAsistente();
        config(['asistente.anthropic.api_key' => 'clave-de-prueba']);
        ConfiguracionAsistente::actual()->update(['modo' => 'mixto']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Las ocho elecciones falsas observadas en la evaluación real del 23-11-2026. */
    public static function eleccionesFalsas(): array
    {
        return [
            'promedio por CSG' => ['¿Cuánto da el neto por tote por CSG esta semana?', 'revisar-taras'],
            'solo vacíos' => ['¿Cuántos totes llegaron vacíos hoy en Validación MP?', 'envases-recibidos'],
            'salida al destare' => ['¿Qué camiones salieron con envases en el destare hoy?', 'revisar-taras'],
            'pendientes' => ['¿Qué falta por validar?', 'validaciones-pt'],
            'producción histórica' => ['¿Cuántas cajas se embalaron ayer para Valle Alto?', 'cajas-pt'],
            'retornos de cargas' => ['¿Qué pallets volvieron de cargas esta semana?', 'repaletizajes'],
            'cajas y destino' => ['¿Cuántas cajas despachamos a Estados Unidos esta temporada?', 'folios-despachados'],
            'cartón en Materiales' => ['¿Cuántas cajas de cartón le quedan a Valle Alto?', 'formatos-pt'],
        ];
    }

    public static function preguntasSinCobertura(): array
    {
        return [...self::eleccionesFalsas(),
            'media por productor' => ['Dame la media del neto por tote por cada productor', 'revisar-taras'],
            'totes sin fruta' => ['Total de totes sin fruta que llegaron hoy', 'envases-recibidos'],
            'bins llenos' => ['Solo los bins llenos que ingresaron', 'envases-recibidos'],
            'envases entregados al camión' => ['Lista los envases entregados al camión al salir', 'revisar-taras'],
            'folios sin validar' => ['Cuántos folios están sin validar', 'validaciones-pt'],
            'validaciones faltantes' => ['Muestra las validaciones faltantes del turno', 'validaciones-pt'],
            'stock de otro mes aunque Haiku omita la fecha' => ['Cuántas cajas había el mes pasado', 'cajas-pt'],
            'producción de hoy no es stock' => ['Cuántas cajas produjimos hoy', 'cajas-pt'],
            'pallets devueltos' => ['Lista los pallets devueltos desde despacho', 'repaletizajes'],
            'devoluciones' => ['Cuántas devoluciones hubo hoy', 'repaletizajes'],
            'unidad kilos' => ['Cuántos kilos despachamos ayer', 'folios-despachados'],
            'filtro de destino' => ['Qué pallets despachamos hacia China', 'folios-despachados'],
            'stock de cartones' => ['Disponibilidad de cartones vacíos', 'formatos-pt'],
            'insumos' => ['Cuántas bolsas quedan disponibles', 'cajas-pt'],
        ];
    }

    #[DataProvider('preguntasSinCobertura')]
    public function test_no_acepta_una_consulta_que_responde_otro_evento_estado_metrica_o_filtro(string $pregunta, string $consulta): void
    {
        $entrada = $this->entrada($pregunta, $consulta);
        // Aun si el modelo elimina el periodo histórico, el servidor conserva el de la pregunta.
        if ($consulta === 'cajas-pt') {
            $entrada = [...$entrada, 'periodo_tipo' => 'actual', 'desde' => '', 'hasta' => ''];
        }
        Http::fake(['*' => Http::response($this->respuesta('consultar', $entrada))]);
        $entidades = app(ReconocedorEntidades::class)->reconocer($pregunta);

        $resultado = app(InterpreteHaiku::class)->interpretar($this->administrador, $pregunta, $entidades);

        $this->assertSame(InterpreteHaiku::PARCIAL, $resultado['tipo']);
        $this->assertSame($consulta, $resultado['consulta']->clave());
        $this->assertNull($resultado['parametros']);
        $this->assertNotEmpty($resultado['sin_cubrir']);
        $this->assertStringStartsWith('parcial:', $resultado['llamada']->detalle);
        Http::assertSentCount(1);
    }

    public static function preguntasConCobertura(): array
    {
        return [
            ['Revisa anomalías de neto por tote por CSG esta semana', 'revisar-taras'],
            ['Cuántos envases recibimos hoy', 'envases-recibidos'],
            ['Cuántos folios se validaron hoy', 'validaciones-pt'],
            ['Cuántas cajas hay ahora', 'cajas-pt'],
            ['Cuántas cajas hay por formato', 'formatos-pt'],
            ['Qué repas se confirmaron ayer', 'repaletizajes'],
            ['Cuántos pallets despachamos ayer', 'folios-despachados'],
        ];
    }

    #[DataProvider('preguntasConCobertura')]
    public function test_conserva_las_consultas_que_si_responden_la_pregunta(string $pregunta, string $consulta): void
    {
        Http::fake(['*' => Http::response($this->respuesta('consultar', $this->entrada($pregunta, $consulta)))]);
        $resultado = app(InterpreteHaiku::class)->interpretar($this->administrador, $pregunta,
            app(ReconocedorEntidades::class)->reconocer($pregunta));

        $this->assertSame(InterpreteHaiku::CONSULTA, $resultado['tipo']);
        $this->assertSame($consulta, $resultado['consulta']->clave());
    }

    public function test_no_permite_que_haiku_quite_un_cliente_o_un_periodo_reconocido(): void
    {
        $pregunta = 'cuánta fruta recibió Exportadora Andina el jueves';
        $casos = ['cliente' => ['cliente' => ''], 'periodo' => ['periodo_tipo' => 'actual', 'desde' => '', 'hasta' => '']];
        $secuencia = Http::sequence();
        foreach ($casos as $cambio) {
            $secuencia->push($this->respuesta('consultar', [...$this->entrada($pregunta, 'kilos-recepciones'), ...$cambio]));
        }
        Http::fake(['*' => $secuencia]);
        foreach (array_keys($casos) as $campo) {
            $resultado = app(InterpreteHaiku::class)->interpretar($this->administrador, $pregunta,
                app(ReconocedorEntidades::class)->reconocer($pregunta));
            $this->assertSame(InterpreteHaiku::PARCIAL, $resultado['tipo']);
            $this->assertNull($resultado['parametros']);
            $this->assertStringContainsString($campo, implode(' ', $resultado['sin_cubrir']));
        }
    }

    public function test_conserva_la_vista_confirmadas_y_su_periodo_reconocido(): void
    {
        $pregunta = 'Guías de envases confirmadas ayer';
        $entrada = [...$this->entrada($pregunta, 'guias-envases'), 'vista' => 'confirmadas'];
        Http::fake(['*' => Http::response($this->respuesta('consultar', $entrada))]);

        $resultado = app(InterpreteHaiku::class)->interpretar($this->administrador, $pregunta,
            app(ReconocedorEntidades::class)->reconocer($pregunta));

        $this->assertSame(InterpreteHaiku::CONSULTA, $resultado['tipo']);
        $this->assertSame('confirmadas', $resultado['vista']->clave);
        $this->assertSame('confirmadas', $resultado['parametros']->vista);
        $this->assertSame(['2026-11-22', '2026-11-22'], $resultado['parametros']->jornadas());
    }

    public function test_una_vista_sin_periodo_no_ignora_el_dia_que_haiku_omite(): void
    {
        $pregunta = 'Guías de envases en borrador ayer';
        $entrada = [...$this->entrada($pregunta, 'guias-envases'), 'vista' => 'borrador',
            'periodo_tipo' => 'actual', 'desde' => '', 'hasta' => ''];
        Http::fake(['*' => Http::response($this->respuesta('consultar', $entrada))]);

        $resultado = app(InterpreteHaiku::class)->interpretar($this->administrador, $pregunta,
            app(ReconocedorEntidades::class)->reconocer($pregunta));

        $this->assertSame(InterpreteHaiku::PARCIAL, $resultado['tipo']);
        $this->assertSame('borrador', $resultado['vista']->clave);
        $this->assertNull($resultado['parametros']);
        $this->assertStringContainsString('periodo solicitado', implode(' ', $resultado['sin_cubrir']));
    }

    public function test_los_limites_de_envases_recibidos_valen_para_sus_dos_vistas(): void
    {
        $limites = app(CoberturaConsultaHaiku::class)->limites(app(CatalogoConsultasAsistente::class)->buscar('envases-recibidos'));

        // El límite se repite en la línea de cada vista: no puede describir solo la de recibidos.
        $this->assertStringNotContainsString('Totales recibidos', $limites);
        $this->assertStringContainsString('Ninguna vista', $limites);
    }

    public function test_la_api_no_ejecuta_la_consulta_incorrecta_y_ofrece_aclarar(): void
    {
        $pregunta = '¿Qué falta por validar?';
        Http::fake(['*' => Http::response($this->respuesta('consultar', $this->entrada($pregunta, 'validaciones-pt')))]);

        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => $pregunta])
            ->assertOk()->assertJsonPath('data.tipo', 'sugerencias')->assertJsonPath('data.resultado', null)
            ->assertJsonPath('data.consulta', null);
    }

    public function test_el_banco_rechaza_las_ocho_elecciones_falsas_reproducidas_sin_llamar_a_la_api_real(): void
    {
        Cliente::create(['codigo' => 'VAL', 'nombre' => 'Valle Alto', 'activo' => true]);
        $this->csg('104512');
        $repeticiones = [];
        foreach (self::eleccionesFalsas() as [$pregunta, $consulta]) {
            $entidades = app(ReconocedorEntidades::class)->reconocer($pregunta);
            $texto = app(FichasAsistente::class)->preparar($pregunta, $entidades)['texto'];
            $repeticiones[$texto] = $this->entrada($pregunta, $consulta);
        }
        Http::fake(function (Request $pedido) use ($repeticiones) {
            foreach ($repeticiones as $texto => $entrada) {
                if (str_contains($pedido['messages'][0]['content'], 'Pregunta: «'.$texto.'»')) {
                    return Http::response($this->respuesta('consultar', $entrada));
                }
            }

            return Http::response($this->respuesta('sin_consulta', ['motivo' => 'dato_no_disponible']));
        });

        $informe = app(EvaluadorBancoAsistente::class)->evaluar($this->administrador, conHaiku: true);

        $this->assertSame(0, $informe['falsas']);
        // La salida al destare (n.º 17) ya la responden las reglas; las otras siete llegan a Haiku y quedan parciales.
        $destare = collect($informe['detalle'])->firstWhere('n', 17);
        $this->assertSame(['reglas', 'envases-recibidos', 'salidas-destare'], [$destare['interprete'], $destare['elegida'], $destare['vista_elegida']]);
        $this->assertSame(7, collect($informe['detalle'])->where('interprete', 'haiku:parcial')->count());
        $this->assertStringContainsString('sin cubrir:', collect($informe['detalle'])->firstWhere('n', 24)['detalle']);
        $this->assertGreaterThan(0, $informe['llamadas_exitosas_haiku']);
        $this->assertSame(0, $informe['errores_haiku']);
    }

    public function test_cero_falsas_con_errores_de_api_no_aprueba_la_evaluacion_json(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $codigo = Artisan::call('asistente:evaluar', ['--modo' => 'haiku', '--json' => true]);
        $informe = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $informe['falsas']);
        $this->assertGreaterThan(0, $informe['errores_haiku']);
        $this->assertSame(0, $informe['llamadas_exitosas_haiku']);
        $this->assertSame(1, $codigo);
    }

    public function test_una_lista_sin_cubrir_sin_texto_no_desactiva_la_comprobacion_del_servidor(): void
    {
        $pregunta = '¿Qué falta por validar?';
        $entrada = [...$this->entrada($pregunta, 'validaciones-pt'), 'pide' => 'folios pendientes', 'sin_cubrir' => [' ', null]];
        Http::fake(['*' => Http::response($this->respuesta('consultar', $entrada))]);

        $resultado = app(InterpreteHaiku::class)->interpretar($this->administrador, $pregunta,
            app(ReconocedorEntidades::class)->reconocer($pregunta));

        $this->assertSame(InterpreteHaiku::PARCIAL, $resultado['tipo']);
        $this->assertNull($resultado['parametros']);
        $this->assertNotEmpty($resultado['sin_cubrir']);
        $this->assertSame('folios pendientes', $resultado['pide']);
    }

    public function test_cero_falsas_con_tope_o_limite_diario_tampoco_aprueba(): void
    {
        Http::fake(['*' => Http::response($this->respuesta('sin_consulta', ['motivo' => 'dato_no_disponible']))]);
        config(['asistente.topes.diario_usuario' => 1]);

        $this->artisan('asistente:evaluar --modo=haiku')->expectsOutputToContain('Evaluación incompleta')->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_la_evaluacion_aprueba_si_la_api_responde_y_no_hay_falsas(): void
    {
        Http::fake(['*' => Http::response($this->respuesta('sin_consulta', ['motivo' => 'dato_no_disponible']))]);

        $this->artisan('asistente:evaluar --modo=haiku')->expectsOutputToContain('0 errores')->assertSuccessful();
    }

    private function entrada(string $pregunta, string $consulta): array
    {
        $entidades = app(ReconocedorEntidades::class)->reconocer($pregunta);
        $periodo = app(PeriodoOperativo::class);
        $explicito = $entidades['periodo_explicito'];

        return [
            // Haiku elige la vista del tema; en uno de varias, «general» no es una elección válida.
            'pide' => '', 'consulta' => $consulta, 'vista' => app(CatalogoConsultasAsistente::class)->buscar($consulta)?->vista(null)?->clave ?? 'general', 'sin_cubrir' => [],
            'periodo_tipo' => $explicito ? ($entidades['turno'] ? 'turno' : 'rango') : 'actual',
            'desde' => $explicito ? $periodo->jornada($entidades['desde']) : '',
            'hasta' => $explicito ? $periodo->jornada($entidades['hasta']->subSecond()) : '',
            'turno' => $entidades['turno'] ?? 'ninguno', 'cliente' => $entidades['cliente_id'] ? 'CLIENTE_1' : '',
            'csg' => $entidades['csg_id'] ? 'CSG_1' : '', 'especie' => 'todas', 'agrupar_por' => 'nada',
        ];
    }

    private function respuesta(string $herramienta, array $entrada): array
    {
        return ['content' => [['type' => 'tool_use', 'name' => $herramienta, 'input' => $entrada]],
            'usage' => ['input_tokens' => 150, 'output_tokens' => 60]];
    }
}
