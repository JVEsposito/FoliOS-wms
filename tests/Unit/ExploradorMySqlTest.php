<?php

namespace Tests\Unit;

use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ExploradorMySql;
use DomainException;
use PHPUnit\Framework\TestCase;

class ExploradorMySqlTest extends TestCase
{
    public function test_descubre_solo_la_base_configurada_sin_pedir_la_definicion_de_vistas(): void
    {
        $conector = $this->createMock(ConectorMySqlLectura::class);
        $conector->expects($this->once())->method('consultar')->willReturnCallback(function ($credenciales, $sql, $parametros = [], $segundos = 60) {
            $this->assertStringContainsString('TABLE_SCHEMA = DATABASE()', $sql);
            $this->assertStringContainsString("'VIEW'", $sql);
            $this->assertStringNotContainsString('SHOW', $sql);
            $this->assertSame(15, $segundos);

            return [['nombre' => 'vista_existencias', 'tipo' => 'VIEW']];
        });
        $this->assertSame('vista_existencias', (new ExploradorMySql($conector))->objetos([])[0]['nombre']);
    }

    public function test_la_muestra_recorta_valores_omite_binarios_y_parametriza_los_filtros(): void
    {
        $conector = $this->createMock(ConectorMySqlLectura::class);
        $conector->expects($this->exactly(2))->method('consultar')->willReturnCallback(function ($credenciales, $sql, $parametros, $segundos) {
            $this->assertSame(15, $segundos);
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                $this->assertSame(['Vista de stock'], $parametros);

                return [['nombre' => 'Folio real', 'tipo' => 'varchar'], ['nombre' => 'archivo', 'tipo' => 'blob']];
            }
            $this->assertStringContainsString('LEFT(CAST(`Folio real` AS CHAR), 501)', $sql);
            $this->assertStringContainsString("'[Dato binario]' AS `archivo`", $sql);
            $this->assertStringContainsString('FROM `Vista de stock` WHERE `Folio real` = ? LIMIT 10', $sql);
            $this->assertStringNotContainsString('DROP', $sql);
            $this->assertSame(["x'; DROP TABLE ejemplo; --"], $parametros);

            return [['Folio real' => str_repeat('a', 501), 'archivo' => '[Dato binario]']];
        });
        $resultado = (new ExploradorMySql($conector))->muestra([], 'Vista de stock', ['Folio real', 'archivo'], [['columna' => 'Folio real', 'valor' => "x'; DROP TABLE ejemplo; --"]]);
        $this->assertTrue($resultado['valores_recortados']);
        $this->assertSame(501, mb_strlen($resultado['filas'][0]['Folio real']));
    }

    public function test_el_presupuesto_de_muestra_no_amplia_la_consulta_de_columnas(): void
    {
        $conector = $this->createMock(ConectorMySqlLectura::class);
        $conector->expects($this->exactly(2))->method('consultar')->willReturnCallback(function ($credenciales, $sql, $parametros, $segundos) {
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                $this->assertSame(15, $segundos);

                return [['nombre' => 'Pallet', 'tipo' => 'varchar']];
            }
            $this->assertSame(60, $segundos);
            $this->assertStringContainsString('WHERE `Pallet` = ? LIMIT 5', $sql);
            $this->assertSame(['21'], $parametros);

            return [['Pallet' => '21']];
        });

        $resultado = (new ExploradorMySql($conector))->muestra([], 'odbc_stock', ['Pallet'], [['columna' => 'Pallet', 'valor' => '21']], 5, 60);
        $this->assertSame([['Pallet' => '21']], $resultado['filas']);
    }

    public function test_rechaza_nombres_ajenos_a_la_base_y_columnas_no_descubiertas(): void
    {
        foreach (['otra_base.tabla', 'tabla` UNION SELECT 1', 'tabla; DROP TABLE x'] as $nombre) {
            try {
                ExploradorMySql::identificador($nombre);
                $this->fail('Aceptó un identificador ajeno.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $conector = $this->createMock(ConectorMySqlLectura::class);
        $conector->expects($this->once())->method('consultar')->willReturn([['nombre' => 'folio', 'tipo' => 'varchar']]);
        $this->expectException(DomainException::class);
        (new ExploradorMySql($conector))->muestra([], 'vista', ['otra_columna']);
    }
}
