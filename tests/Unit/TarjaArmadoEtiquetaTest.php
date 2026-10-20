<?php

namespace Tests\Unit;

use App\Services\Materiales\DisenoTarjaArmado;
use App\Services\Materiales\GeneradorEtiquetaMaterialNlbl;
use App\Services\Materiales\GeneradorEtiquetaMaterialPdf;
use App\Services\Materiales\GeneradorEtiquetaMaterialZpl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TarjaArmadoEtiquetaTest extends TestCase
{
    private const VALORES = [
        'CSF',
        'EXPORTADORA C Y D',
        'BODEGA AGROROSARIO',
        '021021325',
        '14-10-2026',
        '1.000',
        '1002470031410260103',
        'AR02102132510000001',
    ];

    /** @return array<string, array{float, float}> */
    public static function tamanos(): array
    {
        return [
            '100 × 150 vertical' => [283.46, 425.2],
            '100 × 100' => [283.46, 283.46],
            '100 × 50 apaisada' => [283.46, 141.73],
        ];
    }

    #[DataProvider('tamanos')]
    public function test_la_disposicion_queda_dentro_de_la_etiqueta_sin_solapar_el_codigo(float $ancho, float $alto): void
    {
        $diseno = DisenoTarjaArmado::disponer($this->tarja(), $ancho, $alto);
        $codigo = $diseno['codigo'];

        $this->assertSame('AR02102132510000001', $codigo['valor']);
        $this->assertGreaterThan($alto * 0.08, $codigo['alto']);
        $this->assertDentro($codigo, $ancho, $alto);
        foreach ($diseno['textos'] as $texto) {
            $this->assertDentro($texto, $ancho, $alto);
            $this->assertGreaterThan(0, $texto['tamano']);
            $this->assertLessThanOrEqual(
                $texto['ancho'] + 0.01,
                DisenoTarjaArmado::anchoRelativo($texto['texto'], $texto['negrita']) * $texto['tamano'],
                "«{$texto['texto']}» no cabe en su celda.",
            );
            $solapa = $texto['x'] < $codigo['x'] + $codigo['ancho']
                && $codigo['x'] < $texto['x'] + $texto['ancho']
                && $texto['y'] < $codigo['y'] + $codigo['alto']
                && $codigo['y'] < $texto['y'] + $texto['alto'];
            $this->assertFalse($solapa, "«{$texto['texto']}» se monta sobre el código de barras.");
        }
        foreach ($diseno['lineas'] as $linea) {
            $this->assertTrue($linea['x1'] === $linea['x2'] || $linea['y1'] === $linea['y2']);
        }

        $textos = implode("\n", array_column($diseno['textos'], 'texto'));
        foreach ([...self::VALORES, 'CÓDIGO', 'DESCRIPCIÓN', 'FECHA', 'CANTIDAD', 'LOTE', 'FOLIO ÚNICO DE ARMADO'] as $valor) {
            $this->assertStringContainsString($valor, $textos);
        }
    }

    public function test_una_descripcion_larga_pasa_a_dos_lineas_en_la_etiqueta_vertical(): void
    {
        $diseno = DisenoTarjaArmado::disponer($this->tarja(), 283.46, 425.2);
        $lineas = array_values(array_filter(
            $diseno['textos'],
            fn (array $texto): bool => str_starts_with($texto['nombre'], 'DESCRIPCIÓN valor'),
        ));

        $this->assertCount(2, $lineas);
        $this->assertSame(
            'CAJA CARTÓN TELESCÓPICA 5 KG CEREZA EXPORTACIÓN',
            $lineas[0]['texto'].' '.$lineas[1]['texto'],
        );
        $this->assertGreaterThan($lineas[0]['y'], $lineas[1]['y']);
    }

    public function test_pdf_dibuja_la_tarja_con_sus_datos_y_codigo(): void
    {
        $pdf = (new GeneradorEtiquetaMaterialPdf)->generar(
            [$this->etiqueta()],
            ['ancho_mm' => 100, 'alto_mm' => 150, 'orientacion' => 'vertical'],
        );

        foreach ([...self::VALORES, 'FOLIO ÚNICO DE ARMADO', 'DESCRIPCIÓN'] as $valor) {
            $this->assertStringContainsString(
                '('.iconv('UTF-8', 'Windows-1252//TRANSLIT', $valor).')',
                $pdf,
            );
        }
        // Celdas con bordes y barras del Code 128.
        $this->assertGreaterThan(10, substr_count($pdf, ' l S'));
        $this->assertGreaterThan(30, substr_count($pdf, ' re f'));
        // La etiqueta estándar no aparece en la tarja.
        $this->assertStringNotContainsString('Transformación:', $pdf);
    }

    public function test_zpl_dibuja_la_tarja_con_code128_o_qr(): void
    {
        $perfil = ['dpi' => 203, 'ancho_mm' => 100, 'alto_mm' => 50, 'orientacion' => 'horizontal'];
        $code128 = (new GeneradorEtiquetaMaterialZpl)->generar([$this->etiqueta()], $perfil, 2);

        $this->assertSame(2, substr_count($code128, '^XA'));
        $this->assertStringContainsString('^BCN,', $code128);
        $this->assertStringContainsString('^FDAR02102132510000001^FS', $code128);
        $this->assertStringContainsString('^GB', $code128);
        foreach ([...self::VALORES, 'FOLIO ÚNICO DE ARMADO'] as $valor) {
            $this->assertStringContainsString('^FD'.$valor.'^FS', $code128);
        }
        $this->assertStringNotContainsString('Transformación:', $code128);

        $qr = (new GeneradorEtiquetaMaterialZpl)->generar([$this->etiqueta()], $perfil, simbologia: 'qr');
        $this->assertStringContainsString('^BQN,2,', $qr);
        $this->assertStringContainsString('^FDLA,AR02102132510000001^FS', $qr);
    }

    public function test_nlbl_lleva_los_textos_de_la_tarja_dentro_del_area(): void
    {
        $metodo = new ReflectionMethod(GeneradorEtiquetaMaterialNlbl::class, 'formato');
        $formato = $metodo->invoke(
            new GeneradorEtiquetaMaterialNlbl,
            $this->etiqueta(),
            ['fabricante' => 'Zebra', 'modelo' => 'ZT231', 'ancho_mm' => 100, 'alto_mm' => 150, 'orientacion' => 'vertical'],
            'code128',
            'Tarja de prueba',
        );

        $this->assertStringContainsString('<Name>Código Code 128</Name>', $formato);
        $this->assertStringContainsString('<FixedContents>AR02102132510000001</FixedContents>', $formato);
        foreach ([...self::VALORES, 'FOLIO ÚNICO DE ARMADO'] as $valor) {
            $this->assertStringContainsString(
                '<FixedContents Base64Encoded="true">'.base64_encode($valor).'</FixedContents>',
                $formato,
            );
        }
        preg_match_all(
            '#<Geometry Type="RectGeometry"><Width>(\d+)</Width><Height>(\d+)</Height><Left>(\d+)</Left><Top>(\d+)</Top>#',
            $formato,
            $geometrias,
            PREG_SET_ORDER,
        );
        $textos = array_filter($geometrias, fn (array $geometria): bool => (int) $geometria[1] > 0);
        $this->assertGreaterThanOrEqual(15, count($textos));
        foreach ($textos as [, $ancho, $alto, $centroX, $centroY]) {
            $this->assertGreaterThanOrEqual(0, $centroX - intdiv((int) $ancho, 2));
            $this->assertLessThanOrEqual(100000, $centroX + intdiv((int) $ancho, 2));
            $this->assertGreaterThanOrEqual(0, $centroY - intdiv((int) $alto, 2));
            $this->assertLessThanOrEqual(150000, $centroY + intdiv((int) $alto, 2));
        }
    }

    /** @param array{x: float, y: float, ancho: float, alto: float} $caja */
    private function assertDentro(array $caja, float $ancho, float $alto): void
    {
        $this->assertGreaterThanOrEqual(0, $caja['x']);
        $this->assertGreaterThanOrEqual(0, $caja['y']);
        $this->assertLessThanOrEqual($ancho, $caja['x'] + $caja['ancho']);
        $this->assertLessThanOrEqual($alto, $caja['y'] + $caja['alto']);
    }

    /** @return array<string, mixed> */
    private function etiqueta(): array
    {
        return [
            'origen' => 'transformacion',
            'numero_folio' => 'AR02102132510000001',
            'cliente_codigo' => 'CYD',
            'cliente_nombre' => 'Exportadora C y D',
            'item_codigo' => '021021325',
            'item_nombre' => 'CAJA CARTÓN TELESCÓPICA 5 KG CEREZA EXPORTACIÓN',
            'cantidad' => '1.000,000',
            'unidad_medida' => 'unidades',
            'lote_proveedor' => '1002470031410260103',
            'orden_transformacion' => 'OT-1234ABCD',
            'numero_lote_transformacion' => 1,
            'bloqueado' => false,
            'motivo_bloqueo' => null,
            'armado' => $this->tarja(),
        ];
    }

    /** @return array<string, string> */
    private function tarja(): array
    {
        return [
            'proveedor' => 'CSF',
            'proveedor_nombre' => 'Cartones San Fernando',
            'cliente' => 'EXPORTADORA C Y D',
            'bodega' => 'BODEGA AGROROSARIO',
            'codigo' => '021021325',
            'descripcion' => 'CAJA CARTÓN TELESCÓPICA 5 KG CEREZA EXPORTACIÓN',
            'fecha' => '14-10-2026',
            'cantidad' => '1.000',
            'lote' => '1002470031410260103',
            'guia' => '247003',
            'turno' => '01',
            'maquina' => '03',
            'folio' => 'AR02102132510000001',
        ];
    }
}
