<?php

namespace Tests\Feature\Asistente;

use App\Enums\RolUsuario;
use App\Services\Asistente\Consultas\KilosPorCsg;
use App\Services\Asistente\Consultas\KilosRecepciones;
use App\Services\Asistente\Consultas\TiemposRomana;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

class ConsultasRomanaTest extends TestCase
{
    use PreparaAsistente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_tiempos_romana_calcula_promedio_y_maximo_en_minutos(): void
    {
        $this->recepcion('REC-1', 9000, ['ingreso_at' => now()->subMinutes(100), 'salida_at' => now()->subMinutes(10)]);
        $this->recepcion('REC-2', 9000, ['ingreso_at' => now()->subMinutes(40), 'salida_at' => now()->subMinutes(10)]);

        $resultado = app(TiemposRomana::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(ResultadoConsulta::RESPONDIDA, $resultado->estado);
        $this->assertSame(60, $this->cifra($resultado, 'Tiempo en planta promedio'));
        $this->assertSame(90, $this->cifra($resultado, 'Tiempo en planta máximo'));
        $this->assertSame('REC-1', $resultado->filas[0]['codigo']);
    }

    public function test_tiempos_romana_cuenta_las_que_esperan_destare(): void
    {
        $this->recepcion('REC-1', 9000);
        $this->recepcion('REC-2', 0, [
            'estado' => 'en_bascula_salida', 'salida_at' => null, 'peso_tara' => null, 'peso_neto' => null,
            'ingreso_at' => now()->subMinutes(50), 'ingreso_confirmado_at' => now()->subMinutes(45),
        ]);

        $resultado = app(TiemposRomana::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(1, $this->cifra($resultado, 'Esperando destare'));
        $this->assertStringContainsString('1 esperan destare', $resultado->resumen);
    }

    public function test_kilos_recepciones_suma_por_cliente_y_excluye_anuladas(): void
    {
        $this->recepcion('REC-1', 9000);
        $this->recepcion('REC-2', 1000.5);
        $this->recepcion('REC-3', 5000, ['estado' => 'anulada']);

        $resultado = app(KilosRecepciones::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(10000.5, $this->cifra($resultado, 'Kilos netos'));
        $this->assertSame(2, $this->cifra($resultado, 'Recepciones'));
        $this->assertSame('Exportadora Andina', $resultado->filas[0]['cliente']);
    }

    public function test_kilos_por_csg_agrupa_por_csg_snapshot(): void
    {
        $recepcion = $this->recepcion('REC-1', 9000);
        $this->lote($recepcion, $this->csg('11111'), 1, 6000);
        $this->lote($recepcion, $this->csg('22222'), 2, 3000);
        $this->lote($this->recepcion('REC-2', 1000), $this->csg('11111'), 3, 1000);

        $resultado = app(KilosPorCsg::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(10000.0, $this->cifra($resultado, 'Kilos netos'));
        $this->assertSame(['csg' => '11111', 'lotes' => 2, 'kilos' => 7000.0], $resultado->filas[0]);
    }

    public function test_sin_recepciones_en_el_rango_devuelve_sin_datos(): void
    {
        $resultado = app(KilosRecepciones::class)->ejecutar($this->administrador, new ParametrosConsulta);

        $this->assertSame(ResultadoConsulta::SIN_DATOS, $resultado->estado);
        $this->assertStringContainsString('No hay recepciones cerradas', $resultado->resumen);
        $this->assertSame([], $resultado->filas);
    }

    public function test_usuario_sin_permiso_de_romana_no_puede_ejecutar(): void
    {
        $usuario = $this->usuarioAsistente(RolUsuario::SupervisorMateriales, [CatalogoModulosAcceso::OFICINA_ASISTENTE, 'materiales.inventario']);

        $this->assertFalse(app(TiemposRomana::class)->puedeEjecutar($usuario));
        $this->assertTrue(app(TiemposRomana::class)->puedeEjecutar($this->administrador));
    }

    private function cifra(ResultadoConsulta $resultado, string $etiqueta): int|float|string|null
    {
        foreach ($resultado->cifras as $cifra) {
            if ($cifra['etiqueta'] === $etiqueta) {
                return $cifra['valor'];
            }
        }

        return null;
    }
}
