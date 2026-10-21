<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\CuentaCompra;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\LineaAsientoCompra;
use App\Finanzas\Models\PeriodoCompra;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProveedoresDocumentosTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private User $admin;

    private string $proveedor;

    private string $base = '/api/finanzas/proveedores';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson($this->base.'/plan/inicializar')->assertOk();
        $this->proveedor = $this->postJson($this->base, $this->ficha())->assertCreated()->json('data.id');
    }

    private function ficha(array $c = []): array
    {
        return [...['rut' => '76.123.456-0', 'razon_social' => 'Servicios de prueba', 'activo' => true, 'pagos_bloqueados' => false, 'proveedor_material_id' => null], ...$c];
    }

    private function datos(array $c = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $this->proveedor, 'tipo' => '33', 'folio' => '123', 'fecha_emision' => '2026-10-01', 'fecha_recepcion' => '2026-10-02', 'vencimiento' => '2026-11-01', 'neto' => '100000', 'exento' => '0', 'iva' => '19000', 'otros' => '0', 'lineas' => [['cuenta' => '5101', 'descripcion' => 'Mantención de túneles', 'ceco' => 'FRIO', 'monto' => '100000']], 'guias' => [], 'respaldo' => 'Factura recibida y orden de servicio OC-100.'], ...$c];
    }

    private function crear(array $c = []): array
    {
        return $this->postJson($this->base.'/documentos', $this->datos($c))->assertCreated()->json('data');
    }

    private function registrar(array $d, string $fecha = '2026-10-02'): array
    {
        return $this->postJson($this->base.'/documentos/'.$d['id'].'/registrar', ['version' => $d['version'], 'fecha_contable' => $fecha])->assertOk()->json('data');
    }

    public function test_reutiliza_materiales_sin_escribir_en_la_operacion_y_el_rut_es_unico(): void
    {
        $material = (string) Str::uuid();
        DB::table('proveedores_materiales')->insert(['id' => $material, 'codigo' => 'PROV-TEST', 'nombre' => 'Proveedor de materiales', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($this->base.'/materiales?buscar=PROV-TEST')->assertOk()->assertJsonPath('data.0.id', $material);
        DB::connection()->enableQueryLog();
        $this->putJson($this->base.'/fichas/'.$this->proveedor, $this->ficha(['version' => 1, 'motivo' => 'Vincular ficha existente.', 'proveedor_material_id' => $material]))->assertOk()->assertJsonPath('data.version', 2);
        $escrituras = collect(DB::connection()->getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete|alter|create|drop)\b/i', $q['query']))->pluck('query')->all();
        DB::connection()->disableQueryLog();
        $this->assertSame([], $escrituras);
        $this->assertSame('Proveedor de materiales', DB::table('proveedores_materiales')->where('id', $material)->value('nombre'));
        $this->postJson($this->base, $this->ficha(['rut' => '76123456-0']))->assertUnprocessable()->assertJsonValidationErrors('rut');
        $this->postJson($this->base, $this->ficha(['rut' => '11111111-1', 'proveedor_material_id' => $material]))->assertUnprocessable()->assertJsonValidationErrors('proveedor_material_id');
        $this->getJson($this->base.'/historial/proveedor/'.$this->proveedor)->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_factura_balanceada_reintentos_y_snapshot_inmutable_sin_facturar_al_cliente(): void
    {
        $datos = $this->datos();
        $d = $this->postJson($this->base.'/documentos', $datos)->assertCreated()->json('data');
        $this->postJson($this->base.'/documentos', $datos)->assertCreated()->assertJsonPath('data.id', $d['id']);
        $this->postJson($this->base.'/documentos', [...$datos, 'neto' => '90000'])->assertUnprocessable()->assertJsonValidationErrors('operacion_id');
        $this->registrar($d);
        $this->registrar($d);
        $this->assertSame(1, AsientoCompra::query()->count());
        $this->assertSame('119000', (string) LineaAsientoCompra::query()->sum('debe'));
        $this->assertSame('119000', (string) LineaAsientoCompra::query()->sum('haber'));
        $this->assertSame('19000', LineaAsientoCompra::query()->where('cuenta', '1108')->firstOrFail()->debe);
        $this->putJson($this->base.'/fichas/'.$this->proveedor, $this->ficha(['version' => 1, 'motivo' => 'Cambio de domicilio y nombre.', 'razon_social' => 'Nombre nuevo']))->assertOk();
        $this->getJson($this->base.'/documentos/'.$d['id'])->assertOk()->assertJsonPath('data.proveedor_snapshot.razon_social', 'Servicios de prueba')->assertJsonCount(1, 'asientos');
        $edicion = collect($datos)->except('operacion_id')->all();
        $this->putJson($this->base.'/documentos/'.$d['id'], [...$edicion, 'version' => 2])->assertUnprocessable();
        $this->assertSame(0, Hecho::query()->count(), 'Una cuenta por pagar nunca se transforma en un cargo al cliente.');
    }

    public function test_guia_no_duplica_gasto_ni_deuda_y_las_referencias_son_del_mismo_proveedor(): void
    {
        $guia = $this->crear(['tipo' => '52', 'folio' => '80', 'iva' => '0']);
        $this->registrar($guia);
        $this->assertSame(0, AsientoCompra::query()->count());
        $factura = $this->crear(['guias' => [$guia['id']]]);
        $this->registrar($factura);
        $this->assertSame(1, AsientoCompra::query()->count());
        $this->getJson($this->base.'/referencias?proveedor_id='.$this->proveedor.'&buscar=80')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->base.'/documentos/'.$guia['id'].'/anular', ['fecha_contable' => '2026-10-04', 'motivo' => 'Tiene factura vinculada.'])->assertUnprocessable();
        $otro = $this->postJson($this->base, $this->ficha(['rut' => '11111111-1']))->assertCreated()->json('data.id');
        $this->postJson($this->base.'/documentos', $this->datos(['proveedor_id' => $otro, 'guias' => [$guia['id']]]))->assertUnprocessable()->assertJsonValidationErrors('guias');
    }

    public function test_corrige_solo_el_borrador_actual_y_detecta_folios_con_ceros_a_la_izquierda(): void
    {
        $d = $this->crear(['folio' => '000123']);
        $this->postJson($this->base.'/documentos', $this->datos())->assertUnprocessable()->assertJsonValidationErrors('folio');
        $datos = collect($this->datos(['folio' => '124']))->except('operacion_id')->all();
        $this->putJson($this->base.'/documentos/'.$d['id'], [...$datos, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->base.'/documentos/'.$d['id'], [...$datos, 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($this->base.'/documentos/'.$d['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-02'])->assertUnprocessable();
        $this->registrar(DocumentoCompra::query()->findOrFail($d['id'])->toArray());
    }

    public function test_valida_distribucion_impuestos_referencias_y_plazos_sin_asumir_credito_fiscal(): void
    {
        $this->postJson($this->base.'/documentos', $this->datos(['neto' => '90000', 'iva' => '17100']))->assertUnprocessable()->assertJsonValidationErrors('lineas');
        $this->postJson($this->base.'/documentos', $this->datos(['tipo' => '34']))->assertUnprocessable()->assertJsonValidationErrors('iva');
        $this->postJson($this->base.'/documentos', $this->datos(['iva' => '1']))->assertUnprocessable()->assertJsonValidationErrors('observacion_iva');
        $this->postJson($this->base.'/documentos', $this->datos(['tipo' => '61']))->assertUnprocessable()->assertJsonValidationErrors('referencia_id');
        $this->postJson($this->base.'/documentos', $this->datos(['vencimiento' => '2026-12-01']))->assertUnprocessable()->assertJsonValidationErrors('respaldo_plazo');
        $this->postJson($this->base.'/documentos', $this->datos(['lineas' => [['cuenta' => '2101', 'descripcion' => 'Incorrecta', 'monto' => '100000']]]))->assertUnprocessable()->assertJsonValidationErrors('cuenta');
        $this->postJson($this->base.'/documentos', $this->datos(['tipo' => '33', 'folio' => 'ABC']))->assertUnprocessable()->assertJsonValidationErrors('folio');
        $this->crear(['iva' => '19001', 'observacion_iva' => 'Diferencia de redondeo en el documento recibido.']);
    }

    public function test_notas_no_superan_su_factura_y_reversar_no_borra_la_historia(): void
    {
        $factura = $this->crear();
        $this->registrar($factura);
        $nota = $this->crear(['tipo' => '61', 'folio' => '10', 'referencia_id' => $factura['id']]);
        $this->registrar($nota);
        $this->assertSame('0', (string) LineaAsientoCompra::query()->where('cuenta', '2101')->selectRaw('SUM(haber - debe) as saldo')->value('saldo'));
        $exceso = $this->crear(['tipo' => '61', 'folio' => '11', 'referencia_id' => $factura['id']]);
        $this->postJson($this->base.'/documentos/'.$exceso['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-03'])->assertUnprocessable()->assertJsonValidationErrors('total');
        $this->postJson($this->base.'/documentos/'.$factura['id'].'/anular', ['fecha_contable' => '2026-10-04', 'motivo' => 'Aún tiene nota vigente.'])->assertUnprocessable();
        CuentaCompra::query()->where('codigo', '5101')->update(['activa' => false]);
        foreach ([$nota, $factura] as $d) {
            $this->postJson($this->base.'/documentos/'.$d['id'].'/anular', ['fecha_contable' => '2026-10-04', 'motivo' => 'Corrección del registro interno.'])->assertOk();
        }
        $this->assertSame(4, AsientoCompra::query()->count());
        $this->assertSame(2, AsientoCompra::query()->whereNotNull('reversa_de_id')->count());
        $this->assertSame('0', (string) LineaAsientoCompra::query()->selectRaw('SUM(debe - haber) as saldo')->value('saldo'));
        $this->getJson($this->base.'/documentos/'.$nota['id'])->assertJsonCount(2, 'asientos');
    }

    public function test_el_cierre_impide_reescribir_el_mes_y_un_fallo_no_deja_asiento_parcial(): void
    {
        $d = $this->crear();
        CuentaCompra::query()->where('codigo', '2101')->update(['activa' => false]);
        $this->postJson($this->base.'/documentos/'.$d['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-02'])->assertUnprocessable();
        $this->assertNull(DocumentoCompra::query()->findOrFail($d['id'])->registrado_at);
        $this->assertSame(0, AsientoCompra::query()->count());
        $this->assertSame(0, PeriodoCompra::query()->count());
        CuentaCompra::query()->where('codigo', '2101')->update(['activa' => true]);
        $this->registrar($d);
        PeriodoCompra::query()->where('mes', '2026-10')->update(['cerrado_at' => now()]);
        $this->postJson($this->base.'/documentos/'.$d['id'].'/anular', ['fecha_contable' => '2026-10-20', 'motivo' => 'No debe reabrir el mes.'])->assertUnprocessable();
        $this->postJson($this->base.'/documentos/'.$d['id'].'/anular', ['fecha_contable' => '2026-11-01', 'motivo' => 'Corrección en el mes abierto.'])->assertOk();
        $this->getJson($this->base.'/asientos?desde=2026-10-01&hasta=2026-10-31')->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson($this->base.'/asientos?desde=2026-11-01&hasta=2026-11-30')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_los_reversos_no_permiten_reutilizar_notas_en_una_fecha_anterior_ni_cambiar_sus_impuestos(): void
    {
        $factura = $this->crear();
        $this->registrar($factura);
        $nota = $this->crear(['tipo' => '61', 'folio' => '10', 'referencia_id' => $factura['id']]);
        $this->registrar($nota);
        $this->postJson($this->base.'/documentos/'.$nota['id'].'/anular', ['fecha_contable' => '2026-11-05', 'motivo' => 'Reverso de nota en noviembre.'])->assertOk();
        $otra = $this->crear(['tipo' => '61', 'folio' => '11', 'referencia_id' => $factura['id']]);
        $this->postJson($this->base.'/documentos/'.$otra['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors('fecha_contable');
        $this->postJson($this->base.'/documentos/'.$factura['id'].'/anular', ['fecha_contable' => '2026-10-30', 'motivo' => 'No puede preceder a su nota.'])->assertUnprocessable()->assertJsonValidationErrors('fecha_contable');
        $mal = $this->crear(['tipo' => '61', 'folio' => '12', 'referencia_id' => $factura['id'], 'neto' => '0', 'exento' => '100000', 'observacion_iva' => 'Se prueba la validación de componentes.']);
        $this->postJson($this->base.'/documentos/'.$mal['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-11-06'])->assertUnprocessable()->assertJsonValidationErrors('total');
        $this->registrar($otra, '2026-11-06');
        $octubre = $this->getJson($this->base.'/asientos?desde=2026-10-01&hasta=2026-10-31')->assertOk();
        $octubre->assertJsonCount(2, 'data.data');
    }

    public function test_un_error_de_digitacion_contabilizado_se_corrige_sin_perder_la_factura_ni_el_corte_anterior(): void
    {
        $original = $this->crear();
        $this->registrar($original);
        $this->postJson($this->base.'/documentos/'.$original['id'].'/anular', ['fecha_contable' => '2026-11-02', 'motivo' => 'Se digitó un monto incorrecto.'])->assertOk();
        $correccion = $this->crear(['reemplaza_id' => $original['id'], 'neto' => '90000', 'iva' => '17100', 'lineas' => [['cuenta' => '5101', 'descripcion' => 'Monto correcto según factura', 'ceco' => 'FRIO', 'monto' => '90000']]]);
        $this->assertSame(2, $correccion['revision']);
        $this->postJson($this->base.'/documentos/'.$correccion['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors('fecha_contable');
        $this->registrar($correccion, '2026-11-02');
        $this->assertSame('119000', DocumentoCompra::query()->findOrFail($original['id'])->total);
        $this->assertSame('107100', DocumentoCompra::query()->findOrFail($correccion['id'])->total);
        $this->assertSame(3, AsientoCompra::query()->count());
        $this->postJson($this->base.'/documentos', $this->datos(['reemplaza_id' => $original['id']]))->assertUnprocessable()->assertJsonValidationErrors('folio');
    }

    public function test_el_acceso_a_costos_se_asigna_aparte_del_tarifario_y_consulta_no_escribe(): void
    {
        $comercial = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PREFACTURA]);
        $this->actingAs($comercial, 'sanctum')->getJson($this->base)->assertForbidden();
        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_FINANZAS_PROVEEDORES]);
        $this->actingAs($consulta, 'sanctum')->getJson($this->base)->assertOk()->assertJsonPath('puede_gestionar', false);
        $this->postJson($this->base.'/plan/inicializar')->assertForbidden();
        $this->postJson($this->base.'/documentos', $this->datos())->assertForbidden();
        $this->getJson('/api/finanzas/tarifas')->assertForbidden();
        $this->getJson('/api/acceso-oficina')->assertOk()->assertJsonPath('usuario.puede_consultar_proveedores_finanzas', true);
    }
}
