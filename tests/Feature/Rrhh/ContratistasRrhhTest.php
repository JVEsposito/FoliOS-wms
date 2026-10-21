<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Rrhh\Models\AntecedenteContratista;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\ExpedienteContratista;
use App\Rrhh\Models\Institucion;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use App\Rrhh\Models\RetencionContratista;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ContratistasRrhhTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    private Empleador $empresa;

    private Persona $persona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Ana Pérez']), 'sanctum');
        $this->empresa = Empleador::query()->create(['id' => (string) Str::uuid(), 'codigo' => 'CONTRATISTA', 'rut' => '76000010-8', 'razon_social' => 'Contratista de prueba', 'tipo' => 'contratista', 'activo' => true, 'desde' => '2020-01-01', 'hasta' => '9999-12-31', 'verificado_en' => '2026-10-21', 'respaldo' => 'Antecedentes del contratista revisados']);
        $this->persona = $this->persona('EX01');
    }

    public function test_f30_no_sustituye_f301_y_registrar_no_equivale_a_revisar_cumplimiento(): void
    {
        $exp = $this->expediente();
        $f30 = $this->antecedente($exp, 'f30');
        $this->revisar($f30);
        $this->revisionExp($exp)->assertUnprocessable()->assertJsonValidationErrors('estado');
        $f301 = $this->antecedente($exp, 'f301');
        $this->assertSame('pendiente', $f301->estado);
        $this->getJson($this->url($exp))->assertOk()->assertJsonPath('data.estado', 'pendiente');
        $this->revisionExp($exp)->assertUnprocessable();
        $this->revisar($f301);
        foreach (['remuneraciones', 'pago_remuneraciones', 'cotizaciones'] as $tipo) {
            $this->revisar($this->antecedente($exp, $tipo));
        }
        $this->revisionExp($exp)->assertOk()->assertJsonPath('data.estado', 'conforme')->assertJsonPath('data.revisado_por', 'Ana Pérez');
        $this->getJson('/api/rrhh/contratistas/historial?entidad=expediente_contratista&registro_id='.$exp->id)->assertOk()->assertJsonPath('data.data.0.user_nombre', 'Ana Pérez');
        $this->assertSame(5, AntecedenteContratista::query()->count());
    }

    public function test_cambiar_dotacion_invalida_revision_y_exige_cobertura_para_nuevas_personas(): void
    {
        $segunda = $this->persona('EX02');
        $exp = $this->expediente();
        $this->completo($exp);
        $this->revisionExp($exp)->assertOk();
        $version = $exp->fresh()->version;
        $this->patchJson($this->url($exp).'/personal', ['version' => $version - 1, 'personal' => [$this->persona->id, $segunda->id], 'motivo' => 'Ingreso de otra persona a la faena'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->patchJson($this->url($exp).'/personal', ['version' => $version, 'personal' => [$this->persona->id, $segunda->id], 'motivo' => 'Ingreso de otra persona a la faena'])->assertOk()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.personas_count', 2);
        $faltantes = $this->getJson($this->url($exp))->assertOk()->json('faltantes');
        $this->assertCount(4, $faltantes);
        $this->assertContains('f301: 1 persona(s) sin cobertura conforme', $faltantes);
        $this->revisionExp($exp)->assertUnprocessable();
        $this->assertSame(1, EventoRrhh::query()->where('registro_id', $exp->id)->where('accion', 'personal')->count());
    }

    public function test_observaciones_bloquean_y_descartar_conserva_historia_sin_acreditar_cobertura(): void
    {
        $exp = $this->expediente();
        $this->completo($exp);
        $a = $this->antecedente($exp, 'otro');
        $this->revisionExp($exp)->assertUnprocessable();
        $this->revisar($a, 'observado');
        $this->revisionExp($exp)->assertUnprocessable();
        $this->revisar($a, 'descartado');
        $this->revisionExp($exp)->assertOk();
        $this->assertNotNull($a->fresh());
        $f301 = AntecedenteContratista::query()->where('tipo', 'f301')->firstOrFail();
        $this->revisar($f301, 'descartado');
        $this->revisionExp($exp)->assertUnprocessable();
        $this->assertSame(2, EventoRrhh::query()->where('registro_id', $a->id)->where('accion', 'revisado')->count());
    }

    public function test_f301_valida_periodos_y_cobertura_sin_mezclar_honorarios_o_nomina_propia(): void
    {
        $honorario = $this->persona('HON01', 'honorarios');
        $exp = $this->expediente();
        $this->postJson('/api/rrhh/contratistas/expedientes', [...$this->datosExp(), 'faena' => 'OTRA', 'personal' => [$honorario->id]])->assertUnprocessable()->assertJsonValidationErrors('personal');
        $this->postJson('/api/rrhh/contratistas/expedientes', $this->datosExp())->assertUnprocessable()->assertJsonValidationErrors('faena');
        $d = $this->datosAntecedente($exp, 'f301');
        foreach ([['desde' => '2026-06-01'], ['desde' => '2026-09-02'], ['hasta' => '2026-10-31']] as $cambio) {
            $this->postJson($this->url($exp).'/antecedentes', [...$d, ...$cambio])->assertUnprocessable()->assertJsonValidationErrors('hasta');
        }
        $this->postJson($this->url($exp).'/antecedentes', [...$d, 'cubre_todos' => false, 'personas' => [$honorario->id]])->assertUnprocessable()->assertJsonValidationErrors('personas');
        $this->postJson($this->url($exp).'/antecedentes', [...$d, 'desde' => '2026-07-01'])->assertCreated();
        $this->getJson('/api/rrhh/contratistas/dotacion?empleador_id='.$this->empresa->id.'&mes=2026-09')->assertOk()->assertJsonCount(1, 'data.data');
        $this->empresa->update(['tipo' => 'propio']);
        $this->postJson('/api/rrhh/contratistas/expedientes', [...$this->datosExp(), 'faena' => 'PROPIA'])->assertUnprocessable()->assertJsonValidationErrors('empleador_id');
    }

    public function test_est_exige_registro_y_mes_abierto_no_puede_declararse_conforme(): void
    {
        $this->empresa->update(['tipo' => 'est']);
        $exp = $this->expediente();
        $this->completo($exp);
        $this->revisionExp($exp)->assertUnprocessable();
        $this->revisar($this->antecedente($exp, 'registro_est'));
        $this->revisionExp($exp)->assertOk();
        $abierto = $this->expediente(['mes' => '2026-10']);
        $this->revisionExp($abierto)->assertUnprocessable();
    }

    public function test_retenciones_son_idempotentes_respetan_saldo_y_conservan_destinatario_sin_crear_nomina(): void
    {
        $exp = $this->expediente();
        $url = $this->url($exp).'/retenciones';
        $i = Institucion::query()->create(['id' => (string) Str::uuid(), 'codigo' => 'DESTINO', 'rut' => '76000001-9', 'razon_social' => 'Institución verificada', 'tipo' => 'afp', 'desde' => '2020-01-01', 'hasta' => '9999-12-31', 'verificado_en' => '2026-10-21', 'respaldo' => 'Registro institucional verificado']);
        $ret = $this->postJson($url, $this->anotacion('retencion', '100000'))->assertCreated()->json('data.id');
        $lib = $this->postJson($url, $this->anotacion('liberacion', '30000'))->assertCreated()->json('data.id');
        $d = [...$this->anotacion('pago_subrogacion', '20000'), 'institucion_id' => $i->id];
        $id = $this->postJson($url, $d)->assertCreated()->json('data.id');
        $this->postJson($url, $d)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($url, [...$d, 'monto' => '21000'])->assertUnprocessable()->assertJsonValidationErrors('operacion_id');
        $this->postJson($url, $this->anotacion('liberacion', '60000'))->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->postJson($url, [...$this->anotacion('pago_subrogacion', '1'), 'institucion_id' => $i->id, 'persona_destino_id' => $this->persona->id])->assertUnprocessable()->assertJsonValidationErrors('destinatario');
        $this->getJson($url)->assertOk()->assertJsonPath('saldo_retenido', '50000');
        $anular = fn ($id) => $this->patchJson('/api/rrhh/contratistas/retenciones/'.$id.'/anular', ['motivo' => 'Rectificación documentada del comprobante']);
        $anular($ret)->assertUnprocessable();
        $i->update(['razon_social' => 'Nombre posterior']);
        $this->assertSame('Institución verificada', RetencionContratista::query()->findOrFail($id)->destinatario['razon_social']);
        $anular($id)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('saldo_retenido', '70000');
        $anular($lib)->assertOk();
        $anular($ret)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('saldo_retenido', '0');
        $this->assertSame(3, RetencionContratista::query()->count());
        $this->assertSame(0, MovimientoNomina::query()->count());
        $this->assertSame(0, EntregaNomina::query()->count());
    }

    public function test_permisos_salariales_protegen_personal_documentos_y_anotaciones(): void
    {
        $exp = $this->expediente();
        $perfil = PerfilAcceso::query()->create(['codigo' => 'CONTRATISTA_TEST', 'nombre' => 'Consulta RRHH', 'rol_base' => RolUsuario::Consulta, 'modulos' => ['rrhh.personal'], 'modulos_tablet' => [], 'activo' => true]);
        $u = User::factory()->create(['rol' => RolUsuario::Consulta, 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($u, 'sanctum')->getJson($this->url($exp))->assertForbidden();
        $this->getJson($this->url($exp).'/antecedentes')->assertForbidden();
        $this->getJson('/api/rrhh/contratistas/dotacion?empleador_id='.$this->empresa->id.'&mes=2026-09')->assertForbidden();
        $perfil->update(['modulos' => ['rrhh.personal', 'rrhh.remuneraciones']]);
        $this->actingAs($u->fresh(), 'sanctum')->getJson($this->url($exp))->assertOk();
        $this->postJson($this->url($exp).'/retenciones', $this->anotacion('retencion', '100'))->assertForbidden();
        $this->revisionExp($exp)->assertForbidden();
    }

    private function persona(string $codigo, string $tipo = 'indefinido'): Persona
    {
        $p = Persona::query()->create(['id' => (string) Str::uuid(), 'codigo' => $codigo, 'nombre' => 'Persona '.$codigo, 'fuente_marcaje' => 'folios']);
        ContratoLaboral::query()->create(['id' => (string) Str::uuid(), 'persona_id' => $p->id, 'empleador_id' => $this->empresa->id, 'empleador' => $this->empresa->toArray(), 'referencia' => $codigo, 'tipo' => $tipo, 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'respaldo' => 'Contrato de la empresa externa firmado']);

        return $p;
    }

    private function datosExp(): array
    {
        return ['empleador_id' => $this->empresa->id, 'faena' => 'PLANTA_1', 'mes' => '2026-09', 'referencia_contrato' => 'Contrato comercial de servicios 2026', 'personal' => [$this->persona->id]];
    }

    private function expediente(array $cambios = []): ExpedienteContratista
    {
        return ExpedienteContratista::query()->findOrFail($this->postJson('/api/rrhh/contratistas/expedientes', [...$this->datosExp(), ...$cambios])->assertCreated()->json('data.id'));
    }

    private function url(ExpedienteContratista $exp): string
    {
        return '/api/rrhh/contratistas/expedientes/'.$exp->id;
    }

    private function datosAntecedente(ExpedienteContratista $exp, string $tipo): array
    {
        return ['version_expediente' => $exp->fresh()->version, 'tipo' => $tipo, 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'fecha_documento' => '2026-10-10', 'referencia' => 'RESPALDO-'.$tipo, 'cubre_todos' => true];
    }

    private function antecedente(ExpedienteContratista $exp, string $tipo): AntecedenteContratista
    {
        return AntecedenteContratista::query()->findOrFail($this->postJson($this->url($exp).'/antecedentes', $this->datosAntecedente($exp, $tipo))->assertCreated()->json('data.id'));
    }

    private function revisar(AntecedenteContratista $a, string $estado = 'conforme'): void
    {
        $this->patchJson('/api/rrhh/contratistas/antecedentes/'.$a->id.'/revision', ['version' => $a->fresh()->version, 'estado' => $estado, 'observacion' => 'Contenido y cobertura revisados contra la fuente'])->assertOk();
    }

    private function revisionExp(ExpedienteContratista $exp): TestResponse
    {
        return $this->patchJson($this->url($exp).'/revision', ['version' => $exp->fresh()->version, 'estado' => 'conforme', 'observacion' => 'Revisión interna del cumplimiento concluida']);
    }

    private function completo(ExpedienteContratista $exp): void
    {
        foreach (['f301', 'remuneraciones', 'pago_remuneraciones', 'cotizaciones'] as $tipo) {
            AntecedenteContratista::query()->create(['id' => (string) Str::uuid(), 'expediente_id' => $exp->id, 'tipo' => $tipo, 'desde' => '2026-09-01', 'hasta' => '2026-09-30', 'fecha_documento' => '2026-10-10', 'referencia' => 'PRUEBA-'.$tipo, 'personas' => [$this->persona->id], 'estado' => 'conforme', 'revisado_por' => 'Revisor de prueba', 'revisado_at' => now()]);
        }
    }

    private function anotacion(string $tipo, string $monto): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'tipo' => $tipo, 'fecha' => '2026-10-10', 'monto' => $monto, 'referencia' => 'DOC-'.Str::uuid(), 'respaldo' => 'Comprobante privado revisado por administración'];
    }
}
