<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaMaterialesTest extends TestCase
{
    public function test_materiales_solo_consume_la_temporada_transversal(): void
    {
        $this->get('/oficina/materiales')
            ->assertOk()
            ->assertSee('TEMPORADA TRANSVERSAL')
            ->assertSee('La temporada se crea, edita y activa en la oficina Accesos.')
            ->assertDontSee('seasonMaterialForm', false)
            ->assertDontSee('Guardar temporada')
            ->assertDontSee('Nueva temporada');
    }

    public function test_materiales_separa_sus_procesos_en_modulos_seleccionables(): void
    {
        $this->get('/oficina/materiales')
            ->assertOk()
            ->assertSee('data-materials-section="resumen"', false)
            ->assertSee('Catálogos')
            ->assertSee('Recepciones')
            ->assertSee('Etiquetas')
            ->assertSee('Inventario BC')
            ->assertSee('Inventario CC')
            ->assertSee('Despachos')
            ->assertSee('Recetas')
            ->assertSee('Órdenes')
            ->assertSee('Exportaciones');

        $routes = [
            '/oficina/materiales/catalogos' => 'catalogos',
            '/oficina/materiales/recepciones' => 'recepciones',
            '/oficina/materiales/recepcion' => 'recepcion',
            '/oficina/materiales/inventario' => 'inventario',
            '/oficina/materiales/despachos' => 'despachos',
            '/oficina/materiales/recetas' => 'recetas',
            '/oficina/materiales/ordenes' => 'ordenes',
        ];

        foreach ($routes as $route => $section) {
            $this->get($route)
                ->assertOk()
                ->assertSee("data-materials-section=\"{$section}\"", false)
                ->assertSee("data-active-office=\"{$section}\"", false);
        }

        $this->get('/oficina/materiales/transformacion')
            ->assertRedirect('/oficina/materiales/recetas');
    }

    public function test_cada_bloque_principal_de_materiales_pertenece_a_una_sola_vista(): void
    {
        $this->get('/oficina/materiales')
            ->assertOk()
            ->assertSee('id="materialsModuleOverview" data-materials-view="resumen"', false)
            ->assertSee('id="materialsAdminCatalogs" data-materials-view="catalogos"', false)
            ->assertSee('id="materialReceptionsWorkspace" data-materials-view="recepciones"', false)
            ->assertSee('id="materialLabelWorkspace" data-materials-view="recepcion"', false)
            ->assertSee('id="materialDispatchWorkspace" data-materials-view="despachos"', false)
            ->assertSee('id="materialInventoryWorkspace" data-materials-view="inventario"', false);
    }

    public function test_recepciones_de_materiales_expone_administracion_y_carga_masiva_de_bultos(): void
    {
        $this->get('/oficina/materiales/recepciones')
            ->assertOk()
            ->assertSee('Recepciones y folios')
            ->assertSee('Muestreo en blanco')
            ->assertSee('downloadBlankMaterialSampling', false)
            ->assertSee('Motivo de la corrección administrativa')
            ->assertSee('Eliminar y liberar folios')
            ->assertSee('type="date"', false);

        $script = file_get_contents(resource_path('js/office-material-receptions.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('Unidades por bulto', $script);
        $this->assertStringContainsString('puede_administrar_recepciones_materiales', $script);
        $this->assertStringContainsString('/administrar', $script);
        $this->assertStringContainsString("method: 'DELETE'", $script);
        $this->assertStringContainsString('Math.ceil(accepted / packageSize)', $script);
        $this->assertStringContainsString('Registro de muestreo', $script);
        $this->assertStringContainsString('registro-muestreo/en-blanco', $script);
        $this->assertStringContainsString('/registro-muestreo', $script);
        $this->assertStringContainsString('type="search"', $script);
        $this->assertStringContainsString('Buscar por código, nombre, categoría o unidad', $script);
        $this->assertStringContainsString('material-reception-item-filter', $script);
        $this->assertStringContainsString('data-select-reception-item', $script);
        $this->assertStringContainsString('receptionNormalizedSearch', $script);
    }

    public function test_catalogo_permite_regularizar_pt_duplicado_sin_reescribir_historia(): void
    {
        $this->get('/oficina/materiales/catalogos')
            ->assertOk()
            ->assertSee('materialRegularizationDialog', false)
            ->assertSee('Consolidar Material PT duplicado')
            ->assertSee('La historia no se reescribe')
            ->assertSee('Consolidar ítems');

        $script = file_get_contents(resource_path('js/office-materials.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('data-regularize-item', $script);
        $this->assertStringContainsString('/regularizar', $script);
        $this->assertStringContainsString('regularizationOperationId', $script);
        $this->assertStringContainsString("candidate.categoria_operacional === 'material_mp'", $script);
    }

    public function test_inventario_cc_esta_integrado_a_materiales_con_tema_filtros_y_exportacion_propios(): void
    {
        $this->get('/oficina/materiales/almacenes')
            ->assertOk()
            ->assertSee('data-active-office="custodia"', false)
            ->assertSee('Inventario CC')
            ->assertSee('id="custodyFilters"', false)
            ->assertSee('id="custodyExport"', false)
            ->assertSee('id="custodyHistoryFilters"', false)
            ->assertSee('id="custodyHistoryExport"', false)
            ->assertSee('Movimientos: entregas, devoluciones y transferencias')
            ->assertDontSee('id="custodyLoginForm"', false)
            ->assertDontSee('Correo electrónico');

        $script = file_get_contents(resource_path('js/office-material-warehouses.js'));
        $styles = file_get_contents(resource_path('css/office-materials.css'));
        $view = file_get_contents(resource_path('views/office/material-warehouses.blade.php'));
        $vite = file_get_contents(base_path('vite.config.js'));
        $provider = file_get_contents(
            app_path('Providers/CustodiaDistribuidaMaterialesServiceProvider.php'),
        );

        $this->assertIsString($script);
        foreach (['q', 'cliente_id', 'item_id', 'almacen_id', 'camara_id'] as $filtro) {
            $this->assertStringContainsString($filtro, $script);
        }
        $this->assertStringContainsString('/api/materiales/almacenes/exportar', $script);
        $this->assertStringContainsString(
            '/api/materiales/almacenes/movimientos/exportar',
            $script,
        );
        $this->assertStringContainsString('historyExportParameters()', $script);
        $this->assertStringContainsString('function movementRows()', $script);
        $this->assertStringContainsString('function renderFoliosForOrigin()', $script);
        $this->assertStringContainsString('Disponible:', $script);
        $this->assertStringContainsString('row.almacen.id === originId', $script);
        $this->assertStringContainsString('Number(row.cantidad_disponible) > 0', $script);
        $this->assertStringNotContainsString('function inferOrigin()', $script);
        $this->assertLessThan(
            strpos($view, 'Folio disponible en el almacén'),
            strpos($view, 'Almacén origen'),
        );
        $this->assertStringContainsString('id="custodyOriginSummary"', $view);
        $this->assertStringContainsString("const tokenKey = 'estiba_wms_office_token'", $script);
        $this->assertStringContainsString("window.location.replace('/oficina/materiales')", $script);
        $this->assertStringContainsString('error.status === 401', $script);
        $this->assertStringContainsString(
            "document.querySelector('[data-custody-filter-warehouse]')",
            $script,
        );
        $this->assertStringNotContainsString("$('custodyLoginForm')", $script);
        $this->assertStringNotContainsString("$('[data-custody-filter-warehouse]')", $script);
        $this->assertIsString($styles);
        $this->assertStringContainsString('.custody-filters', $styles);
        $this->assertStringContainsString('background: var(--panel)', $styles);
        $this->assertIsString($view);
        $this->assertStringNotContainsString('custodyLoginForm', $view);
        $this->assertStringNotContainsString('<style>', $view);
        $this->assertStringNotContainsString('<script>', $view);
        $this->assertIsString($vite);
        $this->assertStringContainsString(
            'resources/js/office-material-warehouses.js',
            $vite,
        );
        $this->assertIsString($provider);
        $this->assertStringNotContainsString('loadRoutesFrom', $provider);
    }

    public function test_despacho_directo_se_opera_desde_inventario_y_no_se_crea_en_pda(): void
    {
        $this->get('/oficina/materiales/inventario')
            ->assertOk()
            ->assertSee('id="materialDirectDispatchDialog"', false)
            ->assertSee('Entregar material a centro de costo')
            ->assertSee('Confirmar despacho directo');

        $office = file_get_contents(resource_path('js/office-materials.js'));
        $operationalScreen = file_get_contents(
            base_path('mobile/src/screens/OperationalScreen.tsx'),
        );
        $operationModals = file_get_contents(
            base_path('mobile/src/components/OperationModals.tsx'),
        );

        $this->assertIsString($office);
        $this->assertStringContainsString(
            '/api/materiales/despachos/directos',
            $office,
        );
        $this->assertStringContainsString('data-direct-dispatch', $office);
        $this->assertStringContainsString('Boolean(folio.camara)', $office);
        $this->assertIsString($operationalScreen);
        $this->assertStringNotContainsString(
            'materialCreateOperationId',
            $operationalScreen,
        );
        $this->assertStringNotContainsString(
            'api.createMaterialDispatch',
            $operationalScreen,
        );
        $this->assertIsString($operationModals);
        $this->assertStringNotContainsString('Nuevo despacho', $operationModals);
        $this->assertStringContainsString(
            'Orden de despacho asignada',
            $operationModals,
        );
    }

    public function test_consumo_directo_de_insumos_se_opera_desde_inventario_bc(): void
    {
        $this->get('/oficina/materiales/inventario')
            ->assertOk()
            ->assertSee('id="materialDirectConsumptionDialog"', false)
            ->assertSee('CONSUMO DIRECTO · BODEGA CENTRAL')
            ->assertSee('Centro de costo que consume')
            ->assertSee('No crea existencia temporal');

        $office = file_get_contents(resource_path('js/office-materials.js'));

        $this->assertIsString($office);
        $this->assertStringContainsString('data-direct-consumption', $office);
        $this->assertStringContainsString("folio.categoria_operacional === 'insumo'", $office);
        $this->assertStringContainsString("folio.almacen?.codigo === 'BOD-CENTRAL'", $office);
        $this->assertStringContainsString('centro_costo_id', $office);
        $this->assertStringContainsString(
            '/api/materiales/almacenes/movimientos',
            $office,
        );
        $this->assertStringContainsString('window.confirm(', $office);
    }

    public function test_ordenes_expone_cancelacion_segura_durante_la_transformacion(): void
    {
        $this->get('/oficina/materiales/ordenes')
            ->assertOk()
            ->assertSee('Órdenes de transformación');

        $script = file_get_contents(resource_path('js/office-material-orders.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString(
            "(order.estado === 'en_proceso' && !hasOutputs)",
            $script,
        );
        $this->assertStringContainsString(
            'Se descartará cualquier lote abierto',
            $script,
        );
        $this->assertStringContainsString(
            '/api/materiales/transformaciones/ordenes/${order.id}/cancelar',
            $script,
        );
    }

    public function test_materiales_solo_actualiza_datos_de_la_seccion_visible(): void
    {
        $this->get('/oficina/materiales')
            ->assertOk()
            ->assertSee('class="materials-metrics" data-materials-view="resumen"', false);

        $office = file_get_contents(resource_path('js/office-materials.js'));
        $mobilePolling = file_get_contents(base_path('mobile/src/config/polling.ts'));
        $operationalPolling = file_get_contents(base_path('mobile/src/hooks/useOperationalPolling.ts'));
        $operationalScreen = file_get_contents(base_path('mobile/src/screens/OperationalScreen.tsx'));
        $materialDispatchOperation = file_get_contents(
            base_path('mobile/src/components/MaterialDispatchOperation.tsx'),
        );

        $this->assertIsString($office);
        $this->assertStringContainsString(
            "new Set(['resumen', 'catalogos', 'inventario', 'despachos', 'vencimientos'])",
            $office,
        );
        $this->assertStringContainsString(
            "new Set(['resumen', 'inventario', 'despachos'])",
            $office,
        );
        $this->assertStringContainsString('operationalRefreshIntervalMs = 30000', $office);
        $this->assertStringContainsString('if (!mainDataSections.has(section)) return;', $office);
        $this->assertStringContainsString(
            "materialDispatchSummaryPath = '/api/materiales/despachos?vista=resumen'",
            $office,
        );
        $this->assertStringContainsString(
            "materialInventorySummaryPath = '/api/materiales/inventario?vista=resumen'",
            $office,
        );
        $this->assertStringContainsString('loadInventoryPage(state.inventoryCurrentPage + 1)', $office);

        $this->assertIsString($mobilePolling);
        $this->assertStringContainsString('OPERATIONAL_POLL_INTERVAL_MS = 30_000', $mobilePolling);
        $this->assertIsString($operationalPolling);
        $this->assertStringContainsString('AppState.currentState', $operationalPolling);
        $this->assertIsString($operationalScreen);
        $this->assertStringContainsString('useOperationalPolling(', $operationalScreen);
        $this->assertIsString($materialDispatchOperation);
        $this->assertStringContainsString(
            'api.listMaterialDispatchSummaries(auth.token, ALL_STATES)',
            $materialDispatchOperation,
        );
        $this->assertStringContainsString(
            'api.getMaterialDispatch(auth.token, selectedSummary.id)',
            $materialDispatchOperation,
        );
    }
}
