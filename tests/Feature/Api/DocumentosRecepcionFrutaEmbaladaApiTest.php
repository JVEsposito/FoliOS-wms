<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Folio;
use App\Models\FormatoRegistro;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PreparaRecepcionFrutaEmbalada;
use Tests\TestCase;

class DocumentosRecepcionFrutaEmbaladaApiTest extends TestCase
{
    use PreparaRecepcionFrutaEmbalada, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararRecepcionFrutaEmbalada();
    }

    public function test_rrfe_paginas_totales_version_guardada_y_blanco(): void
    {
        for ($n = 2; $n <= 25; $n++) {
            DB::table('recepciones_fruta_embalada_pallets')->insert([...$this->pallet, 'id' => (string) Str::uuid(), 'orden' => $n, 'folio_origen' => 'EXT-'.$n]);
        }
        $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk();
        $respuesta = $this->withToken($this->token)->post($this->ruta('rrfe-01'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $respuesta->getContent();
        $this->assertStringContainsString('/Count 2', $pdf);
        $this->assertStringContainsString('1925', $pdf);
        $this->assertSame(2, substr_count($pdf, '(RRFE-01)'));
        $this->assertSame(2, substr_count($pdf, 'Supervisor de fr'));
        $this->assertStringNotContainsString('GUIA-100', $pdf);
        $this->assertStringNotContainsString('EX-SAG', $pdf);
        $this->assertSame('1', RecepcionFrutaEmbalada::findOrFail($this->recepcion)->formato_rrfe_snapshot['version']);
        FormatoRegistro::where('codigo', 'RRFE-01')->update(['version' => '2', 'fecha_vigencia' => '2026-10-06']);
        $this->assertSame($pdf, $this->withToken($this->token)->post($this->ruta('rrfe-01'))->assertOk()->getContent());
        $this->assertDatabaseCount('eventos_recepcion_fruta_embalada', 3);
        $blanco = $this->withToken($this->token)->get('/api/recepciones-fruta-embalada/rrfe-01/blanco')->assertOk()->getContent();
        $this->assertStringContainsString('/Count 1', $blanco);
        $this->assertSame(20, substr_count($blanco, ' l S 818 '));
        $this->assertStringNotContainsString('EXT00001', $blanco);
    }

    public function test_folio_interno_externo_pendiente_hasta_etiquetar_con_cuatro_copias_e_historial_idempotente(): void
    {
        Folio::create(['numero_folio' => 'EXT00001', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'agotado', 'activo' => false, 'fecha_ingreso' => now()]);
        $r = $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk()->assertJsonPath('data.folios.0.etiqueta_pendiente', true)->json('data.folios.0');
        $this->assertSame('externo', $r['etiqueta']['origen']);
        $this->assertSame('2026-02-06', $r['etiqueta']['fecha_proceso']);
        $this->assertSame('693.00', $r['etiqueta']['kilos_netos']);
        $payload = ['operacion_id' => (string) Str::uuid(), 'temporada_id' => RecepcionFrutaEmbalada::findOrFail($this->recepcion)->temporada_id,
            'folios' => [['id' => $r['folio_id'], 'version' => $r['version_etiqueta']]]];
        $pdf = $this->withToken($this->token)->postJson($this->ruta('etiquetas'), $payload)->assertOk()->getContent();
        $this->assertStringContainsString('/Count 4', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 303.31 209.76]', $pdf);
        $this->assertSame(4, substr_count($pdf, '(EXTERNO)'));
        $this->assertSame(4, substr_count($pdf, '(06-02-2026)'));
        $this->assertSame(4, substr_count($pdf, '(INT0000000001)'));
        $this->withToken($this->token)->postJson($this->ruta('etiquetas'), $payload)->assertOk();
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 1);
        $this->assertDatabaseHas('impresiones_etiquetas_pt', ['tipo' => 'planta', 'copias' => 4, 'user_id' => $this->usuario->id]);
        $this->withToken($this->token)->getJson($this->ruta('aceptacion'))->assertOk()->assertJsonPath('data.folios.0.etiqueta_pendiente', false);
        $this->withToken($this->token)->getJson('/api/validacion/etiquetas?origen=externo')->assertOk()->assertJsonPath('data.0.origen', 'externo');
    }

    public function test_etiqueta_opcional_no_permite_folios_ajenos_ni_formatos_distintos(): void
    {
        $r = $this->withToken($this->token)->postJson($this->ruta('aceptar'), $this->payload())->assertOk()->assertJsonPath('data.folios.0.etiqueta_pendiente', false)->json('data.folios.0');
        $payload = ['operacion_id' => (string) Str::uuid(), 'temporada_id' => RecepcionFrutaEmbalada::findOrFail($this->recepcion)->temporada_id, 'folios' => [['id' => $r['folio_id'], 'version' => $r['version_etiqueta']]]];
        $this->withToken($this->token)->postJson($this->ruta('etiquetas'), [...$payload, 'tipo' => 'folio'])->assertUnprocessable();
        $this->withToken($this->token)->postJson($this->ruta('etiquetas'), [...$payload, 'copias' => 11])->assertUnprocessable();
        $this->withToken($this->token)->postJson($this->ruta('etiquetas'), [...$payload, 'folios' => [['id' => (string) Str::uuid(), 'version' => $r['version_etiqueta']]]])->assertUnprocessable();
        $token = User::factory()->create(['rol' => RolUsuario::Consulta])->createToken('oficina', ['oficina'])->plainTextToken;
        $this->withToken($token)->postJson($this->ruta('etiquetas'), $payload)->assertForbidden();
        $this->assertDatabaseCount('impresiones_etiquetas_pt', 0);
    }

    public function test_borrador_no_emite_documento_ni_snapshot(): void
    {
        $this->withToken($this->token)->post($this->ruta('rrfe-01'))->assertUnprocessable();
        $this->assertNull(RecepcionFrutaEmbalada::findOrFail($this->recepcion)->formato_rrfe_snapshot);
    }
}
