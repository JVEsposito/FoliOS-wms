<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Cierre\CalendarioCierres;
use App\Finanzas\Cierre\DiferenciasCierre;
use App\Finanzas\Cierre\GestorCierres;
use App\Finanzas\Cierre\GestorDiferencias;
use App\Finanzas\Cierre\RevisionDiferencias;
use App\Finanzas\Models\AporteCierre;
use App\Finanzas\Models\AporteDiferencia;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Models\Tarifa;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\LibroPrefactura;
use App\Finanzas\Prefactura\Montos;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Cierre mensual: el mes cerrado no cambia; lo posterior es una diferencia que se decide una por una. */
class CierrePrefacturaTest extends TestCase
{
    use HechosDePrueba;
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private const PALLET = ['bultos' => 1, 'pallets' => 1, 'saldos' => 0, 'cajas' => 120];

    private string $cliente;

    private Tarifa $romana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        // Noviembre ya terminó; diciembre es el mes en curso.
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'name' => 'Administración Demo']);
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
        $this->cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $this->romana = $this->precio(null, 'romana_recepcion', 'kilos_netos', '10', '2026-09-01');
        $this->precio($this->cliente, 'estadia', 'pallets', '100', '2026-09-01');
    }

    public function test_cerrar_congela_el_mes_y_lo_posterior_queda_como_diferencia(): void
    {
        $this->recepcion('2026-11-03 09:00', 8000);
        // Del 10 a las 08:00 al 12 a las 08:00: tres días de un pallet.
        $estadia = $this->estadia($this->cliente, 'F1#1', '2026-11-10 08:00', '2026-11-12 08:00', self::PALLET);

        $cierre = $this->cerrar('2026-11');
        $this->assertSame([1, Cierre::ESTADO_CERRADO], [$cierre->version, $cierre->estado]);
        $this->assertSame(2, AporteCierre::query()->where('cierre_id', $cierre->id)->count(), 'Un aporte por hecho y línea.');
        $cerrado = $this->libro('2026-11');
        $this->assertSame('cerrado', $cerrado['estado']);
        $this->assertSame('80300', $cerrado['totales'][0]['monto']);
        $this->assertSame([['romana_recepcion', '8000', '80000'], ['estadia', '3', '300']], $this->lineas($cerrado));

        // Después del cierre llega una recepción atrasada y la estadía se corrige: sale un día después.
        $this->recepcion('2026-11-20 09:00', 1000, 'R-ATRASADA');
        $this->corregir($estadia, ['termina_at' => $this->enUtc('2026-11-13 08:00'), 'datos' => $this->datosEstadia(self::PALLET, '2026-11-10 08:00', '2026-11-13 08:00')]);

        $this->assertSame('80300', $this->libro('2026-11')['totales'][0]['monto'], 'El mes cerrado no cambia.');
        $revision = $this->diferencias('2026-11');
        $this->assertSame([
            ['romana_recepcion', '1000', 0, '10000'],
            ['estadia', '1', 1, '100'],
        ], array_map(fn (array $diferencia): array => [$diferencia['servicio'], ...array_values($diferencia['diferencia'])], $revision['diferencias']));
        $this->assertSame(['8000', '9000'], [$revision['diferencias'][0]['antes']['cantidad'], $revision['diferencias'][0]['ahora']['cantidad']]);
        $this->assertSame([[AporteDiferencia::NUEVO, 'R-ATRASADA', '1000', 0]], $this->hechosDe($revision['diferencias'][0]));
        $this->assertSame([[AporteDiferencia::CORREGIDO, $estadia->referencia, '1', 1]], $this->hechosDe($revision['diferencias'][1]));
        $this->assertSame(['diferencias' => 2, 'montos' => [['moneda' => 'CLP', 'simbolo' => '$', 'decimales' => 0, 'monto' => '10100']]], $revision['resumen']);
        $this->assertSame(2, app(RevisionDiferencias::class)->resumen($cierre->fresh())['diferencias']);
    }

    public function test_aceptar_cobra_la_diferencia_en_el_primer_mes_abierto_y_se_puede_deshacer(): void
    {
        $this->recepcion('2026-11-03 09:00', 8000);
        $this->cerrar('2026-11');
        $this->recepcion('2026-11-20 09:00', 1000, 'R-ATRASADA');

        $ajuste = $this->decidir('2026-11', Diferencia::ACEPTADA);
        $this->assertSame(['2026-12', '1000', '10000'], [$ajuste->mes_destino, $this->texto($ajuste->cantidad), $this->texto($ajuste->monto)]);
        $this->assertSame([], $this->diferencias('2026-11')['diferencias'], 'Decidida, deja de estar pendiente.');

        // Diciembre está abierto: el ajuste se cobra ahí, aunque el cliente no tenga hechos en el mes.
        $diciembre = $this->libro('2026-12');
        $this->assertSame([['2026-11', 'romana_recepcion', '1000', '10000', true]], array_map(
            fn (array $item): array => [$item['mes_origen'], $item['servicio'], $item['cantidad'], $item['monto'], $item['puede_deshacerse']],
            $diciembre['clientes'][0]['ajustes'],
        ));
        $this->assertSame('10000', $diciembre['totales'][0]['monto']);
        $this->assertSame(1, $diciembre['resumen']['ajustes']);

        // Otra recepción atrasada: solo ella queda pendiente; lo ya aceptado es parte de lo cobrado.
        $this->recepcion('2026-11-25 09:00', 500, 'R-OTRA');
        $pendiente = $this->diferencias('2026-11')['diferencias'][0];
        $this->assertSame(['500', '5000'], [$pendiente['diferencia']['cantidad'], $pendiente['diferencia']['monto']]);
        $this->assertSame([[AporteDiferencia::NUEVO, 'R-OTRA', '500', 0]], $this->hechosDe($pendiente));

        $this->assertValidacion('La diferencia cambió desde que la revisó. Revísela de nuevo antes de decidir.', fn () => app(GestorDiferencias::class)->decidir('2026-11', [
            ...$this->clave($pendiente), 'decision' => Diferencia::ACEPTADA, 'cantidad' => '400', 'dias' => 0, 'monto' => '4000',
        ], $this->administrador));
        $this->decidir('2026-11', Diferencia::DESCARTADA, 'Se cobró aparte con una nota de débito.');
        $this->assertSame([], $this->diferencias('2026-11')['diferencias']);

        // Mientras diciembre siga abierto, la aceptación se puede deshacer: la diferencia vuelve.
        app(GestorDiferencias::class)->anular($ajuste, 'Se aceptó por error.', $this->administrador);
        $this->assertSame([['1000', '10000']], array_map(fn (array $diferencia): array => [$diferencia['diferencia']['cantidad'], $diferencia['diferencia']['monto']], $this->diferencias('2026-11')['diferencias']));
        $this->assertSame([], $this->libro('2026-12')['clientes']);
        $this->assertValidacion('Esa decisión ya estaba deshecha.', fn () => app(GestorDiferencias::class)->anular($ajuste, 'Otra vez.', $this->administrador));
    }

    public function test_el_ajuste_queda_congelado_en_el_cierre_de_su_mes_de_destino(): void
    {
        $this->recepcion('2026-11-03 09:00', 8000);
        $this->cerrar('2026-11');
        $this->recepcion('2026-11-20 09:00', 1000);
        $ajuste = $this->decidir('2026-11', Diferencia::ACEPTADA);

        $this->travelTo(CarbonImmutable::parse('2027-01-05 12:00', 'America/Santiago'));
        $diciembre = $this->cerrar('2026-12');
        $this->assertSame([['moneda' => 'CLP', 'simbolo' => '$', 'decimales' => 0, 'monto' => '10000']], $diciembre->totales);
        $this->assertSame(1, $diciembre->resumen['ajustes']);
        $this->assertFalse($this->libro('2026-12')['clientes'][0]['ajustes'][0]['puede_deshacerse']);
        $this->assertValidacion('El ajuste ya quedó en el cierre de diciembre de 2026. Para deshacerlo, reabra ese mes.', fn () => app(GestorDiferencias::class)->anular($ajuste, 'Se aceptó por error.', $this->administrador));

        // Lo que se acepte ahora se cobra en enero, el nuevo primer mes abierto.
        $this->recepcion('2026-11-28 09:00', 200);
        $this->assertSame('2027-01', $this->decidir('2026-11', Diferencia::ACEPTADA)->mes_destino);
    }

    public function test_los_meses_se_cierran_en_orden_y_solo_se_reabre_el_ultimo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-05 12:00', 'America/Santiago'));
        $this->recepcion('2026-11-03 09:00', 8000);
        $this->cerrar('2026-10');

        $this->assertValidacion('Los meses se cierran en orden: primero cierre noviembre de 2026.', fn () => $this->cerrar('2026-12'));
        $this->assertValidacion('Los meses se cierran en orden desde el primer cierre (octubre de 2026): este mes queda antes y no se cierra.', fn () => $this->cerrar('2026-09'));
        $this->assertValidacion('El mes aún no termina: se puede cerrar desde el 01-02-2027.', fn () => $this->cerrar('2027-01'));
        $this->assertValidacion('El mes ya está cerrado.', fn () => $this->cerrar('2026-10'));
        $this->cerrar('2026-11');

        $this->recepcion('2026-11-30 09:00', 300);
        $aceptada = $this->decidir('2026-11', Diferencia::ACEPTADA);
        $reabrir = fn (string $mes) => app(GestorCierres::class)->reabrir($mes, 'Faltaba la recepción del día 30.', $this->administrador);
        $this->assertValidacion('Solo se reabre el último mes cerrado: noviembre de 2026.', fn () => $reabrir('2026-10'));
        $this->assertValidacion('El mes no está cerrado.', fn () => $reabrir('2026-12'));

        $reabierto = $reabrir('2026-11');
        $this->assertSame([Cierre::ESTADO_REABIERTO, 'Administración Demo'], [$reabierto->estado, $reabierto->reabierto_por_nombre]);
        $this->assertSame('Se reabrió noviembre de 2026: Faltaba la recepción del día 30.', $aceptada->fresh()->motivo_anulacion);
        $abierto = $this->libro('2026-11');
        $this->assertSame(['abierto', true, '83000'], [$abierto['estado'], $abierto['para_cerrar']['posible'], $abierto['totales'][0]['monto']], 'Abierto, se calcula con lo de hoy.');
        $this->assertSame([[1, 'Faltaba la recepción del día 30.']], array_map(fn (array $anterior): array => [$anterior['version'], $anterior['motivo_reapertura']], $abierto['reaperturas']));

        $this->assertSame(2, $this->cerrar('2026-11')->version);
        $this->assertSame('83000', $this->libro('2026-11')['totales'][0]['monto']);
        $this->assertSame([], $this->libro('2026-12')['clientes'], 'La decisión anulada ya no se cobra en diciembre.');
    }

    public function test_cerrar_con_hechos_pendientes_pide_confirmacion(): void
    {
        $this->recepcion('2026-11-03 09:00', 8000);
        $this->hecho('despacho', $this->cliente, '2026-11-04 09:00', self::PALLET);
        $this->hecho('romana_recepcion', null, '2026-11-05 09:00', ['recepciones' => 1, 'kilos_netos' => 100, 'envases' => 1]);

        $this->assertValidacion(
            'Quedan 1 hecho sin precio y 1 hecho sin cliente vinculado: no se cobrarán en este cierre. Confirme para cerrar igual.',
            fn () => $this->cerrar('2026-11'),
            'confirmar_pendientes',
        );
        $this->assertSame([0, 0], [Cierre::query()->count(), AporteCierre::query()->count()], 'Sin confirmar, no queda nada escrito.');

        $this->cerrar('2026-11', confirmarPendientes: true);
        $cerrado = $this->libro('2026-11');
        $this->assertSame([['despacho', 1]], array_map(fn (array $item): array => [$item['servicio'], $item['hechos']], $cerrado['clientes'][0]['sin_precio']));
        $this->assertSame([['romana_recepcion', 1]], array_map(fn (array $item): array => [$item['fuente'], $item['hechos']], $cerrado['sin_cliente']));
        $this->assertSame([1, 1], [$cerrado['resumen']['hechos_sin_precio'], $cerrado['resumen']['hechos_sin_cliente']]);
    }

    public function test_un_cambio_de_precio_despues_del_cierre_es_una_diferencia(): void
    {
        $this->recepcion('2026-11-03 09:00', 8000);
        $this->cerrar('2026-11');

        app(GestorTarifas::class)->modificar($this->romana, ['moneda' => 'CLP', 'precio' => '12', 'vigente_desde' => '2026-09-01'], $this->administrador);
        $diferencia = $this->diferencias('2026-11')['diferencias'][0];
        $this->assertSame(['0', 0, '16000'], array_values($diferencia['diferencia']));
        $this->assertSame(['10', '12', true], [$diferencia['antes']['precio'], $diferencia['ahora']['precio'], $diferencia['cambio_precio']]);
        $this->assertSame([], $diferencia['hechos'], 'Los hechos no cambiaron: cambió el precio.');
        $this->decidir('2026-11', Diferencia::ACEPTADA);

        // Un precio propio para noviembre saca los kilos de la tarifa general y los cobra con el suyo.
        $this->precio($this->cliente, 'romana_recepcion', 'kilos_netos', '9', '2026-11-01');
        $revision = $this->diferencias('2026-11')['diferencias'];
        $this->assertSame([['general', '-8000', '-96000'], ['propio', '8000', '72000']], array_map(fn (array $diferencia): array => [
            Tarifa::query()->find($diferencia['tarifa_id'])->general() ? 'general' : 'propio',
            $diferencia['diferencia']['cantidad'],
            $diferencia['diferencia']['monto'],
        ], $revision));
        $this->assertSame([AporteDiferencia::RETIRADO, AporteDiferencia::NUEVO], [$revision[0]['hechos'][0]['tipo'], $revision[1]['hechos'][0]['tipo']]);
    }

    public function test_la_revision_no_recalcula_un_mes_en_el_que_nada_cambio(): void
    {
        $recepcion = $this->recepcion('2026-11-03 09:00', 8000);
        $cierre = $this->cerrar('2026-11');
        $revision = app(RevisionDiferencias::class);
        $this->assertSame(['diferencias' => 0, 'montos' => []], $revision->resumen($cierre));

        // Un hecho de diciembre no toca noviembre: la revisión solo avanza su marca.
        $diciembre = $this->recepcion('2026-12-02 09:00', 500);
        $this->assertSame(0, $revision->resumen($cierre->fresh())['diferencias']);
        $this->assertSame($diciembre->id, $cierre->fresh()->revision['hechos_id']);

        // Una corrección que lleva la recepción de noviembre a diciembre sí cambia noviembre.
        $this->corregir($recepcion, ['ocurrido_at' => $this->enUtc('2026-12-01 09:00'), 'fecha_operacional' => '2026-12-01']);
        $resumen = $revision->resumen($cierre->fresh());
        $this->assertSame([1, '-80000'], [$resumen['diferencias'], $resumen['montos'][0]['monto']]);
    }

    public function test_no_se_cierra_mientras_una_lectura_registra_hechos(): void
    {
        $this->conCapturaEnOtraSesion(fn () => $this->assertValidacion(
            'Hay una lectura de la operación en curso. Espere a que termine y vuelva a cerrar el mes.',
            fn () => $this->cerrar('2026-11'),
        ));

        $this->assertSame(1, $this->cerrar('2026-11')->version);
    }

    private function recepcion(string $hora, int|float $kilos, ?string $referencia = null): Hecho
    {
        $hecho = $this->hecho('romana_recepcion', $this->cliente, $hora, ['recepciones' => 1, 'kilos_netos' => $kilos, 'envases' => 10]);
        if ($referencia !== null) {
            $hecho->forceFill(['referencia' => $referencia])->save();
        }

        return $hecho;
    }

    private function cerrar(string $mes, bool $confirmarPendientes = false): Cierre
    {
        return app(GestorCierres::class)->cerrar($mes, $confirmarPendientes, $this->administrador);
    }

    /** @return array<string, mixed> */
    private function libro(string $mes): array
    {
        return app(LibroPrefactura::class)->mes(Periodo::mes($mes));
    }

    /** @return array{diferencias: list<array<string, mixed>>, resumen: array<string, mixed>} */
    private function diferencias(string $mes): array
    {
        return app(DiferenciasCierre::class)->calcular(app(CalendarioCierres::class)->vigente($mes));
    }

    /** Decide la primera diferencia pendiente del mes, tal como se revisó. */
    private function decidir(string $mes, string $decision, ?string $motivo = null): Diferencia
    {
        $pendiente = $this->diferencias($mes)['diferencias'][0];

        return app(GestorDiferencias::class)->decidir($mes, [
            ...$this->clave($pendiente),
            ...$pendiente['diferencia'],
            'decision' => $decision,
            'motivo' => $motivo,
        ], $this->administrador);
    }

    /**
     * @param  array<string, mixed>  $diferencia
     * @return array{cliente_id: string, tarifa_id: int, moneda: string}
     */
    private function clave(array $diferencia): array
    {
        return ['cliente_id' => $diferencia['cliente_id'], 'tarifa_id' => $diferencia['tarifa_id'], 'moneda' => $diferencia['moneda']];
    }

    /**
     * @param  array<string, mixed>  $libro
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function lineas(array $libro): array
    {
        return array_map(fn (array $linea): array => [$linea['servicio'], $linea['cantidad'], $linea['monto']], $libro['clientes'][0]['lineas']);
    }

    /**
     * @param  array<string, mixed>  $diferencia
     * @return list<array{0: string, 1: ?string, 2: string, 3: int}>
     */
    private function hechosDe(array $diferencia): array
    {
        return array_map(fn (array $hecho): array => [$hecho['tipo'], $hecho['referencia'], $hecho['cantidad'], $hecho['dias']], $diferencia['hechos']);
    }

    private function texto(string $decimal): string
    {
        return Montos::texto($decimal);
    }

    private function assertValidacion(string $mensaje, callable $accion, string $campo = ''): void
    {
        try {
            $accion();
            $this->fail("Se esperaba el error: {$mensaje}");
        } catch (ValidationException $error) {
            $errores = $campo === '' ? array_merge(...array_values($error->errors())) : ($error->errors()[$campo] ?? []);
            $this->assertContains($mensaje, $errores);
        }
    }
}
