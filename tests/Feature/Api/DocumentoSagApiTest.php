<?php

namespace Tests\Feature\Api;

use App\Models\CondicionSag;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\Pais;
use App\Models\ResponsableContraparteSag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentoSagApiTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(): array
    {
        $usuario = User::factory()->create(['rol' => 'administrador']);
        $this->actingAs($usuario, 'sanctum');
        $this->putJson('/api/contraparte-sag/configuracion', ['version' => 0, 'nombre_establecimiento' => 'Planta prueba',
            'csp' => '00123', 'region' => 'O’Higgins', 'provincia' => 'Cachapoal', 'comuna' => 'Rengo', 'oficina_sag' => 'Rengo'])->assertOk();
        $responsable = ResponsableContraparteSag::create(['nombre' => 'Responsable prueba', 'rut' => '1-9', 'cargo' => 'tecnica', 'referencia_autorizacion' => 'Autorización prueba', 'activo' => true]);
        $condicion = CondicionSag::create(['codigo' => 'AO-TEST', 'nombre' => 'Aprobado origen', 'activo' => true]);
        $folio = Folio::create(['numero_folio' => '00000123', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'disponible',
            'condicion_sag_id' => $condicion->id, 'activo' => true, 'fecha_ingreso' => now(),
            'datos_externos' => ['especie' => 'UVA', 'cantidad_cajas' => 180]]);

        return ['usuario' => $usuario, 'folio' => $folio, 'entrada' => [
            'operacion_id' => (string) Str::uuid(), 'version' => 0, 'tipo' => 'anulacion',
            'responsable_id' => $responsable->id, 'fecha_actividad' => '2026-10-21', 'encabezado' => ['certificados' => 0],
            'folios' => [['folio_id' => $folio->id, 'cantidad' => 4, 'causal' => 'DECISIÓN COMERCIAL', 'tipo_actividad' => 'INSPECCIÓN', 'numero_actividad' => '1513']],
        ]];
    }

    public function test_borrador_idempotente_emision_congela_formato_y_no_borra_cajas_fisicas(): void
    {
        $p = $this->preparar();
        $r = $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->json();
        $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->assertJsonPath('id', $r['id']);
        $this->assertDatabaseCount('documentos_sag', 1);
        $op = ['operacion_id' => (string) Str::uuid(), 'version' => 1];
        $emitido = $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', $op)
            ->assertOk()->assertJsonPath('numero', 1)->assertJsonPath('formato_snapshot.version', '01')->json();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', $op)->assertOk()->assertJsonPath('numero', 1);
        DB::table('formatos_registro')->where('codigo', 'F-PA-CFT-DR-011')->update(['version' => '02']);
        $this->getJson('/api/contraparte-sag/documentos/'.$r['id'])->assertOk()->assertJsonPath('formato_snapshot.version', '01');
        $this->assertSame(180, $p['folio']->fresh()->datos_externos['cantidad_cajas']);
        $this->assertSame(4, $emitido['detalle']['lineas'][0]['cantidad']);
        $this->putJson('/api/contraparte-sag/documentos/'.$r['id'], [...$p['entrada'], 'version' => 2, 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_errores_de_cantidad_o_actividad_no_consumen_numero_y_no_crean_documentos_incompletos(): void
    {
        $p = $this->preparar();
        $mal = $p['entrada'];
        $mal['folios'][0]['cantidad'] = 181;
        $this->postJson('/api/contraparte-sag/documentos', $mal)->assertUnprocessable();
        $this->assertDatabaseCount('documentos_sag', 0);
        $p['entrada']['folios'][0]['numero_actividad'] = '';
        $r = $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->json();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', ['operacion_id' => (string) Str::uuid(), 'version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('secuencias_documentos_sag', 0);
    }

    public function test_datos_obsoletos_y_resolucion_sin_respaldo_se_rechazan(): void
    {
        $p = $this->preparar();
        $r = $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->json();
        $this->putJson('/api/contraparte-sag/documentos/'.$r['id'], [...$p['entrada'], 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', ['operacion_id' => (string) Str::uuid(), 'version' => 1])->assertOk();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/aprobar', ['operacion_id' => (string) Str::uuid(), 'version' => 2])->assertUnprocessable();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/aprobar', ['operacion_id' => (string) Str::uuid(), 'version' => 2, 'referencia' => 'Resolución prueba 1', 'resuelto_por' => 'Inspector prueba'])->assertOk()->assertJsonPath('estado', 'aprobado');
    }

    public function test_documento_de_temporada_anterior_es_consultable_y_no_editable(): void
    {
        $p = $this->preparar();
        $r = $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->json();
        $this->desactivarTemporadasDePrueba();
        $this->getJson('/api/contraparte-sag/documentos/'.$r['id'])->assertOk();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', ['operacion_id' => (string) Str::uuid(), 'version' => 1])->assertConflict();
    }

    public function test_cambio_mercado_toma_el_lote_y_no_autoriza_destinos_al_emitir(): void
    {
        $p = $this->preparar();
        $especie = EspecieValidacion::create(['temporada_id' => $p['folio']->temporada_id, 'nombre' => 'UVA', 'activo' => true]);
        $envase = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Caja 5 kg', 'kilos_netos_por_caja' => 5, 'activo' => true]);
        $p['folio']->update(['datos_externos' => ['especie' => 'UVA', 'cantidad_cajas' => 180, 'envase_validacion_id' => $envase->id]]);
        $lote = LoteInspeccionSag::create(['temporada_id' => $p['folio']->temporada_id, 'codigo' => 'CM-PRUEBA-1', 'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'prueba'), 'tipo' => 'cambio_mercado', 'estado' => 'preparacion', 'creado_por_user_id' => $p['usuario']->id]);
        $lote->folios()->create(['folio_id' => $p['folio']->id, 'estado' => 'pendiente', 'estado_sag_anterior' => ['destinos' => ['China', 'Taiwán']]]);
        $pais = Pais::where('iso_alpha2', 'VN')->firstOrFail();
        $lote->destinos()->create(['tipo_destino' => 'pais', 'pais_id' => $pais->id, 'destino_snapshot' => ['codigo' => 'VN', 'nombre' => 'Vietnam']]);
        $this->getJson('/api/contraparte-sag/fuentes/cambio_mercado/'.$lote->codigo)->assertOk()
            ->assertJsonPath('lineas.0.kilos', 900)->assertJsonPath('lineas.0.mercados_anteriores', 'China - Taiwán')->assertJsonPath('lineas.0.nuevo_destino', 'Vietnam');
        $entrada = [...$p['entrada'], 'tipo' => 'cambio_mercado', 'lote_inspeccion_sag_id' => $lote->id,
            'encabezado' => ['programa' => 'ORIGEN', 'motivo' => 'DECISIÓN COMERCIAL'],
            'actividades' => [$p['folio']->id => ['tipo' => 'INSPECCIÓN', 'numero' => '1513']]];
        unset($entrada['folios']);
        $doc = $this->postJson('/api/contraparte-sag/documentos', $entrada)->assertCreated()->json();
        $this->postJson('/api/contraparte-sag/documentos/'.$doc['id'].'/emitir', ['operacion_id' => (string) Str::uuid(), 'version' => 1])
            ->assertOk()->assertJsonPath('formato_snapshot.codigo', 'F-CER-PPS-PA-002')->assertJsonPath('detalle.lineas.0.kilos', 900);
        $this->assertDatabaseCount('autorizaciones_sag_folio', 0);
        $this->assertSame('preparacion', $lote->fresh()->estado->value);
    }
}
