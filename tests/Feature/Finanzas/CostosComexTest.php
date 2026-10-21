<?php

namespace Tests\Feature\Finanzas;

use App\Comex\Models\Booking;
use App\Comex\Models\ParteComercial;
use App\Enums\RolUsuario;
use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\ImputacionComex;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Comex\PreparaComercial;
use Tests\Feature\Comex\PreparaOperacionComex;
use Tests\TestCase;

class CostosComexTest extends TestCase
{
    use PreparaComercial;
    use PreparaOperacionComex;
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [null, 'finanzas', 'comex'];
    }

    private string $booking;

    private string $proveedor;

    private array $datos;

    private int $folio = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
        config(['finanzas.conexion_comex' => 'comex']);
        $this->actingAs($this->administradorComex());
        $d = $this->datosNota();
        $parte = ParteComercial::query()->findOrFail($d['partes']['vendedor']);
        $b = Booking::query()->create(['id' => (string) Str::uuid(), 'codigo' => 'BK-COSTO', 'naviera_id' => $parte->id, 'cliente_operacion_id' => $d['cliente_operacion_id'], 'cliente' => ['id' => $d['cliente_operacion_id'], 'nombre' => 'Cliente de prueba'], 'gestion' => 'servicio', 'estado' => 'confirmado', 'datos' => ['nave' => 'Nave de prueba', 'viaje' => 'Viaje de prueba'], 'version' => 1]);
        $this->booking = $b->id;
        $this->postJson('/api/finanzas/proveedores/plan/inicializar')->assertOk();
        $this->proveedor = $this->postJson('/api/finanzas/proveedores', ['rut' => '76.123.456-0', 'razon_social' => 'Proveedor de prueba', 'activo' => true, 'pagos_bloqueados' => false])->assertCreated()->json('data.id');
        $this->datos = ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'booking_id' => $this->booking, 'concepto' => 'Flete marítimo de prueba', 'tratamiento' => 'por_definir', 'fecha' => '2026-10-01', 'moneda' => 'USD', 'previsto' => '100.50', 'comprometido' => '90', 'tasa_clp' => '900', 'fecha_cambio' => '2026-10-01', 'fuente_cambio' => 'Tipo acordado con respaldo', 'respaldo' => 'Cotización comercial de prueba'];
    }

    private function costo(): array
    {
        return $this->postJson('/api/finanzas/costos-comex', [...$this->datos, 'operacion_id' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    private function documento(array $extra = [], bool $registrar = true): array
    {
        $d = $this->postJson('/api/finanzas/proveedores/documentos', ['operacion_id' => (string) Str::uuid(), 'proveedor_id' => $this->proveedor, 'tipo' => '33', 'folio' => (string) $this->folio++, 'fecha_emision' => '2026-10-01', 'fecha_recepcion' => '2026-10-02', 'vencimiento' => '2026-11-01', 'neto' => '100000', 'exento' => '0', 'iva' => '19000', 'otros' => '0', 'lineas' => [['cuenta' => '5101', 'descripcion' => 'Servicio logístico', 'monto' => '100000']], 'guias' => [], 'respaldo' => 'Factura recibida con respaldo de prueba', ...$extra])->assertCreated()->json('data');

        return $registrar ? $this->postJson('/api/finanzas/proveedores/documentos/'.$d['id'].'/registrar', ['version' => 1, 'fecha_contable' => '2026-10-02'])->assertOk()->json('data') : $d;
    }

    private function solicitud(array $c, string $accion, array $extra = []): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version' => $c['costo']['version'], 'accion' => $accion, 'motivo' => 'Distribución de costo con respaldo de prueba', ...$extra];
    }

    private function paso(array $c, string $accion, array $extra = []): array
    {
        return $this->postJson('/api/finanzas/costos-comex/'.$c['costo']['id'].'/transiciones', $this->solicitud($c, $accion, $extra))->assertOk()->json('data');
    }

    public function test_distribuye_compra_entre_costos_sin_duplicar_gasto_iva_ni_cargo_al_cliente(): void
    {
        $doc = $this->documento();
        $c = $this->costo();
        $otro = $this->costo();
        $asientos = AsientoCompra::query()->count();
        $c = $this->paso($c, 'imputar', ['documento_id' => $doc['id'], 'linea' => 0, 'monto' => '60000']);
        $this->paso($otro, 'imputar', ['documento_id' => $doc['id'], 'linea' => 0, 'monto' => '40000']);
        $this->postJson('/api/finanzas/costos-comex/'.$c['costo']['id'].'/transiciones', $this->solicitud($c, 'imputar', ['documento_id' => $doc['id'], 'linea' => 0, 'monto' => '1']))->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->assertSame('60000', $c['real_vigente_clp']);
        $this->assertSame('90450', $c['costo']['conversion']['previsto_clp']);
        $this->assertSame($asientos, AsientoCompra::query()->count());
        $this->assertSame(1, DocumentoCompra::query()->count());
        $this->assertSame(0, Hecho::query()->count());
    }

    public function test_reintento_no_duplica_y_no_acepta_guia_ni_borrador(): void
    {
        $c = $this->costo();
        $doc = $this->documento();
        $s = $this->solicitud($c, 'imputar', ['documento_id' => $doc['id'], 'linea' => 0, 'monto' => '100']);
        $url = '/api/finanzas/costos-comex/'.$c['costo']['id'].'/transiciones';
        $c = $this->postJson($url, $s)->assertOk()->json('data');
        $this->postJson($url, $s)->assertOk();
        $this->assertSame(1, ImputacionComex::query()->count());
        foreach ([$this->documento([], false), $this->documento(['tipo' => '52', 'iva' => '0'])] as $d) {
            $this->postJson($url, $this->solicitud($c, 'imputar', ['documento_id' => $d['id'], 'linea' => 0, 'monto' => '100']))->assertUnprocessable();
        }
    }

    public function test_credito_resta_solo_hasta_factura_distribuida_y_preserva_dependencias(): void
    {
        $factura = $this->documento();
        $credito = $this->documento(['tipo' => '61', 'referencia_id' => $factura['id']]);
        $c = $this->costo();
        $c = $this->paso($c, 'imputar', ['documento_id' => $factura['id'], 'linea' => 0, 'monto' => '60000']);
        $url = '/api/finanzas/costos-comex/'.$c['costo']['id'].'/transiciones';
        $this->postJson($url, $this->solicitud($c, 'imputar', ['documento_id' => $credito['id'], 'linea' => 0, 'monto' => '60001']))->assertUnprocessable();
        $c = $this->paso($c, 'imputar', ['documento_id' => $credito['id'], 'linea' => 0, 'monto' => '20000']);
        $this->assertSame('40000', $c['real_vigente_clp']);
        $this->postJson($url, $this->solicitud($c, 'retirar_imputacion', ['imputacion_id' => collect($c['imputaciones'])->firstWhere('signo', 1)['id']]))->assertUnprocessable();
    }

    public function test_cierre_congela_real_y_reverso_posterior_genera_diferencia_sin_reescribirlo(): void
    {
        $doc = $this->documento();
        $c = $this->paso($this->costo(), 'imputar', ['documento_id' => $doc['id'], 'linea' => 0, 'monto' => '100000']);
        $c = $this->paso($c, 'cerrar');
        $this->postJson('/api/finanzas/proveedores/documentos/'.$doc['id'].'/anular', ['fecha_contable' => '2026-10-03', 'motivo' => 'Reversión documentada del registro de prueba'])->assertOk();
        $r = $this->getJson('/api/finanzas/costos-comex/'.$c['costo']['id'])->assertOk()->assertJsonPath('data.real_cierre_clp', '100000')->assertJsonPath('data.real_vigente_clp', '0')->json('data');
        $this->assertNotEmpty($r['alertas']);
        $this->assertTrue($r['imputaciones'][0]['activa']);
    }

    public function test_cambio_comex_se_detecta_y_actualizar_requiere_version_y_respaldo(): void
    {
        $c = $this->costo();
        Booking::query()->findOrFail($this->booking)->update(['estado' => 'cerrado', 'version' => 2]);
        $r = $this->getJson('/api/finanzas/costos-comex/'.$c['costo']['id'])->assertOk()->json('data');
        $this->assertNotEmpty($r['alertas']);
        $this->putJson('/api/finanzas/costos-comex/'.$c['costo']['id'], [...$this->datos, 'version' => 0, 'operacion_id' => (string) Str::uuid(), 'motivo' => 'Actualizar fuente observada'])->assertUnprocessable();
        $this->putJson('/api/finanzas/costos-comex/'.$c['costo']['id'], [...$this->datos, 'version' => 1, 'operacion_id' => (string) Str::uuid(), 'motivo' => 'Actualizar fuente observada'])->assertOk()->assertJsonCount(0, 'data.alertas');
    }

    public function test_solo_lee_comex_y_operacion_y_necesita_ambos_accesos(): void
    {
        $sqls = [];
        DB::listen(function (QueryExecuted $q) use (&$sqls): void {
            if ($q->connectionName === 'comex' || $q->connectionName === config('database.default')) {
                $sqls[] = $q->sql;
            }
        });
        $this->costo();
        foreach ($sqls as $sql) {
            $this->assertMatchesRegularExpression('/^select\s/i', trim($sql));
        }
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['finanzas.proveedores']));
        $this->getJson('/api/finanzas/costos-comex')->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['comex.comercial']));
        $this->getJson('/api/finanzas/costos-comex')->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['comex.comercial', 'finanzas.proveedores']));
        $this->getJson('/api/finanzas/costos-comex')->assertOk()->assertJsonPath('puede_gestionar', false);
        $this->postJson('/api/finanzas/costos-comex', $this->datos)->assertForbidden();
    }

    public function test_sesion_lectora_comex_rechaza_escritura_incluso_sin_filas_afectadas(): void
    {
        $lector = DB::connection('comex_lectura');
        try {
            $lector->transaction(fn () => $lector->statement('UPDATE cx_bookings SET version = version WHERE 1 = 0'));
            $this->fail('La sesión lectora no debe permitir UPDATE.');
        } catch (QueryException $e) {
            $this->assertSame(1792, $e->errorInfo[1]);
        }
    }
}
