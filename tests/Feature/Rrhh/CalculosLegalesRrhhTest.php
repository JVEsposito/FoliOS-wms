<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Rrhh\Models\Afiliacion;
use App\Rrhh\Models\CalculoLegal;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\IndiceNomina;
use App\Rrhh\Models\Institucion;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalculosLegalesRrhhTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    private Persona $persona;

    private ContratoLaboral $contrato;

    private Institucion $afp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->persona = Persona::query()->create(['id' => (string) Str::uuid(), 'codigo' => 'LEGAL-1', 'nombre' => 'Persona de prueba', 'fuente_marcaje' => 'folios']);
        $e = Empleador::query()->create(['id' => (string) Str::uuid(), 'codigo' => 'EMP', 'rut' => '76000010-8', 'razon_social' => 'Empleador de prueba', 'tipo' => 'propio', 'desde' => '2020-01-01', 'hasta' => '9999-12-31', 'verificado_en' => '2026-10-21', 'respaldo' => 'Registro del empleador verificado']);
        $this->contrato = ContratoLaboral::query()->create(['id' => (string) Str::uuid(), 'persona_id' => $this->persona->id, 'empleador_id' => $e->id, 'empleador' => $e->toArray(), 'tipo' => 'indefinido', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'referencia' => 'CONTRATO-01', 'respaldo' => 'Contrato y régimen general revisados']);
        foreach (['afp' => ['76000001-9', 'afp'], 'salud' => ['76000002-7', 'fonasa'], 'accidentes' => ['76000003-5', 'mutualidad']] as $regimen => [$rut, $tipo]) {
            $i = Institucion::query()->create(['id' => (string) Str::uuid(), 'codigo' => strtoupper($tipo), 'rut' => $rut, 'razon_social' => 'Institución de prueba '.$tipo, 'tipo' => $tipo, 'desde' => '2020-01-01', 'hasta' => '9999-12-31', 'activo' => true, 'verificado_en' => '2026-10-21', 'respaldo' => 'Datos institucionales verificados']);
            Afiliacion::query()->create(['id' => (string) Str::uuid(), $regimen === 'accidentes' ? 'empleador_id' : 'persona_id' => $regimen === 'accidentes' ? $e->id : $this->persona->id, 'institucion_id' => $i->id, 'institucion' => $i->toArray(), 'regimen' => $regimen, 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'referencia' => 'CERT-'.$tipo, 'respaldo' => 'Certificado de afiliación revisado']);
            if ($regimen === 'afp') {
                $this->afp = $i;
            }
        }
    }

    public function test_indices_versionados_conservan_uf_utm_y_comision_de_cada_calculo(): void
    {
        $p = $this->indice();
        $entrada = $this->entrada($p);
        $id = $this->postJson('/api/rrhh/nomina/legal/calculos', $entrada)->assertCreated()->assertJsonPath('data.calculo.totales.descuento', '190400')->json('data.id');
        $this->postJson('/api/rrhh/nomina/legal/indices', $this->datosIndice())->assertUnprocessable()->assertJsonValidationErrors('version_anterior');
        $this->postJson('/api/rrhh/nomina/legal/indices', [...$this->datosIndice(), 'version_anterior' => 1, 'uf' => '41000', 'comisiones' => [['institucion_id' => $this->afp->id, 'tasa' => '1.45']]])->assertCreated()->assertJsonPath('data.version', 2);
        $guardado = CalculoLegal::query()->findOrFail($id);
        $this->assertSame('40000.000000', $guardado->calculo['parametros']['uf']);
        $this->assertSame('1.44', $guardado->calculo['parametros']['comisiones'][$this->afp->id]['tasa']);
        $this->assertSame(2, IndiceNomina::query()->count());
        $this->getJson('/api/rrhh/nomina/legal/indices?mes=2026-10')->assertOk()->assertJsonPath('data.data.0.version', 2);
    }

    public function test_preparacion_es_idempotente_y_no_aprueba_descuentos_ni_genera_entregas(): void
    {
        $d = $this->entrada($this->indice());
        $this->postJson('/api/rrhh/nomina/legal/simular', $d)->assertOk()->assertJsonPath('data.preparacion', true)->assertJsonPath('data.totales.aporte_empleador', '68300');
        $this->assertSame(0, CalculoLegal::query()->count());
        $id = $this->postJson('/api/rrhh/nomina/legal/calculos', $d)->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/nomina/legal/calculos', $d)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/rrhh/nomina/legal/calculos', [...$d, 'respaldo' => 'Otra base revisada para la preparación'])->assertUnprocessable()->assertJsonValidationErrors('operacion_id');
        $this->assertSame(1, CalculoLegal::query()->count());
        $this->assertSame(0, MovimientoNomina::query()->count());
        $this->assertSame(0, EntregaNomina::query()->count());
        $this->getJson('/api/rrhh/nomina/legal/calculos?persona_id='.$this->persona->id.'&mes=2026-10')->assertOk()->assertJsonPath('data.data.0.id', $id);
    }

    public function test_cotizaciones_bloquean_meses_parciales_y_comisiones_faltantes_sin_inventar_parametros(): void
    {
        $p = $this->postJson('/api/rrhh/nomina/legal/indices', [...$this->datosIndice(), 'comisiones' => []])->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/nomina/legal/simular', $this->entrada($p))->assertUnprocessable()->assertJsonValidationErrors('parametro_id');
        $this->contrato->update(['hasta' => '2026-10-15']);
        $this->postJson('/api/rrhh/nomina/legal/simular', $this->entrada($p))->assertUnprocessable()->assertJsonValidationErrors('contrato_id');
        $this->postJson('/api/rrhh/nomina/legal/indices', [...$this->datosIndice(), 'version_anterior' => 1, 'fecha_uf' => '2026-10-21'])->assertUnprocessable()->assertJsonValidationErrors('fecha_uf');
    }

    public function test_calculos_anuales_requieren_anticipos_del_ejercicio_y_marcan_proyeccion_abierta(): void
    {
        $d = ['persona_id' => $this->persona->id, 'tipo' => 'anual50', 'mes' => '2026-12', 'respaldo' => 'Bases, reajustes e ingreso mínimo revisados', 'datos' => ['remuneraciones_computables' => '12800000', 'imm_diciembre' => '553553', 'meses_computables' => '12', 'anticipos' => [['mes' => '2026-10', 'monto' => '200000', 'factor_reajuste' => '1.01']]]];
        $this->postJson('/api/rrhh/nomina/legal/simular', $d)->assertOk()->assertJsonPath('data.ejercicio_abierto', true)->assertJsonPath('data.detalle.anticipos.0.actualizado', '202000');
        $d['datos']['anticipos'][0]['mes'] = '2025-10';
        $this->postJson('/api/rrhh/nomina/legal/simular', $d)->assertUnprocessable()->assertJsonValidationErrors('anticipos');
    }

    public function test_apv_conserva_regimen_a_o_b_en_afiliacion_mandato_y_entrega_sin_rebaja_automatica(): void
    {
        $d = ['persona_id' => $this->persona->id, 'institucion_id' => $this->afp->id, 'regimen' => 'apv', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'referencia' => 'APV-A', 'respaldo' => 'Elección de régimen APV respaldada'];
        $this->postJson('/api/rrhh/prevision/afiliaciones', $d)->assertUnprocessable()->assertJsonValidationErrors('regimen_apv');
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$d, 'regimen_apv' => 'A'])->assertCreated();
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$d, 'referencia' => 'APV-B', 'regimen_apv' => 'B'])->assertCreated();
        $this->postJson('/api/rrhh/prevision/afiliaciones', [...$d, 'regimen_apv' => 'A'])->assertUnprocessable();
        $c = $this->postJson('/api/rrhh/nomina/conceptos', ['codigo' => 'APV_B', 'nombre' => 'APV régimen B', 'tipo' => 'descuento', 'calculo' => 'monto', 'grupo_descuento' => 'apv', 'institucion_id' => $this->afp->id, 'remuneracion' => false, 'imponible' => false, 'tributable' => false, 'base_he' => false, 'fundamento' => 'Contrato APV con elección de régimen B', 'activo' => true])->assertCreated()->json('data.id');
        $m = $this->postJson('/api/rrhh/nomina/mandatos', ['persona_id' => $this->persona->id, 'empleador_id' => $this->contrato->empleador_id, 'concepto_id' => $c, 'institucion_id' => $this->afp->id, 'referencia' => 'APV-B-1', 'desde' => '2026-10-01', 'hasta' => '2026-12-31', 'cuota' => '10000', 'pago_directo_institucion' => false, 'regimen_apv' => 'B', 'respaldo' => 'Contrato APV y mandato firmados'])->assertCreated()->json('data.id');
        $id = $this->postJson('/api/rrhh/nomina/movimientos', ['operacion_id' => (string) Str::uuid(), 'persona_id' => $this->persona->id, 'concepto_id' => $c, 'mandato_id' => $m, 'fecha' => '2026-10-01', 'respaldo' => 'Ahorro del mes revisado con respaldo'])->assertCreated()->assertJsonPath('data.calculo.regimen_apv', 'B')->json('data.id');
        $this->patchJson('/api/rrhh/nomina/movimientos/'.$id, ['estado' => 'aprobado', 'motivo' => 'Descuento APV revisado y autorizado'])->assertOk();
        $this->assertSame('B', EntregaNomina::query()->where('movimiento_id', $id)->firstOrFail()->contenido['regimen_apv']);
    }

    private function datosIndice(): array
    {
        return ['mes' => '2026-10', 'uf' => '40000', 'utm' => '72151', 'fecha_uf' => '2026-10-31', 'version_anterior' => 0, 'comisiones' => [['institucion_id' => $this->afp->id, 'tasa' => '1.44']], 'respaldo' => 'UF y UTM de prueba, comisión respaldada por SP'];
    }

    private function indice(): string
    {
        return $this->postJson('/api/rrhh/nomina/legal/indices', $this->datosIndice())->assertCreated()->json('data.id');
    }

    private function entrada(string $p): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'persona_id' => $this->persona->id, 'contrato_id' => $this->contrato->id, 'parametro_id' => $p, 'tipo' => 'cotizaciones', 'mes' => '2026-10', 'respaldo' => 'Base completa revisada sin licencias ni exenciones', 'datos' => ['base_imponible' => '1000000', 'adicional_accidentes' => '0', 'cic_completa' => false, 'caso_general' => true]];
    }
}
