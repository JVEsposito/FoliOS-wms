<?php

namespace Tests\Feature\Asistente;

use App\Models\RangoTaraAsistente;
use App\Models\RecepcionRomana;
use App\Services\Asistente\RevisionTaras;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

class RevisionTarasTest extends TestCase
{
    use PreparaAsistente;
    use RefreshDatabase;

    protected bool $tarasRomanaHistoricas = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_neto_por_tote_7_9_se_marca_bajo_el_rango(): void
    {
        $this->recepcionTotes('REC-1', 7.9);

        $hallazgos = $this->revisar('neto_por_tote');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('REC-1', $hallazgos[0]['recepcion']);
        $this->assertSame('bajo', $hallazgos[0]['sentido']);
        $this->assertSame(7.9, $hallazgos[0]['valor']);
    }

    public function test_neto_por_tote_9_0_no_se_marca(): void
    {
        $this->recepcionTotes('REC-1', 9.0);

        $this->assertSame([], $this->revisar('neto_por_tote'));
    }

    public function test_rango_de_especie_reemplaza_al_general(): void
    {
        RangoTaraAsistente::query()->create(['tipo_envase' => 'totes', 'especie' => 'Cereza', 'minimo_kg' => 9.2, 'maximo_kg' => 9.8, 'activo' => true]);
        $recepcion = $this->recepcionTotes('REC-1', 9.0);
        $this->lote($recepcion, $this->csg('11111'), 1, 900);

        $hallazgos = $this->revisar('neto_por_tote');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('bajo', $hallazgos[0]['sentido']);
    }

    public function test_recepcion_con_cero_totes_no_divide_por_cero(): void
    {
        $this->recepcionTotes('REC-1', 9.0, ['cantidad_envase_calculo_neto' => 0]);
        $this->lote($this->recepcion('REC-2', 900), $this->csg('11111'), 1, 900, 'totes', 0);

        $this->assertSame([], $this->revisar());
    }

    public function test_tara_de_tote_distinta_a_la_oficial_se_marca(): void
    {
        $recepcion = $this->recepcionTotes('REC-1', 9.0, ['taras_romana_snapshot' => ['totes' => 1.8, 'bins' => 40]]);
        $this->detalle($recepcion, 'totes', 2.1);

        $hallazgos = $this->revisar('tara_envase');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('distinta', $hallazgos[0]['sentido']);
        $this->assertSame(2.1, $hallazgos[0]['valor']);
    }

    public function test_esponja_fuera_de_dos_desviaciones_se_marca(): void
    {
        foreach (range(1, 12) as $i) {
            $this->detalle($this->recepcion("REC-H{$i}", 900), 'esponjas', $i % 2 === 0 ? 0.50 : 0.52);
        }
        $this->detalle($this->recepcion('REC-RARA', 900), 'esponjas', 0.80);

        $hallazgos = $this->revisar('esponja');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('REC-RARA', $hallazgos[0]['recepcion']);
        $this->assertSame('sobre', $hallazgos[0]['sentido']);
    }

    public function test_esponja_con_menos_de_10_muestras_no_se_marca(): void
    {
        foreach (range(1, 5) as $i) {
            $this->detalle($this->recepcion("REC-H{$i}", 900), 'esponjas', 0.50);
        }
        $this->detalle($this->recepcion('REC-RARA', 900), 'esponjas', 0.80);

        $this->assertSame([], $this->revisar('esponja'));
    }

    public function test_historico_csg_marca_lote_alejado_de_su_csg(): void
    {
        $csg = $this->csg('11111');
        foreach (range(1, 12) as $i) {
            $this->lote($this->recepcion("REC-H{$i}", 900, ['ingreso_at' => now()->subDays(3)]), $csg, $i, $i % 2 === 0 ? 900 : 910);
        }
        $this->lote($this->recepcion('REC-HOY', 780), $csg, 99, 780);

        $hallazgos = $this->revisar('historico_csg');

        $this->assertCount(1, $hallazgos);
        $this->assertSame('REC-HOY', $hallazgos[0]['recepcion']);
        $this->assertSame('LOTE-99', $hallazgos[0]['lote']);
        $this->assertSame('bajo', $hallazgos[0]['sentido']);
    }

    /** @param  array<string, mixed>  $datos */
    private function recepcionTotes(string $numero, float $netoPorTote, array $datos = []): RecepcionRomana
    {
        return $this->recepcion($numero, $netoPorTote * 100, [
            'tipo_envase_calculo_neto' => 'totes',
            'cantidad_envase_calculo_neto' => 100,
            'peso_neto_por_envase' => $netoPorTote,
            ...$datos,
        ]);
    }

    private function detalle(RecepcionRomana $recepcion, string $tipo, float $tara): void
    {
        $recepcion->detallesEnvases()->create([
            'tipo_envase' => $tipo,
            'propiedad' => 'cliente',
            'cantidad_declarada' => 100,
            'cantidad_validada' => 100,
            'cantidad_vacia' => 0,
            'tara_unitaria_salida' => $tara,
        ]);
    }

    private function revisar(?string $regla = null): array
    {
        $hoy = CarbonImmutable::now('America/Santiago')->startOfDay();
        $hallazgos = app(RevisionTaras::class)->revisar($this->temporada, $hoy->utc(), $hoy->addDay()->utc());

        return array_values($regla === null ? $hallazgos : array_filter($hallazgos, fn (array $h): bool => $h['regla'] === $regla));
    }
}
