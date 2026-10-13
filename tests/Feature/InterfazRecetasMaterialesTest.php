<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazRecetasMaterialesTest extends TestCase
{
    public function test_oficina_materiales_carga_la_integracion_de_recetas(): void
    {
        $this->get('/oficina/materiales')->assertOk();

        $script = file_get_contents(resource_path('js/office-material-recipes.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('/api/materiales/transformaciones/recetas?per_page=100', $script);
        $this->assertStringContainsString('/versiones', $script);
        $this->assertStringContainsString('puede_consultar_transformaciones_materiales', $script);
        $this->assertStringContainsString('puede_administrar_recetas_materiales', $script);
        $this->assertStringContainsString("section.dataset.materialsView = 'recetas'", $script);
        $this->assertStringContainsString('recipeSectionIsActive()', $script);
        $this->assertStringContainsString("['insumo', 'material_mp']", $script);
        $this->assertStringContainsString("['material_mp', 'material_pt']", $script);
        $this->assertStringContainsString('MP → PT mismo código', $script);
        $this->assertStringContainsString('suggestSameItemTransformation', $script);
        $this->assertStringContainsString('Selecciona exactamente un componente principal.', $script);
        $this->assertStringContainsString('recipe.versiones_count', $script);
    }

    public function test_la_receta_no_pide_merma_porque_se_cuadra_al_cerrar_la_orden(): void
    {
        $script = file_get_contents(resource_path('js/office-material-recipes.js'));

        $this->assertIsString($script);
        $this->assertStringNotContainsString('<span>Merma %</span>', $script);
        $this->assertStringNotContainsString('name="merma_estandar_porcentaje"', $script);
        $this->assertStringContainsString('merma_estandar_porcentaje: 0,', $script);
        $this->assertStringContainsString('el camarero la declara al cerrar la orden de trabajo', $script);
    }
}
