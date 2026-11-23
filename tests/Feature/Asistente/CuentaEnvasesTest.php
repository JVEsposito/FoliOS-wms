<?php

namespace Tests\Feature\Asistente;

use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Services\Asistente\Consultas\CuentaEnvases;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
