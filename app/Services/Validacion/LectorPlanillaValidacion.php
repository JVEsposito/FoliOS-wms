<?php

namespace App\Services\Validacion;

use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use SimpleXMLElement;
use ZipArchive;

class LectorPlanillaValidacion
{
    /**
     * @return array<int, array<string, string|int>>
     */
    public function leer(UploadedFile $archivo): array
    {
        $extension = mb_strtolower($archivo->getClientOriginalExtension());

        foreach ($this->hojas($archivo->getRealPath(), $extension, acotar: false) as $hoja) {
            return $this->convertirFilas(array_values($hoja['filas']));
        }

        return [];
    }

    /**
     * Reutiliza la lectura tabular para libros con más de una hoja o títulos antes de las cabeceras.
     *
     * @return iterable<array{nombre: string, filas: array<int, list<string>>, fecha_1904: bool}>
     */
    public function hojas(string $ruta, string $extension, bool $acotar = true): iterable
    {
        if (in_array($extension, ['csv', 'txt'], true)) {
            yield ['nombre' => '', 'filas' => $this->leerCsv($ruta, $acotar), 'fecha_1904' => false];

            return;
        }
        if ($extension !== 'xlsx') {
            throw new DomainException('El archivo debe estar en formato CSV o XLSX.');
        }
        yield from $this->leerXlsx($ruta, $acotar);
    }

    /**
     * @return array<int, list<string>>
     */
    private function leerCsv(string $ruta, bool $variasLineas): array
    {
        $manejador = fopen($ruta, 'rb');
        if ($manejador === false) {
            throw new DomainException('No fue posible abrir la planilla cargada.');
        }

        try {
            $primeraLinea = fgets($manejador);
            if ($primeraLinea === false) {
                return [];
            }

            $muestra = $primeraLinea.($variasLineas ? fread($manejador, 8192) : '');
            $delimitador = $this->detectarDelimitador($muestra);
            rewind($manejador);
            $filas = [];

            while (($fila = fgetcsv($manejador, 0, $delimitador, '"', '\\')) !== false) {
                $filas[count($filas) + 1] = array_map(
                    fn ($valor): string => trim((string) $valor),
                    $fila,
                );
            }

            return $filas;
        } finally {
            fclose($manejador);
        }
    }

    /**
     * @return iterable<array{nombre: string, filas: array<int, list<string>>, fecha_1904: bool}>
     */
    private function leerXlsx(string $ruta, bool $acotar): iterable
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('El servidor no posee la extensión ZIP necesaria para leer XLSX.');
        }

        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new DomainException('No fue posible abrir el archivo XLSX.');
        }

        try {
            $compartidos = $this->leerTextosCompartidos($zip, $acotar);
            $libro = $this->xml($zip, 'xl/workbook.xml', $acotar);
            $fecha1904 = in_array((string) ($libro?->workbookPr['date1904'] ?? ''), ['1', 'true'], true);
            foreach ($this->rutasHojas($zip, $libro, $acotar) as [$nombre, $ruta]) {
                $hoja = $this->xml($zip, $ruta, $acotar);
                if (! $hoja) {
                    throw new DomainException('La planilla XLSX no contiene una hoja legible.');
                }
                yield ['nombre' => $nombre, 'filas' => $this->filasXlsx($hoja, $compartidos, $acotar), 'fecha_1904' => $fecha1904];
            }
        } finally {
            $zip->close();
        }
    }

    private function filasXlsx(SimpleXMLElement $hoja, array $compartidos, bool $acotar): array
    {
        $filas = [];
        $cantidadCeldas = 0;
        foreach ($hoja->sheetData->row ?? [] as $filaXml) {
            $fila = [];
            foreach ($filaXml->c as $celda) {
                $indice = $this->indiceColumna((string) $celda['r']);
                $tipo = (string) $celda['t'];
                $valor = (string) $celda->v;
                $fila[$indice] = trim(match ($tipo) {
                    'inlineStr' => $this->textoNodo($celda->is),
                    's' => $compartidos[(int) $valor] ?? '',
                    default => $valor,
                });
            }
            if ($fila === []) {
                continue;
            }
            $columnas = max(array_keys($fila)) + 1;
            $cantidadCeldas += $columnas;
            if ($acotar && $cantidadCeldas > 1000000) {
                throw new DomainException('La hoja XLSX supera el límite de celdas que puede procesar FoliOS.');
            }
            $numero = (int) $filaXml['r'] ?: count($filas) + 1;
            $filas[$numero] = array_map(fn (int $i): string => $fila[$i] ?? '', range(0, $columnas - 1));
        }

        return $filas;
    }

    /**
     * @return array<int, string>
     */
    private function leerTextosCompartidos(ZipArchive $zip, bool $acotar): array
    {
        $textos = [];
        foreach ($this->xml($zip, 'xl/sharedStrings.xml', $acotar)?->si ?? [] as $item) {
            $textos[] = $this->textoNodo($item);
        }

        return $textos;
    }

    private function rutasHojas(ZipArchive $zip, ?SimpleXMLElement $libro, bool $acotar): array
    {
        $destinos = [];
        foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels', $acotar)?->Relationship ?? [] as $relacion) {
            $destino = ltrim((string) $relacion['Target'], '/');
            $destinos[(string) $relacion['Id']] = str_starts_with($destino, 'xl/') ? $destino : 'xl/'.$destino;
        }
        $hojas = [];
        foreach ($libro?->sheets->sheet ?? [] as $hoja) {
            $atributos = $hoja->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $ruta = $destinos[(string) ($atributos['id'] ?? '')] ?? '';
            if (str_starts_with($ruta, 'xl/worksheets/')) {
                $hojas[] = [(string) $hoja['name'], $ruta];
            }
        }

        return $hojas ?: [['', 'xl/worksheets/sheet1.xml']];
    }

    private function xml(ZipArchive $zip, string $entrada, bool $acotar): ?SimpleXMLElement
    {
        $detalle = $zip->statName($entrada);
        if ($detalle === false) {
            return null;
        }
        if ($acotar && $detalle['size'] > 25000000) {
            throw new DomainException('La planilla XLSX supera el tamaño de lectura permitido.');
        }

        $xml = simplexml_load_string($zip->getFromName($entrada), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($xml === false) {
            throw new DomainException('La planilla XLSX contiene XML inválido.');
        }

        return $xml;
    }

    private function textoNodo(SimpleXMLElement $nodo): string
    {
        $nodo->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $partes = $nodo->xpath('.//m:t') ?: [];

        return implode('', array_map(fn (SimpleXMLElement $texto): string => (string) $texto, $partes));
    }

    private function indiceColumna(string $referencia): int
    {
        preg_match('/^[A-Z]{1,3}(?=[0-9]|$)/i', $referencia, $coincidencias);
        $letras = mb_strtoupper($coincidencias[0] ?? 'A');
        $indice = 0;

        foreach (str_split($letras) as $letra) {
            $indice = ($indice * 26) + (ord($letra) - 64);
        }

        return max(0, $indice - 1);
    }

    private function detectarDelimitador(string $linea): string
    {
        $conteos = [
            ';' => substr_count($linea, ';'),
            ',' => substr_count($linea, ','),
            "\t" => substr_count($linea, "\t"),
        ];
        arsort($conteos);

        return (string) array_key_first($conteos);
    }

    /**
     * @param  array<int, array<int, string>>  $filas
     * @return array<int, array<string, string|int>>
     */
    private function convertirFilas(array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        $cabeceras = array_map($this->normalizarCabecera(...), array_shift($filas));
        $resultado = [];

        foreach ($filas as $indice => $fila) {
            $valores = [];
            foreach ($cabeceras as $columna => $cabecera) {
                if ($cabecera === '') {
                    continue;
                }

                $valores[$cabecera] = trim((string) ($fila[$columna] ?? ''));
            }

            if (collect($valores)->filter(fn (string $valor): bool => $valor !== '')->isEmpty()) {
                continue;
            }

            $resultado[] = ['fila' => $indice + 2, ...$valores];
        }

        return $resultado;
    }

    protected function normalizarCabecera(string $cabecera): string
    {
        $normalizada = Str::of($cabecera)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return match ($normalizada) {
            'categoria', 'categoria_calidad', 'quality_category' => 'categoria',
            'especie', 'fruta' => 'especie',
            'variedad' => 'variedad',
            'calibre' => 'calibre',
            'envase', 'formato', 'packaging' => 'envase',
            'cliente', 'empresa', 'exportadora' => 'cliente',
            'marca' => 'marca',
            'csg', 'ggn', 'predio_codigo' => 'csg',
            'predio', 'nombre_predio' => 'predio',
            'codigo_articulo', 'articulo_codigo', 'sku' => 'codigo_articulo',
            'codigo_origen', 'origen_codigo' => 'codigo_origen',
            'codigo_combinacion', 'combinacion_codigo' => 'codigo_combinacion',
            default => $normalizada,
        };
    }
}
