<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoEnvaseRomana;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogoRepartoEnvasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogo_tiene_orden_de_rc02_y_nombres_historicos_sin_cambiar_codigos(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $data = $this->actingAs($usuario, 'sanctum')->getJson('/api/envases/catalogo')->assertOk()->json('data');
        $this->assertSame(['bins', 'esponjas', 'esponja_tote', 'totes', 'smartpick', 'caja_3_4', 'pallet_cosechero'], array_column($data, 'codigo'));
        $this->assertSame(range(1, 7), array_column($data, 'orden'));
        $this->assertSame('Esponja de bins', $data[1]['nombre']);
        $this->assertSame([true, false, false, true, true, true, false], array_column($data, 'contiene_fruta'));
        $this->getJson('/api/administracion/reparto-envases')->assertForbidden();
    }

    public function test_cereza_precarga_referencias_y_sugerencia_y_admin_rechaza_accesorios(): void
    {
        $especie = EspecieValidacion::create(['temporada_id' => Temporada::where('activa', true)->firstOrFail()->id, 'nombre' => 'Cereza', 'activo' => true]);
        $uva = EspecieValidacion::create(['temporada_id' => $especie->temporada_id, 'nombre' => 'Uva', 'activo' => true]);
        $this->assertDatabaseHas('pesos_referencia_envases', ['especie_validacion_id' => $especie->id, 'tipo_envase' => 'bins', 'peso_referencia' => 210]);
        $this->assertDatabaseHas('pesos_referencia_envases', ['especie_validacion_id' => $especie->id, 'tipo_envase' => 'totes', 'peso_referencia' => 8.75]);
        $this->assertDatabaseMissing('pesos_referencia_envases', ['especie_validacion_id' => $uva->id]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/administracion/reparto-envases')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson('/api/administracion/reparto-envases/'.$especie->id, ['sugerido' => 'esponjas', 'referencias' => []])->assertUnprocessable();
        $this->putJson('/api/administracion/reparto-envases/'.$especie->id, ['sugerido' => 'bins', 'referencias' => [['tipo_envase' => 'pallet_cosechero', 'peso_referencia' => 20]]])->assertUnprocessable();
    }

    public function test_siete_tipos_se_validan_y_lotizan_y_totes_incompletos_reciben_kilos_proporcionales(): void
    {
        $cantidades = array_fill_keys(array_column(TipoEnvaseRomana::cases(), 'value'), 2);
        $cantidades['totes'] = 42;
        $primero = array_fill_keys(array_keys($cantidades), 1);
        $primero['totes'] = 18;
        $c = $this->preparar($cantidades, $primero);
        $respuesta = $this->cerrar($c, 'mismos')->assertOk();
        $respuesta->assertJsonPath('data.tipo_envase_calculo_neto', 'totes')->assertJsonPath('data.reparto_neto_envases.0.cantidad', 42);
        [$uno, $dos] = $this->lotizar($c);
        $this->assertCount(7, $uno->envasesDetalle);
        $this->assertCount(7, $dos->envasesDetalle);
        $this->assertSame('bins', $uno->envase_primario->value);
        $this->assertGreaterThan((float) $uno->kilos_netos_calculados, (float) $dos->kilos_netos_calculados);
        $this->assertSame(9000000, (int) round(((float) $uno->kilos_netos_calculados + (float) $dos->kilos_netos_calculados) * 1000));
        $saldo = DB::table('movimientos_envases')->where('recepcion_romana_id', $c['recepcion']->id)->get()->sum(fn ($m) => $m->cantidad * $m->signo_cuenta);
        $this->assertSame(0, $saldo);
    }

    public function test_reparto_mixto_conserva_referencias_y_ultimo_lote_completa_neto_exacto(): void
    {
        $c = $this->preparar(['bins' => 2, 'caja_3_4' => 6], ['bins' => 1, 'caja_3_4' => 2]);
        $this->referencias($c, 10);
        $this->cerrar($c, 'vacio', ['envases_reparto' => ['bins', 'caja_3_4']])->assertOk();
        $neto = (float) $c['recepcion']->refresh()->peso_neto;
        $this->referencias($c, 100); // Editar el maestro no cambia un destare emitido.
        [$uno, $dos] = $this->lotizar($c);
        $this->assertEqualsWithDelta(round($neto * 230 / 480, 3), (float) $uno->kilos_netos_calculados, 0.0001);
        $this->assertSame((int) round($neto * 1000), (int) round(((float) $uno->kilos_netos_calculados + (float) $dos->kilos_netos_calculados) * 1000));
        $this->assertEquals(10, $c['recepcion']->reparto_neto_envases[1]['peso_referencia']);
    }

    public function test_reparto_mixto_sin_referencia_o_por_accesorios_rechaza_destare_sin_escrituras(): void
    {
        $c = $this->preparar(['bins' => 2, 'caja_3_4' => 2, 'esponjas' => 2, 'pallet_cosechero' => 2]);
        $this->cerrar($c, 'vacio', ['envases_reparto' => ['bins', 'caja_3_4']])->assertUnprocessable()->assertJsonValidationErrors('envases_reparto');
        foreach (['esponjas', 'pallet_cosechero'] as $tipo) {
            $this->cerrar($c, 'vacio', ['envases_reparto' => [$tipo]])->assertUnprocessable();
        }
        $this->assertSame('en_bascula_salida', $c['recepcion']->refresh()->estado->value);
        $this->assertNull($c['recepcion']->reparto_neto_envases);
        $this->assertDatabaseCount('salidas_envases_recepcion_romana', 0);
    }

    public function test_tara_descuenta_todos_los_accesorios_y_solo_la_diferencia_de_pallets(): void
    {
        foreach (['vacio' => 8869.8, 'diferentes' => 8919.8] as $modo => $neto) {
            $c = $this->preparar(['bins' => 2, 'esponjas' => 2, 'pallet_cosechero' => 2]);
            $extras = ['envases_reparto' => ['bins'], 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'esponjas', 'tara_unitaria' => 0.1], ['tipo_envase' => 'pallet_cosechero', 'tara_unitaria' => 25]]];
            if ($modo === 'diferentes') {
                $extras['salida_envases'] = [['tipo_envase' => 'bins', 'cantidad' => 0], ['tipo_envase' => 'esponjas', 'cantidad' => 0], ['tipo_envase' => 'pallet_cosechero', 'cantidad' => 2]];
            }
            $this->cerrar($c, $modo, $extras)->assertOk()->assertJsonPath('data.peso_neto', $neto);
            $this->assertEqualsWithDelta($neto / 2, (float) $c['recepcion']->refresh()->peso_neto_por_envase, 0.0005);
        }
    }

    private function referencias(array $c, float $caja): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/administracion/reparto-envases/'.$c['especie']->id, ['sugerido' => 'bins',
            'referencias' => [['tipo_envase' => 'bins', 'peso_referencia' => 210], ['tipo_envase' => 'caja_3_4', 'peso_referencia' => $caja]]])->assertOk();
    }

    private function preparar(array $cantidades, ?array $primero = null): array
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
        $this->postJson('/api/romana/recepciones/'.$id.'/confirmar-ingreso', ['operacion_id' => (string) Str::uuid()])->assertOk();
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
        $resultado = $this->postJson('/api/validacion-mp/validaciones/'.$validacion.'/confirmar', ['operacion_id' => (string) Str::uuid(),
            'envases' => array_map(fn ($e): array => ['tipo_envase' => $e['tipo_envase'], 'cantidad_validada' => $e['cantidad']], $envases),
            'tarjas_verificadas' => true, 'requiere_segregacion' => true, 'segmentos' => $segmentos])->assertOk()->json('data.segmentos');

        return ['recepcion' => RecepcionRomana::findOrFail($id), 'operador' => $operador, 'cantidades' => $cantidades, 'segmentos' => $resultado, 'especie' => $especie];
    }

    private function cerrar(array $c, string $modo, array $extras = [])
    {
        return $this->actingAs($c['operador'], 'sanctum')->postJson('/api/romana/recepciones/'.$c['recepcion']->id.'/cerrar', [
            'operacion_id' => (string) Str::uuid(), 'peso_tara' => 1000, 'modo_salida_envases' => $modo,
            'numero_guia_salida' => $modo === 'vacio' ? null : 'GS-1',
            'taras_envases' => array_map(fn ($tipo): array => ['tipo_envase' => $tipo, 'tara_unitaria' => 0.1], array_keys($c['cantidades'])), ...$extras]);
    }

    private function lotizar(array $c): array
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
