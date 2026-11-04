<?php

namespace Tests\Feature\Office;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EstructuraOficinaTest extends TestCase
{
    public function test_preparacion_cargas_exporta_las_segregaciones_por_instructivo(): void
    {
        $html = $this->get('/oficina/frigorifico/despacho/cargas')->assertOk()->getContent();
        foreach (['loadInstructions', 'loadInstructionCards', 'loadInstructionSelect', 'folioTableBody'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/cargas.html'), $html);
        }
    }

    public function test_retornos_packing_exporta_el_selector_de_envases_y_cantidades(): void
    {
        $html = $this->get('/oficina/materia-prima/retornos-packing')->assertOk()->getContent();
        foreach (['returnContainerType', 'returnContainerQuantity', 'editReturnContainerType', 'registeredBoxes'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/retornos-packing.html'), $html);
        }
    }

    public function test_ordenes_mp_exporta_el_editor_y_la_correccion_de_entregas(): void
    {
        $html = $this->get('/oficina/materia-prima/ordenes-proceso')->assertOk()->getContent();
        $this->assertStringContainsString('Total programado', $html);
        $this->assertStringContainsString('id="deliveryPackagingForm"', $html);
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/ordenes-proceso.html'), $html);
        }
    }

    public function test_operacion_ahora_exporta_la_vista_para_probar_la_disponibilidad(): void
    {
        $html = $this->get('/oficina/operacion-ahora')->assertOk()->getContent();
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/operacion-ahora.html'), $html);
        }
    }

    public function test_labores_turno_tiene_formulario_navegacion_y_resumen(): void
    {
        $html = $this->get('/oficina/frigorifico/labores-turno')->assertOk()->getContent();
        foreach (['shiftForm', 'shiftAssignments', 'shiftJobs', 'shiftStart', 'shiftEnd'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        $this->assertStringContainsString('aria-current="page"', $html);
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/turnos.html'), $html);
        }
    }

    public function test_consumos_por_ot_tiene_filtros_trazabilidad_y_exportacion(): void
    {
        $html = $this->get('/oficina/materiales/consumos')->assertOk()->getContent();
        foreach (['consumptionFilters', 'consumptionOrders', 'exportConsumptions'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/consumos-ot.html'), $html);
        }
    }

    public function test_tomas_inventario_conserva_cierre_historial_y_filtros(): void
    {
        $html = $this->get('/oficina/materiales/tomas')->assertOk()->getContent();
        foreach (['season', 'warehouseLabel', 'closingSummary', 'decisionHistory'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/tomas.html'), $html);
        }
    }

    public function test_estatus_cargas_conserva_el_detalle_de_recepciones(): void
    {
        $html = $this->get('/oficina/frigorifico/despacho/estatus')->assertOk()->getContent();
        $this->assertStringContainsString('id="dispatchMessage"', $html);
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/estatus.html'), $html);
        }
    }

    public function test_capacidad_materiales_tiene_formularios_y_navegacion(): void
    {
        $html = $this->get('/oficina/materiales/capacidad')->assertOk()->getContent();
        $this->assertStringContainsString('id="capacityWarehouseForm"', $html);
        $this->assertStringContainsString('id="capacitySimulationForm"', $html);
        $this->assertStringContainsString('id="routingPanel"', $html);
        $this->assertStringContainsString('name="motivo"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/capacidad.html'), $html);
        }
    }

    public function test_consultas_exporta_el_buscador_y_el_expediente_de_folio(): void
    {
        $html = $this->get('/oficina/consultas/busqueda')->assertOk()->getContent();
        foreach (['globalSearchForm', 'searchResults', 'folioDialog', 'folioDialogBody'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/consultas-busqueda.html'), $html);
        }
    }

    public function test_supervision_materiales_tiene_navegacion_y_consulta_sin_acciones_operativas(): void
    {
        $html = $this->get('/oficina/materiales/supervision')->assertOk()->getContent();
        $this->assertStringContainsString('office="supervision"', file_get_contents(resource_path('views/office/material-supervision.blade.php')));
        $this->assertStringContainsString('id="supervisionFilters"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringNotContainsString('Eliminar recepción', $html);
        if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
            File::ensureDirectoryExists(storage_path('app/ui/oficina'));
            File::put(storage_path('app/ui/oficina/supervision.html'), $html);
        }
    }

    public function test_cabecera_conserva_identidad_y_navegacion_en_las_seis_areas(): void
    {
        foreach (['/oficina/frigorifico/camaras', '/oficina/validacion', '/oficina/validacion/pendientes', '/oficina/envases/cuenta-corriente', '/oficina/materia-prima', '/oficina/frigorifico', '/oficina/materiales', '/oficina/materiales/catalogos', '/oficina/materiales/despachos', '/oficina/materiales/items', '/oficina/administracion', '/oficina/consultas', '/oficina/accesos', '/oficina/porteria', '/oficina/romana', '/oficina/finanzas/tarifario', '/oficina/finanzas/proveedores', '/oficina/rrhh', '/oficina/rrhh/marcaje', '/oficina/comex/comercial', '/oficina/comex/notas', '/oficina/comex/bookings', '/oficina/finanzas/costos-comex', '/oficina/frigorifico/contraparte-sag'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            if (getenv('ESTIBA_EXPORTAR_UI_TESTS') === '1') {
                File::ensureDirectoryExists(storage_path('app/ui/oficina'));
                File::put(storage_path('app/ui/oficina/'.basename($path).'.html'), $html);
            }
            $dom = new DOMDocument;
            $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            $this->assertSame(6, $xpath->query('//*[@data-domain-key]')->length, $path);
            foreach (['officeUserName', 'officeUserRole', 'officeInitials', 'officeLogoutButton', 'officeSidebar'] as $id) {
                $this->assertSame(1, $xpath->query('//*[@id="'.$id.'"]')->length, $path.' '.$id);
            }
            $this->assertSame(1, $xpath->query('//*[@data-office-context-refresh]')->length, $path);
            $this->assertSame(1, $xpath->query('//*[@data-office-context-status]')->length, $path);
            $this->assertSame(1, $xpath->query('//*[@data-office-menu]')->length, $path);
            $this->assertSame(1, $xpath->query('//*[@data-office-theme-toggle]')->length, $path);
            $this->assertSame(0, $xpath->query('//*[@id="officeThemeSelector"]')->length, $path);
            $this->assertSame(1, $xpath->query('//a[@aria-current="page"]')->length, $path);
            $this->assertSame(0, $xpath->query('//*[@data-office-shell]//button[not(@type)]')->length, $path);
        }
    }
}
