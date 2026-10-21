<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\AplicacionProveedor;
use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\EvaluacionProveedor;
use App\Finanzas\Models\LineaAsientoCompra;
use App\Finanzas\Models\PagoProveedor;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CuentasProveedorTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private string $base = '/api/finanzas/proveedores';

    private string $proveedor;

    private array $factura;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson($this->base.'/plan/inicializar')->assertOk();
        $this->proveedor = $this->postJson($this->base, ['rut' => '76123456-0', 'razon_social' => 'Servicios de prueba', 'activo' => true, 'pagos_bloqueados' => false])->assertCreated()->json('data.id');
        $this->factura = $this->documento();
    }

    private function documento(array $c = []): array
    {
        $d = $this->postJson($this->base.'/documentos', [...['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $this->proveedor, 'tipo' => '33', 'folio' => '100', 'fecha_emision' => '2026-10-01', 'fecha_recepcion' => '2026-10-02', 'vencimiento' => '2026-11-01', 'neto' => '100000', 'exento' => '0', 'iva' => '19000', 'otros' => '0', 'lineas' => [['cuenta' => '5101', 'descripcion' => 'Servicio recibido', 'ceco' => 'FRIO', 'monto' => '100000']], 'guias' => [], 'respaldo' => 'Factura y recepción del servicio.'], ...$c])->assertCreated()->json('data');

        return $this->postJson($this->base.'/documentos/'.$d['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-02'])->assertOk()->json('data');
    }

    private function pago(array $c = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $this->proveedor, 'fecha' => '2026-10-04', 'monto' => '50000', 'cuenta' => '1101', 'referencia' => 'TRX-123', 'respaldo' => 'Comprobante bancario de transferencia realizada.', 'confirmado' => true, 'aplicaciones' => []], ...$c];
    }

    private function aplicar(string $credito, array $c = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $this->proveedor, 'credito_tipo' => 'pago', 'credito_id' => $credito, 'documento_id' => $this->factura['id'], 'fecha' => '2026-10-05', 'monto' => '40000', 'respaldo' => 'Abono parcial a la factura recibida.'], ...$c];
    }

    private function saldo(string $fecha): array
    {
        return $this->getJson($this->base.'/cuenta-corriente?proveedor_id='.$this->proveedor.'&fecha_corte='.$fecha)->assertOk()->json('data');
    }

    public function test_pago_parcial_y_anticipo_separados_cuadran_con_el_mayor_sin_duplicar_gasto(): void
    {
        $datos = $this->pago(['aplicaciones' => [['documento_id' => $this->factura['id'], 'monto' => '40000']]]);
        $p = $this->postJson($this->base.'/pagos', $datos)->assertCreated()->json('data');
        $this->postJson($this->base.'/pagos', $datos)->assertCreated()->assertJsonPath('data.id', $p['id']);
        $this->assertSame('119000', $this->saldo('2026-10-03')['totales']['saldo_neto']);
        $s = $this->saldo('2026-11-10');
        $this->assertSame(['deuda_documentos' => '79000', 'notas_por_aplicar' => '0', 'anticipos_disponibles' => '10000', 'saldo_cuentas_por_pagar' => '79000', 'saldo_neto' => '69000'], $s['totales']);
        $this->assertSame('79000', $s['antiguedad']['1_30']);
        $balance = $this->getJson($this->base.'/balance?desde=2026-10-01&hasta=2026-10-31')->assertOk()->json('data');
        $cuentas = collect($balance['cuentas'])->keyBy('cuenta');
        $this->assertSame('-79000', $cuentas['2101']['saldo_final']);
        $this->assertSame('10000', $cuentas['1205']['saldo_final']);
        $this->assertSame('100000', $balance['gastos'][0]['monto']);
        $this->assertSame('0', (string) LineaAsientoCompra::query()->selectRaw('SUM(debe - haber) as saldo')->value('saldo'));
        $this->postJson($this->base.'/pagos', [...$datos, 'monto' => '60000'])->assertUnprocessable()->assertJsonValidationErrors('operacion_id');
        $this->postJson($this->base.'/pagos', $this->pago(['referencia' => 'trx-123']))->assertUnprocessable()->assertJsonValidationErrors('referencia');
    }

    public function test_los_reversos_conservan_saldos_historicos_y_exigen_desaplicar_primero(): void
    {
        $p = $this->postJson($this->base.'/pagos', $this->pago())->assertCreated()->json('data');
        $a = $this->postJson($this->base.'/aplicaciones', $this->aplicar($p['id']))->assertCreated()->json('data');
        $this->postJson($this->base.'/pagos/'.$p['id'].'/anular', ['fecha' => '2026-10-20', 'motivo' => 'Aún tiene abono vigente.'])->assertUnprocessable();
        $this->postJson($this->base.'/documentos/'.$this->factura['id'].'/anular', ['fecha_contable' => '2026-10-20', 'motivo' => 'Aún tiene abono vigente.'])->assertUnprocessable();
        $this->postJson($this->base.'/aplicaciones/'.$a['id'].'/anular', ['fecha' => '2026-10-20', 'motivo' => 'Corregir imputación del pago.'])->assertOk();
        $this->assertSame('79000', $this->saldo('2026-10-10')['totales']['deuda_documentos']);
        $this->assertSame('119000', $this->saldo('2026-10-21')['totales']['deuda_documentos']);
        $this->assertSame('50000', $this->saldo('2026-10-21')['totales']['anticipos_disponibles']);
        $this->postJson($this->base.'/pagos/'.$p['id'].'/anular', ['fecha' => '2026-10-19', 'motivo' => 'No puede preceder al reverso.'])->assertUnprocessable();
        $this->postJson($this->base.'/pagos/'.$p['id'].'/anular', ['fecha' => '2026-10-22', 'motivo' => 'Se ingresó el comprobante por error.'])->assertOk();
        $this->assertSame('69000', $this->saldo('2026-10-10')['totales']['saldo_neto']);
        $this->assertSame('119000', $this->saldo('2026-10-23')['totales']['saldo_neto']);
        $this->postJson($this->base.'/pagos', $this->pago())->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->assertSame(5, AsientoCompra::query()->count());
    }

    public function test_no_se_reutiliza_antes_de_su_fecha_un_credito_desaplicado_y_no_hay_sobreabonos(): void
    {
        $p = $this->postJson($this->base.'/pagos', $this->pago())->assertCreated()->json('data');
        $datos = $this->aplicar($p['id']);
        $a = $this->postJson($this->base.'/aplicaciones', $datos)->assertCreated()->json('data');
        $this->postJson($this->base.'/aplicaciones', $datos)->assertCreated()->assertJsonPath('data.id', $a['id']);
        $this->postJson($this->base.'/aplicaciones', $this->aplicar($p['id'], ['monto' => '11000']))->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->postJson($this->base.'/aplicaciones/'.$a['id'].'/anular', ['fecha' => '2026-10-20', 'motivo' => 'Se corrige la imputación original.'])->assertOk();
        $this->postJson($this->base.'/aplicaciones', $this->aplicar($p['id'], ['fecha' => '2026-10-10']))->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($this->base.'/aplicaciones', $this->aplicar($p['id'], ['fecha' => '2026-10-21']))->assertCreated();
        $this->assertSame('79000', $this->saldo('2026-10-15')['totales']['deuda_documentos']);
        $this->assertSame('79000', $this->saldo('2026-10-22')['totales']['deuda_documentos']);
    }

    public function test_un_pago_con_aplicacion_invalida_no_deja_pago_asiento_ni_eventos_parciales(): void
    {
        $antes = AsientoCompra::query()->count();
        $this->postJson($this->base.'/pagos', $this->pago(['aplicaciones' => [['documento_id' => $this->factura['id'], 'monto' => '51000']]]))->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->assertSame(0, PagoProveedor::query()->count());
        $this->assertSame(0, AplicacionProveedor::query()->count());
        $this->assertSame($antes, AsientoCompra::query()->count());
        $this->postJson($this->base.'/pagos', $this->pago(['fecha' => '2026-10-01', 'aplicaciones' => [['documento_id' => $this->factura['id'], 'monto' => '1000']]]))->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($this->base.'/pagos', $this->pago(['confirmado' => false]))->assertUnprocessable();
        ProveedorFinanciero::query()->findOrFail($this->proveedor)->update(['pagos_bloqueados' => true]);
        $this->postJson($this->base.'/pagos', $this->pago())->assertUnprocessable()->assertJsonValidationErrors('proveedor_id');
        $this->assertSame(0, PagoProveedor::query()->count());
    }

    public function test_la_nota_reduce_el_pasivo_una_sola_vez_y_su_aplicacion_no_repite_el_asiento(): void
    {
        $nota = $this->documento(['tipo' => '61', 'folio' => '1', 'referencia_id' => $this->factura['id']]);
        $this->assertSame('0', $this->saldo('2026-10-03')['totales']['saldo_cuentas_por_pagar']);
        $antes = AsientoCompra::query()->count();
        $d = $this->aplicar($nota['id'], ['credito_tipo' => 'nota_credito', 'monto' => '119000']);
        $a = $this->postJson($this->base.'/aplicaciones', $d)->assertCreated()->json('data');
        $this->assertSame($antes, AsientoCompra::query()->count());
        $s = $this->saldo('2026-10-10');
        $this->assertSame([], $s['deudas']);
        $this->assertSame([], $s['creditos']);
        $this->postJson($this->base.'/documentos/'.$nota['id'].'/anular', ['fecha_contable' => '2026-10-20', 'motivo' => 'Debe desaplicarse primero.'])->assertUnprocessable();
        $this->postJson($this->base.'/aplicaciones/'.$a['id'].'/anular', ['fecha' => '2026-10-20', 'motivo' => 'Se corrige el ajuste de factura.'])->assertOk();
        $this->assertSame('0', $this->saldo('2026-10-21')['totales']['saldo_neto']);
        $this->postJson($this->base.'/documentos/'.$nota['id'].'/anular', ['fecha_contable' => '2026-10-21', 'motivo' => 'Reversar nota ingresada por error.'])->assertOk();
        $this->assertSame('119000', $this->saldo('2026-10-22')['totales']['saldo_neto']);
    }

    public function test_cierre_y_reapertura_bloquean_pagos_y_conservan_auditoria(): void
    {
        $datos = ['mes' => '2026-10', 'cerrar' => true, 'motivo' => 'Mes conciliado y revisado por contabilidad.'];
        $this->postJson($this->base.'/periodos', $datos)->assertOk();
        $this->postJson($this->base.'/pagos', $this->pago())->assertUnprocessable()->assertJsonValidationErrors('fecha_contable');
        $this->assertSame(0, PagoProveedor::query()->count());
        $this->postJson($this->base.'/periodos', ['mes' => '2026-11', 'cerrar' => true, 'motivo' => 'Cierre del mes siguiente.'])->assertOk();
        $this->postJson($this->base.'/periodos', [...$datos, 'cerrar' => false])->assertUnprocessable();
        $this->postJson($this->base.'/periodos', ['mes' => '2026-11', 'cerrar' => false, 'motivo' => 'Reabrir último mes antes del anterior.'])->assertOk();
        $this->postJson($this->base.'/periodos', [...$datos, 'cerrar' => false])->assertOk();
        $this->postJson($this->base.'/pagos', $this->pago())->assertCreated();
        $this->getJson($this->base.'/historial/periodo/2026-10')->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_evaluacion_con_criterios_ponderados_es_inmutable_y_no_bloquea_pagos_sin_decision(): void
    {
        $d = ['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $this->proveedor, 'desde' => '2026-10-01', 'hasta' => '2026-10-20', 'conclusion' => 'condicional', 'respaldo' => 'Retraso documentado de entregas, mantener revisión.', 'criterios' => [['nombre' => 'Calidad', 'peso' => 40, 'nota' => 5], ['nombre' => 'Plazos', 'peso' => 60, 'nota' => 3]]];
        $e = $this->postJson($this->base.'/evaluaciones', $d)->assertCreated()->assertJsonPath('data.resultado', '3.80')->json('data');
        $this->postJson($this->base.'/evaluaciones', $d)->assertCreated()->assertJsonPath('data.id', $e['id']);
        $this->postJson($this->base.'/evaluaciones', [...$d, 'conclusion' => 'aprobado'])->assertUnprocessable();
        $this->postJson($this->base.'/evaluaciones', [...$d, 'operacion_id' => (string) Str::uuid(), 'criterios' => [['nombre' => 'Calidad', 'peso' => 90, 'nota' => 3]]])->assertUnprocessable()->assertJsonValidationErrors('criterios');
        $this->postJson($this->base.'/evaluaciones/'.$e['id'].'/anular', ['motivo' => 'Se revisará con un período más completo.'])->assertOk();
        $this->assertSame('3.80', EvaluacionProveedor::query()->findOrFail($e['id'])->resultado);
        $this->assertFalse(ProveedorFinanciero::query()->findOrFail($this->proveedor)->pagos_bloqueados);
        $this->getJson($this->base.'/evaluaciones?proveedor_id='.$this->proveedor)->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_no_aplica_pagos_a_deudas_de_otro_proveedor(): void
    {
        $otro = $this->postJson($this->base, ['rut' => '11111111-1', 'razon_social' => 'Proveedor distinto', 'activo' => true, 'pagos_bloqueados' => false])->assertCreated()->json('data.id');
        $otraFactura = $this->documento(['proveedor_id' => $otro, 'folio' => '200']);
        $pago = $this->postJson($this->base.'/pagos', $this->pago())->assertCreated()->json('data');
        $this->postJson($this->base.'/aplicaciones', $this->aplicar($pago['id'], ['documento_id' => $otraFactura['id']]))->assertUnprocessable()->assertJsonValidationErrors('documento_id');
        $this->assertSame(0, AplicacionProveedor::query()->count());
    }

    public function test_mayor_y_csv_conservan_aperturas_y_protegen_los_textos_del_proveedor(): void
    {
        $this->documento(['folio' => '101', 'lineas' => [['cuenta' => '5101', 'descripcion' => 'Segundo servicio', 'ceco' => '=2+2', 'monto' => '100000']]]);
        $mayor = $this->getJson($this->base.'/mayor?cuenta=5101&desde=2026-10-01&hasta=2026-10-31')->assertOk()->json('data');
        $this->assertSame('0', $mayor['saldo_inicial']);
        $this->assertSame('200000', $mayor['saldo_final']);
        $this->assertCount(2, $mayor['movimientos']['data']);
        $this->assertSame('200000', $mayor['movimientos']['data'][1]['saldo']);
        $apertura = $this->getJson($this->base.'/mayor?cuenta=5101&desde=2026-11-01&hasta=2026-11-30')->assertOk()->json('data');
        $this->assertSame('200000', $apertura['saldo_inicial']);
        $this->assertSame([], $apertura['movimientos']['data']);
        $csv = $this->get($this->base.'/exportar?desde=2026-10-01&hasta=2026-10-31')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=2+2", $csv);
        $this->assertStringContainsString('SALDO FINAL CLP', $csv);
    }

    public function test_los_costos_y_el_registro_de_pagos_tienen_permisos_distintos(): void
    {
        $gestor = $this->conPerfil(RolUsuario::Administrador, [CatalogoModulosAcceso::OFICINA_FINANZAS_PROVEEDORES]);
        $this->actingAs($gestor, 'sanctum')->getJson($this->base.'/cuenta-corriente?fecha_corte=2026-10-20')->assertOk();
        $this->postJson($this->base.'/pagos', $this->pago())->assertForbidden();
        $pagador = $this->conPerfil(RolUsuario::Administrador, [CatalogoModulosAcceso::OFICINA_FINANZAS_PROVEEDORES, CatalogoModulosAcceso::FINANZAS_PAGOS_PROVEEDORES]);
        $this->actingAs($pagador, 'sanctum')->postJson($this->base.'/pagos', $this->pago())->assertCreated();
        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PROVEEDORES, CatalogoModulosAcceso::FINANZAS_PAGOS_PROVEEDORES]);
        $this->actingAs($consulta, 'sanctum')->postJson($this->base.'/pagos', $this->pago())->assertForbidden();
        $this->postJson($this->base.'/periodos', ['mes' => '2026-10', 'cerrar' => true, 'motivo' => 'Consulta no puede cerrar.'])->assertForbidden();
    }
}
