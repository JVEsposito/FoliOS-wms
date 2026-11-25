<?php

namespace Tests\Feature\Asistente;

use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\RangoTaraAsistente;
use App\Models\RecepcionRomana;
use App\Services\Asistente\Consultas\NetoPorEnvase;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** «Promedio de totes de 8 Fuegos»: kilos netos por tote o bin, ponderados por envases, con el rango de la planta. */
class NetoPorEnvaseTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_totes_promedia_ponderado_con_minimo_maximo_y_fuera_de_rango(): void
    {
        // Rango general de totes: 8,2 a 9,5 kg.
        $this->conEnvases('REC-1', 'totes', 100, 9.0);
        $this->conEnvases('REC-2', 'totes', 300, 8.6);
        $this->conEnvases('REC-3', 'totes', 100, 7.9);

        $resultado = $this->vista('totes');

        // (900 + 2580 + 790) / 500 = 8,54 kg por tote.
        $this->assertSame([8.54, 7.9, 9.0, 3, 1], array_column($resultado->cifras, 'valor'));
        $this->assertSame(['REC-1', 'REC-2', 'REC-3'], array_column($resultado->filas, 'recepcion'));
        $this->assertSame(['recepcion' => 'REC-3', 'cliente' => 'Exportadora Andina', 'envases' => 100, 'neto' => 790.0,
            'neto_por_envase' => 7.9, 'rango' => 'Bajo 8,2'], array_diff_key($resultado->filas[2], ['ingreso' => true]));
        $this->assertStringContainsString('8,54 kg netos por tote', $resultado->resumen);
    }

    public function test_rango_de_especie_reemplaza_al_general(): void
    {
        RangoTaraAsistente::query()->create(['tipo_envase' => 'totes', 'especie' => 'Cereza', 'minimo_kg' => 9.2, 'maximo_kg' => 9.8, 'activo' => true]);
        $cereza = EspecieValidacion::where('temporada_id', $this->temporada->id)->where('nombre', 'Cereza')->firstOrFail();
        $this->conEnvases('REC-1', 'totes', 100, 9.0, ['especie_validacion_id' => $cereza->id]);

        $this->assertSame('Bajo 9,2', $this->vista('totes')->filas[0]['rango']);
    }

    public function test_recepcion_sin_neto_por_envase_no_entra(): void
    {
        $this->conEnvases('REC-1', 'totes', 100, 9.0);
        $this->recepcion('REC-2', 500, ['tipo_envase_calculo_neto' => 'totes', 'cantidad_envase_calculo_neto' => 0, 'peso_neto_por_envase' => null]);

        $this->assertSame(['REC-1'], array_column($this->vista('totes')->filas, 'recepcion'));
    }

    public function test_bins_no_mezcla_totes(): void
    {
        $this->conEnvases('REC-1', 'totes', 100, 9.0);
        $this->conEnvases('REC-2', 'bins', 20, 310.5);

        $resultado = $this->vista('bins');

        $this->assertSame(['REC-2'], array_column($resultado->filas, 'recepcion'));
        $this->assertSame(310.5, $resultado->cifras[0]['valor']);
        // Sin rango configurado para bins no se marca nada.
        $this->assertNull($resultado->filas[0]['rango']);
    }

    public function test_por_csg_agrupa_lotes_por_csg_y_envase(): void
    {
        $recepcion = $this->conEnvases('REC-1', 'totes', 300, 9.0);
        $uno = $this->csg('104512');
        $otro = $this->csg('200300');
        $this->lote($recepcion, $uno, 1, 900, 'totes', 100);
        $this->lote($recepcion, $uno, 2, 1760, 'totes', 200);
        $this->lote($recepcion, $otro, 3, 3100, 'bins', 10);

        $filas = $this->vista('por-csg')->filas;

        $this->assertSame([
            ['csg' => '104512', 'envase' => 'totes', 'lotes' => 2, 'envases' => 300, 'neto' => 2660.0, 'neto_por_envase' => 8.867],
            ['csg' => '200300', 'envase' => 'bins', 'lotes' => 1, 'envases' => 10, 'neto' => 3100.0, 'neto_por_envase' => 310.0],
        ], $filas);
    }

    public function test_filtra_por_cliente(): void
    {
        $otro = Cliente::create(['codigo' => 'OCHO', 'nombre' => '8 Fuegos', 'activo' => true]);
        $this->conEnvases('REC-1', 'totes', 100, 9.0);
        $this->conEnvases('REC-2', 'totes', 100, 8.4, ['cliente_id' => $otro->id, 'cliente_nombre_snapshot' => $otro->nombre]);

        $resultado = $this->vista('totes', ['cliente_id' => $otro->id]);

        $this->assertSame(['REC-2'], array_column($resultado->filas, 'recepcion'));
        $this->assertSame(8.4, $resultado->cifras[0]['valor']);
    }

    public function test_sin_vista_por_defecto(): void
    {
        $this->assertNull(app(NetoPorEnvase::class)->vistaPorDefecto());
    }

    private function vista(string $vista, array $datos = []): ResultadoConsulta
    {
        return app(NetoPorEnvase::class)->ejecutar($this->administrador, ParametrosConsulta::desdeArreglo([...$datos, 'vista' => $vista]));
    }

    private function conEnvases(string $numero, string $tipo, int $cantidad, float $netoPorEnvase, array $datos = []): RecepcionRomana
    {
        return $this->recepcion($numero, $netoPorEnvase * $cantidad, [
            'tipo_envase_calculo_neto' => $tipo, 'cantidad_envase_calculo_neto' => $cantidad, 'peso_neto_por_envase' => $netoPorEnvase, ...$datos,
        ]);
    }
}
