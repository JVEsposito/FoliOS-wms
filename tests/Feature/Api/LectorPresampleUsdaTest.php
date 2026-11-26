<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\LlamadaIaAsistente;
use App\Models\User;
use App\Services\Asistente\Haiku\PresupuestoHaiku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * «Leer presample»: Claude lee el certificado de pre-muestreo USDA/SAG y Tesseract
 * confirma cada caja. Las contradicciones del propio documento bloquean la aplicación.
 */
class LectorPresampleUsdaTest extends TestCase
{
    use RefreshDatabase;

    /** Las 69 cajas del presample real del 25-11-2026, por columna y de arriba abajo. */
    private const COLUMNAS = [
        [12, 23, 156, 177, 289, 361, 406, 475, 487, 525],
        [567, 743, 780, 789, 798, 827, 903, 906, 914, 957],
        [959, 1007, 1027, 1029, 1082, 1223, 1232, 1264, 1269, 1286],
        [1289, 1299, 1399, 1593, 1610, 1732, 1958, 1959, 1961, 1998],
        [2145, 2195, 2252, 2276, 2322, 2368, 2386, 2410, 2439, 2477],
        [2550, 2557, 2559, 2587, 2655, 2683, 2720, 2734, 2935, 2975],
        [3034, 3143, 3198, 3275, 3282, 3328, 3331, 3371, 3415],
    ];

    private string $binarios;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->binarios = sys_get_temp_dir().'/presample-'.bin2hex(random_bytes(4));
        mkdir($this->binarios);
        // Tesseract leyó 743 como 143 y 903 como 503, como en la prueba con el escaneo real.
        $lectura = str_replace([' 743 ', ' 903 '], [' 143 ', ' 503 '], ' '.implode(' ', array_merge(...self::COLUMNAS)).' ');
        $this->binario('tesseract', "printf '%s' '15 3432 69 20 {$lectura}'");
        $this->binario('pdftoppm', 'for ultimo; do :; done; printf png > "$ultimo.png"');
        config(['sag.tesseract' => $this->binarios.'/tesseract', 'sag.pdftoppm' => $this->binarios.'/pdftoppm',
            'asistente.anthropic.api_key' => 'clave-de-prueba']);
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        Sanctum::actingAs($this->usuario);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->binarios.'/*') ?: []);
        @rmdir($this->binarios);
        parent::tearDown();
    }

    public function test_lee_lote_rangos_y_cajas_y_confirma_con_tesseract(): void
    {
        $this->claudeLee();

        $respuesta = $this->leer()->assertOk();

        $respuesta->assertJsonPath('fuente', 'claude')
            ->assertJsonPath('lote', '15')
            ->assertJsonPath('muestra', 69)
            ->assertJsonPath('rangos', [['desde' => '12358039', 'hasta' => '12358058']])
            ->assertJsonPath('problemas', []);
        $cajas = collect($respuesta->json('cajas'));
        $this->assertCount(69, $cajas);
        $this->assertSame([743, 903], $cajas->where('estado', 'revisar')->pluck('numero')->all());
        $this->assertSame(12, $cajas->first()['numero']);
    }

    public function test_bloquea_si_la_cantidad_no_coincide_con_la_muestra(): void
    {
        $this->claudeLee(['tamano_muestra' => 70]);

        $this->assertSame(['Se leyeron 69 cajas y el presample indica una muestra de 70. Revisa el papel.'], $this->leer()->json('problemas'));
    }

    public function test_bloquea_cajas_fuera_de_orden_o_repetidas(): void
    {
        // Una caja mal leída rompe el orden creciente del listado, aunque la cantidad cuadre.
        $columnas = self::COLUMNAS;
        $columnas[0][3] = 150;
        $this->claudeLee(['columnas_cajas' => $columnas]);

        $this->assertSame(['Las cajas no van en orden: 150 viene después de 156. Revisa el papel.'], $this->leer()->json('problemas'));
    }

    public function test_bloquea_una_caja_sobre_la_partida(): void
    {
        $columnas = self::COLUMNAS;
        $columnas[6][8] = 3500;
        $this->claudeLee(['columnas_cajas' => $columnas]);

        $this->assertSame(['La caja 3500 pasa del tamaño de la partida (3432). Revisa el papel.'], $this->leer()->json('problemas'));
    }

    public function test_bloquea_si_los_rangos_no_suman_los_pallets(): void
    {
        $this->claudeLee(['rangos_lote' => [['desde' => '12358039', 'hasta' => '12358050']]]);

        $this->assertSame(['El rango de lote suma 12 pallets y el presample indica 20. Revisa el papel.'], $this->leer()->json('problemas'));
    }

    public function test_sin_clave_responde_solo_con_tesseract_y_avisa(): void
    {
        config(['asistente.anthropic.api_key' => null]);
        Http::fake();

        $respuesta = $this->leer()->assertOk();

        $respuesta->assertJsonPath('fuente', 'tesseract')->assertJsonPath('lote', null)->assertJsonPath('rangos', []);
        $this->assertSame(['revisar'], collect($respuesta->json('cajas'))->pluck('estado')->unique()->values()->all());
        $this->assertStringContainsString('Tesseract', $respuesta->json('avisos.0'));
        Http::assertNothingSent();
    }

    public function test_registra_la_llamada_sin_contar_en_el_limite_diario(): void
    {
        $this->claudeLee();

        $this->leer()->assertOk();

        $llamada = LlamadaIaAsistente::sole();
        $this->assertSame(['leer_presample', LlamadaIaAsistente::OK], [$llamada->herramienta, $llamada->estado]);
        $this->assertGreaterThan(0, $llamada->costo_usd);
        $this->assertSame(0, app(PresupuestoHaiku::class)->llamadasHoy($this->usuario));
    }

    public function test_rechaza_pdf_de_mas_de_una_pagina_y_otros_formatos(): void
    {
        $this->postJson('/api/sag/diagramas/leer-presample', ['archivo' => UploadedFile::fake()->createWithContent('presample.pdf', $this->pdf(2))])
            ->assertUnprocessable()->assertJsonValidationErrors(['archivo' => 'una sola página']);
        $this->postJson('/api/sag/diagramas/leer-presample', ['archivo' => UploadedFile::fake()->create('presample.docx', 10)])
            ->assertUnprocessable()->assertJsonValidationErrors('archivo');
    }

    public function test_exige_permiso_de_consulta_sag(): void
    {
        Sanctum::actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]));

        $this->leer()->assertForbidden();
    }

    private function leer()
    {
        return $this->postJson('/api/sag/diagramas/leer-presample', ['archivo' => UploadedFile::fake()->createWithContent('presample.pdf', $this->pdf(1))]);
    }

    private function claudeLee(array $cambios = []): void
    {
        $entrada = [...[
            'lote' => '15', 'tamano_partida' => 3432, 'tamano_muestra' => 69, 'numero_pallets' => 20,
            'rangos_lote' => [['desde' => '12358039', 'hasta' => '12358058']], 'columnas_cajas' => self::COLUMNAS,
        ], ...$cambios];
        Http::fake(['*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'registrar_presample', 'input' => $entrada]],
            'usage' => ['input_tokens' => 2400, 'output_tokens' => 420]])]);
    }

    private function pdf(int $paginas): string
    {
        $hojas = implode('', array_map(fn (int $i): string => ($i + 3)." 0 obj<</Type /Page /Parent 2 0 R>>endobj\n", range(1, $paginas)));

        return "%PDF-1.4\n1 0 obj<</Type /Catalog /Pages 2 0 R>>endobj\n2 0 obj<</Type /Pages /Count {$paginas}>>endobj\n{$hojas}trailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function binario(string $nombre, string $cuerpo): void
    {
        file_put_contents($this->binarios.'/'.$nombre, "#!/bin/sh\n{$cuerpo}\n");
        chmod($this->binarios.'/'.$nombre, 0755);
    }
}
