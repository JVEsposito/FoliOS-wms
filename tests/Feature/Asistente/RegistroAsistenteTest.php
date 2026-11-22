<?php

namespace Tests\Feature\Asistente;

use App\Models\ConfiguracionAsistente;
use App\Models\PreguntaAsistente;
use App\Models\RangoTaraAsistente;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistroAsistenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracion_siembra_rango_tote_8_2_a_9_5(): void
    {
        $rango = RangoTaraAsistente::vigentePara('totes', null);

        $this->assertNotNull($rango);
        $this->assertSame(8.2, $rango->minimo_kg);
        $this->assertSame(9.5, $rango->maximo_kg);
        $this->assertTrue($rango->contiene(9.0));
        $this->assertFalse($rango->contiene(7.9));
    }

    public function test_rango_de_especie_tiene_prioridad_sobre_el_general(): void
    {
        RangoTaraAsistente::query()->create([
            'tipo_envase' => 'totes', 'especie' => 'Arándano', 'minimo_kg' => 5, 'maximo_kg' => 6, 'activo' => true,
        ]);

        $this->assertSame(5.0, RangoTaraAsistente::vigentePara('totes', 'arandano')->minimo_kg);
        $this->assertSame(8.2, RangoTaraAsistente::vigentePara('totes', 'Cereza')->minimo_kg);
        $this->assertNull(RangoTaraAsistente::vigentePara('bins', null));
    }

    public function test_pregunta_no_se_puede_eliminar(): void
    {
        $pregunta = PreguntaAsistente::query()->create([
            'user_id' => User::factory()->create()->id,
            'texto' => 'tiempos de romana hoy',
            'interprete' => 'reglas',
            'estado' => 'respondida',
            'created_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $pregunta->delete();
    }

    public function test_configuracion_actual_tiene_margen_2(): void
    {
        $this->assertSame(2.0, ConfiguracionAsistente::actual()->margen_desviaciones);
        $this->assertSame(ConfiguracionAsistente::MODO_SIN_IA, ConfiguracionAsistente::actual()->modo);
    }
}
