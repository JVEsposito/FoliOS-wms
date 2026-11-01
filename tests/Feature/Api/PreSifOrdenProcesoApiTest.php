<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\LineaProceso;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;
use ZipArchive;

class PreSifOrdenProcesoApiTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_la_pre_sif_en_excel_trae_encabezado_lotes_colacion_y_total_de_la_version_vigente(): void
    {
        config(['materia_prima.csp_planta' => '3176555']);
        $orden = $this->ordenConDosLotes();

        $respuesta = $this->get("/api/materia-prima/ordenes-proceso/{$orden['id']}/pre-sif?formato=xlsx")
            ->assertOk()
            ->assertDownload('pre-sif_op-000001_v1_t1_l1_02-11-26.xlsx');
        $celdas = $this->celdas($respuesta->baseResponse->getFile()->getPathname());

        $this->assertSame('PRE-SIF DE INSPECCIÓN FITOSANITARIA PARA INSPECCIÓN EN LÍNEA', $celdas['A1']);
        $this->assertSame(['V1', 'T1', 'L1', '3176555', '02-11-2026', '08:00', '12:30'], [
            $celdas['B3'], $celdas['B4'], $celdas['B5'], $celdas['T3'], $celdas['T2'], $celdas['T4'], $celdas['T5'],
        ]);
        $this->assertSame('N° DE PROCESO', $celdas['B7']);
        // Fila 8: primer lote, 30 bins de 48 = 11.250 kg, 1 h 52 min 30 s a 6.000 kg/h; 11.250 × 90 % ÷ 5 kg = 2.025 cajas.
        $this->assertSame(['Exportadora PS1', 'P-1', 'L-PS1', 'SANTINA', '30', '6', '11250', '1:52', 'GFLV50M', '2025', '09:52', 'China'], [
            $celdas['A8'], $celdas['B8'], $celdas['C8'], $celdas['E8'], $celdas['L8'], $celdas['M8'], $celdas['N8'],
            $celdas['O8'], $celdas['P8'], $celdas['Q8'], $celdas['S8'], $celdas['T8'],
        ]);
        $this->assertSame('SALIDA A COLACIÓN 11:30 · VUELTA 12:30', $celdas['A9']);
        // Segundo lote: 18.000 kg son 3:00 de proceso desde las 09:52; cruza la colación: termina 13:52.
        $this->assertSame(['L-PS2', '18000', '3:00', '13:52'], [$celdas['C10'], $celdas['N10'], $celdas['O10'], $celdas['S10']]);
        $this->assertSame(['TOTAL', '48', '10', '29250'], [$celdas['C11'], $celdas['L11'], $celdas['M11'], $celdas['N11']]);
        $this->assertNotContains('#N/A', $celdas);
    }

    public function test_la_pre_sif_en_pdf_se_descarga_con_el_mismo_nombre(): void
    {
        $orden = $this->ordenConDosLotes();

        $respuesta = $this->get("/api/materia-prima/ordenes-proceso/{$orden['id']}/pre-sif?formato=pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="pre-sif_op-000001_v1_t1_l1_02-11-26.pdf"');
        $this->assertStringStartsWith('%PDF-1.4', $respuesta->getContent());

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum')
            ->get("/api/materia-prima/ordenes-proceso/{$orden['id']}/pre-sif")
            ->assertForbidden();
    }

    public function test_el_detalle_compara_el_primer_viaje_real_con_el_inicio_estimado_en_hora_de_planta(): void
    {
        config(['app.operational_timezone' => 'America/Santiago']);
        $orden = $this->ordenConDosLotes();
        $primerLote = $orden['version_vigente']['lotes'][0]['lote_materia_prima_id'];

        // 11:20 UTC del 2 de noviembre son las 08:20 en Santiago (UTC−3).
        CarbonImmutable::setTestNow('2026-11-02 11:20:00');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio]), 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$primerLote}/entregas", [
                'operacion_id' => (string) Str::uuid(),
                'orden_proceso_mp_id' => $orden['id'],
                'version_orden_proceso_mp_id' => $orden['version_vigente']['id'],
                'cantidad_envases' => 10,
                'turno' => 'A',
            ])
            ->assertOk();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->getJson("/api/materia-prima/ordenes-proceso/{$orden['id']}")
            ->assertOk()
            ->assertJsonPath('data.version_vigente.lotes.0.primera_entrega_at', '2026-11-02 08:20:00')
            ->assertJsonPath('data.version_vigente.lotes.0.desfase_minutos', 20)
            ->assertJsonPath('data.version_vigente.lotes.0.viajes', 1)
            ->assertJsonPath('data.version_vigente.lotes.1.primera_entrega_at', null)
            ->assertJsonPath('data.version_vigente.lotes.1.desfase_minutos', null);
    }

    /** @return array<string, mixed> */
    private function ordenConDosLotes(): array
    {
        $primero = $this->loteMpEnCamara('PS1')['lote'];
        $segundo = $this->loteMpEnCamara('PS2', ['cantidad_envases_primarios' => 18, 'cantidad_envases_secundarios' => 4])['lote'];

        return $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum')
            ->postJson('/api/materia-prima/ordenes-proceso', [
                'operacion_id' => (string) Str::uuid(),
                'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'),
                'fecha' => '2026-11-02',
                'turno_inicio' => 'A',
                'hora_inicio' => '08:00',
                'colacion_desde' => '11:30',
                'colacion_hasta' => '12:30',
                'lotes' => [
                    ['lote_materia_prima_id' => $primero['id'], 'bins_programados' => 30, 'numero_proceso' => 'P-1', 'embalaje' => 'GFLV50M', 'kilos_por_caja' => 5, 'mercado_destino' => 'China'],
                    ['lote_materia_prima_id' => $segundo['id'], 'bins_programados' => 18],
                ],
            ])
            ->assertCreated()
            ->json('data');
    }

    /** @return array<string, string> */
    private function celdas(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $documento = new DOMDocument;
        $documento->loadXML($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $celdas = [];
        foreach ($xpath->query('//s:c') as $celda) {
            $celdas[$celda->getAttribute('r')] = trim($celda->textContent);
        }

        return $celdas;
    }
}
