<?php

namespace Tests\Feature\Api;

use App\Models\DocumentoSag;
use App\Models\PersonalAccessToken;
use App\Models\ResponsableContraparteSag;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Comex\PreparaOperacionComex;
use Tests\TestCase;

class DespachoSagApiTest extends TestCase
{
    use PreparaOperacionComex;
    use RefreshDatabase;

    private function preparar(array $instructivos = [['NL']]): array
    {
        $this->prepararComex();
        $usuario = $this->administradorComex();
        $this->actingAs($usuario, 'sanctum');
        $this->putJson('/api/contraparte-sag/configuracion', ['version' => 0, 'nombre_establecimiento' => 'Planta prueba', 'csp' => '3176555',
            'region' => '06', 'provincia' => 'Cachapoal', 'comuna' => 'Rengo', 'oficina_sag' => 'Rengo'])->assertOk();
        $responsable = ResponsableContraparteSag::create(['nombre' => 'Despachador prueba', 'rut' => '1-9', 'cargo' => 'despachador', 'referencia_autorizacion' => 'Autorización prueba', 'activo' => true]);
        $embarque = $this->embarque($this->clienteOperacion('Exportador prueba'), '2026-10-21', 'maritimo', $instructivos);
        $carga = $this->cargaParaEmbarque($embarque);
        $envase = $this->envaseConKilos('Caja 2.5', '2.5000');
        $parte = ['especie' => 'CEREZA', 'variedad' => 'SANTINA', 'csg' => '00102778', 'csp' => '3176555', 'provincia' => 'Colchagua', 'comuna' => 'Placilla', 'envase_validacion_id' => $envase, 'cantidad_cajas' => 240];
        $folios = [];
        foreach (range(1, 4) as $i) {
            $folios[] = $this->folioEnCarga($carga, '000'.$i, [$parte]);
        }
        $folios[] = $this->folioEnCarga($carga, '0005', [[...$parte, 'cantidad_cajas' => 120], [...$parte, 'csg' => '00099999', 'cantidad_cajas' => 120]]);
        $this->folioEnCarga($carga, 'FUERA-1', [$parte], 'reemplazado');
        $entrada = ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'tipo' => 'despacho', 'embarque_id' => $embarque, 'fecha_actividad' => '2026-10-21', 'responsable_id' => $responsable->id,
            'encabezado' => ['despacho' => ['agente' => 'Agente prueba', 'rut_agente' => '1-9', 'rut_exportador' => '1-9', 'puerto_embarque' => 'Santiago', 'oficina_sag_puerto' => 'Aeropuerto', 'patente' => 'KPXD82',
                'guias' => '136', 'tipo_produccion' => 'CONVENCIONAL', 'transgenico' => 'NO', 'tratamiento' => 'sin_tratamiento', 'nimf15' => true, 'inspeccionado' => true, 'modo' => 'contingencia']]];

        return compact('usuario', 'embarque', 'carga', 'folios', 'entrada');
    }

    public function test_despacho_mixto_congela_cinco_pallets_y_contingencia_exige_motivo(): void
    {
        $p = $this->preparar();
        $fuente = $this->getJson('/api/contraparte-sag/fuentes/despacho/'.$p['embarque'])->assertOk()->assertJsonCount(6, 'lineas')->json();
        $this->assertSame(1200, array_sum(array_column($fuente['lineas'], 'cantidad')));
        $this->assertEquals(3000, array_sum(array_column($fuente['lineas'], 'kilos')));
        $r = $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->json();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', ['operacion_id' => (string) Str::uuid(), 'version' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('secuencias_documentos_sag', 0);
        $entrada = $p['entrada'];
        $entrada['encabezado']['despacho']['motivo_contingencia'] = 'Plataforma Multipuerto sin servicio';
        $entrada['version'] = 1;
        $entrada['operacion_id'] = (string) Str::uuid();
        $this->putJson('/api/contraparte-sag/documentos/'.$r['id'], $entrada)->assertOk();
        $this->postJson('/api/contraparte-sag/documentos/'.$r['id'].'/emitir', ['operacion_id' => (string) Str::uuid(), 'version' => 2])->assertOk()
            ->assertJsonPath('detalle.pallets', 5)->assertJsonPath('formato_snapshot.codigo', 'F-CER-PPS-PA-001')->assertJsonPath('formato_snapshot.version', '01');
        $doc = DocumentoSag::findOrFail($r['id']);
        DB::table('carga_folios')->where('carga_id', $p['carga'])->update(['estado' => 'descartado']);
        $this->getJson('/api/contraparte-sag/documentos/'.$doc->id)->assertOk()->assertJsonCount(6, 'detalle.lineas');
        $this->get('/api/contraparte-sag/documentos/'.$doc->id.'/archivo/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_varios_instructivos_exigen_seleccion_y_rechazan_folios_ajenos(): void
    {
        $p = $this->preparar([['NL'], ['CN']]);
        $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertUnprocessable();
        $entrada = [...$p['entrada'], 'instructivo_id' => DB::table('instructivos_embarque')->where('embarque_id', $p['embarque'])->orderBy('orden')->value('id')];
        $this->postJson('/api/contraparte-sag/documentos', $entrada)->assertUnprocessable();
        $entrada['seleccion_folios'] = [(string) Str::uuid()];
        $this->postJson('/api/contraparte-sag/documentos', $entrada)->assertUnprocessable();
        $entrada['seleccion_folios'] = [$p['folios'][4]];
        $this->postJson('/api/contraparte-sag/documentos', $entrada)->assertCreated()->assertJsonCount(2, 'detalle.lineas')->assertJsonPath('detalle.pallets', 1);
    }

    public function test_token_de_otro_modulo_tablet_no_puede_acceder_a_contraparte(): void
    {
        $p = $this->preparar();
        $token = new PersonalAccessToken;
        $token->forceFill(['dispositivo_id' => (string) Str::uuid(), 'abilities' => ['tablet:porteria']]);
        $usuario = $p['usuario']->withAccessToken($token);
        $alcance = app(AlcanceOperacionalUsuario::class);
        $this->assertFalse($alcance->puedeConsultarContraparteSag($usuario));
        $this->assertFalse($alcance->puedeGestionarContraparteSag($usuario));
        $token->abilities = ['tablet:contraparte_sag'];
        $this->assertTrue($alcance->puedeConsultarContraparteSag($usuario));
        $this->assertTrue($alcance->puedeGestionarContraparteSag($usuario));
    }

    public function test_comex_consulta_documentos_sag_sin_dar_por_lista_la_planilla_oficial(): void
    {
        $p = $this->preparar();
        $this->cargarSugeridos();
        $r = $this->postJson('/api/contraparte-sag/documentos', $p['entrada'])->assertCreated()->json();
        $detalle = $this->getJson('/api/comex/expedientes/'.$p['embarque'])->assertOk()->assertJsonPath('documentos_sag.0.id', $r['id'])->json();
        $this->assertSame('pendiente', collect($detalle['documentos'])->firstWhere('tipo.codigo', 'planilla_sag')['estado']);
    }
}
