<?php

namespace Tests\Unit\Services\Cargas;

use App\Models\Camara;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\UbicacionActual;
use App\Services\Cargas\SectorAcopioCarga;
use Tests\TestCase;

class SectorAcopioLecturaActualTest extends TestCase
{
    public function test_al_cambiar_de_banda_no_exige_un_sector_que_otro_camarero_acaba_de_proteger(): void
    {
        $camara = (new Camara)->forceFill(['id' => 'CAM-A', 'cantidad_bandas' => 4,
            'posiciones_por_banda' => 14, 'cantidad_niveles' => 1]);
        $posiciones = collect();
        foreach (range(1, 4) as $banda) {
            foreach (range(1, 14) as $profundidad) {
                $ubicaciones = collect();
                if ($banda <= 2) {
                    $folio = (new Folio)->setRelation('asignacionCargaActual',
                        (new CargaFolio)->forceFill(['carga_id' => $banda === 1 ? 'CAR-A' : 'CAR-B']));
                    $ubicaciones->push((new UbicacionActual)->setRelation('folio', $folio));
                }
                $posiciones->push((new Posicion)->forceFill(['id' => $banda.'-'.$profundidad,
                    'camara_id' => 'CAM-A', 'banda' => $banda, 'posicion' => $profundidad, 'nivel' => 1, 'estado' => 'activa'])
                    ->setRelation('ubicacionesActuales', $ubicaciones)
                    ->setRelation('reservaTareaActiva', null)->setRelation('reservaPreparacionSagActiva', null));
            }
        }
        $camara->setRelation('posiciones', $posiciones)->setRelation('bandasOperacionales', collect())
            ->setRelation('proteccionesBandas', collect());
        $puntos = array_map(fn ($p) => ['banda' => 1, 'posicion' => $p, 'nivel' => 1], range(1, 14));
        $sector = app(SectorAcopioCarga::class);
        $sector->desdeCamarasActuales(collect([$camara]));
        $anterior = $sector->elegir('CAM-A', 'CAR-A', $puntos, 20);
        $this->assertSame('nuevo', $anterior['sector']['tipo']);
        $this->assertSame(4, $anterior['sector']['banda_desde']);

        // Mientras se espera el mutex de cámara, un rival toma la banda cuatro.
        // La banda tres sigue vacía y es físicamente utilizable para la carga.
        $camara->setRelation('proteccionesBandas', collect([(new ReservaBandaManiobra)->forceFill([
            'banda' => 4, 'nivel' => 1, 'maniobra_operacional_id' => 'MANIOBRA-B',
        ])]));
        $sector->olvidar();
        $sector->desdeCamarasActuales(collect([$camara]));
        $vigente = $sector->elegir('CAM-A', 'CAR-A', $puntos, 20);
        $this->assertSame('libre', $vigente['sector']['tipo']);
        $this->assertSame([], $vigente['puntos']);
    }
}
