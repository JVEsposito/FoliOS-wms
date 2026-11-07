<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
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
use App\Services\MateriaPrima\ServicioLoteMateriaPrima;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class CorreccionPesoBrutoRomanaTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    private function ingreso(bool $acumulativo = false): array
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'PESO-'.Str::random(5), 'nombre' => 'Cliente peso', 'activo' => true]);
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

    private function payload(array $recepcion, float $peso = 29000): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'],
            'peso_bruto' => $peso, 'motivo_correccion' => 'Corrección de lectura de la romana.'];
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

    public function test_solo_administrador_activo_puede_corregir_incluso_por_api_directa(): void
    {
        $recepcion = $this->ingreso();
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/peso-bruto";
        $this->assertTrue($recepcion['puede_corregir_peso_bruto']);
        foreach (RolUsuario::cases() as $rol) {
            if ($rol === RolUsuario::Administrador) {
                continue;
            }
            $usuario = User::factory()->create(['rol' => $rol]);
            $this->actingAs($usuario, 'sanctum')->putJson($ruta, $this->payload($recepcion))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => false]), 'sanctum')
            ->putJson($ruta, $this->payload($recepcion))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->getJson("/api/romana/recepciones/{$recepcion['id']}")->assertOk()->assertJsonPath('data.puede_corregir_peso_bruto', false);
        $this->assertDatabaseHas('recepciones_romana', ['id' => $recepcion['id'], 'peso_bruto' => 28540]);
        $this->assertSame(0, EventoRecepcionRomana::where('tipo', 'peso_bruto_corregido')->count());
    }

    public function test_corrige_tras_toma_mp_sin_reabrir_antecedentes_y_audita_la_operacion_idempotente(): void
    {
        $recepcion = $this->ingreso();
        DB::table('recepciones_romana')->where('id', $recepcion['id'])->update(['estado_validacion_mp' => 'en_curso']);
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/peso-bruto";
        $datos = [...$this->payload($recepcion, 29000.125), 'cliente_id' => (string) Str::uuid(), 'peso_tara' => 1];
        $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.peso_bruto', 29000.125)
            ->assertJsonPath('data.peso_neto', null)->assertJsonPath('data.peso_tara', null)
            ->assertJsonPath('data.estado_validacion_mp', 'en_curso')->assertJsonPath('data.cliente.id', $recepcion['cliente']['id'])
            ->assertJsonPath('data.version', $recepcion['version'] + 1);
        $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.version', $recepcion['version'] + 1);
        $evento = EventoRecepcionRomana::where('operacion_id', $datos['operacion_id'])->sole();
        $this->assertSame(28540.0, (float) $evento->datos['anterior']['peso_bruto']);
        $this->assertSame(29000.125, $evento->datos['posterior']['peso_bruto']);
        $this->assertSame(auth()->id(), $evento->user_id);
        $this->assertSame('Corrección de lectura de la romana.', $evento->datos['motivo']);
        $this->putJson($ruta, [...$datos, 'peso_bruto' => 29500])->assertConflict();
        $this->putJson($ruta, $this->payload($recepcion, 30000))->assertConflict();
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'peso_bruto_corregido')->count());
    }

    public function test_cerrada_recalcula_neto_y_reparto_sin_duplicar_salida_ni_cambiar_taras(): void
    {
        $recepcion = $this->cerrar($this->ingreso());
        $movimientos = DB::table('movimientos_envases')->orderBy('id')->get()->toJson();
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/peso-bruto", $this->payload($recepcion, 29540))
            ->assertOk()->assertJsonPath('data.estado', 'cerrado')->assertJsonPath('data.peso_tara', 10540)
            ->assertJsonPath('data.peso_neto', 19000)->assertJsonPath('data.peso_tara_envases', 0)
            ->assertJsonPath('data.peso_neto_por_envase', 395.833)->assertJsonPath('data.reparto_neto_envases.0.cantidad', 48)
            ->assertJsonPath('data.salida_at', $recepcion['salida_at'])->assertJsonPath('data.numero_guia_salida', $recepcion['numero_guia_salida']);
        $this->assertSame($movimientos, DB::table('movimientos_envases')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('salidas_envases_recepcion_romana', 1);
        $this->getJson("/api/romana/recepciones/{$recepcion['id']}/aviso-recibo")->assertOk();
    }

    public function test_rechaza_brutos_invalidos_motivo_vacio_y_neto_no_positivo_sin_modificar_datos(): void
    {
        $recepcion = $this->cerrar($this->ingreso());
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/peso-bruto";
        foreach ([0, -1, 200000.001, 29000.1234, 10000, 10540, 28540] as $bruto) {
            $this->putJson($ruta, $this->payload($recepcion, $bruto))->assertUnprocessable()->assertJsonValidationErrors('peso_bruto');
        }
        $this->putJson($ruta, [...$this->payload($recepcion), 'motivo_correccion' => '             '])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo_correccion');
        $this->assertDatabaseHas('recepciones_romana', ['id' => $recepcion['id'], 'peso_bruto' => 28540, 'peso_neto' => 18000, 'version' => $recepcion['version']]);
    }

    public function test_conserva_la_tara_de_envases_cuando_el_camion_salio_vacio(): void
    {
        $recepcion = $this->cerrar($this->ingreso(), 'vacio');
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/peso-bruto", $this->payload($recepcion, 29540))
            ->assertOk()->assertJsonPath('data.peso_tara_envases', 1920)->assertJsonPath('data.peso_tara_total', 12460)
            ->assertJsonPath('data.peso_neto', 17080)->assertJsonPath('data.modo_salida_envases', 'vacio');
    }

    public function test_tanda_mixta_conserva_cantidades_taras_y_proporciones_con_cuadratura_al_gramo(): void
    {
        $recepcion = $this->ingreso(true);
        $id = $recepcion['id'];
        $recepcion = $this->postJson("/api/romana/recepciones/{$id}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'peso_bruto' => 705.123,
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 20]]])->assertOk()->json('data');
        $tanda = $recepcion['lecturas_pesaje_envases'][0];
        $nueva = $this->putJson("/api/romana/recepciones/{$id}/peso-bruto", [...$this->payload($recepcion, 520.111), 'pesaje_id' => $tanda['id']])
            ->assertOk()->assertJsonPath('data.peso_tara', 110)->assertJsonPath('data.peso_neto', 410.111)->json('data');
        $lineas = $nueva['lecturas_pesaje_envases'][0]['envases'];
        $this->assertSame(410111, array_sum(array_map(fn ($e): int => (int) round($e['peso_neto'] * 1000), $lineas)));
        foreach ($lineas as $i => $linea) {
            $this->assertSame($tanda['envases'][$i]['cantidad'], $linea['cantidad']);
            $this->assertSame((float) $tanda['envases'][$i]['peso_tara'], (float) $linea['peso_tara']);
            $this->assertEqualsWithDelta($tanda['envases'][$i]['peso_neto'] * 410.111 / $tanda['peso_neto'], $linea['peso_neto'], 0.001);
        }
    }

    public function test_pesaje_acumulativo_corrige_la_tanda_y_totales_conservando_otros_pesajes(): void
    {
        $recepcion = $this->ingreso(true);
        $id = $recepcion['id'];
        $this->postJson("/api/romana/recepciones/{$id}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'peso_bruto' => 500,
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2]]])->assertOk();
        $recepcion = $this->postJson("/api/romana/recepciones/{$id}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'peso_bruto' => 202.5,
            'envases' => [['tipo_envase' => 'totes', 'cantidad' => 20]]])->assertOk()->json('data');
        $tanda = collect($recepcion['lecturas_pesaje_envases'])->firstWhere('secuencia', 1);
        $otra = collect($recepcion['lecturas_pesaje_envases'])->firstWhere('secuencia', 2);
        $recepcion = $this->postJson("/api/romana/recepciones/{$id}/cerrar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data');
        $ruta = "/api/romana/recepciones/{$id}/peso-bruto";
        $this->putJson($ruta, $this->payload($recepcion, 520))->assertUnprocessable()->assertJsonValidationErrors('pesaje_id');
        $datos = [...$this->payload($recepcion, 520), 'pesaje_id' => $tanda['id']];
        $corregida = $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.peso_bruto', 722.5)
            ->assertJsonPath('data.peso_tara', 110)->assertJsonPath('data.peso_neto', 612.5)->assertJsonPath('data.estado', 'cerrado')
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 22)->json('data');
        $this->assertSame($otra, collect($corregida['lecturas_pesaje_envases'])->firstWhere('secuencia', 2));
        $this->putJson($ruta, $datos)->assertOk();
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'peso_bruto_corregido')->count());
        $this->assertSame(220.0, (float) collect($corregida['reparto_neto_envases'])->firstWhere('tipo_envase', 'bins')['peso_neto_unitario']);
    }

    public function test_no_corrige_tanda_de_otra_recepcion_ni_anulada(): void
    {
        $primera = $this->ingreso(true);
        $leida = $this->postJson("/api/romana/recepciones/{$primera['id']}/pesajes-envases", ['operacion_id' => (string) Str::uuid(),
            'peso_bruto' => 500, 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2]]])->assertOk()->json('data');
        $tanda = $leida['lecturas_pesaje_envases'][0]['id'];
        $segunda = $this->ingreso(true);
        $this->putJson("/api/romana/recepciones/{$segunda['id']}/peso-bruto", [...$this->payload($segunda, 550), 'pesaje_id' => $tanda])->assertNotFound();
        DB::table('pesajes_envases_recepcion_romana')->where('id', $tanda)->update(['anulado_at' => now()]);
        $this->putJson("/api/romana/recepciones/{$primera['id']}/peso-bruto", [...$this->payload($leida, 550), 'pesaje_id' => $tanda])->assertNotFound();
    }

    public function test_concilia_lotes_en_borrador_y_bloquea_cambios_despues_de_confirmarlos(): void
    {
        $recepcion = $this->ingreso();
        $admin = auth()->user();
        $id = $recepcion['id'];
        $this->postJson("/api/romana/recepciones/{$id}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum');
        $validacion = $this->postJson("/api/validacion-mp/recepciones/{$id}/tomar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data.id');
        $csg = CsgValidacion::create(['temporada_id' => $recepcion['temporada']['id'], 'codigo' => '123456', 'activo' => true]);
        $variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $recepcion['especie_validacion_id'], 'nombre' => 'Santina'], ['activo' => true]);
        $rutaConfirmar = "/api/validacion-mp/validaciones/{$validacion}/confirmar";
        $this->postJson($rutaConfirmar, $this->payloadConInspeccionRc02($rutaConfirmar, [
            'operacion_id' => (string) Str::uuid(), 'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 48]],
            'tarjas_verificadas' => true, 'requiere_segregacion' => false,
            'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
        ]))->assertOk();
        $this->actingAs($admin, 'sanctum');
        $recepcion = $this->cerrar($this->getJson("/api/romana/recepciones/{$id}")->assertOk()->json('data'));
        $segmento = SegmentoValidacionMp::where('validacion_mp_id', $validacion)->firstOrFail();
        $lote = $this->postJson('/api/materia-prima/lotes', ['operacion_id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $segmento->id,
            'numero_lote' => 'L-PESO-1', 'sdp' => '123', 'fecha_cosecha' => now()->toDateString(),
            'predio' => 'Fundo', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false])->assertCreated()->json('data');
        $ruta = "/api/romana/recepciones/{$id}/peso-bruto";
        $recepcion = $this->putJson($ruta, $this->payload($recepcion, 29540))->assertOk()->json('data');
        $modelo = LoteMateriaPrima::findOrFail($lote['id']);
        $this->assertSame(19000.0, (float) $modelo->kilos_netos_confirmados);
        $this->assertSame(19000.0, (float) $modelo->kilos_netos_calculados);
        $this->assertSame(20920.0, (float) $modelo->kilos_brutos);
        $this->partialMock(ServicioLoteMateriaPrima::class)
            ->shouldReceive('conciliarDestare')->once()->andThrow(new ConflictoOperacion('Fallo simulado de conciliación'));
        $this->putJson($ruta, $this->payload($recepcion, 30000))->assertConflict();
        $this->assertDatabaseHas('recepciones_romana', ['id' => $id, 'peso_bruto' => 29540, 'version' => $recepcion['version']]);
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'peso_bruto_corregido')->count());
        $this->app->forgetInstance(ServicioLoteMateriaPrima::class);
        $this->postJson("/api/materia-prima/lotes/{$modelo->id}/confirmar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $modelo->version])->assertOk();
        $this->putJson($ruta, $this->payload($recepcion, 30000))->assertConflict();
        $this->assertDatabaseHas('recepciones_romana', ['id' => $id, 'peso_bruto' => 29540, 'version' => $recepcion['version']]);
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'peso_bruto_corregido')->count());
    }

    public function test_rechaza_recepcion_anulada_eliminada_solo_envases_o_temporada_inactiva(): void
    {
        $recepcion = $this->ingreso();
        $modelo = RecepcionRomana::findOrFail($recepcion['id']);
        foreach ([['estado' => 'anulada'], ['eliminada_at' => now()], ['tipo_recepcion' => 'solo_envases']] as $cambio) {
            DB::table('recepciones_romana')->where('id', $modelo->id)->update([...$cambio]);
            $this->putJson("/api/romana/recepciones/{$modelo->id}/peso-bruto", $this->payload($recepcion))->assertConflict();
            DB::table('recepciones_romana')->where('id', $modelo->id)->update(['estado' => 'en_bascula_ingreso', 'eliminada_at' => null, 'tipo_recepcion' => 'fruta_con_envases']);
        }
        DB::table('temporadas')->where('id', $modelo->temporada_id)->update(['activa' => false]);
        app(ServicioTemporadaActiva::class)->olvidar();
        $this->putJson("/api/romana/recepciones/{$modelo->id}/peso-bruto", $this->payload($recepcion))->assertConflict();
    }
}
