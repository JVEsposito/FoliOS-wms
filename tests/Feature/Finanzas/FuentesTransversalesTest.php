<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FuentesTransversalesTest extends TestCase
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
        $this->cliente = $this->clienteGlobal('CLIENTE SERVICIOS');
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
    }

    public function test_packing_compensa_un_bin_multiorigen_sin_mezclar_clientes_y_mantiene_el_mes_del_proceso(): void
    {
        $otro = $this->clienteGlobal('OTRO CLIENTE');
        $lote = $this->loteServicio($this->cliente, 'L1');
        $loteOtro = $this->loteServicio($otro, 'L2');
        $this->entregaServicio($lote, 'ORD-1', '10000');
        $this->entregaServicio($loteOtro, 'ORD-1', '5000');
        $bin = $this->binServicio([['lote' => $lote, 'orden' => 'ORD-1', 'kilos' => 1500], ['lote' => $loteOtro, 'orden' => 'ORD-1', 'kilos' => 500]]);
        $clave = $this->crearConcepto(['agrupar' => ['proceso_id'], 'exigir_todos' => true, 'terminos' => [
            ['fuente' => 'packing_entrega', 'medida' => 'kilos', 'factor' => '1', 'filtros' => []], ['fuente' => 'packing_retorno', 'medida' => 'kilos', 'factor' => '-1', 'filtros' => []],
        ]]);
        $this->precio(null, $clave, 'cantidad', '1', '2026-11-01');
        $lectura = $this->capturar(['packing_entrega']);
        $this->assertSame('completada', $lectura->estado, $lectura->error ?? '');
        $resultados = Hecho::query()->vigentes()->where('fuente', 'calculado')->get()->keyBy('cliente_id');
        $this->assertSame('8500', $resultados[$this->cliente]->medidas['cantidad']);
        $this->assertSame('4500', $resultados[$otro]->medidas['cantidad']);
        $this->assertSame('2026-11-01', $resultados[$this->cliente]->fecha_operacional->toDateString());
        $this->assertSame('13000', app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'))['totales'][0]['monto']);
        $this->assertSame([], app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-12'))['totales']);
        DB::table('bins_retorno_packing')->where('id', $bin)->update(['estado' => 'anulado', 'updated_at' => now()]);
        $this->capturar(['packing_retorno']);
        $this->assertSame(2, Hecho::query()->vigentes()->where('fuente', 'calculado')->where('estado', Hecho::ESTADO_ANULADO)->count());
    }

    public function test_una_apertura_de_envases_negativa_y_su_reversa_se_leen_con_signo_sin_alterar_operacion(): void
    {
        $datos = ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporadaFinanzas(), 'cliente_id' => $this->cliente,
            'tipo_movimiento' => 'apertura_cuenta', 'tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => -30,
            'fecha' => '2026-11-01', 'referencia' => 'APERTURA-ARRIENDO', 'motivo' => 'Saldo de apertura convenido con el cliente'];
        $id = $this->postJson('/api/envases/cuenta-corriente/ajustes', $datos)->assertCreated()->json('data.id');
        $this->assertSame('completada', $this->capturar(['envases_movimiento'])->estado);
        $hecho = $this->hechos('envases_movimiento')->sole();
        $this->assertSame(-30, $hecho->medidas['efecto_cuenta']);
        $this->assertSame('apertura_cuenta', $hecho->datos['tipo']);
        $this->postJson('/api/envases/cuenta-corriente/movimientos/'.$id.'/revertir-ajuste', [
            'operacion_id' => (string) Str::uuid(), 'fecha' => '2026-11-02', 'motivo' => 'Reversa del saldo conciliado inicialmente',
        ])->assertCreated();
        $antes = DB::table('movimientos_envases')->orderBy('id')->get()->toJson();
        $this->capturar(['envases_movimiento'], true);
        $this->assertSame(0, $this->hechos('envases_movimiento')->sum(fn (Hecho $h) => $h->medidas['efecto_cuenta']));
        $this->assertSame($antes, DB::table('movimientos_envases')->orderBy('id')->get()->toJson());
    }

    public function test_receta_se_valoriza_por_produccion_real_y_conserva_version_unidad_y_anulacion(): void
    {
        $receta = $this->recetaServicio($this->cliente);
        $regla = ['agrupar' => ['origen_id'], 'exigir_todos' => true, 'terminos' => [['fuente' => 'material_transformacion', 'medida' => 'unidades', 'factor' => '1', 'filtros' => ['receta_id' => $receta['receta'], 'unidad_medida' => 'caja']]]];
        $clave = $this->crearConcepto($regla);
        $this->precio(null, $clave, 'cantidad', '3', '2026-11-01');
        $this->getJson('/api/finanzas/servicios/referencias?fuente=material_transformacion&campo=receta_id')->assertOk()->assertJsonPath('data.0.nombre', 'Caja armada');
        $lectura = $this->capturar(['material_transformacion']);
        $this->assertSame('completada', $lectura->estado, $lectura->error ?? '');
        $this->assertSame('360', app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'))['totales'][0]['monto']);
        $hecho = Hecho::query()->vigentes()->where('fuente', 'material_transformacion')->sole();
        $this->assertSame($receta['version'], $hecho->datos['version_receta_id']);
        DB::table('recetas_materiales')->where('id', $receta['receta'])->update(['activa' => false]);
        $this->capturar(['material_transformacion'], true);
        $this->assertSame(1, Hecho::where('fuente', 'material_transformacion')->count());
        DB::table('lotes_transformacion_materiales')->where('id', $receta['lote'])->update(['estado' => 'anulado', 'updated_at' => now()]);
        $this->capturar(['material_transformacion']);
        $this->assertSame([], app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'))['totales']);
    }

    public function test_catalogo_transversal_y_unidades_variables_exigen_filtro_y_no_exponen_tablas_arbitrarias(): void
    {
        $catalogo = $this->getJson('/api/finanzas/tarifas')->assertOk()->json();
        $fuentes = array_column($catalogo['procesos'], 'area', 'clave');
        $this->assertSame('materiales', $fuentes['material_inventario']);
        $this->assertSame('envases', $fuentes['envases_movimiento']);
        $this->assertSame('logistica', $fuentes['planificador_paso']);
        $this->assertSame('rrhh', $fuentes['rrhh_programacion']);
        $this->assertSame('comex', $fuentes['comex_reserva']);
        $servicio = collect($catalogo['servicios'])->firstWhere('clave', 'material_transformacion');
        $this->assertSame(['lotes'], array_column($servicio['unidades'], 'clave'));
        $regla = ['agrupar' => ['origen_id'], 'exigir_todos' => true, 'terminos' => [['fuente' => 'material_transformacion', 'medida' => 'unidades', 'factor' => '1', 'filtros' => []]]];
        $this->postJson('/api/finanzas/servicios', $this->definicion($regla))->assertUnprocessable()->assertJsonValidationErrors('regla');
        $this->getJson('/api/finanzas/servicios/referencias?fuente=users&campo=password')->assertUnprocessable();
        $lectura = $this->capturar();
        $this->assertSame('completada', $lectura->estado, $lectura->error ?? '');
    }

    private function crearConcepto(array $regla): string
    {
        return $this->postJson('/api/finanzas/servicios', $this->definicion($regla))->assertCreated()->json('data.clave');
    }

    private function definicion(array $regla): array
    {
        return ['nombre' => 'Servicio transversal', 'area' => 'servicios', 'fuente' => 'calculado', 'aplicacion' => 'calculado', 'unidad_libre' => 'unidad calculada', 'regla' => $regla, 'vigente_desde' => '2026-11-01', 'dia_desde' => 1];
    }
}
