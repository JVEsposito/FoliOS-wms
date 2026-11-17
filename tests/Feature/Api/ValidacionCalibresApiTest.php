<?php

namespace Tests\Feature\Api;

use App\Models\Folio;
use App\Services\Validacion\ComposicionEtiquetaPt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionValidacion;
use Tests\TestCase;

class ValidacionCalibresApiTest extends TestCase
{
    use PreparaCorreccionValidacion;
    use RefreshDatabase;

    public function test_aprueba_varios_calibres_del_mismo_csg_lote_y_proceso_sin_duplicar_al_reintentar(): void
    {
        [$catalogo, , $token] = $this->contexto();
        $segundo = $this->otroArticulo($catalogo);
        $payload = $this->mixto($catalogo, $segundo);

        $respuesta = $this->conToken($token)->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.catalogo.articulo.calibre', 'MIX')
            ->assertJsonPath('data.catalogo.composicion.0.calibre', '2J')
            ->assertJsonPath('data.catalogo.composicion.1.calibre', '3J')
            ->assertJsonPath('data.catalogo.composicion.1.articulo_validacion_id', $segundo)
            ->json('data');
        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)->assertOk()->assertJsonPath('data.id', $respuesta['id']);
        $folio = Folio::findOrFail($respuesta['folio']['id']);
        $this->assertSame('MIX', $folio->calibre);
        $this->assertSame(['2J', '3J'], $folio->datos_externos['calibres']);
        $etiqueta = app(ComposicionEtiquetaPt::class)->lineas($folio);
        $this->assertSame(['2J', '3J'], array_column($etiqueta, 'calibre'));
        $this->assertSame([80, 40], array_column($etiqueta, 'cantidad_cajas'));
        $this->assertSame(120, (int) DB::table('trazabilidad_folio_origenes')->where('folio_id', $folio->id)->sum('cantidad_cajas'));
        $this->assertDatabaseCount('validaciones_pallet', 1);

        $payload['composicion'][1]['articulo_validacion_id'] = $catalogo['articulo_original_id'];
        $payload['composicion'][1]['proceso_packing'] = 'P-OTRO';
        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)->assertConflict();

        // Los informes muestran cada calibre con sus cajas, nunca «MIX».
        $ruta = '/api/validacion/registro/rrpp-01?'.http_build_query(['temporada_id' => $catalogo['temporada_id'],
            'fecha' => $respuesta['fecha_jornada'], 'cliente' => $respuesta['catalogo']['origen']['cliente']]);
        $pdf = $this->conToken($token)->get($ruta.'&formato=pdf')->assertOk()->getContent();
        $this->assertStringContainsString('2J', $pdf);
        $this->assertStringContainsString('3J', $pdf);
        $this->assertStringContainsString('(80)', $pdf);
        $this->assertStringContainsString('(40)', $pdf);
        $this->assertStringContainsString('(Total 120)', $pdf);
        // Solo el texto del documento: el logo embebido puede contener esos bytes.
        preg_match_all('/\((.*?)\) Tj/', $pdf, $textos);
        $this->assertNotEmpty($textos[1]);
        $this->assertSame([], array_values(array_filter($textos[1], fn (string $texto): bool => preg_match('/(^|[\s·])MIX($|[\s·])/u', $texto) === 1)));
        $excel = $this->conToken($token)->get($ruta)->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($excel->baseResponse->getFile()->getPathname()) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('3J', $hoja);
        $this->assertStringNotContainsString('MIX', $hoja);
    }

    public function test_no_mezcla_especies_variedades_o_envases_aunque_la_combinacion_exista(): void
    {
        [$catalogo, , $token] = $this->contexto();
        foreach (['especie' => 'Manzana', 'variedad' => 'Lapins', 'envase' => 'Cartón 10 kg'] as $campo => $valor) {
            $segundo = $this->otroArticulo($catalogo, [$campo => $valor]);
            $this->conToken($token)->postJson('/api/validacion/pallets', $this->mixto($catalogo, $segundo))
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('validaciones_pallet', 0);
        $this->assertDatabaseCount('folios', 0);
    }

    public function test_exige_cajas_cuadradas_calibre_activo_y_combinacion_habilitada(): void
    {
        [$catalogo, , $token] = $this->contexto();
        $segundo = $this->otroArticulo($catalogo);
        $payload = $this->mixto($catalogo, $segundo);
        $payload['composicion'][1]['cantidad_cajas'] = 39;
        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)->assertUnprocessable();

        $payload = $this->mixto($catalogo, $segundo);
        $payload['composicion'][1]['articulo_validacion_id'] = $catalogo['articulo_original_id'];
        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)->assertUnprocessable()->assertJsonValidationErrors('composicion');

        DB::table('articulos_validacion')->where('id', $segundo)->update(['activo' => false]);
        $this->conToken($token)->postJson('/api/validacion/pallets', $this->mixto($catalogo, $segundo))->assertUnprocessable();
        DB::table('articulos_validacion')->where('id', $segundo)->update(['activo' => true]);
        DB::table('combinaciones_validacion')->where('articulo_validacion_id', $segundo)->update(['activo' => false]);
        $this->conToken($token)->postJson('/api/validacion/pallets', $this->mixto($catalogo, $segundo))->assertUnprocessable();
        $this->assertDatabaseCount('validaciones_pallet', 0);
        $this->assertDatabaseCount('folios', 0);
    }

    public function test_correccion_simple_conserva_el_mix_y_el_contexto_devuelve_los_calibres_por_linea(): void
    {
        [$catalogo, $usuario, $token] = $this->contexto();
        $segundo = $this->otroArticulo($catalogo);
        $id = $this->conToken($token)->postJson('/api/validacion/pallets', $this->mixto($catalogo, $segundo))
            ->assertCreated()->json('data.id');
        $oficina = $this->tokenOficina($usuario);
        $this->conToken($oficina)->getJson("/api/validacion/pallets/{$id}/correccion")
            ->assertOk()
            ->assertJsonPath('validacion.composicion.1.articulo_validacion_id', $segundo)
            ->assertJsonPath('validacion.composicion.1.calibre', '3J');
        $cambio = [
            ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
            'articulo_validacion_id' => $catalogo['articulo_original_id'],
            'cantidad_cajas' => 120,
        ];
        $ruta = "/api/validacion/pallets/{$id}/corregir";
        $this->conToken($oficina)->putJson($ruta, $cambio)
            ->assertOk()->assertJsonPath('data.catalogo.articulo.calibre', 'MIX')
            ->assertJsonPath('data.catalogo.composicion.1.articulo_validacion_id', $segundo);
        $this->conToken($oficina)->putJson($ruta, $cambio)->assertOk();
        $this->assertDatabaseCount('correcciones_validacion_pallet', 1);

        $this->conToken($oficina)->putJson($ruta, [
            ...$cambio, 'operacion_id' => (string) Str::uuid(),
            'articulo_validacion_id' => $catalogo['articulo_corregido_id'],
        ])->assertUnprocessable();
    }

    public function test_corrige_el_desglose_y_recalcula_el_resumen_sin_perder_el_folio(): void
    {
        [$catalogo, $usuario, $token] = $this->contexto();
        $segundo = $this->otroArticulo($catalogo);
        $registro = $this->conToken($token)->postJson('/api/validacion/pallets', $this->mixto($catalogo, $segundo))
            ->assertCreated()->json('data');
        $cambio = [
            ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
            'articulo_validacion_id' => $catalogo['articulo_original_id'],
            'cantidad_cajas' => 120,
            'composicion' => $this->mixto($catalogo, $segundo)['composicion'],
        ];
        $cambio['composicion'][0]['cantidad_cajas'] = 60;
        $cambio['composicion'][1]['cantidad_cajas'] = 60;
        $ruta = "/api/validacion/pallets/{$registro['id']}/corregir";
        $oficina = $this->tokenOficina($usuario);
        $this->conToken($oficina)->putJson($ruta, $cambio)->assertOk()
            ->assertJsonPath('data.folio.id', $registro['folio']['id'])
            ->assertJsonPath('data.catalogo.composicion.1.cantidad_cajas', 60);
        $cambio['operacion_id'] = (string) Str::uuid();
        $cambio['composicion'] = [[...$cambio['composicion'][0], 'cantidad_cajas' => 120]];
        $this->conToken($oficina)->putJson($ruta, $cambio)->assertOk()
            ->assertJsonPath('data.catalogo.articulo.calibre', '2J');
        $folio = Folio::findOrFail($registro['folio']['id']);
        $this->assertSame('2J', $folio->calibre);
        $this->assertSame(['2J'], $folio->datos_externos['calibres']);
    }

    private function otroArticulo(array $catalogo, array $cambios = []): string
    {
        $id = (string) Str::uuid();
        $original = (array) DB::table('articulos_validacion')->where('id', $catalogo['articulo_original_id'])->first();
        DB::table('articulos_validacion')->insert([...$original, 'id' => $id, 'calibre' => '3J', ...$cambios]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(), 'temporada_id' => $catalogo['temporada_id'],
            'articulo_validacion_id' => $id, 'origen_validacion_id' => $catalogo['origen_id'],
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function mixto(array $catalogo, string $segundo): array
    {
        $linea = [
            'origen_validacion_id' => $catalogo['origen_id'],
            'lote_materia_prima' => 'L-1', 'proceso_packing' => 'P-1',
        ];

        return [
            ...$this->payloadValidacion($catalogo, 'PAL-CALIBRES'),
            'fecha_embalaje' => '2026-10-07',
            'composicion' => [
                [...$linea, 'articulo_validacion_id' => $catalogo['articulo_original_id'], 'cantidad_cajas' => 80],
                [...$linea, 'articulo_validacion_id' => $segundo, 'cantidad_cajas' => 40],
            ],
        ];
    }
}
