<?php

namespace Tests\Unit\Finanzas;

use App\Finanzas\Tarifario\Precio;
use App\Finanzas\Valorizacion\EvaluadorRegla;
use DomainException;
use PHPUnit\Framework\TestCase;

class EvaluadorReglaTest extends TestCase
{
    public function test_compensa_fuentes_del_mismo_proceso_sin_mezclar_clientes_y_conserva_la_fecha_del_servicio(): void
    {
        $regla = $this->regla();
        $grupos = (new EvaluadorRegla)->evaluar($regla, [
            $this->hecho(1, 'entrega', '10000'), $this->hecho(2, 'retorno', '1500', ['ocurrido_at' => '2026-12-02T13:00:00+00:00', 'fecha_operacional' => '2026-12-02']),
            $this->hecho(3, 'retorno', '500', ['cliente_id' => 'otro']),
            $this->hecho(4, 'retorno', '400', ['temporada_id' => 'otra']),
        ]);
        $completo = array_values(array_filter($grupos, fn ($g) => $g['completo']))[0];
        $this->assertSame('8500', $completo['cantidad']);
        $this->assertSame('2026-11-01', $completo['ancla']['fecha_operacional']);
        $this->assertSame([1, 2], array_column($completo['aportes'], 'hecho_id'));
        $this->assertCount(3, $grupos);
        $this->assertSame(1, count(array_filter($grupos, fn ($g) => $g['completo'])));
    }

    public function test_los_filtros_se_aplican_por_termino_y_la_aritmetica_es_decimal_exacta(): void
    {
        $regla = $this->regla();
        $regla['terminos'][1]['filtros'] = ['tipo' => 'descontable'];
        $regla['terminos'][0]['factor'] = '0.1';
        $grupos = (new EvaluadorRegla)->evaluar($regla, [
            $this->hecho(1, 'entrega', '0.3'), $this->hecho(2, 'retorno', '0.01'),
            $this->hecho(3, 'retorno', '999', ['datos' => ['proceso_id' => 'P1', 'tipo' => 'otro']]),
        ]);
        $this->assertSame('0.02', $grupos[0]['cantidad']);
        $this->assertCount(2, $grupos[0]['aportes']);
    }

    public function test_los_terminos_faltantes_quedan_pendientes_salvo_regla_explicita_y_admite_resultado_negativo(): void
    {
        $regla = $this->regla();
        $evaluador = new EvaluadorRegla;
        $this->assertFalse($evaluador->evaluar($regla, [$this->hecho(1, 'entrega', '100')])[0]['completo']);
        $regla['exigir_todos'] = false;
        $this->assertTrue($evaluador->evaluar($regla, [$this->hecho(1, 'entrega', '100')])[0]['completo']);
        $this->assertSame('-20', $evaluador->evaluar($regla, [$this->hecho(1, 'entrega', '100'), $this->hecho(2, 'retorno', '120')])[0]['cantidad']);
        $this->assertSame('-3.5', Precio::normalizar('-0003.5000'));
        $this->assertSame('0', Precio::normalizar('-00.000'));
    }

    public function test_no_agrupa_referencias_ausentes_como_si_fueran_el_mismo_proceso(): void
    {
        $this->expectException(DomainException::class);
        (new EvaluadorRegla)->evaluar($this->regla(), [$this->hecho(1, 'entrega', '100', ['datos' => []])]);
    }

    private function regla(): array
    {
        return ['agrupar' => ['proceso_id'], 'exigir_todos' => true, 'terminos' => [
            ['fuente' => 'entrega', 'medida' => 'kilos', 'factor' => '1', 'filtros' => []],
            ['fuente' => 'retorno', 'medida' => 'kilos', 'factor' => '-1', 'filtros' => []],
        ]];
    }

    private function hecho(int $id, string $fuente, string $kilos, array $extra = []): array
    {
        return ['id' => $id, 'fuente' => $fuente, 'origen_id' => (string) $id, 'version' => 1, 'cliente_id' => 'C1', 'cliente_nombre' => 'Cliente',
            'temporada_id' => 'T1', 'temporada_codigo' => 'T1', 'ocurrido_at' => '2026-11-01T13:00:00+00:00', 'fecha_operacional' => '2026-11-01',
            'referencia' => 'REF', 'medidas' => ['kilos' => $kilos], 'datos' => ['proceso_id' => 'P1', 'tipo' => 'descontable'], ...$extra];
    }
}
