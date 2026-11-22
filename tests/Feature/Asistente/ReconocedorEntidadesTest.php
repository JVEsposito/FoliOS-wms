<?php

namespace Tests\Feature\Asistente;

use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\Temporada;
use App\Services\Asistente\ReconocedorEntidades;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconocedorEntidadesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        config(['app.operational_timezone' => 'America/Santiago']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reconoce_hoy_ayer_y_esta_semana(): void
    {
        $hoy = $this->reconocer('tiempos de romana hoy');
        $this->assertSame('2026-11-22 03:00:00', $hoy['desde']->toDateTimeString());
        $this->assertSame('2026-11-23 03:00:00', $hoy['hasta']->toDateTimeString());

        $ayer = $this->reconocer('kilos de ayer');
        $this->assertSame('2026-11-21 03:00:00', $ayer['desde']->toDateTimeString());
        $this->assertSame('2026-11-22 03:00:00', $ayer['hasta']->toDateTimeString());

        // 22-11-2026 es domingo: la semana parte el lunes 16.
        $semana = $this->reconocer('cargas de esta semana');
        $this->assertSame('2026-11-16 03:00:00', $semana['desde']->toDateTimeString());
        $this->assertSame('2026-11-23 03:00:00', $semana['hasta']->toDateTimeString());
    }

    public function test_ignora_fecha_imposible_31_02(): void
    {
        $resultado = $this->reconocer('kilos del 31-02');

        $this->assertNull($resultado['desde']);
        $this->assertNull($resultado['hasta']);
    }

    public function test_reconoce_rango_entre_dos_fechas(): void
    {
        $resultado = $this->reconocer('kilos del 18/11 al 19/11/2026');

        $this->assertSame('2026-11-18 03:00:00', $resultado['desde']->toDateTimeString());
        $this->assertSame('2026-11-20 03:00:00', $resultado['hasta']->toDateTimeString());
    }

    public function test_reconoce_codigos_rec_car_pf(): void
    {
        $this->assertSame('REC-2611-0108', $this->reconocer('dame la rec-2611-0108')['codigo']);
        $this->assertSame('CAR-000059', $this->reconocer('estado de CAR-000059')['codigo']);
        $this->assertSame('PF-014', $this->reconocer('proceso PF-014')['codigo']);
    }

    public function test_reconoce_cliente_por_nombre_con_un_error_de_tipeo(): void
    {
        $cliente = Cliente::create(['codigo' => 'DIS', 'nombre' => 'Exportadora Andina', 'activo' => true]);

        $this->assertSame($cliente->id, $this->reconocer('kilos de exportadora andina')['cliente_id']);
        $this->assertSame($cliente->id, $this->reconocer('cajas de andinna')['cliente_id']);
        $this->assertNull($this->reconocer('cajas de mañana')['cliente_id']);
        $this->assertSame($cliente->id, $this->reconocer('cajas de DIS hoy')['cliente_id']);
    }

    public function test_reconoce_especie_de_la_temporada_activa(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);

        $resultado = $this->reconocer('neto por tote de cerezas');

        $this->assertSame($especie->id, $resultado['especie_id']);
        $this->assertSame('Cereza', $resultado['especie']);
    }

    public function test_temporada_pasada(): void
    {
        $this->assertTrue($this->reconocer('kilos de la temporada pasada')['temporada_anterior']);
        $this->assertFalse($this->reconocer('kilos de hoy')['temporada_anterior']);
    }

    private function reconocer(string $texto): array
    {
        return app(ReconocedorEntidades::class)->reconocer($texto);
    }
}
