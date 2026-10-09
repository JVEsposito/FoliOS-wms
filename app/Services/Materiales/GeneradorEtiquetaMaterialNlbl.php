<?php

namespace App\Services\Materiales;

use RuntimeException;

class GeneradorEtiquetaMaterialNlbl
{
    private const PASSWORD = ",^_A5Fus&!?j='Epiq*e";

    private const ZEBRA_DEVMODE = 'eNrt21tv2jAYBuCf5KpSJ3bRi9fEIbQ4zbcVKNxlrI1UaEulThH8+sWcEli6gQYVKe8j5Zw4/mznJMXdRBs02hikeozGAD8SPULQRBM6hi+wCabwDFqCCTzApnqIAIgFCfwXtKEt/mAMcDtA1HvzJgO3nA1BDQ8v9WxGA1liqZsaNz0CLn8uur9t1zdxNxzhWtx83619dnn33TJabuR/l2VaenaYgKgyXjfsO/0vsIfJ+ClfZ5HXU0ql55FStfAxm3XLNTZm+vc1k7WZVVt5/zlcz9vabP/6+nPRUyulaRS2n5Bdq2P7lGtb7Z/XFx1M3t41xBSrU1f75mA2psV4zAHuRebjQotkFctwI5Yd309mbx1BwkcJERER0ek5YxEQERERERERERERHYO64D5lMVCV6K+u3RIRERERfS7DeR9RMa5/aDX+o5d38ln6um4WsS1i/Kh/35flKSYvW1ms/7/PijzNtVjK+g7smyk5dyGeWXxl59+izKVk/3laeq1MV4NbYfMDIqVeq2jenyF0o6tlBSY6/tboTOPuz1/9u2bSHcnETs2bfcS0H+hx0x/LbaGsOr4O24mFEn8Ic3ElZx3be0rPQ1xe/gZsIODH';

    private const BIXOLON_DEVMODE = 'eNrtVstOwkAU/SAXYHDRjYtz22lppYUBRoUlVccEAiaAI3y997aW+iLqQhemp2ln7mMe93TSU20pQWgxdpRCeRhbWAQGKWiCQHELhx9DKdi7HAPXDXaGgJh93TYNlZoCbIN2TlqFsgUuNY2hDIzFX8EvnkV1qvToI5kXHNCUDcNhH8X+DN9Br4jRHOjrtErdYs2l4rqyO5IbWv/jpMFJ6xvMZo6TspXMrxPuKj8XBpeVveILaRxxOWfEe+tJEUo4DUYyPqreiJ/r5Kke16BBgwYNfgEhuvQiJvt7EYz4XQLb4qYYhXAMWgzv1XjWQkvauBgmTD/RDvmAB61CP3StX6uDfs0L1TqkJ6XG1tKTZCZMRiOtah38EsHC5/VS034rokf1mvYIYwwd+bKCdvSIyGHmaCe2ceQQKUwcLQqbfwkQeZhaepC8qWYpjeYS3zQHqobWdIquFh43wt8NhK+UTxSfmchgBuaT7dLvYWYbzv4dBrStunkn298uaX2F/PwZqMsUzA==';

    /**
     * @param  array<int, array<string, mixed>>  $etiquetas
     * @param  array<string, mixed>  $perfil
     */
    public function generar(
        array $etiquetas,
        array $perfil,
        int $copias = 1,
        string $simbologia = 'code128',
    ): string {
        $archivos = [];

        foreach ($etiquetas as $etiqueta) {
            for ($copia = 1; $copia <= $copias; $copia++) {
                $nombre = $this->nombreFormato((string) $etiqueta['numero_folio'], $copia, $copias);
                $archivos["Formats/{$nombre}"] = $this->formato(
                    $etiqueta,
                    $perfil,
                    $simbologia,
                    $nombre,
                );
            }
        }

        $nombreSolucion = 'Etiquetas FoliOS';
        $archivos['Etiquetas FoliOS.slnx'] = $this->solucion(
            $nombreSolucion,
            count($archivos),
        );

        return $this->archivoZipAes($archivos);
    }

    /**
     * @param  array<string, mixed>  $etiqueta
     * @param  array<string, mixed>  $perfil
     */
    private function formato(
        array $etiqueta,
        array $perfil,
        string $simbologia,
        string $nombre,
    ): string {
        [$ancho, $alto] = $this->dimensiones($perfil);
        $margen = max(2500, (int) round($ancho * 0.04));
        $esQrVerticalLargo = $simbologia === 'qr'
            && $alto >= 150000
            && $alto >= ($ancho * 1.5);
        $limiteDetalles = $alto - 1200;
        $folio = (string) $etiqueta['numero_folio'];
        $textoFolio = 'Folio: '.$folio;
        $anchoFolio = $ancho - ($margen * 2);
        $tamanoFolio = $this->tamanoFolio(
            $textoFolio,
            $anchoFolio,
            $esQrVerticalLargo
                ? 16
                : max(14, min(22, (int) round($alto / 3500))),
        );
        $items = [];
        $z = 1;

        $items[] = $this->texto(
            $textoFolio,
            $margen,
            $esQrVerticalLargo
                ? 5000
                : max(1800, (int) round($alto * 0.035)),
            $anchoFolio,
            $esQrVerticalLargo
                ? 18000
                : max(6500, (int) round($alto * 0.13)),
            $tamanoFolio,
            true,
            $z++,
            'Folio',
        );

        if ($simbologia === 'qr') {
            if ($esQrVerticalLargo) {
                $lado = max(18000, min(
                    $ancho - ($margen * 2),
                    (int) round($ancho * 0.55),
                ));
                $modulo = max(500, min(2400, (int) floor($lado / 25)));
                $qrX = intdiv($ancho - $lado, 2);
                $qrY = $alto - $margen - $lado;
                $items[] = $this->qr(
                    $folio,
                    $qrX,
                    $qrY,
                    $modulo,
                    $z++,
                );
                $detalleAncho = $ancho - ($margen * 2);
                $detalleY = 34000;
                $limiteDetalles = $qrY - 4000;
            } else {
                $lado = max(18000, min(
                    (int) round($alto * 0.42),
                    (int) round($ancho * 0.38),
                ));
                $modulo = max(500, min(1800, (int) floor($lado / 25)));
                $items[] = $this->qr(
                    $folio,
                    $ancho - $margen - $lado,
                    max(7000, (int) round($alto * 0.17)),
                    $modulo,
                    $z++,
                );
                $detalleAncho = max(16000, $ancho - ($margen * 3) - $lado);
                $detalleY = max(9500, (int) round($alto * 0.22));
            }
        } else {
            $barcodeY = max(8500, (int) round($alto * 0.17));
            $barcodeAlto = max(9000, (int) round($alto * 0.18));
            $items[] = $this->code128(
                $folio,
                $margen,
                $barcodeY,
                $ancho - ($margen * 2),
                $barcodeAlto,
                $z++,
            );
            $detalleAncho = $ancho - ($margen * 2);
            $detalleY = $barcodeY + $barcodeAlto + max(5000, (int) round($alto * 0.08));
        }

        $lineas = [
            $etiqueta['cliente_codigo'].' · '.$etiqueta['cliente_nombre'],
            $etiqueta['item_codigo'].' · '.$etiqueta['item_nombre'],
            'Cantidad: '.$etiqueta['cantidad'].' '.$etiqueta['unidad_medida'],
            ...$this->lineasOrigen($etiqueta),
        ];
        if ($etiqueta['bloqueado']) {
            $lineas[] = 'BLOQUEADO: '.($etiqueta['motivo_bloqueo'] ?: 'Sin motivo');
        }

        $altoLineaBase = max(4200, (int) round($alto * 0.082));
        $altoDisponible = max(1, $limiteDetalles - $detalleY);
        $altoLinea = min(
            $altoLineaBase,
            max(3200, (int) floor($altoDisponible / max(1, count($lineas)))),
        );
        $tamanoBase = $esQrVerticalLargo
            ? 16
            : max(7, min(12, (int) round($alto / 6200)));
        foreach ($lineas as $indice => $linea) {
            $y = $detalleY + ($indice * $altoLinea);
            if ($y + $altoLinea > $limiteDetalles) {
                break;
            }
            $negrita = $indice < 3
                || ($etiqueta['bloqueado'] && $indice === count($lineas) - 1);
            $items[] = $this->texto(
                (string) $linea,
                $margen,
                $y,
                $detalleAncho,
                $altoLinea,
                $esQrVerticalLargo
                    ? 16
                    : $this->tamanoDetalle(
                        (string) $linea,
                        $detalleAncho,
                        $tamanoBase,
                        $negrita,
                    ),
                $negrita,
                $z++,
                'Detalle '.($indice + 1),
            );
        }

        $impresora = $this->impresora($perfil);
        $idFormato = $this->uuid("formato|{$nombre}|{$ancho}|{$alto}");
        $idDiseno = $this->uuid("diseno|{$nombre}");
        $idEscenario = $this->uuid("impresora|{$impresora['driver']}");
        $idFondo = $this->uuid("fondo|{$nombre}");
        $nombreXml = $this->xml($nombre);
        $itemsXml = $this->sangrar(implode("\n", $items), 8);
        $impresoraNombre = $this->xml($impresora['nombre']);
        $impresoraDriver = $this->xml($impresora['driver']);
        $impresoraBuffer = $impresora['buffer'];

        return $this->bom().'<?xml version="1.0" encoding="utf-8"?>'."\r\n".<<<XML
<EuroPlus.NiceLabel Type="Format">
  <Id>{$idFormato}</Id>
  <Name>{$nombreXml}</Name>
  <SampleValue Type="StringContents" />
  <BackgroundDocumentItem Type="BackgroundDocumentItem">
    <Id>{$idFondo}</Id>
    <Name>Fondo</Name>
    <Color>FFFFFFFF</Color>
    <SampleValue Type="StringContents" />
    <PrintAsGraphics>False</PrintAsGraphics>
    <Geometry Type="RectGeometry"><Width>0</Width><Height>0</Height><Left>0</Left><Top>0</Top><AnchoringPoint>4</AnchoringPoint></Geometry>
    <CropVariableGraphics>False</CropVariableGraphics>
    <ZOrder>0</ZOrder>
    <MergeName>Fondo</MergeName>
  </BackgroundDocumentItem>
  <Media Type="FormatMedia">
    <PaperFormat>256</PaperFormat>
    <PaperDetails Type="PaperDetails"><Id>256</Id><Name>USER</Name><IsCustomEpsonPaper>True</IsCustomEpsonPaper></PaperDetails>
    <Width>{$ancho}</Width>
    <Height>{$alto}</Height>
    <RfidTag Type="RfidTagDefinition"><RfidSecurity Type="RfidTagSecurity"><Version>0</Version></RfidSecurity></RfidTag>
    <EpsonMediaTypeId>0</EpsonMediaTypeId>
    <IsEpsonBorderlessPrintingEnabled>False</IsEpsonBorderlessPrintingEnabled>
  </Media>
  <PageLayout Type="SingleFormatLayout">
    <ElementWidth>{$ancho}</ElementWidth>
    <ElementHeight>{$alto}</ElementHeight>
    <HorizontalRadius>1000</HorizontalRadius>
    <VerticalRadius>1000</VerticalRadius>
  </PageLayout>
  <DocumentDesigns>
    <DocumentDesign Type="FormatDocumentDesign">
      <Id>{$idDiseno}</Id>
      <Name>Etiqueta FoliOS</Name>
      <SampleValue Type="StringContents" />
      <Type>0</Type>
      <Items>
{$itemsXml}
      </Items>
      <Height>{$alto}</Height>
      <Width>{$ancho}</Width>
      <MergeName>Etiqueta FoliOS</MergeName>
    </DocumentDesign>
  </DocumentDesigns>
  <PrintScenarioCollection>
    <PrintScenarioCollection Type="PrintScenario">
      <Id>{$idEscenario}</Id>
      <Name>Escenario de impresora</Name>
      <SampleValue Type="StringContents" />
      <Printer Type="Printer">
        <UseAdvancedInterface>False</UseAdvancedInterface>
        <DevModeBuffer>{$impresoraBuffer}</DevModeBuffer>
        <Name>{$impresoraNombre}</Name>
        <DriverName>{$impresoraDriver}</DriverName>
      </Printer>
      <OptimizeSameCopies>False</OptimizeSameCopies>
      <CutterDefinition Type="CutterDefinition"><CutterProcessingType>0</CutterProcessingType></CutterDefinition>
      <PrintRotation>1</PrintRotation>
      <MergeName>Escenario de impresora</MergeName>
    </PrintScenarioCollection>
  </PrintScenarioCollection>
  <DocumentProperties Type="DocumentProperties" />
  <MergeName>{$nombreXml}</MergeName>
</EuroPlus.NiceLabel>
XML;
    }

    private function code128(
        string $valor,
        int $x,
        int $y,
        int $ancho,
        int $alto,
        int $z,
    ): string {
        $modulos = (11 * (mb_strlen($valor) + 3)) + 13;
        $barra = max(180, min(1200, (int) floor($ancho / max(1, $modulos))));
        $id = $this->uuid("code128|{$valor}|{$x}|{$y}");
        $valorXml = $this->xml($valor);

        return <<<XML
<Item Type="BarcodeDocumentItem">
  <Id>{$id}</Id>
  <Name>Código Code 128</Name>
  <Color>FF000000</Color>
  <BarcodeData Type="Code128BarcodeData">
    <HasCheckDigit>False</HasCheckDigit>
    <AutomaticCheckDigit>True</AutomaticCheckDigit>
    <HasManualEncoding>False</HasManualEncoding>
    <HasQuietZones>True</HasQuietZones>
    <BaseBarWidth>{$barra}</BaseBarWidth>
    <UserBarWidth>{$barra}</UserBarWidth>
    <ModuleHeight>{$alto}</ModuleHeight>
    <UserRatio>0</UserRatio>
    <HumanInterpretationFontDescriptor Type="FontDescriptor">
      <Name>Arial</Name><Height>9</Height><Width>0</Width><Color>FF000000</Color>
      <LogFontWrapper Type="LogFontWrapper"><Height>25</Height><Width>0</Width><Weight>700</Weight><CharSet>1</CharSet><Quality>1</Quality><PitchAndFamily>0</PitchAndFamily><FaceName>Arial</FaceName></LogFontWrapper>
    </HumanInterpretationFontDescriptor>
    <AutoFontScaling>False</AutoFontScaling>
    <IsCustomHumanReadableFont>True</IsCustomHumanReadableFont>
  </BarcodeData>
  <SampleValue Type="StringContents"><StringValue>{$valorXml}</StringValue><UserValue>{$valorXml}</UserValue></SampleValue>
  <PrintAsGraphics>False</PrintAsGraphics>
  <Geometry Type="PositionGeometry"><X>{$x}</X><Y>{$y}</Y></Geometry>
  <FixedContents>{$valorXml}</FixedContents>
  <ZOrder>{$z}</ZOrder>
  <MergeName>Código Code 128</MergeName>
</Item>
XML;
    }

    private function qr(string $valor, int $x, int $y, int $modulo, int $z): string
    {
        $id = $this->uuid("qr|{$valor}|{$x}|{$y}");
        $valorXml = $this->xml($valor);

        return <<<XML
<Item Type="BarcodeDocumentItem">
  <Id>{$id}</Id>
  <Name>Código QR</Name>
  <Color>FF000000</Color>
  <BarcodeData Type="QRBarcodeData">
    <QRBarcodeVersion>0</QRBarcodeVersion>
    <QRBarcodeEncoding>0</QRBarcodeEncoding>
    <QRBarcodeErrorCorrectionLevel>0</QRBarcodeErrorCorrectionLevel>
    <BaseBarWidth>{$modulo}</BaseBarWidth>
    <UserBarWidth>{$modulo}</UserBarWidth>
    <ModuleHeight>0</ModuleHeight>
    <UserRatio>0</UserRatio>
    <HumanInterpretationPosition>0</HumanInterpretationPosition>
    <AutoFontScaling>False</AutoFontScaling>
    <DisplayCheckDigit>False</DisplayCheckDigit>
    <AutomaticCheckDigit>False</AutomaticCheckDigit>
    <CodePage>932</CodePage>
  </BarcodeData>
  <SampleValue Type="StringContents"><StringValue>{$valorXml}</StringValue><UserValue>{$valorXml}</UserValue></SampleValue>
  <PrintAsGraphics>False</PrintAsGraphics>
  <Geometry Type="PositionGeometry"><X>{$x}</X><Y>{$y}</Y></Geometry>
  <FixedContents>{$valorXml}</FixedContents>
  <ZOrder>{$z}</ZOrder>
  <MergeName>Código QR</MergeName>
</Item>
XML;
    }

    private function texto(
        string $valor,
        int $x,
        int $y,
        int $ancho,
        int $alto,
        int $tamano,
        bool $negrita,
        int $z,
        string $nombre,
    ): string {
        $base64 = base64_encode($valor);
        $peso = $negrita ? 700 : 400;
        $id = $this->uuid("texto|{$nombre}|{$valor}|{$x}|{$y}");
        $nombreXml = $this->xml($nombre);
        $centroX = $x + intdiv($ancho, 2);
        $centroY = $y + intdiv($alto, 2);

        return <<<XML
<Item Type="TextDocumentItem">
  <Id>{$id}</Id>
  <Name>{$nombreXml}</Name>
  <FontDescriptor Type="FontDescriptor">
    <Name>Arial</Name><Height>{$tamano}</Height><Width>0</Width><Color>FF000000</Color>
    <LogFontWrapper Type="LogFontWrapper"><Height>-{$tamano}</Height><Width>0</Width><Weight>{$peso}</Weight><CharSet>1</CharSet><Quality>1</Quality><PitchAndFamily>0</PitchAndFamily><FaceName>Arial</FaceName></LogFontWrapper>
  </FontDescriptor>
  <TextType>2</TextType>
  <Color>FF000000</Color>
  <SampleValue Type="StringContents"><StringValue Base64Encoded="true">{$base64}</StringValue></SampleValue>
  <PrintAsGraphics>False</PrintAsGraphics>
  <Geometry Type="RectGeometry"><Width>{$ancho}</Width><Height>{$alto}</Height><Left>{$centroX}</Left><Top>{$centroY}</Top><AnchoringPoint>4</AnchoringPoint></Geometry>
  <FixedContents Base64Encoded="true">{$base64}</FixedContents>
  <Contents Type="ExtendedDataValue"><FixedValue Type="StringContents"><StringValue Base64Encoded="true">{$base64}</StringValue></FixedValue></Contents>
  <BestFitMinimumFontSize>4</BestFitMinimumFontSize>
  <BestFitMaximumFontSize>{$tamano}</BestFitMaximumFontSize>
  <TextBoxAlignment>0</TextBoxAlignment>
  <ZOrder>{$z}</ZOrder>
  <MergeName>{$nombreXml}</MergeName>
</Item>
XML;
    }

    private function solucion(string $nombre, int $formatos): string
    {
        $id = $this->uuid("solucion|{$nombre}");
        $nombreXml = $this->xml($nombre);

        return $this->bom().'<?xml version="1.0" encoding="utf-8"?>'."\r\n".<<<XML
<EuroPlus.NiceLabel>
  <FileVersion>43</FileVersion>
  <SolutionFileVersion Type="SolutionFileVersion"><FileVersion>1</FileVersion></SolutionFileVersion>
  <Id>{$id}</Id>
  <Name>{$nombreXml}</Name>
  <SampleValue Type="StringContents" />
  <SolutionType>1</SolutionType>
  <SolutionSummary Type="SolutionSummary">
    <SolutionName>{$nombreXml}</SolutionName>
    <FormatCount>{$formatos}</FormatCount>
    <HasErrors>False</HasErrors>
  </SolutionSummary>
  <MergeName>{$nombreXml}</MergeName>
</EuroPlus.NiceLabel>
XML;
    }

    /**
     * @param  array<string, string>  $archivos
     */
    private function archivoZipAes(array $archivos): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $cantidad = 0;
        $horaDos = 0;
        $fechaDos = (46 << 9) | (1 << 5) | 1;
        $extra = pack('vvva2Cv', 0x9901, 7, 1, 'AE', 3, 8);

        foreach ($archivos as $nombre => $contenido) {
            $comprimido = gzdeflate($contenido, 9);
            if ($comprimido === false) {
                throw new RuntimeException('No fue posible comprimir la etiqueta NiceLabel.');
            }

            $sal = substr(hash('sha256', $nombre."\0".$contenido, true), 0, 16);
            $llaves = hash_pbkdf2('sha1', self::PASSWORD, $sal, 1000, 66, true);
            $cifrado = $this->aesCtr($comprimido, substr($llaves, 0, 32));
            $datos = $sal
                .substr($llaves, 64, 2)
                .$cifrado
                .substr(hash_hmac('sha1', $cifrado, substr($llaves, 32, 32), true), 0, 10);
            $crc = crc32($contenido);
            $tamanoComprimido = strlen($datos);
            $tamano = strlen($contenido);
            $largoNombre = strlen($nombre);

            $cabecera = pack(
                'VvvvvvVVVvv',
                0x04034B50,
                20,
                1,
                99,
                $horaDos,
                $fechaDos,
                $crc,
                $tamanoComprimido,
                $tamano,
                $largoNombre,
                strlen($extra),
            );
            $entradaLocal = $cabecera.$nombre.$extra.$datos;
            $local .= $entradaLocal;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                1,
                99,
                $horaDos,
                $fechaDos,
                $crc,
                $tamanoComprimido,
                $tamano,
                $largoNombre,
                strlen($extra),
                0,
                0,
                0,
                0,
                $offset,
            ).$nombre.$extra;

            $offset += strlen($entradaLocal);
            $cantidad++;
        }

        $fin = pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $cantidad,
            $cantidad,
            strlen($central),
            strlen($local),
            0,
        );

        return $local.$central.$fin;
    }

    private function aesCtr(string $contenido, string $llave): string
    {
        $salida = '';
        $contador = 1;

        for ($offset = 0; $offset < strlen($contenido); $offset += 16) {
            $bloque = substr($contenido, $offset, 16);
            $flujo = openssl_encrypt(
                pack('V', $contador).str_repeat("\0", 12),
                'aes-256-ecb',
                $llave,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            );
            if ($flujo === false) {
                throw new RuntimeException('No fue posible cifrar la etiqueta NiceLabel.');
            }
            $salida .= $bloque ^ substr($flujo, 0, strlen($bloque));
            $contador++;
        }

        return $salida;
    }

    /** @param array<string, mixed> $perfil */
    private function dimensiones(array $perfil): array
    {
        $ancho = max(1, (int) round(((float) $perfil['ancho_mm']) * 1000));
        $alto = max(1, (int) round(((float) $perfil['alto_mm']) * 1000));

        return ($perfil['orientacion'] ?? 'horizontal') === 'vertical'
            ? [min($ancho, $alto), max($ancho, $alto)]
            : [max($ancho, $alto), min($ancho, $alto)];
    }

    /**
     * @param  array<string, mixed>  $perfil
     * @return array{nombre: string, driver: string, buffer: string}
     */
    private function impresora(array $perfil): array
    {
        $equipo = mb_strtoupper(
            ($perfil['fabricante'] ?? '').' '.($perfil['modelo'] ?? ''),
        );
        $bixolon = str_contains($equipo, 'BIXOLON') || str_contains($equipo, 'SLP-TX400');
        $buffer = gzuncompress(base64_decode(
            $bixolon ? self::BIXOLON_DEVMODE : self::ZEBRA_DEVMODE,
            true,
        ));
        if ($buffer === false) {
            throw new RuntimeException('No fue posible cargar el perfil de la impresora.');
        }

        return $bixolon
            ? [
                'nombre' => 'BIXOLON SLP-TX400',
                'driver' => 'BIXOLON SLP-TX400 - BPL-Z',
                'buffer' => $buffer,
            ]
            : [
                'nombre' => 'ZDesigner ZT231-203dpi ZPL',
                'driver' => 'ZDesigner ZT231-203dpi ZPL',
                'buffer' => $buffer,
            ];
    }

    /** @param array<string, mixed> $etiqueta */
    private function lineasOrigen(array $etiqueta): array
    {
        if (($etiqueta['origen'] ?? 'recepcion') === 'transferencia_cliente') {
            return ['Transferencia desde: '.$etiqueta['folio_origen'],
                'Proveedor: '.($etiqueta['proveedor_nombre'] ?: '—').' · Lote: '.($etiqueta['lote_proveedor'] ?: '—'),
                'Ingreso original: '.($etiqueta['fecha_recepcion'] ?: '—')];
        }
        if (($etiqueta['origen'] ?? 'recepcion') === 'transformacion') {
            return [
                'Transformación: '.$etiqueta['orden_transformacion']
                    .' · Lote '.$etiqueta['numero_lote_transformacion'],
                'Lote material: '.($etiqueta['lote_proveedor'] ?: '—'),
            ];
        }

        return [
            'Guía: '.$etiqueta['numero_guia'].' · Lote: '.($etiqueta['lote_proveedor'] ?: '—'),
            'Proveedor: '.$etiqueta['proveedor_nombre'],
            'Fecha recepción: '.(($etiqueta['fecha_recepcion'] ?? null) ?: '—'),
        ];
    }

    private function tamanoFolio(string $texto, int $ancho, int $maximo): int
    {
        $caracteres = max(1, mb_strlen($texto));
        $anchoUtil = max(1, $ancho - 2000);
        $calculado = (int) floor(
            $anchoUtil / ($caracteres * 240),
        );

        return max(7, min($maximo, $calculado));
    }

    private function tamanoDetalle(
        string $texto,
        int $ancho,
        int $maximo,
        bool $negrita,
    ): int {
        $caracteres = max(1, mb_strlen($texto));
        $anchoUtil = max(1, $ancho - 2000);
        $micrometrosPorPunto = $negrita ? 320 : 260;
        $calculado = (int) floor(
            $anchoUtil / ($caracteres * $micrometrosPorPunto),
        );

        return max(4, min($maximo, $calculado));
    }

    private function nombreFormato(string $folio, int $copia, int $copias): string
    {
        $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '-', $folio) ?: 'Etiqueta';

        return $copias > 1 ? "{$nombre}-copia-{$copia}" : $nombre;
    }

    private function uuid(string $valor): string
    {
        $hex = substr(hash('sha256', $valor), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'
            .substr($hex, 8, 4).'-'
            .substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'
            .substr($hex, 20, 12);
    }

    private function sangrar(string $contenido, int $espacios): string
    {
        $prefijo = str_repeat(' ', $espacios);

        return $prefijo.str_replace("\n", "\n".$prefijo, $contenido);
    }

    private function xml(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function bom(): string
    {
        return "\xEF\xBB\xBF";
    }
}
