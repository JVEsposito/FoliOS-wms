<?php

namespace Tests\Feature;

use Tests\TestCase;

class InterfazOficinaAccesosTest extends TestCase
{
    public function test_la_oficina_de_accesos_se_encuentra_disponible(): void
    {
        $this->get('/oficina/accesos')
            ->assertOk()
            ->assertSee('Administración de accesos')
            ->assertSee('Accesos, temporada y clientes')
            ->assertSee('Ciclo operacional compartido')
            ->assertSee('Clientes de servicio')
            ->assertSee('Formatos de registro')
            ->assertSee('recordFormatForm', false)
            ->assertSee('recordFormatsTableBody', false)
            ->assertSee('Mínimo 10 caracteres; debe contener al menos una letra y un número.')
            ->assertSee('sus sesiones se cierran y su trazabilidad se conserva.')
            ->assertSee('seasonForm', false)
            ->assertSee('seasonMigrationForm', false)
            ->assertSee('Migrar inventario vivo de Bodega')
            ->assertSee('seasonsTableBody', false)
            ->assertSee('createUserForm', false)
            ->assertSee('Perfiles de acceso')
            ->assertSee('accessProfileForm', false)
            ->assertSee('accessModuleSelector', false)
            ->assertSee('accessTabletModuleSelector', false)
            ->assertSee('PDA / TABLET')
            ->assertSee('Módulos operacionales móviles')
            ->assertSee('Configura por separado las oficinas PC y los módulos PDA/tablet.')
            ->assertSee('Accesos y temporadas')
            ->assertSee('Perfil de acceso')
            ->assertSee('createDeviceForm', false)
            ->assertSee('data-active-domain="administracion"', false)
            ->assertSee('/oficina/administracion/camaras', false)
            ->assertDontSee('data-office-key="cargas"', false)
            ->assertSee('aria-live="assertive"', false);
    }
}
