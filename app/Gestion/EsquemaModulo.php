<?php

namespace App\Gestion;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base de datos propia de un módulo de gestión (Finanzas, Comex...). Sus tablas viven fuera
 * del esquema operativo, llevan el prefijo del módulo y nunca tienen llaves foráneas hacia la
 * operación: guardan la referencia y una copia de lo leído.
 *
 * El registro de migraciones vive en la base operativa. Por eso, al reiniciarla, el módulo
 * se vacía con ella, y si el registro no conoce sus tablas se retiran solo si están vacías.
 */
abstract class EsquemaModulo
{
    /** Nombre para mensajes: «Finanzas», «Comex». */
    abstract public function nombre(): string;

    /** Conexión de la base del módulo (config/database.php). */
    abstract public function conexionNombre(): string;

    /** Prefijo de sus tablas, por ejemplo «fin_». */
    abstract public function prefijo(): string;

    /** Primera migración con tablas del módulo: si no está registrada, ninguna lo está. */
    abstract public function migracionInicial(): string;

    /** Conexión operativa cuyo registro de migraciones gobierna al módulo. */
    abstract public function conexionOperacion(): string;

    /** Documento con la instalación del módulo, para los mensajes de error. */
    abstract protected function documentacion(): string;

    public function conexion(): ConnectionInterface
    {
        return DB::connection($this->conexionNombre());
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
        return (new ConfigurationUrlParser)->parseConfiguration(config('database.connections.'.$this->conexionNombre()));
    }

    /**
     * Crea la base del módulo en el servidor si todavía no existe. La llama su primera
     * migración; un usuario sin permiso CREATE puede crearla a mano antes.
     */
    public function asegurarBase(): void
    {
        $config = $this->configuracion();
        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return;
        }

        $nombre = $this->nombreBase();
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $nombre) !== 1) {
            throw new RuntimeException("El nombre de la base de {$this->nombre()} no es válido: «{$nombre}». Use letras, números y guion bajo.");
        }

        // Conexión al mismo servidor sin elegir base: la del módulo puede no existir aún.
        $servidor = $this->conexionNombre().'_servidor';
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
                "No fue posible preparar la base de {$this->nombre()} «{$nombre}». Créela a mano o dé permiso CREATE al usuario de la conexión «{$this->conexionNombre()}» ({$this->documentacion()}). Detalle: {$error->getMessage()}",
                previous: $error,
            );
        } finally {
            DB::purge($servidor);
            config(["database.connections.{$servidor}" => null]);
            // La conexión del módulo pudo intentarse antes de que la base existiera.
            DB::purge($this->conexionNombre());
        }
    }

    /**
     * Borra las tablas del módulo. Solo acompaña a un borrado completo de la base operativa
     * (migrate:fresh o db:wipe), porque el registro de migraciones vive en la base operativa y,
     * sin él, las tablas quedarían huérfanas.
     */
    public function vaciar(): void
    {
        try {
            $this->borrar($this->tablas());
        } finally {
            DB::purge($this->conexionNombre());
        }
    }

    /**
     * Antes de migrar: si la base del módulo tiene tablas que el registro de migraciones no
     * conoce, son restos de una base operativa borrada. Vacías, se retiran para que las
     * migraciones las vuelvan a crear; con datos, la migración se detiene para no perderlos.
     *
     * @param  list<string>  $migracionesCorridas
     */
    public function retirarHuerfanas(array $migracionesCorridas): void
    {
        if (in_array($this->migracionInicial(), $migracionesCorridas, true)) {
            return;
        }

        $tablas = $this->tablas();
        foreach ($tablas as $tabla) {
            if ($this->conexion()->table($tabla)->exists()) {
                throw new RuntimeException(
                    "La base de {$this->nombre()} «{$this->nombreBase()}» tiene datos, pero la base operativa no registra sus migraciones. "
                    ."Restaure ambas bases del mismo respaldo o, si esos datos ya no sirven, vacíe la base de {$this->nombre()} a mano ({$this->documentacion()}).",
                );
            }
        }

        $this->borrar($tablas);
    }

    /**
     * Candado de la base del módulo (GET_LOCK de MySQL): no escribe en ninguna tabla y se
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
        return mb_substr(hash('crc32b', $this->nombreBase()).":{$this->conexionNombre()}:{$nombre}", 0, 64);
    }

    /** @return list<string> Tablas del módulo existentes (vacío si la base aún no existe). */
    private function tablas(): array
    {
        try {
            $tablas = array_column($this->conexion()->getSchemaBuilder()->getTables($this->nombreBase()), 'name');
        } catch (QueryException $error) {
            // 1049: la base del módulo todavía no existe; no hay tablas.
            if ((int) ($error->errorInfo[1] ?? 0) === 1049) {
                return [];
            }

            throw $error;
        }

        return array_values(array_filter($tablas, fn (string $tabla): bool => str_starts_with($tabla, $this->prefijo())));
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
