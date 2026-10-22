<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AjustesCuentaEnvasesTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private Cliente $cliente;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Santiago'));
        $this->temporada = Temporada::where('activa', true)->firstOrFail();
        $this->temporada->update(['fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31']);
        $this->cliente = Cliente::create(['codigo' => 'CTA-ENV', 'nombre' => 'Cliente arriendo', 'activo' => true]);
        $this->actor = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->actor, 'sanctum');
    }

    public function test_apertura_negativa_es_idempotente_auditada_y_no_crea_existencia(): void
    {
        $datos = $this->datos(['tipo_movimiento' => 'apertura_cuenta', 'cantidad' => -30]);
        $id = $this->postJson($this->ruta(), $datos)->assertCreated()
            ->assertJsonPath('data.impacto_cuenta', -30)->assertJsonPath('data.impacto_existencia', 0)
            ->assertJsonPath('data.creado_por.nombre', $this->actor->name)->json('data.id');
        $this->postJson($this->ruta(), $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($this->ruta(), [...$datos, 'cantidad' => -31])->assertConflict();
        $this->assertSame(1, MovimientoEnvase::count());
        $this->assertSame(30, MovimientoEnvase::first()->cantidad);
        $this->assertSame(-1, MovimientoEnvase::first()->signo_cuenta);
        $this->assertSame($datos['motivo'], MovimientoEnvase::first()->datos['ajuste_cuenta']['motivo']);
        $this->getJson('/api/envases/cuenta-corriente/movimientos')->assertOk()->assertJsonPath('balances.0.saldo', -30);
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $estado = $cuenta->estadoCuenta($this->cliente, $this->temporada, null, null);
        $this->assertSame(-30, $estado['tipos'][0]['saldo_final']);
        $this->assertSame(30, $estado['movimientos'][0]['salida']);
        $this->assertNull($estado['movimientos'][0]['entrada']);
        $kardex = collect($cuenta->kardex(['temporada_id' => $this->temporada->id]))->first();
        $this->assertSame(-30, $kardex['impacto_cuenta']);
        $this->assertSame($datos['motivo'], $kardex['motivo_correccion']);
        $this->assertSame([], $cuenta->existencia($this->temporada->id)['tipos']);
    }

    public function test_los_ajustes_cruzan_cero_y_su_reversa_es_compensatoria_y_unica(): void
    {
        $this->postJson($this->ruta(), $this->datos(['cantidad' => 20]))->assertCreated();
        $id = $this->postJson($this->ruta(), $this->datos(['cantidad' => -50, 'fecha' => '2026-10-06']))->assertCreated()->json('data.id');
        $this->getJson('/api/envases/cuenta-corriente/movimientos')->assertJsonPath('balances.0.saldo', -30);
        $datos = ['operacion_id' => (string) Str::uuid(), 'fecha' => '2026-10-07', 'motivo' => 'Reversa de conciliación equivocada'];
        $ruta = '/api/envases/cuenta-corriente/movimientos/'.$id.'/revertir-ajuste';
        $reversa = $this->postJson($ruta, $datos)->assertCreated()->assertJsonPath('data.impacto_cuenta', 50)
            ->assertJsonPath('data.movimiento_origen_id', $id)->json('data.id');
        $this->postJson($ruta, $datos)->assertCreated()->assertJsonPath('data.id', $reversa);
        $this->postJson($ruta, [...$datos, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/envases/cuenta-corriente/movimientos/'.$reversa.'/revertir-ajuste', [...$datos, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/envases/cuenta-corriente/movimientos')->assertJsonPath('balances.0.saldo', 20);
        $this->assertSame(3, MovimientoEnvase::count());
        $this->assertSame(50, MovimientoEnvase::findOrFail($id)->cantidad);
        $this->assertSame(0, (int) MovimientoEnvase::sum('signo_existencia'));
        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->cliente, $this->temporada, '2026-10-06', '2026-10-06');
        $this->assertSame(-30, $estado['tipos'][0]['saldo_final']);
        $this->assertCount(1, $estado['movimientos']);
        $this->getJson('/api/envases/cuenta-corriente/movimientos')->assertJsonPath('data.1.puede_revertir_ajuste', false);
    }

    public function test_apertura_es_unica_por_cuenta_y_debe_preceder_sus_movimientos(): void
    {
        $this->postJson($this->ruta(), $this->datos())->assertCreated();
        $this->postJson($this->ruta(), $this->datos(['tipo_movimiento' => 'apertura_cuenta', 'fecha' => '2026-10-06']))->assertConflict();
        $this->postJson($this->ruta(), $this->datos(['tipo_movimiento' => 'apertura_cuenta', 'fecha' => '2026-10-01']))->assertCreated();
        $this->postJson($this->ruta(), $this->datos(['tipo_movimiento' => 'apertura_cuenta', 'fecha' => '2026-10-01']))->assertConflict();
        $this->postJson($this->ruta(), $this->datos(['tipo_movimiento' => 'apertura_cuenta', 'fecha' => '2026-10-01', 'propiedad' => 'propia']))->assertCreated();
    }

    public function test_valida_cantidades_fechas_y_temporada_sin_escribir_asientos(): void
    {
        foreach ([0, '1.5', '-100000001'] as $cantidad) {
            $this->postJson($this->ruta(), $this->datos(['cantidad' => $cantidad]))->assertUnprocessable()->assertJsonValidationErrors('cantidad');
        }
        foreach (['2026-10-22', '2025-12-31'] as $fecha) {
            $this->postJson($this->ruta(), $this->datos(['fecha' => $fecha]))->assertUnprocessable()->assertJsonValidationErrors('fecha');
        }
        $otra = Temporada::create(['codigo' => 'OTRA-CTA', 'nombre' => 'Otra temporada', 'activa' => false]);
        $this->postJson($this->ruta(), $this->datos(['temporada_id' => $otra->id]))->assertConflict();
        $this->assertSame(0, MovimientoEnvase::count());
    }

    public function test_reversa_no_puede_anticipar_el_asiento_y_la_apertura_revertida_sigue_en_el_historial(): void
    {
        $id = $this->postJson($this->ruta(), $this->datos(['tipo_movimiento' => 'apertura_cuenta']))->assertCreated()->json('data.id');
        $ruta = '/api/envases/cuenta-corriente/movimientos/'.$id.'/revertir-ajuste';
        $datos = ['operacion_id' => (string) Str::uuid(), 'fecha' => '2026-10-04', 'motivo' => 'Corrección del saldo inicial'];
        $this->postJson($ruta, $datos)->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $this->postJson($ruta, [...$datos, 'fecha' => '2026-10-05'])->assertCreated();
        $this->postJson($this->ruta(), $this->datos(['tipo_movimiento' => 'apertura_cuenta']))->assertConflict();
        $this->assertSame(2, MovimientoEnvase::count());
    }

    public function test_consulta_y_revision_no_autorizan_ajustar_cuentas(): void
    {
        $id = $this->postJson($this->ruta(), $this->datos())->assertCreated()->json('data.id');
        foreach ([RolUsuario::Consulta, RolUsuario::OperadorRomana, RolUsuario::SupervisorMateriales] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum');
            $this->postJson($this->ruta(), $this->datos())->assertForbidden();
            $this->postJson('/api/envases/cuenta-corriente/movimientos/'.$id.'/revertir-ajuste', [])->assertForbidden();
        }
        $this->assertSame(1, MovimientoEnvase::count());
    }

    private function ruta(): string
    {
        return '/api/envases/cuenta-corriente/ajustes';
    }

    private function datos(array $cambios = []): array
    {
        return [...[
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
            'tipo_movimiento' => 'ajuste_cuenta', 'tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => -30,
            'fecha' => '2026-10-05', 'referencia' => 'ACUERDO-001', 'motivo' => 'Conciliación de la obligación de envases',
        ], ...$cambios];
    }
}
