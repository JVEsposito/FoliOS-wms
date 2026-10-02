<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoEnvaseRomana;
use App\Models\EspecieValidacion;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class CatalogoRepartoEnvasesTest extends TestCase
{
    use PreparaInspeccionesEnvases;
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
}
