<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Rrhh\Integracion\FechaGeoVictoria;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\LoteIntegracion;
use App\Rrhh\Models\Marcaje;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntegracionDirectaRrhhTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    private User $admin;

    private string $persona;

    private string $fuente;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->admin, 'sanctum');
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        $this->persona = (string) Str::uuid();
        Persona::query()->create(['id' => $this->persona, 'codigo' => 'R01', 'nombre' => 'Nombre privado', 'documento' => 'DOCUMENTO_PRIVADO', 'fuente_marcaje' => 'geo']);
        $this->fuente = $this->postJson('/api/rrhh/fuentes', ['codigo' => 'geo', 'nombre' => 'GeoVictoria prueba', 'tipo' => 'geovictoria', 'activa' => true,
            'configuracion' => ['base_url' => 'https://APIV3.geovictoria.com:443', 'api_key' => 'KEY_PRUEBA', 'api_secret' => 'SECRETO_NO_PUBLICAR', 'zona_origen' => 'America/Santiago', 'contrato_checkpoint' => 'validado']])->assertCreated()->json('data.id');
        $this->vincular($this->persona, 'EXT-1');
    }

    public function test_consulta_no_consumidora_firmada_y_lote_cifrado_antes_de_importar_con_reintento_sin_http(): void
    {
        $idLargo = str_repeat('P', 256);
        Http::fake(['https://apiv3.geovictoria.com/api/Punch/ListPendingCheckPoint' => Http::response([
            ['id_punch' => $idLargo, 'identifier' => 'EXT-1', 'type' => 'entry', 'Date' => '20261020080000', 'gps_latitude' => 'GPS_NO_CONSERVAR'],
            ['id_punch' => 'p2', 'identifier' => 'EXT-2', 'type' => 'exit', 'Date' => '20261020170000'],
        ])]);
        $respuesta = $this->consultar()->assertCreated()->assertJsonPath('data.estado', 'con_incidencias')->assertJsonPath('data.resultado.aceptadas', 1)->assertJsonCount(1, 'data.resultado.errores')->assertJsonPath('data.resultado.errores.0.referencia.identificador', 'EXT-2');
        $id = $respuesta->json('data.id');
        $this->assertSame(1, Marcaje::query()->count());
        $this->assertSame('2026-10-20 11:00:00', Marcaje::query()->first()->instante_at->format('Y-m-d H:i:s'));
        $this->assertSame($idLargo, Marcaje::query()->first()->id_externo);
        $lote = LoteIntegracion::query()->findOrFail($id);
        $this->assertStringNotContainsString('EXT-1', $lote->getRawOriginal('contenido'));
        $this->assertStringNotContainsString('GPS_NO_CONSERVAR', json_encode($lote->contenido));
        $this->assertArrayNotHasKey('contenido', $respuesta->json('data'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://apiv3.geovictoria.com/api/Punch/ListPendingCheckPoint' && $r['CheckpointDate'] === '20261001000000' && $r['Range'] === 'EXT-1,EXT-2' && str_starts_with($r->header('Authorization')[0], 'OAuth ') && str_contains($r->header('Authorization')[0], 'oauth_signature=') && ! str_contains($r->header('Authorization')[0], 'SECRETO_NO_PUBLICAR'));
        $p = (string) Str::uuid();
        Persona::query()->create(['id' => $p, 'codigo' => 'R02', 'nombre' => 'Otro trabajador', 'fuente_marcaje' => 'geo']);
        $this->vincular($p, 'EXT-2');
        $this->postJson('/api/rrhh/lotes/'.$id.'/reintentar')->assertOk()->assertJsonPath('data.estado', 'procesado')->assertJsonPath('data.resultado.aceptadas', 1)->assertJsonPath('data.resultado.repetidas', 1);
        $this->postJson('/api/rrhh/lotes/'.$id.'/reintentar')->assertOk();
        Http::assertSentCount(1);
        $this->assertSame(2, Marcaje::query()->count());
        $listado = $this->getJson('/api/rrhh/fuentes/'.$this->fuente.'/lotes')->assertOk()->getContent();
        foreach (['SECRETO_NO_PUBLICAR', 'GPS_NO_CONSERVAR', 'contenido'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $listado);
        }
    }

    public function test_lote_recibido_sobrevive_fallo_antes_de_procesar_y_no_se_vuelve_a_consultar_el_proveedor(): void
    {
        $lote = LoteIntegracion::query()->create(['id' => (string) Str::uuid(), 'fuente_id' => $this->fuente, 'estado' => 'recibido', 'consulta' => ['zona_origen' => 'America/Santiago'],
            'contenido' => [['id_punch' => 'pendiente', 'identifier' => 'EXT-1', 'type' => 'entry', 'Date' => '20261020080000']]]);
        $this->postJson('/api/rrhh/lotes/'.$lote->id.'/reintentar')->assertOk()->assertJsonPath('data.estado', 'procesado');
        Http::assertNothingSent();
        $this->assertSame(1, Marcaje::query()->count());
    }

    public function test_bloquea_destinos_arbitrarios_y_errores_remotos_sin_exponer_cuerpos_o_credenciales(): void
    {
        $f = FuenteMarcaje::query()->findOrFail($this->fuente);
        $f->configuracion = [...$f->configuracion, 'base_url' => 'https://example.test'];
        $f->save();
        $this->consultar()->assertUnprocessable();
        Http::assertNothingSent();
        $f->configuracion = [...$f->configuracion, 'base_url' => 'https://apiv3.geovictoria.com'];
        $f->save();
        Http::fake(['*' => Http::response('SECRETO_NO_PUBLICAR', 500)]);
        $respuesta = $this->consultar()->assertUnprocessable();
        $this->assertStringNotContainsString('SECRETO_NO_PUBLICAR', $respuesta->getContent());
        $this->assertSame(0, LoteIntegracion::query()->count());
        Http::fake(['*' => Http::response(['unexpected' => 'SECRETO_NO_PUBLICAR'])]);
        $this->consultar()->assertUnprocessable();
    }

    public function test_fechas_ambiguas_o_inexistentes_no_se_adivinan(): void
    {
        $this->assertSame('2026-10-21T08:00:00-03:00', FechaGeoVictoria::normalizar('20261021080000', 'America/Santiago'));
        foreach ([['20260404233000', 'America/Santiago'], ['20260906003000', 'America/Santiago'], ['20260230080000', 'America/Santiago'], ['20261021080000', null]] as [$fecha,$zona]) {
            try {
                FechaGeoVictoria::normalizar($fecha, $zona);
                $this->fail('Debe requerir fecha inequívoca');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('Date', $e->errors());
            }
        }
    }

    public function test_outbox_transaccional_idempotente_con_orden_de_aprobacion_y_anulacion_y_acuse(): void
    {
        $id = $this->novedad();
        $this->assertSame(0, EntregaNomina::query()->count());
        $this->estado($id, 'aprobado')->assertOk();
        $this->estado($id, 'aprobado')->assertOk();
        $this->estado($id, 'anulado')->assertOk();
        $this->assertSame(2, EntregaNomina::query()->count());
        $respuesta = $this->getJson('/api/rrhh/nomina/entregas')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.contenido.accion', 'aprobado');
        foreach (['Nombre privado', 'DOCUMENTO_PRIVADO', 'pin_hash'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $respuesta->getContent());
        }
        $entrega = $respuesta->json('data.data.0');
        $anulacion = EntregaNomina::query()->where('secuencia', 2)->firstOrFail();
        $this->postJson('/api/rrhh/nomina/entregas/'.$anulacion->id.'/acuse', ['huella' => $anulacion->huella, 'recibo_externo' => 'reversa-prematura'])->assertUnprocessable();
        $url = '/api/rrhh/nomina/entregas/'.$entrega['id'].'/acuse';
        $this->postJson($url, ['huella' => str_repeat('0', 64), 'recibo_externo' => 'REC-1'])->assertUnprocessable();
        $this->postJson($url, ['huella' => $entrega['huella'], 'recibo_externo' => 'REC-1'])->assertOk();
        $this->postJson($url, ['huella' => $entrega['huella'], 'recibo_externo' => 'REC-1'])->assertOk();
        $this->postJson($url, ['huella' => $entrega['huella'], 'recibo_externo' => 'OTRO-REC'])->assertUnprocessable();
        $this->getJson('/api/rrhh/nomina/entregas')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.contenido.accion', 'anulado');
        $this->postJson('/api/rrhh/nomina/entregas/'.$anulacion->id.'/acuse', ['huella' => $anulacion->huella, 'recibo_externo' => 'REV-1'])->assertOk();
        $this->getJson('/api/rrhh/nomina/entregas')->assertOk()->assertJsonCount(0, 'data.data');
        Http::assertNothingSent();
    }

    public function test_aprobacion_revierte_si_no_puede_prepararse_entrega_y_anular_borrador_no_exporta(): void
    {
        $id = $this->novedad();
        EntregaNomina::creating(fn () => throw new \RuntimeException('Destino no disponible'));
        try {
            $this->estado($id, 'aprobado')->assertStatus(500);
        } finally {
            EntregaNomina::flushEventListeners();
        }
        $this->assertSame('borrador', MovimientoNomina::query()->findOrFail($id)->estado);
        $this->assertSame(0, EntregaNomina::query()->count());
        $this->estado($id, 'anulado')->assertOk();
        $this->assertSame(0, EntregaNomina::query()->count());
    }

    public function test_permisos_aislan_integraciones_de_asistencia_y_novedades_salariales(): void
    {
        $p = PerfilAcceso::query()->create(['codigo' => 'SOLO_RH', 'nombre' => 'Solo personal', 'rol_base' => RolUsuario::Administrador, 'modulos' => ['rrhh.personal'], 'modulos_tablet' => [], 'activo' => true, 'predeterminado' => false, 'protegido' => false]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'perfil_acceso_id' => $p->id]), 'sanctum');
        $this->getJson('/api/rrhh/nomina/entregas')->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum');
        $this->consultar()->assertForbidden();
        Http::assertNothingSent();
    }

    private function consultar()
    {
        return $this->postJson('/api/rrhh/fuentes/'.$this->fuente.'/consultar-geovictoria', ['desde' => '20261001000000', 'identificadores' => ['EXT-1', 'EXT-2']]);
    }

    private function vincular(string $persona, string $externo): void
    {
        $this->postJson('/api/rrhh/fuentes/'.$this->fuente.'/vinculos', ['persona_id' => $persona, 'identificador_externo' => $externo])->assertCreated();
    }

    private function estado(string $id, string $estado)
    {
        return $this->patchJson('/api/rrhh/nomina/movimientos/'.$id, ['estado' => $estado, 'motivo' => 'Revisión respaldada de prueba']);
    }

    private function novedad(): string
    {
        $c = $this->postJson('/api/rrhh/nomina/conceptos', ['codigo' => 'BONO', 'nombre' => 'Bono de turno', 'tipo' => 'haber', 'calculo' => 'monto', 'remuneracion' => true, 'imponible' => true, 'tributable' => true, 'base_he' => false, 'activo' => true, 'fundamento' => 'Tratamiento remuneracional revisado', 'codigo_externo' => 'BONO_DESTINO'])->assertCreated()->json('data.id');

        return $this->postJson('/api/rrhh/nomina/movimientos', ['operacion_id' => (string) Str::uuid(), 'persona_id' => $this->persona, 'concepto_id' => $c, 'fecha' => '2026-10-21', 'cantidad' => '1', 'valor' => '50000', 'respaldo' => 'Bono respaldado por acuerdo escrito'])->assertCreated()->json('data.id');
    }
}
