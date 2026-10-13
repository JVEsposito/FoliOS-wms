<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOrdenesTransformacionMaterialesTest extends TestCase
{
    public function test_oficina_materiales_integra_la_gestion_de_ordenes_de_transformacion(): void
    {
        $this->get('/oficina/materiales')->assertOk();

        $vista = file_get_contents(resource_path('views/office/materials.blade.php'));
        $script = file_get_contents(resource_path('js/office-material-orders.js'));

        $this->assertIsString($vista);
        $this->assertIsString($script);
        $this->assertStringContainsString('resources/js/office-material-orders.js', $vista);
        $this->assertStringContainsString('Órdenes de transformación', $script);
        $this->assertStringContainsString('puede_consultar_transformaciones_materiales', $script);
        $this->assertStringContainsString('puede_gestionar_transformaciones_materiales', $script);
        $this->assertStringContainsString("section.dataset.materialsView = 'ordenes'", $script);
        $this->assertStringContainsString('orderSectionIsActive()', $script);
        $this->assertStringContainsString(
            '/api/materiales/transformaciones/ordenes?per_page=100',
            $script,
        );
        $this->assertStringContainsString(
            '/api/materiales/transformaciones/recetas?per_page=100',
            $script,
        );
        $this->assertStringContainsString(
            '/api/materiales/inventario?vista=resumen',
            $script,
        );
        $this->assertStringContainsString(
            'orderState.inventory = inventory.resumen_items || []',
            $script,
        );
        $this->assertStringContainsString(
            'folio.categoria_operacional === operationalCategory',
            $script,
        );
        $this->assertStringContainsString('/planificar', $script);
        $this->assertStringContainsString('/cancelar', $script);
        $this->assertStringContainsString(
            '/api/materiales/transformaciones/ordenes/${encodeURIComponent(order.id)}',
            $script,
        );
        $this->assertStringContainsString('data-load-material-order-detail', $script);
        $this->assertStringContainsString('order.reservas_count', $script);
        $this->assertStringContainsString('order.lotes_count', $script);
        $this->assertStringContainsString('version_conocida', $script);
        $this->assertStringContainsString('Planificar y reservar FIFO', $script);
        $this->assertStringContainsString('Lista para iniciar desde la PDA/tablet', $script);
        $this->assertStringContainsString('estiba:materials-updated', $script);
        $this->assertStringContainsString('Temporada histórica', $script);
    }

    public function test_las_ordenes_muestran_e_imprimen_los_folios_de_salida_reservados(): void
    {
        $ordenes = file_get_contents(resource_path('js/office-material-orders.js'));
        $etiquetas = file_get_contents(resource_path('js/office-material-labels.js'));

        $this->assertIsString($ordenes);
        $this->assertIsString($etiquetas);
        $this->assertStringContainsString('order.folios_salida_resumen', $ordenes);
        $this->assertStringContainsString('Folios de salida reservados', $ordenes);
        $this->assertStringContainsString('Imprimir folios reservados', $ordenes);
        $this->assertStringContainsString('reimprime su etiqueta', $ordenes);
        $this->assertStringContainsString('order.folios_salida_resumen?.reservados || 0) > 0', $etiquetas);
        $this->assertStringContainsString("reservation.estado === 'reservado'", $etiquetas);
        $this->assertStringContainsString('reserva_ids: reservationIds', $etiquetas);
        $this->assertStringContainsString('reimprimir: cambió la cantidad', $etiquetas);
    }

    public function test_las_ordenes_en_cuadratura_muestran_lo_retirado_la_merma_y_lo_sin_armar(): void
    {
        $ordenes = file_get_contents(resource_path('js/office-material-orders.js'));

        $this->assertIsString($ordenes);
        $this->assertStringContainsString("order.modo_operacion === 'cuadratura_cierre'", $ordenes);
        $this->assertStringContainsString('Cuadratura de cierre', $ordenes);
        $this->assertStringContainsString('<th>Sin armar</th>', $ordenes);
        $this->assertStringContainsString('row.cantidad_retirada', $ordenes);
    }
}
