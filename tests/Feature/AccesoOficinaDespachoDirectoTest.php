<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccesoOficinaDespachoDirectoTest extends TestCase
{
    public function test_identidad_de_oficina_expone_permiso_retiro_para_despacho_directo(): void
    {
        $controller = file_get_contents(
            app_path('Services/Autorizacion/IdentidadOficina.php'),
        );
        $office = file_get_contents(resource_path('js/office-materials.js'));

        $this->assertIsString($controller);
        $this->assertStringContainsString(
            "'puede_retirar_materiales' => \$capacidades['puede_retirar_materiales']",
            $controller,
        );
        $this->assertIsString($office);
        $this->assertStringContainsString(
            'state.identity?.puede_retirar_materiales === true',
            $office,
        );
    }
}
