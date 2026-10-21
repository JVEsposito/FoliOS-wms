<?php

namespace Tests\Feature\Rrhh;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Rrhh\Models\EntregaNomina;
use App\Rrhh\Models\MandatoDescuento;
use App\Rrhh\Models\Persona;
use App\Rrhh\Nomina\DestinosNomina;
use App\Rrhh\Nomina\ReglasChile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DescuentosInstitucionTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'rrhh'];

    private string $persona;

    private string $institucion;

    private string $concepto;

    private string $empleador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->persona = (string) Str::uuid();
        Persona::query()->create(['id' => $this->persona, 'codigo' => 'DES01', 'nombre' => 'Persona de prueba', 'fuente_marcaje' => 'folios']);
        $this->empleador = $this->postJson('/api/rrhh/prevision/directorio/empleador', ['codigo' => 'EMP', 'rut' => '76000010-8', 'razon_social' => 'Empleador propio', 'tipo' => 'propio', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'activo' => true, 'verificado_en' => '2026-10-21', 'respaldo' => 'Identidad del empleador revisada'])->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/prevision/contratos', ['persona_id' => $this->persona, 'empleador_id' => $this->empleador, 'referencia' => 'CONTRATO-01', 'tipo' => 'indefinido', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'respaldo' => 'Contrato laboral firmado y revisado'])->assertCreated();
        $this->institucion = $this->postJson('/api/rrhh/prevision/directorio/institucion', ['codigo' => 'COOP', 'rut' => '76000001-9', 'razon_social' => 'Cooperativa de prueba', 'tipo' => 'cooperativa', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'activo' => true, 'verificado_en' => '2026-10-21', 'respaldo' => 'Identidad y códigos verificados'])->assertCreated()->json('data.id');
        $this->concepto = $this->nuevoConcepto();
    }

    public function test_mandato_no_descuenta_hasta_aprobar_y_no_duplica_cuota_mensual_ni_reintento(): void
    {
        $m = $this->mandato();
        $entrada = $this->entrada($m);
        $id = $this->postJson('/api/rrhh/nomina/movimientos', $entrada)->assertCreated()->assertJsonPath('data.total', '20000')->json('data.id');
        $this->postJson('/api/rrhh/nomina/movimientos', $entrada)->assertCreated()->assertJsonPath('data.id', $id);
        $this->saldo($m, '45000', 0);
        $this->aprobar($id)->assertOk();
        $this->saldo($m, '25000', 1);
        $otro = $this->postJson('/api/rrhh/nomina/movimientos', $this->entrada($m))->assertCreated()->json('data.id');
        $this->aprobar($otro)->assertUnprocessable()->assertJsonValidationErrors('mandato_id');
        $this->getJson('/api/rrhh/nomina/por-institucion?institucion_id='.$this->institucion.'&mes=2026-10')->assertOk()->assertJsonPath('totales_aprobados.descuento', '20000')->assertJsonPath('data.data.0.persona_nombre', 'Persona de prueba');
        $entrega = EntregaNomina::query()->where('movimiento_id', $id)->firstOrFail();
        $this->assertSame('folios.rrhh.novedad.v2', $entrega->contenido['contrato']);
        $this->assertSame('76000001-9', $entrega->contenido['institucion']['rut']);
        $this->assertSame($m, $entrega->contenido['mandato_id']);
    }

    public function test_ultima_cuota_se_ajusta_a_la_deuda_y_anular_restituye_saldo_sin_borrar_historia(): void
    {
        $m = $this->mandato();
        foreach (['2026-10-01' => '20000', '2026-11-01' => '20000', '2026-12-01' => '5000'] as $fecha => $total) {
            $id = $this->postJson('/api/rrhh/nomina/movimientos', $this->entrada($m, $fecha))->assertCreated()->assertJsonPath('data.total', $total)->json('data.id');
            $this->aprobar($id)->assertOk();
        }
        $this->saldo($m, '0', 3);
        $this->postJson('/api/rrhh/nomina/movimientos', $this->entrada($m, '2027-01-01'))->assertUnprocessable()->assertJsonValidationErrors('mandato_id');
        $this->patchJson('/api/rrhh/nomina/movimientos/'.$id, ['estado' => 'anulado', 'motivo' => 'Rectificación de la cuota final'])->assertOk();
        $this->saldo($m, '5000', 2);
        $this->assertSame(2, EntregaNomina::query()->where('movimiento_id', $id)->count());
        $reemplazo = $this->postJson('/api/rrhh/nomina/movimientos', $this->entrada($m, '2026-12-01'))->assertCreated()->assertJsonPath('data.total', '5000')->json('data.id');
        $this->aprobar($reemplazo)->assertOk();
        $this->saldo($m, '0', 3);
    }

    public function test_mandato_valida_persona_institucion_regimen_y_cobertura_de_cuotas(): void
    {
        $d = $this->datosMandato();
        $this->postJson('/api/rrhh/nomina/mandatos', [...$d, 'cuotas' => 2])->assertUnprocessable();
        $this->postJson('/api/rrhh/nomina/mandatos', [...$d, 'deuda_total' => null, 'cuotas' => 3])->assertUnprocessable();
        $m = $this->mandato();
        $otra = (string) Str::uuid();
        Persona::query()->create(['id' => $otra, 'codigo' => 'OTRA', 'nombre' => 'Otra persona', 'fuente_marcaje' => 'folios']);
        $this->postJson('/api/rrhh/nomina/simular', [...$this->entrada($m), 'persona_id' => $otra])->assertUnprocessable()->assertJsonValidationErrors('mandato_id');
        $this->postJson('/api/rrhh/nomina/mandatos', $d)->assertUnprocessable()->assertJsonValidationErrors('referencia');
        $this->postJson('/api/rrhh/nomina/mandatos', [...$d, 'referencia' => 'EXCEPCION', 'excepcion_ccaf' => 'salud'])->assertUnprocessable();
    }

    public function test_porcentaje_usa_base_documentada_y_no_admite_deuda_fija(): void
    {
        $c = $this->nuevoConcepto(['codigo' => 'PORCENTAJE', 'calculo' => 'porcentaje']);
        $d = [...$this->datosMandato(), 'referencia' => 'AUT-PORC', 'concepto_id' => $c, 'cuota' => '5', 'base_porcentaje' => 'remuneracion_bruta'];
        $this->postJson('/api/rrhh/nomina/mandatos', $d)->assertUnprocessable();
        unset($d['deuda_total'], $d['cuotas']);
        $m = $this->postJson('/api/rrhh/nomina/mandatos', $d)->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/nomina/simular', [...$this->entrada($m), 'concepto_id' => $c])->assertOk()->assertJsonPath('data.total', '50000');
        $sinBase = [...$this->entrada($m), 'concepto_id' => $c];
        unset($sinBase['remuneracion_bruta']);
        $this->postJson('/api/rrhh/nomina/simular', $sinBase)->assertUnprocessable()->assertJsonValidationErrors('remuneracion_bruta');
    }

    public function test_contratista_y_honorarios_no_se_convierten_en_descuentos_de_nomina_propia(): void
    {
        $e = $this->postJson('/api/rrhh/prevision/directorio/empleador', ['codigo' => 'CONTRA', 'rut' => '76000003-5', 'razon_social' => 'Contratista de prueba', 'tipo' => 'contratista', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'activo' => true, 'verificado_en' => '2026-10-21', 'respaldo' => 'Contratista real identificado'])->assertCreated()->json('data.id');
        $c = $this->postJson('/api/rrhh/prevision/contratos', ['persona_id' => $this->persona, 'empleador_id' => $e, 'referencia' => 'EXTERNO', 'tipo' => 'plazo_fijo', 'desde' => '2026-10-01', 'hasta' => '2026-12-31', 'respaldo' => 'Contrato de la empresa contratista'])->assertCreated()->json('data.id');
        $this->postJson('/api/rrhh/nomina/mandatos', [...$this->datosMandato(), 'empleador_id' => $e])->assertUnprocessable()->assertJsonValidationErrors('empleador_id');
        $this->postJson('/api/rrhh/nomina/simular', ['persona_id' => $this->persona, 'concepto_id' => $this->concepto, 'contrato_id' => $c, 'fecha' => '2026-10-01', 'cantidad' => '1', 'valor' => '1000', 'respaldo' => 'Intento de usar contrato ajeno'])->assertUnprocessable()->assertJsonValidationErrors('contrato_id');
    }

    public function test_ccaf_acumula_por_sistema_y_aplica_tramos_sin_usar_el_tope_voluntario(): void
    {
        $i = $this->postJson('/api/rrhh/prevision/directorio/institucion', ['codigo' => 'CCAF', 'rut' => '76000002-7', 'razon_social' => 'CCAF de prueba', 'tipo' => 'ccaf', 'desde' => '2026-01-01', 'hasta' => '9999-12-31', 'activo' => true, 'verificado_en' => '2026-10-21', 'respaldo' => 'Identidad y códigos verificados'])->assertCreated()->json('data.id');
        $c = $this->nuevoConcepto(['codigo' => 'CREDITO_CCAF', 'grupo_descuento' => 'ccaf', 'institucion_id' => $i]);
        $d = [...$this->datosMandato(), 'concepto_id' => $c, 'institucion_id' => $i, 'cuota' => '80000'];
        unset($d['deuda_total'], $d['cuotas']);
        $m = $this->postJson('/api/rrhh/nomina/mandatos', $d)->assertCreated()->json('data.id');
        $entrada = [...$this->entrada($m), 'concepto_id' => $c, 'liquido_legal' => '400000'];
        $id = $this->postJson('/api/rrhh/nomina/movimientos', $entrada)->assertCreated()->json('data.id');
        $this->aprobar($id)->assertOk();
        $m2 = $this->postJson('/api/rrhh/nomina/mandatos', [...$d, 'referencia' => 'SEGUNDO', 'cuota' => '10000'])->assertCreated()->json('data.id');
        $id2 = $this->postJson('/api/rrhh/nomina/movimientos', [...$entrada, 'operacion_id' => (string) Str::uuid(), 'mandato_id' => $m2])->assertCreated()->json('data.id');
        $this->aprobar($id2)->assertUnprocessable()->assertJsonValidationErrors('total');
        $r = app(ReglasChile::class);
        $this->assertSame(15, $r->topeCcaf('2026-10-01', '356815', true));
        $this->assertSame(20, $r->topeCcaf('2026-10-01', '553552', true));
        $this->assertSame(25, $r->topeCcaf('2026-10-01', '553553', false));
        $this->assertSame(25, $r->topeCcaf('2026-10-01', '553553', true));
        $this->assertSame(35, $r->topeCcaf('2026-10-02', '553553', true));
    }

    private function nuevoConcepto(array $cambios = []): string
    {
        return $this->postJson('/api/rrhh/nomina/conceptos', [...['codigo' => 'COOP', 'nombre' => 'Cuota cooperativa', 'tipo' => 'descuento', 'calculo' => 'monto', 'remuneracion' => false, 'imponible' => false, 'tributable' => false, 'base_he' => false, 'grupo_descuento' => 'cooperativa', 'institucion_id' => $this->institucion, 'fundamento' => 'Artículo 58 y autorización escrita', 'activo' => true], ...$cambios])->assertCreated()->json('data.id');
    }

    private function datosMandato(): array
    {
        return ['persona_id' => $this->persona, 'empleador_id' => $this->empleador, 'concepto_id' => $this->concepto, 'institucion_id' => $this->institucion, 'referencia' => 'CREDITO-01', 'desde' => '2026-10-01', 'hasta' => '2027-12-31', 'cuota' => '20000', 'deuda_total' => '45000', 'cuotas' => 3, 'pago_directo_institucion' => false, 'respaldo' => 'Autorización y contrato revisados'];
    }

    private function mandato(): string
    {
        return $this->postJson('/api/rrhh/nomina/mandatos', $this->datosMandato())->assertCreated()->json('data.id');
    }

    private function entrada(string $m, string $fecha = '2026-10-01'): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'persona_id' => $this->persona, 'concepto_id' => $this->concepto, 'mandato_id' => $m, 'fecha' => $fecha, 'remuneracion_bruta' => '1000000', 'respaldo' => 'Liquidación mensual y autorización revisadas'];
    }

    private function aprobar(string $id)
    {
        return $this->patchJson('/api/rrhh/nomina/movimientos/'.$id, ['estado' => 'aprobado', 'motivo' => 'Cuota revisada con la liquidación']);
    }

    private function saldo(string $id, string $saldo, int $cuotas): void
    {
        $actual = app(DestinosNomina::class)->saldo(MandatoDescuento::query()->findOrFail($id));
        $this->assertSame($saldo, $actual['saldo_por_descontar']);
        $this->assertSame($cuotas, $actual['cuotas_descontadas']);
    }
}
