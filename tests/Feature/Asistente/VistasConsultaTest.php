<?php

namespace Tests\Feature\Asistente;

use App\Services\Asistente\Consultas\KilosRecepciones;
use App\Services\Asistente\Consultas\VistaConsulta;
use App\Services\Asistente\ParametrosConsulta;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Temas y vistas: las consultas actuales tienen una vista general; un tema une las de sus vistas. */
class VistasConsultaTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_una_consulta_actual_tiene_una_vista_general(): void
    {
        $consulta = app(KilosRecepciones::class);

        $vistas = $consulta->vistas();

        $this->assertCount(1, $vistas);
        $this->assertSame(VistaConsulta::GENERAL, $vistas[0]->clave);
        $this->assertSame($consulta->titulo(), $vistas[0]->titulo);
        $this->assertSame($consulta->filtros(), $vistas[0]->filtros);
        $this->assertEquals($vistas[0], $consulta->vista(null));
        $this->assertEquals($vistas[0], $consulta->vista('general'));
        $this->assertNull($consulta->vista('otra'));
        $this->assertTrue($consulta->puedeVer($this->administrador, $vistas[0]));
    }

    public function test_un_tema_une_palabras_y_filtros_de_sus_vistas(): void
    {
        $tema = app(TemaDePrueba::class);

        $this->assertSame(['periodo', 'cliente'], $tema->filtros());
        // Las frases («por cliente») quedan para elegir la vista, no para el puntaje del tema.
        $this->assertSame(['bodegon', 'bodegones', 'ocupacion', 'ocupadas', 'cliente', 'clientes'], $tema->palabrasClave());
        $this->assertNull($tema->vista(null));
        $this->assertNull($tema->vista('general'));
        $this->assertSame('clientes', $tema->vista('clientes')?->clave);
        $this->assertSame('Vista ocupacion.', $tema->ejecutar($this->administrador, (new ParametrosConsulta)->conVista('ocupacion'))->resumen);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('«Bodegas de prueba» no tiene esa vista.');
        $tema->ejecutar($this->administrador, (new ParametrosConsulta)->conVista('otra'));
    }

    public function test_los_parametros_llevan_la_vista(): void
    {
        $this->assertSame('borrador', ParametrosConsulta::desdeArreglo(['vista' => 'borrador'])->toArray()['vista']);
        $this->assertNull(ParametrosConsulta::desdeArreglo(['vista' => 'BORRA DOR'])->vista);
        $this->assertSame('x', (new ParametrosConsulta)->conVista('x')->vista);
        $this->assertArrayNotHasKey('vista', (new ParametrosConsulta)->toArray());
    }
}
