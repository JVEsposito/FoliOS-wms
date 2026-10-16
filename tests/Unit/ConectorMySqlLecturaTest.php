<?php

namespace Tests\Unit;

use App\Services\Integraciones\ConectorMySqlLectura;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class ConectorMySqlLecturaTest extends TestCase
{
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
}
