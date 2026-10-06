<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\CalibreValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CondicionSag;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\OrigenValidacion;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\UmbralPrefrioEspecie;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Estiba\ServicioPlanesOperacionales;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AceptacionFrutaEmbaladaApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $usuario;

    protected string $token;

    protected string $recepcion;

    protected array $pallet;

    protected EspecieValidacion $especie;

    protected function setUp(): void
    {
        parent::setUp();
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'tablet', 'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $temporada = $this->crearTemporadaActivaPrueba(['codigo' => 'EXTERNA', 'nombre' => 'Fruta embalada']);
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->token = $this->usuario->createToken('oficina', ['oficina'])->plainTextToken;
        $global = Cliente::create(['codigo' => 'EXT', 'nombre' => 'EXPORTADORA', 'activo' => true]);
        $cliente = ClienteValidacion::create(['temporada_id' => $temporada->id, 'cliente_id' => $global->id, 'nombre' => 'EXPORTADORA', 'activo' => true]);
        $this->especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Uva', 'activo' => true]);
        UmbralPrefrioEspecie::create(['especie' => 'UVA', 'temperatura_maxima_c' => 2]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $this->especie->id, 'nombre' => 'Thompson', 'activo' => true]);
        $envase = EnvaseValidacion::create(['especie_validacion_id' => $this->especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => 'Caja 9 kg', 'codigo_externo' => 'RGEN90BAM', 'kilos_netos_por_caja' => 9, 'activo' => true]);
        $calibre = CalibreValidacion::create(['especie_validacion_id' => $this->especie->id, 'nombre' => 'XL', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => '105410', 'activo' => true]);
        $csg->variedades()->attach($variedad);
        $sag = CondicionSag::create(['codigo' => 'EX-SAG', 'nombre' => 'Aprobado', 'activo' => true]);
        $planta = (string) Str::uuid();
        DB::table('plantas_origen')->insert(['id' => $planta, 'nombre' => 'Planta de origen', 'codigo' => 'PORI', 'activa' => true]);
        $origen = OrigenValidacion::create(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $cliente->id, 'csg_validacion_id' => $csg->id, 'cliente' => $cliente->nombre, 'marca' => 'EXTERNO', 'csg' => $csg->codigo, 'activo' => true]);
        $articulo = ArticuloValidacion::create(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $cliente->id, 'especie_validacion_id' => $this->especie->id, 'variedad_validacion_id' => $variedad->id, 'envase_validacion_id' => $envase->id, 'calibre_validacion_id' => $calibre->id, 'especie' => 'Uva', 'variedad' => 'Thompson', 'envase' => 'Caja 9 kg', 'calibre' => 'XL', 'activo' => true]);
        DB::table('combinaciones_validacion')->insert(['id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id, 'activo' => true]);
        $pallet = ['folio_origen' => 'EXT00001', 'tipo_bulto' => 'pallet', 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id,
            'csp' => 'CSP-123', 'cantidad_cajas' => 77, 'fecha_proceso_origen' => '2026-02-06', 'temperatura_pulpa_c' => 1, 'condicion_sag_personalizada' => true, 'condicion_sag_id' => $sag->id];
        $this->recepcion = $this->withToken($this->token)->postJson('/api/recepciones-fruta-embalada', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $global->id, 'planta_origen_id' => $planta,
            'numero_guia' => 'GUIA-100', 'servicio' => 'almacenaje', 'turno' => 'A', 'validador_id' => $this->usuario->id,
            'recepcion_at' => now()->toISOString(), 'salida_at' => null, 'chofer' => 'Chofer externo', 'rut_chofer' => null,
            'patente_delantera' => 'ABCD12', 'patente_carro' => null, 'llega_con_prefrio' => true, 'condicion_sag_id' => null,
            'pallets' => [$pallet],
        ])->assertCreated()->json('data.id');
        $this->pallet = (array) DB::table('recepciones_fruta_embalada_pallets')->where('recepcion_fruta_embalada_id', $this->recepcion)->first();
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

    protected function payload(): array
    {
        $version = $this->withToken($this->token)->getJson($this->ruta('aceptacion'))->assertOk()->json('data.revision.version');

        return ['operacion_id' => (string) Str::uuid(), 'version' => $version];
    }

    protected function ruta(string $accion): string
    {
        return '/api/recepciones-fruta-embalada/'.$this->recepcion.'/'.$accion;
    }
}
