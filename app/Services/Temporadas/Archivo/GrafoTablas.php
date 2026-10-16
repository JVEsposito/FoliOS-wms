<?php

namespace App\Services\Temporadas\Archivo;

use Illuminate\Support\Facades\Schema;

/**
 * Estructura relacional leída del esquema: tablas, clave primaria y llaves foráneas de una
 * columna. El archivo de temporada se deriva de este grafo para no depender de una lista
 * mantenida a mano que quede desactualizada con cada migración.
 */
class GrafoTablas
{
    /** @var array<string, array{pk: ?string, columnas: array<int, string>, fks: array<int, array{columna: string, tabla: string, referencia: string}>}> */
    private array $tablas = [];

    /** @param array<int, string> $excluidas */
    public function __construct(array $excluidas)
    {
        // Solo la base de FoliOS: sin esquema, MySQL lista las tablas de todas las bases del servidor.
        foreach (Schema::getTables(Schema::getCurrentSchemaName()) as $tabla) {
            $nombre = $tabla['name'];
            if (in_array($nombre, $excluidas, true) || str_starts_with($nombre, 'bench_')) {
                continue;
            }

            $primaria = collect(Schema::getIndexes($nombre))->firstWhere('primary', true);
            $this->tablas[$nombre] = [
                'pk' => $primaria && count($primaria['columns']) === 1 ? $primaria['columns'][0] : null,
                'columnas' => collect(Schema::getColumns($nombre))->pluck('name')->all(),
                'fks' => [],
            ];
        }

        foreach (array_keys($this->tablas) as $nombre) {
            foreach (Schema::getForeignKeys($nombre) as $fk) {
                if (count($fk['columns']) !== 1 || count($fk['foreign_columns']) !== 1) {
                    continue;
                }

                $this->tablas[$nombre]['fks'][] = [
                    'columna' => $fk['columns'][0],
                    'tabla' => $fk['foreign_table'],
                    'referencia' => $fk['foreign_columns'][0],
                ];
            }
        }
    }

    /** @return array<int, string> */
    public function nombres(): array
    {
        return array_keys($this->tablas);
    }

    public function existe(string $tabla): bool
    {
        return isset($this->tablas[$tabla]);
    }

    public function clavePrimaria(string $tabla): ?string
    {
        return $this->tablas[$tabla]['pk'] ?? null;
    }

    /** @return array<int, string> */
    public function columnas(string $tabla): array
    {
        return $this->tablas[$tabla]['columnas'] ?? [];
    }

    /** @return array<int, array{columna: string, tabla: string, referencia: string}> */
    public function llavesForaneas(string $tabla): array
    {
        return $this->tablas[$tabla]['fks'] ?? [];
    }
}
