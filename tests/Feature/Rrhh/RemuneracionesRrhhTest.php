<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Rrhh\Models\EventoRrhh;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use App\Rrhh\Nomina\ReglasChile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RemuneracionesRrhhTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    private string $persona;

    private string $cargo;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->admin, 'sanctum');
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $this->persona = (string) Str::uuid();
        Persona::query()->create(['id' => $this->persona, 'codigo' => 'RH01', 'nombre' => 'Trabajador prueba', 'fuente_marcaje' => 'folios']);
        $this->cargo = $this->postJson('/api/rrhh/cargos', ['codigo' => 'CAMARERO', 'nombre' => 'Camarero', 'activo' => true])->assertCreated()->json('data.id');
    }

    public function test_cargos_versionados_y_condiciones_sucesivas_sin_reescribir_historia(): void
    {
        $this->condicion();
        $this->putJson('/api/rrhh/cargos/'.$this->cargo, ['codigo' => 'CAMARERO', 'nombre' => 'Operador de cámaras', 'activo' => true, 'version' => '1'])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/rrhh/nomina/personas/'.$this->persona)->assertOk()->assertJsonPath('data.condiciones.data.0.cargo_nombre', 'Camarero');
        $this->putJson('/api/rrhh/cargos/'.$this->cargo, ['codigo' => 'CAMARERO', 'nombre' => 'Cambio obsoleto', 'activo' => true, 'version' => 1])->assertUnprocessable();
        $this->postJson('/api/rrhh/nomina/condiciones', $this->datosCondicion())->assertUnprocessable()->assertJsonValidationErrors('desde');
        $this->postJson('/api/rrhh/nomina/condiciones', $this->datosCondicion(['desde' => '2027-01-01', 'hasta' => '2028-05-01']))->assertUnprocessable()->assertJsonValidationErrors('horas_semanales');
    }

    public function test_conceptos_configurables_con_tratamiento_y_barrera_de_permisos_salariales(): void
    {
        $concepto = $this->concepto(['codigo' => 'BONO', 'nombre' => 'Bono nocturno', 'base_he' => true]);
        $this->getJson('/api/rrhh/nomina/conceptos')->assertOk()->assertJsonPath('data.data.0.base_he', true);
        $this->concepto(['codigo' => 'COLACION', 'nombre' => 'Asignación de colación', 'remuneracion' => false, 'imponible' => false, 'tributable' => false]);
        $this->postJson('/api/rrhh/nomina/conceptos', [...$this->datosConcepto(), 'codigo' => 'ERROR', 'tipo' => 'descuento', 'grupo_descuento' => 'voluntario'])->assertUnprocessable();
        $this->actingAs($this->perfil(['rrhh.personal']), 'sanctum');
        $this->getJson('/api/rrhh/cargos')->assertOk();
        $this->getJson('/api/rrhh/nomina/conceptos')->assertForbidden();
        $this->getJson('/api/rrhh/nomina/personas/'.$this->persona)->assertForbidden();
        $this->getJson('/api/rrhh/nomina/historial?entidad=concepto_nomina&registro_id='.$concepto)->assertForbidden();
        $this->getJson('/api/rrhh/historial?entidad=concepto_nomina&registro_id='.$concepto)->assertUnprocessable();
        $this->actingAs($this->perfil(['rrhh.personal', 'rrhh.remuneraciones'], RolUsuario::Consulta), 'sanctum');
        $this->getJson('/api/rrhh/nomina/conceptos')->assertOk();
        $this->postJson('/api/rrhh/nomina/conceptos', $this->datosConcepto())->assertForbidden();
    }

    public function test_horas_extra_con_sueldo_fijo_adicional_minutos_y_precision_decimal(): void
    {
        $this->condicion();
        $extra = $this->concepto(['codigo' => 'HE', 'calculo' => 'horas_extra']);
        $bono = $this->concepto(['codigo' => 'CARGO', 'base_he' => true]);
        $this->pacto($bono, ['valor' => '60000']);
        $d = $this->datosMovimiento($extra, ['minutos' => 120]);
        $sim = $this->postJson('/api/rrhh/nomina/simular', $d)->assertOk()->assertJsonPath('data.base_he', '860000')->assertJsonPath('data.total', '14333');
        $this->assertStringStartsWith('7166.666666', $sim->json('data.valor_hora_extra'));
        $this->postJson('/api/rrhh/nomina/simular', [...$d, 'minutos' => 1])->assertOk()->assertJsonPath('data.total', '119');
        $this->postJson('/api/rrhh/nomina/simular', [...$d, 'recargo' => '49.99'])->assertUnprocessable();
        $this->postJson('/api/rrhh/nomina/simular', [...$d, 'pacto_hasta' => '2027-02-01'])->assertUnprocessable();
    }

    public function test_piso_imm_por_vigencia_y_jornada_parcial_sin_adivinar_2027(): void
    {
        $this->condicion(['sueldo_mensual' => '100000', 'hasta' => '2027-12-31']);
        $extra = $this->concepto(['calculo' => 'horas_extra']);
        $this->postJson('/api/rrhh/nomina/simular', $this->datosMovimiento($extra))->assertOk()->assertJsonPath('data.base_he', '553553')->assertJsonPath('data.total', '4613');
        $this->postJson('/api/rrhh/nomina/simular', $this->datosMovimiento($extra, ['fecha' => '2027-02-01', 'respaldo_he' => 'conocimiento_empleador']))->assertUnprocessable()->assertJsonValidationErrors('fecha');
        $reglas = app(ReglasChile::class);
        $this->assertSame('553553', $reglas->minimo('2026-10-21', 'general', '31')['monto']);
        $this->assertSame('263596.666666666667', $reglas->minimo('2026-10-21', 'general', '20')['monto']);
        $this->assertSame('412938', $reglas->minimo('2026-10-21', 'menor_mayor', '42')['monto']);
        $this->assertSame('539000', $reglas->minimo('2026-04-25', 'general', '44')['monto']);
    }

    public function test_no_oculta_horas_sin_pacto_o_excesos_y_exige_revision_para_aprobar(): void
    {
        $this->condicion();
        $he = $this->concepto(['calculo' => 'horas_extra']);
        $id = $this->crear($he, ['minutos' => 180, 'respaldo_he' => 'conocimiento_empleador']);
        $m = MovimientoNomina::query()->findOrFail($id);
        $this->assertSame('20000', $m->total);
        $this->assertCount(2, $m->calculo['incidencias']);
        $this->patchJson('/api/rrhh/nomina/movimientos/'.$id, ['estado' => 'aprobado', 'motivo' => 'Horas efectivamente trabajadas'])->assertUnprocessable()->assertJsonValidationErrors('acepta_incidencias');
        $this->aprobar($id, ['acepta_incidencias' => true])->assertOk();
        $otro = $this->crear($he, ['minutos' => 30]);
        $this->aprobar($otro)->assertUnprocessable()->assertJsonValidationErrors('acepta_incidencias');
        $this->assertSame(2, MovimientoNomina::query()->count());
    }

    public function test_descuentos_acumulan_topes_mensuales_no_incluyen_obligatorios_y_exigen_autorizacion(): void
    {
        $d = $this->conceptoDescuento('VOL', 'voluntario');
        $a = $this->crear($d, ['cantidad' => '1', 'valor' => '100000', 'remuneracion_bruta' => '1000000', 'autorizacion' => 'Acuerdo escrito firmado por trabajador']);
        $this->aprobar($a)->assertOk();
        $b = $this->crear($d, ['cantidad' => '1', 'valor' => '60000', 'remuneracion_bruta' => '1000000', 'autorizacion' => 'Segundo acuerdo escrito firmado']);
        $this->aprobar($b)->assertUnprocessable()->assertJsonValidationErrors('total');
        $c = $this->crear($d, ['cantidad' => '1', 'valor' => '10000', 'remuneracion_bruta' => '2000000', 'autorizacion' => 'Base distinta del mismo mes']);
        $this->aprobar($c)->assertUnprocessable()->assertJsonValidationErrors('remuneracion_bruta');
        $sin = $this->crear($d, ['cantidad' => '1', 'valor' => '1']);
        $this->aprobar($sin)->assertUnprocessable();
        $ob = $this->conceptoDescuento('COT', 'obligatorio');
        $o = $this->crear($ob, ['cantidad' => '1', 'valor' => '500000', 'autorizacion' => 'Planilla de cotización emitida por remuneraciones']);
        $this->aprobar($o)->assertOk();
        $this->assertCount(0, app(ReglasChile::class)->limites(['voluntario' => '150000', 'vivienda' => '300000'], '1000000'));
        $this->assertNotEmpty(app(ReglasChile::class)->limites(['voluntario' => '150001'], '1000000'));
        $this->assertNotEmpty(app(ReglasChile::class)->limites(['cooperativa' => '200000', 'voluntario' => '100000'], '1000000'));
    }

    public function test_idempotencia_y_cambios_de_antecedentes_no_recalculan_aprobados(): void
    {
        $this->condicion();
        $he = $this->concepto(['calculo' => 'horas_extra']);
        $datos = $this->datosMovimiento($he);
        $id = $this->postJson('/api/rrhh/nomina/movimientos', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/nomina/movimientos', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/rrhh/nomina/movimientos', [...$datos, 'minutos' => 61])->assertUnprocessable();
        $this->aprobar($id)->assertOk();
        $original = MovimientoNomina::query()->findOrFail($id)->calculo;
        $borrador = $this->crear($he);
        $bono = $this->concepto(['codigo' => 'BONO_BASE', 'base_he' => true]);
        $this->pacto($bono, ['valor' => '60000']);
        $this->aprobar($borrador)->assertUnprocessable();
        $this->assertSame($original, MovimientoNomina::query()->findOrFail($id)->calculo);
        $this->assertGreaterThan(0, EventoRrhh::query()->where('registro_id', $id)->where('accion', 'aprobado')->count());
    }

    public function test_asignacion_mensual_porcentaje_y_proteccion_de_doble_pago_en_mes(): void
    {
        $bono = $this->concepto(['calculo' => 'porcentaje']);
        $pacto = $this->pacto($bono, ['cantidad' => '5', 'valor' => '800000']);
        $a = $this->crear($bono, ['pacto_id' => $pacto]);
        $this->assertSame('40000', MovimientoNomina::query()->findOrFail($a)->total);
        $this->aprobar($a)->assertOk();
        $b = $this->crear($bono, ['pacto_id' => $pacto, 'fecha' => '2026-10-25']);
        $this->aprobar($b)->assertUnprocessable()->assertJsonValidationErrors('pacto_id');
        $c = $this->crear($bono, ['pacto_id' => $pacto, 'fecha' => '2026-11-01']);
        $this->aprobar($c)->assertOk();
        $this->patchJson('/api/rrhh/nomina/movimientos/'.$a, ['estado' => 'anulado', 'motivo' => 'Rectificación de novedad con respaldo'])->assertOk();
        $this->aprobar($b)->assertOk();
    }

    public function test_parametros_sucesivos_auditados_y_regimen_especial_sin_formula_general(): void
    {
        $this->postJson('/api/rrhh/nomina/parametros', ['desde' => '2027-01-01', 'hasta' => '2027-12-31', 'imm_general' => 600000, 'imm_menor_mayor' => 450000, 'respaldo' => 'Dato de prueba, no parámetro real'])->assertCreated();
        $this->postJson('/api/rrhh/nomina/parametros', ['desde' => '2026-12-01', 'hasta' => '2027-01-01', 'imm_general' => 1, 'imm_menor_mayor' => 1, 'respaldo' => 'Superposición no permitida'])->assertUnprocessable();
        $this->condicion(['regimen' => 'especial', 'horas_semanales' => '48']);
        $he = $this->concepto(['calculo' => 'horas_extra']);
        $this->postJson('/api/rrhh/nomina/simular', $this->datosMovimiento($he))->assertUnprocessable();
        $this->assertSame(44, ReglasChile::jornadaMaxima('2026-04-25'));
        $this->assertSame(42, ReglasChile::jornadaMaxima('2026-04-26'));
        $this->assertSame(40, ReglasChile::jornadaMaxima('2028-04-26'));
    }

    public function test_acorta_vigencias_para_cambios_futuros_sin_invalidar_movimientos_aprobados(): void
    {
        $this->condicion();
        $c = $this->getJson('/api/rrhh/nomina/personas/'.$this->persona)->json('data.condiciones.data.0.id');
        $he = $this->concepto(['calculo' => 'horas_extra']);
        $id = $this->crear($he);
        $this->aprobar($id)->assertOk();
        $url = '/api/rrhh/nomina/vigencias/condicion/'.$c;
        $this->patchJson($url, ['hasta_actual' => '2026-12-31', 'hasta' => '2026-10-01', 'motivo' => 'Cambio retroactivo no permitido'])->assertUnprocessable();
        $this->patchJson($url, ['hasta_actual' => '2026-12-31', 'hasta' => '2026-10-31', 'motivo' => 'Anexo salarial a contar de noviembre'])->assertOk();
        $this->postJson('/api/rrhh/nomina/condiciones', $this->datosCondicion(['desde' => '2026-11-01', 'sueldo_mensual' => '900000']))->assertCreated();
        $this->assertSame('6667', MovimientoNomina::query()->findOrFail($id)->total);
    }

    private function datosCondicion(array $c = []): array
    {
        return ['persona_id' => $this->persona, 'cargo_id' => $this->cargo, 'desde' => '2026-01-01', 'hasta' => '2026-12-31', 'regimen' => 'general', 'horas_semanales' => '42', 'sueldo_mensual' => '800000', 'tramo_imm' => 'general', 'respaldo' => 'Contrato mensual firmado para jornada general', ...$c];
    }

    private function condicion(array $c = []): void
    {
        $this->postJson('/api/rrhh/nomina/condiciones', $this->datosCondicion($c))->assertCreated();
    }

    private function datosConcepto(array $c = []): array
    {
        return ['codigo' => 'CON_'.Str::upper(Str::random(6)), 'nombre' => 'Concepto de prueba', 'tipo' => 'haber', 'calculo' => 'monto', 'remuneracion' => true, 'imponible' => true, 'tributable' => true, 'base_he' => false, 'activo' => true, 'fundamento' => 'Tratamiento validado por responsable remuneracional', ...$c];
    }

    private function concepto(array $c = []): string
    {
        return $this->postJson('/api/rrhh/nomina/conceptos', $this->datosConcepto($c))->assertCreated()->json('data.id');
    }

    private function conceptoDescuento(string $codigo, string $grupo): string
    {
        return $this->concepto(['codigo' => $codigo, 'tipo' => 'descuento', 'grupo_descuento' => $grupo, 'remuneracion' => false, 'imponible' => false, 'tributable' => false]);
    }

    private function pacto(string $concepto, array $c = []): string
    {
        return $this->postJson('/api/rrhh/nomina/asignaciones', ['persona_id' => $this->persona, 'concepto_id' => $concepto, 'desde' => '2026-01-01', 'hasta' => '2026-12-31', 'cantidad' => '1', 'valor' => '50000', 'respaldo' => 'Anexo de asignación mensual firmado', ...$c])->assertCreated()->json('data.id');
    }

    private function datosMovimiento(string $concepto, array $c = []): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'persona_id' => $this->persona, 'concepto_id' => $concepto, 'fecha' => '2026-10-21', 'minutos' => 60, 'recargo' => '50', 'respaldo_he' => 'pacto', 'pacto_desde' => '2026-10-01', 'pacto_hasta' => '2026-12-31', 'respaldo' => 'Antecedentes revisados de la novedad mensual', ...$c];
    }

    private function crear(string $concepto, array $c = []): string
    {
        return $this->postJson('/api/rrhh/nomina/movimientos', $this->datosMovimiento($concepto, $c))->assertCreated()->json('data.id');
    }

    private function aprobar(string $id, array $c = [])
    {
        return $this->patchJson('/api/rrhh/nomina/movimientos/'.$id, ['estado' => 'aprobado', 'motivo' => 'Revisión documentada de antecedentes', ...$c]);
    }

    private function perfil(array $modulos, RolUsuario $rol = RolUsuario::Administrador): User
    {
        $p = PerfilAcceso::query()->create(['codigo' => 'RN_'.Str::upper(Str::random(6)), 'nombre' => 'Remuneraciones prueba', 'rol_base' => $rol, 'modulos' => $modulos, 'modulos_tablet' => [], 'activo' => true, 'predeterminado' => false, 'protegido' => false]);

        return User::factory()->create(['rol' => $rol, 'perfil_acceso_id' => $p->id, 'activo' => true]);
    }
}
