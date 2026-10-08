<?php

namespace Tests\Unit;

use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class VencimientoMaterialTest extends TestCase
{
    public function test_vigente_todo_el_dia_chileno_y_vencido_al_dia_siguiente(): void
    {
        $material = new FolioMaterial(['fecha_vencimiento' => '2026-10-05']);
        $this->travelTo(Carbon::parse('2026-10-06 02:59:59', 'UTC'));
        $this->assertFalse($material->estaVencido());
        $this->travelTo(Carbon::parse('2026-10-06 03:00:00', 'UTC'));
        $this->assertTrue($material->estaVencido());
        $this->assertFalse($material->estaVencido(Carbon::parse('2026-10-05 23:59:59', 'America/Santiago')));
    }

    public function test_sin_fecha_nunca_vence_y_la_alerta_se_puede_configurar_por_item(): void
    {
        $material = new FolioMaterial;
        $material->setRelation('item', new ItemMaterial(['dias_alerta_vencimiento' => 2]));
        $this->travelTo(Carbon::parse('2026-10-05 12:00', 'America/Santiago'));
        $this->assertFalse($material->estaVencido());
        $this->assertSame('vigente', $material->informacionVencimiento()['estado']);
        $material->fecha_vencimiento = '2026-10-08';
        $this->assertSame('vigente', $material->informacionVencimiento()['estado']);
        $material->fecha_vencimiento = '2026-10-07';
        $this->assertSame('Vence en 2 días', $material->informacionVencimiento()['etiqueta']);
        $material->fecha_vencimiento = '2026-10-05';
        $this->assertSame('Vence en 0 días', $material->informacionVencimiento()['etiqueta']);
    }

    public function test_mensaje_uniforme_con_folio_y_fecha(): void
    {
        $material = new FolioMaterial(['fecha_vencimiento' => '2026-10-05']);
        $material->setRelation('folio', new Folio(['numero_folio' => 'FGE0000123']));
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'America/Santiago'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('El folio FGE0000123 venció el 05-10-2026 y no puede utilizarse.');
        $material->asegurarVigente();
    }
}
