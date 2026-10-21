<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Rrhh\Models\Afiliacion;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrevisionRrhhTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    protected string $persona;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Ana Pérez']);
        $this->actingAs($this->admin, 'sanctum');
        $this->persona = (string) Str::uuid();
        Persona::query()->create(['id' => $this->persona, 'codigo' => 'PREV01', 'nombre' => 'Persona de prueba', 'fuente_marcaje' => 'folios']);
    }

    public function test_directorio_valida_rut_identidad_y_version_y_conserva_la_copia_afiliada(): void
    {
        $d = $this->datosEntidad();
        $url = '/api/rrhh/prevision/directorio/institucion';
        $this->postJson($url, [...$d, 'rut' => '76000001-0'])->assertUnprocessable();
        $id = $this->postJson($url, $d)->assertCreated()->assertJsonPath('data.rut', '76000001-9')->json('data.id');
        $this->postJson($url, [...$d, 'codigo' => 'OTRA', 'rut' => '76.000.001-9'])->assertUnprocessable();
        $a = $this->postJson('/api/rrhh/prevision/afiliaciones', $this->datosAfiliacion($id))->assertCreated()->json('data.id');
        $this->putJson($url.'/'.$id, [...$d, 'razon_social' => 'Nombre actualizado', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame('AFP de prueba', Afiliacion::query()->findOrFail($a)->institucion['razon_social']);
        $this->putJson($url.'/'.$id, [...$d, 'version' => 1])->assertUnprocessable();
        $this->putJson($url.'/'.$id, [...$d, 'tipo' => 'isapre', 'version' => 2])->assertUnprocessable();
        $this->putJson($url.'/'.$id, [...$d, 'hasta' => '2026-12-30', 'version' => 2])->assertUnprocessable();
        $this->assertSame('Ana Pérez', EventoRrhh::query()->where('entidad', 'afiliacion')->firstOrFail()->user_nombre);
    }

    public function test_afiliacion_rechaza_solapamiento_y_admite_cambio_sucesivo_con_historia(): void
    {
        $i = $this->entidad();
        $d = $this->datosAfiliacion($i);
        $id = $this->postJson('/api/rrhh/prevision/afiliaciones', $d)->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/prevision/afiliaciones', $d)->assertUnprocessable()->assertJsonValidationErrors('desde');
        $this->patchJson('/api/rrhh/prevision/vigencias/afiliacion/'.$id, ['hasta_actual' => $d['hasta'], 'hasta' => '2026-10-31', 'motivo' => 'Cambio de AFP documentado'])->assertOk();
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$d, 'desde' => '2026-11-01'])->assertCreated();
        $this->patchJson('/api/rrhh/prevision/vigencias/afiliacion/'.$id, ['hasta_actual' => $d['hasta'], 'hasta' => '2026-09-30', 'motivo' => 'Solicitud con datos obsoletos'])->assertUnprocessable();
        $this->getJson('/api/rrhh/prevision/afiliaciones?persona_id='.$this->persona)->assertOk()->assertJsonCount(2, 'data.data');
        $this->getJson('/api/rrhh/prevision/historial?entidad=afiliacion&registro_id='.$id)->assertOk()->assertJsonPath('data.data.0.accion', 'terminado');
    }

    public function test_salud_exige_tipo_correcto_y_plan_isapre_segun_fun_sin_precio_en_fonasa(): void
    {
        $afp = $this->entidad();
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$this->datosAfiliacion($afp), 'regimen' => 'salud'])->assertUnprocessable()->assertJsonValidationErrors('institucion_id');
        $isapre = $this->entidad(['codigo' => 'ISAPRE', 'rut' => '76000002-7', 'tipo' => 'isapre']);
        $d = [...$this->datosAfiliacion($isapre), 'regimen' => 'salud'];
        $this->postJson('/api/rrhh/prevision/afiliaciones', $d)->assertUnprocessable()->assertJsonValidationErrors('precio_plan');
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$d, 'moneda_plan' => 'UF', 'precio_plan' => '3.45'])->assertCreated()->assertJsonPath('data.precio_plan', '3.450000');
        $fonasa = $this->entidad(['codigo' => 'FONASA', 'rut' => '76000003-5', 'tipo' => 'fonasa']);
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$this->datosAfiliacion($fonasa), 'regimen' => 'salud', 'moneda_plan' => 'CLP', 'precio_plan' => '100000'])->assertUnprocessable();
        $this->getJson('/api/rrhh/prevision/directorio/institucion?regimen=salud')->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_ccaf_y_accidentes_corresponden_al_empleador_y_no_se_asignan_a_la_persona(): void
    {
        $e = $this->entidad(['tipo' => 'propio'], 'empleador');
        $c = $this->entidad(['codigo' => 'CAJA', 'rut' => '76000002-7', 'tipo' => 'ccaf']);
        $d = [...$this->datosAfiliacion($c), 'regimen' => 'ccaf'];
        $this->postJson('/api/rrhh/prevision/afiliaciones', $d)->assertUnprocessable();
        unset($d['persona_id']);
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$d, 'empleador_id' => $e])->assertCreated();
        $this->getJson('/api/rrhh/prevision/afiliaciones?empleador_id='.$e)->assertOk()->assertJsonCount(1, 'data.data');
        $this->getJson('/api/rrhh/prevision/afiliaciones?persona_id='.$this->persona.'&empleador_id='.$e)->assertUnprocessable();
    }

    public function test_contrato_identifica_empleador_y_prorrogas_sin_solapar_ni_mezclar_con_honorarios(): void
    {
        $e = $this->entidad(['tipo' => 'contratista'], 'empleador');
        $d = ['persona_id' => $this->persona, 'empleador_id' => $e, 'referencia' => 'CONTRATO-1', 'tipo' => 'plazo_fijo', 'desde' => '2026-10-01', 'hasta' => '2026-10-30', 'respaldo' => 'Contrato firmado por las partes'];
        $id = $this->postJson('/api/rrhh/prevision/contratos', $d)->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/prevision/contratos', [...$d, 'referencia' => 'SOLAPADO'])->assertUnprocessable();
        $this->postJson('/api/rrhh/prevision/contratos', [...$d, 'referencia' => 'PRORROGA', 'prorroga_de' => $id, 'desde' => '2026-11-01', 'hasta' => '2026-11-30'])->assertUnprocessable();
        $this->postJson('/api/rrhh/prevision/contratos', [...$d, 'referencia' => 'PRORROGA', 'prorroga_de' => $id, 'desde' => '2026-10-31', 'hasta' => '2026-11-30'])->assertCreated();
        $this->postJson('/api/rrhh/prevision/contratos', [...$d, 'referencia' => 'BOLETA', 'tipo' => 'honorarios', 'prorroga_de' => $id, 'desde' => '2026-10-31', 'hasta' => '2026-11-30'])->assertUnprocessable();
        $this->assertSame('contratista', ContratoLaboral::query()->findOrFail($id)->empleador['tipo']);
    }

    public function test_permisos_salariales_protegen_directorios_contratos_y_afiliaciones(): void
    {
        $perfil = PerfilAcceso::query()->create(['codigo' => 'PREVISION_TEST', 'nombre' => 'Consulta RRHH', 'rol_base' => RolUsuario::Consulta, 'modulos' => ['rrhh.personal'], 'modulos_tablet' => [], 'activo' => true]);
        $u = User::factory()->create(['rol' => RolUsuario::Consulta, 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($u, 'sanctum');
        $this->getJson('/api/rrhh/prevision/directorio/institucion')->assertForbidden();
        $this->getJson('/api/rrhh/prevision/contratos?persona_id='.$this->persona)->assertForbidden();
        $this->postJson('/api/rrhh/prevision/directorio/institucion', $this->datosEntidad())->assertForbidden();
        $perfil->update(['modulos' => ['rrhh.personal', 'rrhh.remuneraciones']]);
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/rrhh/prevision/directorio/institucion')->assertOk();
        $this->postJson('/api/rrhh/prevision/directorio/institucion', $this->datosEntidad())->assertForbidden();
    }

    protected function datosEntidad(array $cambios = []): array
    {
        return [...['codigo' => 'AFP_PRUEBA', 'rut' => '76000001-9', 'razon_social' => 'AFP de prueba', 'tipo' => 'afp', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'activo' => true, 'verificado_en' => '2026-10-21', 'respaldo' => 'Registro oficial verificado por RRHH'], ...$cambios];
    }

    protected function entidad(array $cambios = [], string $tipo = 'institucion'): string
    {
        return $this->postJson('/api/rrhh/prevision/directorio/'.$tipo, $this->datosEntidad($cambios))->assertCreated()->json('data.id');
    }

    protected function datosAfiliacion(string $institucion): array
    {
        return ['persona_id' => $this->persona, 'institucion_id' => $institucion, 'regimen' => 'afp', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'referencia' => 'AFILIACION-01', 'respaldo' => 'Certificado de afiliación revisado'];
    }
}
