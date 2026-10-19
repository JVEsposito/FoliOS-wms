<?php

namespace App\Finanzas;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base de datos propia de Finanzas. Sus tablas viven fuera del esquema operativo y nunca
 * tienen llaves foráneas hacia él: guardan la referencia y una copia de lo leído.
 */
final class EsquemaFinanzas
{
    public const CONEXION = 'finanzas';

    /** Primera migración con tablas de Finanzas: si no está registrada, ninguna lo está. */
    public const MIGRACION_INICIAL = '2026_10_17_100100_crear_captura_hechos_finanzas';

    public function conexion(): ConnectionInterface
    {
        return DB::connection(self::CONEXION);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    public function transaccion(callable $operacion): mixed
    {
        return $this->conexion()->transaction($operacion);
    }

    public function nombreBase(): string
    {
        return (string) $this->configuracion()['database'];
    }

    /** La URL tiene la misma precedencia que al abrir una conexión con Laravel. */
    private function configuracion(): array
    {
        return (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.'.self::CONEXION));
    }

    /**
     * Crea la base de Finanzas en el servidor si todavía no existe. La llama la primera
     * migración de Finanzas; un usuario sin permiso CREATE puede crearla a mano antes.
     */
    public function asegurarBase(): void
    {
        $config = $this->configuracion();
        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return;
        }

        $nombre = $this->nombreBase();
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $nombre) !== 1) {
            throw new RuntimeException("El nombre de la base de Finanzas no es válido: «{$nombre}». Use letras, números y guion bajo.");
        }

        // Conexión al mismo servidor sin elegir base: la de Finanzas puede no existir aún.
        $servidor = 'finanzas_servidor';
        config(["database.connections.{$servidor}" => [...$config, 'database' => null]]);

        try {
            $existe = DB::connection($servidor)
                ->table('information_schema.SCHEMATA')
                ->where('SCHEMA_NAME', $nombre)
                ->exists();

            if (! $existe) {
                $charset = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($config['charset'] ?? 'utf8mb4'));
                $collation = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($config['collation'] ?? 'utf8mb4_unicode_ci'));
                DB::connection($servidor)->statement(
                    "CREATE DATABASE IF NOT EXISTS `{$nombre}` CHARACTER SET {$charset} COLLATE {$collation}",
                );
            }
        } catch (QueryException $error) {
            throw new RuntimeException(
                "No fue posible preparar la base de Finanzas «{$nombre}». Créela a mano o dé permiso CREATE al usuario de la conexión «finanzas» (docs/FINANZAS.md). Detalle: {$error->getMessage()}",
                previous: $error,
            );
        } finally {
            DB::purge($servidor);
            config(["database.connections.{$servidor}" => null]);
            // La conexión de Finanzas pudo intentarse antes de que la base existiera.
            DB::purge(self::CONEXION);
        }
    }

    /**
     * Borra las tablas de Finanzas. Solo acompaña a un borrado completo de la base
     * operativa (migrate:fresh o db:wipe), porque el registro de migraciones vive en la base
     * operativa y, sin él, las tablas quedarían huérfanas.
     */
    public function vaciar(): void
    {
        try {
            $this->borrar($this->tablas());
        } finally {
            DB::purge(self::CONEXION);
        }
    }

    /**
     * Antes de migrar: si la base de Finanzas tiene tablas que el registro de migraciones no
     * conoce, son restos de una base operativa borrada. Vacías, se retiran para que las
     * migraciones las vuelvan a crear; con datos, la migración se detiene para no perderlos.
     *
     * @param  list<string>  $migracionesCorridas
     */
    public function retirarHuerfanas(array $migracionesCorridas): void
    {
        if (in_array(self::MIGRACION_INICIAL, $migracionesCorridas, true)) {
            return;
        }

        $tablas = $this->tablas();
        foreach ($tablas as $tabla) {
            if ($this->conexion()->table($tabla)->exists()) {
                throw new RuntimeException(
                    "La base de Finanzas «{$this->nombreBase()}» tiene datos, pero la base operativa no registra sus migraciones. "
                    .'Restaure ambas bases del mismo respaldo o, si esos datos ya no sirven, vacíe la base de Finanzas a mano (docs/FINANZAS.md).',
                );
            }
        }

        $this->borrar($tablas);
    }

    /**
     * Candado de la base de Finanzas (GET_LOCK de MySQL): no escribe en ninguna tabla y se
     * libera solo si el proceso termina. Sin espera, falla si otro proceso lo tiene.
     */
    public function tomarCandado(string $nombre, int $esperaSegundos = 0): bool
    {
        return (int) $this->conexion()->scalar('SELECT GET_LOCK(?, ?)', [$this->nombreCandado($nombre), max(0, $esperaSegundos)]) === 1;
    }

    public function liberarCandado(string $nombre): void
    {
        $this->conexion()->scalar('SELECT RELEASE_LOCK(?)', [$this->nombreCandado($nombre)]);
    }

    /** Los nombres de GET_LOCK son del servidor: se distinguen por base y no pasan de 64. */
    public function nombreCandado(string $nombre): string
    {
        return mb_substr(hash('crc32b', $this->nombreBase()).":finanzas:{$nombre}", 0, 64);
    }

    /** @return list<string> Tablas de Finanzas existentes (vacío si la base aún no existe). */
    private function tablas(): array
    {
        try {
            $tablas = array_column($this->conexion()->getSchemaBuilder()->getTables($this->nombreBase()), 'name');
        } catch (QueryException $error) {
            // 1049: la base de Finanzas todavía no existe; no hay tablas.
            if ((int) ($error->errorInfo[1] ?? 0) === 1049) {
                return [];
            }

            throw $error;
        }

        return array_values(array_filter($tablas, fn (string $tabla): bool => str_starts_with($tabla, 'fin_')));
    }

    /** @param  list<string>  $tablas */
    private function borrar(array $tablas): void
    {
        if ($tablas === []) {
            return;
        }

        $esquema = $this->conexion()->getSchemaBuilder();
        $esquema->withoutForeignKeyConstraints(function () use ($esquema, $tablas): void {
            foreach ($tablas as $tabla) {
                $esquema->dropIfExists($tabla);
            }
        });
    }
}
