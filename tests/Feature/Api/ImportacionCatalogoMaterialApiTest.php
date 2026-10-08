<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ImportacionCatalogoMaterial;
use App\Models\ItemMaterial;
use App\Models\User;
use App\Services\Materiales\LectorPlanillaMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class ImportacionCatalogoMaterialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_previsualiza_y_confirma_catalogo_sin_tocar_inventario(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $existente = $this->crearItem($administrador, [
            'codigo' => 'FILM-01',
            'nombre' => 'Film anterior',
            'codigo_externo' => 'ERP-FILM',
        ]);
        $ausente = $this->crearItem($administrador, [
            'codigo' => 'ZUNCHO-01',
            'nombre' => 'Zuncho plástico',
            'activo' => false,
        ]);
        $archivo = UploadedFile::fake()->createWithContent(
            'formatos.csv',
            "temporada_codigo;cliente_codigo;codigo;nombre;categoria;tipo_item;unidad_medida;codigo_externo;activo\n".
            "GENERAL;GENERAL;FILM-01;Film stretch reforzado;Embalaje;insumo;ROLLOS;;si\n".
            "GENERAL;GENERAL;CAJ-5KG;Caja cartón 5 kg;Cajas;material_mp;UNIDAD;ERP-CAJ-5;si\n",
        );

        $importacionId = $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.resumen.filas_validas', 2)
            ->assertJsonPath('data.resumen.nuevos_estimados', 1)
            ->assertJsonPath('data.resumen.actualizaciones_estimadas', 1)
            ->assertJsonMissingPath('data.filas.0.huella_catalogo')
            ->json('data.id');

        $this->assertDatabaseCount('items_materiales', 2);
        $this->assertDatabaseCount('folios_materiales', 0);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/materiales/importaciones/{$importacionId}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.resumen.creados', 1)
            ->assertJsonPath('data.resumen.actualizados', 1)
            ->assertJsonPath('data.resumen.sin_cambios', 0)
            ->assertJsonPath('data.confirmado_por.id', $administrador->id);

        $this->assertDatabaseHas('items_materiales', [
            'id' => $existente->id,
            'nombre' => 'Film stretch reforzado',
            'codigo_externo' => 'ERP-FILM',
            'unidad_medida' => 'rollos',
            'categoria_operacional' => 'insumo',
            'activo' => true,
            'origen_sistema' => 'importacion_catalogo',
        ]);
        $this->assertDatabaseHas('items_materiales', [
            'codigo' => 'CAJ-5KG',
            'nombre' => 'Caja cartón 5 kg',
            'unidad_medida' => 'unidad',
            'categoria_operacional' => 'material_mp',
            'codigo_externo' => 'ERP-CAJ-5',
        ]);
        $this->assertDatabaseHas('items_materiales', [
            'id' => $ausente->id,
            'activo' => false,
            'origen_sistema' => 'manual',
        ]);
        $this->assertDatabaseCount('items_materiales', 3);
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('folios_materiales', 0);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/materiales/importaciones/{$importacionId}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.resumen.creados', 1)
            ->assertJsonPath('data.resumen.actualizados', 1);

        $this->assertDatabaseCount('items_materiales', 3);
        $this->assertSame($administrador->id, ImportacionCatalogoMaterial::findOrFail($importacionId)->confirmado_por_user_id);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/materiales/importaciones')
            ->assertOk()
            ->assertJsonPath('data.0.id', $importacionId)
            ->assertJsonPath('data.0.creado_por.id', $administrador->id)
            ->assertJsonPath('data.0.estado', 'confirmada');
    }

    public function test_planilla_con_errores_no_se_confirma_y_camarero_no_puede_importar(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroMateriales]);
        $contenido = "temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida;activo\n".
            "GENERAL;GENERAL;CAJA-01;Caja uno;material_mp;unidad;si\n".
            "GENERAL;GENERAL;CAJA-01;Caja duplicada;material_mp;unidad;si\n".
            "GENERAL;GENERAL;FILM-01;Film stretch;insumo;rollos;quizas\n";

        $this->actingAs($camarero, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => UploadedFile::fake()->createWithContent('sin_permiso.csv', $contenido),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $importacionId = $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => UploadedFile::fake()->createWithContent('errores.csv', $contenido),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'con_errores')
            ->assertJsonPath('data.resumen.filas_validas', 1)
            ->assertJsonPath('data.resumen.filas_con_error', 2)
            ->json('data.id');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/materiales/importaciones/{$importacionId}/confirmar")
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseCount('items_materiales', 0);
    }

    public function test_importacion_distingue_el_mismo_codigo_de_item_por_cliente(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporadaId = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail()->temporada_material_id;
        $clientes = collect(['CLI-NORTE', 'CLI-SUR'])->mapWithKeys(function (string $codigo) use ($administrador, $temporadaId): array {
            $cliente = ClienteMaterial::create([
                'temporada_material_id' => $temporadaId,
                'codigo' => $codigo,
                'nombre' => 'Cliente '.$codigo,
                'activo' => true,
                'creado_por_user_id' => $administrador->id,
                'actualizado_por_user_id' => $administrador->id,
            ]);

            return [$codigo => $cliente];
        });
        $archivo = UploadedFile::fake()->createWithContent(
            'catalogo-clientes.csv',
            "temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida\n".
            "GENERAL;CLI-NORTE;CAJA-5KG;Caja cliente norte;material_mp;unidades\n".
            "GENERAL;CLI-SUR;CAJA-5KG;Caja cliente sur;material_mp;unidades\n",
        );

        $importacionId = $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.resumen.filas_validas', 2)
            ->json('data.id');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/materiales/importaciones/{$importacionId}/confirmar")
            ->assertOk()
            ->assertJsonPath('data.resumen.creados', 2);

        foreach ($clientes as $cliente) {
            $this->assertDatabaseHas('items_materiales', [
                'cliente_material_id' => $cliente->id,
                'codigo' => 'CAJA-5KG',
            ]);
        }
    }

    public function test_importacion_no_cambia_unidad_de_item_con_folios(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->crearItem($administrador);
        $folio = Folio::create([
            'numero_folio' => 'MAT-001',
            'tipo_bulto' => 'material',
            'estado_operacional' => 'disponible',
            'fecha_ingreso' => now(),
            'activo' => true,
            'origen_sistema' => 'manual',
            'estado_integracion' => 'no_vinculado',
        ]);
        FolioMaterial::create([
            'folio_id' => $folio->id,
            'item_material_id' => $item->id,
            'cantidad_inicial' => 10,
            'cantidad_actual' => 10,
            'cantidad_reservada' => 0,
            'unidad_medida' => 'rollos',
        ]);
        $archivo = UploadedFile::fake()->createWithContent(
            'unidad.csv',
            "temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida\nGENERAL;GENERAL;FILM-01;Film stretch;insumo;cajas\n",
        );

        $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'con_errores')
            ->assertJsonPath('data.resumen.filas_con_error', 1)
            ->assertJsonFragment([
                'mensaje' => 'La unidad de medida no puede cambiar porque el ítem ya posee folios asociados.',
            ]);
    }

    public function test_confirmacion_rechaza_catalogo_modificado_despues_de_previsualizar(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->crearItem($administrador);
        $archivo = UploadedFile::fake()->createWithContent(
            'catalogo.csv',
            "temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida\nGENERAL;GENERAL;FILM-01;Film importado;insumo;rollos\n",
        );

        $importacionId = $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => $archivo,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->json('data.id');

        $item->update(['nombre' => 'Film modificado en otra sesión']);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/materiales/importaciones/{$importacionId}/confirmar")
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath(
                'message',
                'El catálogo cambió después de la previsualización para el ítem FILM-01. Vuelve a previsualizar la planilla.',
            );

        $this->assertDatabaseHas('items_materiales', [
            'id' => $item->id,
            'nombre' => 'Film modificado en otra sesión',
            'origen_sistema' => 'manual',
        ]);
        $this->assertDatabaseHas('importaciones_catalogo_materiales', [
            'id' => $importacionId,
            'estado' => 'borrador',
            'confirmado_por_user_id' => null,
        ]);
    }

    public function test_previsualizacion_rechaza_planilla_con_mas_de_cinco_mil_filas(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $filas = ['temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida'];

        for ($indice = 1; $indice <= 5001; $indice++) {
            $filas[] = "GENERAL;GENERAL;ITEM-{$indice};Material {$indice};insumo;unidad";
        }

        $this->actingAs($administrador, 'sanctum')
            ->post('/api/administracion/materiales/importaciones/previsualizar', [
                'archivo' => UploadedFile::fake()->createWithContent('catalogo-grande.csv', implode("\n", $filas)),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath('message', 'La planilla supera el máximo de 5.000 filas permitidas.');

        $this->assertDatabaseCount('importaciones_catalogo_materiales', 0);
        $this->assertDatabaseCount('items_materiales', 0);
    }

    public function test_lector_admite_planilla_xlsx_del_catalogo(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('La extensión ZIP no está disponible.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'materiales-xlsx-');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>sku</t></is></c>
      <c r="B1" t="inlineStr"><is><t>formato</t></is></c>
      <c r="C1" t="inlineStr"><is><t>familia</t></is></c>
      <c r="D1" t="inlineStr"><is><t>unidad</t></is></c>
      <c r="E1" t="inlineStr"><is><t>codigo_erp</t></is></c>
      <c r="F1" t="inlineStr"><is><t>estado</t></is></c>
      <c r="G1" t="inlineStr"><is><t>cliente_codigo</t></is></c>
      <c r="H1" t="inlineStr"><is><t>temporada_codigo</t></is></c>
      <c r="I1" t="inlineStr"><is><t>stock_minimo</t></is></c>
      <c r="J1" t="inlineStr"><is><t>punto_reorden</t></is></c>
      <c r="K1" t="inlineStr"><is><t>stock_maximo</t></is></c>
    </row>
    <row r="2">
      <c r="A2" t="inlineStr"><is><t>ETQ-01</t></is></c>
      <c r="B2" t="inlineStr"><is><t>Etiqueta caja 5 kg</t></is></c>
      <c r="C2" t="inlineStr"><is><t>Etiquetas</t></is></c>
      <c r="D2" t="inlineStr"><is><t>unidades</t></is></c>
      <c r="E2" t="inlineStr"><is><t>ERP-ETQ-01</t></is></c>
      <c r="F2" t="inlineStr"><is><t>activo</t></is></c>
      <c r="G2" t="inlineStr"><is><t>GENERAL</t></is></c>
      <c r="H2" t="inlineStr"><is><t>GENERAL</t></is></c>
      <c r="I2"><v>10.5</v></c>
      <c r="J2"><v>20</v></c>
      <c r="K2"><v>100</v></c>
    </row>
  </sheetData>
</worksheet>
XML);
        $zip->close();
        $filas = app(LectorPlanillaMaterial::class)->leer(new UploadedFile(
            $ruta,
            'formatos.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        ));
        unlink($ruta);

        $this->assertSame('ETQ-01', $filas[0]['codigo']);
        $this->assertSame('10.5', (string) $filas[0]['stock_minimo']);
        $this->assertSame('20', (string) $filas[0]['punto_reorden']);
        $this->assertSame('100', (string) $filas[0]['stock_maximo']);
        $this->assertSame('GENERAL', $filas[0]['temporada_codigo']);
        $this->assertSame('GENERAL', $filas[0]['cliente_codigo']);
        $this->assertSame('Etiqueta caja 5 kg', $filas[0]['nombre']);
        $this->assertSame('Etiquetas', $filas[0]['categoria']);
        $this->assertSame('unidades', $filas[0]['unidad_medida']);
        $this->assertSame('ERP-ETQ-01', $filas[0]['codigo_externo']);
        $this->assertSame('activo', $filas[0]['activo']);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function test_importacion_carga_niveles_preserva_vacios_y_valida_contra_valores_existentes(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->crearItem($admin, ['stock_minimo' => 10, 'punto_reorden' => 20, 'stock_maximo' => 50]);
        $archivo = UploadedFile::fake()->createWithContent('niveles.csv', "temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida;stock_minimo;punto_reorden;stock_maximo\nGENERAL;GENERAL;FILM-01;Film stretch;insumo;rollos;;25;60\n");
        $id = $this->actingAs($admin, 'sanctum')->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.estado', 'borrador')->assertJsonPath('data.resumen.actualizaciones_estimadas', 1)->json('data.id');
        $this->postJson('/api/administracion/materiales/importaciones/'.$id.'/confirmar')->assertOk();
        $this->assertSame('10.000', $item->fresh()->stock_minimo);
        $this->assertSame('25.000', $item->fresh()->punto_reorden);
        $this->assertSame('60.000', $item->fresh()->stock_maximo);
        $archivo = UploadedFile::fake()->createWithContent('invalidos.csv', "temporada_codigo;cliente_codigo;codigo;nombre;tipo_item;unidad_medida;stock_minimo;punto_reorden;stock_maximo\nGENERAL;GENERAL;FILM-01;Film stretch;insumo;rollos;30;;\n");
        $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.estado', 'con_errores');
    }

    public function test_api_niveles_rechaza_orden_invalido_y_guarda_historial_con_actor(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $item = $this->crearItem($admin);
        $datos = ['cliente_material_id' => $item->cliente_material_id, 'codigo' => $item->codigo, 'nombre' => $item->nombre, 'categoria_operacional' => 'insumo', 'unidad_medida' => 'rollos', 'stock_minimo' => 30, 'punto_reorden' => 20, 'stock_maximo' => 50];
        $ruta = '/api/administracion/materiales/items/'.$item->id;
        $this->actingAs($admin, 'sanctum')->putJson($ruta, $datos)->assertUnprocessable()->assertJsonValidationErrors('stock_minimo');
        $this->assertNull($item->fresh()->stock_minimo);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorMateriales, 'activo' => true]);
        $this->actingAs($supervisor, 'sanctum')->putJson($ruta, [...$datos, 'stock_minimo' => 10])->assertForbidden();
        $this->get('/oficina/materiales/items/niveles')->assertOk()->assertSee('Ítems · niveles de stock');
        $this->actingAs($supervisor, 'sanctum')->getJson('/api/materiales/items/niveles')->assertOk()->assertJsonFragment(['codigo' => $item->codigo, 'stock_minimo' => null]);
        $nivelesRuta = '/api/materiales/items/'.$item->id.'/niveles';
        $this->actingAs($supervisor, 'sanctum')->putJson($nivelesRuta, ['stock_minimo' => 10, 'punto_reorden' => 20, 'stock_maximo' => 50, 'nombre' => 'No debe cambiar'])->assertOk()->assertJsonPath('data.stock_minimo', '10.000');
        $this->assertSame($item->nombre, $item->fresh()->nombre);
        $this->actingAs($supervisor, 'sanctum')->getJson('/api/materiales/items/niveles')->assertOk()->assertJsonFragment(['codigo' => $item->codigo]);
        $this->assertDatabaseHas('cambios_niveles_stock_materiales', ['item_material_id' => $item->id, 'user_id' => $supervisor->id]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroMateriales]);
        $this->actingAs($camarero, 'sanctum')->putJson($nivelesRuta, ['stock_minimo' => 5, 'punto_reorden' => 20, 'stock_maximo' => 50])->assertForbidden();
    }

    private function crearItem(User $usuario, array $datos = []): ItemMaterial
    {
        return ItemMaterial::create([
            'cliente_material_id' => ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail()->id,
            'codigo' => 'FILM-01',
            'nombre' => 'Film stretch',
            'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'rollos',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
            ...$datos,
        ]);
    }
}
