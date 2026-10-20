<?php

namespace Tests\Feature\Comex;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class PackingListComexTest extends TestCase
{
    use PreparaOperacionComex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
    }

    public function test_el_packing_list_lista_cada_linea_de_pallet_con_sus_kilos(): void
    {
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(2), 'maritimo', [['CN', ['booking' => 'BK-9001', 'recibidor' => 'RECEIVER CO LTD']]]);
        $carga = $this->cargaParaEmbarque($embarque);
        $caja5 = $this->envaseConKilos('Caja 5 kg', '5.0000');
        $caja25 = $this->envaseConKilos('Caja 2,5 kg', '2.5000');
        $this->folioEnCarga($carga, 'PT-0002', [['csg' => '100002', 'cantidad_cajas' => 100, 'envase_validacion_id' => $caja5, 'fecha_embalaje' => '2026-11-03']], 'despachado');
        $this->folioEnCarga($carga, 'PT-0001', [
            ['csg' => '100001', 'predio' => 'PREDIO NORTE', 'cantidad_cajas' => 60, 'envase_validacion_id' => $caja5],
            ['csg' => '100003', 'cantidad_cajas' => 40, 'envase_validacion_id' => $caja25, 'variedad' => 'Lapins'],
        ]);
        $this->folioEnCarga($carga, 'PT-0003', [['csg' => '100004', 'cantidad_cajas' => 10]], 'descartado');

        $respuesta = $this->actingAs($this->administradorComex())->get("/api/comex/expedientes/{$embarque}/packing-list")->assertOk();
        $this->assertSame('attachment; filename=packing-list-cx-0001.xlsx', $respuesta->headers->get('Content-Disposition'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->getFile()->getPathname()) === true);
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $corte = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();

        // Ordenado por folio; el pallet mixto ocupa dos filas y el descartado no aparece.
        $this->assertStringContainsString('<t xml:space="preserve">PT-0001 (1/2)</t>', $hoja);
        $this->assertStringContainsString('<t xml:space="preserve">PT-0001 (2/2)</t>', $hoja);
        $this->assertStringContainsString('<t xml:space="preserve">Lapins</t>', $hoja);
        $this->assertStringContainsString('<t xml:space="preserve">PREDIO NORTE</t>', $hoja);
        $this->assertStringNotContainsString('PT-0003', $hoja);
        $this->assertStringContainsString('<c r="P2" s="4"><v>300</v></c>', $hoja);
        $this->assertStringContainsString('<c r="P3" s="4"><v>100</v></c>', $hoja);
        $this->assertStringContainsString('<c r="P4" s="4"><v>500</v></c>', $hoja);
        $this->assertStringContainsString('<t xml:space="preserve">Despachado</t>', $hoja);
        $this->assertStringContainsString('<t xml:space="preserve">En andén</t>', $hoja);

        foreach (['Packing list CX-0001', 'EXPORTADORA DEMO SPA', 'RECEIVER CO LTD', 'BK-9001', 'TEST1234567', 'SELLO-01', 'NAVE DE PRUEBA', 'Marítimo', '900,00'] as $dato) {
            $this->assertStringContainsString($dato, $corte);
        }
        $this->assertStringContainsString('<t xml:space="preserve">Pallets</t></is></c><c r="B', $corte);
    }

    public function test_sin_carga_o_sin_pallets_explica_por_que_no_hay_packing_list(): void
    {
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(2));
        $admin = $this->administradorComex();

        $this->actingAs($admin)->getJson("/api/comex/expedientes/{$embarque}/packing-list")
            ->assertUnprocessable()->assertJsonValidationErrors(['embarque' => 'El embarque todavía no tiene una carga asignada: no hay pallets para el packing list.']);

        $this->cargaParaEmbarque($embarque);
        $this->actingAs($admin)->getJson("/api/comex/expedientes/{$embarque}/packing-list")
            ->assertUnprocessable()->assertJsonValidationErrors(['embarque' => 'La carga del embarque todavía no tiene pallets asignados.']);
    }
}
