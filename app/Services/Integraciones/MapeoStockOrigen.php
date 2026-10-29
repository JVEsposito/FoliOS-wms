<?php

namespace App\Services\Integraciones;

use DomainException;

/** Traduce columnas descubiertas al contrato de la copia de stock; nunca recibe SQL. */
class MapeoStockOrigen
{
    public const MAX_FILAS = 50000;

    public const CAMPOS = [
        'Pallet' => 'Folio / número de pallet', 'cantidad' => 'Cantidad de cajas',
        'cod_empresa' => 'Código de cliente (exportadora)', 'cod_temporada' => 'Código de temporada', 'temporada' => 'Temporada',
        'cod_articulo' => 'Código de envase', 'descripcion_articulo' => 'Envase', 'peso_neto_articulo' => 'Kilos por envase',
        'cod_especie' => 'Código de especie', 'descripcion_especie' => 'Especie', 'codigo_entidad' => 'Código de productor',
        'razon_social_entidad' => 'Productor', 'variedad' => 'Variedad', 'codigo_variedad' => 'Código de variedad',
        'lote_recepcion' => 'Lote de recepción', 'cod_calibre' => 'Calibre', 'cod_categoria' => 'Categoría',
        'codigo_csg_predio' => 'CSG', 'descripcion_predio' => 'Predio', 'fecha_embalaje' => 'Fecha de embalaje',
        'kilos_netos' => 'Kilos netos', 'cod_bodega' => 'Bodega', 'origen_i' => 'Origen',
        'cod_estado_stock_paletizado' => 'Estado del bulto (PC / PI)', 'marca' => 'Marca', 'Proceso' => 'Proceso',
        'fecha_recepcion' => 'Fecha de recepción', 'disponibilidad' => 'Disponibilidad en origen (sin interpretar)',
    ];

    public function __construct(private readonly ExploradorMySql $explorador) {}

    /** @return array{string, array} */
    public function consulta(array $ajustes, array $columnas, bool $muestra = false, ?string $numeroPallet = null): array
    {
        $tabla = ExploradorMySql::identificador($ajustes['tabla']);
        $mapa = $ajustes['campos'];
        if (array_diff(array_keys($mapa), array_keys(self::CAMPOS)) !== [] || ($mapa['Pallet'] ?? '') === '' || ($mapa['cantidad'] ?? '') === '') {
            throw new DomainException('Asigne al menos las columnas de folio y cantidad de cajas.');
        }
        $tipos = array_column($columnas, 'tipo', 'nombre');
        $seleccion = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $columna = $mapa[$campo] ?? null;
            $expresion = 'NULL';
            if ($columna !== null && $columna !== '') {
                if (! is_string($columna) || ! array_key_exists($columna, $tipos) || in_array(strtolower($tipos[$columna]), ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'geometry'], true)) {
                    throw new DomainException('Una columna asignada no está disponible o contiene datos binarios. Actualice la estructura.');
                }
                $expresion = 'LEFT(CAST('.ExploradorMySql::identificador($columna).' AS CHAR), 501)';
            }
            $seleccion[] = $expresion.' AS '.ExploradorMySql::identificador($campo);
        }
        [$where, $parametros] = $this->explorador->condiciones($columnas, $ajustes['filtros']);
        if ($numeroPallet !== null) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ')
                .'UPPER(TRIM(CAST('.ExploradorMySql::identificador($mapa['Pallet']).' AS CHAR))) = ?';
            $parametros[] = $numeroPallet;
        }

        return ['SELECT '.implode(', ', $seleccion).' FROM '.$tabla.$where.' LIMIT '.($muestra ? 10 : self::MAX_FILAS + 1), $parametros];
    }

    public function validarFilas(array $filas): void
    {
        if (count($filas) > self::MAX_FILAS) {
            throw new DomainException('La lectura supera 50.000 líneas. Acote la fuente con los filtros antes de importar.');
        }
        foreach ($filas as $indice => $fila) {
            $fila = array_change_key_case($fila, CASE_LOWER);
            $folio = trim((string) ($fila['pallet'] ?? ''));
            if ($folio === '' || mb_strlen(mb_strtoupper($folio)) > 50 || preg_match('/[\x00-\x1F\x7F]/u', $folio)) {
                throw new DomainException('La fila '.($indice + 1).' no tiene un folio válido de hasta 50 caracteres.');
            }
            $cajas = trim((string) ($fila['cantidad'] ?? ''));
            if (! preg_match('/^\d+(?:\.0+)?$/D', $cajas) || (float) $cajas > 4294967295) {
                throw new DomainException('La fila '.($indice + 1).' debe tener una cantidad entera de cajas, mayor o igual a cero.');
            }
            foreach (['kilos_netos' => 9999999999.99, 'peso_neto_articulo' => 99999.999] as $campo => $maximo) {
                $valor = $fila[$campo] ?? null;
                if ($valor !== null && $valor !== '' && (! is_numeric($valor) || ! is_finite((float) $valor) || (float) $valor < 0 || (float) $valor > $maximo)) {
                    throw new DomainException('La fila '.($indice + 1).' tiene un valor inválido en '.self::CAMPOS[$campo].'.');
                }
            }
        }
    }
}
