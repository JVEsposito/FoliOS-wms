<?php

namespace App\Services\Integraciones;

use PDO;
use SensitiveParameter;

/** Conexión independiente: nunca cambia la base operacional ni recibe SQL del navegador. */
class ConectorMySqlLectura
{
    public function probar(#[SensitiveParameter] array $credenciales): void
    {
        $pdo = $this->abrir($credenciales);

        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            $pdo->query('SELECT 1')->fetchColumn();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    protected function abrir(#[SensitiveParameter] array $credenciales): PDO
    {
        return new PDO(
            'mysql:host='.$credenciales['host'].';port='.(int) $credenciales['puerto'].';dbname='.$credenciales['base_datos'].';charset=utf8mb4',
            $credenciales['usuario'],
            $credenciales['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ],
        );
    }
}
