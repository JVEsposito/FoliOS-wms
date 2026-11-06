<?php

namespace Tests\Unit\Validacion;

use App\Models\Folio;
use App\Models\Repaletizaje;
use App\Services\Validacion\ServicioRegistroRepaletizaje;
use Tests\TestCase;

class BloquesRegistroRepaletizajeTest extends TestCase
{
    public function test_el_registro_imprime_el_calibre_del_componente_y_no_el_mix_del_folio(): void
    {
        $repa = $this->repa([
            'modalidad' => 'consolidacion', 'codigo' => 'REPA-MIX', 'tipo_resultado' => 'pallet',
            'cantidad_resultante' => 240, 'estado' => 'confirmado',
            'snapshot' => ['especificaciones' => ['variedad' => 'Royal Dawn', 'marca' => 'FALCO', 'envase' => '5 kg'],
                'genealogia' => [[
                    'numero_folio' => 'MIX-240', 'especificaciones' => ['calibre' => 'MIX'],
                    'composicion_aportada' => [
                        ['csg' => '152223', 'fecha_embalaje' => '2026-11-03', 'calibre' => '2J', 'cantidad_cajas' => 100],
                        ['csg' => '152223', 'fecha_embalaje' => '2026-11-03', 'calibre' => '3J', 'cantidad_cajas' => 140],
                    ],
                ]],
            ],
        ], 'RESULTADO-240');
        $bloques = app(ServicioRegistroRepaletizaje::class)->bloques($repa);
        $this->assertSame(['2J', '3J'], array_column($bloques[0]['lineas'], 'calibre'));
        $this->assertSame([100, 140], array_column($bloques[0]['lineas'], 'cajas'));
        $this->assertSame(240, $bloques[0]['total']);
    }

    public function test_una_consolidacion_con_mas_de_ocho_lineas_continua_en_otro_bloque_y_el_total_va_al_final(): void
    {
        $genealogia = collect(range(1, 10))->map(fn (int $numero): array => [
            'folio_id' => "origen-{$numero}",
            'numero_folio' => "SAL-{$numero}",
            'cajas_aportadas' => 12,
            'composicion_aportada' => [['csg' => '105410', 'fecha_embalaje' => '2026-09-21', 'cantidad_cajas' => 12]],
            'especificaciones' => ['calibre' => 'XL'],
        ])->all();
        $repa = $this->repa([
            'modalidad' => 'consolidacion',
            'codigo' => 'REPA-2026-000010',
            'tipo_resultado' => 'pallet',
            'cantidad_resultante' => 120,
            'estado' => 'confirmado',
            'snapshot' => [
                'especificaciones' => ['variedad' => 'Lapins', 'marca' => 'ANDES', 'envase' => 'Caja 5 kg'],
                'genealogia' => $genealogia,
            ],
        ], 'PAL-GRANDE');

        $bloques = app(ServicioRegistroRepaletizaje::class)->bloques($repa);

        $this->assertCount(2, $bloques);
        $this->assertSame('REPA-2026-000010 (1/2)', $bloques[0]['repa']);
        $this->assertCount(8, $bloques[0]['lineas']);
        $this->assertNull($bloques[0]['total'], 'El primer bloque indica que continúa.');
        $this->assertSame('REPA-2026-000010 (2/2)', $bloques[1]['repa']);
        $this->assertCount(2, $bloques[1]['lineas']);
        $this->assertSame(120, $bloques[1]['total']);
        $this->assertSame(['folio' => 'SAL-1', 'fecha' => '21-09-2026', 'calibre' => 'XL', 'csg' => '105410', 'cajas' => 12], $bloques[0]['lineas'][0]);
        $this->assertSame('PAL-GRANDE', $bloques[1]['folio']);
        $this->assertSame('completo', $bloques[1]['estado']);
    }

    public function test_una_division_genera_un_bloque_por_cada_folio_resultante(): void
    {
        $repa = $this->repa([
            'modalidad' => 'division',
            'codigo' => 'REPA-2026-000011',
            'tipo_resultado' => 'division',
            'cantidad_resultante' => 100,
            'estado' => 'anulado',
            'motivo_anulacion' => 'Se dividió el pallet equivocado',
            'snapshot' => [
                'genealogia' => [[
                    'folio_id' => 'origen',
                    'numero_folio' => 'PAL-ORIGEN',
                    'especificaciones' => ['variedad' => 'Regina', 'marca' => 'PREMIUM', 'envase' => 'Caja 2,5 kg', 'calibre' => '3J', 'csg' => '105411'],
                ]],
                'resultados' => [
                    ['numero_folio' => 'PAL-A', 'tipo_resultado' => 'saldo', 'cantidad_resultante' => 60, 'composicion' => [['csg' => '105411', 'fecha_embalaje' => '2026-09-22', 'cantidad_cajas' => 60]]],
                    ['numero_folio' => 'PAL-B', 'tipo_resultado' => 'saldo', 'cantidad_resultante' => 40, 'composicion' => [['csg' => '105411', 'fecha_embalaje' => '2026-09-22', 'cantidad_cajas' => 40]]],
                ],
            ],
        ], 'PAL-A');

        $bloques = app(ServicioRegistroRepaletizaje::class)->bloques($repa);

        $this->assertSame(['PAL-A', 'PAL-B'], array_column($bloques, 'folio'));
        $this->assertSame(['saldo', 'saldo'], array_column($bloques, 'estado'));
        $this->assertSame([60, 40], array_column($bloques, 'total'));
        $this->assertSame('PAL-ORIGEN', $bloques[1]['lineas'][0]['folio']);
        $this->assertSame('Regina', $bloques[0]['variedad']);
        $this->assertTrue($bloques[0]['anulada']);
        $this->assertSame('Se dividió el pallet equivocado', $bloques[1]['motivo']);
    }

    /** @param  array<string, mixed>  $atributos */
    private function repa(array $atributos, string $folioResultante): Repaletizaje
    {
        $repa = new Repaletizaje;
        $repa->forceFill($atributos);
        $repa->setRelation('detalles', collect());
        $repa->setRelation('folioResultante', (new Folio)->forceFill(['numero_folio' => $folioResultante]));

        return $repa;
    }
}
