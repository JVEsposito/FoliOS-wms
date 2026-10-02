<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoEnvaseRomana;
use App\Models\FormatoRegistro;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionEnvases;
use Tests\TestCase;

class ControlEnvasesRc02Test extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaRecepcionEnvases;
    use RefreshDatabase;

    public function test_mp_no_se_completa_sin_inspeccion_ni_controles_o_con_tipos_incorrectos(): void
    {
        $c = $this->preparar(['bins' => 2, 'totes' => 48], null, false);
        $ruta = '/api/validacion-mp/validaciones/'.$c['validacion'].'/confirmar';
        $this->actingAs($c['validador'], 'sanctum')->postJson($ruta, $c['mp'])->assertUnprocessable()->assertJsonValidationErrors('inspeccion_envases');
        $payload = $this->payloadConInspeccionRc02($ruta, $c['mp']);
        unset($payload['inspeccion_envases']['coincide_cantidad_bins']);
        $this->postJson($ruta, $payload)->assertUnprocessable()->assertJsonValidationErrors('inspeccion_envases.coincide_cantidad_bins');
        $payload = $this->payloadConInspeccionRc02($ruta, $c['mp']);
        $payload['inspeccion_envases']['items'][0]['tipo_envase'] = 'smartpick';
        $this->postJson($ruta, $payload)->assertUnprocessable()->assertJsonValidationErrors('inspeccion_envases.items');
        $this->assertDatabaseCount('inspecciones_envases_recepcion', 0);
        $this->assertDatabaseMissing('validaciones_mp', ['id' => $c['validacion'], 'estado' => 'validada']);
        $payload = $this->payloadConInspeccionRc02($ruta, $c['mp']);
        $payload['inspeccion_envases']['coincide_especie_variedad'] = false;
        $this->postJson($ruta, $payload)->assertOk();
        $this->postJson($ruta, $payload)->assertOk();
        $this->assertDatabaseCount('inspecciones_envases_recepcion', 1);
        $this->assertDatabaseCount('eventos_inspeccion_envases', 1);
        $this->assertDatabaseHas('inspecciones_envases_recepcion', ['coincide_especie_variedad' => false]);
    }

    public function test_vacio_genera_solo_recepcion_y_salida_requiere_inspeccion_y_guia_correcta(): void
    {
        $c = $this->preparar(['bins' => 2, 'totes' => 48, 'esponjas' => 2]);
        $ruta = '/api/romana/recepciones/'.$c['recepcion']->id;
        $this->actingAs($c['operador'], 'sanctum')->get($ruta.'/control-envases/recepcion')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($ruta.'/control-envases/despacho')->assertNotFound();
        $payload = ['operacion_id' => (string) Str::uuid(), 'peso_tara' => 1000, 'modo_salida_envases' => 'mismos', 'numero_guia_salida' => 'GS-PRUEBA', 'taras_envases' => array_map(fn ($t) => ['tipo_envase' => $t, 'tara_unitaria' => 1], array_keys($c['cantidades']))];
        $this->postJson($ruta.'/cerrar', $payload)->assertUnprocessable()->assertJsonValidationErrors('inspeccion_envases');
        $this->assertSame('en_bascula_salida', $c['recepcion']->refresh()->estado->value);
        $this->cerrar($c, 'vacio')->assertOk()->assertJsonPath('data.rc02_recepcion_disponible', true)->assertJsonPath('data.rc02_despacho_disponible', false);
        $this->assertDatabaseCount('inspecciones_envases_recepcion', 1);
        $this->get($ruta.'/control-envases/despacho')->assertNotFound();
    }

    public function test_dos_documentos_conservan_version_y_cantidades_guia_y_controles_y_siete_filas(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
        $c = $this->preparar(array_fill_keys(array_column(TipoEnvaseRomana::cases(), 'value'), 2));
        $recepcion = $c['recepcion'];
        $formato = FormatoRegistro::where('codigo', 'RC-02')->firstOrFail();
        $formato->update(['version' => '2', 'fecha_vigencia' => '2026-10-01']);
        $payload = ['salida_envases' => array_map(fn ($t) => ['tipo_envase' => $t, 'cantidad' => $t === 'bins' ? 3 : 1], array_keys($c['cantidades']))];
        $this->cerrar($c, 'diferentes', $payload)->assertOk()->assertJsonPath('data.rc02_despacho_disponible', true);
        $this->assertDatabaseCount('inspecciones_envases_recepcion', 2);
        $ruta = '/api/romana/recepciones/'.$recepcion->id.'/control-envases/';
        $documentoRecepcion = $this->get($ruta.'recepcion')->assertOk()->getContent();
        $documentoDespacho = $this->get($ruta.'despacho')->assertOk()->getContent();
        $this->assertStringContainsString('/DCTDecode', $documentoRecepcion);
        $this->assertStringContainsString('/DCTDecode', $documentoDespacho);
        $pdfRecepcion = $this->textoPdf($documentoRecepcion);
        $pdfDespacho = $this->textoPdf($documentoDespacho);
        foreach ([$pdfRecepcion, $pdfDespacho] as $texto) {
            $pos = -1;
            foreach (TipoEnvaseRomana::cases() as $tipo) {
                $n = strpos($texto, mb_strtoupper($tipo->etiqueta()));
                $this->assertIsInt($n);
                $this->assertGreaterThan($pos, $n);
                $pos = $n;
            }
            $this->assertStringContainsString('FIRMA CHOFER', $texto);
            $this->assertStringContainsString('RECEPCIONADO POR', $texto);
        }
        $this->assertStringContainsString('15-09-2025', $pdfRecepcion);
        $this->assertStringNotContainsString('01-10-2026', $pdfRecepcion);
        $this->assertStringContainsString($recepcion->numero_guia_despacho, $pdfRecepcion);
        $this->assertStringNotContainsString('GS-1', $pdfRecepcion);
        $this->assertStringContainsString('01-10-2026', $pdfDespacho);
        $this->assertStringContainsString('GS-1', $pdfDespacho);
        $this->assertStringNotContainsString($recepcion->numero_guia_despacho, $pdfDespacho);
        $this->assertSame(3, substr_count($pdfDespacho, 'No aplica'));
        $this->assertStringNotContainsString('No aplica', $pdfRecepcion);
        $inspecciones = $recepcion->inspeccionesEnvases()->get()->keyBy('tipo');
        $this->assertSame('1', $inspecciones['recepcion']->formato['version']);
        $this->assertSame('2', $inspecciones['despacho']->formato['version']);
        $this->assertDatabaseHas('items_inspeccion_envases', ['inspeccion_envases_id' => $inspecciones['recepcion']->id, 'tipo_envase' => 'bins', 'cantidad' => 2]);
        $this->assertDatabaseHas('items_inspeccion_envases', ['inspeccion_envases_id' => $inspecciones['despacho']->id, 'tipo_envase' => 'bins', 'cantidad' => 3]);
        $this->assertStringContainsString($c['operador']->name, $pdfDespacho);
        $formato->update(['version' => '3']);
        $this->assertStringContainsString('VERSION2', $this->textoPdf($this->get($ruta.'despacho')->assertOk()->getContent()));
        $pdf = $this->textoPdf($this->get('/api/romana/control-envases/en-blanco')->assertOk()->getContent());
        foreach (TipoEnvaseRomana::cases() as $tipo) {
            $this->assertStringContainsString(mb_strtoupper($tipo->etiqueta()), $pdf);
        }
        $this->assertStringContainsString('CONTROL DE ENVASES', $pdf);
        $this->assertStringContainsString('VERSION3', $pdf);
        $this->assertStringNotContainsString('GS-1', $pdf);
    }

    public function test_correccion_es_auditada_idempotente_y_no_cambia_version_o_cantidades_por_cliente(): void
    {
        $c = $this->preparar(['bins' => 2, 'totes' => 48]);
        $recepcion = $c['recepcion'];
        $inspeccion = $recepcion->inspeccionesEnvases()->where('tipo', 'recepcion')->firstOrFail();
        $ruta = '/api/romana/recepciones/'.$recepcion->id.'/inspecciones-envases/recepcion';
        $payload = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'motivo' => 'Corrección supervisada de condición', 'inspeccion_envases' => $this->inspeccionRc02DePrueba($c['cantidades'])];
        $payload['inspeccion_envases']['items'][0]['cantidad'] = 999;
        $payload['inspeccion_envases']['items'][0]['condicion'] = 'regular';
        $payload['inspeccion_envases']['items'][0]['nota'] = 'Una esquina rota';
        $this->actingAs($c['operador'], 'sanctum')->putJson($ruta, $payload)->assertForbidden();
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->putJson($ruta, $payload)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.items.0.cantidad', 2)->assertJsonPath('data.formato.version', '1');
        $this->putJson($ruta, $payload)->assertOk();
        $this->assertDatabaseCount('eventos_inspeccion_envases', 2);
        $evento = $inspeccion->eventos()->where('operacion_id', $payload['operacion_id'])->firstOrFail();
        $this->assertSame('buena', $evento->antes['items'][0]['condicion']);
        $this->assertSame('regular', $evento->despues['items'][0]['condicion']);
        $this->assertSame($admin->id, $evento->user_id);
        $payload['operacion_id'] = (string) Str::uuid();
        $this->putJson($ruta, $payload)->assertConflict();
        $this->desactivarTemporadasDePrueba();
        $this->putJson($ruta, $payload)->assertConflict();
    }

    private function textoPdf(string $pdf): string
    {
        preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\) Tj/', $pdf, $coincidencias);

        return implode('', array_map(fn (string $valor): string => iconv('Windows-1252', 'UTF-8', strtr($valor, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\'])), $coincidencias[1]));
    }
}
