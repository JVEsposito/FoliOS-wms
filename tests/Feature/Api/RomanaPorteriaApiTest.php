<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\IngresoPorteria;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RomanaPorteriaApiTest extends TestCase
{
    use RefreshDatabase;

    private function ingreso(array $cambios = []): IngresoPorteria
    {
        $guardia = User::factory()->create(['rol' => RolUsuario::GuardiaPorteria]);

        return IngresoPorteria::query()->create([
            ...['operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
                'cliente_nombre' => 'Falco Fruit', 'cliente_es_otro' => true, 'motivo' => 'carga',
                'patente' => 'ABCD12', 'tipo_camion' => 'termo', 'nombre_conductor' => 'Conductor prueba',
                'guias' => ['000123', '000124'], 'ingreso_at' => now(), 'creado_por_user_id' => $guardia->id,
                'creado_por_nombre' => $guardia->name, 'cantidad' => 24, 'unidad' => 'bins'],
            ...$cambios,
        ]);
    }

    public function test_romana_ve_pendientes_de_dias_anteriores_y_revisar_no_crea_recepciones(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 15:00:00', 'UTC'));
        $ingreso = $this->ingreso(['ingreso_at' => now()->subDay()]);
        $this->ingreso(['anulado_at' => now()]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->actingAs($operador, 'sanctum');
        $this->getJson('/api/romana/porteria/resumen')->assertOk()->assertJsonPath('pendientes', 1)->assertJsonPath('recientes.0.id', $ingreso->id);
        $this->getJson('/api/porteria/ingresos?pendientes=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('puede_revisar', true);
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => 1])->assertOk()->assertJsonPath('data.revisado_por_nombre', $operador->name);
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => 1])->assertOk();
        $this->assertSame(1, $ingreso->eventos()->where('accion', 'revisado')->count());
        $this->getJson('/api/romana/porteria/resumen')->assertJsonPath('pendientes', 0);
        $this->assertDatabaseCount('recepciones_romana', 0);
    }

    public function test_guardia_no_revisa_ni_descarga_pdf_y_consulta_solo_lee(): void
    {
        $ingreso = $this->ingreso();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::GuardiaPorteria]), 'sanctum');
        $this->get('/api/romana/porteria/diario.pdf?fecha=2026-10-19')->assertForbidden();
        $this->getJson('/api/romana/porteria/resumen')->assertForbidden();
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum');
        $this->getJson('/api/romana/porteria/resumen')->assertOk();
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => 1])->assertForbidden();
    }

    public function test_una_correccion_vuelve_a_pendientes_y_la_version_obsoleta_no_pisa_cambios(): void
    {
        $ingreso = $this->ingreso(['revisado_at' => now()]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $datos = ['version' => 1, 'cliente_otro' => 'Falco Fruit', 'motivo' => 'carga', 'patente' => 'EFGH34', 'tipo_camion' => 'termo', 'nombre_conductor' => 'Conductor corregido', 'guias' => ['000123'], 'motivo_correccion' => 'Corrección de patente'];
        $this->putJson('/api/porteria/ingresos/'.$ingreso->id, $datos)->assertOk()->assertJsonPath('data.revisado_at', null);
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => 1])->assertConflict();
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => 2])->assertOk();
        $ingreso->refresh();
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/anular', ['version' => $ingreso->version, 'motivo' => 'El ingreso se registró por error'])->assertOk();
        $this->postJson('/api/porteria/ingresos/'.$ingreso->id.'/revisar', ['version' => $ingreso->version])->assertConflict();
    }

    public function test_pdf_diario_corta_por_chile_cuenta_llegadas_y_separa_cantidades_y_anulados(): void
    {
        $this->ingreso(['ingreso_at' => CarbonImmutable::parse('2026-10-20 02:30:00', 'UTC')]);
        $this->ingreso(['ingreso_at' => CarbonImmutable::parse('2026-10-19 15:00:00', 'UTC'), 'cantidad' => 10, 'unidad' => 'bultos']);
        $this->ingreso(['ingreso_at' => CarbonImmutable::parse('2026-10-19 16:00:00', 'UTC'), 'cantidad' => 99, 'unidad' => 'bins', 'anulado_at' => now()]);
        $this->ingreso(['ingreso_at' => CarbonImmutable::parse('2026-10-20 03:30:00', 'UTC'), 'cliente_nombre' => 'FUERA-DEL-DIA']);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $respuesta = $this->get('/api/romana/porteria/diario.pdf?fecha=2026-10-19')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $respuesta->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        foreach (['2 ingresos', '1 anulados', '24 bins', '10 bultos', '000123', 'Anulado'] as $texto) {
            $this->assertStringContainsString($texto, $pdf);
        }
        $this->assertStringNotContainsString('FUERA-DEL-DIA', $pdf);
        $this->getJson('/api/romana/porteria/diario.pdf?fecha=incorrecta')->assertUnprocessable();
        $this->ingreso(['ingreso_at' => CarbonImmutable::parse('2026-09-06 04:30:00', 'UTC'), 'cliente_nombre' => 'DENTRO-DST']);
        $this->ingreso(['ingreso_at' => CarbonImmutable::parse('2026-09-07 03:30:00', 'UTC'), 'cliente_nombre' => 'FUERA-DST']);
        $pdfCambioHora = $this->get('/api/romana/porteria/diario.pdf?fecha=2026-09-06')->assertOk()->getContent();
        $this->assertStringContainsString('DENTRO-DST', $pdfCambioHora);
        $this->assertStringNotContainsString('FUERA-DST', $pdfCambioHora);
    }

    public function test_pdf_vacio_y_registro_con_muchas_guias_paginan_sin_perder_el_final(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $this->get('/api/romana/porteria/diario.pdf?fecha=2020-01-01')->assertOk()->assertSee('Sin ingresos registrados', false);
        $guias = array_map(fn ($i): string => str_repeat('1234567890', 4).str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(1, 29));
        $guias[] = 'ULTIMA-GUIA';
        $this->ingreso(['guias' => $guias]);
        $pdf = $this->get('/api/romana/porteria/diario.pdf?fecha='.now('America/Santiago')->toDateString())->assertOk()->getContent();
        $this->assertGreaterThan(1, substr_count($pdf, '/Type /Page '));
        $this->assertStringContainsString('ULTIMA-GUIA', $pdf);
    }
}
