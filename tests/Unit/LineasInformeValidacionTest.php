<?php

namespace Tests\Unit;

use App\Services\Validacion\LineasInformeValidacion;
use PHPUnit\Framework\TestCase;

class LineasInformeValidacionTest extends TestCase
{
    public function test_agrupa_por_csg_y_calibre_y_nunca_devuelve_mix(): void
    {
        $snapshot = [
            'articulo' => ['calibre' => 'MIX'],
            'origen' => ['csg' => 'MIX'],
            'composicion' => [
                ['csg' => '153854', 'calibre' => '2JD', 'cantidad_cajas' => 30, 'lote_materia_prima' => 'L-1'],
                ['csg' => '3130897', 'calibre' => '2JD', 'cantidad_cajas' => 20],
                ['csg' => '153854', 'calibre' => '2jd', 'cantidad_cajas' => 10, 'lote_materia_prima' => 'L-2'],
                ['csg' => '153854', 'calibre' => '3J', 'cantidad_cajas' => 13],
                ['csg' => 'MIX', 'calibre' => '3J', 'cantidad_cajas' => 0],
            ],
        ];

        $lineas = LineasInformeValidacion::de($snapshot, 73);

        $this->assertSame([
            ['csg' => '153854', 'calibre' => '2JD', 'cajas' => 40],
            ['csg' => '3130897', 'calibre' => '2JD', 'cajas' => 20],
            ['csg' => '153854', 'calibre' => '3J', 'cajas' => 13],
        ], $lineas);
        $this->assertNull(LineasInformeValidacion::calibreUnico($lineas));
    }

    public function test_un_pallet_antiguo_sin_composicion_conserva_su_unica_linea(): void
    {
        $lineas = LineasInformeValidacion::de(['articulo' => ['calibre' => 'J'], 'origen' => ['csg' => '105410']], 120);
        $this->assertSame([['csg' => '105410', 'calibre' => 'J', 'cajas' => 120]], $lineas);
        $this->assertSame('J', LineasInformeValidacion::calibreUnico($lineas));

        $antiguo = LineasInformeValidacion::de([
            'articulo' => ['calibre' => 'J'],
            'composicion' => [['csg' => '105410', 'cantidad_cajas' => 60], ['csg' => '105411', 'cantidad_cajas' => 60]],
        ], 120);
        $this->assertSame(['J', 'J'], array_column($antiguo, 'calibre'));
        $this->assertSame('Sin desglose', LineasInformeValidacion::de(['origen' => ['csg' => 'MIX']], 5)[0]['csg']);
    }
}
