<?php

namespace Tests\Feature;

use App\Models\RecepcionMaterial;
use App\Services\Materiales\RegistroRecepcionMaterial;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\TestCase;

class RegistroRecepcionMaterialTest extends TestCase
{
    public function test_cinco_dias_corridos_respetan_el_cambio_de_hora_chileno(): void
    {
        $registro = app(RegistroRecepcionMaterial::class);
        $this->assertSame('2026-09-09T02:59:59+00:00', $registro->limite(CarbonImmutable::parse('2026-09-03T10:00:00-04:00'))->toAtomString());
        $this->assertSame('2026-10-29T02:59:59+00:00', $registro->limite(CarbonImmutable::parse('2026-10-23T23:30:00-03:00'))->toAtomString());
    }

    public function test_un_cliente_anterior_no_inventa_fechas_fisicas_ni_retroactividad(): void
    {
        $datos = app(RegistroRecepcionMaterial::class)->preparar([]);
        $this->assertNull($datos['fecha_recepcion']);
        $this->assertFalse($datos['provisional']);
        $this->assertFalse($datos['registro_retroactivo']);
    }

    public function test_la_fecha_declarada_no_vuelve_retroactivo_un_borrador_del_mismo_dia_al_editarlo_despues(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-23T18:00:00Z'));
        $recepcion = new RecepcionMaterial(['fecha_recepcion' => '2026-10-20 15:00:00', 'registro_retroactivo' => false]);
        $datos = app(RegistroRecepcionMaterial::class)->preparar(['fecha_recepcion' => '2026-10-20T12:00:00-03:00'], $recepcion);
        $this->assertFalse($datos['registro_retroactivo']);
        $this->assertSame('2026-10-26T02:59:59+00:00', $datos['fecha_limite_regularizacion']->toAtomString());
    }

    public function test_no_acepta_fecha_fisica_futura(): void
    {
        $this->expectException(DomainException::class);
        app(RegistroRecepcionMaterial::class)->preparar(['fecha_recepcion' => now()->addDay()->toAtomString()]);
    }
}
