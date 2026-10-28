<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\MovimientoEnvase;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Romana\GeneradorAvisoReciboPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionesEnvases;
use Tests\TestCase;

class AnulacionRecepcionRomanaTest extends TestCase
{
    use PreparaCorreccionesEnvases, RefreshDatabase, \Tests\Concerns\PreparaRecepcionEnvases;

    public function test_anula_documental_cerrada_pendiente_y_la_retira_de_las_bandejas(): void
    {
        [$recepcion, , $cliente, $temporada] = $this->ingresoEnvases(false);
        $ruta = '/api/romana/recepciones/'.$recepcion->id;
        $this->postJson($ruta.'/confirmar-ingreso', ['operacion_id' => (string) Str::uuid()])->assertOk();
        $this->postJson($ruta.'/cerrar', ['operacion_id' => (string) Str::uuid()])->assertOk();
        $datos = $this->datos($recepcion->refresh()->version);
        $this->postJson($ruta.'/anular', $datos)->assertOk()->assertJsonPath('data.estado', 'anulada')
            ->assertJsonPath('data.puede_editar', false)->assertJsonPath('data.correccion_administrativa_disponible', false);
        $this->postJson($ruta.'/anular', $datos)->assertOk();
        $this->getJson('/api/romana/recepciones')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/romana/recepciones?estado=anulada')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/validacion-mp/pendientes')->assertJsonCount(0, 'data');
        $this->postJson('/api/validacion-mp/recepciones/'.$recepcion->id.'/tomar', ['operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$cliente->id)->assertJsonCount(0, 'pendientes');
        $this->assertDatabaseHas('eventos_recepcion_romana', ['operacion_id' => $datos['operacion_id'], 'tipo' => 'recepcion_anulada']);
        $this->assertDatabaseCount('recepciones_romana', 1);
        $this->assertDatabaseCount('movimientos_envases', 0);
        $this->assertSame(0, app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($cliente, $temporada, null, null)['lineas_pendientes_validacion']);
        $pdf = app(GeneradorAvisoReciboPdf::class)->generar($recepcion->refresh());
        $this->assertStringContainsString('ANULADA', $pdf);
    }

    public function test_anula_ingreso_validado_con_reversa_unica_y_el_pdf_no_lo_suma(): void
    {
        [$r, $m, $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $ruta = '/api/romana/recepciones/'.$r->id.'/anular';
        $datos = $this->datos($r->version);
        $this->postJson($ruta, $datos)->assertOk();
        $this->postJson($ruta, $datos)->assertOk();
        $this->postJson($ruta, [...$datos, 'motivo' => 'Otro motivo con la misma operación'])->assertConflict();
        $this->postJson($ruta, [...$datos, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(0, $this->saldo('signo_cuenta', $cliente));
        $this->assertSame(0, $this->saldo('signo_existencia', $cliente));
        $this->assertSame(138, $m->refresh()->cantidad);
        $this->assertDatabaseCount('movimientos_envases', 2);
        $reversa = MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->sole();
        $this->assertSame($m->id, $reversa->movimiento_origen_id);
        $this->assertSame($admin->id, $reversa->creado_por_user_id);
        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($cliente, $temporada, null, null);
        $this->assertSame([], $estado['movimientos']);
    }

    public function test_exige_administrador_motivo_version_y_temporada_activa(): void
    {
        [$r, , , $temporada, $admin] = $this->ingresoEnvases(false);
        $ruta = '/api/romana/recepciones/'.$r->id.'/anular';
        $datos = $this->datos($r->version);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')->postJson($ruta, $datos)->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson($ruta, [...$datos, 'motivo' => ' '])->assertUnprocessable();
        $this->postJson($ruta, [...$datos, 'version_conocida' => 999])->assertConflict();
        $temporada->update(['activa' => false]);
        $this->postJson($ruta, $datos)->assertConflict();
        $this->assertSame('en_bascula_ingreso', $r->refresh()->estado->value);
    }

    public function test_no_anula_una_validacion_en_curso_ni_un_ingreso_reservado(): void
    {
        [$r] = $this->ingresoEnvases(false);
        $this->postJson('/api/validacion-mp/recepciones/'.$r->id.'/tomar', ['operacion_id' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/romana/recepciones/'.$r->id.'/anular', $this->datos($r->refresh()->version))->assertConflict();
        [$r, $m, $cliente] = $this->ingresoEnvases();
        $this->postJson('/api/envases/guias-despacho', ['operacion_id' => (string) Str::uuid(), 'cliente_id' => $cliente->id,
            'salida_at' => now()->toAtomString(), 'detalles' => [['tipo_envase' => 'bins', 'cantidad' => 1,
                'propiedad' => 'arrendada', 'movimiento_origen_id' => $m->id]]])->assertCreated();
        $this->postJson('/api/romana/recepciones/'.$r->id.'/anular', $this->datos($r->refresh()->version))->assertConflict();
        $this->assertSame(138, $this->saldo('signo_existencia', $cliente));
    }

    public function test_los_segmentos_de_fruta_bloquean_la_anulacion_antes_de_lotizar(): void
    {
        $contexto = $this->preparar(['bins' => 4]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $r = $contexto['recepcion']->refresh();
        $this->actingAs($admin, 'sanctum')->postJson('/api/romana/recepciones/'.$r->id.'/anular', $this->datos($r->version))
            ->assertConflict()->assertJsonPath('message', 'La recepción tiene segmentos o lotes de materia prima vinculados. Requiere conciliación antes de anular.');
        $this->assertNotSame('anulada', $r->refresh()->estado->value);
    }

    private function datos(int $version): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $version, 'motivo' => 'Recepción de prueba ingresada por equivocación.'];
    }
}
