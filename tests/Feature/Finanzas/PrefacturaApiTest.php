<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class PrefacturaApiTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private string $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Administración Demo']);
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
        $this->cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        app(GestorTarifas::class)->crear([
            'cliente_id' => null, 'servicio' => 'romana_recepcion', 'unidad' => 'kilos_netos', 'moneda' => 'CLP',
            'precio' => '10', 'vigente_desde' => '2026-11-01',
        ], $administrador);
        $this->recepcionRomana($this->cliente, 'R-7001', 8000, '2026-11-03 09:00');
        $this->recepcionRomana($this->cliente, 'R-7002', 1500.5, '2026-11-12 09:00');
        $this->capturar();
        $this->actingAs($administrador, 'sanctum');
    }

    public function test_la_prefactura_la_ve_quien_consulta_finanzas(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum')
            ->getJson('/api/finanzas/prefactura?mes=2026-11')->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]), 'sanctum')
            ->getJson('/api/finanzas/prefactura?mes=2026-11')
            ->assertOk()
            ->assertJsonPath('totales.0.monto', '95005');
    }

    public function test_el_mes_por_cliente_con_su_detalle(): void
    {
        $this->getJson('/api/finanzas/prefactura?mes=2026-11')
            ->assertOk()
            ->assertJsonPath('periodo.mes', '2026-11')
            ->assertJsonPath('corte', '2026-11-30')
            ->assertJsonPath('a_la_fecha', false)
            ->assertJsonPath('clientes.0.cliente_nombre', 'PRODUCTOR DE PRUEBA SPA')
            ->assertJsonPath('clientes.0.lineas.0.unidad_nombre', 'por kilo neto')
            ->assertJsonPath('clientes.0.lineas.0.origen', 'general')
            ->assertJsonPath('clientes.0.lineas.0.cantidad', '9500.5')
            ->assertJsonPath('clientes.0.lineas.0.hechos', 2)
            ->assertJsonPath('clientes.0.totales.0.monto', '95005')
            ->assertJsonPath('resumen.hechos_valorizados', 2)
            ->assertJsonPath('ultima_captura.estado', 'completada')
            ->assertJsonMissingPath('clientes.0.lineas.0.detalle');

        $detalle = $this->getJson("/api/finanzas/prefactura/detalle?mes=2026-11&cliente={$this->cliente}")
            ->assertOk()
            ->assertJsonPath('data.cliente_id', $this->cliente)
            ->json('data.lineas.0.detalle');
        $this->assertSame(['R-7001', 'R-7002'], array_column($detalle, 'referencia'));
        $this->assertSame(['8000', '1500.5'], array_column($detalle, 'cantidad'));

        // Con la línea indicada, solo viaja su detalle.
        $tarifa = $this->getJson('/api/finanzas/prefactura?mes=2026-11')->json('clientes.0.lineas.0.tarifa_id');
        $this->getJson("/api/finanzas/prefactura/detalle?mes=2026-11&cliente={$this->cliente}&tarifa={$tarifa}")
            ->assertOk()
            ->assertJsonCount(2, 'data.lineas.0.detalle');
        $this->getJson("/api/finanzas/prefactura/detalle?mes=2026-11&cliente={$this->cliente}&tarifa=999999")
            ->assertOk()
            ->assertJsonCount(0, 'data.lineas.0.detalle');

        $this->getJson('/api/finanzas/prefactura?mes=noviembre')
            ->assertUnprocessable()
            ->assertJsonPath('errors.mes.0', 'El mes debe tener el formato AAAA-MM.');
        $this->getJson('/api/finanzas/prefactura/detalle?mes=2026-11')
            ->assertUnprocessable()
            ->assertJsonPath('errors.cliente.0', 'Indique el cliente.');
    }

    public function test_descarga_el_mes_en_excel(): void
    {
        $respuesta = $this->get('/api/finanzas/prefactura/exportar?mes=2026-11')->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $respuesta->baseResponse);
        $this->assertStringContainsString('prefactura-2026-11.xlsx', (string) $respuesta->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $corte = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        $this->assertStringContainsString('PRODUCTOR DE PRUEBA SPA', $hoja.$corte);
        $this->assertStringContainsString('por kilo neto', $hoja.$corte);
        $this->assertStringContainsString('valorizado hasta el 30-11-2026 (mes completo)', $hoja.$corte);
    }

    public function test_la_oficina_de_prefactura_esta_en_finanzas(): void
    {
        $html = $this->get('/oficina/finanzas/prefactura')->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Prefactura</h1>', $html);
        $this->assertStringContainsString('data-office-key="finanzas-prefactura"', $html);
        $this->assertStringContainsString('href="/oficina/finanzas/tarifario"', $html);
    }
}
