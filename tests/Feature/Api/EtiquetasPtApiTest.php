<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClienteValidacion;
use App\Models\Dispositivo;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\ImpresionEtiquetaPt;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EtiquetasPtApiTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private string $token;

    private ValidacionPallet $validacion;

    protected function setUp(): void
    {
        parent::setUp();
        $temporada = $this->crearTemporadaActivaPrueba(['codigo' => 'PT-TEST', 'nombre' => 'Pruebas PT']);
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->token = $this->usuario->createToken('oficina', ['oficina'])->plainTextToken;
        $dispositivo = Dispositivo::create(['codigo' => 'PT-ETIQUETAS', 'nombre' => 'PDA', 'plataforma' => 'android', 'activo' => true]);
        $articulo = (string) Str::uuid();
        $origen = (string) Str::uuid();
        DB::table('articulos_validacion')->insert([
            'id' => $articulo, 'temporada_id' => $temporada->id, 'especie' => 'Cereza',
            'variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg',
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen, 'temporada_id' => $temporada->id, 'cliente' => 'DIS', 'marca' => 'ATLAS', 'csg' => '105410',
        ]);
        $composicion = [
            ['csg' => '105410', 'predio' => 'Los Olmos', 'cantidad_cajas' => 80, 'fecha_embalaje' => '2026-10-05'],
            ['csg' => '105411', 'predio' => 'El Sol', 'cantidad_cajas' => 40, 'fecha_embalaje' => '2026-10-04'],
        ];
        $folio = Folio::create([
            'temporada_id' => $temporada->id, 'numero_folio' => '0000000003', 'tipo_bulto' => 'pallet',
            'estado_operacional' => 'pendiente_prefrio', 'activo' => true, 'fecha_ingreso' => now(),
            'variedad' => 'Santina', 'calibre' => '2J', 'exportadora' => 'DIS', 'marca' => 'ATLAS',
            'datos_externos' => ['especie' => 'Cereza', 'envase' => '5 kg', 'cantidad_cajas' => 120,
                'categoria' => 'CAT-1', 'csg' => 'MIXTO', 'composicion' => $composicion],
        ]);
        $this->validacion = ValidacionPallet::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'numero_folio' => $folio->numero_folio, 'numero_intento' => 1, 'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 120, 'linea_proceso' => 1, 'turno' => 'A', 'temporada_id' => $temporada->id,
            'articulo_validacion_id' => $articulo, 'origen_validacion_id' => $origen,
            'resultado' => 'aprobado', 'estado' => 'aceptada', 'catalogo_version_dispositivo' => 1,
            'catalogo_version_servidor' => 1, 'snapshot' => [], 'user_id' => $this->usuario->id,
            'dispositivo_id' => $dispositivo->id, 'folio_id' => $folio->id,
            'generado_dispositivo_at' => now(), 'recibido_servidor_at' => now(),
        ]);
    }

    public function test_lista_datos_actuales_sin_perder_ceros_y_filtra_por_jornada_y_folio(): void
    {
        $this->sesion()->getJson('/api/validacion/etiquetas?folio=0000000003&linea_proceso=1&turno=A')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', '0000000003')
            ->assertJsonPath('data.0.composicion.1.csg', '105411');
        $this->sesion()->getJson('/api/validacion/etiquetas?turno=B')->assertOk()->assertJsonCount(0, 'data');
        $this->sesion()->getJson('/api/validacion/etiquetas?folio=3')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_genera_pdf_por_copias_y_reintentar_no_duplica_auditoria(): void
    {
        $payload = $this->payload();
        $response = $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('/Count 2', $response->getContent());
        $this->assertStringContainsString('(0000000003)', $response->getContent());
        $this->assertStringContainsString('105411', $response->getContent());
        $this->assertSame(2, substr_count($response->getContent(), '% QR folio'));
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertOk()->assertHeader('X-Impresion-Id', $response->headers->get('X-Impresion-Id'));
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 1);
        $this->assertDatabaseCount('impresion_etiqueta_pt_folios', 1);
        $this->sesion()->getJson('/api/validacion/etiquetas/historial')->assertOk()
            ->assertJsonPath('data.0.folios.0', '0000000003');
        $this->sesion()->postJson('/api/validacion/etiquetas', [...$payload, 'copias' => 3])->assertConflict();
    }

    public function test_reimpresion_exige_motivo_y_conserva_snapshot(): void
    {
        $this->sesion()->postJson('/api/validacion/etiquetas', $this->payload())->assertOk();
        $this->sesion()->postJson('/api/validacion/etiquetas', $this->payload())->assertUnprocessable();
        $this->sesion()->postJson('/api/validacion/etiquetas', [...$this->payload(), 'motivo_reimpresion' => 'Etiqueta dañada'])->assertOk();
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 2);
        $this->assertDatabaseHas('impresiones_etiquetas_pt', ['motivo_reimpresion' => 'Etiqueta dañada']);
    }

    public function test_imprime_varios_folios_en_un_pdf_y_audita_cada_uno(): void
    {
        $folio = $this->validacion->folio->replicate();
        $folio->numero_folio = '0000000004';
        $folio->save();
        $validacion = $this->validacion->replicate();
        $validacion->operacion_id = (string) Str::uuid();
        $validacion->numero_folio = $folio->numero_folio;
        $validacion->folio_id = $folio->id;
        $validacion->save();
        $items = $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()->json('data');
        $payload = [...$this->payload(), 'validaciones' => array_map(fn ($item) => ['id' => $item['validacion_id'], 'version' => $item['version']], $items)];
        $response = $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertOk();
        $this->assertStringContainsString('/Count 4', $response->getContent());
        $this->assertStringContainsString('(0000000004)', $response->getContent());
        $this->assertDatabaseCount('impresion_etiqueta_pt_folios', 2);
    }

    public function test_rechaza_cambios_desde_la_revision_y_publica_la_cantidad_actual_del_repa(): void
    {
        $payload = $this->payload();
        $folio = $this->validacion->folio;
        $folio->update(['datos_externos' => [...$folio->datos_externos, 'cantidad_cajas' => 70,
            'composicion' => [[...$folio->datos_externos['composicion'][0], 'cantidad_cajas' => 70]]]]);
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertConflict();
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 0);
        $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()->assertJsonPath('data.0.cantidad_cajas', 70);
    }

    public function test_excluye_conflictos_observados_anulados_y_folios_despachados(): void
    {
        $payload = $this->payload();
        foreach ([['estado' => 'conflicto'], ['estado' => 'anulada'], ['estado' => 'aceptada', 'resultado' => 'observado']] as $cambio) {
            $this->validacion->update($cambio);
            $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()->assertJsonCount(0, 'data');
            $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertUnprocessable();
        }
        $this->validacion->update(['estado' => 'aceptada', 'resultado' => 'aprobado']);
        $this->validacion->folio->update(['estado_operacional' => 'despachado']);
        $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()->assertJsonCount(0, 'data');
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertUnprocessable();
    }

    public function test_no_admite_pda_ni_usuarios_sin_permiso_y_exige_autenticacion(): void
    {
        $payload = $this->payload();
        $pda = $this->usuario->crearTokenParaDispositivo($this->validacion->dispositivo, 'pda')->plainTextToken;
        $this->sesion($pda)->postJson('/api/validacion/etiquetas', $payload)->assertForbidden();
        $this->sesion($pda)->getJson('/api/validacion/etiquetas')->assertForbidden();
        $sinPermiso = User::factory()->create(['rol' => RolUsuario::CamareroMateriales])->createToken('oficina', ['oficina'])->plainTextToken;
        $this->sesion($sinPermiso)->postJson('/api/validacion/etiquetas', $payload)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/validacion/etiquetas')->assertUnauthorized();
    }

    public function test_cambiar_temporada_impide_generar_etiquetas_de_la_anterior(): void
    {
        $payload = $this->payload();
        $this->crearTemporadaActivaPrueba(['codigo' => 'PT-NUEVA', 'nombre' => 'Nueva']);
        app(ServicioTemporadaActiva::class)->olvidar();
        $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()->assertJsonCount(0, 'data');
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertUnprocessable();
    }

    public function test_rechaza_duplicados_y_lotes_fuera_del_limite_sin_registrar_pdf(): void
    {
        $payload = $this->payload();
        $payload['validaciones'][] = $payload['validaciones'][0];
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertUnprocessable();
        $payload['validaciones'] = array_fill(0, 51, $payload['validaciones'][0]);
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 0);
    }

    public function test_vista_en_oficina_tiene_navegacion_y_revisar_datos(): void
    {
        $this->get('/oficina/validacion/etiquetas')->assertOk()->assertSee('Etiquetas PT')->assertSee('ptReview', false);
    }

    public function test_planta_calcula_kilos_de_composicion_y_registra_el_estado_impreso_idempotentemente(): void
    {
        $envase = $this->envasePlanta(9);
        $folio = $this->validacion->folio;
        $folio->update(['datos_externos' => [...$folio->datos_externos, 'cantidad_cajas' => 77,
            'composicion' => [[...$folio->datos_externos['composicion'][0], 'cantidad_cajas' => 77, 'envase_validacion_id' => $envase->id]]]]);
        $item = $this->sesion()->getJson('/api/validacion/etiquetas?origen=validacion')->assertOk()
            ->assertJsonPath('data.0.kilos_netos', '693.00')->assertJsonPath('data.0.envase_codigo', 'RGEN90BAM')
            ->assertJsonPath('data.0.origen', 'validacion')->json('data.0');
        $payload = $this->payloadPlanta($item);
        $response = $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertOk();
        $this->assertStringContainsString('/Count 4', $response->getContent());
        $this->assertStringContainsString('(693,00)', $response->getContent());
        $this->assertStringContainsString('(PROCESO)', $response->getContent());
        $this->assertSame(4, substr_count($response->getContent(), '% QR folio'));
        $envase->update(['kilos_netos_por_caja' => 10]);
        $this->sesion()->postJson('/api/validacion/etiquetas', $payload)->assertOk()
            ->assertHeader('X-Impresion-Id', $response->headers->get('X-Impresion-Id'));
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 1);
        $snapshot = ImpresionEtiquetaPt::firstOrFail();
        $this->assertSame(4, $snapshot->copias);
        $this->assertSame($this->usuario->id, $snapshot->user_id);
        $this->assertSame('693.00', $snapshot->etiquetas_snapshot[0]['kilos_netos']);
        $this->sesion()->postJson('/api/validacion/etiquetas', [...$payload, 'operacion_id' => (string) Str::uuid()])->assertConflict();
    }

    public function test_planta_informa_envase_sin_kilos_y_lo_imprime_desconocido(): void
    {
        $this->envasePlanta(null);
        $item = $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()
            ->assertJsonPath('data.0.kilos_netos', null)
            ->assertJsonPath('data.0.envases_sin_kilos.0.nombre', '5 kg')->json('data.0');
        $response = $this->sesion()->postJson('/api/validacion/etiquetas', $this->payloadPlanta($item))->assertOk();
        $this->assertStringContainsString("(\x97)", $response->getContent());
    }

    public function test_repaletizado_aparece_sin_validacion_y_suma_composicion_mixta(): void
    {
        $envase = $this->envasePlanta(9);
        $folio = $this->validacion->folio->replicate();
        $folio->numero_folio = 'REPA-0001';
        $folio->origen_sistema = 'repaletizaje';
        $folio->fecha_proceso_pt = '2026-02-06';
        $folio->datos_externos = [...$folio->datos_externos, 'cantidad_cajas' => 77, 'composicion' => [
            ['csg' => '105410', 'variedad' => 'Santina', 'envase_validacion_id' => $envase->id, 'cantidad_cajas' => 40],
            ['csg' => '105411', 'variedad' => 'Lapins', 'envase_validacion_id' => $envase->id, 'cantidad_cajas' => 37],
        ]];
        $folio->save();
        $item = $this->sesion()->getJson('/api/validacion/etiquetas?origen=repaletizaje')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.validacion_id', null)->assertJsonPath('data.0.variedad', 'MIXTA')
            ->assertJsonPath('data.0.kilos_netos', '693.00')->assertJsonPath('data.0.cantidad_cajas', 77)->json('data.0');
        $response = $this->sesion()->postJson('/api/validacion/etiquetas', $this->payloadPlanta($item))->assertOk();
        $this->assertStringContainsString('(REPALETIZADO)', $response->getContent());
        $this->assertStringContainsString('(MIXTA)', $response->getContent());
        $this->assertStringContainsString('(06-02-2026)', $response->getContent());
        $envase2 = $envase->replicate();
        $envase2->nombre = 'Caja 5 kg';
        $envase2->codigo_externo = 'CAJA5';
        $envase2->kilos_netos_por_caja = 5;
        $envase2->save();
        $datos = $folio->datos_externos;
        $datos['composicion'][1]['envase_validacion_id'] = $envase2->id;
        $datos['composicion'][1]['envase'] = $envase2->nombre;
        $folio->update(['datos_externos' => $datos, 'fecha_proceso_pt' => null]);
        $this->sesion()->getJson('/api/validacion/etiquetas?origen=repaletizaje')->assertOk()
            ->assertJsonPath('data.0.envase', 'MIXTO')->assertJsonPath('data.0.envase_codigo', 'MIXTO')
            ->assertJsonPath('data.0.kilos_netos', '545.00')->assertJsonPath('data.0.fecha_proceso', null);
    }

    public function test_tarjador_imprime_desde_oficina_sin_acceso_a_consultar_validaciones(): void
    {
        $tarjador = User::factory()->create(['rol' => RolUsuario::Tarjador]);
        $token = $tarjador->createToken('oficina', ['oficina'])->plainTextToken;
        $item = $this->sesion($token)->getJson('/api/validacion/etiquetas')->assertOk()->json('data.0');
        $this->sesion($token)->postJson('/api/validacion/etiquetas', $this->payloadPlanta($item))->assertOk();
        $this->sesion($token)->getJson('/api/validacion/pallets')->assertForbidden();
    }

    public function test_administracion_guarda_y_limpia_kilos_por_caja_con_validacion_decimal(): void
    {
        $envase = $this->envasePlanta(null);
        $datos = ['especie_validacion_id' => $envase->especie_validacion_id, 'cliente_validacion_id' => $envase->cliente_validacion_id,
            'nombre' => $envase->nombre, 'codigo_externo' => $envase->codigo_externo, 'activo' => true, 'kilos_netos_por_caja' => '9.1250'];
        $ruta = '/api/administracion/validacion/envases/'.$envase->id;
        $this->sesion()->putJson($ruta, $datos)->assertOk();
        $this->assertSame('9.1250', $envase->refresh()->kilos_netos_por_caja);
        $this->sesion()->putJson($ruta, [...$datos, 'kilos_netos_por_caja' => -1])->assertUnprocessable();
        $this->sesion()->putJson($ruta, [...$datos, 'kilos_netos_por_caja' => null])->assertOk();
        $this->assertNull($envase->refresh()->kilos_netos_por_caja);
    }

    private function envasePlanta(?float $kilos): EnvaseValidacion
    {
        $cliente = ClienteValidacion::create(['temporada_id' => $this->validacion->temporada_id, 'nombre' => 'DIS', 'activo' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $this->validacion->temporada_id, 'nombre' => 'Cereza', 'activo' => true]);

        return EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cliente->id,
            'nombre' => '5 kg', 'codigo_externo' => 'RGEN90BAM', 'kilos_netos_por_caja' => $kilos, 'activo' => true]);
    }

    private function payloadPlanta(array $item): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->validacion->temporada_id,
            'tipo' => 'planta', 'folios' => [['id' => $item['folio_id'], 'version' => $item['version']]]];
    }

    private function payload(): array
    {
        $item = $this->sesion()->getJson('/api/validacion/etiquetas')->assertOk()->json('data.0');

        return ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->validacion->temporada_id,
            'tipo' => 'ventana', 'copias' => 2, 'motivo_reimpresion' => null,
            'validaciones' => [['id' => $item['validacion_id'], 'version' => $item['version']]]];
    }

    private function sesion(?string $token = null): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }
}
