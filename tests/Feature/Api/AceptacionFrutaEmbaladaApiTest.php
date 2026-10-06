<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Folio;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PreparaRecepcionFrutaEmbalada;
use Tests\TestCase;

class AceptacionFrutaEmbaladaApiTest extends TestCase
{
    use PreparaRecepcionFrutaEmbalada;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRecepcionFrutaEmbalada();
    }

    public function test_acepta_con_numero_origen_sag_habilitacion_prioridad_y_trazabilidad(): void
    {
        $payload = $this->payload();
        $resultado = $this->withToken($this->token)->postJson($this->ruta('aceptar'), $payload)->assertOk()
            ->assertJsonPath('data.estado', 'aceptada')->assertJsonPath('data.folios.0.numero_folio', 'EXT00001')
            ->assertJsonPath('data.folios.0.estado_operacional', 'pendiente_ubicacion')->json('data');
        $folio = Folio::findOrFail($resultado['folios'][0]['folio_id']);
        $this->assertSame($this->pallet['condicion_sag_id'], $folio->condicion_sag_id);
        $this->assertSame('prefrio_origen', $folio->fuente_habilitacion_almacenamiento->value);
        $this->assertSame('CSP-123', $folio->datos_externos['csp']);
        $this->assertDatabaseHas('tareas_movimiento', ['folio_id' => $folio->id, 'prioridad' => 'alta', 'tipo_movimiento' => 'ubicacion_inicial']);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $payload)->assertOk();
        $this->assertDatabaseCount('recepcion_fruta_embalada_folios', 1);
        $this->assertDatabaseCount('aceptaciones_fruta_embalada', 1);
        $this->assertDatabaseCount('eventos_recepcion_fruta_embalada', 2);
        $this->assertSame(2, RecepcionFrutaEmbalada::findOrFail($this->recepcion)->version);
        $this->withToken($this->token)->getJson('/api/consultas/folios/'.$folio->id)->assertOk()
            ->assertJsonPath('folio.especificaciones.guia', 'GUIA-100')->assertJsonPath('folio.especificaciones.referencia_externa', 'EXT00001');
    }

    public function test_choque_activo_o_historico_asigna_folio_interno_y_permite_referencias_externas_repetidas(): void
    {
        Folio::create(['numero_folio' => 'EXT00001', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'agotado', 'activo' => false, 'fecha_ingreso' => now()]);
        $otro = [...$this->pallet, 'id' => (string) Str::uuid(), 'orden' => 2];
        DB::table('recepciones_fruta_embalada_pallets')->insert($otro);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk();
        $folios = Folio::where('origen_sistema', 'recepcion_externa')->orderBy('numero_folio')->get();
        $this->assertSame(['INT0000000001', 'INT0000000002'], $folios->pluck('numero_folio')->all());
        $this->assertSame(['EXT00001', 'EXT00001'], $folios->pluck('identificador_externo')->all());
        $this->assertDatabaseCount('recepcion_fruta_embalada_folios', 2);
    }

    public function test_sin_prefrio_queda_pendiente_sin_tareas(): void
    {
        DB::table('recepciones_fruta_embalada')->where('id', $this->recepcion)->update(['llega_con_prefrio' => false]);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk()
            ->assertJsonPath('data.folios.0.estado_operacional', 'pendiente_prefrio')->assertJsonPath('data.plan_operacional_id', null);
        $this->assertDatabaseCount('tareas_movimiento', 0);
    }

    public function test_prefrio_declarado_fuera_de_umbral_abre_incidencia_y_no_habilita(): void
    {
        DB::table('recepciones_fruta_embalada_pallets')->where('id', $this->pallet['id'])->update(['temperatura_pulpa_c' => 4.2]);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk()
            ->assertJsonPath('data.folios.0.habilitacion_almacenamiento', 'no_habilitado')->assertJsonCount(1, 'data.incidencias');
        $this->assertDatabaseHas('incidencias_recepcion_embalada', ['temperatura_pulpa' => 4.2, 'umbral_prefrio' => 2, 'estado' => 'abierta']);
        $this->withToken($this->token)->getJson('/api/operacion-ahora')->assertOk()->assertJsonPath('data.incidencias.resumen.recepcion_embalada', 1)
            ->assertJsonPath('data.incidencias.abiertas.0.origen', 'recepcion_embalada')->assertJsonPath('data.incidencias.abiertas.0.folio.numero_folio', 'EXT00001');
    }

    public function test_sin_umbral_respeta_declaracion_con_advertencia_y_saldo_no_entra_al_planificador(): void
    {
        DB::table('umbrales_prefrio_especies')->delete();
        DB::table('recepciones_fruta_embalada_pallets')->where('id', $this->pallet['id'])->update(['tipo_bulto' => 'saldo', 'temperatura_pulpa_c' => 8]);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk()
            ->assertJsonPath('data.folios.0.habilitacion_almacenamiento', 'habilitado')
            ->assertJsonPath('data.advertencias.0.tipo', 'sin_umbral_prefrio')->assertJsonPath('data.plan_operacional_id', null);
    }

    public function test_fallo_del_planificador_revierte_todos_los_folios_y_la_aceptacion(): void
    {
        DB::table('recepciones_fruta_embalada_pallets')->insert([...$this->pallet, 'id' => (string) Str::uuid(), 'orden' => 2, 'folio_origen' => 'EXT00002']);
        $this->mock(ServicioPlanesOperacionales::class)->shouldReceive('crear')->once()->andThrow(new DomainException('No se pudo crear el destino.'));
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('aceptaciones_fruta_embalada', 0);
        $this->assertDatabaseCount('historial_habilitaciones_almacenamiento', 0);
        $this->assertDatabaseHas('recepciones_fruta_embalada', ['id' => $this->recepcion, 'estado' => 'borrador']);
    }

    public function test_catalogo_cambiado_o_recepcion_de_otra_temporada_no_se_acepta(): void
    {
        $payload = $this->payload();
        DB::table('umbrales_prefrio_especies')->update(['temperatura_maxima_c' => 0]);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $payload)->assertConflict();
        DB::table('csg_variedades_validacion')->delete();
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('folios', 0);
    }

    public function test_anula_sin_movimientos_inactiva_folios_cancela_tareas_y_es_idempotente(): void
    {
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk();
        $datos = ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Guía recibida por error.'];
        $this->withToken($this->token)->postJson($this->ruta('anular'), $datos)->assertOk()->assertJsonPath('data.estado', 'anulada');
        $this->withToken($this->token)->postJson($this->ruta('anular'), $datos)->assertOk();
        $this->assertDatabaseHas('folios', ['numero_folio' => 'EXT00001', 'activo' => false, 'estado_operacional' => 'anulado']);
        $this->assertDatabaseHas('tareas_movimiento', ['estado' => 'cancelada']);
        $this->assertDatabaseHas('maniobras_operacionales', ['estado' => 'cancelada']);
    }

    public function test_actividad_fisica_posterior_impide_anular_sin_cancelaciones_parciales(): void
    {
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk();
        DB::table('tareas_movimiento')->update(['estado' => 'en_proceso']);
        $this->withToken($this->token)->postJson($this->ruta('anular'), ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Intentar anular recepción.'])->assertConflict();
        $this->assertDatabaseHas('folios', ['numero_folio' => 'EXT00001', 'activo' => true]);
        $this->assertDatabaseHas('recepciones_fruta_embalada', ['id' => $this->recepcion, 'estado' => 'aceptada']);
    }

    public function test_temporada_inactiva_y_usuarios_sin_permiso_no_crean_inventario(): void
    {
        $payload = $this->payload();
        $token = User::factory()->create(['rol' => RolUsuario::CamareroMateriales])->createToken('oficina', ['oficina'])->plainTextToken;
        $this->withToken($token)->postJson($this->ruta('aceptar'), $payload)->assertForbidden();
        $this->crearTemporadaActivaPrueba(['codigo' => 'OTRA-EXTERNA', 'nombre' => 'Otra temporada']);
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('folios', 0);
    }
}
