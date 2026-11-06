<?php

namespace App\Services\Validacion;

use App\Enums\ResultadoValidacionPallet;
use App\Models\ValidacionPallet;
use DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

class ServicioExportacionRegistroValidacion
{
    private const FILA_INICIAL = 11;

    private const FILAS_POR_HOJA = 20;

    private const COLUMNA_CATEGORIA = 'M';

    private const NS_HOJA = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL_DOCUMENTO = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_REL_PAQUETE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /**
     * @param  Collection<int, ValidacionPallet>  $validaciones
     */
    public function generar(Collection $validaciones): string
    {
        if ($validaciones->isEmpty()) {
            throw new DomainException('No existen validaciones confirmadas para generar el registro RRPP-01.');
        }

        return $this->generarPaginas($this->paginas($validaciones));
    }

    public function generarEnBlanco(): string
    {
        return $this->generarPaginas([[
            'fecha' => '',
            'encargado' => '',
            'linea' => 0,
            'turno' => '',
            'numero' => 1,
            'validaciones' => collect(),
        ]]);
    }

    /**
     * @param  array<int, array{fecha:string,encargado:string,linea:int,turno:string,numero:int,validaciones:Collection<int,ValidacionPallet>}>  $paginas
     */
    private function generarPaginas(array $paginas): string
    {
        $plantilla = resource_path('templates/validacion/rrpp-01.xlsx');
        if (is_file($plantilla) === false) {
            throw new RuntimeException('No se encuentra la plantilla RRPP-01.');
        }

        $ruta = $this->rutaTemporal();
        if (copy($plantilla, $ruta) === false) {
            throw new RuntimeException('No fue posible preparar la plantilla RRPP-01.');
        }

        $zip = new ZipArchive;
        $abierto = false;

        try {
            if ($zip->open($ruta) !== true) {
                throw new RuntimeException('No fue posible abrir la plantilla RRPP-01.');
            }
            $abierto = true;

            $hojaPlantilla = $this->entrada($zip, 'xl/worksheets/sheet1.xml');
            $relacionesHoja = $this->entrada($zip, 'xl/worksheets/_rels/sheet1.xml.rels');
            $dibujo = $this->entrada($zip, 'xl/drawings/drawing1.xml');
            $relacionesDibujo = $this->entrada($zip, 'xl/drawings/_rels/drawing1.xml.rels');

            foreach ($paginas as $indice => $pagina) {
                $numero = $indice + 1;
                $zip->addFromString(
                    "xl/worksheets/sheet{$numero}.xml",
                    $this->completarHoja($hojaPlantilla, $pagina),
                );

                $relaciones = str_replace(
                    'drawings/drawing1.xml',
                    "drawings/drawing{$numero}.xml",
                    $relacionesHoja,
                );
                $zip->addFromString("xl/worksheets/_rels/sheet{$numero}.xml.rels", $relaciones);
                $zip->addFromString("xl/drawings/drawing{$numero}.xml", $dibujo);
                $zip->addFromString("xl/drawings/_rels/drawing{$numero}.xml.rels", $relacionesDibujo);
            }

            $zip->addFromString(
                'xl/workbook.xml',
                $this->actualizarLibro($this->entrada($zip, 'xl/workbook.xml'), $paginas),
            );
            $zip->addFromString(
                'xl/_rels/workbook.xml.rels',
                $this->actualizarRelacionesLibro(
                    $this->entrada($zip, 'xl/_rels/workbook.xml.rels'),
                    count($paginas),
                ),
            );
            $zip->addFromString(
                '[Content_Types].xml',
                $this->actualizarTiposContenido(
                    $this->entrada($zip, '[Content_Types].xml'),
                    count($paginas),
                ),
            );

            if ($zip->close() === false) {
                throw new RuntimeException('No fue posible finalizar el registro RRPP-01.');
            }
            $abierto = false;

            return $ruta;
        } catch (Throwable $excepcion) {
            if ($abierto) {
                $zip->close();
            }
            File::delete($ruta);

            throw $excepcion;
        }
    }

    /**
     * @param  Collection<int, ValidacionPallet>  $validaciones
     * @return array<int, array{fecha:string,encargado:string,linea:int,turno:string,numero:int,validaciones:Collection<int,ValidacionPallet>}>
     */
    private function paginas(Collection $validaciones): array
    {
        $ordenadas = $validaciones->sortBy(fn (ValidacionPallet $validacion): string => implode('|', [
            $validacion->fechaJornada()?->toDateString() ?? '',
            str_pad((string) $validacion->user_id, 12, '0', STR_PAD_LEFT),
            (string) $validacion->linea_proceso,
            (string) $validacion->turno,
            $validacion->generado_dispositivo_at?->format('YmdHis.u') ?? '',
            (string) $validacion->numero_intento,
        ]));
        $paginas = [];

        foreach ($ordenadas->groupBy(fn (ValidacionPallet $validacion): string => implode('|', [
            $validacion->fechaJornada()?->toDateString() ?? '',
            (string) $validacion->user_id,
            (string) $validacion->linea_proceso,
            (string) $validacion->turno,
        ])) as $grupo) {
            /** @var ValidacionPallet $primera */
            $primera = $grupo->first();
            foreach ($grupo->values()->chunk(self::FILAS_POR_HOJA) as $indice => $lote) {
                $paginas[] = [
                    'fecha' => $primera->fechaJornada()?->format('d-m-Y') ?? '',
                    'encargado' => $primera->usuario?->name ?? 'Sin usuario',
                    'linea' => (int) $primera->linea_proceso,
                    'turno' => (string) $primera->turno,
                    'numero' => $indice + 1,
                    'validaciones' => $lote->values(),
                ];
            }
        }

        return $paginas;
    }

    /**
     * @param  array{fecha:string,encargado:string,linea:int,turno:string,numero:int,validaciones:Collection<int,ValidacionPallet>}  $pagina
     */
    private function completarHoja(string $xml, array $pagina): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $this->prepararColumnaCategoria($documento, $xpath);

        $this->texto($documento, $xpath, 'C5', $pagina['fecha']);
        $this->texto($documento, $xpath, 'C6', $pagina['encargado']);
        $this->texto($documento, $xpath, 'C7', $pagina['turno']);
        $this->texto(
            $documento,
            $xpath,
            'C8',
            $pagina['linea'] > 0 ? (string) $pagina['linea'] : '',
        );
        $this->texto($documento, $xpath, self::COLUMNA_CATEGORIA.'10', 'Categoría');

        for ($indice = 0; $indice < self::FILAS_POR_HOJA; $indice += 1) {
            $fila = self::FILA_INICIAL + $indice;
            foreach (range('B', self::COLUMNA_CATEGORIA) as $columna) {
                $this->limpiar($xpath, $columna.$fila);
            }

            /** @var ValidacionPallet|null $validacion */
            $validacion = $pagina['validaciones']->get($indice);
            if ($validacion === null) {
                continue;
            }

            $articulo = $validacion->snapshot['articulo'] ?? [];
            $origen = $validacion->snapshot['origen'] ?? [];
            $csgs = collect($validacion->snapshot['composicion'] ?? [])
                ->pluck('csg')
                ->filter()
                ->unique()
                ->implode(' / ');
            $categoria = $validacion->snapshot['categoria'] ?? [];
            $this->texto($documento, $xpath, 'B'.$fila, $validacion->numero_folio);
            $this->texto($documento, $xpath, 'C'.$fila, $origen['marca'] ?? null);
            $this->texto($documento, $xpath, 'D'.$fila, $articulo['envase'] ?? null);
            $this->texto($documento, $xpath, 'E'.$fila, $articulo['especie'] ?? null);
            $this->texto($documento, $xpath, 'F'.$fila, $articulo['variedad'] ?? null);
            $this->texto(
                $documento,
                $xpath,
                'G'.$fila,
                $csgs !== '' ? $csgs : ($origen['csg'] ?? null),
            );
            $this->texto($documento, $xpath, 'H'.$fila, $articulo['calibre'] ?? null);
            $this->numero($documento, $xpath, 'I'.$fila, (int) $validacion->cantidad_cajas);

            if ($validacion->resultado === ResultadoValidacionPallet::Aprobado) {
                $this->texto($documento, $xpath, 'J'.$fila, 'X');
            } elseif ($validacion->resultado === ResultadoValidacionPallet::Rechazado) {
                $this->texto($documento, $xpath, 'K'.$fila, 'X');
            }

            $this->texto($documento, $xpath, 'L'.$fila, $this->observacion($validacion));
            $this->texto(
                $documento,
                $xpath,
                self::COLUMNA_CATEGORIA.$fila,
                $categoria['nombre'] ?? $categoria['codigo_externo'] ?? null,
            );
        }

        $this->formula(
            $documento,
            $xpath,
            'I31',
            'SUM(I11:I30)',
            (int) $pagina['validaciones']->sum('cantidad_cajas'),
        );
        $this->configurarImpresion($documento, $xpath);

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible completar una hoja RRPP-01.');
    }

    private function prepararColumnaCategoria(DOMDocument $documento, DOMXPath $xpath): void
    {
        $this->asegurarCeldaDesde($xpath, self::COLUMNA_CATEGORIA.'10', 'H10');
        foreach (range(self::FILA_INICIAL, self::FILA_INICIAL + self::FILAS_POR_HOJA - 1) as $fila) {
            $this->asegurarCeldaDesde($xpath, self::COLUMNA_CATEGORIA.$fila, 'H'.$fila);
        }

        $dimension = $xpath->query('/m:worksheet/m:dimension')->item(0);
        if ($dimension instanceof DOMElement) {
            $referencia = $dimension->getAttribute('ref');
            $actualizada = preg_replace(
                '/[A-Z]+(\d+)$/',
                self::COLUMNA_CATEGORIA.'$1',
                $referencia,
            );
            if (is_string($actualizada) && $actualizada !== '') {
                $dimension->setAttribute('ref', $actualizada);
            }
        }

        $columnas = $xpath->query('/m:worksheet/m:cols')->item(0);
        if (! $columnas instanceof DOMElement) {
            return;
        }

        foreach ($xpath->query('/m:worksheet/m:cols/m:col') as $columna) {
            if (! $columna instanceof DOMElement) {
                continue;
            }

            $minimo = (int) $columna->getAttribute('min');
            $maximo = (int) $columna->getAttribute('max');
            if ($minimo <= 8 && $maximo >= 8) {
                $nueva = $columna->cloneNode(true);
                if ($nueva instanceof DOMElement) {
                    $nueva->setAttribute('min', '13');
                    $nueva->setAttribute('max', '13');
                    $columnas->appendChild($nueva);
                }

                break;
            }
        }
    }

    private function asegurarCeldaDesde(
        DOMXPath $xpath,
        string $referencia,
        string $referenciaModelo,
    ): void {
        if ($xpath->query("//m:c[@r='{$referencia}']")->length > 0) {
            return;
        }

        $modelo = $this->celda($xpath, $referenciaModelo);
        $filaNumero = (int) preg_replace('/\D+/', '', $referencia);
        $fila = $xpath->query("//m:row[@r='{$filaNumero}']")->item(0);
        if (! $fila instanceof DOMElement) {
            throw new RuntimeException("La plantilla RRPP-01 no contiene la fila {$filaNumero}.");
        }

        $celda = $modelo->cloneNode(true);
        if (! $celda instanceof DOMElement) {
            throw new RuntimeException("No fue posible preparar la celda {$referencia}.");
        }

        $celda->setAttribute('r', $referencia);
        while ($celda->firstChild) {
            $celda->removeChild($celda->firstChild);
        }
        $celda->removeAttribute('t');
        $fila->appendChild($celda);
    }

    private function configurarImpresion(DOMDocument $documento, DOMXPath $xpath): void
    {
        $raiz = $documento->documentElement;
        $formato = $xpath->query('/m:worksheet/m:sheetFormatPr')->item(0);
        if (($raiz instanceof DOMElement) === false || $formato === null) {
            throw new RuntimeException('La plantilla RRPP-01 no contiene la estructura de impresión.');
        }

        if ($xpath->query('/m:worksheet/m:sheetPr')->length === 0) {
            $propiedades = $documento->createElementNS(self::NS_HOJA, 'sheetPr');
            $ajuste = $documento->createElementNS(self::NS_HOJA, 'pageSetUpPr');
            $ajuste->setAttribute('fitToPage', '1');
            $propiedades->appendChild($ajuste);
            $raiz->insertBefore($propiedades, $formato);
        }

        if ($xpath->query('/m:worksheet/m:sheetViews')->length === 0) {
            $vistas = $documento->createElementNS(self::NS_HOJA, 'sheetViews');
            $vista = $documento->createElementNS(self::NS_HOJA, 'sheetView');
            $vista->setAttribute('workbookViewId', '0');
            $vista->setAttribute('zoomScale', '100');
            $vistas->appendChild($vista);
            $raiz->insertBefore($vistas, $formato);
        }

        if ($xpath->query('/m:worksheet/m:pageSetup')->length === 0) {
            $configuracion = $documento->createElementNS(self::NS_HOJA, 'pageSetup');
            $configuracion->setAttribute('paperSize', '9');
            $configuracion->setAttribute('orientation', 'portrait');
            $configuracion->setAttribute('fitToWidth', '1');
            $configuracion->setAttribute('fitToHeight', '1');
            $margenes = $xpath->query('/m:worksheet/m:pageMargins')->item(0);
            $raiz->insertBefore($configuracion, $margenes?->nextSibling);
        }
    }

    /**
     * @param  array<int, array{fecha:string,encargado:string,linea:int,turno:string,numero:int,validaciones:Collection<int,ValidacionPallet>}>  $paginas
     */
    private function actualizarLibro(string $xml, array $paginas): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $hojas = $xpath->query('/m:workbook/m:sheets')->item(0);
        if (($hojas instanceof DOMElement) === false) {
            throw new RuntimeException('La plantilla RRPP-01 no contiene la colección de hojas.');
        }

        while ($hojas->firstChild) {
            $hojas->removeChild($hojas->firstChild);
        }

        foreach ($paginas as $indice => $pagina) {
            $numero = $indice + 1;
            $hoja = $documento->createElementNS(self::NS_HOJA, 'sheet');
            $hoja->setAttribute('name', $this->nombreHoja($pagina, $numero));
            $hoja->setAttribute('sheetId', (string) $numero);
            $hoja->setAttributeNS(
                self::NS_REL_DOCUMENTO,
                'r:id',
                $numero === 1 ? 'rId1' : 'rId'.($numero + 3),
            );
            $hojas->appendChild($hoja);
        }

        foreach ($xpath->query('/m:workbook/m:definedNames/m:definedName') as $nombreDefinido) {
            if (! $nombreDefinido instanceof DOMElement
                || $nombreDefinido->getAttribute('name') !== '_xlnm.Print_Area') {
                continue;
            }

            $nombreDefinido->nodeValue = preg_replace(
                '/\$L\$(\d+)/',
                '$'.self::COLUMNA_CATEGORIA.'$$1',
                $nombreDefinido->textContent,
            ) ?? $nombreDefinido->textContent;
        }

        foreach ($xpath->query('/m:workbook/*[local-name()="AlternateContent" or local-name()="revisionPtr"]') as $nodo) {
            $nodo->parentNode?->removeChild($nodo);
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar el libro RRPP-01.');
    }

    private function actualizarRelacionesLibro(string $xml, int $cantidad): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('r', self::NS_REL_PAQUETE);
        $raiz = $documento->documentElement;

        foreach ($xpath->query('/r:Relationships/r:Relationship[contains(@Type, "/worksheet")]') as $relacion) {
            $raiz?->removeChild($relacion);
        }

        for ($numero = 1; $numero <= $cantidad; $numero += 1) {
            $relacion = $documento->createElementNS(self::NS_REL_PAQUETE, 'Relationship');
            $relacion->setAttribute('Id', $numero === 1 ? 'rId1' : 'rId'.($numero + 3));
            $relacion->setAttribute('Type', self::NS_REL_DOCUMENTO.'/worksheet');
            $relacion->setAttribute('Target', "worksheets/sheet{$numero}.xml");
            $raiz?->appendChild($relacion);
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar las relaciones RRPP-01.');
    }

    private function actualizarTiposContenido(string $xml, int $cantidad): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('c', self::NS_CONTENT_TYPES);
        $raiz = $documento->documentElement;

        for ($numero = 2; $numero <= $cantidad; $numero += 1) {
            $hoja = $documento->createElementNS(self::NS_CONTENT_TYPES, 'Override');
            $hoja->setAttribute('PartName', "/xl/worksheets/sheet{$numero}.xml");
            $hoja->setAttribute(
                'ContentType',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml',
            );
            $raiz?->appendChild($hoja);

            $dibujo = $documento->createElementNS(self::NS_CONTENT_TYPES, 'Override');
            $dibujo->setAttribute('PartName', "/xl/drawings/drawing{$numero}.xml");
            $dibujo->setAttribute(
                'ContentType',
                'application/vnd.openxmlformats-officedocument.drawing+xml',
            );
            $raiz?->appendChild($dibujo);
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar los tipos RRPP-01.');
    }

    private function limpiar(DOMXPath $xpath, string $referencia): void
    {
        $celda = $this->celda($xpath, $referencia);
        while ($celda->firstChild) {
            $celda->removeChild($celda->firstChild);
        }
        $celda->removeAttribute('t');
    }

    private function texto(
        DOMDocument $documento,
        DOMXPath $xpath,
        string $referencia,
        mixed $valor,
    ): void {
        $this->limpiar($xpath, $referencia);
        if ($valor === null || $valor === '') {
            return;
        }

        $celda = $this->celda($xpath, $referencia);
        $celda->setAttribute('t', 'inlineStr');
        $inline = $documento->createElementNS(self::NS_HOJA, 'is');
        $texto = $documento->createElementNS(self::NS_HOJA, 't');
        $texto->appendChild($documento->createTextNode((string) $valor));
        $inline->appendChild($texto);
        $celda->appendChild($inline);
    }

    private function numero(
        DOMDocument $documento,
        DOMXPath $xpath,
        string $referencia,
        int $valor,
    ): void {
        $this->limpiar($xpath, $referencia);
        $celda = $this->celda($xpath, $referencia);
        $numero = $documento->createElementNS(self::NS_HOJA, 'v');
        $numero->appendChild($documento->createTextNode((string) $valor));
        $celda->appendChild($numero);
    }

    private function formula(
        DOMDocument $documento,
        DOMXPath $xpath,
        string $referencia,
        string $formula,
        int $valor,
    ): void {
        $this->limpiar($xpath, $referencia);
        $celda = $this->celda($xpath, $referencia);
        $nodoFormula = $documento->createElementNS(self::NS_HOJA, 'f');
        $nodoFormula->appendChild($documento->createTextNode($formula));
        $celda->appendChild($nodoFormula);
        $nodoValor = $documento->createElementNS(self::NS_HOJA, 'v');
        $nodoValor->appendChild($documento->createTextNode((string) $valor));
        $celda->appendChild($nodoValor);
    }

    private function celda(DOMXPath $xpath, string $referencia): DOMElement
    {
        $celda = $xpath->query("//m:c[@r='{$referencia}']")->item(0);
        if (($celda instanceof DOMElement) === false) {
            throw new RuntimeException("La plantilla RRPP-01 no contiene la celda {$referencia}.");
        }

        return $celda;
    }

    private function observacion(ValidacionPallet $validacion): ?string
    {
        $partes = [];
        if ($validacion->resultado === ResultadoValidacionPallet::Observado) {
            $partes[] = 'OBSERVADO';
        }
        if ($validacion->motivo) {
            $partes[] = ucfirst(str_replace('_', ' ', $validacion->motivo->value));
        }
        if (filled($validacion->observacion)) {
            $partes[] = trim((string) $validacion->observacion);
        }

        return $partes === [] ? null : implode(': ', array_slice($partes, 0, 2))
            .(count($partes) > 2 ? ' — '.implode(' — ', array_slice($partes, 2)) : '');
    }

    /**
     * @param  array{fecha:string,encargado:string,linea:int,turno:string,numero:int,validaciones:Collection<int,ValidacionPallet>}  $pagina
     */
    private function nombreHoja(array $pagina, int $numero): string
    {
        if ($pagina['fecha'] === '' && $pagina['turno'] === '' && $pagina['linea'] === 0) {
            return 'RRPP-01 en blanco';
        }

        $nombre = sprintf(
            '%s-L%d-%s-%02d',
            str_replace('-', '', $pagina['fecha']),
            $pagina['linea'],
            $pagina['turno'],
            $numero,
        );

        return mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '-', $nombre) ?: "Registro-{$numero}", 0, 31);
    }

    private function documento(string $xml): DOMDocument
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->preserveWhiteSpace = false;
        $documento->formatOutput = false;
        if ($documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) === false) {
            throw new RuntimeException('La plantilla RRPP-01 contiene XML inválido.');
        }

        return $documento;
    }

    private function entrada(ZipArchive $zip, string $nombre): string
    {
        $contenido = $zip->getFromName($nombre);
        if (is_string($contenido) === false) {
            throw new RuntimeException("La plantilla RRPP-01 no contiene {$nombre}.");
        }

        return $contenido;
    }

    private function rutaTemporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'estiba-rrpp-');
        if ($base === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal RRPP-01.');
        }

        File::delete($base);

        return $base.'.xlsx';
    }
}
