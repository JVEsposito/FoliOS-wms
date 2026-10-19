<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\Moneda;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class CierrePrefacturaApiTest extends TestCase
{
    use HechosDePrueba;
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private string $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Administración Demo']);
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
        $this->cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        $this->precio(null, 'romana_recepcion', 'kilos_netos', '10', '2026-09-01');
        $this->recepcion('2026-11-03 09:00', 8000, 'R-7001');
        $this->actingAs($this->administrador, 'sanctum');
    }

    public function test_cerrar_y_reabrir_con_sus_permisos(): void
    {
        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/finanzas/prefactura?mes=2026-11')
            ->assertOk()
            ->assertJsonPath('estado', 'abierto')
            ->assertJsonPath('para_cerrar.posible', true)
            ->assertJsonPath('permisos', ['cerrar' => false, 'reabrir' => false]);
        $this->actingAs($consulta, 'sanctum')->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])->assertForbidden();

        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/finanzas/prefactura/cierres', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.mes.0', 'Indique el mes.');
        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])
            ->assertCreated()
            ->assertJsonPath('estado', 'cerrado')
            ->assertJsonPath('cierre.version', 1)
            ->assertJsonPath('cierre.cerrado_por', 'Administración Demo')
            ->assertJsonPath('totales.0.monto', '80000')
            ->assertJsonPath('para_reabrir.posible', true)
            ->assertJsonPath('permisos', ['cerrar' => true, 'reabrir' => true]);
        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.mes.0', 'El mes ya está cerrado.');

        // Un perfil con el módulo cierra, pero reabrir es solo de un Administrador.
        $finanzas = $this->conPerfil(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($finanzas, 'sanctum')->getJson('/api/finanzas/prefactura?mes=2026-11')
            ->assertJsonPath('permisos', ['cerrar' => true, 'reabrir' => false]);
        $this->actingAs($finanzas, 'sanctum')->postJson('/api/finanzas/prefactura/cierres/2026-11/reabrir', ['motivo' => 'Faltaba un hecho.'])->assertForbidden();

        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/finanzas/prefactura/cierres/2026-11/reabrir', [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motivo.0', 'Indique por qué se reabre el mes.');
        $this->postJson('/api/finanzas/prefactura/cierres/2026-11/reabrir', ['motivo' => 'Faltaba la recepción del día 30.'])
            ->assertOk()
            ->assertJsonPath('estado', 'abierto')
            ->assertJsonPath('cierre', null)
            ->assertJsonPath('reaperturas.0.version', 1)
            ->assertJsonPath('reaperturas.0.motivo_reapertura', 'Faltaba la recepción del día 30.')
            ->assertJsonPath('reaperturas.0.reabierto_por', 'Administración Demo');
    }

    public function test_revisar_diferencias_no_agota_el_limite_para_cerrar(): void
    {
        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])->assertCreated();
        foreach (range(1, 10) as $vez) {
            $this->getJson('/api/finanzas/prefactura/diferencias/resumen')->assertOk();
        }

        // Cada grupo de rutas tiene su propio contador: revisar no bloquea reabrir.
        $this->postJson('/api/finanzas/prefactura/cierres/2026-11/reabrir', ['motivo' => 'Faltaba la recepción del día 30.'])->assertOk();
    }

    public function test_cerrar_con_pendientes_exige_confirmarlos(): void
    {
        $this->hecho('despacho', $this->cliente, '2026-11-04 09:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120]);

        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.confirmar_pendientes.0', 'Queda 1 hecho sin precio: no se cobrará en este cierre. Confirme para cerrar igual.');
        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11', 'confirmar_pendientes' => true])
            ->assertCreated()
            ->assertJsonPath('resumen.hechos_sin_precio', 1)
            ->assertJsonPath('clientes.0.sin_precio.0.servicio', 'despacho');
    }

    public function test_revisar_y_decidir_las_diferencias(): void
    {
        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])->assertCreated();
        $this->getJson('/api/finanzas/prefactura/diferencias?mes=2026-12')
            ->assertUnprocessable()
            ->assertJsonPath('errors.mes.0', 'El mes no está cerrado: no tiene diferencias que revisar.');
        $this->getJson('/api/finanzas/prefactura/diferencias/resumen')->assertOk()->assertJsonPath('data', []);

        $this->recepcion('2026-11-20 09:00', 1000, 'R-ATRASADA');
        $this->getJson('/api/finanzas/prefactura/diferencias/resumen')
            ->assertOk()
            ->assertJsonPath('data.0.mes', '2026-11')
            ->assertJsonPath('data.0.diferencias', 1)
            ->assertJsonPath('data.0.montos.0.monto', '10000');
        $revision = $this->getJson('/api/finanzas/prefactura/diferencias?mes=2026-11')
            ->assertOk()
            ->assertJsonPath('primer_mes_abierto', '2026-12')
            ->assertJsonPath('diferencias.0.cliente_nombre', 'PRODUCTOR DE PRUEBA SPA')
            ->assertJsonPath('diferencias.0.unidad_nombre', 'por kilo neto')
            ->assertJsonPath('diferencias.0.antes.monto', '80000')
            ->assertJsonPath('diferencias.0.ahora.monto', '90000')
            ->assertJsonPath('diferencias.0.diferencia', ['cantidad' => '1000', 'dias' => 0, 'monto' => '10000'])
            ->assertJsonPath('diferencias.0.hechos_total', 1)
            ->assertJsonPath('diferencias.0.hechos.0.tipo', 'nuevo')
            ->assertJsonPath('diferencias.0.hechos.0.referencia', 'R-ATRASADA')
            ->assertJsonPath('diferencias.0.hechos.0.posterior_al_cierre', true)
            ->assertJsonPath('diferencias.0.hechos.0.motivo_version', 'captura')
            ->assertJsonPath('decisiones', [])
            ->json('diferencias.0');
        $decision = [
            'mes' => '2026-11',
            'cliente_id' => $revision['cliente_id'],
            'tarifa_id' => $revision['tarifa_id'],
            'moneda' => $revision['moneda'],
            ...$revision['diferencia'],
        ];

        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($consulta, 'sanctum')->postJson('/api/finanzas/prefactura/diferencias', [...$decision, 'decision' => 'aceptada'])->assertForbidden();
        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/finanzas/prefactura/diferencias', [...$decision, 'decision' => 'descartada'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motivo.0', 'Indique por qué se descarta la diferencia.');
        $id = $this->postJson('/api/finanzas/prefactura/diferencias', [...$decision, 'decision' => 'aceptada'])
            ->assertCreated()
            ->assertJsonPath('data.mes_destino', '2026-12')
            ->assertJsonPath('data.monto', '10000')
            ->assertJsonPath('data.puede_deshacerse', true)
            ->json('data.id');

        $this->getJson('/api/finanzas/prefactura?mes=2026-12')
            ->assertOk()
            ->assertJsonPath('clientes.0.ajustes.0.mes_origen', '2026-11')
            ->assertJsonPath('clientes.0.ajustes.0.monto', '10000')
            ->assertJsonPath('totales.0.monto', '10000')
            ->assertJsonPath('resumen.ajustes', 1);
        $this->getJson('/api/finanzas/prefactura/diferencias?mes=2026-11')
            ->assertJsonPath('diferencias', [])
            ->assertJsonPath('decisiones.0.decision', 'aceptada');

        $this->postJson("/api/finanzas/prefactura/diferencias/{$id}/anular", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.motivo.0', 'Indique por qué se deshace la decisión.');
        $this->postJson("/api/finanzas/prefactura/diferencias/{$id}/anular", ['motivo' => 'Se aceptó por error.'])
            ->assertOk()
            ->assertJsonPath('data.anulada_por', 'Administración Demo')
            ->assertJsonPath('data.puede_deshacerse', false);
        $this->getJson('/api/finanzas/prefactura/diferencias?mes=2026-11')->assertJsonCount(1, 'diferencias');
    }

    public function test_el_mes_cerrado_se_consulta_y_se_descarga_congelado(): void
    {
        $this->postJson('/api/finanzas/prefactura/cierres', ['mes' => '2026-11'])->assertCreated();
        $this->recepcion('2026-11-20 09:00', 1000, 'R-ATRASADA');

        $this->getJson('/api/finanzas/prefactura?mes=2026-11')->assertJsonPath('totales.0.monto', '80000');
        $detalle = $this->getJson("/api/finanzas/prefactura/detalle?mes=2026-11&cliente={$this->cliente}")
            ->assertOk()
            ->json('data.lineas.0.detalle');
        $this->assertSame(['R-7001'], array_column($detalle, 'referencia'), 'El detalle es el congelado.');

        $respuesta = $this->get('/api/finanzas/prefactura/exportar?mes=2026-11')->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $respuesta->baseResponse);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $corte = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        $this->assertStringContainsString('mes cerrado el 05-12-2026 12:00 por Administración Demo (versión 1)', $corte);
    }

    private function recepcion(string $hora, int $kilos, string $referencia): void
    {
        $this->hecho('romana_recepcion', $this->cliente, $hora, ['recepciones' => 1, 'kilos_netos' => $kilos, 'envases' => 10])
            ->forceFill(['referencia' => $referencia])->save();
    }
}
