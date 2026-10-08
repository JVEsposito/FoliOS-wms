<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\FotoItemMaterial;
use App\Models\ItemMaterial;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Materiales\LectorPlanillaMaterial;
use App\Services\Materiales\ServicioCatalogoItemsMateriales;
use App\Services\Temporadas\ServicioMigracionTemporada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class CatalogoFotosItemsMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_exportar_y_reimportar_xlsx_no_modifica_ningun_item_incluso_inactivos_y_sin_tipo(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->item($admin, ['stock_minimo' => 1.125, 'punto_reorden' => 2.250, 'stock_maximo' => 10]);
        $this->item($admin, ['codigo' => 'ANTIGUO', 'activo' => false, 'categoria_operacional' => null]);
        $cliente = ClienteMaterial::create(['temporada_material_id' => $item->cliente->temporada_material_id, 'codigo' => 'ANTERIOR', 'nombre' => 'Cliente inactivo', 'activo' => false]);
        $this->item($admin, ['codigo' => 'INACTIVO', 'cliente_material_id' => $cliente->id]);
        $respuesta = $this->actingAs($admin, 'sanctum')->get('/api/materiales/items/catalogo/exportar/xlsx')->assertOk();
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($ruta));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $this->assertStringContainsString('<c r="M1" t="inlineStr" s="7">', $xml);
            $archivo = new UploadedFile($ruta, 'catalogo.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $filas = app(LectorPlanillaMaterial::class)->leer($archivo);
            $this->assertCount(3, $filas);
            $this->assertSame(['fila', 'temporada_codigo', 'cliente_codigo', 'codigo', 'nombre', 'categoria', 'categoria_operacional', 'unidad_medida', 'codigo_externo', 'activo', 'stock_minimo', 'punto_reorden', 'stock_maximo', ...ServicioCatalogoItemsMateriales::COLUMNAS_INFORMATIVAS], array_keys($filas[0]));
            $id = $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])
                ->assertCreated()->assertJsonPath('data.resumen.filas_con_error', 0)->assertJsonPath('data.resumen.sin_cambios_estimados', 3)
                ->assertJsonPath('data.resumen.columnas_omitidas', ServicioCatalogoItemsMateriales::COLUMNAS_INFORMATIVAS)->json('data.id');
            $this->postJson("/api/administracion/materiales/importaciones/{$id}/confirmar")->assertOk()
                ->assertJsonPath('data.resumen.creados', 0)->assertJsonPath('data.resumen.actualizados', 0)->assertJsonPath('data.resumen.sin_cambios', 3);
            $this->assertDatabaseCount('items_materiales', 3);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_catalogo_y_exportacion_comparten_filtros_y_permisos(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->item($admin);
        $this->item($admin, ['codigo' => 'INACTIVO', 'activo' => false]);
        $otro = ClienteMaterial::create(['temporada_material_id' => $item->cliente->temporada_material_id, 'codigo' => 'OTRO', 'nombre' => 'Otro', 'activo' => true]);
        $this->item($admin, ['codigo' => 'OTRO', 'cliente_material_id' => $otro->id]);
        $destino = Temporada::create(['codigo' => 'FUTURA', 'nombre' => 'Futura', ...$this->vigenciaProductiva(), 'activa' => false]);
        app(ServicioMigracionTemporada::class)->migrar($item->cliente->temporada->temporadaGlobal, $destino, ['copiar_catalogo_materiales' => true], $admin);
        $this->actingAs($admin, 'sanctum')->getJson('/api/materiales/items/catalogo')->assertOk()->assertJsonPath('total', 6);
        $filtro = http_build_query(['temporada_id' => $item->cliente->temporada_material_id, 'cliente_id' => $item->cliente_material_id, 'categoria' => 'Embalaje', 'tipo_item' => 'insumo', 'estado' => 'activos']);
        $this->getJson('/api/materiales/items/catalogo?'.$filtro)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $item->id);
        $csv = $this->get('/api/materiales/items/catalogo/exportar/csv?'.$filtro)->assertOk()->streamedContent();
        $lineas = explode("\n", trim(substr($csv, 3)));
        $this->assertCount(2, $lineas);
        $this->assertSame([...ServicioCatalogoItemsMateriales::COLUMNAS_IMPORTACION, ...ServicioCatalogoItemsMateriales::COLUMNAS_INFORMATIVAS], str_getcsv($lineas[0], ';', '"', ''));
        $this->assertSame('ITEM-01', str_getcsv($lineas[1], ';', '"', '')[2]);
        $lectura = User::factory()->create(['rol' => RolUsuario::CamareroMateriales]);
        $this->actingAs($lectura, 'sanctum')->getJson('/api/materiales/items/catalogo?'.$filtro)->assertOk();
        $this->get('/api/materiales/items/catalogo/exportar/csv?'.$filtro)->assertOk();
        $sinPermiso = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->actingAs($sinPermiso, 'sanctum')->getJson('/api/materiales/items/catalogo')->assertForbidden();
        $this->get('/api/materiales/items/catalogo/exportar/xlsx')->assertForbidden();
    }

    public function test_fotos_multiples_principal_orden_version_y_borrado_auditado(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->item($admin);
        $datos = $this->actingAs($admin, 'sanctum')->post('/api/materiales/items/'.$item->id.'/fotos', ['fotografias' => [UploadedFile::fake()->image('uno.jpg', 600, 400), UploadedFile::fake()->image('dos.png', 400, 600)], 'version_conocida' => 0], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('data.0.principal', true)->assertJsonPath('data.1.principal', false)->assertJsonPath('version', 1)->json('data');
        $foto = FotoItemMaterial::findOrFail($datos[0]['id']);
        Storage::disk('local')->assertExists([$foto->ruta, $foto->ruta_miniatura]);
        $this->assertSame([300, 200], array_slice(getimagesize(Storage::disk('local')->path($foto->ruta_miniatura)), 0, 2));
        $this->assertSame('image/jpeg', getimagesize(Storage::disk('local')->path($foto->ruta_miniatura))['mime']);
        $ids = array_reverse(array_column($datos, 'id'));
        $this->putJson('/api/materiales/items/'.$item->id.'/fotos', ['orden' => $ids, 'principal_id' => $ids[0], 'version_conocida' => 1])->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.0.principal', true);
        $this->putJson('/api/materiales/items/'.$item->id.'/fotos', ['orden' => $ids, 'principal_id' => $ids[1], 'version_conocida' => 1])->assertConflict();
        $this->putJson('/api/materiales/items/'.$item->id.'/fotos', ['orden' => [$ids[0]], 'principal_id' => $ids[0]])->assertUnprocessable();
        $this->assertSame(1, $item->fotos()->where('principal', true)->count());
        $this->deleteJson('/api/materiales/items/'.$item->id.'/fotos/'.$ids[0], ['version_conocida' => 2])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.0.principal', true);
        $eliminada = FotoItemMaterial::withTrashed()->findOrFail($ids[0]);
        $this->assertNotNull($eliminada->deleted_at);
        $this->assertSame($admin->id, $eliminada->eliminada_por_user_id);
        Storage::disk('local')->assertMissing([$eliminada->ruta, $eliminada->ruta_miniatura]);
        $this->getJson('/api/materiales/items/catalogo')->assertOk()->assertJsonPath('data.0.cantidad_fotos', 1)->assertJsonPath('data.0.foto_principal.id', $ids[1])->assertJsonMissingPath('data.0.foto_principal.ruta');
        $this->assertSame($admin->id, $item->refresh()->actualizado_por_user_id);
    }

    public function test_fotos_rechazan_tamano_formato_y_undecima_y_limpian_archivos_rechazados(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->item($admin);
        $url = '/api/materiales/items/'.$item->id.'/fotos';
        $this->actingAs($admin, 'sanctum')->post($url, ['fotografias' => [UploadedFile::fake()->image('grande.jpg')->size(5121)]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($url, ['fotografias' => [UploadedFile::fake()->image('formato.gif')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->post($url, ['fotografias' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.png"), range(1, 10))], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(10, 'data');
        $this->post($url, ['fotografias' => [UploadedFile::fake()->image('once.jpg')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(10, $item->fotos()->count());
        $this->assertCount(20, Storage::disk('local')->allFiles());
    }

    public function test_archivos_son_privados_sin_cache_y_camarero_solo_lee(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->item($admin);
        $this->actingAs($admin, 'sanctum')->post('/api/materiales/items/'.$item->id.'/fotos', ['fotografias' => [UploadedFile::fake()->image('foto.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $foto = $item->fotos()->firstOrFail();
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroMateriales]);
        $this->actingAs($camarero, 'sanctum')->getJson('/api/materiales/items/'.$item->id.'/fotos')->assertOk();
        foreach (['archivo', 'miniatura'] as $variante) {
            $this->get('/api/materiales/fotos-items/'.$foto->id.'/'.$variante)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        $this->post('/api/materiales/items/'.$item->id.'/fotos', ['fotografias' => [UploadedFile::fake()->image('otra.jpg')]], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson('/api/materiales/items/'.$item->id.'/fotos/'.$foto->id)->assertForbidden();
        $this->putJson('/api/materiales/items/'.$item->id.'/fotos', ['orden' => [$foto->id], 'principal_id' => $foto->id])->assertForbidden();
        $this->app['auth']->forgetGuards();
        foreach (['archivo', 'miniatura'] as $variante) {
            $this->getJson('/api/materiales/fotos-items/'.$foto->id.'/'.$variante)->assertUnauthorized();
        }
    }

    public function test_migracion_copia_registros_sin_duplicar_archivos_y_conserva_referencias_activas(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->item($admin);
        $this->actingAs($admin, 'sanctum')->post('/api/materiales/items/'.$item->id.'/fotos', ['fotografias' => [UploadedFile::fake()->image('foto.jpg')]], ['Accept' => 'application/json'])->assertCreated();
        $original = $item->fotos()->firstOrFail();
        $destino = Temporada::create(['codigo' => 'FUTURA', 'nombre' => 'Futura', ...$this->vigenciaProductiva(), 'activa' => false]);
        app(ServicioMigracionTemporada::class)->migrar($item->cliente->temporada->temporadaGlobal, $destino, ['copiar_catalogo_materiales' => true], $admin);
        $copia = FotoItemMaterial::where('item_material_id', '!=', $item->id)->firstOrFail();
        $this->assertNotSame($original->id, $copia->id);
        $this->assertSame($original->archivo_origen_id, $copia->archivo_origen_id);
        $this->assertSame($original->ruta, $copia->ruta);
        $this->assertSame($original->ruta_miniatura, $copia->ruta_miniatura);
        $this->assertTrue($copia->principal);
        $this->assertSame($original->subida_at->toAtomString(), $copia->subida_at->toAtomString());
        $this->assertSame($admin->id, $copia->subida_por_user_id);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->deleteJson('/api/materiales/items/'.$item->id.'/fotos/'.$original->id)->assertOk();
        Storage::disk('local')->assertExists([$original->ruta, $original->ruta_miniatura]);
        $this->get('/api/materiales/fotos-items/'.$copia->id.'/miniatura')->assertOk();
        $this->deleteJson('/api/materiales/items/'.$copia->item_material_id.'/fotos/'.$copia->id)->assertOk();
        Storage::disk('local')->assertMissing([$original->ruta, $original->ruta_miniatura]);
    }

    private function item(User $usuario, array $datos = []): ItemMaterial
    {
        return ItemMaterial::create(['cliente_material_id' => ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail()->id,
            'codigo' => 'ITEM-01', 'nombre' => 'Film stretch', 'categoria' => 'Embalaje', 'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'rollos', 'activo' => true, 'origen_sistema' => 'manual', 'creado_por_user_id' => $usuario->id, 'actualizado_por_user_id' => $usuario->id, ...$datos]);
    }
}
