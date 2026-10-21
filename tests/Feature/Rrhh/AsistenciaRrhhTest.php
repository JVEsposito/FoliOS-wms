<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\FuenteMarcaje;
use App\Rrhh\Models\Marcaje;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AsistenciaRrhhTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rrhh.conexion_lectura' => config('database.default')]);
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->admin, 'sanctum');
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:00:00Z'));
    }

    public function test_personal_independiente_del_login_pin_oculto_y_edicion_con_version(): void
    {
        $usuarios = User::query()->count();
        $id = $this->persona();
        $this->assertSame($usuarios, User::query()->count());
        $this->assertNotSame('345678', Persona::query()->findOrFail($id)->pin_hash);
        $respuesta = $this->getJson('/api/rrhh/personas')->assertOk()->assertJsonPath('data.data.0.codigo', 'OP01');
        $this->assertStringNotContainsString('pin_hash', $respuesta->getContent());
        $this->assertStringNotContainsString('345678', json_encode(EventoRrhh::query()->get()));
        $this->putJson('/api/rrhh/personas/'.$id, [...$this->datosPersona(), 'nombre' => 'Nombre nuevo', 'version' => '1'])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/rrhh/personas/'.$id, [...$this->datosPersona(), 'version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_el_reloj_toma_la_hora_del_servidor_y_el_reintento_no_duplica(): void
    {
        $this->persona();
        $datos = ['operacion_id' => (string) Str::uuid(), 'codigo' => 'OP01', 'pin' => '345678', 'tipo' => 'entrada'];
        $marca = $this->postJson('/api/rrhh/marcajes', $datos)->assertCreated()->assertJsonMissingPath('data.persona_id')->json('data');
        $this->assertSame('2026-11-02T12:00:00.000000Z', $marca['instante_at']);
        $this->travel(3)->minutes();
        $this->postJson('/api/rrhh/marcajes', $datos)->assertCreated()->assertJsonPath('data.id', $marca['id'])->assertJsonPath('data.instante_at', $marca['instante_at']);
        $this->postJson('/api/rrhh/marcajes', [...$datos, 'instante' => '2020-01-01T00:00:00Z'])->assertUnprocessable();
        $this->postJson('/api/rrhh/marcajes', [...$datos, 'pin' => '111111'])->assertUnprocessable();
        $this->assertSame(1, Marcaje::query()->count());
    }

    public function test_puesto_de_marcaje_no_puede_leer_fichas_ni_corregir_asistencia(): void
    {
        $this->persona();
        $puesto = $this->perfil(['rrhh.marcaje']);
        $this->actingAs($puesto, 'sanctum');
        $this->getJson('/api/rrhh/personas')->assertForbidden();
        $marca = $this->postJson('/api/rrhh/marcajes', ['operacion_id' => (string) Str::uuid(), 'codigo' => 'OP01', 'pin' => '345678', 'tipo' => 'entrada'])->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/marcajes/'.$marca.'/corregir', ['motivo' => 'Corrección no autorizada'])->assertForbidden();
        $this->getJson('/api/rrhh/historial?entidad=marcaje&registro_id='.$marca)->assertForbidden();
    }

    public function test_turno_nocturno_descanso_costo_y_cancelacion_conservan_la_programacion(): void
    {
        $persona = $this->persona();
        $datos = $this->datosAsignacion($persona);
        $id = $this->postJson('/api/rrhh/asignaciones', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/asignaciones', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/rrhh/asignaciones', [...$datos, 'operacion_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('desde');
        $this->getJson('/api/rrhh/asignaciones?desde=2026-11-01&hasta=2026-11-02')->assertOk()->assertJsonPath('data.data.0.horas_programadas', '7.5')->assertJsonPath('data.data.0.costo_programado', '7500')->assertJsonPath('data.data.0.asistencia_valida', false);
        $this->patchJson('/api/rrhh/asignaciones/'.$id.'/estado', ['version' => 1, 'estado' => 'cancelada', 'motivo' => 'Cliente suspendió el servicio.'])->assertOk();
        $this->getJson('/api/rrhh/asignaciones?desde=2026-11-01&hasta=2026-11-02')->assertOk()->assertJsonPath('data.data.0.horas_programadas', '7.5')->assertJsonPath('data.data.0.jornadas_programadas', '1');
        $this->patchJson('/api/rrhh/asignaciones/'.$id.'/estado', ['version' => 2, 'estado' => 'programada', 'motivo' => 'Se intenta reabrir.'])->assertUnprocessable();
    }

    public function test_importa_con_vinculos_explicitos_sin_repetir_ni_perder_errores_del_lote(): void
    {
        $fuente = $this->fuente();
        $persona = $this->persona(['fuente_marcaje' => 'geo']);
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/vinculos', ['persona_id' => $persona, 'identificador_externo' => 'EXT-1'])->assertCreated();
        $filas = [
            ['id_externo' => 'p1', 'identificador' => 'EXT-1', 'tipo' => 'entrada', 'instante' => '2026-11-01T22:00:00-03:00'],
            ['id_externo' => 'P1', 'identificador' => 'EXT-1', 'tipo' => 'salida', 'instante' => '2026-11-02T06:00:00-03:00'],
            ['id_externo' => 'p-caso', 'identificador' => 'ext-1', 'tipo' => 'entrada', 'instante' => '2026-11-01T22:00:00-03:00'],
            ['id_externo' => 'p3', 'identificador' => 'SIN-VINCULO', 'tipo' => 'entrada', 'instante' => '2026-11-01T22:00:00-03:00'],
            ['id_externo' => 'p4', 'identificador' => 'EXT-1', 'tipo' => 'entrada', 'instante' => '2026-11-01 22:00:00'],
        ];
        $url = '/api/rrhh/fuentes/'.$fuente.'/importar';
        $this->postJson($url, ['marcajes' => $filas])->assertCreated()->assertJsonPath('data.aceptadas', 2)->assertJsonCount(3, 'data.errores');
        $this->postJson($url, ['marcajes' => $filas])->assertCreated()->assertJsonPath('data.repetidas', 2)->assertJsonPath('data.aceptadas', 0);
        $this->postJson($url, ['marcajes' => [[...$filas[0], 'instante' => '2026-11-01T23:00:00-03:00']]])->assertCreated()->assertJsonCount(1, 'data.errores');
        $this->assertSame(2, Marcaje::query()->count());
        $this->postJson('/api/rrhh/marcajes', ['operacion_id' => (string) Str::uuid(), 'codigo' => 'OP01', 'pin' => '345678', 'tipo' => 'entrada'])->assertUnprocessable();
    }

    public function test_geo_punch_se_normaliza_y_la_configuracion_no_se_expone_ni_se_audita(): void
    {
        $fuente = $this->fuente('geovictoria');
        $persona = $this->persona(['fuente_marcaje' => 'geo']);
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/vinculos', ['persona_id' => $persona, 'identificador_externo' => 'EXT-1'])->assertCreated();
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/importar', ['marcajes' => [['id_punch' => 'gv1', 'UserIdentifier' => 'EXT-1', 'Type' => 'Ingreso', 'Date' => '2026-11-01T08:00:00-03:00']]])->assertCreated()->assertJsonPath('data.aceptadas', 1);
        $this->assertStringNotContainsString('test_secret_123', FuenteMarcaje::query()->findOrFail($fuente)->getRawOriginal('configuracion'));
        $this->assertStringNotContainsString('test_secret_123', $this->getJson('/api/rrhh/personas')->assertOk()->getContent());
        $this->assertStringNotContainsString('test_secret_123', json_encode(EventoRrhh::query()->get()));
    }

    public function test_corregir_conserva_la_marca_original_y_el_replay_externo_no_la_reactiva(): void
    {
        $fuente = $this->fuente();
        $persona = $this->persona(['fuente_marcaje' => 'geo']);
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/vinculos', ['persona_id' => $persona, 'identificador_externo' => 'EXT-1'])->assertCreated();
        $fila = ['id_externo' => 'p1', 'identificador' => 'EXT-1', 'tipo' => 'entrada', 'instante' => '2026-11-01T22:00:00-03:00'];
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/importar', ['marcajes' => [$fila]])->assertCreated();
        $marca = Marcaje::query()->firstOrFail();
        $this->postJson('/api/rrhh/marcajes/'.$marca->id.'/corregir', ['motivo' => 'Error de digitación respaldado.', 'instante' => '2026-11-01T21:50:00-03:00', 'tipo' => 'entrada'])->assertOk()->assertJsonPath('data.corrige_id', $marca->id);
        $this->assertNotNull($marca->fresh()->anulado_at);
        $this->assertSame('2026-11-02 01:00:00', $marca->fresh()->instante_at->format('Y-m-d H:i:s'));
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/importar', ['marcajes' => [$fila]])->assertCreated()->assertJsonPath('data.repetidas', 1);
        $this->assertNotNull($marca->fresh()->anulado_at);
        $this->assertSame(2, Marcaje::query()->count());
        $this->getJson('/api/rrhh/historial?entidad=marcaje&registro_id='.$marca->id)->assertOk()->assertJsonPath('data.data.0.accion', 'corregido');
    }

    public function test_las_horas_asistidas_se_recortan_al_turno_y_una_salida_faltante_lo_marca_para_revision(): void
    {
        $fuente = $this->fuente();
        $persona = $this->persona(['fuente_marcaje' => 'geo']);
        $this->postJson('/api/rrhh/fuentes/'.$fuente.'/vinculos', ['persona_id' => $persona, 'identificador_externo' => 'EXT-1'])->assertCreated();
        $this->postJson('/api/rrhh/asignaciones', $this->datosAsignacion($persona))->assertCreated();
        $url = '/api/rrhh/fuentes/'.$fuente.'/importar';
        $this->postJson($url, ['marcajes' => [['id_externo' => 'p1', 'identificador' => 'EXT-1', 'tipo' => 'entrada', 'instante' => '2026-11-01T21:55:00-03:00']]])->assertCreated();
        $this->getJson('/api/rrhh/asignaciones?desde=2026-11-01&hasta=2026-11-02')->assertOk()->assertJsonPath('data.data.0.asistencia_valida', false)->assertJsonPath('data.data.0.costo_asistencia', null);
        $this->postJson($url, ['marcajes' => [['id_externo' => 'p2', 'identificador' => 'EXT-1', 'tipo' => 'salida', 'instante' => '2026-11-02T06:05:00-03:00']]])->assertCreated();
        $this->getJson('/api/rrhh/asignaciones?desde=2026-11-01&hasta=2026-11-02')->assertOk()->assertJsonPath('data.data.0.asistencia_valida', true)->assertJsonPath('data.data.0.horas_asistidas', '8');
    }

    public function test_consulta_y_roles_operativos_no_modifican_rrhh(): void
    {
        $this->actingAs($this->perfil(['rrhh.personal'], RolUsuario::Consulta), 'sanctum');
        $this->getJson('/api/rrhh/personas')->assertOk()->assertJsonPath('permisos.gestionar', false);
        $this->postJson('/api/rrhh/personas', $this->datosPersona())->assertForbidden();
        $this->actingAs($this->perfil([]), 'sanctum')->getJson('/api/rrhh/personas')->assertForbidden();
    }

    private function datosPersona(): array
    {
        return ['codigo' => 'OP01', 'nombre' => 'Persona Operativa', 'documento' => 'DOC-PRUEBA', 'cargo' => 'Camarero', 'area' => 'Frigorífico', 'ceco' => 'FRIO', 'activa' => true, 'fuente_marcaje' => 'folios', 'pin' => '345678'];
    }

    private function persona(array $cambios = []): string
    {
        return $this->postJson('/api/rrhh/personas', [...$this->datosPersona(), ...$cambios])->assertCreated()->json('data.id');
    }

    private function datosAsignacion(string $persona): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'persona_id' => $persona, 'cargo' => 'Camarero', 'area' => 'Frigorífico', 'desde' => '2026-11-01T22:00:00-03:00', 'hasta' => '2026-11-02T06:00:00-03:00', 'descanso_programado_minutos' => 30, 'costo_hora' => '1000', 'moneda_costo' => 'CLP'];
    }

    private function fuente(string $tipo = 'normalizado'): string
    {
        return $this->postJson('/api/rrhh/fuentes', ['codigo' => 'geo', 'nombre' => 'GeoVictoria planta', 'tipo' => $tipo, 'activa' => true, 'configuracion' => ['api_secret' => 'test_secret_123']])->assertCreated()->json('data.id');
    }

    private function perfil(array $modulos, RolUsuario $rol = RolUsuario::Administrador): User
    {
        $perfil = PerfilAcceso::query()->create(['codigo' => 'RRHH_'.Str::upper(Str::random(6)), 'nombre' => 'Perfil RRHH de prueba', 'rol_base' => $rol, 'modulos' => $modulos, 'modulos_tablet' => [], 'activo' => true, 'predeterminado' => false, 'protegido' => false]);

        return User::factory()->create(['rol' => $rol, 'perfil_acceso_id' => $perfil->id, 'activo' => true]);
    }
}
