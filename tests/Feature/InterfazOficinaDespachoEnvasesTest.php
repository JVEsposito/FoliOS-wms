<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaDespachoEnvasesTest extends TestCase
{
    public function test_muestra_reservas_confirmacion_y_respaldos_del_despacho_de_envases(): void
    {
        $this->get('/oficina/envases/despachos')
            ->assertOk()
            ->assertSee('El borrador reserva disponibilidad')
            ->assertSee('RESERVADO EN BORRADORES')
            ->assertSee('Crear borrador y reservar')
            ->assertSee('HISTORIAL Y RESPALDOS')
            ->assertSee('Fecha y hora efectiva de salida')
            ->assertSee('id="guideDialog"', false)
            ->assertSee('N° de la guía de despacho emitida *')
            ->assertSee('accept="application/pdf,image/jpeg,image/png,image/webp"', false);

        $script = file_get_contents(resource_path('js/office-container-dispatches.js'));
        $this->assertStringContainsString('numero_guia_despacho: number', $script);
        $this->assertStringContainsString('/archivo-guia', $script);
        $this->assertStringContainsString('/numero-guia', $script);
    }

    public function test_cuenta_corriente_distingue_reservas_de_movimientos_confirmados(): void
    {
        $this->get('/oficina/envases/cuenta-corriente')
            ->assertOk()
            ->assertSee('ENVASES RESERVADOS')
            ->assertSee('Borradores de despacho')
            ->assertSee('No modifican el saldo hasta confirmar la salida.');
    }

    public function test_cuenta_corriente_muestra_existencia_propia_y_ofrece_reportes(): void
    {
        $this->get('/oficina/envases/cuenta-corriente')
            ->assertOk()
            ->assertSee('id="container-accounts-panel-stock"', false)
            ->assertSee('Envases en planta por propiedad')
            ->assertSee('Envases propios entregados a clientes')
            ->assertSee('La compra de envases propios no mueve la cuenta de ningún cliente')
            ->assertSee('El estado de cuenta toma la temporada y las fechas del filtro.')
            ->assertSee('id="movementsExcelButton"', false)
            ->assertSeeInOrder(['<th>Cuenta</th>', '<th>Existencia</th>'], false);

        $script = file_get_contents(resource_path('js/office-container-accounts.js'));
        $this->assertStringContainsString('/api/envases/cuenta-corriente/estado-cuenta?', $script);
        $this->assertStringContainsString('/api/envases/cuenta-corriente/movimientos/excel?', $script);
        $this->assertStringContainsString('data-office-action-menu', $script);
    }
}
