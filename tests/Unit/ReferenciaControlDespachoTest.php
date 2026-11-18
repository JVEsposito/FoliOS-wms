<?php

namespace Tests\Unit;

use App\Models\Carga;
use App\Models\ControlDespacho;
use App\Models\Embarque;
use App\Models\InstructivoEmbarque;
use App\Services\ControlDespacho\ReferenciaControlDespacho;
use Tests\TestCase;

class ReferenciaControlDespachoTest extends TestCase
{
    public function test_conserva_todas_las_referencias_en_orden_sin_repetirlas_ni_usar_el_codigo_interno(): void
    {
        $embarque = (new Embarque(['codigo' => 'EFC000009']))->setRelation('instructivos', collect([
            new InstructivoEmbarque(['orden' => 3, 'numero_externo' => 'F026']),
            new InstructivoEmbarque(['orden' => 1, 'numero_externo' => ' F025 ']),
            new InstructivoEmbarque(['orden' => 2, 'numero_externo' => 'F025']),
            new InstructivoEmbarque(['orden' => 4, 'numero_externo' => ' ']),
        ]));
        $carga = (new Carga(['codigo' => 'CAR-000049']))->setRelation('embarque', $embarque);
        $this->assertSame('F025 / F026', ReferenciaControlDespacho::deCarga($carga));
        $this->assertSame('R-24_F025_F026.xlsx', ReferenciaControlDespacho::nombreArchivo('F025 / F026', $carga->codigo));
        $sinReferencia = (new Carga(['codigo' => 'CAR-000049']))->setRelation('embarque', null);
        $this->assertNull(ReferenciaControlDespacho::deCarga($sinReferencia));
    }

    public function test_la_referencia_confirmada_prevalece_y_una_cerrada_no_adopta_datos_posteriores(): void
    {
        $carga = (new Carga(['numero_orden_externa' => 'NUEVA']))->setRelation('embarque', null);
        $control = (new ControlDespacho(['estado' => 'cerrado', 'encabezado' => ['referencia_embarque' => 'F025']]))->setRelation('carga', $carga);
        $this->assertSame('F025', ReferenciaControlDespacho::deControl($control));
        $control->encabezado = [];
        $this->assertNull(ReferenciaControlDespacho::deControl($control));
        $control->estado = 'borrador';
        $this->assertSame('NUEVA', ReferenciaControlDespacho::deControl($control));
    }

    public function test_los_nombres_de_descarga_son_seguros_y_admiten_un_registro_antiguo_sin_referencia(): void
    {
        $this->assertSame('R-24_fotos_F025.pdf', ReferenciaControlDespacho::nombreArchivo('F025', 'CAR-000049', fotos: true));
        $this->assertSame('R-24_CAR-000049.xlsx', ReferenciaControlDespacho::nombreArchivo('../', 'CAR-000049'));
        $this->assertSame('R-24_F025_F026.xlsx', ReferenciaControlDespacho::nombreArchivo('../F025/F026', 'CAR-000049'));
        $this->assertLessThanOrEqual(200, strlen(ReferenciaControlDespacho::nombreArchivo(str_repeat('ñ', 250), 'CAR-000049')));
    }
}
