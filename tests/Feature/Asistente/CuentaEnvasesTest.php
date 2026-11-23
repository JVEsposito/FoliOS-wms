<?php

namespace Tests\Feature\Asistente;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\RecepcionRomana;
use App\Services\Asistente\Consultas\CuentaEnvases;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Tema «Cuenta de envases»: las mismas cifras que la oficina de Cuenta Envases. */
class CuentaEnvasesTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_saldos_suma_la_cuenta_por_cliente_y_envase(): void
    {
        $this->movimiento('ajuste_cuenta', 'bins', 10, 1);
        $this->movimiento('ajuste_cuenta', 'bins', 4, -1);
        $this->movimiento('ajuste_cuenta', 'totes', 3, -1);

        $resultado = $this->vista('saldos');

        $this->assertSame([
            ['cliente' => 'Exportadora Andina', 'envase' => 'Bins plástico', 'saldo' => 6, 'quien' => 'Planta debe'],
            ['cliente' => 'Exportadora Andina', 'envase' => 'Tote', 'saldo' => -3, 'quien' => 'Cliente debe'],
        ], $resultado->filas);
    }

    public function test_saldos_no_suma_compras_de_propios(): void
    {
        $this->movimiento('recepcion_compra', 'bins', 50, 0);

        $this->assertSame(ResultadoConsulta::SIN_DATOS, $this->vista('saldos')->estado);
    }

    public function test_la_oficina_y_el_asistente_dan_el_mismo_saldo(): void
    {
        $this->movimiento('ajuste_cuenta', 'bins', 10, 1);
        $this->movimiento('ajuste_cuenta', 'bins', 4, -1);

        $oficina = $this->actingAs($this->administrador, 'sanctum')->getJson('/api/envases/cuenta-corriente/movimientos')->assertOk()->json('balances');

        $this->assertSame([6], array_column($oficina, 'saldo'));
        $this->assertSame([6], array_column($this->vista('saldos')->filas, 'saldo'));
    }

    public function test_estado_cuenta_coincide_con_el_documento(): void
    {
        $this->movimiento('ajuste_cuenta', 'bins', 10, 1);
        $this->movimiento('ajuste_cuenta', 'bins', 4, -1);
        $documento = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->cliente, $this->temporada, null, null);

        $resultado = $this->vista('estado-cuenta', ['cliente_id' => $this->cliente->id]);

        $this->assertSame(array_map(fn (array $t): array => [
            'envase' => $t['nombre'], 'inicial' => $t['saldo_inicial'], 'entradas' => $t['entradas'], 'salidas' => $t['salidas'], 'final' => $t['saldo_final'],
        ], $documento['tipos']), $resultado->filas);
        $this->assertSame(6, $resultado->filas[0]['final']);
    }

    public function test_estado_cuenta_sin_cliente_pide_el_cliente(): void
    {
        $this->movimiento('ajuste_cuenta', 'bins', 10, 1);

        $resultado = $this->vista('estado-cuenta');

        $this->assertSame(ResultadoConsulta::SIN_DATOS, $resultado->estado);
        $this->assertSame('Dime de qué cliente: el estado de cuenta es por cliente.', $resultado->resumen);
    }

    public function test_por_productor_con_csg_busca_el_cliente(): void
    {
        $csg = $this->csgDe([$this->cliente]);
        $this->devolucion($this->cliente, 8);

        $resultado = $this->vista('por-productor', ['csg_id' => $csg->id]);

        $this->assertSame([['cliente' => 'Exportadora Andina', 'csg' => '104512', 'envase' => 'Bins plástico', 'saldo' => 8]], $resultado->filas);
    }

    public function test_por_productor_no_suma_clientes_distintos(): void
    {
        $otro = Cliente::create(['codigo' => 'OTR', 'nombre' => 'Frutícola Sur', 'activo' => true]);
        $csg = $this->csgDe([$this->cliente, $otro]);
        $this->devolucion($this->cliente, 8);
        $this->devolucion($otro, 5);

        $filas = $this->vista('por-productor', ['csg_id' => $csg->id])->filas;

        $this->assertEqualsCanonicalizing([['Exportadora Andina', 8], ['Frutícola Sur', 5]], array_map(fn (array $f): array => [$f['cliente'], $f['saldo']], $filas));
    }

    public function test_descuadres_lista_la_doble_salida_con_su_diferencia(): void
    {
        $this->dobleSalida(romana: 10, despacho: 12);

        $resultado = $this->vista('descuadres');

        $this->assertSame([['despacho' => 'GDE-DOBLE-1', 'recepcion' => 'REC-DOBLE-1', 'cliente' => 'Exportadora Andina',
            'envase' => 'Bins plástico · arrendada', 'romana' => 10, 'guia' => 12, 'diferencia' => 2, 'nota' => null]], $resultado->filas);
    }

    public function test_descuadres_exige_ajustar_la_cuenta(): void
    {
        $supervisor = $this->usuarioAsistente(RolUsuario::SupervisorMateriales, [CatalogoModulosAcceso::OFICINA_ASISTENTE, 'materia-prima.cuenta-envases']);
        $this->dobleSalida(romana: 10, despacho: 12);

        $this->actingAs($supervisor, 'sanctum')->postJson('/api/asistente/consultas/cuenta-envases', ['vista' => 'descuadres'])->assertForbidden();
        $vistas = collect($this->getJson('/api/asistente/consultas')->assertOk()->json('data'))->firstWhere('clave', 'cuenta-envases')['vistas'];
        $this->assertNotContains('descuadres', array_column($vistas, 'clave'));
    }

    public function test_la_oficina_lista_las_mismas_parejas(): void
    {
        $this->dobleSalida(romana: 10, despacho: 12);

        $oficina = $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/envases/cuenta-corriente/conciliaciones-salida?temporada_id='.$this->temporada->id)->assertOk()->json('data');

        $this->assertSame(array_column($oficina, 'despacho'), array_column($this->vista('descuadres')->filas, 'despacho'));
    }

    public function test_una_pareja_que_requiere_revision_individual_no_rompe_la_respuesta(): void
    {
        $this->dobleSalida(romana: 10, despacho: 12, conOrigen: false);

        $filas = $this->vista('descuadres')->filas;

        $this->assertSame('Requiere revisión individual', $filas[0]['nota']);
        $this->assertNull($filas[0]['diferencia']);
    }

    /** Una salida física registrada dos veces: en el destare de Romana y en una guía de despacho de envases. */
    private function dobleSalida(int $romana, int $despacho, bool $conOrigen = true): void
    {
        $recepcion = RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'recepcion'), 'numero_recepcion' => 'REC-DOBLE-1',
            'temporada_id' => $this->temporada->id, 'temporada_codigo_snapshot' => $this->temporada->codigo, 'temporada_nombre_snapshot' => $this->temporada->nombre,
            'cliente_id' => $this->cliente->id, 'cliente_codigo_snapshot' => $this->cliente->codigo, 'cliente_nombre_snapshot' => $this->cliente->nombre,
            'tipo_recepcion' => 'fruta_con_envases', 'tipo_servicio' => 'proceso', 'cantidad_envases_declarados' => 100, 'tipo_envase_declarado' => 'bins',
            'numero_guia_despacho' => '11893', 'numero_guia_salida' => '002.236', 'modo_salida_envases' => 'diferentes',
            'tipo_envase_calculo_neto' => 'bins', 'peso_tara_envases' => 0, 'peso_bruto' => 20000, 'peso_tara' => 8000, 'peso_neto' => 12000,
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo', 'rut_conductor' => '12345678-5', 'nombre_conductor' => 'Conductor de prueba',
            'estado' => 'cerrado', 'estado_validacion_mp' => 'validada', 'ingreso_at' => now()->subHours(2), 'salida_at' => now()->subHour(),
            'creado_por_user_id' => $this->administrador->id,
        ]);
        $guia = GuiaDespachoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'numero' => 'GDE-DOBLE-1', 'numero_guia_despacho' => '2236',
            'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id, 'estado' => 'confirmada',
            'salida_at' => now()->subHour(), 'creado_por_user_id' => $this->administrador->id,
        ]);
        $origen = $this->movimiento('recepcion_arriendo', 'bins', 1000, 1, extra: ['propiedad' => 'arrendada']);
        $this->movimiento('despacho_cliente', 'bins', $despacho, -1, extra: ['propiedad' => 'arrendada', 'documento_tipo' => 'guia_despacho_envases',
            'documento_id' => $guia->id, 'movimiento_origen_id' => $conOrigen ? $origen->id : null]);
        $salida = $this->movimiento('salida_recepcion_fruta', 'bins', $romana, -1, extra: ['propiedad' => 'arrendada', 'recepcion_romana_id' => $recepcion->id,
            'documento_tipo' => 'recepcion_romana', 'documento_id' => $recepcion->id, 'numero_documento' => '002.236']);
        $recepcion->salidasEnvases()->create(['tipo_envase' => 'bins', 'cantidad' => $romana, 'tara_unitaria' => 1, 'movimiento_envase_id' => $salida->id]);
    }

    /** @param  list<Cliente>  $clientes */
    private function csgDe(array $clientes): CsgValidacion
    {
        $productor = ProductorCsg::create(['codigo' => '104512', 'razon_social' => 'Agrícola Prueba', 'predio' => 'Fundo de prueba', 'estado_sag' => 'activo',
            'tipo_codigo' => 'CSG', 'especies' => [], 'fuente_url' => 'https://example.test/sag', 'primera_verificacion_at' => now(),
            'ultima_verificacion_at' => now(), 'ultima_consulta_user_id' => $this->administrador->id, 'respuesta_hash' => str_repeat('a', 64)]);
        foreach ($clientes as $cliente) {
            DB::table('clientes_productores_csg')->insert(['id' => (string) Str::uuid(), 'cliente_id' => $cliente->id, 'productor_csg_id' => $productor->id,
                'activo' => true, 'asociado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return CsgValidacion::create(['temporada_id' => $this->temporada->id, 'codigo' => '104512', 'productor_csg_id' => $productor->id, 'activo' => true]);
    }

    private function devolucion(Cliente $cliente, int $cantidad): void
    {
        $this->movimiento('devolucion_envases', 'bins', $cantidad, 1, $cliente, [
            'documento_tipo' => 'devolucion_envases', 'datos' => ['devolucion' => ['productor' => ['codigo' => '104512', 'nombre' => 'Agrícola Prueba']]],
        ]);
    }

    private function vista(string $vista, array $datos = []): ResultadoConsulta
    {
        return app(CuentaEnvases::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));
    }

    /** @param  array<string, mixed>  $extra */
    private function movimiento(string $tipo, string $envase, int $cantidad, int $signoCuenta, ?Cliente $cliente = null, array $extra = []): MovimientoEnvase
    {
        return MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => ($cliente ?? $this->cliente)->id,
            'documento_tipo' => 'ajuste_cuenta', 'documento_id' => (string) Str::uuid(), 'numero_documento' => 'AJ-'.Str::random(4),
            'tipo_movimiento' => $tipo, 'tipo_envase' => $envase, 'cantidad' => $cantidad, 'signo_cuenta' => $signoCuenta,
            'signo_existencia' => $signoCuenta === 0 ? 1 : $signoCuenta, 'propiedad' => $signoCuenta === 0 ? 'propia' : 'cliente',
            'ocurrido_at' => now(), 'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->administrador->id, ...$extra,
        ]);
    }
}
