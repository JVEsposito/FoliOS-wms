<?php

namespace App\Services\Integraciones;

use DomainException;
use SensitiveParameter;

/** Descubre la base seleccionada por la conexión, con consultas propias y muestras acotadas. */
class ExploradorMySql
{
    public function __construct(private readonly ConectorMySqlLectura $conector) {}

    public function objetos(#[SensitiveParameter] array $credenciales): array
    {
        return $this->conector->consultar($credenciales,
            "SELECT TABLE_NAME AS nombre, TABLE_TYPE AS tipo FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE IN ('BASE TABLE', 'VIEW') ORDER BY TABLE_TYPE, TABLE_NAME", segundos: 15);
    }

    public function columnas(#[SensitiveParameter] array $credenciales, string $tabla): array
    {
        self::identificador($tabla);
        $columnas = $this->conector->consultar($credenciales,
            'SELECT COLUMN_NAME AS nombre, DATA_TYPE AS tipo, IS_NULLABLE AS nullable, COLUMN_KEY AS clave FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$tabla], 15);
        if ($columnas === []) {
            throw new DomainException('La fuente no está disponible en esta base o el usuario no tiene acceso a sus columnas.');
        }

        return $columnas;
    }

    public function muestra(#[SensitiveParameter] array $credenciales, string $tabla, array $seleccion, array $filtros = [], int $limite = 10, int $segundos = 15): array
    {
        $columnas = $this->columnas($credenciales, $tabla);
        if ($seleccion === [] || count($seleccion) > 40 || count(array_unique($seleccion)) !== count($seleccion) || $limite < 1 || $limite > 20) {
            throw new DomainException('Elija entre 1 y 40 columnas y una muestra de hasta 20 filas.');
        }
        $tipos = array_column($columnas, 'tipo', 'nombre');
        $campos = [];
        foreach ($seleccion as $columna) {
            if (! array_key_exists($columna, $tipos)) {
                throw new DomainException('Una columna seleccionada ya no está disponible. Actualice la estructura.');
            }
            $nombre = self::identificador($columna);
            $binaria = in_array(strtolower($tipos[$columna]), ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'geometry'], true);
            $campos[] = ($binaria ? "'[Dato binario]'" : "LEFT(CAST({$nombre} AS CHAR), 501)").' AS '.$nombre;
        }
        [$where, $parametros] = $this->condiciones($columnas, $filtros);
        $filas = $this->conector->consultar($credenciales, 'SELECT '.implode(', ', $campos).' FROM '.self::identificador($tabla).$where.' LIMIT '.$limite, $parametros, $segundos);
        $recortadas = false;
        foreach ($filas as &$fila) {
            foreach ($fila as &$valor) {
                if (is_string($valor) && mb_strlen($valor) > 500) {
                    $valor = mb_substr($valor, 0, 500).'…';
                    $recortadas = true;
                }
            }
            unset($valor);
        }
        unset($fila);

        return ['columnas' => $seleccion, 'filas' => $filas, 'limite' => $limite, 'valores_recortados' => $recortadas];
    }

    /** @return array{string, array} */
    public function condiciones(array $columnas, array $filtros): array
    {
        if (count($filtros) > 8) {
            throw new DomainException('Puede configurar hasta ocho filtros de igualdad.');
        }
        $conocidas = array_column($columnas, 'nombre');
        $condiciones = [];
        $parametros = [];
        foreach ($filtros as $filtro) {
            $campo = $filtro['columna'] ?? null;
            $valor = $filtro['valor'] ?? null;
            if (! is_string($campo) || ! in_array($campo, $conocidas, true) || ! is_scalar($valor) || mb_strlen((string) $valor) > 180) {
                throw new DomainException('Cada filtro debe usar una columna disponible y un valor de hasta 180 caracteres.');
            }
            $condiciones[] = self::identificador($campo).' = ?';
            $parametros[] = (string) $valor;
        }

        return [$condiciones === [] ? '' : ' WHERE '.implode(' AND ', $condiciones), $parametros];
    }

    public static function identificador(string $nombre): string
    {
        if (preg_match('/^[\p{L}\p{N}_$ -]{1,64}$/u', $nombre) !== 1) {
            throw new DomainException('El nombre de fuente o columna no es compatible con el explorador.');
        }

        return '`'.$nombre.'`';
    }
}
