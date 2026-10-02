<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class CorreccionPropiedadEnvasesTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    public function test_administrador_corrige_todas_las_lineas_y_conserva_historial_y_existencia(): void
    {
        [$recepcion, $ingresos, $cliente, $temporada] = $this->recepcionValidada();
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $ruta = $this->ruta($ingresos[0]);

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->postJson($ruta, ['concepto_envases' => 'arriendo', 'motivo' => 'La guía acredita que son envases arrendados.'])
            ->assertForbidden();
        $this->actingAs($administrador, 'sanctum')->postJson($ruta, [
            'concepto_envases' => 'arriendo', 'motivo' => 'La guía acredita que son envases arrendados.',
        ])->assertOk();

        $this->assertSame('arriendo', RecepcionRomana::findOrFail($recepcion['id'])->concepto_envases->value);
        $this->assertDatabaseCount('movimientos_envases', 6);
        foreach ($ingresos as $ingreso) {
            $this->assertDatabaseHas('movimientos_envases', [
                'id' => $ingreso->id, 'propiedad' => 'propia', 'signo_existencia' => 1,
            ]);
            $this->assertDatabaseHas('movimientos_envases', [
                'movimiento_origen_id' => $ingreso->id, 'tipo_movimiento' => 'correccion_propiedad',
                'signo_existencia' => -1, 'signo_cuenta' => 0, 'propiedad' => 'propia',
            ]);
            $this->assertDatabaseHas('movimientos_envases', [
                'recepcion_romana_id' => $recepcion['id'], 'tipo_movimiento' => 'recepcion_arriendo',
                'tipo_envase' => $ingreso->tipo_envase->value, 'cantidad' => $ingreso->cantidad,
                'signo_existencia' => 1, 'signo_cuenta' => 1, 'propiedad' => 'arrendada',
            ]);
        }
        $this->assertSame(50, (int) MovimientoEnvase::query()->where('cliente_id', $cliente->id)
            ->selectRaw('SUM(CAST(cantidad AS SIGNED) * signo_cuenta) as saldo')->value('saldo'));
        $inventario = app(ServicioGuiaDespachoEnvases::class)->inventario($temporada);
        $this->assertSame(0, collect($inventario['origenes'])->where('propiedad', 'propia')->sum('fisico'));
        $this->assertSame(50, collect($inventario['origenes'])->where('propiedad', 'arrendada')->sum('fisico'));
        $balances = $this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$cliente->id)
            ->assertOk()->json('balances');
        $this->assertCount(2, $balances);
        $this->assertSame(50, collect($balances)->sum('saldo'));

        $this->postJson($ruta, [
            'concepto_envases' => 'arriendo', 'motivo' => 'Reintento de la misma corrección.',
        ])->assertConflict();
        $this->assertDatabaseCount('movimientos_envases', 6);
    }

    public function test_bloquea_la_correccion_si_un_origen_tiene_una_guia_en_borrador(): void
    {
        [$recepcion, $ingresos, $cliente] = $this->recepcionValidada();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->actingAs($operador, 'sanctum')->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'salida_at' => now()->toAtomString(),
            'detalles' => [[
                'tipo_envase' => 'bins', 'cantidad' => 1, 'propiedad' => 'propia',
                'movimiento_origen_id' => $ingresos[0]->id,
            ]],
        ])->assertCreated();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->postJson($this->ruta($ingresos[0]), [
                'concepto_envases' => 'arriendo', 'motivo' => 'La guía original registra arriendo.',
            ])->assertConflict();
        $this->assertSame('compra', RecepcionRomana::findOrFail($recepcion['id'])->concepto_envases->value);
        $this->assertDatabaseCount('movimientos_envases', 2);
    }

    /** @return array{array<string, mixed>, array<int, MovimientoEnvase>, Cliente, Temporada} */
    private function recepcionValidada(): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'CLI-CORR', 'nombre' => 'Cliente corregido', 'activo' => true]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'solo_envases',
            'concepto_envases' => 'compra',
            'fecha_ingreso' => now(config('app.operational_timezone'))->toDateString(),
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 20],
                ['tipo_envase' => 'totes', 'cantidad' => 30],
            ],
            'numero_guia_despacho' => 'GUIA-CORR-001',
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor Prueba',
        ])->assertCreated()->json('data');
        $validacion = $this->actingAs($validador, 'sanctum')->postJson(
            '/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar',
            ['operacion_id' => (string) Str::uuid()],
        )->assertOk()->json('data');
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', $this->payloadConInspeccionRc02('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad_validada' => 20],
                ['tipo_envase' => 'totes', 'cantidad_validada' => 30],
            ],
        ]))->assertOk();

        return [$recepcion, MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])
            ->orderBy('tipo_envase')->get()->all(), $cliente, $temporada];
    }

    private function ruta(MovimientoEnvase $ingreso): string
    {
        return '/api/envases/cuenta-corriente/movimientos/'.$ingreso->id.'/corregir-propiedad';
    }
}
