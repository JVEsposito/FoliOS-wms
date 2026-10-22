<?php

namespace Tests\Unit;

use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ExploradorMySql;
use App\Services\Integraciones\MapeoStockOrigen;
use DomainException;
use PHPUnit\Framework\TestCase;

class MapeoStockOrigenTest extends TestCase
{
    private function mapeo(): MapeoStockOrigen
    {
        return new MapeoStockOrigen(new ExploradorMySql($this->createMock(ConectorMySqlLectura::class)));
    }

    public function test_admite_nombres_distintos_sin_imponer_filtros_y_usa_parametros(): void
    {
        $columnas = [['nombre' => 'Número real', 'tipo' => 'varchar'], ['nombre' => 'unidades', 'tipo' => 'decimal']];
        $ajustes = ['tabla' => 'Existencias actuales', 'campos' => ['Pallet' => 'Número real', 'cantidad' => 'unidades'], 'filtros' => []];
        [$sql, $parametros] = $this->mapeo()->consulta($ajustes, $columnas);
        $this->assertStringContainsString('`Número real` AS CHAR), 501) AS `Pallet`', $sql);
        $this->assertStringContainsString('NULL AS `descripcion_especie`', $sql);
        $this->assertStringEndsWith('FROM `Existencias actuales` LIMIT 50001', $sql);
        $this->assertStringNotContainsString(' WHERE ', $sql);
        $this->assertSame([], $parametros);
        $ajustes['filtros'] = [['columna' => 'Número real', 'valor' => "a' OR 1=1 --"]];
        [$sql, $parametros] = $this->mapeo()->consulta($ajustes, $columnas, true);
        $this->assertStringEndsWith('WHERE `Número real` = ? LIMIT 10', $sql);
        $this->assertSame(["a' OR 1=1 --"], $parametros);
    }

    public function test_no_acepta_columnas_inexistentes_binarias_ni_campos_arbitrarios(): void
    {
        foreach ([['Pallet' => 'desconocida', 'cantidad' => 'cajas'], ['Pallet' => 'archivo', 'cantidad' => 'cajas'], ['Pallet' => 'cajas'], ['Pallet' => 'cajas', 'cantidad' => 'cajas', 'sql' => 'cajas']] as $campos) {
            try {
                $this->mapeo()->consulta(['tabla' => 'vista', 'campos' => $campos, 'filtros' => []], [['nombre' => 'cajas', 'tipo' => 'int'], ['nombre' => 'archivo', 'tipo' => 'blob']]);
                $this->fail('Aceptó un mapeo inválido.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_los_folios_y_cantidades_invalidos_no_se_omiten_ni_se_truncan(): void
    {
        $mapeo = $this->mapeo();
        $mapeo->validarFilas([['Pallet' => '0000123', 'cantidad' => '120.000', 'kilos_netos' => '600.50']]);
        foreach ([['Pallet' => '', 'cantidad' => 1], ['Pallet' => str_repeat('a', 51), 'cantidad' => 1], ['Pallet' => '123', 'cantidad' => null], ['Pallet' => '123', 'cantidad' => 'cajas'], ['Pallet' => '123', 'cantidad' => -1], ['Pallet' => '123', 'cantidad' => '1.5'], ['Pallet' => '123', 'cantidad' => 1, 'kilos_netos' => -1]] as $fila) {
            try {
                $mapeo->validarFilas([$fila]);
                $this->fail('Aceptó una fila inválida.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(DomainException::class);
        $mapeo->validarFilas(array_fill(0, MapeoStockOrigen::MAX_FILAS + 1, ['Pallet' => '123', 'cantidad' => 1]));
    }
}
