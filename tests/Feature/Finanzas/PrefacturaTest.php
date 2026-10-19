<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Reglas de la prefactura en paralelo: qué se cobra, cuántos días y con qué precio. */
class PrefacturaTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        // Noviembre ya cerró: se valoriza completo.
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
        Moneda::query()->create(['codigo' => 'UF', 'nombre' => 'Unidad de fomento', 'simbolo' => 'UF', 'decimales' => 2, 'activa' => true]);
    }

    public function test_cada_hecho_se_valoriza_con_el_precio_de_su_dia_propio_o_general(): void
    {
        $conContrato = $this->clienteGlobal('EXPORTADORA CON CONTRATO');
        $sinContrato = $this->clienteGlobal('EXPORTADORA SIN CONTRATO');
        $this->precio(null, 'romana_recepcion', 'kilos_netos', '12', '2026-11-01');
        $this->precio(null, 'prefrio', 'cajas', '35', '2026-11-01');
        $this->precio(null, 'prefrio', 'cajas', '38', '2026-11-16', cerrarAnterior: true);
        $this->precio($conContrato, 'prefrio', 'cajas', '30', '2026-11-01');

        $this->hecho('romana_recepcion', $conContrato, '2026-11-03 09:00', ['recepciones' => 1, 'kilos_netos' => 8000.5, 'envases' => 40]);
        $this->hecho('romana_recepcion', $conContrato, '2026-10-30 09:00', ['recepciones' => 1, 'kilos_netos' => 5000, 'envases' => 20]);
        $this->hecho('prefrio', $conContrato, '2026-11-05 08:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 50]);
        $this->hecho('prefrio', $sinContrato, '2026-11-10 08:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120]);
        $this->hecho('prefrio', $sinContrato, '2026-11-20 08:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 100]);
        $this->hecho('prefrio', $sinContrato, '2026-11-11 08:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 90], estado: Hecho::ESTADO_ANULADO);

        $prefactura = $this->prefactura();
        $conLineas = $this->cliente($prefactura, 'EXPORTADORA CON CONTRATO');
        $this->assertSame([
            ['romana_recepcion', 'kilos_netos', 'general', '12', '8000.5', '96006'],
            ['prefrio', 'cajas', 'propio', '30', '50', '1500'],
        ], $this->lineas($conLineas));
        $this->assertSame([['moneda' => 'CLP', 'simbolo' => '$', 'decimales' => 0, 'monto' => '97506']], $conLineas['totales']);

        // El cambio de precio a mitad de mes deja una línea por precio; lo anulado no se cobra.
        $this->assertSame([
            ['prefrio', 'cajas', 'general', '35', '120', '4200'],
            ['prefrio', 'cajas', 'general', '38', '100', '3800'],
        ], $this->lineas($this->cliente($prefactura, 'EXPORTADORA SIN CONTRATO')));
        $this->assertSame('105506', $prefactura['totales'][0]['monto']);
        $this->assertSame(['clientes' => 2, 'hechos_valorizados' => 4, 'hechos_sin_precio' => 0, 'hechos_sin_cliente' => 0], $prefactura['resumen']);
    }

    public function test_el_almacenaje_se_cobra_por_dia_o_fraccion_con_lo_que_habia_al_empezar_el_dia(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $this->precio($cliente, 'estadia', 'pallets', '100', '2026-11-01');
        $this->precio($cliente, 'estadia', 'saldos', '60', '2026-11-01');
        $pallet = ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120];
        $saldo = ['bultos' => 1, 'pallets' => 0, 'saldos' => 1, 'cajas' => 80];

        // Del 5 a las 22:00 al 7 a la 01:00: tres días calendario (5, 6 y 7).
        $this->estadia($cliente, 'F1#1', '2026-11-05 22:00', '2026-11-07 01:00', $pallet);
        // Vuelve a entrar el 7: ese día ya lo pagó; solo suma el 8.
        $this->estadia($cliente, 'F1#2', '2026-11-07 10:00', '2026-11-08 09:00', $pallet);
        // Sale justo a medianoche del 12: solo cuenta el 11.
        $this->estadia($cliente, 'F3#1', '2026-11-11 08:00', '2026-11-12 00:00', $pallet);
        // Abierta: pallet hasta el repaletizaje del 20 a las 15:00 y saldo después.
        // El 20 se cobra como pallet (lo que había al empezar el día): 11 días pallet y 10 saldo.
        $this->estadia($cliente, 'F2#1', '2026-11-10 10:00', null, $pallet, tramos: [
            [$pallet, '2026-11-10 10:00', '2026-11-20 15:00'],
            [$saldo, '2026-11-20 15:00', null],
        ]);
        // El folio que nace en ese repaletizaje cobra desde el día siguiente: del 21 al 30.
        $this->estadia($cliente, 'F4#1', '2026-11-20 15:00', null, $pallet, ['repaletizaje_ingreso' => 'RP-0001']);

        $lineas = $this->cliente($this->prefactura(), 'EXPORTADORA DEMO')['lineas'];
        $porUnidad = collect($lineas)->keyBy('unidad');
        // Pallet-días: F1 4 (5, 6, 7 y 8) + F3 1 + F2 11 + F4 10.
        $this->assertSame('26', $porUnidad['pallets']['cantidad']);
        $this->assertSame(26, $porUnidad['pallets']['dias']);
        $this->assertSame('2600', $porUnidad['pallets']['monto']);
        $this->assertSame(5, $porUnidad['pallets']['hechos']);
        $this->assertSame('10', $porUnidad['saldos']['cantidad']);
        $this->assertSame('600', $porUnidad['saldos']['monto']);

        // El detalle muestra, por estadía, los días cobrados en el mes.
        $detalle = collect(collect($this->cliente($this->prefactura(conDetalle: true), 'EXPORTADORA DEMO')['lineas'])
            ->firstWhere('unidad', 'pallets')['detalle'])->firstWhere('desde', '2026-11-10');
        $this->assertSame(['2026-11-20', 11, '11'], [$detalle['hasta'], $detalle['dias'], $detalle['cantidad']]);
    }

    public function test_por_caja_y_dia_y_montos_redondeados_en_su_moneda(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA EN UF');
        $this->precio($cliente, 'estadia', 'cajas', '0.0045', '2026-11-01', moneda: 'UF');
        $this->precio($cliente, 'romana_recepcion', 'kilos_netos', '12.5', '2026-11-01');
        // 111 cajas por 3 días: 333 caja-días × 0,0045 = 1,4985 UF → 1,50.
        $this->estadia($cliente, 'F9#1', '2026-11-01 08:00', '2026-11-03 18:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 111]);
        // 3 kilos × $12,5 = $37,5 → $38: el peso chileno no lleva decimales.
        $this->hecho('romana_recepcion', $cliente, '2026-11-03 09:00', ['recepciones' => 1, 'kilos_netos' => 3, 'envases' => 1]);

        $cliente = $this->cliente($this->prefactura(), 'EXPORTADORA EN UF');
        $this->assertSame([
            ['romana_recepcion', 'kilos_netos', 'propio', '12.5', '3', '38'],
            ['estadia', 'cajas', 'propio', '0.0045', '333', '1.50'],
        ], $this->lineas($cliente));
        $this->assertSame([['CLP', '38'], ['UF', '1.50']], array_map(fn (array $total): array => [$total['moneda'], $total['monto']], $cliente['totales']));
    }

    public function test_sin_cliente_o_sin_precio_queda_pendiente(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $this->precio(null, 'estadia', 'bultos', '90', '2026-11-16');
        $this->hecho('romana_recepcion', null, '2026-11-03 09:00', ['recepciones' => 1, 'kilos_netos' => 1000, 'envases' => 10]);
        $this->hecho('despacho', $cliente, '2026-11-04 09:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120]);
        // La estadía empieza sin precio: se cobran solo los días desde que rige (16 al 20).
        $this->estadia($cliente, 'F5#1', '2026-11-10 08:00', '2026-11-20 08:00', ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120]);

        $prefactura = $this->prefactura();
        $demo = $this->cliente($prefactura, 'EXPORTADORA DEMO');
        $this->assertSame([['estadia', 'bultos', 'general', '90', '5', '450']], $this->lineas($demo));
        $this->assertSame([['despacho', 1], ['estadia', 1]], array_map(fn (array $pendiente): array => [$pendiente['servicio'], $pendiente['hechos']], $demo['sin_precio']));
        $this->assertSame([['fuente' => 'romana_recepcion', 'nombre' => 'Recepciones en romana', 'hechos' => 1]], $prefactura['sin_cliente']);
        $this->assertSame(['clientes' => 1, 'hechos_valorizados' => 1, 'hechos_sin_precio' => 2, 'hechos_sin_cliente' => 1], $prefactura['resumen']);
    }

    public function test_el_mes_en_curso_se_valoriza_hasta_hoy_y_un_mes_futuro_no_tiene_nada(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00', 'America/Santiago'));
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $this->precio(null, 'estadia', 'bultos', '90', '2026-11-01');
        $this->estadia($cliente, 'F6#1', '2026-11-10 08:00', null, ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120]);

        $actual = $this->prefactura();
        $this->assertTrue($actual['a_la_fecha']);
        $this->assertSame('2026-11-15', $actual['corte']);
        $this->assertSame('6', $this->cliente($actual, 'EXPORTADORA DEMO')['lineas'][0]['cantidad'], 'Del 10 al 15.');

        $futuro = app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-12'));
        $this->assertNull($futuro['corte']);
        $this->assertFalse($futuro['a_la_fecha']);
        $this->assertSame([], $futuro['clientes']);
    }

    public function test_valoriza_los_hechos_capturados_de_la_operacion(): void
    {
        $cliente = $this->clienteGlobal('PRODUCTOR DE PRUEBA SPA');
        $this->precio(null, 'romana_recepcion', 'kilos_netos', '10', '2026-11-01');
        $this->precio($cliente, 'estadia', 'cajas', '2', '2026-11-01');
        $this->recepcionRomana($cliente, 'R-5001', 8000, '2026-11-03 09:00');
        $camara = $this->camara('C1');
        $folio = $this->folio('PT-5001', $cliente, cajas: 100);
        $this->movimiento($folio, null, $camara, '2026-11-25 08:00');
        $this->ubicar($folio, $camara, '2026-11-25 08:00');
        $this->capturar();

        // Sigue en cámara: del 25 al 30 son 6 días de 100 cajas.
        $this->assertSame([
            ['romana_recepcion', 'kilos_netos', 'general', '10', '8000', '80000'],
            ['estadia', 'cajas', 'propio', '2', '600', '1200'],
        ], $this->lineas($this->cliente($this->prefactura(), 'PRODUCTOR DE PRUEBA SPA')));
    }

    /** @return array<string, mixed> */
    private function prefactura(bool $conDetalle = false): array
    {
        return app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'), conDetalle: $conDetalle);
    }

    /**
     * @param  array<string, mixed>  $prefactura
     * @return array<string, mixed>
     */
    private function cliente(array $prefactura, string $nombre): array
    {
        $cliente = collect($prefactura['clientes'])->firstWhere('cliente_nombre', $nombre);
        $this->assertNotNull($cliente, "Falta {$nombre} en la prefactura.");

        return $cliente;
    }

    /**
     * @param  array<string, mixed>  $cliente
     * @return list<list<string>>
     */
    private function lineas(array $cliente): array
    {
        return array_map(fn (array $linea): array => [$linea['servicio'], $linea['unidad'], $linea['origen'], $linea['precio'], $linea['cantidad'], $linea['monto']], $cliente['lineas']);
    }

    private function precio(?string $cliente, string $servicio, string $unidad, string $precio, string $desde, bool $cerrarAnterior = false, string $moneda = 'CLP'): void
    {
        app(GestorTarifas::class)->crear([
            'cliente_id' => $cliente, 'servicio' => $servicio, 'unidad' => $unidad, 'moneda' => $moneda,
            'precio' => $precio, 'vigente_desde' => $desde, 'cerrar_anterior' => $cerrarAnterior,
        ], $this->administrador);
    }

    /**
     * @param  array<string, int|float>  $medidas
     * @param  array<string, mixed>  $datos
     */
    private function hecho(string $fuente, ?string $cliente, string $ocurrido, array $medidas, ?string $termina = null, array $datos = [], ?string $origen = null, string $estado = Hecho::ESTADO_VIGENTE): Hecho
    {
        $inicio = CarbonImmutable::parse($ocurrido, 'America/Santiago');

        return Hecho::query()->create([
            'fuente' => $fuente,
            'origen_id' => $origen ?? (string) Str::uuid(),
            'version' => 1,
            'vigente' => true,
            'estado' => $estado,
            'motivo_version' => Hecho::MOTIVO_CAPTURA,
            'cliente_id' => $cliente,
            'cliente_nombre' => $cliente === null ? 'EXPORTADORA SIN VINCULAR' : null,
            'referencia' => 'REF-'.Str::upper(Str::random(5)),
            'ocurrido_at' => $inicio->utc(),
            'termina_at' => $termina === null ? null : CarbonImmutable::parse($termina, 'America/Santiago')->utc(),
            'fecha_operacional' => $inicio->toDateString(),
            'medidas' => $medidas,
            'datos' => $datos,
            'huella' => str_repeat('0', 64),
            'capturado_at' => now(),
        ]);
    }

    /**
     * @param  array<string, int>  $medidas
     * @param  array<string, mixed>  $datos
     * @param  list<array{0: array<string, int>, 1: string, 2: ?string}>  $tramos  [medidas, desde, hasta]
     */
    private function estadia(string $cliente, string $origen, string $ingreso, ?string $salida, array $medidas, array $datos = [], array $tramos = []): void
    {
        $iso = fn (?string $hora): ?string => $hora === null ? null : CarbonImmutable::parse($hora, 'America/Santiago')->utc()->toIso8601String();
        $tramos = $tramos === [] ? [[$medidas, $ingreso, $salida]] : $tramos;
        $this->hecho('estadia', $cliente, $ingreso, $medidas, $salida, [
            ...$datos,
            'tramos' => array_map(fn (array $tramo): array => ['desde_at' => $iso($tramo[1]), 'hasta_at' => $iso($tramo[2]), 'medidas' => $tramo[0], 'repaletizaje_inicio' => null], $tramos),
        ], $origen);
    }
}
