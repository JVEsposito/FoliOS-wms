<?php

namespace Tests\Feature\Asistente;

use App\Models\RecepcionRomana;
use App\Services\Asistente\Consultas\EnvasesRecibidos;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Tema «Envases recibidos»: lo que llegó con la fruta y lo que salió con el camión en el destare. */
class EnvasesRecibidosTest extends TestCase
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

    public function test_salidas_destare_del_periodo(): void
    {
        $this->recepcionConSalida('REC-2611-0001', '2026-11-21 16:00', ['bins' => 12, 'totes' => 40]);
        $this->recepcionConSalida('REC-2611-0002', '2026-11-22 09:00', ['bins' => 5]);
        $this->recepcionConSalida('REC-2611-0003', '2026-11-21 17:00', ['bins' => 7], estado: 'anulada');

        $ayer = $this->vista('salidas-destare', ['desde' => '2026-11-21']);

        $this->assertSame([
            ['recepcion' => 'REC-2611-0001', 'cliente' => 'Exportadora Andina', 'patente' => 'ABCD12', 'envase' => 'Bins plástico', 'cantidad' => 12],
            ['recepcion' => 'REC-2611-0001', 'cliente' => 'Exportadora Andina', 'patente' => 'ABCD12', 'envase' => 'Tote', 'cantidad' => 40],
        ], $ayer->filas);
        $this->assertSame(['REC-2611-0002'], array_column($this->vista('salidas-destare')->filas, 'recepcion'));
    }

    public function test_recibidos_sigue_igual(): void
    {
        $tema = app(EnvasesRecibidos::class);

        $this->assertSame('recibidos', $tema->vistaPorDefecto());
        $this->assertEquals(
            $tema->ejecutar($this->administrador, (new ParametrosConsulta)->conVista('recibidos')),
            $tema->ejecutar($this->administrador, new ParametrosConsulta),
        );
    }

    private function vista(string $vista, array $datos = []): ResultadoConsulta
    {
        return app(EnvasesRecibidos::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));
    }

    /** @param  array<string, int>  $salidas */
    private function recepcionConSalida(string $numero, string $salida, array $salidas, string $estado = 'cerrado'): void
    {
        $momento = CarbonImmutable::parse($salida, 'America/Santiago')->utc();
        $recepcion = RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $numero), 'numero_recepcion' => $numero,
            'temporada_id' => $this->temporada->id, 'temporada_codigo_snapshot' => $this->temporada->codigo, 'temporada_nombre_snapshot' => $this->temporada->nombre,
            'cliente_id' => $this->cliente->id, 'cliente_codigo_snapshot' => $this->cliente->codigo, 'cliente_nombre_snapshot' => $this->cliente->nombre,
            'tipo_recepcion' => 'fruta_con_envases', 'tipo_servicio' => 'proceso', 'cantidad_envases_declarados' => 100, 'tipo_envase_declarado' => 'bins',
            'numero_guia_despacho' => '1'.substr($numero, -4), 'modo_salida_envases' => 'diferentes',
            'tipo_envase_calculo_neto' => 'bins', 'peso_tara_envases' => 0, 'peso_bruto' => 20000, 'peso_tara' => 8000, 'peso_neto' => 12000,
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo', 'rut_conductor' => '12345678-5', 'nombre_conductor' => 'Conductor de prueba',
            'estado' => $estado, 'estado_validacion_mp' => 'validada', 'ingreso_at' => $momento->subHours(2), 'salida_at' => $momento,
            'creado_por_user_id' => $this->administrador->id,
        ]);
        foreach ($salidas as $tipo => $cantidad) {
            $recepcion->salidasEnvases()->create(['tipo_envase' => $tipo, 'cantidad' => $cantidad, 'tara_unitaria' => 1]);
        }
    }
}
