<?php

namespace App\Services\Planillas;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Base para llenar una planilla oficial de planta guardada como XLSX de una hoja.
 *
 * La hoja de la plantilla se repite tantas veces como haga falta (con su logo) y cada copia
 * se completa celda por celda. Los textos se escriben en línea, sin tocar los estilos.
 */
abstract class PlantillaXlsx
{
    protected const NS_HOJA = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL_DOCUMENTO = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_REL_PAQUETE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** Ruta de la plantilla bajo resources/. */
    abstract protected function plantilla(): string;

    /** Código de la planilla para los mensajes (p. ej. «POEMP R-41»). */
    abstract protected function codigo(): string;

    abstract protected function nombreHoja(): string;

    /**
     * Copia la plantilla, repite su hoja y completa cada copia.
     *
     * @param  callable(DOMDocument, DOMXPath, int): void  $completar  Recibe la hoja y su índice (desde 0).
     * @return string Ruta del archivo temporal generado.
     */
    protected function generarHojas(int $cantidad, callable $completar): string
    {
        $plantilla = resource_path($this->plantilla());
        if (! is_file($plantilla)) {
            throw new RuntimeException("No se encuentra la plantilla {$this->codigo()}.");
        }
        $ruta = $this->rutaTemporal();
        if (! copy($plantilla, $ruta)) {
            throw new RuntimeException("No fue posible preparar la plantilla {$this->codigo()}.");
        }
        $cantidad = max(1, $cantidad);
        $zip = new ZipArchive;
        $abierto = false;
        try {
            if ($zip->open($ruta) !== true) {
                throw new RuntimeException("No fue posible abrir la plantilla {$this->codigo()}.");
            }
            $abierto = true;
            $hoja = $this->entrada($zip, 'xl/worksheets/sheet1.xml');
            $relacionesHoja = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
            $dibujo = $zip->getFromName('xl/drawings/drawing1.xml');
            $relacionesDibujo = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
            $conDibujo = is_string($relacionesHoja) && is_string($dibujo) && is_string($relacionesDibujo);

            for ($indice = 0; $indice < $cantidad; $indice++) {
                $numero = $indice + 1;
                $documento = $this->documento($hoja);
                $xpath = new DOMXPath($documento);
                $xpath->registerNamespace('m', self::NS_HOJA);
                $completar($documento, $xpath, $indice);
                $vista = $xpath->query('/m:worksheet/m:sheetViews/m:sheetView')->item(0);
                if ($vista instanceof DOMElement && $indice > 0) {
                    $vista->removeAttribute('tabSelected');
                }
                $zip->addFromString("xl/worksheets/sheet{$numero}.xml", $documento->saveXML()
                    ?: throw new RuntimeException("No fue posible completar la planilla {$this->codigo()}."));
                if ($conDibujo) {
                    $zip->addFromString("xl/worksheets/_rels/sheet{$numero}.xml.rels", str_replace('drawings/drawing1.xml', "drawings/drawing{$numero}.xml", $relacionesHoja));
                    $zip->addFromString("xl/drawings/drawing{$numero}.xml", $dibujo);
                    $zip->addFromString("xl/drawings/_rels/drawing{$numero}.xml.rels", $relacionesDibujo);
                }
            }
            $zip->addFromString('xl/workbook.xml', $this->libro($this->entrada($zip, 'xl/workbook.xml'), $cantidad));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relacionesLibro($this->entrada($zip, 'xl/_rels/workbook.xml.rels'), $cantidad));
            $zip->addFromString('[Content_Types].xml', $this->tiposContenido($this->entrada($zip, '[Content_Types].xml'), $cantidad, $conDibujo));

            if (! $zip->close()) {
                throw new RuntimeException("No fue posible finalizar la planilla {$this->codigo()}.");
            }
            $abierto = false;

            return $ruta;
        } catch (Throwable $error) {
            if ($abierto) {
                $zip->close();
            }
            File::delete($ruta);

            throw $error;
        }
    }

    protected function texto(DOMDocument $documento, DOMXPath $xpath, string $referencia, mixed $valor): void
    {
        $celda = $this->limpiar($documento, $xpath, $referencia);
        if ($valor === null || $valor === '') {
            return;
        }
        $celda->setAttribute('t', 'inlineStr');
        $inline = $documento->createElementNS(self::NS_HOJA, 'is');
        $texto = $documento->createElementNS(self::NS_HOJA, 't');
        $texto->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $texto->appendChild($documento->createTextNode((string) $valor));
        $inline->appendChild($texto);
        $celda->appendChild($inline);
    }

    protected function numero(DOMDocument $documento, DOMXPath $xpath, string $referencia, int|float|null $valor): void
    {
        $celda = $this->limpiar($documento, $xpath, $referencia);
        if ($valor === null) {
            return;
        }
        $celda->setAttribute('t', 'n');
        $numero = $documento->createElementNS(self::NS_HOJA, 'v');
        $numero->appendChild($documento->createTextNode((string) $valor));
        $celda->appendChild($numero);
    }

    /**
     * Parte un texto en líneas de un ancho máximo; lo que no cabe en la última se le agrega.
     *
     * @return array<int, string>
     */
    protected function lineas(string $texto, int $ancho, int $maximo): array
    {
        $lineas = [];
        $actual = '';
        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palabra) {
            if ($palabra === '') {
                continue;
            }
            $candidata = $actual === '' ? $palabra : $actual.' '.$palabra;
            if (mb_strlen($candidata) > $ancho && $actual !== '') {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $candidata;
            }
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }
        if (count($lineas) > $maximo) {
            $lineas = [...array_slice($lineas, 0, $maximo - 1), implode(' ', array_slice($lineas, $maximo - 1))];
        }

        return $lineas;
    }

    private function limpiar(DOMDocument $documento, DOMXPath $xpath, string $referencia): DOMElement
    {
        $celda = $this->celda($documento, $xpath, $referencia);
        while ($celda->firstChild) {
            $celda->removeChild($celda->firstChild);
        }
        $celda->removeAttribute('t');

        return $celda;
    }

    /** Devuelve la celda; si la plantilla no la trae, la crea en su fila y en orden de columna. */
    protected function celda(DOMDocument $documento, DOMXPath $xpath, string $referencia): DOMElement
    {
        $celda = $xpath->query("//m:c[@r='{$referencia}']")->item(0);
        if ($celda instanceof DOMElement) {
            return $celda;
        }
        preg_match('/^([A-Z]+)(\d+)$/', $referencia, $partes);
        [$columna, $numeroFila] = [$partes[1], (int) $partes[2]];
        $datos = $xpath->query('/m:worksheet/m:sheetData')->item(0);
        if (! $datos instanceof DOMElement) {
            throw new RuntimeException("La plantilla {$this->codigo()} no contiene datos de hoja.");
        }
        $fila = $xpath->query("m:row[@r='{$numeroFila}']", $datos)->item(0);
        if (! $fila instanceof DOMElement) {
            $fila = $documento->createElementNS(self::NS_HOJA, 'row');
            $fila->setAttribute('r', (string) $numeroFila);
            $siguiente = null;
            foreach ($xpath->query('m:row', $datos) as $existente) {
                if ($existente instanceof DOMElement && (int) $existente->getAttribute('r') > $numeroFila) {
                    $siguiente = $existente;
                    break;
                }
            }
            $datos->insertBefore($fila, $siguiente);
        }
        $celda = $documento->createElementNS(self::NS_HOJA, 'c');
        $celda->setAttribute('r', $referencia);
        $siguiente = null;
        foreach ($xpath->query('m:c', $fila) as $existente) {
            if ($existente instanceof DOMElement && $this->indiceColumna(preg_replace('/\d+/', '', $existente->getAttribute('r'))) > $this->indiceColumna($columna)) {
                $siguiente = $existente;
                break;
            }
        }
        $fila->insertBefore($celda, $siguiente);

        return $celda;
    }

    private function indiceColumna(string $columna): int
    {
        $indice = 0;
        foreach (str_split($columna) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice;
    }

    private function libro(string $xml, int $cantidad): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $hojas = $xpath->query('/m:workbook/m:sheets')->item(0);
        if (! $hojas instanceof DOMElement) {
            throw new RuntimeException("La plantilla {$this->codigo()} no contiene la colección de hojas.");
        }
        while ($hojas->firstChild) {
            $hojas->removeChild($hojas->firstChild);
        }
        for ($numero = 1; $numero <= $cantidad; $numero++) {
            $hoja = $documento->createElementNS(self::NS_HOJA, 'sheet');
            $hoja->setAttribute('name', $numero === 1 ? $this->nombreHoja() : $this->nombreHoja()." ({$numero})");
            $hoja->setAttribute('sheetId', (string) $numero);
            $hoja->setAttribute('state', 'visible');
            $hoja->setAttributeNS(self::NS_REL_DOCUMENTO, 'r:id', "rIdHoja{$numero}");
            $hojas->appendChild($hoja);
        }

        return $documento->saveXML() ?: throw new RuntimeException("No fue posible actualizar el libro {$this->codigo()}.");
    }

    private function relacionesLibro(string $xml, int $cantidad): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('r', self::NS_REL_PAQUETE);
        $raiz = $documento->documentElement;
        foreach (iterator_to_array($xpath->query('/r:Relationships/r:Relationship[contains(@Type, "/worksheet")]')) as $relacion) {
            $raiz?->removeChild($relacion);
        }
        for ($numero = 1; $numero <= $cantidad; $numero++) {
            $relacion = $documento->createElementNS(self::NS_REL_PAQUETE, 'Relationship');
            $relacion->setAttribute('Id', "rIdHoja{$numero}");
            $relacion->setAttribute('Type', self::NS_REL_DOCUMENTO.'/worksheet');
            $relacion->setAttribute('Target', "worksheets/sheet{$numero}.xml");
            $raiz?->appendChild($relacion);
        }

        return $documento->saveXML() ?: throw new RuntimeException("No fue posible actualizar las relaciones {$this->codigo()}.");
    }

    private function tiposContenido(string $xml, int $cantidad, bool $conDibujo): string
    {
        $documento = $this->documento($xml);
        $raiz = $documento->documentElement;
        for ($numero = 2; $numero <= $cantidad; $numero++) {
            $partes = ["/xl/worksheets/sheet{$numero}.xml" => 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml'];
            if ($conDibujo) {
                $partes["/xl/drawings/drawing{$numero}.xml"] = 'application/vnd.openxmlformats-officedocument.drawing+xml';
            }
            foreach ($partes as $parte => $tipo) {
                $nodo = $documento->createElementNS(self::NS_CONTENT_TYPES, 'Override');
                $nodo->setAttribute('PartName', $parte);
                $nodo->setAttribute('ContentType', $tipo);
                $raiz?->appendChild($nodo);
            }
        }

        return $documento->saveXML() ?: throw new RuntimeException("No fue posible actualizar los tipos {$this->codigo()}.");
    }

    private function documento(string $xml): DOMDocument
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->preserveWhiteSpace = false;
        $documento->formatOutput = false;
        if ($documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) === false) {
            throw new RuntimeException("La plantilla {$this->codigo()} contiene XML inválido.");
        }

        return $documento;
    }

    private function entrada(ZipArchive $zip, string $nombre): string
    {
        $contenido = $zip->getFromName($nombre);
        if (! is_string($contenido)) {
            throw new RuntimeException("La plantilla {$this->codigo()} no contiene {$nombre}.");
        }

        return $contenido;
    }

    private function rutaTemporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'folios-planilla-');
        if ($base === false) {
            throw new RuntimeException("No fue posible crear el archivo temporal {$this->codigo()}.");
        }
        File::delete($base);

        return $base.'.xlsx';
    }
}
