<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\EventoTarifa;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\Tarifa;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Finanzas\Tarifario\Precio;
use App\Finanzas\Tarifario\ResolutorTarifas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Reglas del tarifario: qué precio se cobra a quién, y qué combinaciones se rechazan. */
class TarifarioTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Administración Demo']);
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
    }

    public function test_un_cliente_con_precio_propio_deja_de_usar_la_tarifa_general_de_ese_servicio(): void
    {
        $conContrato = $this->clienteGlobal('EXPORTADORA CON CONTRATO');
        $sinContrato = $this->clienteGlobal('EXPORTADORA SIN CONTRATO');
        $general = $this->precio(null, 'estadia', 'bultos', '100', '2026-11-01');
        $prefrio = $this->precio(null, 'prefrio', 'cajas', '30', '2026-11-01');
        $porCaja = $this->precio($conContrato, 'estadia', 'cajas', '2.5', '2026-11-15');

        $resolutor = ResolutorTarifas::entre('2026-10-01', '2026-11-30');

        $this->assertSame([$general->id], $this->ids($resolutor->aplicables($conContrato, 'estadia', '2026-11-14')), 'Antes de su precio propio rige la tarifa general.');
        $this->assertSame([$porCaja->id], $this->ids($resolutor->aplicables($conContrato, 'estadia', '2026-11-15')), 'Con precio propio no se suma la tarifa general.');
        $this->assertSame([$prefrio->id], $this->ids($resolutor->aplicables($conContrato, 'prefrio', '2026-11-20')), 'Sin precio propio de prefrío, usa la tarifa general.');
        $this->assertSame([$general->id], $this->ids($resolutor->aplicables($sinContrato, 'estadia', '2026-11-20')));
        $this->assertSame([$general->id], $this->ids($resolutor->aplicables(null, 'estadia', '2026-11-20')));
        $this->assertSame([], $resolutor->aplicables($conContrato, 'estadia', '2026-10-31'));
        $this->assertSame([], $resolutor->aplicables($conContrato, 'despacho', '2026-11-20'));
    }

    public function test_los_precios_de_un_mismo_servicio_y_unidad_no_se_cruzan(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $this->precio(null, 'estadia', 'bultos', '100', '2026-11-01', '2026-11-30');

        $this->assertRechazado(
            fn () => $this->precio(null, 'estadia', 'bultos', '110', '2026-11-15'),
            'vigente_desde',
            'Se cruza con otro precio por bulto y día, vigente desde el 01-11-2026 hasta el 30-11-2026. Ajuste las fechas o ponga término a ese precio.',
        );

        // El día siguiente al término ya no se cruza, y cada cliente tiene sus propias vigencias.
        $this->precio(null, 'estadia', 'bultos', '110', '2026-12-01');
        $this->precio($cliente, 'estadia', 'bultos', '90', '2026-11-15');
        $this->assertSame(3, Tarifa::query()->count());
    }

    public function test_un_servicio_no_se_cobra_por_bulto_y_a_la_vez_por_pallet_o_saldo(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $porBulto = $this->precio($cliente, 'estadia', 'bultos', '100', '2026-11-01');

        $this->assertRechazado(
            fn () => $this->precio($cliente, 'estadia', 'pallets', '120', '2026-11-10'),
            'vigente_desde',
            'Se cruza con el precio por bulto y día, vigente desde el 01-11-2026 sin término: cobrar por bulto y, a la vez, por pallet o saldo cobraría dos veces el mismo bulto.',
        );

        // Al cambiar de unidad, el precio nuevo puede cerrar el anterior el día previo.
        $porPallet = $this->precio($cliente, 'estadia', 'pallets', '120', '2026-11-10', cerrarAnterior: true);
        $porSaldo = $this->precio($cliente, 'estadia', 'saldos', '60', '2026-11-10');
        $this->assertSame('2026-11-09', $porBulto->refresh()->vigente_hasta->toDateString());

        $resolutor = ResolutorTarifas::entre('2026-11-01', '2026-11-30');
        $this->assertSame([$porBulto->id], $this->ids($resolutor->aplicables($cliente, 'estadia', '2026-11-09')));
        $this->assertSame([$porPallet->id, $porSaldo->id], $this->ids($resolutor->aplicables($cliente, 'estadia', '2026-11-10')));
    }

    public function test_un_precio_nuevo_cierra_el_anterior_el_dia_previo_sin_dejar_dias_sin_precio(): void
    {
        $anterior = $this->precio(null, 'prefrio', 'cajas', '30', '2026-01-01');
        $nuevo = $this->precio(null, 'prefrio', 'cajas', '35', '2026-11-01', cerrarAnterior: true);

        $this->assertSame('2026-10-31', $anterior->refresh()->vigente_hasta->toDateString());
        $cierre = EventoTarifa::query()->where('tarifa_id', $anterior->id)->where('accion', EventoTarifa::CERRADA)->sole();
        $this->assertSame(['vigente_hasta' => ['antes' => null, 'despues' => '2026-10-31']], $cierre->cambios);
        $this->assertSame('Reemplazado por un precio nuevo desde el 01-11-2026.', $cierre->motivo);
        $this->assertSame('Administración Demo', $cierre->user_nombre);

        $resolutor = ResolutorTarifas::entre('2026-10-01', '2026-11-30');
        $this->assertSame([$anterior->id], $this->ids($resolutor->aplicables(null, 'prefrio', '2026-10-31')));
        $this->assertSame([$nuevo->id], $this->ids($resolutor->aplicables(null, 'prefrio', '2026-11-01')));

        // Un precio temporal no corta lo que el vigente cubre después: se informa el cruce.
        $cruce = 'Se cruza con otro precio por caja, vigente desde el 01-11-2026 sin término. Ajuste las fechas o ponga término a ese precio.';
        $this->assertRechazado(fn () => $this->precio(null, 'prefrio', 'cajas', '40', '2026-12-01', '2026-12-31', cerrarAnterior: true), 'vigente_desde', $cruce);
        $this->assertNull($nuevo->refresh()->vigente_hasta);

        // Uno que empieza el mismo día no se puede cerrar el día anterior.
        $this->assertRechazado(fn () => $this->precio(null, 'prefrio', 'cajas', '36', '2026-11-01', cerrarAnterior: true), 'vigente_desde', $cruce);
        $this->assertSame(2, Tarifa::query()->count());
    }

    public function test_editar_y_anular_dejan_historial_y_lo_anulado_no_se_cobra(): void
    {
        $gestor = app(GestorTarifas::class);
        $tarifa = $this->precio(null, 'despacho', 'pallets', '1500', '2026-11-01');
        $cambios = ['moneda' => 'CLP', 'precio' => '1650.50', 'vigente_desde' => '2026-11-01', 'vigente_hasta' => '2027-03-31', 'nota' => 'Contrato 2026-2027'];

        $gestor->modificar($tarifa, $cambios, $this->administrador);
        // Guardar lo mismo no agrega historial.
        $gestor->modificar($tarifa, [...$cambios, 'precio' => '1650.500000'], $this->administrador);
        $gestor->anular($tarifa, 'Precio cargado al cliente equivocado.', $this->administrador);

        $eventos = EventoTarifa::query()->where('tarifa_id', $tarifa->id)->orderBy('id')->get();
        $this->assertSame([EventoTarifa::CREADA, EventoTarifa::MODIFICADA, EventoTarifa::ANULADA], $eventos->pluck('accion')->all());
        $this->assertSame('1500', $eventos[0]->cambios['precio']);
        $this->assertSame('Tarifa general', $eventos[0]->cambios['cliente']);
        // MySQL ordena las claves de una columna JSON: se compara sin depender del orden.
        $this->assertEquals([
            'precio' => ['antes' => '1500', 'despues' => '1650.5'],
            'vigente_hasta' => ['antes' => null, 'despues' => '2027-03-31'],
            'nota' => ['antes' => null, 'despues' => 'Contrato 2026-2027'],
        ], $eventos[1]->cambios);
        $this->assertSame('Precio cargado al cliente equivocado.', $eventos[2]->motivo);

        $this->assertSame([], ResolutorTarifas::delDia('2026-11-15')->aplicables(null, 'despacho', '2026-11-15'));
        $this->assertRechazado(fn () => $gestor->modificar($tarifa, $cambios, $this->administrador), 'tarifa', 'Un precio anulado no se puede editar: cree uno nuevo.');
        $this->assertRechazado(fn () => $gestor->anular($tarifa, 'Otra vez.', $this->administrador), 'tarifa', 'El precio ya estaba anulado.');

        // Anulado, deja libre su vigencia para el precio correcto.
        $this->precio(null, 'despacho', 'pallets', '1600', '2026-11-01');
        $this->assertCount(1, ResolutorTarifas::delDia('2026-11-15')->aplicables(null, 'despacho', '2026-11-15'));
    }

    public function test_solo_se_usan_monedas_activas_unidades_del_servicio_y_clientes_de_la_operacion(): void
    {
        Moneda::query()->create(['codigo' => 'USD', 'nombre' => 'Dólar estadounidense', 'simbolo' => 'US$', 'decimales' => 2, 'activa' => true]);
        $tarifa = $this->precio(null, 'recepcion_embalada', 'cajas', '0.012', '2026-11-01', moneda: 'USD');
        Moneda::query()->whereKey('USD')->update(['activa' => false]);

        $this->assertRechazado(fn () => $this->precio(null, 'repaletizaje', 'operaciones', '5', '2026-11-01', moneda: 'USD'), 'moneda', 'La moneda no existe o está inactiva.');
        // Un precio que ya la usa se puede seguir editando sin cambiarla.
        app(GestorTarifas::class)->modificar($tarifa, ['moneda' => 'USD', 'precio' => '0.0125', 'vigente_desde' => '2026-11-01'], $this->administrador);
        $this->assertSame('0.0125', Precio::normalizar($tarifa->refresh()->precio));

        $this->assertRechazado(fn () => $this->precio((string) Str::uuid(), 'prefrio', 'cajas', '30', '2026-11-01'), 'cliente_id', 'El cliente no existe en la operación.');
        $this->assertRechazado(fn () => $this->precio(null, 'prefrio', 'kilos_netos', '30', '2026-11-01'), 'unidad', 'Esa unidad de cobro no corresponde al servicio.');
    }

    public function test_los_precios_son_decimales_exactos(): void
    {
        $this->assertSame('0.35', Precio::normalizar('0.350000'));
        $this->assertSame('12', Precio::normalizar('012.000000'));
        $this->assertSame('0', Precio::normalizar('0.000000'));

        $tarifa = $this->precio(null, 'romana_recepcion', 'kilos_netos', '999999999999.999999', '2026-11-01');
        $this->assertSame('999999999999.999999', Precio::normalizar($tarifa->refresh()->precio));
    }

    private function precio(?string $cliente, string $servicio, string $unidad, string $precio, string $desde, ?string $hasta = null, bool $cerrarAnterior = false, string $moneda = 'CLP'): Tarifa
    {
        return app(GestorTarifas::class)->crear([
            'cliente_id' => $cliente,
            'servicio' => $servicio,
            'unidad' => $unidad,
            'moneda' => $moneda,
            'precio' => $precio,
            'vigente_desde' => $desde,
            'vigente_hasta' => $hasta,
            'cerrar_anterior' => $cerrarAnterior,
        ], $this->administrador);
    }

    private function assertRechazado(callable $accion, string $campo, string $mensaje): void
    {
        try {
            $accion();
            $this->fail("Se esperaba un rechazo en «{$campo}».");
        } catch (ValidationException $error) {
            $this->assertSame([$campo => [$mensaje]], $error->errors());
        }
    }

    /**
     * @param  list<Tarifa>  $tarifas
     * @return list<int>
     */
    private function ids(array $tarifas): array
    {
        return array_map(fn (Tarifa $tarifa): int => $tarifa->id, $tarifas);
    }
}
