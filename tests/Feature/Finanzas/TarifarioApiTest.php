<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TarifarioApiTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private const PESO = ['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
    }

    public function test_administracion_edita_el_tarifario_y_un_perfil_de_consulta_solo_lo_ve(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum')
            ->getJson('/api/finanzas/tarifas')->assertForbidden();

        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/finanzas/tarifas')
            ->assertOk()
            ->assertJsonPath('puede_gestionar', false);
        $this->postJson('/api/finanzas/monedas', self::PESO)->assertForbidden();
        $this->postJson('/api/finanzas/tarifas', ['servicio' => 'prefrio'])->assertForbidden();

        $this->administrador();
        $this->getJson('/api/finanzas/tarifas')
            ->assertOk()
            ->assertJsonPath('puede_gestionar', true)
            ->assertJsonPath('servicios.4.clave', 'estadia')
            ->assertJsonPath('servicios.4.unidades.3.nombre', 'por caja y día');
        $this->postJson('/api/finanzas/monedas', self::PESO)->assertCreated();
    }

    public function test_monedas_configurables(): void
    {
        $this->administrador();

        $this->postJson('/api/finanzas/monedas', [...self::PESO, 'codigo' => ' clp '])
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'CLP')
            ->assertJsonPath('data.activa', true);
        $this->postJson('/api/finanzas/monedas', self::PESO)
            ->assertUnprocessable()
            ->assertJsonPath('errors.codigo.0', 'Ya existe una moneda con ese código.');
        $this->postJson('/api/finanzas/monedas', ['codigo' => 'U F', 'nombre' => 'Unidad de fomento', 'simbolo' => 'UF', 'decimales' => 9])
            ->assertUnprocessable()
            ->assertJsonPath('errors.codigo.0', 'El código debe tener de 2 a 10 letras o números, sin espacios (por ejemplo, CLP, UF o USD).')
            ->assertJsonPath('errors.decimales.0', 'Los decimales deben estar entre 0 y 6.');
        $this->postJson('/api/finanzas/monedas', ['codigo' => 'UF'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.nombre.0', 'Indique el nombre.');

        $this->putJson('/api/finanzas/monedas/clp', ['nombre' => 'Peso chileno', 'simbolo' => 'CLP$', 'decimales' => 0, 'activa' => false])
            ->assertOk()
            ->assertJsonPath('data.simbolo', 'CLP$')
            ->assertJsonPath('data.activa', false);
        $this->getJson('/api/finanzas/tarifas')->assertJsonPath('monedas.0.codigo', 'CLP');
    }

    public function test_precios_por_cliente_con_filtros_tarifa_efectiva_e_historial(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00', 'America/Santiago'));
        $this->administrador();
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO SPA');
        $this->postJson('/api/finanzas/monedas', self::PESO)->assertCreated();

        $this->postJson('/api/finanzas/tarifas', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.servicio.0', 'Indique el servicio.')
            ->assertJsonPath('errors.precio.0', 'Indique el precio.');
        $general = ['servicio' => 'estadia', 'unidad' => 'bultos', 'moneda' => 'clp', 'vigente_desde' => '2026-11-01'];
        $this->postJson('/api/finanzas/tarifas', [...$general, 'precio' => '1.234,5'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.precio.0', 'El precio debe ser un número positivo, con hasta 6 decimales y sin separador de miles.');
        $this->postJson('/api/finanzas/tarifas', [...$general, 'precio' => '1300', 'vigente_hasta' => '2026-10-31'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.vigente_hasta.0', 'El término no puede ser anterior al inicio.');
        $this->postJson('/api/finanzas/tarifas', [...$general, 'precio' => '1234,5'])
            ->assertCreated()
            ->assertJsonPath('data.general', true)
            ->assertJsonPath('data.cliente_nombre', 'Tarifa general')
            ->assertJsonPath('data.unidad_nombre', 'por bulto y día')
            ->assertJsonPath('data.precio', '1234.5')
            ->assertJsonPath('data.moneda', 'CLP')
            ->assertJsonPath('data.moneda_simbolo', '$')
            ->assertJsonPath('data.estado', 'vigente');
        $propio = $this->postJson('/api/finanzas/tarifas', [
            'cliente_id' => $cliente, 'servicio' => 'estadia', 'unidad' => 'cajas', 'moneda' => 'CLP',
            'precio' => '12', 'vigente_desde' => '2026-11-15', 'nota' => 'Contrato de temporada',
        ])->assertCreated()->assertJsonPath('data.cliente_nombre', 'EXPORTADORA DEMO SPA')->json('data.id');
        $this->postJson('/api/finanzas/tarifas', ['servicio' => 'prefrio', 'unidad' => 'cajas', 'moneda' => 'CLP', 'precio' => '30', 'vigente_desde' => '2026-12-01'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'futura');

        // Tarifa general primero; filtros por cliente, servicio y estado.
        $this->getJson('/api/finanzas/tarifas')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.general', true)
            ->assertJsonPath('data.0.servicio', 'prefrio')
            ->assertJsonPath('data.2.cliente_id', $cliente)
            ->assertJsonPath('resumen', ['vigentes' => 2, 'futuras' => 1, 'clientes_con_precio_propio' => 1, 'servicios_en_tarifa_general' => 2]);
        $this->getJson("/api/finanzas/tarifas?cliente={$cliente}")->assertJsonCount(1, 'data');
        $this->getJson('/api/finanzas/tarifas?cliente=general&servicio=estadia')->assertJsonCount(1, 'data');
        $this->getJson('/api/finanzas/tarifas?estado=futura')->assertJsonCount(1, 'data')->assertJsonPath('data.0.servicio', 'prefrio');
        $this->getJson('/api/finanzas/tarifas?estado=caducadas')
            ->assertUnprocessable()
            ->assertJsonPath('errors.estado.0', 'El estado elegido no existe.');
        $clientes = collect($this->getJson('/api/finanzas/tarifas')->json('clientes'))->keyBy('id');
        $this->assertTrue($clientes[$cliente]['con_precio']);

        // Tarifa efectiva: el cliente paga su almacenaje por caja; lo demás, con la tarifa general.
        $efectiva = collect($this->getJson("/api/finanzas/tarifas/efectiva?cliente={$cliente}")
            ->assertOk()
            ->assertJsonPath('fecha', '2026-11-20')
            ->assertJsonPath('cliente.nombre', 'EXPORTADORA DEMO SPA')
            ->json('servicios'))->keyBy('servicio');
        $this->assertSame('propio', $efectiva['estadia']['origen']);
        $this->assertSame(['cajas'], array_column($efectiva['estadia']['precios'], 'unidad'));
        $this->assertNull($efectiva['prefrio']['origen'], 'El prefrío general rige desde diciembre.');
        $this->assertSame('general', collect($this->getJson("/api/finanzas/tarifas/efectiva?cliente={$cliente}&fecha=2026-12-01")->json('servicios'))->firstWhere('servicio', 'prefrio')['origen']);
        $this->assertSame('general', collect($this->getJson("/api/finanzas/tarifas/efectiva?cliente={$cliente}&fecha=2026-11-14")->json('servicios'))->firstWhere('servicio', 'estadia')['origen']);

        // Editar y anular quedan en el historial con el usuario.
        $edicion = ['moneda' => 'CLP', 'precio' => '13', 'vigente_desde' => '2026-11-15', 'nota' => 'Contrato de temporada'];
        $this->putJson("/api/finanzas/tarifas/{$propio}", $edicion)->assertOk()->assertJsonPath('data.precio', '13');
        $this->postJson("/api/finanzas/tarifas/{$propio}/anular", ['motivo' => 'no'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motivo.0', 'El motivo debe tener al menos 5 caracteres.');
        $this->postJson("/api/finanzas/tarifas/{$propio}/anular", ['motivo' => 'El contrato no se firmó.'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulada');
        $this->getJson("/api/finanzas/tarifas/{$propio}")
            ->assertOk()
            ->assertJsonPath('eventos.0.accion', 'anulada')
            ->assertJsonPath('eventos.0.motivo', 'El contrato no se firmó.')
            ->assertJsonPath('eventos.0.usuario', 'Administración Demo')
            ->assertJsonPath('eventos.1.cambios.precio', ['antes' => '12', 'despues' => '13'])
            ->assertJsonPath('eventos.2.accion', 'creada');
        $this->putJson("/api/finanzas/tarifas/{$propio}", $edicion)
            ->assertUnprocessable()
            ->assertJsonPath('errors.tarifa.0', 'Un precio anulado no se puede editar: cree uno nuevo.');
        $this->getJson('/api/finanzas/tarifas')->assertJsonCount(2, 'data');
        $this->getJson('/api/finanzas/tarifas?estado=anulada')->assertJsonCount(1, 'data');
    }

    public function test_la_oficina_de_tarifario_esta_en_finanzas_junto_a_hechos(): void
    {
        $html = $this->get('/oficina/finanzas/tarifario')->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Tarifario</h1>', $html);
        $this->assertStringContainsString('data-office-key="finanzas-tarifario"', $html);
        $this->assertStringContainsString('href="/oficina/finanzas/hechos"', $html);
        $this->assertMatchesRegularExpression('/<p data-navigation-permissions="puede_consultar_finanzas,puede_consultar_proveedores_finanzas,puede_consultar_costos_comex"\s+data-navigation-module=""\s+class="estiba-office-subgroup">Finanzas<\/p>/', $html);
    }

    private function administrador(): User
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Administración Demo']);
        $this->actingAs($administrador, 'sanctum');

        return $administrador;
    }
}
