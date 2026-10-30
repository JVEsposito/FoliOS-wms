<?php

namespace Tests\Unit\Services\Cargas;

use App\Models\Camara;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\UbicacionActual;
use App\Services\Cargas\CalculadorConcentracionCarga;
use Tests\TestCase;

class ConcentracionBandaCompletaTest extends TestCase
{
    public function test_los_pallets_apilados_en_la_misma_banda_cuentan_como_un_grupo(): void
    {
        $asignaciones = collect();
        foreach (range(1, 10) as $p) {
            foreach ([1, 2] as $nivel) {
                $asignaciones->push($this->asignacion('CAM-A', 1, $p, $nivel));
            }
        }
        $analisis = app(CalculadorConcentracionCarga::class)->analizar($asignaciones);
        $this->assertSame(20, $analisis['concentrados']);
        $this->assertSame(100, $analisis['porcentaje']);
        $this->assertTrue($analisis['cumple_umbral']);
    }

    public function test_no_junta_camaras_distintas_ni_niveles_sin_contacto_vertical(): void
    {
        $asignaciones = collect([$this->asignacion('CAM-A', 1, 1, 1), $this->asignacion('CAM-A', 1, 2, 2),
            $this->asignacion('CAM-B', 1, 1, 1)]);
        $analisis = app(CalculadorConcentracionCarga::class)->analizar($asignaciones);
        $this->assertSame(1, $analisis['concentrados']);
        $asignaciones->push($this->asignacion('CAM-A', 1, 1, 2));
        $analisis = app(CalculadorConcentracionCarga::class)->analizar($asignaciones);
        $this->assertSame(3, $analisis['concentrados']);
        $this->assertSame(75, $analisis['porcentaje']);
    }

    public function test_una_banda_completa_no_se_desarma_para_pegarla_a_la_siguiente_parcial(): void
    {
        $asignaciones = collect();
        foreach (range(1, 14) as $p) {
            $asignaciones->push($this->asignacion('CAM-A', 1, $p, 1, 1));
        }
        foreach (range(1, 6) as $p) {
            $asignaciones->push($this->asignacion('CAM-A', 4, $p, 1, 1));
        }
        $analisis = app(CalculadorConcentracionCarga::class)->analizar($asignaciones);
        $this->assertSame(20, $analisis['concentrados']);
        $this->assertSame(100, $analisis['porcentaje']);
        $this->assertSame([1, 4], $analisis['grupo_principal']['bandas']);
    }

    public function test_tres_bandas_parciales_no_cuentan_como_carga_compacta(): void
    {
        $asignaciones = collect();
        foreach ([1 => 7, 3 => 7, 5 => 6] as $banda => $cantidad) {
            foreach (range(1, $cantidad) as $p) {
                $asignaciones->push($this->asignacion('CAM-A', $banda, $p, 1, 1));
            }
        }
        $analisis = app(CalculadorConcentracionCarga::class)->analizar($asignaciones);
        $this->assertSame(7, $analisis['concentrados']);
        $this->assertSame(35, $analisis['porcentaje']);
    }

    public function test_una_banda_fuera_de_las_dimensiones_no_se_suma_como_banda_completa(): void
    {
        $asignaciones = collect();
        foreach ([7 => 14, 3 => 6] as $banda => $cantidad) {
            foreach (range(1, $cantidad) as $p) {
                $asignaciones->push($this->asignacion('CAM-A', $banda, $p, 1, 1));
            }
        }
        $this->assertSame(14, app(CalculadorConcentracionCarga::class)->analizar($asignaciones)['concentrados']);
    }

    private function asignacion(string $camaraId, int $banda, int $profundidad, int $nivel, int $niveles = 2): CargaFolio
    {
        $id = implode('-', [$camaraId, $banda, $profundidad, $nivel]);
        $camara = (new Camara)->forceFill(['id' => $camaraId, 'codigo' => $camaraId, 'nombre' => $camaraId,
            'cantidad_bandas' => 6, 'posiciones_por_banda' => 14, 'cantidad_niveles' => $niveles]);
        $posicion = (new Posicion)->forceFill(['camara_id' => $camaraId, 'banda' => $banda, 'posicion' => $profundidad, 'nivel' => $nivel])
            ->setRelation('camara', $camara);
        $ubicacion = (new UbicacionActual)->setRelation('posicion', $posicion);
        $folio = (new Folio)->forceFill(['id' => $id])->setRelation('ubicacionActual', $ubicacion);

        return (new CargaFolio)->forceFill(['id' => $id, 'folio_id' => $id, 'estado' => 'pendiente'])->setRelation('folio', $folio);
    }
}
