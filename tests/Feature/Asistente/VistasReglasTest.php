<?php

namespace Tests\Feature\Asistente;

use App\Services\Asistente\CatalogoConsultasAsistente;
use App\Services\Asistente\InterpreteReglasAsistente;
use App\Services\Asistente\OpcionConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Las reglas eligen primero el tema y después la vista; si la vista no está clara, preguntan. */
class VistasReglasTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_frase_le_gana_a_la_raiz(): void
    {
        // «confirmar» comparte raíz con «confirmadas», pero «sin confirmar» es la frase de borrador.
        $interpretacion = $this->interpretar('guías sin confirmar');

        $this->assertSame('guias-envases', $interpretacion['consulta']?->clave());
        $this->assertSame('borrador', $interpretacion['parametros']->vista);
    }

    public function test_sin_palabras_de_vista_rige_la_por_defecto(): void
    {
        $interpretacion = $this->interpretar('guías gde');

        $this->assertSame('guias-envases', $interpretacion['consulta']?->clave());
        $this->assertSame('borrador', $interpretacion['parametros']->vista);
    }

    public function test_una_consulta_de_una_vista_queda_en_general(): void
    {
        $this->assertSame('general', $this->interpretar('kilos recibidos hoy')['parametros']->vista);
    }

    public function test_un_tema_sin_vista_por_defecto_sugiere_sus_vistas(): void
    {
        $catalogo = app(CatalogoConsultasAsistente::class);
        $this->app->instance(CatalogoConsultasAsistente::class, new CatalogoConsultasAsistente([...$catalogo->todas(), app(TemaDePrueba::class)]));

        $interpretacion = $this->interpretar('bodegones');

        $this->assertNull($interpretacion['consulta']);
        $this->assertSame('«Bodegas de prueba» tiene varias vistas. ¿Cuál quieres?', $interpretacion['motivo']);
        $this->assertContainsOnlyInstancesOf(OpcionConsulta::class, $interpretacion['alternativas']);
        $this->assertSame(['ocupacion', 'clientes'], array_map(fn (OpcionConsulta $o) => $o->vista?->clave, $interpretacion['alternativas']));
    }

    /** @return array<string, mixed> */
    private function interpretar(string $texto): array
    {
        return app(InterpreteReglasAsistente::class)->interpretar($this->administrador, $texto);
    }
}
