<?php

namespace Tests\Feature\Asistente;

use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\OpcionConsulta;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ServicioAsistente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** La vista viaja por la API: se ejecuta, se valida, se sugiere y se dice en «Entendí». */
class VistasAsistenteApiTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ejecutar_una_vista(): void
    {
        $this->ejecutar('guias-envases', ['vista' => 'sin-numero'])
            ->assertOk()
            ->assertJsonPath('data.parametros.vista', 'sin-numero')
            ->assertJsonPath('data.entendido.consulta', 'Guías de despacho de envases · Sin N° de guía emitida');
    }

    public function test_sin_vista_rige_la_por_defecto(): void
    {
        $this->ejecutar('guias-envases')->assertOk()->assertJsonPath('data.parametros.vista', 'borrador');
    }

    public function test_una_vista_inexistente_es_regla_de_negocio(): void
    {
        $this->ejecutar('guias-envases', ['vista' => 'anuladas'])->assertUnprocessable()->assertJsonPath('codigo', 'regla_de_negocio');
    }

    public function test_una_vista_con_formato_invalido_no_pasa_la_validacion(): void
    {
        $this->ejecutar('guias-envases', ['vista' => 'BORRA DOR'])->assertUnprocessable()->assertJsonValidationErrors('vista');
    }

    public function test_una_consulta_de_una_vista_sigue_igual(): void
    {
        $respuesta = $this->ejecutar('kilos-recepciones')->assertOk()->assertJsonPath('data.parametros.vista', 'general');

        $this->assertSame('Kilos recibidos', $respuesta->json('data.entendido.consulta'));
    }

    public function test_el_catalogo_trae_las_vistas(): void
    {
        $catalogo = collect($this->actingAs($this->administrador, 'sanctum')->getJson('/api/asistente/consultas')->assertOk()->json('data'));

        $this->assertSame(['borrador', 'confirmadas', 'sin-numero'], array_column($catalogo->firstWhere('clave', 'guias-envases')['vistas'], 'clave'));
        $this->assertSame(['general'], array_column($catalogo->firstWhere('clave', 'kilos-recepciones')['vistas'], 'clave'));
    }

    public function test_una_sugerencia_de_vista_lleva_la_vista_en_sus_parametros(): void
    {
        $guias = app(CatalogoConsultasAsistente::class)->buscar('guias-envases');
        $servicio = app(ServicioAsistente::class);
        $sugerir = new ReflectionMethod($servicio, 'sugerir');

        $respuesta = $sugerir->invoke($servicio, $this->administrador, 'guías', new ParametrosConsulta,
            [new OpcionConsulta($guias, $guias->vista('confirmadas')), $guias], '¿Cuál?', ServicioAsistente::MODO);

        $this->assertSame('confirmadas', $respuesta['sugerencias'][0]['vista']);
        $this->assertSame('confirmadas', $respuesta['sugerencias'][0]['parametros']['vista']);
        $this->assertSame('Guías de despacho de envases · Confirmadas', $respuesta['sugerencias'][0]['titulo']);
        // Un tema sin vista elegida se sugiere con su vista por defecto.
        $this->assertSame('borrador', $respuesta['sugerencias'][1]['vista']);
    }

    private function ejecutar(string $clave, array $datos = [])
    {
        return $this->actingAs($this->administrador, 'sanctum')->postJson("/api/asistente/consultas/{$clave}", $datos);
    }
}
