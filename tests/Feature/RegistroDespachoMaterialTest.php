<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Materiales\RegistroDespachoMaterial;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RegistroDespachoMaterialTest extends TestCase
{
    public function test_los_clientes_anteriores_no_inventan_una_fecha_fisica(): void
    {
        $this->assertSame([], app(RegistroDespachoMaterial::class)->preparar([], new User));
    }

    public function test_plazo_corridos_reutiliza_el_calendario_local_incluso_con_cambio_de_hora(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04T18:00:00Z'));
        Gate::define('autorizar-despachos-tardios-materiales', fn () => true);
        $datos = app(RegistroDespachoMaterial::class)->preparar(['fecha_despacho' => '2026-09-03T10:00:00-04:00', 'provisional' => true, 'motivo' => 'Respaldo pendiente', 'motivo_autorizacion' => 'Conteo físico verificado'], (new User)->forceFill(['id' => 7]));
        $this->assertSame('2026-09-09T02:59:59+00:00', $datos['fecha_limite_regularizacion']->toAtomString());
        $this->assertSame('2026-09-03T14:00:00+00:00', $datos['fecha_despacho']->toAtomString());
        $this->assertTrue($datos['registro_retroactivo']);
        $this->assertSame(7, $datos['autorizado_por_user_id']);
        $this->assertNull($datos['numero_documento']);
    }

    public function test_fecha_fisica_futura_no_es_una_entrega_ejecutada(): void
    {
        $this->expectException(DomainException::class);
        app(RegistroDespachoMaterial::class)->preparar(['fecha_despacho' => now()->addDay()->toAtomString()], new User);
    }

    public function test_documento_y_emision_se_completan_juntos(): void
    {
        $this->expectException(DomainException::class);
        app(RegistroDespachoMaterial::class)->validarDocumento('VALE-1', null);
    }
}
