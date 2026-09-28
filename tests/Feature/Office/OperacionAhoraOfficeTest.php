<?php

namespace Tests\Feature\Office;

use Tests\TestCase;

class OperacionAhoraOfficeTest extends TestCase
{
    public function test_publica_el_centro_de_control_con_editor_administrativo_del_plano(): void
    {
        $this->get('/oficina/operacion-ahora')
            ->assertOk()
            ->assertSee('Operación ahora')
            ->assertSee('CENTRO DE CONTROL · INFORMACIÓN EN VIVO')
            ->assertSee('Puesto de mando del planificador')
            ->assertSee('Qué ordena el planificador y qué ocurre en planta')
            ->assertDontSee('Fuera rollout')
            ->assertSee('id="plannerTechnical"', false)
            ->assertSee('id="operationPlannerPanel"', false)
            ->assertSee('id="plannerFreshnessSignal"', false)
            ->assertSee('id="plannerDecisionRows"', false)
            ->assertSee('id="plannerRiskRows"', false)
            ->assertSee('id="plannerComparisonOpen"', false)
            ->assertSee('id="operationCycleComparisonDialog"', false)
            ->assertSee('id="operationCycleComparisonContent"', false)
            ->assertSee('Qué cambió y por qué respecto de la evaluación anterior')
            ->assertSee('id="operationSupervisionDialog"', false)
            ->assertSee('id="operationSupervisionContent"', false)
            ->assertSee('id="operationSupervisionClose"', false)
            ->assertSee('Explicación, evidencia física e intervenciones autorizadas')
            ->assertSee('<th scope="col">Motivo</th>', false)
            ->assertSee('Vista de planta')
            ->assertSee('data-legend="repa"', false)
            ->assertSee('<option value="recepcion_mp">', false)
            ->assertSee('Editar plano')
            ->assertSee('Plano físico y estado en vivo de la planta')
            ->assertSee('operationMapDialog', false)
            ->assertSee('operationEditorStage', false)
            ->assertSee('Camareros activos')
            ->assertSee('Prefrío')
            ->assertSee('Estado, progreso y producto')
            ->assertSee('<th scope="col">Estado</th>', false)
            ->assertSee('<th scope="col">Progreso</th>', false)
            ->assertSee('<th scope="col">Producto</th>', false)
            ->assertSee('Incidencias abiertas')
            ->assertSee('Alertas operacionales')
            ->assertSee('ACCESOS RÁPIDOS')
            ->assertSee('id="operationCameraRows"', false)
            ->assertSee('id="operationOperatorList"', false)
            ->assertSee('id="operationTunnelList"', false)
            ->assertSee('id="operationIncidentRows"', false)
            ->assertSee('id="operationFacilityMap"', false)
            ->assertSee('id="operationAlertRows"', false)
            ->assertSee('class="operation-now-syncbar"', false)
            ->assertSee('class="operation-now-syncbar__metrics"', false)
            ->assertSee('data-active-office="operacion-ahora"', false)
            ->assertDontSee('class="operation-now-metrics"', false)
            ->assertSee('id="operationMapZoneForm"', false);
    }

    public function test_navegacion_y_resumen_exponen_operacion_ahora_con_permiso_gerencial(): void
    {
        $this->get('/oficina/operacion-ahora')
            ->assertOk()
            ->assertSee('data-navigation-module="gerencia.panel"', false)
            ->assertSee('data-navigation-permissions="puede_consultar_panel_gerencial"', false);

        $this->get('/oficina/administracion')
            ->assertOk()
            ->assertSee('href="/oficina/operacion-ahora"', false)
            ->assertSee('TIEMPO REAL');
    }

    public function test_cliente_consume_el_contrato_actual_y_coordina_refrescos(): void
    {
        $script = file_get_contents(resource_path('js/office-operation-now.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("api('/api/operacion-ahora', { cache: 'no-store' })", $script);
        $this->assertStringContainsString('createOperationalPoller', $script);
        $this->assertStringContainsString('actualizacion_sugerida_segundos', $script);
        $this->assertStringContainsString('validateSnapshot', $script);
        $this->assertStringContainsString('renderPlanner', $script);
        $this->assertStringContainsString('data.planificador?.salud?.riesgos', $script);
        $this->assertStringContainsString('data.planificador?.arbitraje?.vigencia', $script);
        $this->assertStringContainsString('Cálculo ${plannerFreshnessLabel', $script);
        $this->assertStringNotContainsString('WMS_PLANNER_MODE', $script);
        $this->assertStringContainsString("state.identity?.rol !== 'administrador'", $script);
        $this->assertStringContainsString('Planificador detenido por configuración', $script);
        $this->assertStringContainsString('plannerConflictDetail', $script);
        $this->assertStringContainsString('createManeuverSupervisionDrawer', $script);
        $this->assertStringContainsString('buildManeuverInterventionRequest', $script);
        $this->assertStringContainsString('buildCycleComparison', $script);
        $this->assertStringContainsString('renderCycleComparison', $script);
        $this->assertStringContainsString("api('/api/operacion-ahora/planificador/comparacion'", $script);
        $this->assertStringContainsString('buildCycleReplay', $script);
        $this->assertStringContainsString('renderCycleReplay', $script);
        $this->assertStringContainsString('/api/operacion-ahora/planificador/replay?ciclo=', $script);
        $this->assertStringContainsString('executeManeuverIntervention', $script);
        $this->assertStringContainsString("can('puede_supervisar')", $script);
        $this->assertStringContainsString('data-maneuver-detail', $script);
        $this->assertStringContainsString('operationLocationLabel', $script);
        $this->assertStringContainsString('SIN REGISTRO', $script);
        $this->assertStringContainsString('renderFacility', $script);
        $this->assertStringContainsString("from './shared/plant-view'", $script);
        $this->assertStringContainsString('plantNodeModel(item, index)', $script);
        $this->assertStringContainsString('plantNodeLabel(model)', $script);
        $this->assertStringContainsString("api('/api/administracion/operacion-ahora/plano'", $script);
        $this->assertStringContainsString('resizeElement', $script);
        $this->assertStringContainsString('reconcilePlantSnapshot', $script);
        $this->assertStringContainsString("cache: 'no-store'", $script);
        $this->assertStringContainsString('!state.mapSaving', $script);
        $this->assertStringContainsString('buildOperationalAlerts', $script);
        $this->assertStringContainsString('proceso_activo', $script);
        $this->assertStringContainsString('process?.productos', $script);
        $this->assertStringContainsString('tunnelProgress', $script);
        $this->assertStringContainsString('pauseWhenHidden', file_get_contents(resource_path('js/shared/operational-poller.js')));
        $drawer = file_get_contents(resource_path('js/shared/operation-supervision-drawer.js'));
        $this->assertIsString($drawer);
        $this->assertStringContainsString('/api/intervenciones-planificador/maniobras/', $drawer);
        $this->assertStringContainsString("method: 'POST'", $drawer);
        $this->assertStringContainsString("method: 'PATCH'", $drawer);
        $this->assertStringContainsString('Por qué se tomó esta decisión', $drawer);
        $this->assertStringContainsString('factor_decisivo', $drawer);
        $this->assertStringContainsString('Evidencia conservada del ciclo', $drawer);
        $comparison = file_get_contents(resource_path('js/shared/operation-cycle-comparison.js'));
        $this->assertIsString($comparison);
        $this->assertStringContainsString('Diferencias verificadas', $comparison);
        $this->assertStringContainsString('Sin cambios operacionales', $comparison);
        $replay = file_get_contents(resource_path('js/shared/operation-cycle-replay.js'));
        $this->assertIsString($replay);
        $this->assertStringContainsString('Replay aislado', $replay);
        $this->assertStringContainsString('Verificación determinista', $replay);
        $this->assertStringContainsString("method: 'PUT'", $script);
    }

    public function test_conserva_densidad_operacional_y_respuesta_tactil_sin_decoracion_generica(): void
    {
        $styles = file_get_contents(resource_path('css/office-operation-now.css'));

        $this->assertIsString($styles);
        $this->assertStringContainsString('overflow-x: auto', $styles);
        $this->assertStringContainsString('@media (pointer: coarse)', $styles);
        $this->assertStringContainsString('--eui-density-touch-control', $styles);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $styles);
        $this->assertStringContainsString('background: #17394b', $styles);
        $this->assertStringContainsString('operation-now-facility__grid', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(12, minmax(0, 1fr))', $styles);
        $this->assertStringContainsString('max-height: 196px', $styles);
        $this->assertStringContainsString('repeating-linear-gradient', $styles);
        $this->assertStringContainsString('color: var(--plant-ink) !important', $styles);
        $this->assertStringContainsString('color: var(--plant-muted) !important', $styles);
        $this->assertStringContainsString('container-type: size', $styles);
        $this->assertStringContainsString('@container plant-node', $styles);
        $this->assertStringContainsString(':root[data-office-theme="dark-industrial"] :is(.plant-view, .operation-map-dialog)', $styles);
        $this->assertStringContainsString('.operation-now-panel--facility { grid-column: 1 / -1; grid-row: 2; }', $styles);
        $this->assertStringContainsString('operation-now-panel--planner', $styles);
        $this->assertStringContainsString('operation-now-table--planner', $styles);
        $this->assertStringContainsString('operation-supervision__surface', $styles);
        $this->assertStringContainsString('operation-supervision__steps', $styles);
        $this->assertStringContainsString('operation-supervision__explanation', $styles);
        $this->assertStringContainsString('operation-supervision__calculation', $styles);
        $this->assertStringContainsString('operation-supervision__evidence', $styles);
        $this->assertStringContainsString('operation-supervision__actions', $styles);
        $this->assertStringContainsString('operation-supervision__confirm', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(7, minmax(132px, 1fr))', $styles);
        $this->assertStringNotContainsString('radial-gradient', $styles);
    }

    public function test_shell_conserva_funciones_y_usa_una_apariencia_operacional(): void
    {
        $view = file_get_contents(resource_path('views/components/office/navigation.blade.php'));
        $styles = file_get_contents(resource_path('css/office-shell.css'));

        $this->assertIsString($view);
        $this->assertIsString($styles);
        $this->assertStringContainsString('SISTEMA DE GESTIÓN', $view);
        $this->assertStringContainsString('estiba-office-system', $view);
        $this->assertStringContainsString('aria-label="Operación en tiempo real"', $view);
        $this->assertStringContainsString('class="estiba-office-status"', $view);
        $this->assertStringContainsString('data-office-context-status', $view);
        $this->assertStringContainsString('data-office-context-refresh', $view);
        $this->assertStringContainsString('FRÍO QUE', $view);
        $this->assertStringContainsString('--eui-shell-frost: #0f5c6e', $styles);
        $this->assertStringContainsString('background: var(--eui-shell-frost)', $styles);
        $this->assertStringNotContainsString('officeThemeSelector', $view);
    }
}
