<?php

namespace Tests\Unit;

use App\Services\Integraciones\ServicioLecturaEstandaresOrigen;
use PHPUnit\Framework\TestCase;

class AgrupacionEstandaresOrigenTest extends TestCase
{
    private function fila(array $cambios = []): array
    {
        return [
            'cod_empresa' => 'FCO', 'cod_temporada' => 'T2627', 'codigo_estandar' => 'FC50PM', 'descripcion_estandar' => 'FALCO CARTON 5KG POUCH',
            'cod_articulo' => 'FC50PM', 'descripcion_articulo' => 'FALCO CARTON 5KG POUCH BOLSA ATMOSFERA', 'cod_insumo' => '031026',
            'descripcion_insumo' => 'TAPA CAJA BOLSITAS 5 KG', 'tipo_rebaja_insumo' => 'Entero', 'cantidad' => '1', 'cod_especie' => 'CE',
            'desc_especie' => 'CEREZAS', 'fecha_creacion' => '2026-11-06 09:59:57', 'fecha_edicion' => null, ...$cambios,
        ];
    }

    public function test_toma_la_cabecera_mas_reciente_suma_insumos_repetidos_y_limpia_flotantes(): void
    {
        $resultado = ServicioLecturaEstandaresOrigen::agrupar([
            $this->fila(),
            $this->fila(),
            $this->fila(['cod_insumo' => '031000', 'descripcion_insumo' => 'PALLET 1000X1200', 'tipo_rebaja_insumo' => 'Decimal', 'cantidad' => '0.006200000178813934']),
            $this->fila(['cod_insumo' => 'AR5048', 'descripcion_insumo' => 'ETIQUETA 100X70', 'cantidad' => '1']),
            $this->fila(['cod_insumo' => 'AR5048', 'descripcion_insumo' => 'ETIQUETA 100X70', 'cantidad' => '0.009999999776482582']),
            $this->fila(['fecha_creacion' => '2026-10-01 08:00:00', 'cod_insumo' => 'VIEJO', 'cantidad' => '3']),
            $this->fila(['cod_empresa' => 'fla', 'cod_articulo' => 'CFL1201NPM', 'tipo_rebaja_insumo' => 'raro']),
            $this->fila(['cod_insumo' => 'CERO', 'cantidad' => '0']),
        ]);

        $this->assertSame(8, $resultado['filas']);
        $this->assertSame(1, $resultado['duplicadas']);
        $this->assertSame(1, $resultado['descartadas']);
        $this->assertSame(1, $resultado['invalidas']);
        $this->assertCount(2, $resultado['estandares']);
        [$fco, $fla] = $resultado['estandares'];
        $this->assertSame(['FCO', 'FC50PM', 2, '2026-11-06 09:59:57'], [$fco['cod_empresa'], $fco['cod_articulo'], $fco['cabeceras'], $fco['fecha_creacion']]);
        $this->assertSame([
            ['cod_insumo' => '031026', 'descripcion' => 'TAPA CAJA BOLSITAS 5 KG', 'redondeo' => 'entero', 'cantidad' => '1.000000', 'repetido' => 1],
            ['cod_insumo' => '031000', 'descripcion' => 'PALLET 1000X1200', 'redondeo' => 'decimal', 'cantidad' => '0.006200', 'repetido' => 1],
            ['cod_insumo' => 'AR5048', 'descripcion' => 'ETIQUETA 100X70', 'redondeo' => 'entero', 'cantidad' => '1.010000', 'repetido' => 2],
        ], $fco['lineas']);
        $this->assertSame('FLA', $fla['cod_empresa']);
        $this->assertSame('decimal', $fla['lineas'][0]['redondeo']);
    }

    public function test_la_huella_solo_cambia_si_cambia_el_contenido_del_estandar(): void
    {
        $base = ServicioLecturaEstandaresOrigen::agrupar([$this->fila(), $this->fila(['cod_insumo' => '031000', 'cantidad' => '0.0062'])])['estandares'][0]['huella'];
        $reordenado = ServicioLecturaEstandaresOrigen::agrupar([$this->fila(['cod_insumo' => '031000', 'cantidad' => '0.0062']), $this->fila()])['estandares'][0]['huella'];
        $editado = ServicioLecturaEstandaresOrigen::agrupar([$this->fila(['fecha_edicion' => '2026-11-10 10:00:00']), $this->fila(['cod_insumo' => '031000', 'cantidad' => '0.0062', 'fecha_edicion' => '2026-11-10 10:00:00'])])['estandares'][0]['huella'];
        $otraCantidad = ServicioLecturaEstandaresOrigen::agrupar([$this->fila(), $this->fila(['cod_insumo' => '031000', 'cantidad' => '0.0083'])])['estandares'][0]['huella'];

        $this->assertSame($base, $reordenado);
        $this->assertSame($base, $editado);
        $this->assertNotSame($base, $otraCantidad);
    }
}
