<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\InspeccionSag\RegistroCerezasChinaSag;
use App\Services\InspeccionSag\RegistroSdpMexico;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class HerramientasSagApiTest extends TestCase
{
    private const API = '/api/contraparte-sag/herramientas';

    protected function setUp(): void
    {
        parent::setUp();
        $this->entrar(1);
    }

    public function test_lee_lote_ins_y_genera_la_planilla_con_certificados_por_folio(): void
    {
        $contenido = "\xEF\xBB\xBF00010000310310800218000201006110002\r\n00000000380056\r\n00000000390020\r\n&&\r\n\r\n";
        $archivo = UploadedFile::fake()->createWithContent('00300010.INS', $contenido);
        $this->post(self::API.'/lote', ['archivo' => $archivo], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('sif', '00010')->assertJsonPath('pallets.0.folio', '0000000038')
            ->assertJsonPath('pallets.1.acumulado', 76)->assertJsonPath('total_cajas', 76);

        $datos = ['archivo' => $archivo, 'lote' => '8B', 'especie' => 'Cereza', 'muestreo' => 'PE', 'seleccion' => 'M', 'despacho' => 'I'];
        $respuesta = $this->post(self::API.'/lote/pdf', [
            ...$datos, 'certificados' => json_encode([['folio' => '0000000038', 'premuestreo' => 'PRE-123', 'cajas_premuestreo' => 12]]),
        ], ['Accept' => 'application/json'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('PRE-123', $respuesta->getContent());
        $this->assertStringContainsString('0000000038', $respuesta->getContent());
        $this->post(self::API.'/lote/pdf', [
            ...$datos, 'certificados' => json_encode([['folio' => '0000000038', 'inspeccion' => 'INS-123', 'cajas_inspeccion' => 57]]),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post(self::API.'/lote', ['archivo' => UploadedFile::fake()->createWithContent('mal.INS', substr($contenido, 0, -6))],
            ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_verifica_sdp_una_vez_por_codigo_y_mantiene_independientes_los_totales_por_fecha(): void
    {
        $form = '<form id="frmbuscar"><input name="BCODSAG"></form>';
        Http::preventStrayRequests();
        Http::fake([
            RegistroSdpMexico::URL.'*' => function (Request $r) use ($form) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $parametros);

                return match ($parametros['BCODSAG']) {
                    '00053178' => Http::response($form.'<table><tr><td><span class="titulo2">00053178</span></td><td>SI</td><td>CEREZA.REGINA - <strong>Clasificación: C*</strong><br>CEREZA.SANTINA - Clasificación: A</td></tr></table>'),
                    '99999999' => Http::response($form.'NO se registran Sitios de Producción de acuerdo al criterio seleccionado.'),
                    default => Http::response('<html>Portal temporalmente fuera de servicio</html>'),
                };
            },
        ]);
        $archivo = UploadedFile::fake()->createWithContent('packing.csv', implode("\n", [
            'Folio;SdP;Fecha de embalaje;Cajas;Especie;Variedad;Clasificación SdP',
            '10;53178;20-11-2026;50;Cerezas;Regina;C',
            '11;00053178;2026-11-20;70;Cereza;Lapins;',
            '12;99999999;19/11/2026;30;Cereza;Regina;',
            '13;123456;fecha;40;Cereza;Regina;',
            'Total;;;190;;;',
        ]));
        $id = $this->post(self::API.'/mexico', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('grupos', 1)->assertJsonPath('codigos', 3)->json('id');
        $url = self::API.'/mexico/'.$id;
        $this->getJson($url.'/pdf')->assertConflict();
        $this->postJson($url.'/grupos/0')->assertNoContent();
        $this->postJson($url.'/grupos/0')->assertNoContent();
        Http::assertSentCount(3);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('filas.0.clasificaciones', ['C*'])
            ->assertJsonPath('filas.0.observaciones.0', 'La clasificación declarada no coincide con el SAG.')
            ->assertJsonPath('filas.1.observaciones.0', 'La especie o variedad del packing list no coincide con el registro.')
            ->assertJsonPath('filas.2.estado', 'no_encontrado')->assertJsonPath('filas.3.estado', 'no_verificable')
            ->assertJsonPath('fechas.0.cajas', 30)->assertJsonPath('fechas.1.cajas', 120)->assertJsonPath('fechas.2.fecha', 'Sin fecha válida')
            ->assertJsonPath('total_cajas', 190);
        $this->getJson($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->entrar(2);
        $this->getJson($url)->assertNotFound();
    }

    public function test_consulta_csg_y_csp_en_sus_hojas_del_registro_de_cerezas(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            RegistroCerezasChinaSag::URL => Http::response('<a href="/sites/default/files/CHERRY-2026-10-15.xlsx">Descargar</a>'),
            'https://www.sag.gob.cl/sites/default/files/CHERRY-2026-10-15.xlsx' => Http::response($this->libroChina()),
        ]);
        $this->postJson(self::API.'/china', ['csg' => "000123, 123\n456", 'csp' => '456;123'])
            ->assertOk()->assertJsonPath('actualizado', '2026-10-15')->assertJsonCount(4, 'resultados')
            ->assertJsonPath('resultados.0.inscrito', true)->assertJsonPath('resultados.0.codigo', '123')
            ->assertJsonPath('resultados.1.inscrito', false)->assertJsonPath('resultados.2.inscrito', true)
            ->assertJsonPath('resultados.2.ubicaciones.0.comuna', 'Curicó')->assertJsonPath('resultados.3.inscrito', false);
    }

    public function test_emite_adhesivos_de_las_cajas_marcadas_con_la_exportadora(): void
    {
        $datos = [
            'tipo' => 'usda', 'pallets' => [
                ['folio' => 'AAAA', 'base' => 4, 'corridas' => 4],
                ['folio' => 'BBBB', 'base' => 3, 'corridas' => 4, 'ultima' => 2],
            ],
            'exportadora' => 'Exportadora Valle', 'lote' => 'Lote 01', 'cajas_marcadas' => [5, 27], 'formato' => 'rollo',
        ];
        $respuesta = $this->postJson('/api/sag/diagramas/etiquetas', $datos)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('/Count 2', $respuesta->getContent());
        $this->assertStringContainsString('Exportadora Valle', $respuesta->getContent());
        $this->assertStringContainsString('Caja 27', $respuesta->getContent());
        $this->assertStringContainsString('BBBB', $respuesta->getContent());
        $this->postJson('/api/sag/diagramas/etiquetas', [...$datos, 'cajas_marcadas' => [28]])->assertUnprocessable();

        $this->entrar(3, 'cliente');
        $this->postJson('/api/sag/diagramas/etiquetas', $datos)->assertForbidden();
        $this->postJson(self::API.'/china', ['csg' => '123'])->assertForbidden();
    }

    private function entrar(int $id, string $rol = 'administrador'): void
    {
        $usuario = new User(['rol' => $rol, 'activo' => true]);
        $usuario->id = $id;
        $this->actingAs($usuario, 'sanctum');
    }

    private function libroChina(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sag-test-');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Orchard" r:id="rId1"/><sheet name="Facilities" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>');
        foreach ([['ORCHARD CODE (CSG)', '123'], ['FACILITY CODE (CSP)', '456']] as $indice => [$cabecera, $codigo]) {
            $zip->addFromString('xl/worksheets/sheet'.($indice + 1).'.xml', <<<XML
                <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
                  <sheetData>
                    <row r="8"><c r="A8" t="inlineStr"><is><t>{$cabecera}</t></is></c></row>
                    <row r="9"><c r="A9"><v>{$codigo}</v></c><c r="C9" t="inlineStr"><is><t>Maule</t></is></c><c r="D9" t="inlineStr"><is><t>Curicó</t></is></c></row>
                  </sheetData>
                </worksheet>
                XML);
        }
        $zip->close();
        $contenido = file_get_contents($ruta);
        unlink($ruta);

        return $contenido;
    }
}
