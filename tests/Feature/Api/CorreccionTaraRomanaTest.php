<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\EventoRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

/** Corrección transversal de tara: solo administración, aunque existan procesos posteriores. */
class CorreccionTaraRomanaTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('configuracion_taras_romana')->where('tipo_envase', 'bins')->update(['tara_unitaria' => 40]);
        DB::table('configuracion_taras_romana')->where('tipo_envase', 'totes')->update(['tara_unitaria' => 1.5]);
    }

    private function ingreso(bool $acumulativo = false): array
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'TARA-'.Str::random(5), 'nombre' => 'Cliente tara', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);

        return $this->actingAs($admin, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'especie_validacion_id' => $especie->id, 'tipo_recepcion' => $acumulativo ? 'fruta_pesaje_envases' : 'fruta_con_envases',
            'tipo_servicio' => 'prefrio', 'numero_guia_despacho' => 'GD-'.Str::random(6), 'patente_camion' => 'ABCD12',
            'tipo_camion' => 'plano', 'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor',
            ...($acumulativo ? ['envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 2, 'tara_unitaria' => 40, 'propiedad' => 'cliente'],
                ['tipo_envase' => 'totes', 'cantidad' => 20, 'tara_unitaria' => 1.5, 'propiedad' => 'cliente'],
            ]] : ['peso_bruto' => 28540, 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 48, 'propiedad' => 'cliente']]]),
        ])->assertCreated()->json('data');
    }

    private function cerrar(array $recepcion, string $modo = 'mismos'): array
    {
        $id = $recepcion['id'];
        if ($recepcion['estado'] === 'en_bascula_ingreso') {
            $this->postJson("/api/romana/recepciones/{$id}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        }
        DB::table('recepciones_romana')->where('id', $id)->update(['estado_validacion_mp' => 'validada']);
        DB::table('detalles_envases_recepcion_romana')->where('recepcion_romana_id', $id)->update(['cantidad_validada' => 48]);
        $ruta = "/api/romana/recepciones/{$id}/cerrar";

        return $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(), 'peso_tara' => 10540, 'modo_salida_envases' => $modo,
            'numero_guia_salida' => 'GS-'.Str::random(6), 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40]],
            'envases_reparto' => ['bins'],
        ]))->assertOk()->json('data');
    }

    private function payload(array $recepcion, array $cambios): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'],
            'motivo_correccion' => 'La tara oficial de bins estaba mal configurada.', ...$cambios];
    }

    /** Recepción validada con un lote confirmado, en cámara y con una entrega a proceso. */
    private function recepcionConEntrega(bool $acumulativo = false): array
    {
        $recepcion = $this->ingreso($acumulativo);
        $admin = auth()->user();
        $id = $recepcion['id'];
        if ($acumulativo) {
            $this->postJson("/api/romana/recepciones/{$id}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'peso_bruto' => 705.123,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 20]]])->assertOk();
            $this->postJson("/api/romana/recepciones/{$id}/cerrar", ['operacion_id' => (string) Str::uuid()])->assertOk();
        } else {
            $this->postJson("/api/romana/recepciones/{$id}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        }
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum');
        $validacion = $this->postJson("/api/validacion-mp/recepciones/{$id}/tomar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data.id');
        $csg = CsgValidacion::create(['temporada_id' => $recepcion['temporada']['id'], 'codigo' => '123456', 'activo' => true]);
        $variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $recepcion['especie_validacion_id'], 'nombre' => 'Santina'], ['activo' => true]);
        $rutaConfirmar = "/api/validacion-mp/validaciones/{$validacion}/confirmar";
        $this->postJson($rutaConfirmar, $this->payloadConInspeccionRc02($rutaConfirmar, [
            'operacion_id' => (string) Str::uuid(), 'envases' => $acumulativo
                ? [['tipo_envase' => 'bins', 'cantidad_validada' => 2], ['tipo_envase' => 'totes', 'cantidad_validada' => 20]]
                : [['tipo_envase' => 'bins', 'cantidad_validada' => 48]],
            'tarjas_verificadas' => true, 'requiere_segregacion' => false,
            'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
        ]))->assertOk();
        $this->actingAs($admin, 'sanctum');
        if (! $acumulativo) {
            $this->cerrar($this->getJson("/api/romana/recepciones/{$id}")->assertOk()->json('data'));
        }
        $segmento = SegmentoValidacionMp::where('validacion_mp_id', $validacion)->firstOrFail();
        $lote = $this->postJson('/api/materia-prima/lotes', ['operacion_id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $segmento->id,
            'numero_lote' => 'L-TARA-1', 'sdp' => '123', 'fecha_cosecha' => now()->toDateString(),
            'predio' => 'Fundo', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false])->assertCreated()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $lote['version']])->assertOk();
        $camara = Camara::create(['codigo' => 'MP-TARA', 'nombre' => 'Cámara MP', 'tipo' => 'almacenaje', 'contenido' => 'materia_prima',
            'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/asignar-camara", ['operacion_id' => (string) Str::uuid(), 'camara_id' => $camara->id])->assertOk();
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", ['operacion_id' => (string) Str::uuid(), 'camara_origen_id' => $camara->id,
            'cantidad_envases' => $acumulativo ? 5 : 12, 'linea_proceso' => 'Línea 1', 'turno' => 'A', 'numero_orden' => 'OP-TARA'])->assertOk();

        return [$this->getJson("/api/romana/recepciones/{$id}")->assertOk()->json('data'), LoteMateriaPrima::findOrFail($lote['id'])];
    }

    public function test_solo_administracion_activa_corrige_la_tara_incluso_por_api_directa(): void
    {
        $recepcion = $this->cerrar($this->ingreso());
        $this->assertTrue($recepcion['puede_corregir_tara']);
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/tara";
        foreach (RolUsuario::cases() as $rol) {
            if ($rol === RolUsuario::Administrador) {
                continue;
            }
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')
                ->putJson($ruta, $this->payload($recepcion, ['peso_tara' => 10000]))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => false]), 'sanctum')
            ->putJson($ruta, $this->payload($recepcion, ['peso_tara' => 10000]))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->getJson("/api/romana/recepciones/{$recepcion['id']}")->assertOk()->assertJsonPath('data.puede_corregir_tara', false);
        $this->assertDatabaseHas('recepciones_romana', ['id' => $recepcion['id'], 'peso_tara' => 10540, 'peso_neto' => 18000]);
        $this->assertSame(0, EventoRecepcionRomana::where('tipo', 'tara_corregida')->count());
    }

    public function test_corrige_tara_de_camion_y_envases_recalcula_neto_y_es_idempotente(): void
    {
        $recepcion = $this->cerrar($this->ingreso(), 'vacio');
        $this->assertSame(16080, (int) $recepcion['peso_neto']);
        $movimientos = DB::table('movimientos_envases')->orderBy('id')->get()->toJson();
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/tara";
        $datos = $this->payload($recepcion, ['peso_tara' => 10500, 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 43]]]);
        $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.peso_tara', 10500)
            ->assertJsonPath('data.peso_tara_envases', 2064)->assertJsonPath('data.peso_neto', 15976)
            ->assertJsonPath('data.peso_neto_por_envase', 332.833)->assertJsonPath('data.envases.0.tara_unitaria_salida', 43)
            ->assertJsonPath('data.taras_romana_snapshot.bins', 43)->assertJsonPath('data.version', $recepcion['version'] + 1)
            ->assertJsonPath('data.eventos', fn (array $eventos): bool => end($eventos)['tipo'] === 'tara_corregida');
        $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.version', $recepcion['version'] + 1);
        $this->putJson($ruta, [...$datos, 'peso_tara' => 10400])->assertConflict();
        $this->putJson($ruta, $this->payload($recepcion, ['peso_tara' => 10400]))->assertConflict();
        $evento = EventoRecepcionRomana::where('operacion_id', $datos['operacion_id'])->sole();
        $this->assertSame(16080.0, (float) $evento->datos['anterior']['peso_neto']);
        $this->assertSame(40.0, (float) $evento->datos['anterior']['taras_envases']['bins']);
        $this->assertSame(15976.0, (float) $evento->datos['posterior']['peso_neto']);
        $this->assertSame(43.0, (float) $evento->datos['posterior']['taras_envases']['bins']);
        $this->assertSame($datos['motivo_correccion'], $evento->datos['motivo']);
        $this->assertSame($movimientos, DB::table('movimientos_envases')->orderBy('id')->get()->toJson());
        $this->getJson("/api/romana/recepciones/{$recepcion['id']}/aviso-recibo")->assertOk();
    }

    public function test_con_mismos_envases_la_tara_de_envases_no_cambia_el_neto_pero_si_las_salidas(): void
    {
        $recepcion = $this->cerrar($this->ingreso());
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/tara",
            $this->payload($recepcion, ['taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 43]]]))
            ->assertOk()->assertJsonPath('data.peso_neto', 18000)->assertJsonPath('data.peso_tara_envases', 0)
            ->assertJsonPath('data.salida_envases.0.tara_unitaria', 43)->assertJsonPath('data.peso_tara', 10540);
    }

    public function test_rehace_lotes_con_entregas_a_proceso_sin_tocar_entregas_ni_ubicacion(): void
    {
        [$recepcion, $lote] = $this->recepcionConEntrega();
        $this->assertSame('entrega_parcial_proceso', $lote->estado->value);
        $entregas = DB::table('entregas_fruta_proceso')->get()->toJson();
        $asignacion = DB::table('asignaciones_camara_lote_materia_prima')->where('lote_materia_prima_id', $lote->id)->get()->toJson();
        // La corrección de bruto sigue exigiendo conciliación con las entregas.
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/peso-bruto", ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $recepcion['version'], 'peso_bruto' => 29000, 'motivo_correccion' => 'Lectura equivocada de romana.'])->assertConflict();
        $datos = $this->payload($recepcion, ['peso_tara' => 10040, 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 43]]]);
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/tara", $datos)
            ->assertOk()->assertJsonPath('data.peso_neto', 18500)->assertJsonPath('data.lotes_materia_prima.0.kilos_netos', 18500);
        $lote->refresh();
        $this->assertSame('entrega_parcial_proceso', $lote->estado->value);
        $this->assertSame(18500.0, (float) $lote->kilos_netos_confirmados);
        $this->assertSame(18500.0 + 48 * 43, (float) $lote->kilos_brutos);
        $this->assertSame(43.0, (float) $lote->envasesDetalle()->sole()->tara_unitaria);
        $this->assertSame($entregas, DB::table('entregas_fruta_proceso')->get()->toJson());
        $this->assertSame($asignacion, DB::table('asignaciones_camara_lote_materia_prima')->where('lote_materia_prima_id', $lote->id)->get()->toJson());
        $audit = json_decode(DB::table('eventos_lote_materia_prima')->where('lote_materia_prima_id', $lote->id)
            ->where('tipo', 'peso_romana_conciliado')->where('datos->operacion_romana_id', $datos['operacion_id'])->sole()->datos, true);
        $this->assertTrue($audit['con_entregas_proceso']);
        $this->assertSame('tara_corregida', $audit['origen']);
        $this->assertSame(18000.0, (float) $audit['anterior']['kilos_netos_confirmados']);
        $evento = EventoRecepcionRomana::where('operacion_id', $datos['operacion_id'])->sole();
        $this->assertEquals([['id' => $lote->id, 'numero_lote' => 'L-TARA-1', 'estado' => 'entrega_parcial_proceso',
            'kilos_anteriores' => 18000, 'kilos_posteriores' => 18500]], $evento->datos['lotes']);
    }

    public function test_pesaje_acumulativo_validado_rehace_tandas_reparto_y_lote_con_entregas(): void
    {
        [$recepcion, $lote] = $this->recepcionConEntrega(acumulativo: true);
        $tanda = $recepcion['lecturas_pesaje_envases'][0];
        $this->assertSame(110, (int) $tanda['peso_tara']);
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/tara";
        $this->putJson($ruta, $this->payload($recepcion, ['peso_tara' => 1000, 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 43]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('peso_tara');
        $nueva = $this->putJson($ruta, $this->payload($recepcion, ['taras_envases' => [
            ['tipo_envase' => 'bins', 'tara_unitaria' => 43], ['tipo_envase' => 'totes', 'tara_unitaria' => 0.89],
        ]]))->assertOk()->assertJsonPath('data.estado', 'cerrado')->assertJsonPath('data.peso_tara', 103.8)
            ->assertJsonPath('data.peso_neto', 601.323)->assertJsonPath('data.lotes_materia_prima.0.kilos_netos', 601.323)->json('data');
        $lineas = collect($nueva['lecturas_pesaje_envases'][0]['envases'])->keyBy('tipo_envase');
        $this->assertSame(86.0, (float) $lineas['bins']['peso_tara']);
        $this->assertSame(17.8, (float) $lineas['totes']['peso_tara']);
        $this->assertSame(0.89, (float) $lineas['totes']['tara_unitaria']);
        $this->assertSame(601323, (int) round(($lineas['bins']['peso_neto'] + $lineas['totes']['peso_neto']) * 1000));
        $this->assertEqualsWithDelta(601.323, (float) collect($nueva['reparto_neto_envases'])->sum('peso_neto'), 0.0005);
        $this->assertSame(601.323, (float) $lote->fresh()->kilos_netos_confirmados);
        $this->assertSame('entrega_parcial_proceso', $lote->fresh()->estado->value);
        $this->assertSame(['bins' => 43, 'totes' => 0.89], $nueva['taras_romana_snapshot']);
        $this->assertDatabaseHas('detalles_envases_recepcion_romana', ['recepcion_romana_id' => $recepcion['id'], 'tipo_envase' => 'totes', 'tara_unitaria_salida' => 0.89]);
    }

    public function test_pesaje_abierto_corrige_tandas_y_las_siguientes_usan_la_tara_corregida(): void
    {
        $recepcion = $this->ingreso(true);
        $id = $recepcion['id'];
        $recepcion = $this->postJson("/api/romana/recepciones/{$id}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'peso_bruto' => 500,
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2]]])->assertOk()->json('data');
        $this->assertTrue($recepcion['puede_corregir_tara']);
        $recepcion = $this->putJson("/api/romana/recepciones/{$id}/tara", $this->payload($recepcion, ['taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 43]]]))
            ->assertOk()->assertJsonPath('data.estado', 'en_pesaje_envases')->assertJsonPath('data.peso_tara', 86)
            ->assertJsonPath('data.peso_neto', 414)->json('data');
        $this->postJson("/api/romana/recepciones/{$id}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'peso_bruto' => 230,
            'envases' => [['tipo_envase' => 'totes', 'cantidad' => 20]]])->assertOk()
            ->assertJsonPath('data.peso_tara', 116)->assertJsonPath('data.peso_neto', 614);
        $this->assertSame(0, LoteMateriaPrima::count());
    }

    public function test_rechaza_sin_cambios_neto_no_positivo_envase_ajeno_y_estados_no_corregibles(): void
    {
        $abierta = $this->ingreso();
        $this->assertFalse($abierta['puede_corregir_tara']);
        $this->putJson("/api/romana/recepciones/{$abierta['id']}/tara", $this->payload($abierta, ['peso_tara' => 10000]))->assertConflict();
        $recepcion = $this->cerrar($this->ingreso(), 'vacio');
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/tara";
        foreach ([
            ['peso_tara' => 10540, 'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40]]],
            ['peso_tara' => 26700],
            ['taras_envases' => [['tipo_envase' => 'totes', 'tara_unitaria' => 1]]],
            ['taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 0]]],
            [],
        ] as $cambio) {
            $this->putJson($ruta, $this->payload($recepcion, $cambio))->assertUnprocessable();
        }
        $this->putJson($ruta, [...$this->payload($recepcion, ['peso_tara' => 10000]), 'motivo_correccion' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo_correccion');
        $modelo = RecepcionRomana::findOrFail($recepcion['id']);
        foreach ([['estado' => 'anulada'], ['eliminada_at' => now()], ['tipo_recepcion' => 'solo_envases']] as $cambio) {
            DB::table('recepciones_romana')->where('id', $modelo->id)->update($cambio);
            $this->putJson($ruta, $this->payload($recepcion, ['peso_tara' => 10000]))->assertConflict();
            DB::table('recepciones_romana')->where('id', $modelo->id)->update(['estado' => 'cerrado', 'eliminada_at' => null, 'tipo_recepcion' => 'fruta_con_envases']);
        }
        $this->assertDatabaseHas('recepciones_romana', ['id' => $modelo->id, 'peso_tara' => 10540, 'peso_neto' => 16080, 'version' => $recepcion['version']]);
        $this->assertSame(0, EventoRecepcionRomana::where('tipo', 'tara_corregida')->count());
    }
}
