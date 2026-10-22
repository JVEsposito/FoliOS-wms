<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Cierre\DiferenciasCierre;
use App\Finanzas\Cierre\GestorCierres;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Prefactura\LibroPrefactura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConceptosTransversalesTest extends TestCase
{
    use HechosDePrueba, PreparaOperacionFinanzas, RefreshDatabase;

    private string $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->administrador, 'sanctum');
        $this->cliente = $this->clienteGlobal('CLIENTE SERVICIOS');
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
    }

    public function test_simula_sin_escribir_y_rechaza_unidades_dimensiones_y_fuentes_incompatibles(): void
    {
        $this->recepcionRomana($this->cliente, 'R1', 1000, '2026-11-01 10:00', ['tipo_servicio' => 'proceso']);
        $this->capturar(['romana_recepcion']);
        $antes = Hecho::count();
        $this->postJson('/api/finanzas/servicios/simular', ['regla' => $this->regla(false), 'vigente_desde' => '2026-11-01'])
            ->assertOk()->assertJsonPath('data.muestra.0.cantidad', '1000')->assertJsonPath('data.muestra.0.completo', true);
        $this->assertSame($antes, Hecho::count());
        $regla = $this->regla();
        $regla['terminos'][1]['medida'] = 'envases';
        $this->postJson('/api/finanzas/servicios', $this->concepto($regla))->assertUnprocessable()->assertJsonValidationErrors('regla');
        $regla = $this->regla();
        $regla['agrupar'] = ['cliente_secreto'];
        $this->postJson('/api/finanzas/servicios', $this->concepto($regla))->assertUnprocessable();
        $regla['terminos'][0]['fuente'] = 'calculado';
        $this->postJson('/api/finanzas/servicios', $this->concepto($regla))->assertUnprocessable();
    }

    public function test_un_solo_cobro_con_aportes_versionados_reintentos_y_cierre_congelado(): void
    {
        $this->recepcionRomana($this->cliente, 'R1', 10000, '2026-11-01 10:00', ['tipo_servicio' => 'proceso']);
        $resta = $this->recepcionRomana($this->cliente, 'R2', 1500, '2026-11-01 11:00', ['tipo_servicio' => 'almacenaje']);
        $clave = $this->postJson('/api/finanzas/servicios', $this->concepto($this->regla()))->assertCreated()->assertJsonPath('data.area', 'servicios')->json('data.clave');
        $this->precio(null, $clave, 'cantidad', '2', '2026-11-01');
        $this->capturar(['romana_recepcion']);
        $hecho = Hecho::query()->vigentes()->where('fuente', 'calculado')->sole();
        $this->assertSame('8500', $hecho->medidas['cantidad']);
        $this->assertCount(2, $hecho->datos['aportes']);
        $this->capturar(['calculado']);
        $this->assertSame(1, Hecho::where('fuente', 'calculado')->count());
        $resultado = app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'));
        $this->assertCount(1, $resultado['clientes'][0]['lineas']);
        $this->assertSame('17000', $resultado['totales'][0]['monto']);
        $cierre = app(GestorCierres::class)->cerrar('2026-11', true, $this->administrador);
        DB::table('recepciones_romana')->where('id', $resta)->update(['peso_neto' => 2000, 'updated_at' => now()]);
        $this->capturar(['romana_recepcion']);
        $this->assertSame(2, Hecho::where('fuente', 'calculado')->count());
        $this->assertSame('16000', app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'))['totales'][0]['monto']);
        $this->assertSame('17000', app(LibroPrefactura::class)->mes(Periodo::mes('2026-11'))['totales'][0]['monto']);
        $this->assertNotEmpty(app(DiferenciasCierre::class)->calcular($cierre)['diferencias']);
        DB::table('recepciones_romana')->where('id', $resta)->update(['estado' => 'en_bascula_salida', 'updated_at' => now()]);
        $this->capturar(['romana_recepcion']);
        $this->assertSame(Hecho::ESTADO_ANULADO, Hecho::query()->vigentes()->where('fuente', 'calculado')->sole()->estado);
    }

    public function test_abonos_por_precio_o_cantidad_negativa_y_saldo_total_a_favor(): void
    {
        $clave = $this->postJson('/api/finanzas/servicios', ['nombre' => 'Abono comercial', 'area' => 'envases', 'fuente' => 'declarado', 'aplicacion' => 'declarado', 'unidad_libre' => 'bin', 'vigente_desde' => '2026-11-01', 'dia_desde' => 1])->assertCreated()->json('data.clave');
        $this->postJson('/api/finanzas/tarifas', ['servicio' => $clave, 'unidad' => 'cantidad', 'moneda' => 'CLP', 'precio' => '-2,50', 'vigente_desde' => '2026-11-01'])->assertCreated()->assertJsonPath('data.precio', '-2.5');
        foreach (['10', '-2'] as $cantidad) {
            $id = $this->postJson('/api/finanzas/cargos', ['operacion_id' => (string) Str::uuid(), 'servicio' => $clave, 'cliente_id' => $this->cliente, 'fecha' => '2026-11-05', 'cantidad' => $cantidad, 'base_cobro' => 'acuerdo', 'estado_ejecucion' => 'no_aplica', 'respaldo' => 'Acuerdo comercial de envases'])->assertCreated()->json('data.id');
            $this->postJson("/api/finanzas/cargos/{$id}/aprobar")->assertOk();
        }
        $resultado = app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'));
        $this->assertSame('8', $resultado['clientes'][0]['lineas'][0]['cantidad']);
        $this->assertSame('-20', $resultado['totales'][0]['monto']);
        app(GestorCierres::class)->cerrar('2026-11', false, $this->administrador);
        $this->assertSame('-20', app(LibroPrefactura::class)->mes(Periodo::mes('2026-11'))['totales'][0]['monto']);
    }

    private function concepto(array $regla): array
    {
        return ['nombre' => 'Servicio calculado', 'area' => 'servicios', 'fuente' => 'calculado', 'aplicacion' => 'calculado', 'unidad_libre' => 'kg', 'regla' => $regla, 'vigente_desde' => '2026-11-01', 'dia_desde' => 1];
    }

    private function regla(bool $exigir = true): array
    {
        return ['agrupar' => ['fecha_operacional'], 'exigir_todos' => $exigir, 'terminos' => [
            ['fuente' => 'romana_recepcion', 'medida' => 'kilos_netos', 'factor' => '1', 'filtros' => ['tipo_servicio' => 'proceso']],
            ['fuente' => 'romana_recepcion', 'medida' => 'kilos_netos', 'factor' => '-1', 'filtros' => ['tipo_servicio' => 'almacenaje']],
        ]];
    }
}
