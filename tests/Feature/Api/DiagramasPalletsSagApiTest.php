<?php

namespace Tests\Feature\Api;

use App\Models\DiagramaPalletsSag;
use App\Models\PerfilAcceso;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagramasPalletsSagApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => 'administrador', 'activo' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_se_consulta_y_emite_con_inspeccion_sag_y_guardar_exige_gestionarla(): void
    {
        $this->getJson('/api/sag/diagramas/catalogo')->assertOk()->assertJsonPath('puede_gestionar', true);

        $consulta = User::factory()->create(['rol' => 'consulta']);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/sag/diagramas/catalogo')->assertOk()->assertJsonPath('puede_gestionar', false);
        $this->postJson('/api/sag/diagramas/pdf', [...$this->diagrama(), 'papel' => 'a4', 'mostrar_numeros' => true])->assertOk();
        $this->postJson('/api/sag/diagramas', $this->guardable())->assertForbidden();

        $contraparte = User::factory()->create(['rol' => 'supervisor_frio',
            'perfil_acceso_id' => PerfilAcceso::where('codigo', 'CONTRAPARTE_SAG')->firstOrFail()->id]);
        $this->actingAs($contraparte, 'sanctum')->postJson('/api/sag/diagramas', $this->guardable())->assertOk();

        foreach (['camarero_frio', 'despachador'] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')->getJson('/api/sag/diagramas/catalogo')->assertForbidden();
        }
    }

    public function test_el_catalogo_trae_solo_el_csp_configurado_sin_nombre_de_planta(): void
    {
        DB::table('configuracion_contraparte_sag')->updateOrInsert(['id' => 'planta'], ['datos' => json_encode([
            'nombre_establecimiento' => 'Planta de prueba', 'csp' => '3176555', 'region' => 'R', 'provincia' => 'P', 'comuna' => 'C', 'oficina_sag' => 'O',
        ]), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $respuesta = $this->getJson('/api/sag/diagramas/catalogo')->assertOk()->assertJsonPath('csp', '3176555');
        $this->assertStringNotContainsString('Planta de prueba', $respuesta->getContent());
    }

    public function test_guarda_con_numeracion_continua_y_un_reintento_no_duplica(): void
    {
        $datos = $this->guardable(['numero' => ' 8b ', 'cajas_marcadas' => [411, 5, 82, 145, 206]]);
        $primera = $this->postJson('/api/sag/diagramas', $datos)->assertOk()
            ->assertJsonPath('registro.numero', '8B')
            ->assertJsonPath('registro.csp', '3176555')
            ->assertJsonPath('registro.total_pallets', 16)
            ->assertJsonPath('registro.total_cajas', 1664)
            ->assertJsonPath('registro.cajas_marcadas', [5, 82, 145, 206, 411])
            ->assertJsonPath('registro.version', 1);
        $this->postJson('/api/sag/diagramas', $datos)->assertOk()->assertJsonPath('registro.id', $primera->json('registro.id'));
        $this->assertSame(1, DiagramaPalletsSag::count());

        $this->postJson('/api/sag/diagramas', [...$datos, 'lote' => 'OTRO'])->assertConflict();
        $this->postJson('/api/sag/diagramas', [...$datos, 'operacion_id' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Ya existe el diagrama USDA N.º 8B'));
        $this->postJson('/api/sag/diagramas', [...$datos, 'tipo' => 'sag', 'operacion_id' => (string) Str::uuid()])->assertOk();

        $id = $primera->json('registro.id');
        $lista = $this->getJson('/api/sag/diagramas?texto=8b')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $usda = collect($lista)->firstWhere('tipo', 'usda');
        $this->assertSame([5, $this->admin->name], [$usda['total_marcadas'], $usda['actualizado_por']]);
        $this->getJson('/api/sag/diagramas?texto=OTRO-LOTE')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/sag/diagramas/'.$id)->assertOk()->assertJsonPath('historial.0.version', 1);
    }

    public function test_actualizar_exige_la_version_vigente_y_recalcula_los_rangos(): void
    {
        $id = $this->postJson('/api/sag/diagramas', $this->guardable())->assertOk()->json('registro.id');
        $pallets = $this->diagrama()['pallets'];
        $pallets[0] = ['folio' => 'P-1', 'base' => 8, 'corridas' => 13, 'ultima' => 5, 'declaradas' => 101];
        $this->putJson('/api/sag/diagramas/'.$id, $this->guardable(['version' => 1, 'pallets' => $pallets, 'cajas_marcadas' => [101, 102]]))
            ->assertOk()->assertJsonPath('registro.version', 2)->assertJsonPath('registro.total_cajas', 1661)
            ->assertJsonPath('registro.pallets.0.folio', 'P-1');
        $this->putJson('/api/sag/diagramas/'.$id, $this->guardable(['version' => 1]))->assertConflict();
        $this->assertSame(2, DB::table('eventos_diagrama_pallets_sag')->where('diagrama_id', $id)->count());
    }

    public function test_rechaza_configuraciones_que_no_cuadran_y_cajas_inexistentes(): void
    {
        $pallets = $this->diagrama()['pallets'];
        $pallets[2]['ultima'] = 8;
        $this->postJson('/api/sag/diagramas', $this->guardable(['pallets' => $pallets]))->assertUnprocessable()->assertJsonValidationErrors('pallets.2.ultima');

        $pallets = $this->diagrama()['pallets'];
        $pallets[1]['declaradas'] = 100;
        $this->postJson('/api/sag/diagramas', $this->guardable(['pallets' => $pallets]))->assertUnprocessable()
            ->assertJsonValidationErrors(['pallets.1.declaradas' => 'no coinciden con la configuración (104)']);

        $this->postJson('/api/sag/diagramas', $this->guardable(['cajas_marcadas' => [5, 1665]]))->assertUnprocessable()
            ->assertJsonValidationErrors(['cajas_marcadas' => 'La numeración va de 1 a 1664']);
        $this->postJson('/api/sag/diagramas', $this->guardable(['cajas_marcadas' => [5, 5]]))->assertUnprocessable();
        $this->postJson('/api/sag/diagramas', $this->guardable(['numero' => null]))->assertUnprocessable()
            ->assertJsonValidationErrors(['numero' => 'Indica el N.º del diagrama']);
        $this->postJson('/api/sag/diagramas', $this->guardable(['pallets' => [['base' => null, 'corridas' => 13]]]))->assertUnprocessable();
        $this->postJson('/api/sag/diagramas', $this->guardable(['pallets' => []]))->assertUnprocessable();
        $this->assertSame(0, DiagramaPalletsSag::count());
    }

    public function test_solo_lista_y_abre_los_diagramas_de_la_temporada_activa(): void
    {
        $id = $this->postJson('/api/sag/diagramas', $this->guardable())->assertOk()->json('registro.id');
        $otra = Temporada::create(['codigo' => 'T-ANTERIOR', 'nombre' => 'Temporada anterior', 'activa' => false]);
        DiagramaPalletsSag::whereKey($id)->update(['temporada_id' => $otra->id]);

        $this->getJson('/api/sag/diagramas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/sag/diagramas/'.$id)->assertNotFound();
        $this->putJson('/api/sag/diagramas/'.$id, $this->guardable(['version' => 1]))->assertStatus(409);
    }

    public function test_el_pdf_sale_en_a4_o_a3_horizontal_neutral_y_con_las_cajas_marcadas(): void
    {
        $a4 = $this->post('/api/sag/diagramas/pdf', [...$this->diagrama(), 'cajas_marcadas' => [5, 82, 145], 'papel' => 'a4', 'mostrar_numeros' => true], ['Accept' => 'application/pdf'])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Diagrama-USDA-8-A4.pdf"')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $a4);
        $this->assertStringContainsString('/MediaBox [0 0 842 595]', $a4);
        $this->assertStringContainsString('(DIAGRAMA USDA N.'."\xBA".' 8)', $a4);
        $this->assertStringContainsString('CSP 3176555', $a4);
        $this->assertStringContainsString('No constituye aprobaci'."\xF3".'n del SAG ni del USDA', $a4);
        $this->assertStringContainsString('(1664)', $a4);
        $this->assertSame(3, substr_count($a4, '1 0.824 0.122 rg'));
        // Los paréntesis van escapados dentro del texto del PDF.
        $this->assertStringContainsString('82 \\(c11 p2\\)', $a4);
        $this->assertStringNotContainsString('/Logo', $a4);
        $this->assertDoesNotMatchRegularExpression('/agro\s*rosario/i', $a4);

        $a3 = $this->post('/api/sag/diagramas/pdf', [...$this->diagrama(), 'papel' => 'a3', 'mostrar_numeros' => false], ['Accept' => 'application/pdf'])->assertOk()->getContent();
        $this->assertStringContainsString('/MediaBox [0 0 1191 842]', $a3);
        $this->assertStringNotContainsString('(1664)', $a3);
        $this->assertLessThanOrEqual(substr_count($a4, '/Type /Page '), substr_count($a3, '/Type /Page '));

        $this->postJson('/api/sag/diagramas/pdf', [...$this->diagrama(), 'papel' => 'carta', 'mostrar_numeros' => true])->assertUnprocessable();
    }

    public function test_un_pallet_alto_y_muchos_pallets_se_reparten_en_hojas_sin_salirse(): void
    {
        $pallets = array_fill(0, 40, ['folio' => null, 'base' => 10, 'corridas' => 50, 'ultima' => null, 'declaradas' => null]);
        $pdf = $this->post('/api/sag/diagramas/pdf', [...$this->diagrama(), 'pallets' => $pallets, 'papel' => 'a4', 'mostrar_numeros' => true], ['Accept' => 'application/pdf'])
            ->assertOk()->getContent();
        $this->assertGreaterThan(2, substr_count($pdf, '/Type /Page '));
        $this->assertStringContainsString('(20000)', $pdf);
        preg_match_all('/(-?\d+\.\d+) (-?\d+\.\d+) (\d+\.\d+) (\d+\.\d+) re B/', $pdf, $cajas, PREG_SET_ORDER);
        foreach ($cajas as [, $x, $y, $ancho, $alto]) {
            $this->assertGreaterThanOrEqual(28, (float) $x);
            $this->assertLessThanOrEqual(842 - 28 + 0.01, (float) $x + (float) $ancho);
            $this->assertGreaterThanOrEqual(52 - 0.01, (float) $y);
            $this->assertLessThanOrEqual(595, (float) $y + (float) $alto);
        }
    }

    /** Configuración del «DIAGRAMA USDA Nº 8» corregida: 16 pallets de 8 × 13. */
    private function diagrama(array $cambios = []): array
    {
        return [
            'tipo' => 'usda', 'numero' => '8', 'csp' => '3176555', 'folio' => 'F-1', 'fecha' => '2026-11-11',
            'lote' => 'L-30013', 'especie' => 'Cereza', 'exportadora' => 'Exportadora de prueba',
            'pallets' => array_fill(0, 16, ['folio' => null, 'base' => 8, 'corridas' => 13, 'ultima' => null, 'declaradas' => null]),
            'cajas_marcadas' => [], ...$cambios,
        ];
    }

    private function guardable(array $cambios = []): array
    {
        return $this->diagrama(['operacion_id' => (string) Str::uuid(), ...$cambios]);
    }
}
