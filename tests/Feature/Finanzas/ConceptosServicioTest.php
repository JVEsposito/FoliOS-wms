<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Cierre\GestorCierres;
use App\Finanzas\Cierre\RevisionDiferencias;
use App\Finanzas\Models\ConceptoServicio;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Prefactura\LibroPrefactura;
use App\Finanzas\Tarifario\CatalogoServicios;
use App\Finanzas\Tarifario\GestorServicios;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConceptosServicioTest extends TestCase
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
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->cliente = $this->clienteGlobal('EXPORTADORA PRUEBA');
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
    }

    public function test_catalogo_permisos_asociacion_unidades_e_historial(): void
    {
        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/finanzas/tarifas')->assertOk()->assertJsonCount(7, 'servicios')->assertJsonCount(9, 'procesos');
        $this->postJson('/api/finanzas/servicios', $this->definicion())->assertForbidden();
        $this->actingAs($this->administrador, 'sanctum');
        $servicio = $this->postJson('/api/finanzas/servicios', $this->definicion())->assertCreated()
            ->assertJsonPath('data.nombre', 'Servicio especial')->assertJsonPath('data.fuente', 'repaletizaje')
            ->assertJsonPath('data.base', false)->assertJsonPath('data.unidades.3.nombre', 'por caja resultante')->json('data.clave');
        $this->getJson('/api/finanzas/tarifas')->assertJsonCount(8, 'servicios');
        $this->postJson('/api/finanzas/tarifas', ['servicio' => $servicio, 'unidad' => 'kilos_netos', 'moneda' => 'CLP', 'precio' => '10', 'vigente_desde' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('unidad');
        $this->postJson('/api/finanzas/tarifas', ['servicio' => $servicio, 'unidad' => 'cajas', 'moneda' => 'CLP', 'precio' => '10', 'vigente_desde' => '2026-11-01'])
            ->assertCreated()->assertJsonPath('data.servicio_nombre', 'Servicio especial');
        $this->actingAs($consulta, 'sanctum')->getJson("/api/finanzas/servicios/{$servicio}/historial")
            ->assertOk()->assertJsonPath('data.0.accion', 'creado')->assertJsonPath('data.0.datos.fuente', 'repaletizaje');
        $this->postJson("/api/finanzas/servicios/{$servicio}/finalizar", ['vigente_hasta' => '2026-12-05', 'motivo' => 'Fin del contrato'])->assertForbidden();
        $this->putJson("/api/finanzas/servicios/{$servicio}", ['fuente' => 'despacho'])->assertNotFound();
        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/finanzas/servicios', $this->definicion(['fuente' => 'tabla_arbitraria']))->assertUnprocessable();
        $this->postJson('/api/finanzas/servicios', $this->definicion(['dia_desde' => 4]))->assertUnprocessable()->assertJsonValidationErrors('dia_desde');
    }

    public function test_adicionales_valorizan_el_mismo_hecho_con_precio_propio_y_conservan_su_trazabilidad(): void
    {
        $concepto = $this->concepto();
        $this->precio(null, 'repaletizaje', 'operaciones', '100', '2026-11-01');
        $this->precio(null, $concepto->clave, 'cajas', '2', '2026-11-01');
        $propio = $this->precio($this->cliente, $concepto->clave, 'cajas', '3', '2026-11-01');
        $hecho = $this->hecho('repaletizaje', $this->cliente, '2026-11-03 10:00', ['operaciones' => 1, 'cajas' => 40]);
        $resultado = $this->calcular();
        $this->assertSame('220', $resultado['totales'][0]['monto']);
        $this->assertSame(1, $resultado['resumen']['hechos_valorizados']);
        $linea = collect($resultado['clientes'][0]['lineas'])->firstWhere('tarifa_id', $propio->id);
        $this->assertSame('40', $linea['cantidad']);
        $this->assertSame($hecho->id, $linea['detalle'][0]['hecho_id']);
        $this->assertSame($resultado, $this->calcular(), 'Consultar no duplica hechos ni cobros.');
        $this->assertSame(1, Hecho::query()->count());
        $this->corregir($hecho, ['estado' => Hecho::ESTADO_ANULADO]);
        $this->assertSame([], $this->calcular()['totales']);
    }

    public function test_reemplazo_respeta_fechas_inclusivas_y_sin_precio_queda_pendiente(): void
    {
        $concepto = $this->concepto(['aplicacion' => 'reemplaza_base', 'vigente_desde' => '2026-11-10', 'vigente_hasta' => '2026-11-11']);
        $this->precio(null, 'repaletizaje', 'operaciones', '100', '2026-11-01');
        foreach ([9, 10, 11, 12] as $dia) {
            $this->hecho('repaletizaje', $this->cliente, "2026-11-{$dia} 10:00", ['operaciones' => 1, 'cajas' => 40]);
        }
        $resultado = $this->calcular();
        $this->assertSame('200', $resultado['totales'][0]['monto']);
        $this->assertSame([['servicio' => $concepto->clave, 'nombre' => 'Servicio especial', 'hechos' => 2]], $resultado['clientes'][0]['sin_precio']);
        $this->assertSame(2, $resultado['resumen']['hechos_sin_precio']);
        $this->precio(null, $concepto->clave, 'operaciones', '20', '2026-11-01');
        $this->assertSame('240', $this->calcular()['totales'][0]['monto']);
    }

    public function test_tramos_de_dias_cuentan_desde_el_ingreso_no_desde_el_mes_y_usan_cantidades_historicas(): void
    {
        $normal = $this->concepto(['nombre' => 'Frío normal', 'fuente' => 'estadia', 'aplicacion' => 'reemplaza_base', 'vigente_desde' => '2026-10-01', 'dia_hasta' => 15]);
        $numeral = $this->concepto(['nombre' => 'Numeral', 'fuente' => 'estadia', 'aplicacion' => 'reemplaza_base', 'vigente_desde' => '2026-10-01', 'dia_desde' => 16]);
        $this->precio(null, 'estadia', 'cajas', '100', '2026-10-01');
        $this->precio(null, $normal->clave, 'cajas', '1', '2026-10-01');
        $this->precio(null, $numeral->clave, 'cajas', '2', '2026-10-01');
        $antes = ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120];
        $despues = ['bultos' => 1, 'pallets' => 0, 'saldos' => 1, 'cajas' => 80];
        $this->estadia($this->cliente, 'F1#1', '2026-10-25 12:00', '2026-11-11 00:00', $antes, tramos: [
            [$antes, '2026-10-25 12:00', '2026-11-05 13:00'], [$despues, '2026-11-05 13:00', '2026-11-11 00:00'],
        ]);
        $lineas = collect($this->calcular()['clientes'][0]['lineas'])->keyBy('servicio');
        $this->assertFalse($lineas->has('estadia'));
        // El día 5 usa todavía las 120 cajas; el 6 comienza con 80.
        $this->assertSame(['840', 8, '840'], [$lineas[$normal->clave]['cantidad'], $lineas[$normal->clave]['dias'], $lineas[$normal->clave]['monto']]);
        $this->assertSame(['160', 2, '320'], [$lineas[$numeral->clave]['cantidad'], $lineas[$numeral->clave]['dias'], $lineas[$numeral->clave]['monto']]);
        $this->assertSame(['2026-11-09', '2026-11-10'], [$lineas[$numeral->clave]['detalle'][0]['desde'], $lineas[$numeral->clave]['detalle'][0]['hasta']]);
    }

    public function test_rangos_usan_dias_civiles_y_el_folio_nuevo_no_cobra_el_dia_del_repaletizaje(): void
    {
        $concepto = $this->concepto(['fuente' => 'estadia', 'aplicacion' => 'reemplaza_base', 'vigente_desde' => '2026-09-01', 'dia_desde' => 3]);
        $this->precio(null, 'estadia', 'bultos', '100', '2026-09-01');
        $this->precio(null, $concepto->clave, 'bultos', '10', '2026-09-01');
        $this->estadia($this->cliente, 'DST#1', '2026-09-05 22:00', '2026-09-08 00:00', ['bultos' => 1]);
        $this->assertSame('210', $this->calcular('2026-09')['totales'][0]['monto']);
        $this->estadia($this->cliente, 'NUEVO#1', '2026-11-01 10:00', '2026-11-04 00:00', ['bultos' => 1], ['repaletizaje_ingreso' => 'REP-1']);
        $this->assertSame('110', $this->calcular()['totales'][0]['monto']);
    }

    public function test_rechaza_reemplazos_cruzados_y_admite_rangos_contiguos(): void
    {
        $datos = ['fuente' => 'estadia', 'aplicacion' => 'reemplaza_base', 'dia_hasta' => 15];
        $this->concepto($datos);
        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/finanzas/servicios', $this->definicion([...$datos, 'dia_desde' => 15, 'dia_hasta' => 20]))
            ->assertUnprocessable()->assertJsonValidationErrors('aplicacion');
        $this->postJson('/api/finanzas/servicios', $this->definicion([...$datos, 'dia_desde' => 16, 'dia_hasta' => null]))->assertCreated();
        $this->postJson('/api/finanzas/servicios', $this->definicion(['fuente' => 'estadia', 'aplicacion' => 'adicional']))->assertCreated();
    }

    public function test_finalizar_conserva_historial_no_admite_retroactividad_y_no_cambia_la_definicion(): void
    {
        $concepto = $this->concepto();
        $this->actingAs($this->administrador, 'sanctum')->postJson("/api/finanzas/servicios/{$concepto->clave}/finalizar", ['vigente_hasta' => '2026-12-04', 'motivo' => 'Fin del servicio'])
            ->assertUnprocessable()->assertJsonValidationErrors('vigente_hasta');
        $this->postJson("/api/finanzas/servicios/{$concepto->clave}/finalizar", ['vigente_hasta' => '2026-12-05', 'motivo' => 'Fin del servicio', 'fuente' => 'despacho'])
            ->assertOk()->assertJsonPath('data.fuente', 'repaletizaje')->assertJsonPath('data.vigente_hasta', '2026-12-05');
        $this->getJson("/api/finanzas/servicios/{$concepto->clave}/historial")->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.accion', 'finalizado')->assertJsonPath('data.0.motivo', 'Fin del servicio');
        $catalogo = app(CatalogoServicios::class);
        $this->assertContains($concepto->clave, $catalogo->paraHecho('repaletizaje', '2026-12-05'));
        $this->assertNotContains($concepto->clave, $catalogo->paraHecho('repaletizaje', '2026-12-06'));
        $this->postJson("/api/finanzas/servicios/{$concepto->clave}/finalizar", ['vigente_hasta' => '2026-12-06', 'motivo' => 'Intento de extensión'])->assertUnprocessable();
    }

    public function test_cierre_permanece_congelado_y_un_concepto_nuevo_invalida_la_revision_aun_sin_precio(): void
    {
        $this->precio(null, 'repaletizaje', 'operaciones', '100', '2026-11-01');
        $this->hecho('repaletizaje', $this->cliente, '2026-11-03 10:00', ['operaciones' => 1]);
        $cierre = app(GestorCierres::class)->cerrar('2026-11', false, $this->administrador);
        $revision = app(RevisionDiferencias::class);
        $this->assertSame(0, $revision->resumen($cierre)['diferencias']);
        $concepto = $this->concepto(['aplicacion' => 'reemplaza_base']);
        $this->assertSame(1, $revision->resumen($cierre->fresh())['diferencias'], 'Cambió la aplicación sin cambiar un precio.');
        $this->precio(null, $concepto->clave, 'operaciones', '20', '2026-11-01');
        $this->assertSame(2, $revision->resumen($cierre->fresh())['diferencias']);
        $congelado = app(LibroPrefactura::class)->mes(Periodo::mes('2026-11'));
        $this->assertSame('100', $congelado['totales'][0]['monto']);
        $this->assertSame('Repaletizajes', $congelado['clientes'][0]['lineas'][0]['servicio_nombre']);
    }

    private function definicion(array $cambios = []): array
    {
        return [...[
            'nombre' => 'Servicio especial', 'descripcion' => null, 'fuente' => 'repaletizaje', 'aplicacion' => 'adicional',
            'vigente_desde' => '2026-11-01', 'vigente_hasta' => null, 'dia_desde' => 1, 'dia_hasta' => null,
        ], ...$cambios];
    }

    private function concepto(array $cambios = []): ConceptoServicio
    {
        return app(GestorServicios::class)->crear($this->definicion($cambios), $this->administrador);
    }

    private function calcular(string $mes = '2026-11'): array
    {
        return app(CalculadoraPrefactura::class)->calcular(Periodo::mes($mes), conDetalle: true);
    }
}
