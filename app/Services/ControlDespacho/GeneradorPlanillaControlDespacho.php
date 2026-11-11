<?php

namespace App\Services\ControlDespacho;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Llena la plantilla oficial POEMP R-24 con una planilla de consolidación.
 *
 * Cada hoja trae 22 posiciones (impares a la izquierda, pares a la derecha). Un camión con
 * más posiciones continúa en otra hoja con el mismo encabezado.
 */
class GeneradorPlanillaControlDespacho
{
    public const POSICIONES_POR_HOJA = 22;

    private const PLANTILLA = 'templates/control-despacho/poemp-r-24.xlsx';

    private const NOMBRE_HOJA = 'PLANILLA CONTROL DESPACHO';

    private const PRIMERA_FILA_PALLETS = 33;

    private const NS_HOJA = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL_DOCUMENTO = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_REL_PAQUETE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** Celdas de texto del encabezado. */
    private const ENCABEZADO = [
        'tipo_operacion' => 'U5', 'exportadora' => 'F6', 'frigorifico' => 'F7', 'empresa_transporte' => 'U7',
        'referencia_embarque' => 'F8', 'conductor' => 'U8', 'rut_conductor' => 'U9', 'celular_conductor' => 'U10',
        'numero_guia' => 'F11', 'patente_camion' => 'U11', 'patente_carro' => 'AA11',
        'empresa_consolidacion' => 'F12', 'contenedor' => 'U12', 'recibidor' => 'F13', 'destino' => 'K13',
        'nave' => 'F14', 'naviera' => 'K14', 'sello_planta' => 'U14', 'sello_naviera' => 'AA14',
        'puerto_zarpe' => 'F15', 'puerto_destino' => 'K15', 'temperatura_seteo' => 'U15', 'especie' => 'F16', 'ventilacion' => 'U16',
        'termografo_1' => 'H17', 'termografo_1_pallet' => 'U17', 'termografo_2' => 'H18', 'termografo_2_pallet' => 'U18',
        'termografo_3' => 'H19', 'termografo_3_pallet' => 'U19', 'termografo_control' => 'H20', 'termografo_control_pallet' => 'U20',
    ];

    /** Inspección visual: celda «SI» y celda «NO» de cada punto. */
    private const INSPECCION = [
        'enfriado_previo' => ['M23', 'N23'], 'horometro' => ['M24', 'N24'], 'termostato' => ['M25', 'N25'],
        'chute_aire' => ['M26', 'N26'], 'puertas' => ['M27', 'N27'], 'danos_visuales' => ['M28', 'N28'],
        'objetos_olores' => ['M29', 'N29'], 'trinca' => ['M30', 'N30'], 'linea_roja' => ['AB23', 'AD23'],
        'carton_piso' => ['AB24', 'AD24'], 'termografos_norma' => ['AB25', 'AD25'], 'unidad_frio_funciona' => ['AB26', 'AD26'],
        'presencia_consolidacion' => ['AB27', 'AD27'],
    ];

    /** Etiquetado: celda «CUMPLE» y celda «NO CUMPLE». */
    private const ETIQUETADO = [
        'etiquetado_asia' => ['S50', 'T50'], 'etiquetado_america' => ['S52', 'T52'], 'etiquetado_cliente' => ['S54', 'T54'],
    ];

    private const TIPOS_PALLET = [
        'pallets_taco_1000' => 'AD49', 'pallets_chep_1000' => 'AD50', 'pallets_yugo_1000' => 'AD51',
        'pallets_extendido_1016' => 'AD52', 'pallets_taco_800' => 'AD53',
    ];

    /** Líneas de observaciones, de arriba hacia abajo. */
    private const OBSERVACIONES = [['E48', 32], ['E49', 32], ['B50', 44], ['B51', 44], ['B52', 44], ['B53', 44]];

    /**
     * Celdas fusionadas que la plantilla parte en dos y aquí se unen para que el valor quepa.
     *
     * @var array<string, array<int, string>>
     */
    private const UNIR = ['F6:N6' => ['F6:I6', 'J6:N6']];

    /**
     * Celdas que toman el estilo de otra: la plantilla les da formato de rótulo (letra chica o
     * alineada a la derecha) y aquí llevan un valor.
     *
     * @var array<string, string>
     */
    private const ESTILO_DE = ['F6' => 'F7', 'K15' => 'F15', 'F16' => 'F15', 'E48' => 'B50', 'E49' => 'B50'];

    /** Datos del contenedor: letra normal que se reduce si el valor no cabe. */
    private const AJUSTADAS = ['U13', 'Y13', 'AB13'];

    /** Estilo agregado a la plantilla: valor con «reducir hasta ajustar». */
    private ?int $estiloAjustado = null;

    /**
     * @param  array<string, mixed>  $planilla  Detalle de {@see PlanillaControlDespacho::detalle()}.
     * @return string Ruta del archivo temporal generado.
     */
    public function generar(array $planilla): string
    {
        $plantilla = resource_path(self::PLANTILLA);
        if (! is_file($plantilla)) {
            throw new RuntimeException('No se encuentra la plantilla POEMP R-24.');
        }
        $ruta = $this->rutaTemporal();
        if (! copy($plantilla, $ruta)) {
            throw new RuntimeException('No fue posible preparar la plantilla POEMP R-24.');
        }

        $paginas = $this->paginas($planilla['pallets'] ?? []);
        $zip = new ZipArchive;
        $abierto = false;
        try {
            if ($zip->open($ruta) !== true) {
                throw new RuntimeException('No fue posible abrir la plantilla POEMP R-24.');
            }
            $abierto = true;
            $hoja = $this->entrada($zip, 'xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/styles.xml', $this->prepararEstilos($this->entrada($zip, 'xl/styles.xml'), $hoja));
            $relacionesHoja = $this->sinImpresora($this->entrada($zip, 'xl/worksheets/_rels/sheet1.xml.rels'));
            $dibujo = $this->entrada($zip, 'xl/drawings/drawing1.xml');
            $relacionesDibujo = $this->entrada($zip, 'xl/drawings/_rels/drawing1.xml.rels');
            $zip->deleteName('xl/printerSettings/printerSettings1.bin');

            foreach ($paginas as $indice => $pallets) {
                $numero = $indice + 1;
                $zip->addFromString("xl/worksheets/sheet{$numero}.xml", $this->completarHoja($hoja, $planilla, $pallets, $indice, count($paginas)));
                $zip->addFromString("xl/worksheets/_rels/sheet{$numero}.xml.rels", str_replace('drawings/drawing1.xml', "drawings/drawing{$numero}.xml", $relacionesHoja));
                $zip->addFromString("xl/drawings/drawing{$numero}.xml", $dibujo);
                $zip->addFromString("xl/drawings/_rels/drawing{$numero}.xml.rels", $relacionesDibujo);
            }
            $zip->addFromString('xl/workbook.xml', $this->libro($this->entrada($zip, 'xl/workbook.xml'), count($paginas)));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relacionesLibro($this->entrada($zip, 'xl/_rels/workbook.xml.rels'), count($paginas)));
            $zip->addFromString('[Content_Types].xml', $this->tiposContenido($this->entrada($zip, '[Content_Types].xml'), count($paginas)));

            if (! $zip->close()) {
                throw new RuntimeException('No fue posible finalizar la planilla POEMP R-24.');
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

    /**
     * Agrupa los pallets por posición y por hoja. Dos folios que comparten posición van en la
     * misma fila.
     *
     * @param  array<int, array<string, mixed>>  $pallets
     * @return array<int, array<int, array<int, array<string, mixed>>>> hoja => posición => pallets
     */
    public function paginas(array $pallets): array
    {
        $porPosicion = [];
        foreach ($pallets as $pallet) {
            $posicion = $pallet['posicion']['posicion'] ?? null;
            if ($posicion !== null) {
                $porPosicion[(int) $posicion][] = $pallet;
            }
        }
        ksort($porPosicion);
        $hojas = max(1, (int) ceil((max(array_keys($porPosicion) ?: [0])) / self::POSICIONES_POR_HOJA));
        $paginas = array_fill(0, $hojas, []);
        foreach ($porPosicion as $posicion => $grupo) {
            $paginas[intdiv($posicion - 1, self::POSICIONES_POR_HOJA)][$posicion] = $grupo;
        }

        return $paginas;
    }

    /**
     * @param  array<string, mixed>  $planilla
     * @param  array<int, array<int, array<string, mixed>>>  $pallets
     */
    private function completarHoja(string $xml, array $planilla, array $pallets, int $indice, int $total): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $this->unirCeldas($documento, $xpath);
        foreach (self::ESTILO_DE as $destino => $origen) {
            $this->celda($documento, $xpath, $destino)->setAttribute('s', $this->celda($documento, $xpath, $origen)->getAttribute('s'));
        }
        if ($this->estiloAjustado !== null) {
            foreach (self::AJUSTADAS as $referencia) {
                $this->celda($documento, $xpath, $referencia)->setAttribute('s', (string) $this->estiloAjustado);
            }
        }
        $encabezado = $planilla['encabezado'] ?? [];
        $cierre = $planilla['cierre'] ?? [];

        if ($total > 1) {
            $this->texto($documento, $xpath, 'G4', sprintf(' LOCALIDAD: RENGO, CARRETERA 5 SUR, KM 108, ROSARIO, COMUNA DE RENGO · Hoja %d de %d', $indice + 1, $total));
        }
        $tipoFrigorifico = $encabezado['frigorifico_tipo'] ?? null;
        $this->texto($documento, $xpath, 'F5', ($tipoFrigorifico === 'agrorosario' ? 'X ' : '').'AGROROSARIO');
        $this->texto($documento, $xpath, 'J5', $tipoFrigorifico === 'otra' ? 'X OTRA: '.($encabezado['frigorifico'] ?? '') : 'OTRA (Especificar)');
        foreach (self::ENCABEZADO as $clave => $celda) {
            $this->texto($documento, $xpath, $celda, $encabezado[$clave] ?? null);
        }
        $this->marca($documento, $xpath, 'Y6', ($encabezado['tipo_camion'] ?? null) === 'contenedor');
        $this->marca($documento, $xpath, 'AD6', ($encabezado['tipo_camion'] ?? null) === 'thermo');
        $this->texto($documento, $xpath, 'F9', 'Fecha: '.$this->fecha($encabezado['fecha_instructivo'] ?? null));
        $this->texto($documento, $xpath, 'K9', 'Hora: '.($encabezado['hora_instructivo'] ?? ''));
        $this->texto($documento, $xpath, 'F10', 'Fecha: '.$this->fecha($encabezado['fecha_carga'] ?? null));
        $this->texto($documento, $xpath, 'K10', sprintf('Inicio %s · Término %s', $encabezado['hora_inicio'] ?? '', $encabezado['hora_termino'] ?? ''));
        $this->texto($documento, $xpath, 'U13', 'Año '.($encabezado['contenedor_anio'] ?? ''));
        $this->texto($documento, $xpath, 'Y13', 'Tara '.($encabezado['contenedor_tara'] ?? ''));
        $this->texto($documento, $xpath, 'AB13', 'P. bruto '.($encabezado['contenedor_peso_bruto'] ?? ''));
        $this->texto($documento, $xpath, 'AB15', $this->siNo($encabezado['temperatura_cumple'] ?? null) ?? 'SI/NO');
        $this->texto($documento, $xpath, 'AB16', $this->siNo($encabezado['ventilacion_cumple'] ?? null) ?? 'SI/NO');

        foreach (self::INSPECCION as $clave => [$si, $no]) {
            $valor = ($planilla['inspeccion'] ?? [])[$clave] ?? null;
            $this->texto($documento, $xpath, $si, match ($valor) {
                'si' => 'X',
                'na' => 'N/A',
                default => null,
            });
            $this->marca($documento, $xpath, $no, $valor === 'no');
        }

        for ($fila = 0; $fila < self::POSICIONES_POR_HOJA / 2; $fila++) {
            foreach ([[1, 'B', 'C', 'D', 'J', 'M'], [2, 'Q', 'S', 'T', 'Y', 'AB']] as [$lado, $nro, $pe, $folio, $cajas, $temperatura]) {
                $posicion = $indice * self::POSICIONES_POR_HOJA + $fila * 2 + $lado;
                $grupo = $pallets[$posicion] ?? [];
                $r = self::PRIMERA_FILA_PALLETS + $fila;
                $this->numero($documento, $xpath, $nro.$r, $posicion);
                $this->texto($documento, $xpath, $pe.$r, collect($grupo)->pluck('posicion.codigo_pe')->filter()->unique()->implode(' / ') ?: null);
                $this->texto($documento, $xpath, $folio.$r, collect($grupo)->pluck('numero_folio')->implode(' / ') ?: null);
                $this->numero($documento, $xpath, $cajas.$r, $grupo === [] ? null : (int) collect($grupo)->sum(fn (array $p): int => (int) ($p['posicion']['cajas'] ?? 0)));
                $this->texto($documento, $xpath, $temperatura.$r, collect($grupo)->pluck('posicion.temperatura')->filter(fn ($t): bool => $t !== null)
                    ->map(fn ($t): string => number_format((float) $t, 1, ',', ''))->implode(' / ') ?: null);
            }
        }
        $this->numero($documento, $xpath, 'M44', (int) (($planilla['totales'] ?? [])['cajas_pcd'] ?? 0));
        $this->numero($documento, $xpath, 'AB44', isset($cierre['cajas_guia']) ? (int) $cierre['cajas_guia'] : null);
        $this->texto($documento, $xpath, 'I46', $cierre['sello_lavado'] ?? null);

        $lineas = $this->lineas((string) ($cierre['observaciones'] ?? ''));
        foreach (self::OBSERVACIONES as $orden => [$celda]) {
            $this->texto($documento, $xpath, $celda, $lineas[$orden] ?? null);
        }
        foreach (self::ETIQUETADO as $clave => [$cumple, $noCumple]) {
            $valor = $cierre[$clave] ?? null;
            $this->texto($documento, $xpath, $cumple, match ($valor) {
                'cumple' => 'X',
                'na' => 'N/A',
                default => null,
            });
            $this->marca($documento, $xpath, $noCumple, $valor === 'no_cumple');
        }
        $this->texto($documento, $xpath, 'N53', $cierre['etiquetado_cliente_nombre'] ?? null);
        $this->texto($documento, $xpath, 'H54', match ($cierre['walmart'] ?? null) {
            'si' => 'SI',
            'no' => 'NO',
            'na' => 'N/A',
            default => 'SI/NO',
        });
        $this->texto($documento, $xpath, 'O54', $cierre['walmart_etiqueta'] ?? null);
        $totalPallets = 0;
        foreach (self::TIPOS_PALLET as $clave => $celda) {
            $cantidad = isset($cierre[$clave]) ? (int) $cierre[$clave] : null;
            $totalPallets += $cantidad ?? 0;
            $this->numero($documento, $xpath, $celda, $cantidad);
        }
        $this->numero($documento, $xpath, 'AD54', $totalPallets > 0 ? $totalPallets : null);
        $this->texto($documento, $xpath, 'B56', 'RESPONSABLE: '.($cierre['responsable'] ?? '____________________________'));
        $this->texto($documento, $xpath, 'M56', 'JEFE DE SECCION: '.($cierre['jefe_seccion'] ?? '________________________________'));
        $this->texto($documento, $xpath, 'B60', $this->pie($planilla));

        $vista = $xpath->query('/m:worksheet/m:sheetViews/m:sheetView')->item(0);
        if ($vista instanceof DOMElement && $indice > 0) {
            $vista->removeAttribute('tabSelected');
        }
        $impresion = $xpath->query('/m:worksheet/m:pageSetup')->item(0);
        if ($impresion instanceof DOMElement) {
            $impresion->removeAttributeNS(self::NS_REL_DOCUMENTO, 'id');
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible completar la planilla POEMP R-24.');
    }

    /** @param  array<string, mixed>  $planilla */
    private function pie(array $planilla): string
    {
        $zona = config('app.operational_timezone', config('app.timezone'));
        $partes = ['Registrada en FoliOS', 'Carga '.(($planilla['carga'] ?? [])['codigo'] ?? '')];
        if (! empty($planilla['cerrado_at'])) {
            $partes[] = sprintf('Cerrada %s por %s', CarbonImmutable::parse($planilla['cerrado_at'])->setTimezone($zona)->format('d-m-Y H:i'), $planilla['cerrado_por'] ?? '');
        } else {
            $partes[] = 'BORRADOR: planilla sin cerrar';
        }

        return implode(' · ', $partes);
    }

    /** @return array<int, string> */
    private function lineas(string $texto): array
    {
        $lineas = [];
        $palabras = preg_split('/\s+/u', trim($texto)) ?: [];
        $actual = '';
        $orden = 0;
        foreach ($palabras as $palabra) {
            if ($palabra === '') {
                continue;
            }
            $ancho = self::OBSERVACIONES[min($orden, count(self::OBSERVACIONES) - 1)][1];
            $candidata = $actual === '' ? $palabra : $actual.' '.$palabra;
            if (mb_strlen($candidata) > $ancho && $actual !== '') {
                $lineas[] = $actual;
                $orden++;
                $actual = $palabra;
            } else {
                $actual = $candidata;
            }
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }
        $maximo = count(self::OBSERVACIONES);
        if (count($lineas) > $maximo) {
            $lineas = [...array_slice($lineas, 0, $maximo - 1), implode(' ', array_slice($lineas, $maximo - 1))];
        }

        return $lineas;
    }

    private function siNo(?string $valor): ?string
    {
        return match ($valor) {
            'si' => 'SI',
            'no' => 'NO',
            default => null,
        };
    }

    private function fecha(?string $valor): string
    {
        if (! $valor || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return (string) $valor;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $valor)->format('d-m-Y');
    }

    private function prepararEstilos(string $xml, string $hoja): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_HOJA);
        $estilos = $xpath->query('/m:styleSheet/m:cellXfs')->item(0);
        $documentoHoja = $this->documento($hoja);
        $xpathHoja = new DOMXPath($documentoHoja);
        $xpathHoja->registerNamespace('m', self::NS_HOJA);
        $base = (int) $this->celda($documentoHoja, $xpathHoja, 'U12')->getAttribute('s');
        $modelo = $estilos instanceof DOMElement ? $estilos->getElementsByTagNameNS(self::NS_HOJA, 'xf')->item($base) : null;
        $nuevo = $modelo?->cloneNode(true);
        if (! $estilos instanceof DOMElement || ! $nuevo instanceof DOMElement) {
            return $xml;
        }
        $alineacion = $nuevo->getElementsByTagNameNS(self::NS_HOJA, 'alignment')->item(0);
        if (! $alineacion instanceof DOMElement) {
            $alineacion = $documento->createElementNS(self::NS_HOJA, 'alignment');
            $nuevo->appendChild($alineacion);
        }
        $alineacion->setAttribute('vertical', 'center');
        $alineacion->setAttribute('shrinkToFit', '1');
        $alineacion->removeAttribute('wrapText');
        $nuevo->setAttribute('applyAlignment', '1');
        $estilos->appendChild($nuevo);
        $cantidad = $estilos->getElementsByTagNameNS(self::NS_HOJA, 'xf')->length;
        $estilos->setAttribute('count', (string) $cantidad);
        $this->estiloAjustado = $cantidad - 1;

        return $documento->saveXML() ?: $xml;
    }

    private function unirCeldas(DOMDocument $documento, DOMXPath $xpath): void
    {
        $fusiones = $xpath->query('/m:worksheet/m:mergeCells')->item(0);
        if (! $fusiones instanceof DOMElement) {
            return;
        }
        foreach (self::UNIR as $nueva => $partes) {
            foreach (iterator_to_array($xpath->query('m:mergeCell', $fusiones)) as $fusion) {
                if ($fusion instanceof DOMElement && in_array($fusion->getAttribute('ref'), $partes, true)) {
                    $fusiones->removeChild($fusion);
                }
            }
            $elemento = $documento->createElementNS(self::NS_HOJA, 'mergeCell');
            $elemento->setAttribute('ref', $nueva);
            $fusiones->appendChild($elemento);
        }
        $fusiones->setAttribute('count', (string) $xpath->query('m:mergeCell', $fusiones)->length);
    }

    private function marca(DOMDocument $documento, DOMXPath $xpath, string $referencia, bool $marcada): void
    {
        $this->texto($documento, $xpath, $referencia, $marcada ? 'X' : null);
    }

    private function texto(DOMDocument $documento, DOMXPath $xpath, string $referencia, mixed $valor): void
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

    private function numero(DOMDocument $documento, DOMXPath $xpath, string $referencia, ?int $valor): void
    {
        $celda = $this->limpiar($documento, $xpath, $referencia);
        if ($valor === null) {
            return;
        }
        $numero = $documento->createElementNS(self::NS_HOJA, 'v');
        $numero->appendChild($documento->createTextNode((string) $valor));
        $celda->appendChild($numero);
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
    private function celda(DOMDocument $documento, DOMXPath $xpath, string $referencia): DOMElement
    {
        $celda = $xpath->query("//m:c[@r='{$referencia}']")->item(0);
        if ($celda instanceof DOMElement) {
            return $celda;
        }
        preg_match('/^([A-Z]+)(\d+)$/', $referencia, $partes);
        [$columna, $numeroFila] = [$partes[1], (int) $partes[2]];
        $datos = $xpath->query('/m:worksheet/m:sheetData')->item(0);
        if (! $datos instanceof DOMElement) {
            throw new RuntimeException('La plantilla POEMP R-24 no contiene datos de hoja.');
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
        $nombres = $xpath->query('/m:workbook/m:definedNames')->item(0);
        if (! $hojas instanceof DOMElement) {
            throw new RuntimeException('La plantilla POEMP R-24 no contiene la colección de hojas.');
        }
        while ($hojas->firstChild) {
            $hojas->removeChild($hojas->firstChild);
        }
        if ($nombres instanceof DOMElement) {
            while ($nombres->firstChild) {
                $nombres->removeChild($nombres->firstChild);
            }
        }
        for ($numero = 1; $numero <= $cantidad; $numero++) {
            $nombre = $numero === 1 ? self::NOMBRE_HOJA : self::NOMBRE_HOJA." ({$numero})";
            $hoja = $documento->createElementNS(self::NS_HOJA, 'sheet');
            $hoja->setAttribute('name', $nombre);
            $hoja->setAttribute('sheetId', (string) $numero);
            $hoja->setAttributeNS(self::NS_REL_DOCUMENTO, 'r:id', $numero === 1 ? 'rId1' : 'rId'.($numero + 3));
            $hojas->appendChild($hoja);
            if ($nombres instanceof DOMElement) {
                $area = $documento->createElementNS(self::NS_HOJA, 'definedName');
                $area->setAttribute('name', '_xlnm.Print_Area');
                $area->setAttribute('localSheetId', (string) ($numero - 1));
                $area->appendChild($documento->createTextNode("'{$nombre}'!\$B\$1:\$AD\$60"));
                $nombres->appendChild($area);
            }
        }
        foreach (iterator_to_array($xpath->query('/m:workbook/*[local-name()="AlternateContent" or local-name()="revisionPtr"]')) as $nodo) {
            $nodo->parentNode?->removeChild($nodo);
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar el libro POEMP R-24.');
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
            $relacion->setAttribute('Id', $numero === 1 ? 'rId1' : 'rId'.($numero + 3));
            $relacion->setAttribute('Type', self::NS_REL_DOCUMENTO.'/worksheet');
            $relacion->setAttribute('Target', "worksheets/sheet{$numero}.xml");
            $raiz?->appendChild($relacion);
        }

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar las relaciones POEMP R-24.');
    }

    private function tiposContenido(string $xml, int $cantidad): string
    {
        $documento = $this->documento($xml);
        $raiz = $documento->documentElement;
        for ($numero = 2; $numero <= $cantidad; $numero++) {
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

        return $documento->saveXML() ?: throw new RuntimeException('No fue posible actualizar los tipos POEMP R-24.');
    }

    private function sinImpresora(string $xml): string
    {
        $documento = $this->documento($xml);
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('r', self::NS_REL_PAQUETE);
        foreach (iterator_to_array($xpath->query('/r:Relationships/r:Relationship[contains(@Type, "/printerSettings")]')) as $relacion) {
            $relacion->parentNode?->removeChild($relacion);
        }

        return $documento->saveXML() ?: $xml;
    }

    private function documento(string $xml): DOMDocument
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->preserveWhiteSpace = false;
        $documento->formatOutput = false;
        if ($documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) === false) {
            throw new RuntimeException('La plantilla POEMP R-24 contiene XML inválido.');
        }

        return $documento;
    }

    private function entrada(ZipArchive $zip, string $nombre): string
    {
        $contenido = $zip->getFromName($nombre);
        if (! is_string($contenido)) {
            throw new RuntimeException("La plantilla POEMP R-24 no contiene {$nombre}.");
        }

        return $contenido;
    }

    private function rutaTemporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'folios-r24-');
        if ($base === false) {
            throw new RuntimeException('No fue posible crear el archivo temporal POEMP R-24.');
        }
        File::delete($base);

        return $base.'.xlsx';
    }
}
