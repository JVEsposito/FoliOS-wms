<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CondicionSag;
use App\Models\CsgValidacion;
use App\Models\Dispositivo;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\OrigenValidacion;
use App\Models\PlantaOrigen;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecepcionFrutaEmbaladaApiTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desactivarTemporadasDePrueba();
        $temporada = Temporada::create(['codigo' => 'RF-2026', 'nombre' => 'RF 2026', 'activa' => true, 'version_catalogo' => 1]);
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Validador, 'activo' => true]);
        $cliente = Cliente::create(['codigo' => 'RF', 'nombre' => 'Cliente RF', 'activo' => true]);
        $catalogo = ClienteValidacion::create(['temporada_id' => $temporada->id, 'cliente_id' => $cliente->id, 'nombre' => $cliente->nombre, 'activo' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'UVA', 'activo' => true]);
        $variedad = VariedadValidacion::create(['temporada_id' => $temporada->id, 'especie_validacion_id' => $especie->id, 'nombre' => 'THOMPSON', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => '12345', 'activo' => true]);
        DB::table('csg_variedades_validacion')->insert(['csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id]);
        $origen = OrigenValidacion::create(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $catalogo->id, 'csg_validacion_id' => $csg->id, 'cliente' => $cliente->nombre, 'marca' => 'EXTERNO', 'csg' => '12345', 'activo' => true]);
        $articulo = ArticuloValidacion::create(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $catalogo->id, 'variedad_validacion_id' => $variedad->id, 'especie' => 'UVA', 'variedad' => 'THOMPSON', 'envase' => '8.2 KG', 'calibre' => 'L', 'activo' => true]);
        DB::table('combinaciones_validacion')->insert(['id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id, 'activo' => true]);
        $planta = PlantaOrigen::create(['codigo' => 'RNG', 'nombre' => 'Rengo', 'activa' => true]);
        $this->payload = [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id, 'planta_origen_id' => $planta->id,
            'numero_guia' => '123', 'servicio' => 'almacenaje', 'turno' => 'A', 'validador_id' => $this->usuario->id,
            'recepcion_at' => '2026-10-06T12:00:00Z', 'salida_at' => '2026-10-06T14:00:00Z', 'chofer' => 'Conductor',
            'rut_chofer' => '12.345.678-5', 'patente_delantera' => 'ABCD12', 'patente_carro' => 'EFGH34', 'llega_con_prefrio' => true, 'condicion_sag_id' => null,
            'pallets' => [['folio_origen' => 'EX-001', 'tipo_bulto' => 'pallet', 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id,
                'csp' => '111', 'cantidad_cajas' => 100, 'fecha_proceso_origen' => '2026-10-05', 'temperatura_pulpa_c' => 1.5, 'condicion_sag_personalizada' => false]],
        ];
        $this->actingAs($this->usuario, 'sanctum');
    }

    public function test_guarda_seis_pallets_sin_inventario_y_reintenta_sin_duplicar(): void
    {
        $this->payload['pallets'] = array_map(fn ($n) => [...$this->payload['pallets'][0], 'folio_origen' => "EX-{$n}"], range(1, 6));
        $id = $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated()->assertJsonPath('data.estado', 'borrador')->assertJsonCount(6, 'data.pallets')->json('data.id');
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('recepciones_fruta_embalada', 1);
        $this->assertDatabaseCount('eventos_recepcion_fruta_embalada', 1);
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_guia_obligatoria_duplicada_avisa_y_requiere_confirmacion(): void
    {
        $this->postJson('/api/recepciones-fruta-embalada', [...$this->payload, 'numero_guia' => ''])->assertUnprocessable()->assertJsonValidationErrors('numero_guia');
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated();
        $params = http_build_query(collect($this->payload)->only(['cliente_id', 'planta_origen_id', 'numero_guia'])->all());
        $this->getJson('/api/recepciones-fruta-embalada/revisar-guia?'.$params)->assertOk()->assertJsonPath('duplicada', true);
        $otro = [...$this->payload, 'operacion_id' => (string) Str::uuid()];
        $this->postJson('/api/recepciones-fruta-embalada', $otro)->assertUnprocessable()->assertJsonValidationErrors('numero_guia');
        $this->postJson('/api/recepciones-fruta-embalada', [...$otro, 'confirmar_guia_duplicada' => true])->assertCreated();
    }

    public function test_csg_de_otro_cliente_y_variedad_no_asociada_se_rechazan_sin_escrituras(): void
    {
        OrigenValidacion::whereKey($this->payload['pallets'][0]['origen_validacion_id'])->update(['cliente_validacion_id' => null]);
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('pallets.0.origen_validacion_id');
        OrigenValidacion::whereKey($this->payload['pallets'][0]['origen_validacion_id'])->update(['cliente_validacion_id' => ClienteValidacion::first()->id]);
        DB::table('csg_variedades_validacion')->delete();
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('pallets.0.articulo_validacion_id');
        $this->assertDatabaseCount('recepciones_fruta_embalada', 0);
    }

    public function test_sag_hereda_y_respeta_ajuste_individual_incluso_sin_condicion(): void
    {
        $sag = CondicionSag::create(['codigo' => 'AP', 'nombre' => 'Aprobado', 'activo' => true]);
        $this->payload['condicion_sag_id'] = $sag->id;
        $this->payload['pallets'][] = [...$this->payload['pallets'][0], 'folio_origen' => 'EX-002', 'condicion_sag_personalizada' => true, 'condicion_sag_id' => null];
        $resp = $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated()->assertJsonPath('data.pallets.0.condicion_sag_id', $sag->id)->assertJsonPath('data.pallets.1.condicion_sag_id', null);
        $edit = [...$this->payload, 'operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'condicion_sag_id' => null];
        foreach ($edit['pallets'] as $i => &$pallet) {
            $pallet['id'] = $resp->json("data.pallets.{$i}.id");
        }
        unset($pallet);
        $this->putJson('/api/recepciones-fruta-embalada/'.$resp->json('data.id'), $edit)->assertOk()->assertJsonPath('data.pallets.0.condicion_sag_id', null);
    }

    public function test_folio_vigente_avisa_al_escanear_sin_alterarlo(): void
    {
        Folio::create(['tipo_bulto' => 'pallet', 'fecha_ingreso' => now(), 'numero_folio' => 'EX-001', 'temporada_id' => $this->payload['temporada_id'], 'activo' => true, 'estado_operacional' => 'pendiente_prefrio']);
        $this->getJson('/api/recepciones-fruta-embalada/revisar-folio?folio_origen=ex-001')->assertOk()->assertJsonPath('repetido', true)->assertJsonPath('mensaje', 'Se asignará folio interno al aceptar');
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated()->assertJsonPath('data.pallets.0.folio_repetido', true);
        $this->assertDatabaseCount('folios', 1);
    }

    public function test_temporada_cerrada_estado_aceptado_y_version_antigua_no_permiten_editar(): void
    {
        $id = $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated()->json('data.id');
        $edit = [...$this->payload, 'operacion_id' => (string) Str::uuid(), 'version_conocida' => 9];
        $this->putJson('/api/recepciones-fruta-embalada/'.$id, $edit)->assertConflict();
        RecepcionFrutaEmbalada::whereKey($id)->update(['estado' => 'aceptada']);
        $edit['version_conocida'] = 1;
        $this->putJson('/api/recepciones-fruta-embalada/'.$id, $edit)->assertConflict();
        RecepcionFrutaEmbalada::whereKey($id)->update(['estado' => 'borrador']);
        DB::table('temporadas')->where('id', $this->payload['temporada_id'])->update(['activa' => false]);
        $this->putJson('/api/recepciones-fruta-embalada/'.$id, $edit)->assertConflict();
    }

    public function test_no_acepta_inventario_desde_el_request_y_no_reutiliza_operacion_con_datos_distintos(): void
    {
        $this->postJson('/api/recepciones-fruta-embalada', [...$this->payload, 'estado' => 'aceptada'])->assertUnprocessable();
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated();
        $this->postJson('/api/recepciones-fruta-embalada', [...$this->payload, 'chofer' => 'Otro'])->assertConflict();
    }

    public function test_opciones_catalogo_condicional_y_filtros(): void
    {
        $this->getJson('/api/recepciones-fruta-embalada/opciones')->assertOk()->assertJsonCount(1, 'clientes')->assertJsonCount(1, 'plantas_origen');
        $etag = $this->getJson('/api/recepciones-fruta-embalada/catalogo-pt')->assertOk()->headers->get('ETag');
        $this->withHeader('If-None-Match', $etag)->get('/api/recepciones-fruta-embalada/catalogo-pt')->assertStatus(304);
        $this->withHeader('If-None-Match', '')->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertCreated();
        $this->getJson('/api/recepciones-fruta-embalada?estado=borrador&desde=2026-10-06&hasta=2026-10-06')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/recepciones-fruta-embalada?estado=aceptada')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_consulta_no_escribe_y_token_tablet_exige_habilidad_del_modulo(): void
    {
        $this->usuario->update(['rol' => RolUsuario::Consulta]);
        $this->postJson('/api/recepciones-fruta-embalada', $this->payload)->assertForbidden();
        $this->usuario->update(['rol' => RolUsuario::Validador]);
        $device = Dispositivo::create(['codigo' => 'RF-PDA', 'nombre' => 'RF PDA', 'activo' => true]);
        $token = $this->usuario->createToken('tablet', ['tablet:validacion']);
        $token->accessToken->update(['dispositivo_id' => $device->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/recepciones-fruta-embalada')->assertForbidden();
        $token->accessToken->update(['abilities' => [CatalogoModulosAcceso::habilidadTablet(CatalogoModulosAcceso::TABLET_RECEPCION_FRUTA_EMBALADA)]]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/recepciones-fruta-embalada')->assertOk();
    }

    public function test_encabezado_solo_y_error_en_ultimo_pallet_no_dejan_captura_parcial(): void
    {
        $encabezado = [...$this->payload, 'pallets' => []];
        $id = $this->postJson('/api/recepciones-fruta-embalada', $encabezado)->assertCreated()->assertJsonCount(0, 'data.pallets')->json('data.id');
        $edit = [...$this->payload, 'operacion_id' => (string) Str::uuid(), 'version_conocida' => 1];
        $edit['pallets'] = array_map(fn ($n) => [...$edit['pallets'][0], 'folio_origen' => "EX-{$n}"], range(1, 6));
        $edit['pallets'][5]['articulo_validacion_id'] = (string) Str::uuid();
        $this->putJson('/api/recepciones-fruta-embalada/'.$id, $edit)->assertUnprocessable()->assertJsonValidationErrors('pallets.5.articulo_validacion_id');
        $this->assertDatabaseCount('recepciones_fruta_embalada_pallets', 0);
        $this->assertDatabaseCount('eventos_recepcion_fruta_embalada', 1);
        $this->assertSame(1, RecepcionFrutaEmbalada::findOrFail($id)->version);
        $this->assertDatabaseCount('folios', 0);
    }

    public function test_datos_malformados_y_usuario_sin_permiso_para_validar_no_se_aceptan(): void
    {
        $this->postJson('/api/recepciones-fruta-embalada', [...$this->payload, 'numero_guia' => ['123']])->assertUnprocessable()->assertJsonValidationErrors('numero_guia');
        $otro = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $this->postJson('/api/recepciones-fruta-embalada', [...$this->payload, 'validador_id' => $otro->id])->assertUnprocessable()->assertJsonValidationErrors('validador_id');
        $this->assertDatabaseCount('recepciones_fruta_embalada', 0);
    }

    public function test_catalogos_administrativos_auditados_y_versionados(): void
    {
        $this->getJson('/api/administracion/fruta-embalada')->assertForbidden();
        $this->usuario->update(['rol' => RolUsuario::Administrador]);
        $planta = $this->postJson('/api/administracion/fruta-embalada/plantas', ['codigo' => 'NEW', 'nombre' => 'Nueva', 'activa' => true])->assertCreated()->json('data');
        $this->putJson('/api/administracion/fruta-embalada/plantas/'.$planta['id'], ['codigo' => 'NEW', 'nombre' => 'Nueva 2', 'activa' => false, 'version_conocida' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/administracion/fruta-embalada/plantas/'.$planta['id'], ['codigo' => 'NEW', 'nombre' => 'Nueva 3', 'activa' => true, 'version_conocida' => 1])->assertConflict();
        $this->postJson('/api/administracion/fruta-embalada/umbrales', ['especie' => 'uva', 'temperatura_maxima_c' => 2])->assertCreated()->assertJsonPath('data.especie', 'UVA');
        $this->assertDatabaseCount('eventos_catalogo_fruta_embalada', 3);
    }
}
