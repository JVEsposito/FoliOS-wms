<?php

namespace Tests\Concerns;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Support\Str;

trait PreparaCorreccionesEnvases
{
    use PreparaInspeccionesEnvases;

    private function ingresoEnvases(bool $validar = true, int $cantidad = 138): array
    {
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'COR-'.Str::random(6), 'nombre' => 'La Aguada', 'activo' => true]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $r = $this->actingAs($admin, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'solo_envases', 'concepto_envases' => 'arriendo',
            'fecha_ingreso' => now(config('app.operational_timezone'))->toDateString(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => $cantidad]],
            'numero_guia_despacho' => '123', 'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor Prueba',
        ])->assertCreated()->json('data');
        $recepcion = RecepcionRomana::findOrFail($r['id']);
        if ($validar) {
            $v = $this->postJson('/api/validacion-mp/recepciones/'.$r['id'].'/tomar', ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data.id');
            $ruta = '/api/validacion-mp/validaciones/'.$v.'/confirmar';
            $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, ['operacion_id' => (string) Str::uuid(),
                'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => $cantidad]]]))->assertOk();
        }

        return [$recepcion->refresh(), $recepcion->movimientosEnvases()->first(), $cliente, $temporada, $admin];
    }

    private function saldo(string $columna, Cliente $cliente): int
    {
        return (int) MovimientoEnvase::where('cliente_id', $cliente->id)
            ->selectRaw('SUM(CAST(cantidad AS SIGNED) * '.$columna.') as saldo')->value('saldo');
    }
}
