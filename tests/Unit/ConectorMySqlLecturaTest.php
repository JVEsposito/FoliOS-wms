<?php

namespace Tests\Unit;

use App\Services\Integraciones\ConectorMySqlLectura;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class ConectorMySqlLecturaTest extends TestCase
{
    public function test_las_muestras_amplian_su_presupuesto_solo_en_cola_y_con_un_limite_acotado(): void
    {
        $this->assertSame(60, ConectorMySqlLectura::segundosMuestra(true));
        foreach ([[15, 15], [60, 60], ['90', 90], [120, 120], [300, 120], [0, 15], [-30, 15], ['', 60], [null, 60], ['60s', 60]] as [$configurado, $esperado]) {
            $this->assertSame($esperado, ConectorMySqlLectura::segundosMuestra(true, $configurado));
            $this->assertSame(15, ConectorMySqlLectura::segundosMuestra(false, $configurado));
        }
    }

    public function test_solo_ejecuta_una_consulta_fija_dentro_de_una_transaccion_de_lectura(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('fetchColumn')->willReturn(1);
        $pdo->expects($this->once())->method('exec')->with('SET SESSION TRANSACTION READ ONLY')->willReturn(0);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('query')->with('SELECT 1')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $conector = $this->getMockBuilder(ConectorMySqlLectura::class)->onlyMethods(['abrir'])->getMock();
        $conector->method('abrir')->willReturn($pdo);
        $conector->probar([]);
    }

    public function test_libera_la_transaccion_si_falla_la_consulta(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('exec')->willReturn(0);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->method('query')->willThrowException(new PDOException('Falla simulada'));
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $conector = $this->getMockBuilder(ConectorMySqlLectura::class)->onlyMethods(['abrir'])->getMock();
        $conector->method('abrir')->willReturn($pdo);
        $this->expectException(PDOException::class);
        $conector->probar([]);
    }

    public function test_consultar_lee_dentro_de_una_transaccion_de_lectura_y_la_revierte(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->with(['AR01'])->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->with(PDO::FETCH_ASSOC)->willReturn([['Pallet' => '1']]);
        $pdo->expects($this->exactly(2))->method('exec')->willReturnCallback(function (string $sql): int {
            $this->assertContains($sql, ['SET SESSION TRANSACTION READ ONLY', 'SET SESSION MAX_EXECUTION_TIME=60000']);

            return 0;
        });
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('prepare')->with('SELECT `Pallet` FROM `odbc_stock` WHERE `cod_bodega` = ?')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $conector = $this->getMockBuilder(ConectorMySqlLectura::class)->onlyMethods(['abrir'])->getMock();
        $conector->method('abrir')->willReturn($pdo);

        $this->assertSame([['Pallet' => '1']], $conector->consultar([], 'SELECT `Pallet` FROM `odbc_stock` WHERE `cod_bodega` = ?', ['AR01']));
    }

    public function test_diagnostica_la_causa_sin_exponer_servidor_ni_usuario(): void
    {
        $casos = [
            [null, 'could not find driver', 'sin_controlador'],
            [2002, 'SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo for srv-privado failed', 'servidor_inaccesible'],
            [1045, "SQLSTATE[HY000] [1045] Access denied for user 'lector_privado'@'10.0.0.5'", 'acceso_denegado'],
            [1049, "SQLSTATE[HY000] [1049] Unknown database 'base_privada'", 'base_inexistente'],
            [1146, "SQLSTATE[42S02]: Table 'base_privada.odbc_stock' doesn't exist", 'tabla_inexistente'],
            [1142, "SQLSTATE[42000]: SELECT command denied to user 'lector_privado'", 'sin_permiso_tabla'],
            [3024, 'Query execution was interrupted, maximum statement execution time exceeded', 'tiempo_agotado'],
            [1969, 'Query execution was interrupted (max_statement_time exceeded)', 'tiempo_agotado'],
            [9999, 'otro error con srv-privado', 'error_desconocido'],
        ];
        foreach ($casos as [$codigo, $mensaje, $causa]) {
            $error = new PDOException($mensaje);
            $error->errorInfo = $codigo === null ? null : ['HY000', $codigo, $mensaje];
            $diagnostico = ConectorMySqlLectura::diagnosticar($error);
            $this->assertSame($causa, $diagnostico['causa'], $mensaje);
            foreach (['srv-privado', 'lector_privado', 'base_privada', '10.0.0.5'] as $privado) {
                $this->assertStringNotContainsString($privado, $diagnostico['mensaje']);
            }
        }

        $columna = new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'origen.Proceso' in 'field list'");
        $columna->errorInfo = ['42S22', 1054, "Unknown column 'origen.Proceso' in 'field list'"];
        $this->assertSame(
            ['causa' => 'columna_faltante', 'mensaje' => 'A la tabla de origen le falta la columna Proceso.', 'columna' => 'Proceso'],
            ConectorMySqlLectura::diagnosticar($columna),
        );
    }
}
