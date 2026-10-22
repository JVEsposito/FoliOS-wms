<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\CategoriaComercialMaterial;
use App\Models\ClienteMaterial;
use App\Models\TipoItemMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClasificacionesCatalogoMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ClienteMaterial $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_categorias_reutilizables_rechazan_duplicados_con_tildes_espacios_y_distinta_caja(): void
    {
        $id = $this->postJson('/api/administracion/materiales/categorias', ['nombre' => '  Adhesivos   especiales ', 'activo' => true])
            ->assertCreated()->assertJsonPath('data.nombre', 'Adhesivos especiales')->json('data.id');
        $this->putJson('/api/administracion/materiales/categorias/'.$id, ['nombre' => 'Adhesivos especiales', 'activo' => false])->assertOk();
        $this->postJson('/api/administracion/materiales/categorias', ['nombre' => 'ADHÉSIVOS ESPECIALES', 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->assertDatabaseHas('categorias_comerciales_materiales', [
            'id' => $id, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id,
        ]);
        $this->assertDatabaseCount('categorias_comerciales_materiales', 1);
    }

    public function test_tipo_configurable_asigna_el_uso_operacional_sin_pedir_un_enum_al_formulario(): void
    {
        $tipo = $this->crearTipo('Adhesivo', 'insumo');
        $id = $this->postJson('/api/administracion/materiales/items', $this->datosItem([
            'tipo_item_material_id' => $tipo, 'categoria_operacional' => null,
        ]))->assertCreated()->assertJsonPath('data.categoria_operacional', 'insumo')
            ->assertJsonPath('data.tipo_item.nombre', 'Adhesivo')->json('data.id');

        $this->getJson('/api/administracion/materiales/items')->assertOk()
            ->assertJsonPath('data.0.tipo_item_material_id', $tipo)
            ->assertJsonPath('clasificaciones.categorias.0.nombre', 'Cajas');
        $this->getJson('/api/materiales/catalogo')->assertOk()->assertJsonPath('items.0.tipo_item.nombre', 'Adhesivo');
        $this->getJson('/api/materiales/items/catalogo')->assertOk()->assertJsonPath('data.0.tipo_item.nombre', 'Adhesivo');
        $this->assertDatabaseHas('items_materiales', ['id' => $id, 'categoria_operacional' => 'insumo', 'tipo_item_material_id' => $tipo]);
        $this->assertDatabaseCount('folios_materiales', 0);
    }

    public function test_tipo_y_enum_no_pueden_contradecirse_y_no_admite_funciones_inventadas(): void
    {
        $tipo = $this->crearTipo('Caja por armar', 'material_mp');
        $this->postJson('/api/administracion/materiales/items', $this->datosItem([
            'tipo_item_material_id' => $tipo, 'categoria_operacional' => 'material_pt',
        ]))->assertUnprocessable()->assertJsonValidationErrors('categoria_operacional');
        $this->postJson('/api/administracion/materiales/tipos-items', ['nombre' => 'Uso inválido', 'categoria_operacional' => 'inventado', 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('categoria_operacional');
        $this->assertDatabaseCount('items_materiales', 0);
        $this->assertDatabaseCount('categorias_comerciales_materiales', 0);
    }

    public function test_api_anterior_conserva_el_enum_y_registra_la_categoria_para_el_desplegable(): void
    {
        $this->postJson('/api/administracion/materiales/items', $this->datosItem())
            ->assertCreated()->assertJsonPath('data.categoria_operacional', 'material_mp')
            ->assertJsonPath('data.tipo_item.nombre', 'Material MP');
        $this->assertDatabaseHas('categorias_comerciales_materiales', ['nombre' => 'Cajas', 'activo' => true]);
    }

    public function test_inactivos_se_conservan_al_editar_el_item_original_pero_no_pueden_asignarse_a_otro(): void
    {
        $tipo = $this->crearTipo('Cartón de exportación', 'material_mp');
        $datos = $this->datosItem(['tipo_item_material_id' => $tipo]);
        $id = $this->postJson('/api/administracion/materiales/items', $datos)->assertCreated()->json('data.id');
        $categoria = CategoriaComercialMaterial::where('nombre', 'Cajas')->firstOrFail();
        $this->putJson('/api/administracion/materiales/categorias/'.$categoria->id, ['nombre' => 'Cajas', 'activo' => false])->assertOk();
        $this->putJson('/api/administracion/materiales/tipos-items/'.$tipo, ['nombre' => 'Cartón de exportación', 'categoria_operacional' => 'material_mp', 'activo' => false])->assertOk();

        $this->putJson('/api/administracion/materiales/items/'.$id, [...$datos, 'nombre' => 'Caja actualizada'])
            ->assertOk()->assertJsonPath('data.tipo_item.activo', false);
        $this->postJson('/api/administracion/materiales/items', [...$datos, 'codigo' => 'OTRA-CAJA'])
            ->assertUnprocessable()->assertJsonValidationErrors('categoria');
        $this->postJson('/api/administracion/materiales/items', [...$datos, 'codigo' => 'OTRA-CAJA', 'categoria' => 'Categoría nueva'])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_item_material_id');
        $this->assertDatabaseCount('items_materiales', 1);
        $this->assertDatabaseCount('categorias_comerciales_materiales', 1);
    }

    public function test_categoria_en_uso_no_renombra_datos_ni_habilitaciones_de_proveedores(): void
    {
        $this->postJson('/api/administracion/materiales/items', $this->datosItem())->assertCreated();
        $categoria = CategoriaComercialMaterial::firstOrFail();
        $this->putJson('/api/administracion/materiales/categorias/'.$categoria->id, ['nombre' => 'Otro nombre', 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->getJson('/api/administracion/materiales/clasificaciones')->assertOk()->assertJsonPath('data.categorias.0.en_uso', true);
        $this->assertDatabaseHas('items_materiales', ['categoria' => 'Cajas']);
    }

    public function test_tipo_en_uso_o_base_conserva_su_funcion_y_un_tipo_libre_si_puede_cambiar(): void
    {
        $tipo = $this->crearTipo('Bolsa especial', 'material_mp');
        $this->putJson('/api/administracion/materiales/tipos-items/'.$tipo, ['nombre' => 'Bolsa especial', 'categoria_operacional' => 'insumo', 'activo' => true])->assertOk();
        $this->postJson('/api/administracion/materiales/items', $this->datosItem(['tipo_item_material_id' => $tipo, 'categoria_operacional' => 'insumo']))->assertCreated();
        $this->putJson('/api/administracion/materiales/tipos-items/'.$tipo, ['nombre' => 'Bolsa renombrada', 'categoria_operacional' => 'material_pt', 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('categoria_operacional');
        $this->putJson('/api/administracion/materiales/tipos-items/'.$tipo, ['nombre' => 'Bolsa renombrada', 'categoria_operacional' => 'insumo', 'activo' => true])->assertOk();
        $base = TipoItemMaterial::where('codigo_base', 'material_pt')->firstOrFail();
        $this->putJson('/api/administracion/materiales/tipos-items/'.$base->id, ['nombre' => $base->nombre, 'categoria_operacional' => 'insumo', 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('categoria_operacional');
        $this->assertDatabaseHas('items_materiales', ['tipo_item_material_id' => $tipo, 'categoria_operacional' => 'insumo']);
    }

    public function test_permisos_del_catalogo_protegen_lectura_creacion_y_edicion_de_los_maestros(): void
    {
        $tipo = TipoItemMaterial::firstOrFail();
        $categoria = $this->postJson('/api/administracion/materiales/categorias', ['nombre' => 'Reservada', 'activo' => true])->assertCreated()->json('data.id');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum');
        $this->getJson('/api/administracion/materiales/clasificaciones')->assertForbidden();
        $this->postJson('/api/administracion/materiales/categorias', ['nombre' => 'Intrusión', 'activo' => true])->assertForbidden();
        $this->postJson('/api/administracion/materiales/tipos-items', ['nombre' => 'Intrusión', 'activo' => true, 'categoria_operacional' => 'insumo'])->assertForbidden();
        $this->putJson('/api/administracion/materiales/categorias/'.$categoria, ['nombre' => 'Intrusión', 'activo' => false])->assertForbidden();
        $this->putJson('/api/administracion/materiales/tipos-items/'.$tipo->id, ['nombre' => 'Intrusión', 'activo' => false, 'categoria_operacional' => 'insumo'])->assertForbidden();
    }

    public function test_editar_sin_campo_erp_conserva_los_mapeos_de_items_proveedores_destinos_y_clientes(): void
    {
        $item = $this->postJson('/api/administracion/materiales/items', $this->datosItem(['codigo_externo' => 'ERP-ITEM']))->assertCreated()->json('data.id');
        $this->putJson('/api/administracion/materiales/items/'.$item, $this->datosItem(['nombre' => 'Descripción actualizada']))
            ->assertOk()->assertJsonPath('data.codigo_externo', 'ERP-ITEM');
        $proveedor = ['nombre' => 'Proveedor con integración', 'activo' => true, 'cliente_ids' => [$this->cliente->cliente_id],
            'categorias' => [['cliente_id' => $this->cliente->cliente_id, 'categoria' => 'Cajas']]];
        $id = $this->postJson('/api/administracion/materiales/proveedores', [...$proveedor, 'codigo_externo' => 'ERP-PROV'])->assertCreated()->json('data.id');
        $this->putJson('/api/administracion/materiales/proveedores/'.$id, $proveedor)->assertOk()->assertJsonPath('data.codigo_externo', 'ERP-PROV');
        $destino = ['nombre' => 'Packing integrado', 'centro_costo' => 'CC-ERP', 'activo' => true];
        $id = $this->postJson('/api/administracion/materiales/destinos', [...$destino, 'codigo_externo' => 'ERP-DEST'])->assertCreated()->json('data.id');
        $this->putJson('/api/administracion/materiales/destinos/'.$id, $destino)->assertOk()->assertJsonPath('data.codigo_externo', 'ERP-DEST');
        $cliente = ['codigo' => 'CLI-ERP', 'nombre' => 'Cliente integrado', 'activo' => true];
        $id = $this->postJson('/api/administracion/clientes', [...$cliente, 'codigo_externo' => 'ERP-CLI'])->assertCreated()->json('data.id');
        $this->putJson('/api/administracion/clientes/'.$id, $cliente)->assertOk()->assertJsonPath('data.codigo_externo', 'ERP-CLI');
        $this->putJson('/api/administracion/materiales/items/'.$item, $this->datosItem(['codigo_externo' => null]))
            ->assertOk()->assertJsonPath('data.codigo_externo', null);
    }

    public function test_importacion_registra_categorias_nuevas_y_conserva_el_tipo_configurable_de_un_item(): void
    {
        $tipo = $this->crearTipo('Cartón especial', 'material_mp');
        $id = $this->postJson('/api/administracion/materiales/items', $this->datosItem(['tipo_item_material_id' => $tipo]))->assertCreated()->json('data.id');
        $archivo = UploadedFile::fake()->createWithContent('categorias.csv', "temporada_codigo;cliente_codigo;codigo;nombre;categoria;tipo_item;unidad_medida\nGENERAL;GENERAL;CAJA-PR2;Caja actualizada;Embalajes nuevos;material_mp;unidades\n");
        $importacion = $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/administracion/materiales/importaciones/'.$importacion.'/confirmar')->assertOk();
        $this->assertDatabaseHas('items_materiales', ['id' => $id, 'tipo_item_material_id' => $tipo, 'categoria' => 'Embalajes nuevos']);
        $this->assertDatabaseHas('categorias_comerciales_materiales', ['nombre' => 'Embalajes nuevos', 'creado_por_user_id' => $this->admin->id]);
    }

    public function test_importacion_detecta_inactivos_antes_de_confirmar_y_revalida_cambios_posteriores(): void
    {
        $categoria = $this->postJson('/api/administracion/materiales/categorias', ['nombre' => 'Obsoleta', 'activo' => false])->assertCreated()->json('data.id');
        $archivo = UploadedFile::fake()->createWithContent('inactiva.csv', "temporada_codigo;cliente_codigo;codigo;nombre;categoria;tipo_item;unidad_medida\nGENERAL;GENERAL;NUEVO;Material nuevo;Obsoleta;insumo;unidades\n");
        $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.resumen.filas_con_error', 1);

        $archivo = UploadedFile::fake()->createWithContent('vigente.csv', "temporada_codigo;cliente_codigo;codigo;nombre;categoria;tipo_item;unidad_medida\nGENERAL;GENERAL;NUEVO;Material nuevo;Categoría importada;insumo;unidades\n");
        $importacion = $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.resumen.filas_con_error', 0)->json('data.id');
        $tipo = TipoItemMaterial::where('codigo_base', 'insumo')->firstOrFail();
        $this->putJson('/api/administracion/materiales/tipos-items/'.$tipo->id, ['nombre' => $tipo->nombre, 'categoria_operacional' => 'insumo', 'activo' => false])->assertOk();
        $this->postJson('/api/administracion/materiales/importaciones/'.$importacion.'/confirmar')
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_item_material_id');
        $this->assertDatabaseCount('items_materiales', 0);
        $this->assertDatabaseCount('categorias_comerciales_materiales', 1);
        $this->assertDatabaseHas('categorias_comerciales_materiales', ['id' => $categoria, 'activo' => false]);
    }

    public function test_categoria_libre_puede_renombrarse_y_los_nombres_de_tipos_tambien_son_unicos(): void
    {
        $id = $this->postJson('/api/administracion/materiales/categorias', ['nombre' => 'Categoría libre', 'activo' => true])->assertCreated()->json('data.id');
        $this->putJson('/api/administracion/materiales/categorias/'.$id, ['nombre' => 'Adhesivos', 'activo' => true])->assertOk();
        $this->crearTipo('Pegamento especial', 'insumo');
        $this->postJson('/api/administracion/materiales/tipos-items', ['nombre' => ' PEGAMÉNTO   ESPECIAL ', 'categoria_operacional' => 'material_mp', 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson('/api/administracion/materiales/categorias', ['nombre' => ['malformado'], 'activo' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->getJson('/api/materiales/items/catalogo?tipo_item_material_id='.$id)->assertUnprocessable();
    }

    private function crearTipo(string $nombre, string $operacional): string
    {
        return $this->postJson('/api/administracion/materiales/tipos-items', ['nombre' => $nombre, 'categoria_operacional' => $operacional, 'activo' => true])
            ->assertCreated()->json('data.id');
    }

    private function datosItem(array $cambios = []): array
    {
        return [...[
            'cliente_material_id' => $this->cliente->id, 'codigo' => 'CAJA-PR2', 'nombre' => 'Caja de prueba',
            'categoria' => 'Cajas', 'categoria_operacional' => 'material_mp', 'unidad_medida' => 'unidades', 'activo' => true,
        ], ...$cambios];
    }
}
