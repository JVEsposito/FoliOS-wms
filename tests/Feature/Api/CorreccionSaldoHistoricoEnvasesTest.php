<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\MovimientoEnvase;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionesEnvases;
use Tests\TestCase;

class CorreccionSaldoHistoricoEnvasesTest extends TestCase
{
    use PreparaCorreccionesEnvases, RefreshDatabase;

    public function test_corrige_mas_138_a_menos_138_sin_tocar_existencia_y_actualiza_informes(): void
    {
        [$r, $m, $cliente, $temporada, $admin] = $this->ingresoEnvases();
        MovimientoEnvase::create(['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id, 'documento_tipo' => 'ajuste_cuenta_envases', 'numero_documento' => 'Entregas previas',
            'tipo_movimiento' => 'ajuste_cuenta', 'tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => 444,
            'signo_cuenta' => -1, 'signo_existencia' => 0, 'ocurrido_at' => now()->addSecond(), 'estado_revision' => 'pendiente', 'creado_por_user_id' => $admin->id]);
        $fisico = $this->saldo('signo_existencia', $cliente);
        $datos = $this->datos();
        $this->postJson($this->ruta($m), $datos)->assertCreated()->assertJsonPath('data.impacto_cuenta', -276)->assertJsonPath('data.impacto_existencia', 0);
        $this->postJson($this->ruta($m), $datos)->assertCreated();
        $this->assertSame(-582, $this->saldo('signo_cuenta', $cliente));
        $this->assertSame($fisico, $this->saldo('signo_existencia', $cliente));
        $this->assertSame(138, $m->refresh()->cantidad);
        $this->assertSame(1, $m->signo_cuenta);
        $this->assertSame(138, $r->refresh()->cantidad_envases_declarados);
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $estado = $cuenta->estadoCuenta($cliente, $temporada, null, null);
        $this->assertSame(-582, $estado['tipos'][0]['saldo_final']);
        $this->assertSame(138, $estado['movimientos'][0]['salida']);
        $this->assertSame('Saldo anterior corregido', $estado['movimientos'][0]['movimiento']);
        $this->assertSame(2, count($estado['movimientos']));
        $kardex = collect($cuenta->kardex(['temporada_id' => $temporada->id, 'cliente_id' => $cliente->id]));
        $this->assertSame(-276, $kardex->firstWhere('movimiento', 'Corrección de saldo anterior')['impacto_cuenta']);
        $this->assertSame($datos['motivo'], $kardex->firstWhere('movimiento', 'Corrección de saldo anterior')['motivo_correccion']);
        $filas = $this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$cliente->id)->assertOk()->json('data');
        $this->assertSame(-138, collect($filas)->firstWhere('id', $m->id)['impacto_cuenta_vigente']);
        $this->getJson('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$cliente->id.'&temporada_id='.$temporada->id)->assertOk();
        $this->postJson('/api/romana/recepciones/'.$r->id.'/anular', ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $r->version, 'motivo' => 'No duplicar el efecto de una corrección anterior.'])->assertConflict();
        $this->assertDatabaseCount('movimientos_envases', 3);
    }

    public function test_exige_admin_motivo_y_confirmacion_de_saldo_sin_movimiento_fisico(): void
    {
        [, $m, , , $admin] = $this->ingresoEnvases();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')->postJson($this->ruta($m), $this->datos())->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson($this->ruta($m), $this->datos(['es_saldo_historico' => false]))->assertUnprocessable();
        $this->postJson($this->ruta($m), $this->datos(['motivo' => '']))->assertUnprocessable();
        $this->postJson($this->ruta($m), $this->datos(['impacto_conocido' => 999]))->assertConflict();
        $this->postJson($this->ruta($m), $this->datos(['saldo_correcto' => 138]))->assertConflict();
        $this->assertDatabaseCount('movimientos_envases', 1);
    }

    public function test_rechaza_reintento_modificado_y_otra_pantalla_con_importe_antiguo(): void
    {
        [, $m] = $this->ingresoEnvases();
        $datos = $this->datos();
        $this->postJson($this->ruta($m), $datos)->assertCreated();
        $this->postJson($this->ruta($m), [...$datos, 'saldo_correcto' => -139])->assertConflict();
        $this->postJson($this->ruta($m), $this->datos())->assertConflict();
        $this->postJson($this->ruta($m), $this->datos(['impacto_conocido' => -138, 'saldo_correcto' => -140]))->assertCreated()->assertJsonPath('data.impacto_cuenta', -2);
        $this->assertDatabaseCount('movimientos_envases', 3);
    }

    public function test_no_corrige_asientos_anulados_o_temporadas_cerradas(): void
    {
        [$r, $m, , $temporada] = $this->ingresoEnvases();
        $this->postJson('/api/romana/recepciones/'.$r->id.'/anular', ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $r->version, 'motivo' => 'Recepción de prueba ingresada por equivocación.'])->assertOk();
        $this->postJson($this->ruta($m), $this->datos())->assertConflict();
        $temporada->update(['activa' => false]);
        $this->postJson($this->ruta($m), $this->datos())->assertConflict();
    }

    public function test_corrige_una_apertura_y_no_permite_revertir_el_importe_original(): void
    {
        [, , $cliente, $temporada, $admin] = $this->ingresoEnvases(false);
        $movimiento = $this->postJson('/api/envases/cuenta-corriente/ajustes', ['operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id, 'tipo_movimiento' => 'apertura_cuenta',
            'tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => 138,
            'fecha' => now(config('app.operational_timezone'))->toDateString(), 'referencia' => 'Saldo anterior',
            'motivo' => 'Registro de una deuda anterior de envases.'])->assertCreated()->json('data.id');
        $m = MovimientoEnvase::findOrFail($movimiento);
        $this->postJson($this->ruta($m), $this->datos())->assertCreated();
        $this->postJson('/api/envases/cuenta-corriente/movimientos/'.$m->id.'/revertir-ajuste', [
            'operacion_id' => (string) Str::uuid(), 'fecha' => now(config('app.operational_timezone'))->toDateString(),
            'motivo' => 'No revertir una apertura con saldo corregido.'])->assertConflict();
        $this->assertSame(-138, $this->saldo('signo_cuenta', $cliente));
        $this->assertSame(0, $this->saldo('signo_existencia', $cliente));
    }

    private function datos(array $extras = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'impacto_conocido' => 138, 'saldo_correcto' => -138,
            'es_saldo_historico' => true, 'motivo' => 'Saldo anterior adeudado por el cliente con signo equivocado.'], ...$extras];
    }

    private function ruta(MovimientoEnvase $m): string
    {
        return '/api/envases/cuenta-corriente/movimientos/'.$m->id.'/corregir-saldo-historico';
    }
}
