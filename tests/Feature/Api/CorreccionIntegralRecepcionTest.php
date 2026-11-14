<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\EventoRecepcionRomana;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

class CorreccionIntegralRecepcionTest extends TestCase
{
    use PreparaInspeccionesEnvases, PreparaRecepcionValidadaCorregible, RefreshDatabase;

    public function test_corrige_numero_de_guia_y_conteo_de_un_segmento_con_ajuste_trazable_en_la_cuenta(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias();
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->assertOk()
            ->assertJsonCount(2, 'data.guias')->assertJsonCount(2, 'data.segmentos')->assertJsonPath('data.destare.peso_neto', 18000)->json('data');
        $payload = $this->payload($estado);
        $payload['guias'][0]['numero_guia_despacho'] = '1521';
        $payload['segmentos'][0]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 50]];

        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $payload)->assertOk()
            ->assertJsonPath('data.numero_guia_despacho', '1521 / 1512')
            ->assertJsonPath('data.correccion_integral.segmentos.0.envases.1.cantidad', 50);

        $this->assertSame(99, DetalleEnvaseRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'totes')->value('cantidad_validada'));
        // La cuenta no reescribe el ingreso: suma una corrección enlazada de +2.
        $ingreso = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'recepcion_fruta')->where('tipo_envase', 'totes')->sole();
        $this->assertSame(97, $ingreso->cantidad);
        $correccion = MovimientoEnvase::where('movimiento_origen_id', $ingreso->id)->sole();
        $this->assertSame(['correccion_cantidad', 2, 1, 1], [$correccion->tipo_movimiento->value, $correccion->cantidad, $correccion->signo_cuenta, $correccion->signo_existencia]);
        $this->assertSame('1521 / 1512', $ingreso->refresh()->numero_documento);
        // Salió con los mismos envases: la salida acompaña el conteo corregido.
        $this->assertSame(99, (int) MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'totes')
            ->whereIn('tipo_movimiento', ['salida_recepcion_fruta', 'reversion_salida_fruta'])->get()->sum(fn ($m) => -$m->cantidad * $m->signo_existencia));
        $this->assertEquals([['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 50]],
            GuiaRecepcionRomana::where('numero_guia_despacho', '1521')->sole()->envases_validados);
        $evento = EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'correccion_integral')->sole();
        $this->assertSame('Conteo de totes mal digitado en la guía 1511, número real 1521.', $evento->datos['motivo']);

        // Repetir la misma operación no duplica nada; reenviar sin cambios se rechaza.
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $payload)->assertOk();
        $this->assertSame(1, MovimientoEnvase::where('movimiento_origen_id', $ingreso->id)->count());
        $sinCambios = $this->payload($this->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data'));
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $sinCambios)->assertConflict()
            ->assertJsonPath('message', 'La corrección no presenta cambios respecto de lo registrado.');
    }

    public function test_corrige_tipo_propiedad_y_destare_y_reconcilia_los_kilos_del_lote(): void
    {
        [$recepcion, $segmentos] = $this->recepcionCerradaConDosGuias();
        $lote = $this->lote($segmentos[0]);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->assertOk()->json('data');
        $payload = $this->payload($estado);
        // En el segmento 1 eran 3 bins (no 2), los bins son arrendados, la tara del camión era 9.900 y salió con 4 bins.
        $payload['segmentos'][0]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 3], ['tipo_envase' => 'totes', 'cantidad' => 48]];
        $payload['guias'][0]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 3], ['tipo_envase' => 'totes', 'cantidad' => 48]];
        $payload['propiedades'] = [['tipo_envase' => 'bins', 'propiedad' => 'arrendada'], ['tipo_envase' => 'totes', 'propiedad' => 'cliente']];
        $payload['destare'] = [...$payload['destare'], 'peso_tara' => 9900, 'modo_salida_envases' => 'diferentes',
            'salida_envases' => [['tipo_envase' => 'bins', 'cantidad' => 4], ['tipo_envase' => 'totes', 'cantidad' => 97]]];

        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $payload)->assertOk()
            ->assertJsonPath('data.correccion_integral.destare.peso_neto', 18060);

        // Neto = 28.000 − 9.900 − (5 − 4) × 40 kg de tara del bin que quedó en planta.
        $recepcion->refresh();
        $this->assertSame(18060.0, (float) $recepcion->peso_neto);
        $this->assertSame(40.0, (float) $recepcion->peso_tara_envases);
        // Bins: el ingreso como cliente queda en cero y entra uno nuevo como arrendado por 5.
        $bins = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')
            ->whereIn('tipo_movimiento', ['recepcion_fruta', 'correccion_cantidad'])->get();
        $this->assertSame(0, $bins->where('propiedad.value', 'cliente')->sum(fn ($m) => $m->cantidad * $m->signo_existencia));
        $this->assertSame(5, $bins->where('propiedad.value', 'arrendada')->sum(fn ($m) => $m->cantidad * $m->signo_existencia));
        $this->assertSame('arrendada', DetalleEnvaseRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')->value('propiedad')->value);
        // El lote del segmento 1 toma el conteo corregido y su parte del neto (3 de 5 bins).
        $lote->refresh()->load('envasesDetalle');
        $this->assertSame(3, $lote->cantidad_envases_primarios);
        $this->assertSame(3, $lote->envasesDetalle->firstWhere('tipo_envase.value', 'bins')->cantidad);
        $this->assertEqualsWithDelta(18060 * 3 / 5, (float) $lote->kilos_netos_confirmados, 0.01);
    }

    public function test_la_cuenta_corriente_muestra_la_recepcion_separada_por_guia_y_sigue_las_correcciones(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias();
        $cuenta = fn (): array => app(ServicioCuentaCorrienteEnvases::class)
            ->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $entradas = fn (array $estado): array => collect($estado['movimientos'])->whereNotNull('entrada')
            ->map(fn ($l) => [$l['documento'], $l['tipo_envase'], $l['entrada'], $l['saldo'], $l['productor']])->values()->all();

        // Un movimiento por tipo en la cuenta, pero el cliente lo ve por guía con lo validado de cada una.
        $this->assertSame([
            ['1511', 'Bins plástico', 2, 2, 'CSG CSG-CORR'], ['1511', 'Tote', 48, 48, 'CSG CSG-CORR'],
            ['1512', 'Bins plástico', 2, 4, 'CSG CSG-CORR'], ['1512', 'Tote', 49, 97, 'CSG CSG-CORR'],
        ], $entradas($cuenta()));
        $this->assertSame(['1511', '1512', 'GS-CORR-1'], array_column($cuenta()['grupos_documentos'], 'documento'));
        $this->assertSame(0, collect($cuenta()['tipos'])->sum('saldo_final'));

        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');
        $payload = $this->payload($estado);
        $payload['guias'][1]['numero_guia_despacho'] = '1522';
        $payload['segmentos'][1]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 45]];
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $payload)->assertOk();

        // La corrección se pliega en su guía: la 1522 muestra 45 totes y el total cuadra.
        $this->assertSame([
            ['1511', 'Bins plástico', 2, 2, 'CSG CSG-CORR'], ['1511', 'Tote', 48, 48, 'CSG CSG-CORR'],
            ['1522', 'Bins plástico', 2, 4, 'CSG CSG-CORR'], ['1522', 'Tote', 45, 93, 'CSG CSG-CORR'],
        ], $entradas($cuenta()));
        $this->assertSame(0, collect($cuenta()['tipos'])->sum('saldo_final'));
    }

    public function test_corrige_una_recepcion_solo_de_envases_sin_mover_la_cuenta_de_envases_comprados(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::firstOrCreate(['codigo' => 'CLI-SOLO'], ['nombre' => 'Cliente envases', 'activo' => true]);
        $r = $this->postJson('/api/romana/recepciones', ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id, 'tipo_recepcion' => 'solo_envases', 'concepto_envases' => 'compra',
            'fecha_ingreso' => now(config('app.operational_timezone'))->toDateString(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 20]], 'numero_guia_despacho' => 'GUIA-SOLO-1',
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano', 'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor'])
            ->assertCreated()->json('data');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum');
        $v = $this->postJson("/api/validacion-mp/recepciones/{$r['id']}/tomar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data');
        $ruta = "/api/validacion-mp/validaciones/{$v['id']}/confirmar";
        $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, ['operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 20]]]))->assertOk();

        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$r['id']}/correccion-integral")->assertOk()
            ->assertJsonPath('data.propiedad_editable', false)->assertJsonCount(0, 'data.segmentos')->json('data');
        $payload = [...$this->payload($estado), 'envases_validados' => [['tipo_envase' => 'bins', 'cantidad' => 22]]];
        $payload['guias'][0]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 22]];
        unset($payload['segmentos'], $payload['propiedades']);
        $this->putJson("/api/romana/recepciones/{$r['id']}/correccion-integral", $payload)->assertOk();

        $movimientos = MovimientoEnvase::where('recepcion_romana_id', $r['id'])->get();
        $this->assertSame(22, $movimientos->sum(fn ($m) => $m->cantidad * $m->signo_existencia));
        // Envases comprados: entran a la existencia propia, no a la cuenta del cliente.
        $this->assertSame(0, $movimientos->sum(fn ($m) => $m->cantidad * $m->signo_cuenta));
        $this->assertSame('propia', $movimientos->firstWhere('tipo_movimiento.value', 'correccion_cantidad')->propiedad->value);
    }

    public function test_corrige_el_conteo_de_una_recepcion_validada_que_aun_no_tiene_destare(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(cerrar: false);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->assertOk()
            ->assertJsonPath('data.destare', null)->json('data');
        $payload = $this->payload($estado);
        $payload['segmentos'][1]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 49]];
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", [...$payload, 'destare' => ['peso_tara' => 1,
            'modo_salida_envases' => 'vacio']])->assertUnprocessable()->assertJsonValidationErrors('destare');
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $payload)->assertOk();
        $this->assertSame(3, DetalleEnvaseRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')->value('cantidad_validada'));
        $this->assertSame(3, (int) MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')
            ->get()->sum(fn ($m) => $m->cantidad * $m->signo_existencia));
        // Romana cierra después con el conteo corregido.
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $cierre = "/api/romana/recepciones/{$recepcion->id}/cerrar";
        $this->postJson($cierre, $this->payloadConInspeccionRc02($cierre, ['operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'vacio', 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]],
            'peso_tara' => 10000, 'tipo_envase_calculo_neto' => 'bins']))->assertOk()->assertJsonPath('data.peso_neto', 17686);
    }

    public function test_solo_administracion_corrige_y_respeta_version_y_estado(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias();
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');
        $payload = $this->payload($estado);
        $payload['guias'][1]['numero_guia_despacho'] = '1599';

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]), 'sanctum')
            ->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $payload)->assertForbidden();
        $this->comoAdministrador()->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", [...$payload, 'version_conocida' => 1])
            ->assertConflict();
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", [...$payload, 'guias' => [$payload['guias'][0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('guias');
        $otro = [...$payload, 'operacion_id' => (string) Str::uuid()];
        $otro['guias'][1]['numero_guia_despacho'] = $payload['guias'][0]['numero_guia_despacho'];
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $otro)->assertUnprocessable();
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", [...$payload, 'destare' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('destare');
        $this->assertSame(0, EventoRecepcionRomana::where('tipo', 'correccion_integral')->count());
    }

    private function payload(array $estado): array
    {
        return [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $estado['version'],
            'motivo_correccion' => 'Conteo de totes mal digitado en la guía 1511, número real 1521.',
            'guias' => array_map(fn ($g) => ['id' => $g['id'], 'numero_guia_despacho' => $g['numero_guia_despacho'], 'envases' => $g['envases']], $estado['guias']),
            'segmentos' => array_map(fn ($s) => ['id' => $s['id'], 'envases' => $s['envases']], $estado['segmentos']),
            'propiedades' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'propiedad' => $e['propiedad']], $estado['envases']),
            'destare' => $estado['destare'] === null ? null : [
                'peso_tara' => $estado['destare']['peso_tara'], 'modo_salida_envases' => $estado['destare']['modo_salida_envases'],
                'numero_guia_salida' => $estado['destare']['numero_guia_salida'], 'salida_envases' => $estado['destare']['salida_envases'],
                'taras_envases' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'tara_unitaria' => $e['tara_unitaria_salida']], $estado['envases']),
            ],
        ];
    }
}
