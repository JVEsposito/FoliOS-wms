<?php

namespace Tests\Feature\Api;

use App\Models\BloqueMercado;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\Embarque;
use App\Models\Folio;
use App\Models\InstructivoEmbarque;
use App\Models\OrigenValidacion;
use App\Models\Pais;
use App\Models\PerfilAcceso;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Cargas\RevisionCargaOperacional;
use App\Services\Clientes\ServicioCliente;
use App\Services\InspeccionSag\ServicioRetencionesLoteSag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;
use ZipArchive;

class RetencionesLoteSagApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => 'administrador', 'activo' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_solo_perfiles_contraparte_y_administracion_gestionan_el_modulo(): void
    {
        $this->getJson('/api/sag/lotes-retenidos/catalogo')->assertOk()->assertJsonStructure(['clientes', 'paises', 'bloques', 'temporada']);
        foreach (['supervisor_frio', 'camarero_frio', 'despachador', 'consulta'] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')->getJson('/api/sag/lotes-retenidos/catalogo')->assertForbidden();
        }
        $perfil = PerfilAcceso::where('codigo', 'CONTRAPARTE_SAG')->firstOrFail();
        $contraparte = User::factory()->create(['rol' => 'supervisor_frio', 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($contraparte, 'sanctum')->getJson('/api/sag/lotes-retenidos/catalogo')->assertOk();
        $perfil->update(['activo' => false]);
        $contraparte->unsetRelation('perfilAcceso');
        $this->getJson('/api/sag/lotes-retenidos/catalogo')->assertForbidden();
    }

    public function test_cliente_lote_y_temporada_son_la_identidad_y_un_reintento_no_duplica(): void
    {
        $cliente = $this->cliente('UNO');
        $folio = $this->trazar(null, $cliente, '10056', 1);
        $otro = $this->trazar(null, $this->cliente('DOS'), '10056', 120);
        $datos = $this->datos($cliente);
        $caso = $this->postJson('/api/sag/lotes-retenidos', $datos)->assertOk()
            ->assertJsonPath('folios.0.cajas_lote', 1)->assertJsonCount(1, 'folios')->json('registro');
        $this->postJson('/api/sag/lotes-retenidos', $datos)->assertOk()->assertJsonPath('registro.id', $caso['id']);
        $this->postJson('/api/sag/lotes-retenidos', [...$datos, 'observacion' => 'Intento distinto con el mismo UUID'])->assertConflict();
        $this->postJson('/api/sag/lotes-retenidos', [...$datos, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('eventos_retencion_lote_sag', 1);
        $alertas = app(ServicioRetencionesLoteSag::class)->paraFolios([$folio->id, $otro->id]);
        $this->assertArrayHasKey($folio->id, $alertas);
        $this->assertArrayNotHasKey($otro->id, $alertas);
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'activo' => true, 'estado_operacional' => 'disponible']);
    }

    public function test_folios_posteriores_y_composicion_dividida_heredan_el_control_y_la_liberacion_no_borra_otro_lote(): void
    {
        $cliente = $this->cliente('FUTURO');
        $a = $this->postJson('/api/sag/lotes-retenidos', $this->datos($cliente))->assertOk()->assertJsonCount(0, 'folios')->json('registro');
        $b = $this->postJson('/api/sag/lotes-retenidos', [...$this->datos($cliente), 'numero_lote' => 'OTRO'])->assertOk()->json('registro');
        $folio = $this->trazar(null, $cliente, '10056', 80);
        $segundo = $this->trazar(null, $cliente, '10056', 40);
        $externos = $folio->datos_externos;
        $externos['composicion'][] = [...$externos['composicion'][0], 'lote_materia_prima' => 'OTRO', 'cantidad_cajas' => 40];
        $folio->update(['datos_externos' => $externos]);
        $servicio = app(ServicioRetencionesLoteSag::class);
        $this->assertCount(2, $servicio->paraFolios([$folio->id])[$folio->id]);
        $this->assertSame(40, $servicio->paraFolios([$segundo->id])[$segundo->id][0]['cajas_lote']);
        $this->putJson('/api/sag/lotes-retenidos/'.$a['id'], $this->resolucion($a, ['estado' => 'liberado']))->assertOk();
        $this->assertSame($b['id'], $servicio->paraFolios([$folio->id])[$folio->id][0]['id']);
        $this->assertArrayNotHasKey($segundo->id, $servicio->paraFolios([$segundo->id]));
    }

    public function test_un_seguimiento_previo_a_la_digitacion_conserva_el_vinculo_si_se_corrige_el_numero_del_lote(): void
    {
        $cliente = $this->cliente('ALIAS');
        $caso = $this->postJson('/api/sag/lotes-retenidos', $this->datos($cliente))->assertOk()->json('registro');
        $primero = $this->trazar(null, $cliente, '10056', 5);
        $ctx = $this->loteMpEnCamara('SAGALIAS', ['numero_lote' => '10056'], $cliente);
        $this->assertDatabaseHas('retenciones_lote_sag', ['id' => $caso['id'], 'lote_materia_prima_id' => $ctx['lote']['id']]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/materia-prima/lotes/'.$ctx['lote']['id'].'/corregir-numero', [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $ctx['lote']['version'],
            'numero_lote' => '10057', 'motivo' => 'Corregir el número digitado en el lote de recepción.',
        ])->assertOk();
        $posterior = $this->trazar(null, $cliente, '10057', 15);
        $alertas = app(ServicioRetencionesLoteSag::class)->paraFolios([$primero->id, $posterior->id]);
        $this->assertSame($caso['id'], $alertas[$primero->id][0]['id']);
        $this->assertSame($caso['id'], $alertas[$posterior->id][0]['id']);
    }

    public function test_liberacion_parcial_versionada_y_bloques_respetan_los_destinos(): void
    {
        $cliente = $this->cliente('DESTINOS');
        $folio = $this->trazar(null, $cliente, '10056', 120);
        $pais = Pais::where('iso_alpha2', 'US')->firstOrFail();
        $otro = Pais::where('iso_alpha2', 'CN')->firstOrFail();
        $bloque = BloqueMercado::create(['codigo' => 'PRUEBA-SAG', 'nombre' => 'Bloque de prueba', 'activo' => true]);
        $bloque->paises()->attach($pais->id, ['vigente_desde' => now()->subDay()->toDateString()]);
        $caso = $this->postJson('/api/sag/lotes-retenidos', [...$this->datos($cliente), 'todos_destinos' => false,
            'destinos' => [['tipo' => 'bloque', 'id' => $bloque->id], ['tipo' => 'pais', 'id' => $otro->id]]])
            ->assertOk()->json('registro');
        $servicio = app(ServicioRetencionesLoteSag::class);
        $this->assertEqualsCanonicalizing([$pais->id, $otro->id], $servicio->paraFolios([$folio->id])[$folio->id][0]['paises_restringidos']);
        $cambio = $this->resolucion($caso, ['todos_destinos' => false, 'destinos' => [['tipo' => 'pais', 'id' => $otro->id]]]);
        $this->putJson('/api/sag/lotes-retenidos/'.$caso['id'], $cambio)->assertOk()->assertJsonPath('registro.version', 2);
        $this->putJson('/api/sag/lotes-retenidos/'.$caso['id'], $cambio)->assertOk();
        $this->putJson('/api/sag/lotes-retenidos/'.$caso['id'], [...$cambio, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->assertSame([$otro->id], $servicio->paraFolios([$folio->id])[$folio->id][0]['paises_restringidos']);
        $this->assertDatabaseCount('eventos_retencion_lote_sag', 2);
        $this->assertSame([], DB::table('reservas_posiciones_inspeccion_sag')->get()->all());
    }

    public function test_pallet_con_una_caja_retenida_puede_asignarse_y_moverse_pero_no_salir_y_el_cache_se_invalida(): void
    {
        config(['despacho.cierre_exige_pallets_cargados' => false]);
        $ctx = $this->crearContextoFrio(1, 'SAG');
        $cliente = $this->cliente('CAMION');
        $folio = $this->trazar($ctx['folios'][0], $cliente, '10056', 1);
        $revision = app(RevisionCargaOperacional::class)->calcular();
        $this->actingAs($this->admin, 'sanctum');
        $caso = $this->postJson('/api/sag/lotes-retenidos', $this->datos($cliente))->assertOk()->json('registro');
        $this->assertNotSame($revision, app(RevisionCargaOperacional::class)->calcular());
        $carga = $this->crearCargaPublicada($ctx['despachador'], $ctx['anden'], [$folio]);
        $this->enviarAlAnden($ctx, $carga, $folio);
        $payload = ['operacion_id' => (string) Str::uuid(), 'patente' => 'ABCD12', 'conductor' => 'Conductor prueba'];
        $this->conToken($ctx['tokenOficina'])->postJson('/api/cargas/'.$carga->id.'/cerrar-despacho', $payload)->assertUnprocessable();
        $this->assertTrue($folio->fresh()->activo);
        $this->assertNull($carga->fresh()->cerrada_at);
        $this->assertDatabaseHas('reservas_carga_folio', ['folio_id' => $folio->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/sag/lotes-retenidos/'.$caso['id'], $this->resolucion($caso, ['estado' => 'liberado']))->assertOk();
        $this->conToken($ctx['tokenOficina'])->postJson('/api/cargas/'.$carga->id.'/cerrar-despacho', [...$payload, 'operacion_id' => (string) Str::uuid()])->assertOk();
        $this->assertFalse($folio->fresh()->activo);
    }

    public function test_revisa_el_destino_del_instructivo_de_cada_pallet_y_falla_cerrado_si_falta(): void
    {
        $ctx = $this->crearContextoFrio(2, 'SAGDEST');
        $cliente = $this->cliente('MULTI');
        foreach ($ctx['folios'] as $folio) {
            $this->trazar($folio, $cliente, '10056', 10);
        }
        $carga = $this->crearCargaPublicada($ctx['despachador'], $ctx['anden'], $ctx['folios']);
        $embarque = Embarque::create(['temporada_id' => $carga->temporada_id, 'cliente_id' => $cliente->id, 'carga_id' => $carga->id,
            'codigo' => 'EMB-SAG', 'numero_correlativo' => 98765, 'fecha_programada' => now()->toDateString(), 'hora_programada' => '12:00', 'intervalo_minutos' => 60, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id, 'modalidad' => 'maritimo', 'estado' => 'confirmado']);
        $us = Pais::where('iso_alpha2', 'US')->firstOrFail();
        $cl = Pais::where('iso_alpha2', 'CL')->firstOrFail();
        $i1 = InstructivoEmbarque::create(['embarque_id' => $embarque->id, 'orden' => 1, 'pais_destino_id' => $us->id, 'destino_pais' => 'US']);
        $i2 = InstructivoEmbarque::create(['embarque_id' => $embarque->id, 'orden' => 2, 'pais_destino_id' => $cl->id, 'destino_pais' => 'CL']);
        $a = $carga->asignacionesActuales()->orderBy('folio_id')->get();
        $a[0]->update(['instructivo_embarque_id' => $i1->id]);
        $a[1]->update(['instructivo_embarque_id' => $i2->id]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/sag/lotes-retenidos', [...$this->datos($cliente),
            'todos_destinos' => false, 'destinos' => [['tipo' => 'pais', 'id' => $us->id]]])->assertOk();
        $servicio = app(ServicioRetencionesLoteSag::class);
        $alertas = collect($servicio->alertasCarga($carga->fresh()))->keyBy('instructivo_id');
        $this->assertTrue($alertas[$i1->id]['bloquea_salida']);
        $this->assertFalse($alertas[$i2->id]['bloquea_salida']);
        $a[1]->update(['instructivo_embarque_id' => null]);
        $alerta = collect($servicio->alertasCarga($carga->fresh()))->firstWhere('folio_id', $a[1]->folio_id);
        $this->assertTrue($alerta['bloquea_salida']);
        $this->assertTrue($alerta['destino_sin_definir']);
    }

    public function test_reportes_llevan_logo_resoluciones_y_snapshot_de_folios(): void
    {
        $cliente = $this->cliente('REPORTES');
        $this->trazar(null, $cliente, '10056', 25);
        $caso = $this->postJson('/api/sag/lotes-retenidos', $this->datos($cliente))->assertOk()->json('registro');
        $this->getJson('/api/sag/lotes-retenidos/'.$caso['id'])->assertOk()->assertJsonCount(1, 'historial.0.folios_afectados');
        $pdf = $this->get('/api/sag/lotes-retenidos/'.$caso['id'].'/archivo/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('10056', $pdf);
        $respuesta = $this->get('/api/sag/lotes-retenidos/'.$caso['id'].'/archivo/xlsx')->assertOk();
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $this->assertNotFalse($zip->getFromName('xl/media/logo-planta.jpg'));
        $this->assertStringContainsString('10056', $zip->getFromName('xl/worksheets/sheet2.xml'));
        $this->assertStringContainsString('Antecedente v1', $zip->getFromName('xl/worksheets/sheet2.xml'));
        $zip->close();
        @unlink($ruta);
    }

    public function test_no_permite_retenciones_sin_destino_ni_resoluciones_anonimas_o_cambiar_identidad(): void
    {
        $cliente = $this->cliente('REGLAS');
        $this->postJson('/api/sag/lotes-retenidos', [...$this->datos($cliente), 'todos_destinos' => false])->assertUnprocessable();
        $caso = $this->postJson('/api/sag/lotes-retenidos', [...$this->datos($cliente), 'estado' => 'con_observaciones', 'todos_destinos' => false])->assertOk()->json('registro');
        $this->putJson('/api/sag/lotes-retenidos/'.$caso['id'], $this->resolucion($caso, ['resolucion' => null]))->assertUnprocessable();
        $this->putJson('/api/sag/lotes-retenidos/'.$caso['id'], $this->resolucion($caso, ['numero_lote' => 'OTRO']))->assertUnprocessable();
        $this->assertDatabaseCount('eventos_retencion_lote_sag', 1);
    }

    private function cliente(string $codigo): Cliente
    {
        return app(ServicioCliente::class)->guardarMaestro(['codigo' => 'SAG-'.$codigo, 'nombre' => 'Cliente '.$codigo, 'activo' => true], $this->admin);
    }

    private function trazar(?Folio $folio, Cliente $cliente, string $lote, int $cajas): Folio
    {
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $catalogo = ClienteValidacion::where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)->firstOrFail();
        $origen = OrigenValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'cliente_validacion_id' => $catalogo->id,
            'cliente' => $cliente->nombre, 'marca' => 'Marca SAG', 'csg' => 'CSG-SAG'], ['activo' => true]);
        $externos = ['cantidad_cajas' => 120, 'composicion' => [[
            'origen_validacion_id' => $origen->id, 'csg' => 'CSG-SAG', 'lote_materia_prima' => $lote,
            'proceso_packing' => 'PROCESO-SAG', 'cantidad_cajas' => $cajas,
        ]]];
        if ($folio) {
            $folio->update(['datos_externos' => $externos]);
        } else {
            $folio = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'SAG-'.Str::uuid(),
                'tipo_bulto' => 'pallet', 'estado_operacional' => 'disponible', 'activo' => true, 'fecha_ingreso' => now(),
                'exportadora' => $cliente->nombre, 'datos_externos' => $externos]);
        }

        return $folio;
    }

    private function datos(Cliente $cliente): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'cliente_id' => $cliente->id, 'numero_lote' => '10056',
            'estado' => 'retenido', 'fecha_deteccion' => now()->toDateString(), 'observacion' => 'Detección informada por contraparte.',
            'todos_destinos' => true, 'destinos' => [], 'definitivo' => false];
    }

    private function resolucion(array $caso, array $cambios = []): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version' => $caso['version'], 'estado' => $caso['estado'],
            'fecha_deteccion' => substr($caso['fecha_deteccion'], 0, 10), 'observacion' => $caso['observacion'],
            'todos_destinos' => $caso['todos_destinos'], 'destinos' => $caso['destinos'], 'definitivo' => true,
            'resolucion' => 'Resolución informada por contraparte.', ...$cambios];
    }
}
