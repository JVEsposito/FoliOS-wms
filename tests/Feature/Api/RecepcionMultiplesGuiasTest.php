<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class RecepcionMultiplesGuiasTest extends TestCase
{
    use PreparaInspeccionesEnvases, RefreshDatabase;

    public function test_registra_dos_guias_sin_duplicar_camion_pesaje_ni_envases_y_es_idempotente(): void
    {
        $datos = $this->ingreso();
        $r = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()
            ->assertJsonCount(2, 'data.guias')->assertJsonPath('data.numero_guia_despacho', '1511 / 1512')->json('data');
        $this->postJson('/api/romana/recepciones', $datos)->assertCreated()->assertJsonPath('data.id', $r['id']);
        $this->assertDatabaseCount('recepciones_romana', 1);
        $this->assertDatabaseCount('guias_recepcion_romana', 2);
        $this->assertDatabaseCount('detalles_envases_recepcion_romana', 2);
        $this->assertDatabaseCount('movimientos_envases', 0);
        $this->assertSame(28000.0, (float) $r['peso_bruto']);
        $this->assertSame([['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 48]], $r['guias'][0]['envases']);
        $this->getJson('/api/romana/recepciones?buscar=1512')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/romana/recepciones', [...$datos, 'guias' => array_reverse($datos['guias'])])->assertConflict();
    }

    public function test_rechaza_desglose_incompleto_repetido_y_sumas_incorrectas_sin_dejar_datos(): void
    {
        $datos = $this->ingreso();
        foreach ([
            [...$datos, 'guias' => []],
            [...$datos, 'guias' => [$datos['guias'][0], $datos['guias'][0]]],
            [...$datos, 'guias' => [$datos['guias'][0]]],
            [...$datos, 'guias' => [array_replace($datos['guias'][0], ['envases' => [['tipo_envase' => 'bins', 'cantidad' => 0]]]), $datos['guias'][1]]],
            [...$datos, 'guias' => [array_replace($datos['guias'][0], ['envases' => [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'bins', 'cantidad' => 1]]]), $datos['guias'][1]]],
        ] as $invalido) {
            $this->postJson('/api/romana/recepciones', $invalido)->assertUnprocessable();
        }
        $this->assertDatabaseCount('recepciones_romana', 0);
        $this->assertDatabaseCount('guias_recepcion_romana', 0);
    }

    public function test_reserva_cada_guia_y_revierte_por_completo_una_edicion_en_conflicto(): void
    {
        $datos = $this->ingreso();
        $r = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        $simple = [...$datos, 'operacion_id' => (string) Str::uuid(), 'numero_guia_despacho' => '1512'];
        unset($simple['guias']);
        $this->postJson('/api/romana/recepciones', $simple)->assertConflict();
        $this->postJson('/api/romana/recepciones', [...$simple, 'numero_guia_despacho' => 'OCUPADA'])->assertCreated();
        $nuevo = $datos;
        $nuevo['operacion_id'] = (string) Str::uuid();
        $nuevo['guias'][1]['numero_guia_despacho'] = 'OCUPADA';
        $this->putJson('/api/romana/recepciones/'.$r['id'], $nuevo)->assertConflict();
        $this->getJson('/api/romana/recepciones/'.$r['id'])->assertOk()->assertJsonPath('data.guias.1.numero_guia_despacho', '1512');
        $this->assertSame(2, GuiaRecepcionRomana::where('recepcion_romana_id', $r['id'])->where('vigente', 1)->count());
        // Un cliente antiguo no puede borrar involuntariamente las guías al editar.
        $this->putJson('/api/romana/recepciones/'.$r['id'], [...$simple, 'numero_guia_despacho' => 'OTRA'])->assertUnprocessable()->assertJsonValidationErrors('guias');
        $this->assertDatabaseCount('recepciones_romana', 2);
    }

    public function test_desglosa_el_mismo_origen_en_varias_guias_admite_diferencias_y_no_duplica_cuenta(): void
    {
        [$r, $ruta, $payload] = $this->validacion();
        $this->postJson($ruta, $payload)->assertOk()->assertJsonCount(2, 'data.segmentos')
            ->assertJsonPath('data.segmentos.0.numero_guia_despacho', '1511')
            ->assertJsonPath('data.segmentos.1.guia_recepcion_romana_id', $r['guias'][1]['id']);
        $this->postJson($ruta, $payload)->assertOk();
        $this->assertDatabaseCount('segmentos_validacion_mp', 2);
        // Una entrada por tipo, aunque cada tipo venga en las dos guías.
        $this->assertDatabaseCount('movimientos_envases', 2);
        $this->assertSame(4, (int) MovimientoEnvase::where('tipo_envase', 'bins')->sum('cantidad'));
        $this->assertSame(97, (int) MovimientoEnvase::where('tipo_envase', 'totes')->sum('cantidad'));
        $this->assertSame([['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 49]], GuiaRecepcionRomana::findOrFail($r['guias'][1]['id'])->envases_validados);
        $this->assertSame(2, count(MovimientoEnvase::firstOrFail()->datos['guias']));
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $segmentos = $this->getJson('/api/materia-prima/segmentos-pendientes')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['1511', '1512'], array_column($segmentos, 'numero_guia_despacho'));
        $segmento = collect($segmentos)->firstWhere('numero_guia_despacho', '1512');
        $lote = $this->postJson('/api/materia-prima/lotes', ['operacion_id' => (string) Str::uuid(),
            'segmento_validacion_mp_id' => $segmento['id'], 'numero_lote' => 'LOTE-GUIA-1512', 'sdp' => '123',
            'fecha_cosecha' => now()->toDateString(), 'predio' => 'Fundo de prueba',
            'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false])->assertCreated();
        $lote->assertJsonPath('data.segmento.numero_guia_despacho', '1512');
    }

    public function test_no_permite_omitir_guias_usar_una_guia_ajena_o_repartir_mas_que_el_conteo_real(): void
    {
        [$r, $ruta, $payload] = $this->validacion();
        $sinGuia = $payload;
        unset($sinGuia['segmentos'][1]['guia_recepcion_romana_id']);
        $this->postJson($ruta, $sinGuia)->assertUnprocessable()->assertJsonValidationErrors('segmentos.1.guia_recepcion_romana_id');
        $ajena = $payload;
        $ajena['segmentos'][1]['guia_recepcion_romana_id'] = (string) Str::uuid();
        $this->postJson($ruta, $ajena)->assertUnprocessable()->assertJsonValidationErrors('segmentos.1.guia_recepcion_romana_id');
        $repetida = $payload;
        $repetida['segmentos'][1]['guia_recepcion_romana_id'] = $r['guias'][0]['id'];
        $this->postJson($ruta, $repetida)->assertUnprocessable()->assertJsonValidationErrors('segmentos');
        $sobra = $payload;
        $sobra['segmentos'][1]['envases'][0]['cantidad'] = 3;
        $this->postJson($ruta, $sobra)->assertUnprocessable();
        $sinSegregar = [...$payload, 'requiere_segregacion' => false,
            'csg_validacion_id' => $payload['segmentos'][0]['csg_validacion_id'],
            'variedad_validacion_id' => $payload['segmentos'][0]['variedad_validacion_id']];
        unset($sinSegregar['segmentos']);
        $this->postJson($ruta, $sinSegregar)->assertUnprocessable()->assertJsonValidationErrors('segmentos');
        $this->assertDatabaseCount('movimientos_envases', 0);
        $this->assertDatabaseCount('segmentos_validacion_mp', 0);
        $this->assertNull(GuiaRecepcionRomana::findOrFail($r['guias'][0]['id'])->envases_validados);
        // Una guía admite más de un segmento para separar cuarteles/variedades.
        $segmento = $payload['segmentos'][0];
        $segmento['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 24]];
        $payload['segmentos'] = [$segmento, $segmento, $payload['segmentos'][1]];
        $this->postJson($ruta, $payload)->assertOk()->assertJsonCount(3, 'data.segmentos');
    }

    public function test_eliminar_libera_ambas_guias_sin_borrar_la_trazabilidad(): void
    {
        $datos = $this->ingreso();
        $r = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->deleteJson('/api/romana/recepciones/'.$r['id'], ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $r['version'], 'motivo' => 'Camión registrado por error de digitación'])->assertOk();
        $this->assertSame(2, GuiaRecepcionRomana::where('recepcion_romana_id', $r['id'])->whereNull('vigente')->count());
        $this->postJson('/api/romana/recepciones', [...$datos, 'operacion_id' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseCount('guias_recepcion_romana', 4);
    }

    public function test_la_base_impide_reservar_la_misma_guia_desde_dos_recepciones(): void
    {
        $datos = $this->ingreso();
        $r = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        $otra = $this->postJson('/api/romana/recepciones', [...$datos, 'operacion_id' => (string) Str::uuid(),
            'guias' => [['numero_guia_despacho' => 'OTRA', 'envases' => $datos['envases']]]])->assertCreated()->json('data');
        $guia = GuiaRecepcionRomana::findOrFail($r['guias'][1]['id']);
        $this->expectException(UniqueConstraintViolationException::class);
        $guia->replicate()->fill(['recepcion_romana_id' => $otra['id']])->save();
    }

    private function ingreso(): array
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::firstOrCreate(['codigo' => 'CLI-GUIAS'], ['nombre' => 'Cliente guías', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $envases = [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 48]];

        return ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'fruta_con_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 4], ['tipo_envase' => 'totes', 'cantidad' => 96]],
            'guias' => [['numero_guia_despacho' => '1511', 'envases' => $envases], ['numero_guia_despacho' => '1512', 'envases' => $envases]],
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo', 'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor de prueba', 'peso_bruto' => 28000];
    }

    private function validacion(): array
    {
        $datos = $this->ingreso();
        $r = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        $csg = CsgValidacion::create(['temporada_id' => $datos['temporada_id'], 'codigo' => 'CSG-GUIA', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $datos['especie_validacion_id'], 'nombre' => 'Santina', 'activo' => true]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum');
        $v = $this->postJson('/api/validacion-mp/recepciones/'.$r['id'].'/tomar', ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data');
        $ruta = '/api/validacion-mp/validaciones/'.$v['id'].'/confirmar';
        $payload = ['operacion_id' => (string) Str::uuid(), 'tarjas_verificadas' => true, 'requiere_segregacion' => true,
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 4], ['tipo_envase' => 'totes', 'cantidad_validada' => 97]],
            'segmentos' => array_map(fn ($g, $i) => ['guia_recepcion_romana_id' => $g['id'], 'motivos' => ['guia'],
                'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => $i === 0 ? 48 : 49]]], $r['guias'], [0, 1])];

        return [$r, $ruta, $this->payloadConInspeccionRc02($ruta, $payload)];
    }
}
