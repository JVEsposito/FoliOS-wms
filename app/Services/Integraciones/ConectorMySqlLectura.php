<?php

namespace App\Services\Integraciones;

use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

/** Conexión independiente: nunca cambia la base operacional ni recibe SQL del navegador. */
class ConectorMySqlLectura
{
    /** Mensajes por causa, sin servidor, usuario ni clave. */
    public const CAUSAS = [
        'sin_controlador' => 'PHP no tiene habilitado pdo_mysql en el servidor de FoliOS.',
        'servidor_inaccesible' => 'El servidor de FoliOS no llega a la base de origen. Revisa la IP, el puerto y el firewall.',
        'acceso_denegado' => 'La base de origen rechazó el usuario o la contraseña.',
        'sin_permiso_base' => 'El usuario no tiene permiso sobre esa base de datos.',
        'base_inexistente' => 'La base de datos indicada no existe en el servidor de origen.',
        'tabla_inexistente' => 'La tabla o vista configurada no existe en la base de origen.',
        'sin_permiso_tabla' => 'El usuario no tiene permiso de lectura sobre la tabla o vista configurada.',
        'columna_faltante' => 'A la tabla de origen le falta una columna esperada.',
        'tiempo_agotado' => 'La consulta a la base de origen tardó demasiado.',
        'error_desconocido' => 'La base de origen respondió con un error no reconocido.',
    ];

    /** Amplía solo las muestras en cola; deja margen bajo los 180 s del worker. */
    public static function segundosMuestra(bool $enCola, mixed $configurados = 60): int
    {
        if (! $enCola) {
            return 15;
        }

        $segundos = filter_var($configurados, FILTER_VALIDATE_INT);

        return max(15, min(120, $segundos === false ? 60 : $segundos));
    }

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

    /**
     * Ejecuta una consulta SELECT fija (armada por FoliOS, nunca por el navegador) dentro de
     * una transacción de solo lectura que se revierte al terminar.
     *
     * @param  array<int|string, scalar|null>  $parametros
     * @return list<array<string, mixed>>
     */
    public function consultar(#[SensitiveParameter] array $credenciales, string $sql, array $parametros = [], int $segundos = 60): array
    {
        $pdo = $this->abrir($credenciales);

        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            try {
                // MySQL 5.7+; MariaDB usa max_statement_time (segundos).
                $pdo->exec('SET SESSION MAX_EXECUTION_TIME='.max(1, $segundos) * 1000);
            } catch (PDOException) {
                try {
                    $pdo->exec('SET SESSION max_statement_time='.max(1, $segundos));
                } catch (PDOException) {
                    // Servidores antiguos: el proceso exclusivo mantiene aislada la espera.
                }
            }
            $pdo->beginTransaction();
            $consulta = $pdo->prepare($sql);
            $consulta->execute($parametros);

            return $consulta->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * Clasifica un error del controlador sin exponer su texto (que incluye servidor, usuario
     * y DSN). Devuelve la causa y, si es una columna, su nombre: viene de la consulta de FoliOS.
     *
     * @return array{causa: string, mensaje: string, columna?: string}
     */
    public static function diagnosticar(Throwable $error): array
    {
        $codigo = $error instanceof PDOException
            ? (int) ($error->errorInfo[1] ?? (is_numeric($error->getCode()) ? $error->getCode() : 0))
            : 0;
        $causa = match (true) {
            str_contains(strtolower($error->getMessage()), 'could not find driver') => 'sin_controlador',
            in_array($codigo, [2002, 2003, 2005, 2006, 2013], true) => 'servidor_inaccesible',
            $codigo === 1045 => 'acceso_denegado',
            $codigo === 1044 => 'sin_permiso_base',
            $codigo === 1049 => 'base_inexistente',
            $codigo === 1146 => 'tabla_inexistente',
            in_array($codigo, [1142, 1143], true) => 'sin_permiso_tabla',
            $codigo === 1054 => 'columna_faltante',
            in_array($codigo, [3024, 1969], true) => 'tiempo_agotado',
            default => 'error_desconocido',
        };
        $resultado = ['causa' => $causa, 'mensaje' => self::CAUSAS[$causa]];
        if ($causa === 'columna_faltante' && preg_match("/Unknown column '(?:[^'.]+\\.)?([A-Za-z0-9_]+)'/", $error->getMessage(), $columna)) {
            $resultado['columna'] = $columna[1];
            $resultado['mensaje'] = "A la tabla de origen le falta la columna {$columna[1]}.";
        }

        return $resultado;
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
