<?php

namespace Tests\Unit;

use App\Services\Autorizacion\CatalogoOficinas;
use App\Services\Autorizacion\InicioOficina;
use PHPUnit\Framework\TestCase;

class InicioOficinaTest extends TestCase
{
    private InicioOficina $inicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inicio = new InicioOficina(new CatalogoOficinas);
    }

    public function test_usa_la_pantalla_preferida_del_rol_cuando_el_perfil_la_habilita(): void
    {
        $this->assertSame('/oficina/romana', $this->inicio->resolver([
            'rol' => 'operador_romana',
            'modulos_acceso' => ['materia-prima.romana', 'materia-prima.digitacion'],
            'puede_consultar_romana' => true,
            'puede_consultar_materia_prima' => true,
        ]));
    }

    public function test_sin_la_pantalla_del_rol_usa_la_primera_oficina_habilitada_del_menu(): void
    {
        // El despachador prefiere Cargas, pero su perfil solo trae Materiales.
        $this->assertSame('/oficina/materiales', $this->inicio->resolver([
            'rol' => 'despachador',
            'modulos_acceso' => ['materiales.resumen'],
            'puede_consultar_catalogo_cargas' => false,
            'puede_consultar_despachos_materiales' => true,
        ]));
    }

    public function test_el_administrador_ve_todo_y_parte_en_operacion_ahora(): void
    {
        $this->assertSame('/oficina/operacion-ahora', $this->inicio->resolver([
            'rol' => 'administrador',
            'modulos_acceso' => null,
        ]));
    }

    public function test_un_rol_sin_preferida_recibe_la_primera_oficina_habilitada(): void
    {
        $this->assertSame('/oficina/consultas', $this->inicio->resolver([
            'rol' => 'rol_nuevo',
            'modulos_acceso' => ['consultas.sag'],
            'puede_consultar_sag' => true,
        ]));
    }

    public function test_las_claves_de_la_identidad_prevalecen_sobre_las_capacidades(): void
    {
        $this->assertNull($this->inicio->resolver([
            'rol' => 'consulta',
            'modulos_acceso' => ['consultas.busqueda'],
            'puede_consultar_oficina_consultas' => false,
            'capacidades' => ['puede_consultar_oficina_consultas' => true],
        ]));

        $this->assertSame('/oficina/consultas', $this->inicio->resolver([
            'rol' => 'consulta',
            'modulos_acceso' => ['consultas.busqueda'],
            'capacidades' => ['puede_consultar_oficina_consultas' => true],
        ]));
    }

    public function test_la_preferida_gana_aunque_otra_oficina_aparezca_antes_en_el_menu(): void
    {
        // El resumen de Frigorífico va antes que Cámaras, pero el camarero parte en Cámaras.
        $this->assertSame('/oficina/frigorifico/camaras', $this->inicio->resolver([
            'rol' => 'camarero_frio',
            'modulos_acceso' => ['frigorifico.camaras'],
            'ambito_camaras' => 'productos',
        ]));
    }

    public function test_camaras_exige_ambito_de_productos(): void
    {
        $this->assertNull($this->inicio->resolver([
            'rol' => 'camarero_frio',
            'modulos_acceso' => ['frigorifico.camaras'],
            'ambito_camaras' => 'materiales',
        ]));
    }

    public function test_la_pantalla_del_perfil_gana_a_la_del_rol(): void
    {
        $identidad = [
            'rol' => 'operador_romana',
            'modulos_acceso' => ['materia-prima.romana', 'materia-prima.digitacion'],
            'puede_consultar_romana' => true,
            'puede_consultar_materia_prima' => true,
        ];

        $this->assertSame('/oficina/materia-prima/lotes', $this->inicio->resolver($identidad, '/oficina/materia-prima/lotes'));
    }

    public function test_si_el_perfil_no_habilita_su_pantalla_se_usa_la_del_rol(): void
    {
        $identidad = [
            'rol' => 'operador_romana',
            'modulos_acceso' => ['materia-prima.romana'],
            'puede_consultar_romana' => true,
        ];

        $this->assertSame('/oficina/romana', $this->inicio->resolver($identidad, '/oficina/materia-prima/lotes'));
        $this->assertSame('/oficina/romana', $this->inicio->resolver($identidad, '/oficina/no-existe'));
    }

    public function test_sin_oficinas_habilitadas_no_hay_inicio(): void
    {
        $this->assertNull($this->inicio->resolver([
            'rol' => 'validador',
            'modulos_acceso' => [],
        ]));
    }
}
