<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DisponibilidadDespachoEnvasesTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private Cliente $cliente;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporada = Temporada::where('activa', true)->firstOrFail();
        $this->cliente = Cliente::create(['codigo' => 'CLI-STOCK', 'nombre' => 'Cliente con diferencia', 'activo' => true]);
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function movimiento(string $tipo, string $propiedad, int $cantidad, string $movimiento, int $signo, array $extra = []): MovimientoEnvase
    {
        return MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id,
            'cliente_id' => $this->cliente->id, 'documento_tipo' => 'prueba', 'numero_documento' => 'GUIA-PRUEBA',
            'tipo_movimiento' => $movimiento, 'tipo_envase' => $tipo, 'propiedad' => $propiedad,
            'cantidad' => $cantidad, 'signo_existencia' => $signo, 'signo_cuenta' => $signo,
            'ocurrido_at' => now(), 'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->admin->id,
            ...$extra,
        ]);
    }

    private function reservar(array $detalles, ?Cliente $cliente = null)
    {
        return $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(), 'cliente_id' => ($cliente ?? $this->cliente)->id,
            'salida_at' => now()->toAtomString(), 'detalles' => $detalles,
        ]);
    }

    private function linea(string $tipo, string $propiedad, int $cantidad = 1, array $extra = []): array
    {
        return ['tipo_envase' => $tipo, 'propiedad' => $propiedad, 'cantidad' => $cantidad, ...$extra];
    }

    public function test_saldo_positivo_no_oculta_68_esponjas_de_cliente_sin_cobertura_ni_bloquea_las_propias(): void
    {
        $this->movimiento('esponjas', 'propia', 1322, 'recepcion_compra', 1);
        $this->movimiento('esponjas', 'cliente', 68, 'salida_recepcion_fruta', -1);
        $cantidad = MovimientoEnvase::count();
        $catalogo = $this->getJson('/api/envases/guias-despacho/catalogos')->assertOk()
            ->assertJsonCount(1, 'incidencias')->assertJsonPath('incidencias.0.cantidad', 68)
            ->assertJsonPath('incidencias.0.cliente_id', $this->cliente->id)->json();
        $porPropiedad = collect($catalogo['disponibilidad'])->keyBy('propiedad');
        $this->assertSame(-68, $porPropiedad['cliente']['fisico']);
        $this->assertSame(0, $porPropiedad['cliente']['disponible']);
        $this->assertSame(1322, $porPropiedad['propia']['disponible']);
        $this->assertSame(1254, (int) collect($catalogo['inventario'])->sum('fisico'));
        $this->assertContains($this->cliente->id, array_column($catalogo['clientes'], 'id'));
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->existencia($this->temporada->id);
        $this->assertSame(1254, collect($cuenta['tipos'])->firstWhere('tipo_envase', 'esponjas')['en_planta']['total']);
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->reservar([$this->linea('esponjas', 'cliente')])->assertConflict()
            ->assertJsonPath('message', fn ($m) => str_contains($m, '68') && str_contains($m, 'Cliente con diferencia') && str_contains($m, 'Esponja de bins'));
        $this->assertDatabaseCount('guias_despacho_envases', 0);
        $guia = $this->reservar([$this->linea('esponjas', 'propia', 50)])->assertCreated()->json('data');
        $this->getJson('/api/envases/guias-despacho')->assertOk()->assertJsonPath('data.0.id', $guia['id']);
        $this->postJson('/api/envases/guias-despacho/'.$guia['id'].'/confirmar', ['numero_guia_despacho' => '90001'])->assertOk();
    }

    public function test_no_compensa_clientes_ni_propiedades_y_una_solicitud_mixta_falla_sin_reservas_parciales(): void
    {
        $otro = Cliente::create(['codigo' => 'CLI-SANO', 'nombre' => 'Otro titular', 'activo' => true]);
        $this->movimiento('bins', 'cliente', 100, 'recepcion_fruta', 1);
        $this->movimiento('bins', 'cliente', 120, 'salida_recepcion_fruta', -1);
        $this->movimiento('bins', 'cliente', 100, 'recepcion_fruta', 1, ['cliente_id' => $otro->id]);
        $this->movimiento('totes', 'arrendada', 200, 'recepcion_arriendo', 1);
        $this->reservar([$this->linea('totes', 'arrendada', 10), $this->linea('bins', 'cliente')])->assertConflict();
        $this->assertDatabaseCount('detalles_guias_despacho_envases', 0);
        $this->reservar([$this->linea('bins', 'cliente', 100)], $otro)->assertCreated();
        $this->reservar([$this->linea('totes', 'arrendada', 200)])->assertCreated();
        $this->reservar([$this->linea('bins', 'cliente')])->assertConflict();
    }

    public function test_fifo_arrendado_sigue_siendo_compartido_y_el_agotamiento_se_informa(): void
    {
        $otro = Cliente::create(['codigo' => 'ARR', 'nombre' => 'Arrendador', 'activo' => true]);
        $origen = $this->movimiento('bins', 'arrendada', 20, 'recepcion_arriendo', 1, ['cliente_id' => $otro->id]);
        $salida = $this->movimiento('bins', 'arrendada', 20, 'salida_recepcion_fruta', -1);
        $this->getJson('/api/envases/guias-despacho/catalogos')->assertOk()->assertJsonCount(0, 'incidencias')
            ->assertJsonPath('inventario.0.fisico', 0)->assertJsonPath('disponibilidad.0.estado', 'agotado');
        $this->reservar([$this->linea('bins', 'arrendada')])->assertConflict();
        // Una recepción anulada compensa su salida: no debe seguir consumiendo stock.
        $this->movimiento('bins', 'arrendada', 20, 'anulacion_recepcion', 1, ['movimiento_origen_id' => $salida->id]);
        $this->getJson('/api/envases/guias-despacho/catalogos')->assertOk()->assertJsonCount(0, 'incidencias')
            ->assertJsonPath('origenes.0.id', $origen->id)->assertJsonPath('inventario.0.disponible', 20);
        $this->reservar([$this->linea('bins', 'arrendada', 20)])->assertCreated();
        $this->getJson('/api/envases/guias-despacho/catalogos')->assertOk()
            ->assertJsonPath('disponibilidad.0.estado', 'reservado')->assertJsonPath('inventario.0.disponible', 0);
    }

    public function test_confirmacion_revalida_el_grupo_afectado_y_cancelar_libera_su_reserva(): void
    {
        $origen = $this->movimiento('bins', 'cliente', 20, 'recepcion_fruta', 1);
        $guia = $this->reservar([$this->linea('bins', 'cliente', 10)])->assertCreated()->json('data');
        $this->movimiento('bins', 'cliente', 30, 'salida_recepcion_fruta', -1);
        $this->postJson('/api/envases/guias-despacho/'.$guia['id'].'/confirmar', ['numero_guia_despacho' => '90002'])->assertConflict();
        $this->reservar([$this->linea('bins', 'cliente', 1, ['movimiento_origen_id' => $origen->id])])->assertConflict();
        $this->postJson('/api/envases/guias-despacho/'.$guia['id'].'/cancelar', ['motivo' => 'Liberar reserva hasta revisar el saldo'])->assertOk();
        $catalogo = app(ServicioGuiaDespachoEnvases::class)->inventario($this->temporada, incluirAgotados: true);
        $this->assertSame(20, $catalogo['origenes'][0]['disponible_trazable']);
        $this->assertSame(-10, $catalogo['resumen'][0]['fisico']);
        $this->assertSame(0, $catalogo['resumen'][0]['reservado']);
    }

    public function test_compensacion_excesiva_se_aisla_y_no_fabrica_disponibilidad(): void
    {
        $this->movimiento('bins', 'propia', 10, 'recepcion_compra', 1);
        $salida = $this->movimiento('totes', 'arrendada', 5, 'salida_recepcion_fruta', -1);
        $this->movimiento('totes', 'arrendada', 8, 'conciliacion_salida', 1, [
            'movimiento_origen_id' => $salida->id, 'datos' => ['movimiento_conciliado_id' => $salida->id],
        ]);
        $this->getJson('/api/envases/guias-despacho/catalogos')->assertOk()
            ->assertJsonPath('incidencias.0.codigo', 'compensacion_excesiva')->assertJsonPath('incidencias.0.cantidad', 3);
        $this->reservar([$this->linea('bins', 'propia', 10)])->assertCreated();
        $this->reservar([$this->linea('totes', 'arrendada')])->assertConflict();
    }

    public function test_salida_historica_o_saldo_negativo_vinculado_no_bloquea_otro_tipo(): void
    {
        $bins = $this->movimiento('bins', 'propia', 10, 'recepcion_compra', 1);
        $this->movimiento('bins', 'propia', 12, 'despacho_cliente', -1, ['movimiento_origen_id' => $bins->id]);
        $this->movimiento('esponjas', 'propia', 7, 'despacho_cliente', -1);
        $this->movimiento('totes', 'arrendada', 5, 'despacho_cliente', -1);
        $this->movimiento('caja_3_4', 'arrendada', 30, 'recepcion_arriendo', 1);
        $catalogo = $this->getJson('/api/envases/guias-despacho/catalogos')->assertOk()->assertJsonCount(3, 'incidencias')->json();
        $this->assertSame(-2, collect($catalogo['inventario'])->firstWhere('tipo_envase', 'bins')['fisico']);
        $this->assertSame(-7, collect($catalogo['inventario'])->firstWhere('tipo_envase', 'esponjas')['fisico']);
        $this->reservar([$this->linea('bins', 'propia')])->assertConflict();
        $this->reservar([$this->linea('esponjas', 'propia')])->assertConflict();
        $this->reservar([$this->linea('totes', 'arrendada')])->assertConflict();
        $this->reservar([$this->linea('caja_3_4', 'arrendada', 30)])->assertCreated();
    }
}
