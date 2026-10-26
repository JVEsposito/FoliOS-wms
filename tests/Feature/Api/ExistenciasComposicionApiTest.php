<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Folio;
use App\Models\User;
use App\Services\Existencias\ServicioExistencias;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ExistenciasComposicionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_conexion_y_xlsx_desglosan_pallet_y_saldo_y_la_misma_conexion_recibe_cambios(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $token = $usuario->createToken('existencia-composicion', ['oficina'])->plainTextToken;
        $composicion = [['csg' => '119003', 'predio' => 'LONGO SA', 'cantidad_cajas' => 90], ['csg' => '3126339', 'predio' => 'AGROFRUTICOLA S', 'cantidad_cajas' => 45]];
        $pallet = $this->crearFolio('1000000059', 'pallet', ['cantidad_cajas' => 135, 'composicion' => $composicion]);
        $this->crearFolio('1000000130', 'saldo', ['cantidad_cajas' => 67, 'composicion' => [
            [...$composicion[0], 'cantidad_cajas' => 50], [...$composicion[1], 'cantidad_cajas' => 17],
        ]]);
        $conexion = $this->withToken($token)->post('/api/existencias/producto-terminado/conexion-excel')->assertCreated();
        preg_match('/token=([A-Za-z0-9]+)/', $conexion->getContent(), $captura);
        $url = '/api/existencias/producto-terminado/consulta?token='.$captura[1];
        $filas = $this->filasHtml($this->get($url)->assertOk()->assertStreamed()->streamedContent());
        $this->assertCount(4, $filas);
        $this->assertSame(['Desglosado', 'Desglosado', 'Desglosado', 'Desglosado'], array_column($filas, 'estado_desglose'));
        $this->assertSame([90, 45, 50, 17], array_map('intval', array_column($filas, 'cantidad_cajas')));
        $this->assertSame(['119003', '3126339', '119003', '3126339'], array_column($filas, 'csg'));
        $this->assertSame(['LONGO SA', 'AGROFRUTICOLA S', 'LONGO SA', 'AGROFRUTICOLA S'], array_column($filas, 'predio'));
        $this->assertSame(['Pallet', 'Pallet', 'Saldo', 'Saldo'], array_column($filas, 'tipo_bulto'));
        $xlsx = $this->withToken($token)->get('/api/existencias/producto-terminado/corte')->assertOk()->assertDownload();
        $ruta = $xlsx->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($ruta);
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $datos = [];
        foreach ($doc->getElementsByTagName('row') as $i => $fila) {
            if ($i === 0) {
                continue;
            }
            $valores = [];
            foreach ($fila->getElementsByTagName('c') as $celda) {
                $valores[] = $celda->textContent;
            }
            $datos[] = array_combine($this->claves(), $valores);
        }
        $this->assertCount(4, $datos);
        $this->assertSame([90, 45, 50, 17], array_map('intval', array_column($datos, 'cantidad_cajas')));
        $this->assertSame(array_column($filas, 'csg'), array_column($datos, 'csg'));
        $this->assertSame(array_column($filas, 'predio'), array_column($datos, 'predio'));
        $pallet->update(['datos_externos' => [...$pallet->datos_externos, 'composicion' => [
            [...$composicion[0], 'cantidad_cajas' => 80], [...$composicion[1], 'cantidad_cajas' => 55],
        ]]]);
        $actualizadas = $this->filasHtml($this->get($url)->assertOk()->streamedContent());
        $this->assertSame([80, 55, 50, 17], array_map('intval', array_column($actualizadas, 'cantidad_cajas')));
        $this->assertDatabaseCount('conexiones_existencias', 1);
    }

    public function test_catalogos_se_leen_una_vez_por_bloque_y_completan_las_combinaciones_reales(): void
    {
        $temporadaId = DB::table('temporadas')->where('activa', true)->value('id');
        $combinaciones = [];
        $genealogia = [];
        foreach ([['Santina', '2J', '5 kg', '111', 'Predio A'], ['Lapins', '3J', '2 kg', '222', 'Predio B']] as $i => [$variedad, $calibre, $envase, $csg, $predio]) {
            $articulo = (string) Str::uuid();
            $origen = (string) Str::uuid();
            $combinacion = (string) Str::uuid();
            DB::table('articulos_validacion')->insert(['id' => $articulo, 'temporada_id' => $temporadaId, 'especie' => 'Cereza', 'variedad' => $variedad, 'calibre' => $calibre, 'envase' => $envase]);
            DB::table('origenes_validacion')->insert(['id' => $origen, 'temporada_id' => $temporadaId, 'cliente' => 'Cliente A', 'marca' => 'Marca A', 'csg' => $csg, 'predio' => $predio]);
            DB::table('combinaciones_validacion')->insert(['id' => $combinacion, 'temporada_id' => $temporadaId, 'articulo_validacion_id' => $articulo, 'origen_validacion_id' => $origen]);
            $combinaciones[] = ['combinacion_validacion_id' => $combinacion, 'cantidad_cajas' => 1];
            $genealogia[] = ['especificaciones' => ['categoria' => 'Cat '.$i], 'composicion_aportada' => [['csg' => $csg, 'predio' => $predio, 'cantidad_cajas' => 60]]];
        }
        $this->crearFolio('CAT-120', 'pallet', ['cantidad_cajas' => 120, 'categoria' => 'MIX', 'envase' => 'MIX', 'genealogia_repaletizaje' => $genealogia, 'composicion' => array_map(fn ($i) => $combinaciones[$i % 2], range(0, 119))], ['variedad' => 'MIX', 'calibre' => 'MIX']);
        DB::enableQueryLog();
        $filas = app(ServicioExistencias::class)->filas(ServicioExistencias::PRODUCTO_TERMINADO)->values()->all();
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(120, $filas);
        $this->assertSame(120, array_sum(array_column($filas, 'cantidad_cajas')));
        foreach (['articulos_validacion', 'origenes_validacion', 'combinaciones_validacion'] as $tabla) {
            $this->assertCount(1, array_filter($consultas, fn ($q) => str_contains($q['query'], $tabla)), $tabla);
        }
        $this->assertSame('Santina', $filas[0]['variedad']);
        $this->assertSame('Lapins', $filas[1]['variedad']);
        $this->assertSame('2J', $filas[0]['calibre']);
        $this->assertSame('3J', $filas[1]['calibre']);
        $this->assertSame('Cat 0', $filas[0]['categoria']);
        $this->assertSame('Cat 1', $filas[1]['categoria']);
        $this->assertSame('Predio A', $filas[0]['predio']);
        $this->assertSame('Predio B', $filas[1]['predio']);
    }

    public function test_recupera_calibres_agrupados_solo_con_aportes_completos_y_no_reparte_un_saldo_parcial(): void
    {
        $temporadaId = DB::table('temporadas')->where('activa', true)->value('id');
        $articulo = (string) Str::uuid();
        DB::table('articulos_validacion')->insert(['id' => $articulo, 'temporada_id' => $temporadaId, 'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J', 'envase' => '5 kg']);
        $composicion = [['clave' => 'GRUPO-CSG', 'articulo_validacion_id' => $articulo, 'csg' => '111', 'cantidad_cajas' => 100]];
        $genealogia = [
            ['especificaciones' => ['calibre' => '2J'], 'composicion_aportada' => [['clave' => 'GRUPO-CSG', 'csg' => '111', 'cantidad_cajas' => 80]]],
            ['especificaciones' => ['calibre' => '3J'], 'composicion_aportada' => [['clave' => 'GRUPO-CSG', 'csg' => '111', 'cantidad_cajas' => 20]]],
        ];
        $folio = $this->crearFolio('HIST-100', 'pallet', ['cantidad_cajas' => 100, 'composicion' => $composicion, 'genealogia_repaletizaje' => $genealogia], ['calibre' => 'MIX']);
        $servicio = app(ServicioExistencias::class);
        $filas = $servicio->filas(ServicioExistencias::PRODUCTO_TERMINADO)->values()->all();
        $this->assertSame([80, 20], array_column($filas, 'cantidad_cajas'));
        $this->assertSame(['2J', '3J'], array_column($filas, 'calibre'));
        $folio->update(['datos_externos' => [...$folio->datos_externos, 'cantidad_cajas' => 90, 'composicion' => [[...$composicion[0], 'cantidad_cajas' => 90]]]]);
        $filas = $servicio->filas(ServicioExistencias::PRODUCTO_TERMINADO)->values()->all();
        $this->assertCount(1, $filas);
        $this->assertSame(90, $filas[0]['cantidad_cajas']);
        $this->assertSame('MIX', $filas[0]['calibre']);
        $this->assertStringContainsString('parcial', $filas[0]['estado_desglose']);
    }

    private function crearFolio(string $numero, string $tipo, array $datos, array $atributos = []): Folio
    {
        return Folio::create(['temporada_id' => DB::table('temporadas')->where('activa', true)->value('id'), 'numero_folio' => $numero,
            'tipo_bulto' => $tipo, 'activo' => true, 'fecha_ingreso' => now(), 'variedad' => 'Santina', 'calibre' => '2J', 'marca' => 'Marca A', 'exportadora' => 'Cliente A',
            'datos_externos' => ['csg' => 'MIX', 'predio' => 'MIX', 'especie' => 'Cereza', 'categoria' => 'PREMIUM', 'envase' => '5 kg', ...$datos], ...$atributos]);
    }

    private function claves(): array
    {
        return array_column(app(ServicioExistencias::class)->definicion(ServicioExistencias::PRODUCTO_TERMINADO)['columnas'], 'clave');
    }

    private function filasHtml(string $html): array
    {
        $doc = new DOMDocument;
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($doc);
        $filas = [];
        foreach ($xpath->query('//table[@id="Existencia"]/tbody/tr') as $fila) {
            $valores = [];
            foreach ($fila->getElementsByTagName('td') as $celda) {
                $valores[] = $celda->textContent;
            }
            $filas[] = array_combine($this->claves(), $valores);
        }

        return $filas;
    }
}
