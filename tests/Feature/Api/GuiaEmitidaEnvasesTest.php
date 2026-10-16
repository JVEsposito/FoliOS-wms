<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ArchivoGuiaDespachoEnvase;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuiaEmitidaEnvasesTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $cliente;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Santiago'));
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->cliente = Cliente::create(['codigo' => 'AG-001', 'nombre' => 'La Aguada', 'activo' => true]);
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id,
            'cliente_id' => $this->cliente->id,
            'documento_tipo' => 'recepcion_romana',
            'numero_documento' => 'FAC-1',
            'tipo_movimiento' => 'recepcion_compra',
            'tipo_envase' => 'bins',
            'cantidad' => 200,
            'signo_cuenta' => 0,
            'signo_existencia' => 1,
            'propiedad' => 'propia',
            'ocurrido_at' => now()->subDay(),
            'ingreso_at' => now()->subDay(),
            'estado_revision' => 'pendiente',
            'creado_por_user_id' => $this->administrador->id,
        ]);
        $this->actingAs($this->administrador, 'sanctum');
    }

    public function test_confirmar_la_salida_pide_el_numero_de_la_guia_emitida(): void
    {
        $primera = $this->borrador(50);
        $this->postJson("/api/envases/guias-despacho/{$primera['id']}/confirmar")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('numero_guia_despacho');
        $this->postJson("/api/envases/guias-despacho/{$primera['id']}/confirmar", ['numero_guia_despacho' => 'N° 12'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.numero_guia_despacho.0', 'Ingresa el N° de la guía de despacho emitida, solo el número (por ejemplo, 12345).');
        $this->assertDatabaseCount('movimientos_envases', 1);

        // Puntos de miles y ceros a la izquierda no cambian el folio.
        $this->postJson("/api/envases/guias-despacho/{$primera['id']}/confirmar", ['numero_guia_despacho' => '0012.345'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.numero_guia_despacho', '12345')
            ->assertJsonPath('data.falta_numero_guia', false);
        $guia = GuiaDespachoEnvase::query()->findOrFail($primera['id']);
        $this->assertSame('12345', $guia->documento_snapshot['numero_guia_despacho']);
        $this->assertDatabaseHas('eventos_guias_despacho_envases', [
            'guia_despacho_envase_id' => $guia->id,
            'tipo' => 'confirmada',
        ]);
        $pdf = $this->get("/api/envases/guias-despacho/{$guia->id}/documento")->assertOk()->getContent();
        $this->assertStringContainsString('12345 \\(sistema de facturaci', $pdf);

        $segunda = $this->borrador(30);
        $this->postJson("/api/envases/guias-despacho/{$segunda['id']}/confirmar", ['numero_guia_despacho' => '12345'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.numero_guia_despacho.0', "La guía de despacho N° 12345 ya está registrada en {$primera['numero']}.");
        $this->postJson("/api/envases/guias-despacho/{$segunda['id']}/confirmar", ['numero_guia_despacho' => '12346'])
            ->assertOk();
    }

    public function test_se_registra_el_numero_en_guias_ya_confirmadas_y_se_corrige_con_motivo(): void
    {
        $guia = $this->confirmada(40, '700');
        // Guía confirmada antes de que FoliOS pidiera el número.
        DB::table('guias_despacho_envases')->where('id', $guia['id'])->update(['numero_guia_despacho' => null]);
        $this->getJson("/api/envases/guias-despacho/{$guia['id']}")
            ->assertJsonPath('data.falta_numero_guia', true)
            ->assertJsonPath('data.puede_registrar_guia', true);

        $this->putJson("/api/envases/guias-despacho/{$guia['id']}/numero-guia", ['numero_guia_despacho' => '777'])
            ->assertOk()
            ->assertJsonPath('data.numero_guia_despacho', '777')
            ->assertJsonPath('data.falta_numero_guia', false);
        $this->putJson("/api/envases/guias-despacho/{$guia['id']}/numero-guia", ['numero_guia_despacho' => '778'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
        $this->putJson("/api/envases/guias-despacho/{$guia['id']}/numero-guia", [
            'numero_guia_despacho' => '778',
            'motivo' => 'Se digitó mal el folio.',
        ])->assertOk()->assertJsonPath('data.numero_guia_despacho', '778');

        $eventos = $this->getJson("/api/envases/guias-despacho/{$guia['id']}")->json('data.eventos');
        $this->assertEquals(
            ['anterior' => null, 'nuevo' => '777', 'motivo' => null],
            collect($eventos)->firstWhere('tipo', 'numero_guia_registrado')['datos'],
        );
        $this->assertEquals(
            ['anterior' => '777', 'nuevo' => '778', 'motivo' => 'Se digitó mal el folio.'],
            collect($eventos)->firstWhere('tipo', 'numero_guia_corregido')['datos'],
        );

        $borrador = $this->borrador(10);
        $this->putJson("/api/envases/guias-despacho/{$borrador['id']}/numero-guia", ['numero_guia_despacho' => '900'])
            ->assertConflict();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->putJson("/api/envases/guias-despacho/{$guia['id']}/numero-guia", ['numero_guia_despacho' => '901'])
            ->assertForbidden();
    }

    public function test_el_archivo_de_la_guia_se_sube_se_reemplaza_y_se_descarga(): void
    {
        Storage::fake('local');
        $guia = $this->confirmada(40, '12345');
        $url = "/api/envases/guias-despacho/{$guia['id']}/archivo-guia";

        $this->post($url, ['archivo' => UploadedFile::fake()->create('notas.txt', 10, 'text/plain')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('archivo');
        $this->post($url, ['archivo' => UploadedFile::fake()->create('scan-001.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.archivo_guia.nombre', 'scan-001.pdf')
            ->assertJsonPath('data.archivo_guia.mime', 'application/pdf');
        // El mismo archivo otra vez (doble clic) no duplica el respaldo.
        $this->post($url, ['archivo' => UploadedFile::fake()->create('scan-001.pdf', 120, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertDatabaseCount('archivos_guias_despacho_envases', 1);
        $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('guia-despacho-12345.pdf');

        $this->post($url, ['archivo' => UploadedFile::fake()->image('foto-guia.jpg', 800, 1100)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.archivo_guia.nombre', 'foto-guia.jpg');
        $archivos = ArchivoGuiaDespachoEnvase::query()->orderBy('subido_at')->orderBy('created_at')->get();
        $this->assertCount(2, $archivos);
        $this->assertSame(1, $archivos->whereNull('reemplazado_at')->count());
        $archivos->each(fn (ArchivoGuiaDespachoEnvase $archivo) => Storage::disk('local')->assertExists($archivo->ruta));
        $this->get($url)->assertOk()->assertDownload('guia-despacho-12345.jpg');
        $this->assertDatabaseHas('eventos_guias_despacho_envases', ['guia_despacho_envase_id' => $guia['id'], 'tipo' => 'archivo_guia_reemplazado']);

        $borrador = $this->borrador(10);
        $this->post("/api/envases/guias-despacho/{$borrador['id']}/archivo-guia", [
            'archivo' => UploadedFile::fake()->create('guia.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertConflict();
        $this->getJson("/api/envases/guias-despacho/{$borrador['id']}/archivo-guia")->assertConflict();
    }

    public function test_el_reinicio_operacional_borra_los_archivos_de_las_guias(): void
    {
        Storage::fake('local');
        $guia = $this->confirmada(40, '12345');
        $this->post("/api/envases/guias-despacho/{$guia['id']}/archivo-guia", [
            'archivo' => UploadedFile::fake()->create('guia.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $ruta = ArchivoGuiaDespachoEnvase::query()->sole()->ruta;
        Storage::disk('local')->assertExists($ruta);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();

        $this->postJson("/api/administracion/temporadas/{$temporada->id}/reinicio-operacional", [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'Cierre controlado de la etapa de pruebas integrales.',
            'password' => 'password',
            'confirmacion' => "REINICIAR {$temporada->codigo}",
            'confirmar_exclusion_bodega' => true,
            'confirmar_preservar_configuracion' => true,
        ])->assertCreated()->assertJsonPath('data.resumen_eliminado.archivos_guias_envases', 1);

        $this->assertDatabaseCount('archivos_guias_despacho_envases', 0);
        $this->assertDatabaseCount('guias_despacho_envases', 0);
        Storage::disk('local')->assertMissing($ruta);
    }

    /** @return array<string, mixed> */
    private function borrador(int $cantidad): array
    {
        return $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $this->cliente->id,
            'salida_at' => '2026-10-16T08:30:00-03:00',
            'detalles' => [['tipo_envase' => 'bins', 'cantidad' => $cantidad, 'propiedad' => 'propia']],
        ])->assertCreated()->json('data');
    }

    /** @return array<string, mixed> */
    private function confirmada(int $cantidad, string $numero): array
    {
        $guia = $this->borrador($cantidad);

        return $this->postJson("/api/envases/guias-despacho/{$guia['id']}/confirmar", ['numero_guia_despacho' => $numero])
            ->assertOk()
            ->json('data');
    }
}
