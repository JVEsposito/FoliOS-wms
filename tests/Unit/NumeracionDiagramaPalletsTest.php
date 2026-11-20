<?php

namespace Tests\Unit;

use App\Services\InspeccionSag\NumeracionDiagramaPallets;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Mismos casos que tests/JavaScript/SagPalletDiagramTest.mjs: pantalla y servidor numeran igual. */
class NumeracionDiagramaPalletsTest extends TestCase
{
    private NumeracionDiagramaPallets $numeracion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->numeracion = new NumeracionDiagramaPallets;
    }

    public function test_la_numeracion_sigue_de_un_pallet_al_siguiente_sin_reiniciar(): void
    {
        $pallets = [$this->pallet(8, 13), $this->pallet(5, 10), $this->pallet(5, 10), ...array_fill(0, 13, $this->pallet(8, 13))];
        $n = $this->numeracion->numerar($pallets);

        $this->assertSame([[1, 104], [105, 154], [155, 204], [205, 308], [309, 412], [413, 516], [517, 620]],
            array_map(fn (array $r): array => [$r['inicio'], $r['fin']], array_slice($n['rangos'], 0, 7)));
        $this->assertSame(104 * 14 + 100, $n['total']);
    }

    public function test_la_ultima_corrida_incompleta_queda_arriba_desde_la_izquierda(): void
    {
        $n = $this->numeracion->numerar([$this->pallet(8, 13, 5), $this->pallet(8, 2)]);

        $this->assertSame([101, 102], [$n['rangos'][0]['fin'], $n['rangos'][1]['inicio']]);
        $corridas = $this->numeracion->corridas($n['rangos'][0]);
        $this->assertCount(13, $corridas);
        $this->assertSame([97, 98, 99, 100, 101], $corridas[0]);
        $this->assertSame(range(1, 8), end($corridas));
    }

    public function test_ubica_una_caja_por_corrida_desde_abajo_y_posicion_desde_la_izquierda(): void
    {
        $n = $this->numeracion->numerar([$this->pallet(8, 13), $this->pallet(5, 10)]);

        $this->assertSame(['pallet' => 0, 'corrida' => 1, 'posicion' => 1], $this->numeracion->ubicar(1, $n));
        $this->assertSame(['pallet' => 0, 'corrida' => 13, 'posicion' => 8], $this->numeracion->ubicar(104, $n));
        $this->assertSame(['pallet' => 1, 'corrida' => 9, 'posicion' => 1], $this->numeracion->ubicar(145, $n));
        $this->assertNull($this->numeracion->ubicar(155, $n));
    }

    public function test_las_marcas_se_ordenan_sin_repetidos_y_no_pueden_apuntar_a_cajas_inexistentes(): void
    {
        $n = $this->numeracion->numerar([$this->pallet(8, 13)]);

        $this->assertSame([5, 82], $this->numeracion->marcas([82, 5, 82], $n));
        $this->expectException(ValidationException::class);
        $this->numeracion->marcas([5, 105], $n);
    }

    public function test_rechaza_una_ultima_corrida_igual_a_la_base_y_declaradas_distintas(): void
    {
        try {
            $this->numeracion->numerar([$this->pallet(8, 13, 8), ['base' => 8, 'corridas' => 13, 'declaradas' => 100]]);
            $this->fail('Debió rechazar la configuración.');
        } catch (ValidationException $e) {
            $this->assertSame(['pallets.0.ultima', 'pallets.1.declaradas'], array_keys($e->errors()));
        }
    }

    private function pallet(int $base, int $corridas, ?int $ultima = null): array
    {
        return ['folio' => null, 'base' => $base, 'corridas' => $corridas, 'ultima' => $ultima, 'declaradas' => null];
    }
}
