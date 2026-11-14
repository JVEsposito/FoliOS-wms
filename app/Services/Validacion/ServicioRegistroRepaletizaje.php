<?php

namespace App\Services\Validacion;

use App\Models\Repaletizaje;
use App\Models\RepaletizajeDetalle;
use DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Registro RRPL-01 «Registro de repaletizaje».
 *
 * Se llena desde los repaletizajes guardados, con la plantilla oficial. Cada
 * hoja corresponde a una fecha operacional, un turno y un tarjador, con cuatro
 * bloques por hoja. Cada bloque es un folio resultante con hasta ocho líneas
 * de origen; una repa con más líneas continúa en el bloque siguiente. Una repa
 * anulada conserva su bloque, marcado ANULADA.
 */
class ServicioRegistroRepaletizaje
{
    public const LINEAS_POR_BLOQUE = 8;

    public const BLOQUES_POR_HOJA = 4;

    private const PLANTILLA = 'templates/repaletizaje/rrpl-01.xlsx';

    private const NS_HOJA = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL_DOCUMENTO = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_REL_PAQUETE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** Estilo agregado a la plantilla: el del recuadro FOLIO COMPLETO con ajuste de texto. */
    private ?int $estiloFolioConAjuste = null;

    /** Estilo agregado para la X de ESTADO PALLET: centrada y con la fuente del formulario. */
    private ?int $estiloMarca = null;

    /** Estilo del valor FECHA del encabezado, reutilizado para TURNO. */
    private ?int $estiloEncabezado = null;

    /** @param  Collection<int, Repaletizaje>  $repas */
    public function generar(Collection $repas): string
    {
        $repas = $repas->reject(fn ($repa) => $repa->modalidad === 'reembalaje');
        if ($repas->isEmpty()) {
            throw new DomainException('No existen repaletizajes para generar el registro RRPL-01.');
        }

        return $this->generarPaginas($this->paginas($repas));
    }

    public function generarEnBlanco(): string
    {
        return $this->generarPaginas([[
            'fecha' => '',
            'turno' => '',
            'tarjador' => '',
            'numero' => 1,
            'bloques' => [],
        ]]);
    }

    /**
     * Bloques del formulario para una repa, en el orden en que se imprimen.
     *
     * @return array<int, array{folio:string, variedad:?string, etiqueta:?string, embalaje:?string, repa:string, estado:string, lineas:array<int, array{folio:string, fecha:?string, calibre:?string, csg:?string, cajas:int}>, total:?int, anulada:bool, motivo:?string}>
     */
    public function bloques(Repaletizaje $repa): array
    {
        if ($repa->modalidad === 'reembalaje') {
            return [];
        }
        $resultados = $this->resultados($repa);
        $bloques = [];

        foreach ($resultados as $resultado) {
            $partes = array_chunk($resultado['lineas'], self::LINEAS_POR_BLOQUE) ?: [[]];
            $cantidadPartes = count($partes);
            foreach ($partes as $indice => $lineas) {
                $ultima = $indice === $cantidadPartes - 1;
                $bloques[] = [
                    'folio' => $resultado['folio'],
                    'variedad' => $resultado['variedad'],
                    'etiqueta' => $resultado['etiqueta'],
                    'embalaje' => $resultado['embalaje'],
                    'repa' => $cantidadPartes > 1
                        ? sprintf('%s (%d/%d)', $repa->codigo, $indice + 1, $cantidadPartes)
                        : $repa->codigo,
                    'estado' => $resultado['estado'],
                    'lineas' => $lineas,
                    'total' => $ultima ? $resultado['total'] : null,
                    'anulada' => $repa->estado === 'anulado',
                    'motivo' => $repa->motivo_anulacion,
                ];
            }
        }

        return $bloques;
    }

    /**
     * @param  Collection<int, Repaletizaje>  $repas
     * @return array<int, array{fecha:string, turno:string, tarjador:string, numero:int, bloques:array<int, array<string, mixed>>}>
     */
    private function paginas(Collection $repas): array
    {
        $paginas = [];
        $grupos = $repas
            ->sortBy(fn (Repaletizaje $repa): string => implode('|', [
                $repa->fecha_operacional?->toDateString() ?? '',
                (string) ($repa->turnoOperativo() ?? $repa->turno),
                str_pad((string) $repa->user_id, 12, '0', STR_PAD_LEFT),
                $repa->confirmado_at?->format('YmdHis.u') ?? '',
                $repa->codigo,
            ]))
            ->groupBy(fn (Repaletizaje $repa): string => implode('|', [
                $repa->fecha_operacional?->toDateString() ?? '',
                (string) ($repa->turnoOperativo() ?? $repa->turno),
                (string) $repa->user_id,
            ]));

        foreach ($grupos as $grupo) {
            /** @var Repaletizaje $primera */
            $primera = $grupo->first();
            $bloques = $grupo->flatMap(fn (Repaletizaje $repa): array => $this->bloques($repa))->values()->all();
            foreach (array_chunk($bloques, self::BLOQUES_POR_HOJA) as $indice => $lote) {
                $paginas[] = [
                    'fecha' => $primera->fecha_operacional?->format('d-m-Y') ?? '',
                    'turno' => (string) ($primera->turnoOperativo() ?? $primera->turno),
                    'tarjador' => $primera->usuario?->name ?? 'Sin usuario',
                    'numero' => $indice + 1,
                    'bloques' => $lote,
                ];
            }
        }

        return $paginas;
    }

    /**
     * Folios resultantes de la repa con sus líneas de origen.
     *
     * @return array<int, array{folio:string, variedad:?string, etiqueta:?string, embalaje:?string, estado:string, lineas:array<int, array{folio:string, fecha:?string, calibre:?string, csg:?string, cajas:int}>, total:int}>
     */
    private function resultados(Repaletizaje $repa): array
    {
        $snapshot = $repa->snapshot ?? [];
        $genealogia = collect($snapshot['genealogia'] ?? []);
        $fechasOrigen = $repa->detalles->mapWithKeys(fn (RepaletizajeDetalle $detalle): array => [
            (string) $detalle->folio_origen_id => $this->fechaOrigen($detalle),
        ]);

        if (($repa->modalidad ?? 'consolidacion') === 'consolidacion') {
            $especificaciones = $snapshot['especificaciones'] ?? [];
            $lineas = $genealogia->flatMap(function (array $origen) use ($fechasOrigen): array {
                $composicion = collect($origen['composicion_aportada'] ?? [])
                    ->filter(fn (mixed $linea): bool => is_array($linea) && (int) ($linea['cantidad_cajas'] ?? 0) > 0);
                if ($composicion->isEmpty()) {
                    return [$this->linea($origen, [
                        'cantidad_cajas' => (int) ($origen['cajas_aportadas'] ?? 0),
                    ], $fechasOrigen)];
                }

                return $composicion->map(fn (array $linea): array => $this->linea($origen, $linea, $fechasOrigen))->all();
            })->values()->all();

            return [[
                'folio' => $repa->folioResultante?->numero_folio ?? '',
                'variedad' => $especificaciones['variedad'] ?? null,
                'etiqueta' => $especificaciones['marca'] ?? null,
                'embalaje' => $especificaciones['envase'] ?? null,
                'estado' => $repa->tipo_resultado === 'pallet' ? 'completo' : 'saldo',
                'lineas' => $lineas,
                'total' => (int) $repa->cantidad_resultante,
            ]];
        }

        // Cambio de folio o división: cada folio resultante es un bloque.
        $origen = $genealogia->first() ?? [];
        $especificaciones = $origen['especificaciones'] ?? [];

        return collect($snapshot['resultados'] ?? [])->map(fn (array $resultado): array => [
            'folio' => (string) ($resultado['numero_folio'] ?? ''),
            'variedad' => $especificaciones['variedad'] ?? null,
            'etiqueta' => $especificaciones['marca'] ?? null,
            'embalaje' => $resultado['envase'] ?? $especificaciones['envase'] ?? null,
            'estado' => ($resultado['tipo_resultado'] ?? '') === 'pallet' ? 'completo' : 'saldo',
            'lineas' => collect($resultado['composicion'] ?? [])
                ->filter(fn (mixed $linea): bool => is_array($linea))
                ->map(fn (array $linea): array => $this->linea($origen, $linea, $fechasOrigen))
                ->values()
                ->all(),
            'total' => (int) ($resultado['cantidad_resultante'] ?? 0),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $origen
     * @param  array<string, mixed>  $linea
     * @param  Collection<string, ?string>  $fechasOrigen
     * @return array{folio:string, fecha:?string, calibre:?string, csg:?string, cajas:int}
     */
    private function linea(array $origen, array $linea, Collection $fechasOrigen): array
    {
        $fecha = $linea['fecha_embalaje'] ?? $fechasOrigen->get((string) ($origen['folio_id'] ?? ''));

        return [
            'folio' => (string) ($origen['numero_folio'] ?? ''),
            'fecha' => filled($fecha) ? $this->fecha((string) $fecha) : null,
            'calibre' => $linea['calibre'] ?? $origen['especificaciones']['calibre'] ?? null,
            'csg' => $linea['csg'] ?? ($origen['especificaciones']['csg'] ?? null),
            'cajas' => (int) ($linea['cantidad_cajas'] ?? $linea['cantidad_aportada'] ?? 0),
        ];
    }

    private function fechaOrigen(RepaletizajeDetalle $detalle): ?string
    {
        $fecha = $detalle->snapshot_antes['atributos']['datos_externos']['fecha_embalaje'] ?? null;

        return $fecha === 'MIX' ? null : $fecha;
    }

    private function fecha(string $valor): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $partes) === 1) {
            return "{$partes[3]}-{$partes[2]}-{$partes[1]}";
        }

        return $valor;
    }

    /**
     * @param  array<int, array{fecha:string, turno:string, tarjador:string, numero:int, bloques:array<int, array<string, mixed>>}>  $paginas
     */
    private function generarPaginas(array $paginas): string
    {
        $plantilla = resource_path(self::PLANTILLA);
        if (is_file($plantilla) === false) {
            throw new RuntimeException('No se encuentra la plantilla RRPL-01.');
        }

        $ruta = $this->rutaTemporal();
        if (copy($plantilla, $ruta) === false) {
            throw new RuntimeException('No fue posible preparar la plantilla RRPL-01.');
        }

        $zip = new ZipArchive;
        $abierto = false;

        try {
            if ($zip->open($ruta) !== true) {
                throw new RuntimeException('No fue posible abrir la plantilla RRPL-01.');
            }
            $abierto = true;

            $zip->addFromString('xl/styles.xml', $this->prepararEstilos($this->entrada($zip, 'xl/styles.xml')));
            $hojaPlantilla = $this->entrada($zip, 'xl/worksheets/sheet1.xml');
            $relacionesHoja = $this->relacionesSinImpresora($this->entrada($zip, 'xl/worksheets/_rels/sheet1.xml.rels'));
            $dibujo = $this->entrada($zip, 'xl/drawings/drawing1.xml');
            $relacionesDibujo = $this->entrada($zip, 'xl/drawings/_rels/drawing1.xml.rels');
            // La configuración de impresora del autor no se comparte entre hojas.
            $zip->deleteName('xl/printerSettings/printerSettings1.bin');

            foreach ($paginas as $indice => $pagina) {
                $numero = $indice + 1;
                $zip->addFromString("xl/worksheets/sheet{$numero}.xml", $this->completarHoja($hojaPlantilla, $pagina));
                $zip->addFromString(
                    "xl/worksheets/_rels/sheet{$numero}.xml.rels",
                    str_replace('drawings/drawing1.xml', "drawings/drawing{$numero}.xml", $relacionesHoja),
                );
                $zip->addFromString("xl/drawings/drawing{$numero}.xml", $dibujo);
                $zip->addFromString("xl/drawings/_rels/drawing{$numero}.xml.rels", $relacionesDibujo);
            }

            $zip->addFromString('xl/workbook.xml', $this->actualizarLibro($this->entrada($zip, 'xl/workbook.xml'), $paginas));
            $zip->addFromString(
                'xl/_rels/workbook.xml.rels',
                $this->actualizarRelacionesLibro($this->entrada($zip, 'xl/_rels/workbook.xml.rels'), count($paginas)),
            );
            $zip->addFromString(
                '[Content_Types].xml',
                $this->actualizarTiposContenido($this->entrada($zip, '[Content_Types].xml'), count($paginas)),
            );

            if ($zip->close() === false) {
                throw new RuntimeException('No fue posible finalizar el registro RRPL-01.');
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
     * @param  array{fecha:string, turno:string, tarjador:string, numero:int, bloques:array<int, array<string, mixed>>}  $pagina
     */
    private function completarHoja(string $xml, array $pagina): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);

        $this->texto($documento, $xpath, 'B5', $pagina['turno']);
        if ($this->estiloEncabezado !== null) {
            $this->celda($xpath, 'B5')->setAttribute('s', (string) $this->estiloEncabezado);
        }
        $this->texto($documento, $xpath, 'F5', $pagina['fecha']);
        $this->texto($documento, $xpath, 'N5', $pagina['tarjador']);

        for ($indice = 0; $indice < self::BLOQUES_POR_HOJA; $indice += 1) {
            $this->completarBloque($documento, $xpath, $indice, $pagina['bloques'][$indice] ?? null);
        }

        $vista = $xpath->query('/m:worksheet/m:sheetViews/m:sheetView')->item(0);
        if ($vista instanceof DOMElement) {
            $vista->removeAttribute('tabSelected');
            $vista->setAttribute('zoomScale', '60');
            $vista->setAttribute('zoomScaleNormal', '60');
        }
        $impresion = $xpath->query('/m:worksheet/m:pageSetup')->item(0);
        if ($impresion instanceof DOMElement) {
            $impresion->removeAttributeNS(self::NS_REL_DOCUMENTO, 'id');
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible completar una hoja RRPL-01.');
    }

    /** @param  array<string, mixed>|null  $bloque */
    private function completarBloque(DOMDocument $documento, DOMXPath $xpath, int $indice, ?array $bloque): void
    {
        $columnas = $indice % 2 === 1 ? 11 : 0;
        $filas = $indice >= 2 ? 21 : 0;
        $celda = fn (string $columna, int $fila): string => $this->columna($columna, $columnas).($fila + $filas);

        $folio = $bloque === null ? null : $bloque['folio'];
        if ($bloque !== null && $bloque['anulada']) {
            $folio = trim($bloque['folio']."\nANULADA".(filled($bloque['motivo']) ? "\n".$bloque['motivo'] : ''));
        }
        $this->texto($documento, $xpath, $celda('A', 8), $folio);
        if ($bloque !== null && $bloque['anulada'] && $this->estiloFolioConAjuste !== null) {
            $this->celda($xpath, $celda('A', 8))->setAttribute('s', (string) $this->estiloFolioConAjuste);
        }

        $this->texto($documento, $xpath, $celda('B', 16), $bloque['variedad'] ?? null);
        $this->texto($documento, $xpath, $celda('B', 18), $bloque['etiqueta'] ?? null);
        $this->texto($documento, $xpath, $celda('B', 20), $bloque['embalaje'] ?? null);
        $this->texto($documento, $xpath, $celda('B', 22), $bloque['repa'] ?? null);
        $this->texto($documento, $xpath, $celda('B', 25), ($bloque['estado'] ?? null) === 'completo' ? 'X' : null);
        $this->texto($documento, $xpath, $celda('C', 25), ($bloque['estado'] ?? null) === 'saldo' ? 'X' : null);
        if ($this->estiloMarca !== null) {
            $this->celda($xpath, $celda('B', 25))->setAttribute('s', (string) $this->estiloMarca);
            $this->celda($xpath, $celda('C', 25))->setAttribute('s', (string) $this->estiloMarca);
        }

        for ($linea = 0; $linea < self::LINEAS_POR_BLOQUE; $linea += 1) {
            $fila = 8 + ($linea * 2);
            $datos = $bloque['lineas'][$linea] ?? null;
            $this->texto($documento, $xpath, $celda('E', $fila), $datos['folio'] ?? null);
            $this->texto($documento, $xpath, $celda('G', $fila), $datos['fecha'] ?? null);
            $this->texto($documento, $xpath, $celda('H', $fila), $datos['calibre'] ?? null);
            $this->texto($documento, $xpath, $celda('I', $fila), $datos['csg'] ?? null);
            if ($datos === null) {
                $this->limpiar($xpath, $celda('J', $fila));
            } else {
                $this->numero($documento, $xpath, $celda('J', $fila), (int) $datos['cajas']);
            }
        }

        if ($bloque === null) {
            $this->limpiar($xpath, $celda('J', 24));
        } elseif ($bloque['total'] === null) {
            $this->texto($documento, $xpath, $celda('J', 24), 'Continúa');
        } else {
            $this->numero($documento, $xpath, $celda('J', 24), (int) $bloque['total']);
        }
    }

    private function columna(string $columna, int $desplazamiento): string
    {
        $numero = 0;
        foreach (str_split($columna) as $letra) {
            $numero = ($numero * 26) + (ord($letra) - 64);
        }
        $numero += $desplazamiento;
        $resultado = '';
        while ($numero > 0) {
            $resto = ($numero - 1) % 26;
            $resultado = chr(65 + $resto).$resultado;
            $numero = intdiv($numero - 1, 26);
        }

        return $resultado;
    }

    /**
     * Agrega a la plantilla dos estilos derivados de los suyos: el recuadro
     * FOLIO COMPLETO con ajuste de texto (para la marca ANULADA) y la X de
     * ESTADO PALLET centrada con la fuente del formulario.
     */
    private function prepararEstilos(string $xml): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $estilos = $xpath->query('/m:styleSheet/m:cellXfs')->item(0);
        if (! $estilos instanceof DOMElement) {
            return $xml;
        }

        $hoja = $this->documento($this->hojaPlantilla());
        $xpathHoja = new DOMXPath($hoja);
        $xpathHoja->registerNamespace('m', self::NS_HOJA);
        $estiloDe = fn (string $referencia): int => (int) $this->celda($xpathHoja, $referencia)->getAttribute('s');
        $this->estiloEncabezado = $estiloDe('F5');
        $fuenteFormulario = $this->xf($estilos, $estiloDe('B16'))?->getAttribute('fontId');

        $this->estiloFolioConAjuste = $this->derivarEstilo($documento, $estilos, $estiloDe('A8'), null);
        $this->estiloMarca = $this->derivarEstilo($documento, $estilos, $estiloDe('B25'), $fuenteFormulario);

        return $documento->saveXML() ?: $xml;
    }

    private function xf(DOMElement $estilos, int $indice): ?DOMElement
    {
        $xf = $estilos->getElementsByTagNameNS(self::NS_HOJA, 'xf')->item($indice);

        return $xf instanceof DOMElement ? $xf : null;
    }

    private function derivarEstilo(DOMDocument $documento, DOMElement $estilos, int $base, ?string $fuente): ?int
    {
        $modelo = $this->xf($estilos, $base);
        $nuevo = $modelo?->cloneNode(true);
        if (! $nuevo instanceof DOMElement) {
            return null;
        }

        $alineacion = $nuevo->getElementsByTagNameNS(self::NS_HOJA, 'alignment')->item(0);
        if (! $alineacion instanceof DOMElement) {
            $alineacion = $documento->createElementNS(self::NS_HOJA, 'alignment');
            $nuevo->appendChild($alineacion);
        }
        $alineacion->setAttribute('horizontal', 'center');
        $alineacion->setAttribute('vertical', 'center');
        $alineacion->setAttribute('wrapText', '1');
        $nuevo->setAttribute('applyAlignment', '1');
        if ($fuente !== null && $fuente !== '') {
            $nuevo->setAttribute('fontId', $fuente);
            $nuevo->setAttribute('applyFont', '1');
        }

        $estilos->appendChild($nuevo);
        $cantidad = $estilos->getElementsByTagNameNS(self::NS_HOJA, 'xf')->length;
        $estilos->setAttribute('count', (string) $cantidad);

        return $cantidad - 1;
    }

    private function hojaPlantilla(): string
    {
        $zip = new ZipArchive;
        if ($zip->open(resource_path(self::PLANTILLA)) !== true) {
            throw new RuntimeException('No fue posible abrir la plantilla RRPL-01.');
        }

        try {
            return $this->entrada($zip, 'xl/worksheets/sheet1.xml');
        } finally {
            $zip->close();
        }
    }

    private function relacionesSinImpresora(string $xml): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('r', self::NS_REL_PAQUETE);
        foreach ($xpath->query('/r:Relationships/r:Relationship[contains(@Type, "/printerSettings")]') as $relacion) {
            $relacion->parentNode?->removeChild($relacion);
        }

        return $documento->saveXML() ?: $xml;
    }

    /**
     * @param  array<int, array{fecha:string, turno:string, tarjador:string, numero:int, bloques:array<int, array<string, mixed>>}>  $paginas
     */
    private function actualizarLibro(string $xml, array $paginas): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $hojas = $xpath->query('/m:workbook/m:sheets')->item(0);
        if (! $hojas instanceof DOMElement) {
            throw new RuntimeException('La plantilla RRPL-01 no contiene la colección de hojas.');
        }

        while ($hojas->firstChild) {
            $hojas->removeChild($hojas->firstChild);
        }

        $nombres = [];
        foreach ($paginas as $indice => $pagina) {
            $numero = $indice + 1;
            $nombre = $this->nombreHoja($pagina, $numero);
            while (in_array($nombre, $nombres, true)) {
                $nombre = mb_substr($nombre, 0, 27).'-'.$numero;
            }
            $nombres[] = $nombre;

            $hoja = $documento->createElementNS(self::NS_HOJA, 'sheet');
            $hoja->setAttribute('name', $nombre);
            $hoja->setAttribute('sheetId', (string) $numero);
            $hoja->setAttributeNS(self::NS_REL_DOCUMENTO, 'r:id', $numero === 1 ? 'rId1' : 'rId'.($numero + 3));
            $hojas->appendChild($hoja);
        }

        foreach ($xpath->query('/m:workbook/*[local-name()="AlternateContent" or local-name()="revisionPtr"]') as $nodo) {
            $nodo->parentNode?->removeChild($nodo);
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar el libro RRPL-01.');
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

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar las relaciones RRPL-01.');
    }

    private function actualizarTiposContenido(string $xml, int $cantidad): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('c', self::NS_CONTENT_TYPES);
        $raiz = $documento->documentElement;

        for ($numero = 2; $numero <= $cantidad; $numero += 1) {
            foreach ([
                "/xl/worksheets/sheet{$numero}.xml" => 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml',
                "/xl/drawings/drawing{$numero}.xml" => 'application/vnd.openxmlformats-officedocument.drawing+xml',
            ] as $parte => $tipo) {
                $nodo = $documento->createElementNS(self::NS_CONTENT_TYPES, 'Override');
                $nodo->setAttribute('PartName', $parte);
                $nodo->setAttribute('ContentType', $tipo);
                $raiz?->appendChild($nodo);
            }
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar los tipos RRPL-01.');
    }

    /** @param  array{fecha:string, turno:string, tarjador:string, numero:int, bloques:array<int, array<string, mixed>>}  $pagina */
    private function nombreHoja(array $pagina, int $numero): string
    {
        if ($pagina['fecha'] === '' && $pagina['turno'] === '') {
            return 'RRPL-01 en blanco';
        }

        $iniciales = collect(preg_split('/\s+/', trim($pagina['tarjador'])) ?: [])
            ->filter()
            ->map(fn (string $parte): string => mb_strtoupper(mb_substr($parte, 0, 1)))
            ->take(3)
            ->implode('');
        $nombre = sprintf('%s-%s-%s-%02d', str_replace('-', '', $pagina['fecha']), $pagina['turno'], $iniciales ?: 'X', $pagina['numero']);

        return mb_substr(preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '-', $nombre) ?: "Registro-{$numero}", 0, 31);
    }

    private function limpiar(DOMXPath $xpath, string $referencia): void
    {
        $celda = $this->celda($xpath, $referencia);
        while ($celda->firstChild) {
            $celda->removeChild($celda->firstChild);
        }
        $celda->removeAttribute('t');
    }

    private function texto(DOMDocument $documento, DOMXPath $xpath, string $referencia, mixed $valor): void
    {
        $this->limpiar($xpath, $referencia);
        if ($valor === null || $valor === '') {
            return;
        }

        $celda = $this->celda($xpath, $referencia);
        $celda->setAttribute('t', 'inlineStr');
        $inline = $documento->createElementNS(self::NS_HOJA, 'is');
        $texto = $documento->createElementNS(self::NS_HOJA, 't');
        $texto->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $texto->appendChild($documento->createTextNode((string) $valor));
        $inline->appendChild($texto);
        $celda->appendChild($inline);
    }

    private function numero(DOMDocument $documento, DOMXPath $xpath, string $referencia, int $valor): void
    {
        $this->limpiar($xpath, $referencia);
        $numero = $documento->createElementNS(self::NS_HOJA, 'v');
        $numero->appendChild($documento->createTextNode((string) $valor));
        $this->celda($xpath, $referencia)->appendChild($numero);
    }

    private function celda(DOMXPath $xpath, string $referencia): DOMElement
    {
        $celda = $xpath->query("//m:c[@r='{$referencia}']")->item(0);
        if (! $celda instanceof DOMElement) {
            throw new RuntimeException("La plantilla RRPL-01 no contiene la celda {$referencia}.");
        }

        return $celda;
    }

    private function documento(string $xml): DOMDocument
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->preserveWhiteSpace = false;
        $documento->formatOutput = false;
        if ($documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) === false) {
            throw new RuntimeException('La plantilla RRPL-01 contiene XML inválido.');
        }

        return $documento;
    }

    private function entrada(ZipArchive $zip, string $nombre): string
    {
        $contenido = $zip->getFromName($nombre);
        if (! is_string($contenido)) {
            throw new RuntimeException("La plantilla RRPL-01 no contiene {$nombre}.");
        }

        return $contenido;
    }

    private function rutaTemporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'estiba-rrpl-');
        if ($base === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal RRPL-01.');
        }

        File::delete($base);

        return $base.'.xlsx';
    }
}
