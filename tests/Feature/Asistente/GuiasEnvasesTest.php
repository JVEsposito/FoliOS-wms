<?php

namespace Tests\Feature\Asistente;

use App\Models\GuiaDespachoEnvase;
use App\Services\Asistente\Consultas\GuiasEnvases;
use App\Services\Asistente\ParametrosConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Tema «Guías de despacho de envases»: borrador, confirmadas y sin N° de guía emitida. */
class GuiasEnvasesTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 12:00', 'America/Santiago'));
        $this->prepararAsistente();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_borrador_lista_solo_las_guias_en_borrador(): void
    {
        $this->guia('GDE-2611-0001', 'borrador');
        $this->guia('GDE-2611-0002', 'confirmada');

        $this->assertSame(['GDE-2611-0001'], $this->numeros('borrador'));
    }

    public function test_confirmadas_cuenta_las_salidas_del_periodo(): void
    {
        $this->guia('GDE-2611-0001', 'confirmada', '2026-11-21 15:00');
        $this->guia('GDE-2611-0002', 'confirmada', '2026-11-22 09:00');
        $this->guia('GDE-2611-0003', 'borrador', '2026-11-21 16:00');

        $this->assertSame(['GDE-2611-0001'], $this->numeros('confirmadas', ['desde' => '2026-11-21']));
        $this->assertSame(['GDE-2611-0002'], $this->numeros('confirmadas'));
    }

    public function test_sin_numero_lista_las_confirmadas_sin_guia_emitida(): void
    {
        $this->guia('GDE-2611-0001', 'confirmada');
        $this->guia('GDE-2611-0002', 'confirmada', numeroEmitido: '12345');
        $this->guia('GDE-2611-0003', 'borrador');

        $this->assertSame(['GDE-2611-0001'], $this->numeros('sin-numero'));
    }

    public function test_sin_vista_rige_borrador(): void
    {
        $this->guia('GDE-2611-0001', 'borrador');
        $this->guia('GDE-2611-0002', 'confirmada');

        $tema = app(GuiasEnvases::class);

        $this->assertSame('borrador', $tema->vistaPorDefecto());
        $this->assertEquals(
            $tema->ejecutar($this->administrador, (new ParametrosConsulta)->conVista('borrador')),
            $tema->ejecutar($this->administrador, new ParametrosConsulta),
        );
    }

    /** @return list<string> */
    private function numeros(string $vista, array $datos = []): array
    {
        $resultado = app(GuiasEnvases::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));

        return array_column($resultado->filas, 'numero');
    }

    private function guia(string $numero, string $estado, ?string $salida = null, ?string $numeroEmitido = null): void
    {
        GuiaDespachoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'numero' => $numero, 'numero_guia_despacho' => $numeroEmitido, 'temporada_id' => $this->temporada->id,
            'cliente_id' => $this->cliente->id, 'cliente_codigo_snapshot' => 'ASI', 'cliente_nombre_snapshot' => 'Exportadora Andina',
            'estado' => $estado, 'salida_at' => $salida ? CarbonImmutable::parse($salida, 'America/Santiago')->utc() : now(),
            'confirmado_at' => $estado === 'confirmada' ? now() : null,
            'patente_camion' => 'ABCD12', 'version' => 1, 'creado_por_user_id' => $this->administrador->id,
        ]);
    }
}
