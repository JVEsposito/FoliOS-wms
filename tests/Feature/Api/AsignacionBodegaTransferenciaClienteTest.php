<?php

namespace Tests\Feature\Api;

use App\Models\FolioMaterial;
use App\Models\SaldoMaterialAlmacen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MultibodegaMaterialFixture;
use Tests\TestCase;

class AsignacionBodegaTransferenciaClienteTest extends TestCase
{
    use MultibodegaMaterialFixture, RefreshDatabase;

    public function test_transferencia_de_propiedad_no_debita_bodega_inicial_distinta_a_central(): void
    {
        $this->prepararMultibodega();
        $this->repartir(40);
        // Simula una recepción cuyo origen de compatibilidad es Norte, repartida posteriormente.
        $this->origen->forceFill(['almacen_inicial_id' => $this->norte['id']])->saveQuietly();
        $antes = SaldoMaterialAlmacen::where('folio_id', $this->origen->folio_id)->orderBy('id')->get()->toJson();
        $this->postJson('/api/materiales/inventario/'.$this->origen->folio_id.'/transferir-cliente', $this->datosTransferencia(10))->assertUnprocessable();
        $this->assertSame($antes, SaldoMaterialAlmacen::where('folio_id', $this->origen->folio_id)->orderBy('id')->get()->toJson());
        $this->assertEquals(100, FolioMaterial::findOrFail($this->origen->folio_id)->cantidad_actual);
        $this->assertDatabaseCount('transferencias_clientes_materiales', 0);
    }
}
