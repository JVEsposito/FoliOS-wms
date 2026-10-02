<?php

namespace Tests\Concerns;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Support\Str;

trait PreparaRecepcionEnvases
{
    protected function referencias(array $c, float $caja): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/administracion/reparto-envases/'.$c['especie']->id, ['sugerido' => 'bins',
            'referencias' => [['tipo_envase' => 'bins', 'peso_referencia' => 210], ['tipo_envase' => 'caja_3_4', 'peso_referencia' => $caja]]])->assertOk();
    }

    protected function preparar(array $cantidades, ?array $primero = null, bool $confirmar = true): array
    {
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'ENV-'.Str::random(8), 'nombre' => 'Exportadora', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina'], ['activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-'.Str::random(8), 'activo' => true]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $envases = collect($cantidades)->map(fn ($cantidad, $tipo): array => ['tipo_envase' => $tipo, 'cantidad' => $cantidad])->values()->all();
        $id = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', ['operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id, 'especie_validacion_id' => $especie->id,
            'tipo_recepcion' => 'fruta_con_envases', 'tipo_servicio' => 'proceso', 'envases' => $envases,
            'numero_guia_despacho' => 'GD-'.Str::uuid(), 'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor', 'peso_bruto' => 10000])->assertCreated()->json('data.id');
        $this->postJson('/api/romana/recepciones/'.$id.'/confirmar-ingreso', $this->payloadConInspeccionRc02('/api/romana/recepciones/'.$id.'/confirmar-ingreso', ['operacion_id' => (string) Str::uuid()]))->assertOk();
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $validacion = $this->actingAs($validador, 'sanctum')->postJson('/api/validacion-mp/recepciones/'.$id.'/tomar', ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data.id');
        $primero ??= array_fill_keys(array_keys($cantidades), 1);
        $segmentos = [];
        foreach ([$primero, array_map(fn ($tipo) => $cantidades[$tipo] - $primero[$tipo], array_keys($cantidades))] as $i => $conteo) {
            if ($i === 1) {
                $conteo = array_combine(array_keys($cantidades), $conteo);
            }
            $segmentos[] = ['motivos' => ['cuartel'], 'cuartel' => 'C'.($i + 1), 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => collect($conteo)->map(fn ($cantidad, $tipo): array => ['tipo_envase' => $tipo, 'cantidad' => $cantidad])->values()->all()];
        }
        if (! $confirmar) {
            return ['recepcion' => RecepcionRomana::findOrFail($id), 'operador' => $operador, 'validador' => $validador, 'validacion' => $validacion, 'cantidades' => $cantidades, 'especie' => $especie, 'mp' => ['operacion_id' => (string) Str::uuid(), 'envases' => array_map(fn ($e): array => ['tipo_envase' => $e['tipo_envase'], 'cantidad_validada' => $e['cantidad']], $envases), 'tarjas_verificadas' => true, 'requiere_segregacion' => true, 'segmentos' => $segmentos]];
        }
        $resultado = $this->postJson('/api/validacion-mp/validaciones/'.$validacion.'/confirmar', $this->payloadConInspeccionRc02('/api/validacion-mp/validaciones/'.$validacion.'/confirmar', ['operacion_id' => (string) Str::uuid(),
            'envases' => array_map(fn ($e): array => ['tipo_envase' => $e['tipo_envase'], 'cantidad_validada' => $e['cantidad']], $envases),
            'tarjas_verificadas' => true, 'requiere_segregacion' => true, 'segmentos' => $segmentos]))->assertOk()->json('data.segmentos');

        return ['recepcion' => RecepcionRomana::findOrFail($id), 'operador' => $operador, 'cantidades' => $cantidades, 'segmentos' => $resultado, 'especie' => $especie];
    }

    protected function cerrar(array $c, string $modo, array $extras = [])
    {
        return $this->actingAs($c['operador'], 'sanctum')->postJson('/api/romana/recepciones/'.$c['recepcion']->id.'/cerrar', $this->payloadConInspeccionRc02('/api/romana/recepciones/'.$c['recepcion']->id.'/cerrar', [
            'operacion_id' => (string) Str::uuid(), 'peso_tara' => 1000, 'modo_salida_envases' => $modo,
            'numero_guia_salida' => $modo === 'vacio' ? null : 'GS-1',
            'taras_envases' => array_map(fn ($tipo): array => ['tipo_envase' => $tipo, 'tara_unitaria' => 0.1], array_keys($c['cantidades'])), ...$extras]));
    }

    protected function lotizar(array $c): array
    {
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $lotes = [];
        foreach ($c['segmentos'] as $segmento) {
            $id = $this->actingAs($digitador, 'sanctum')->postJson('/api/materia-prima/lotes', ['operacion_id' => (string) Str::uuid(),
                'segmento_validacion_mp_id' => $segmento['id'], 'numero_lote' => 'L-'.Str::random(8), 'sdp' => '123',
                'fecha_cosecha' => now()->toDateString(), 'predio' => 'Fundo', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false])->assertCreated()->json('data.id');
            $lotes[] = LoteMateriaPrima::with('envasesDetalle')->findOrFail($id);
        }

        return $lotes;
    }
}
