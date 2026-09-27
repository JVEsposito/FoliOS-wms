<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorreccionCantidadEnvasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_corrige_140_a_104_y_actualiza_saldo_existencia_y_validacion_con_traza(): void
    {
        [$recepcion, $ingreso, $cliente, $temporada] = $this->recepcionValidada();
        $ruta = $this->ruta($ingreso);
        $datos = ['cantidad_correcta' => 104, 'motivo' => 'Se transcribieron 140 bins y la guía indica 104.'];

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->postJson($ruta, $datos)->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->postJson($ruta, $datos)->assertOk();

        $this->assertDatabaseHas('movimientos_envases', [
            'id' => $ingreso->id, 'cantidad' => 140, 'signo_existencia' => 1,
        ]);
        $this->assertDatabaseHas('movimientos_envases', [
            'movimiento_origen_id' => $ingreso->id, 'tipo_movimiento' => 'correccion_cantidad',
            'cantidad' => 36, 'signo_existencia' => -1, 'signo_cuenta' => -1,
        ]);
        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $recepcion['id'], 'tipo_envase' => 'bins',
            'cantidad_declarada' => 104, 'cantidad_validada' => 104,
        ]);
        $this->assertSame(104, RecepcionRomana::findOrFail($recepcion['id'])->cantidad_envases_declarados);
        $this->assertSame(104, (int) MovimientoEnvase::query()->where('cliente_id', $cliente->id)
            ->selectRaw('SUM(CAST(cantidad AS SIGNED) * signo_cuenta) as saldo')->value('saldo'));
        $this->assertSame(104, collect(app(ServicioGuiaDespachoEnvases::class)->inventario($temporada)['origenes'])->sum('fisico'));
        $movimientos = $this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$cliente->id)
            ->assertOk()->json('data');
        $this->assertTrue(collect($movimientos)->firstWhere('id', $ingreso->id)['cantidad_corregida']);
        $this->assertSame($datos['motivo'], collect($movimientos)->firstWhere('tipo_movimiento', 'correccion_cantidad')['correccion_cantidad']['motivo']);

        $this->postJson($ruta, $datos)->assertConflict();
        $this->assertDatabaseCount('movimientos_envases', 2);
    }

    public function test_bloquea_cantidad_invalida_y_reserva_de_guia(): void
    {
        [$recepcion, $ingreso, $cliente] = $this->recepcionValidada();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->postJson($this->ruta($ingreso), ['cantidad_correcta' => 140, 'motivo' => 'Error de digitación en recepción.'])
            ->assertConflict();
        $this->postJson($this->ruta($ingreso), ['cantidad_correcta' => 0, 'motivo' => 'Error de digitación en recepción.'])
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->postJson('/api/envases/guias-despacho', [
                'operacion_id' => (string) Str::uuid(),
                'cliente_id' => $cliente->id,
                'salida_at' => now()->toAtomString(),
                'detalles' => [[
                    'tipo_envase' => 'bins', 'cantidad' => 1, 'propiedad' => 'arrendada',
                    'movimiento_origen_id' => $ingreso->id,
                ]],
            ])->assertCreated();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->postJson($this->ruta($ingreso), ['cantidad_correcta' => 104, 'motivo' => 'Error de digitación en recepción.'])
            ->assertConflict();
        $this->assertSame(140, DetalleEnvaseRecepcionRomana::query()->where('recepcion_romana_id', $recepcion['id'])->firstOrFail()->cantidad_validada);
    }

    public function test_ingreso_propio_corrige_existencia_sin_modificar_cuenta_del_cliente(): void
    {
        [, $ingreso, $cliente, $temporada] = $this->recepcionValidada('compra');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->postJson($this->ruta($ingreso), [
                'cantidad_correcta' => 104, 'motivo' => 'Se digitó erróneamente la cantidad de bins propios.',
            ])->assertOk();
        $this->assertDatabaseHas('movimientos_envases', [
            'movimiento_origen_id' => $ingreso->id, 'tipo_movimiento' => 'correccion_cantidad',
            'cantidad' => 36, 'signo_cuenta' => 0, 'signo_existencia' => -1,
        ]);
        $this->assertSame(0, (int) MovimientoEnvase::query()->where('cliente_id', $cliente->id)
            ->selectRaw('SUM(CAST(cantidad AS SIGNED) * signo_cuenta) as saldo')->value('saldo'));
        $this->assertSame(104, collect(app(ServicioGuiaDespachoEnvases::class)->inventario($temporada)['origenes'])->sum('fisico'));
    }

    /** @return array{array<string, mixed>, MovimientoEnvase, Cliente, Temporada} */
    private function recepcionValidada(string $concepto = 'arriendo'): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'CLI-CANT', 'nombre' => 'Cliente cantidad', 'activo' => true]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'solo_envases',
            'concepto_envases' => $concepto,
            'fecha_ingreso' => now(config('app.operational_timezone'))->toDateString(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 140]],
            'numero_guia_despacho' => 'GUIA-CANT-001',
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor Prueba',
        ])->assertCreated()->json('data');
        $validacion = $this->actingAs($validador, 'sanctum')->postJson(
            '/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar',
            ['operacion_id' => (string) Str::uuid()],
        )->assertOk()->json('data');
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 140]],
        ])->assertOk();

        return [$recepcion, MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])->firstOrFail(), $cliente, $temporada];
    }

    private function ruta(MovimientoEnvase $ingreso): string
    {
        return '/api/envases/cuenta-corriente/movimientos/'.$ingreso->id.'/corregir-cantidad';
    }
}
