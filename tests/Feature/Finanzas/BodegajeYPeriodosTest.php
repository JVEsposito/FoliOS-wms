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

class BodegajeYPeriodosTest extends TestCase
{
    use HechosDePrueba, PreparaOperacionFinanzas, PreparaServiciosTransversales, RefreshDatabase;

    private string $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->administrador, 'sanctum');
        $this->cliente = $this->clienteGlobal('CLIENTE BODEGAJE');
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
    }

    public function test_bodegaje_usa_las_cantidades_de_cada_dia_y_filtros_sin_multiplicar_retiros_parciales(): void
    {
        $m = $this->materialServicio($this->cliente);
        $this->kardexServicio($m, '2026-11-01 10:00', '0', '120');
        $this->kardexServicio($m, '2026-11-03 12:00', '120', '80');
        $this->kardexServicio($m, '2026-11-05 00:00', '80', '0');
        $concepto = $this->concepto('material_bodegaje', ['unidad_medida' => 'caja']);
        $this->precio(null, $concepto, 'unidades', '2', '2026-11-01');
        $this->precio(null, 'material_bodegaje', 'folios', '10', '2026-11-01');
        $lectura = $this->capturar(['material_bodegaje']);
        $this->assertSame('completada', $lectura->estado, $lectura->error ?? '');
        $resultado = $this->calcular();
        $lineas = collect($resultado['clientes'][0]['lineas'])->keyBy('servicio');
        $this->assertSame('440', $lineas[$concepto]['cantidad']);
        $this->assertSame('4', $lineas['material_bodegaje']['cantidad']);
        $this->assertSame('920', $resultado['totales'][0]['monto']);
        $this->capturar(['material_bodegaje'], true);
        $this->assertSame(1, Hecho::where('fuente', 'material_bodegaje')->count());
        DB::table('folios_materiales')->where('folio_id', $m['folio'])->update(['cantidad_actual' => 7, 'updated_at' => now()]);
        $this->assertSame('con_errores', $this->capturar(['material_bodegaje'])->estado);
        $this->assertSame('920', $this->calcular()['totales'][0]['monto']);
    }

    public function test_un_perfil_de_solo_consulta_no_crea_acuerdos_y_los_filtros_son_inmutables(): void
    {
        $a = $this->concepto('material_bodegaje', ['unidad_medida' => 'caja'], 'reemplaza_base');
        $b = $this->concepto('material_bodegaje', ['unidad_medida' => 'kg'], 'reemplaza_base');
        $this->assertNotSame($a, $b);
        $this->postJson('/api/finanzas/servicios', $this->definicion('material_bodegaje', ['unidad_medida' => 'caja'], 'reemplaza_base'))->assertUnprocessable();
        $this->postJson('/api/finanzas/servicios', $this->definicion('material_bodegaje', ['campo_inexistente' => 'x']))->assertUnprocessable();
        $this->putJson('/api/finanzas/servicios/'.$a, ['filtros' => ['unidad_medida' => 'kg']])->assertNotFound();
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['finanzas.prefactura']), 'sanctum');
        $this->postJson('/api/finanzas/cargos', [])->assertForbidden();
    }

    public function test_un_mes_sin_existencia_entre_un_retiro_y_una_devolucion_no_genera_cobro_ni_pendiente(): void
    {
        $m = $this->materialServicio($this->cliente, '80');
        $this->kardexServicio($m, '2026-10-01 10:00', '0', '120');
        $this->kardexServicio($m, '2026-10-03 12:00', '120', '0');
        $this->kardexServicio($m, '2026-12-01 10:00', '0', '80');
        $this->assertSame('completada', $this->capturar(['material_bodegaje'])->estado);
        $resultado = $this->calcular();
        $this->assertSame([], $resultado['totales']);
        $this->assertSame(0, $resultado['resumen']['hechos_sin_precio']);
    }

    public function test_arriendo_por_periodo_cruza_meses_y_el_termino_anticipado_conserva_el_cierre(): void
    {
        $servicio = $this->concepto('periodico');
        $this->precio(null, $servicio, 'cantidad', '2', '2026-11-01');
        $datos = ['operacion_id' => (string) Str::uuid(), 'servicio' => $servicio, 'cliente_id' => $this->cliente, 'fecha' => '2026-11-25', 'fecha_hasta' => '2026-12-10', 'cantidad' => '30', 'base_cobro' => 'disponibilidad', 'estado_ejecucion' => 'no_realizado', 'respaldo' => 'Arriendo contratado de 30 bins por día'];
        $id = $this->postJson('/api/finanzas/cargos', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/finanzas/cargos', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame([], $this->calcular()['totales']);
        $this->postJson('/api/finanzas/cargos/'.$id.'/aprobar')->assertOk();
        $this->assertSame('360', $this->calcular()['totales'][0]['monto']);
        $this->assertSame('300', $this->calcular('2026-12')['totales'][0]['monto']);
        $this->getJson('/api/finanzas/cargos?mes=2026-12')->assertJsonCount(1, 'data.data');
        $cierre = app(GestorCierres::class)->cerrar('2026-11', false, $this->administrador);
        $this->postJson('/api/finanzas/cargos/'.$id.'/finalizar-periodo', ['fecha_hasta' => '2026-11-28', 'motivo' => 'Devolución anticipada acordada'])->assertOk();
        $this->assertSame('240', $this->calcular()['totales'][0]['monto']);
        $this->assertSame('360', app(LibroPrefactura::class)->mes(Periodo::mes('2026-11'))['totales'][0]['monto']);
        $this->assertNotEmpty(app(DiferenciasCierre::class)->calcular($cierre)['diferencias']);
        $this->assertSame([], $this->calcular('2026-12')['totales']);
        $this->postJson('/api/finanzas/cargos/'.$id.'/finalizar-periodo', ['fecha_hasta' => '2026-12-01', 'motivo' => 'No puede extender un término'])->assertUnprocessable();
    }

    private function concepto(string $fuente, array $filtros = [], string $aplicacion = 'adicional'): string
    {
        return $this->postJson('/api/finanzas/servicios', $this->definicion($fuente, $filtros, $aplicacion))->assertCreated()->json('data.clave');
    }

    public function test_el_acuerdo_cobra_dias_civiles_con_cambio_de_hora_y_admite_un_abono_diario(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00', 'America/Santiago'));
        $servicio = $this->postJson('/api/finanzas/servicios', [...$this->definicion('periodico', []), 'vigente_desde' => '2026-09-01'])->assertCreated()->json('data.clave');
        $this->precio(null, $servicio, 'cantidad', '1', '2026-09-01');
        $datos = ['operacion_id' => (string) Str::uuid(), 'servicio' => $servicio, 'cliente_id' => $this->cliente, 'fecha' => '2026-09-05', 'fecha_hasta' => '2026-09-08', 'cantidad' => '-30', 'base_cobro' => 'acuerdo', 'estado_ejecucion' => 'no_aplica', 'respaldo' => 'Bonificación pactada por día civil'];
        $id = $this->postJson('/api/finanzas/cargos', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/finanzas/cargos/'.$id.'/aprobar')->assertOk();
        $this->assertSame('-120', $this->calcular('2026-09')['totales'][0]['monto']);
        $this->postJson('/api/finanzas/cargos', [...$datos, 'operacion_id' => (string) Str::uuid(), 'fecha_hasta' => null])->assertUnprocessable()->assertJsonValidationErrors('fecha_hasta');
    }

    private function definicion(string $fuente, array $filtros, string $aplicacion = 'adicional'): array
    {
        return ['nombre' => 'Servicio de bodegaje o arriendo', 'area' => 'servicios', 'fuente' => $fuente, 'aplicacion' => $fuente === 'periodico' ? 'declarado' : $aplicacion, 'unidad_libre' => 'bin', 'filtros' => $filtros, 'vigente_desde' => '2026-11-01', 'dia_desde' => 1];
    }

    private function calcular(string $mes = '2026-11'): array
    {
        return app(CalculadoraPrefactura::class)->calcular(Periodo::mes($mes));
    }
}
