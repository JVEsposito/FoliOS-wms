<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\User;
use App\Services\Materiales\LectorPlanillaMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MayusculasCatalogoMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_normaliza_nombres_categorias_tipos_y_unidades_en_el_servidor_y_rechaza_duplicados(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $categoria = $this->postJson('/api/administracion/materiales/categorias', ['nombre' => '  Cartón   pequeño ', 'activo' => true])
            ->assertCreated()->assertJsonPath('data.nombre', 'CARTÓN PEQUEÑO')->json('data.id');
        $tipo = $this->postJson('/api/administracion/materiales/tipos-items', ['nombre' => '  Bolsa   ñandú ', 'categoria_operacional' => 'material_mp', 'activo' => true])
            ->assertCreated()->assertJsonPath('data.nombre', 'BOLSA ÑANDÚ')->json('data.id');
        $cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        $datos = ['cliente_material_id' => $cliente->id, 'codigo' => 'mi-item', 'nombre' => '  Caja   pequeña ñandú ', 'categoria' => 'cartón pequeño', 'tipo_item_material_id' => $tipo, 'unidad_medida' => 'KG'];
        $id = $this->postJson('/api/administracion/materiales/items', $datos)->assertCreated()
            ->assertJsonPath('data.nombre', 'CAJA PEQUEÑA ÑANDÚ')->assertJsonPath('data.categoria', 'CARTÓN PEQUEÑO')
            ->assertJsonPath('data.unidad_medida', 'kg')->json('data.id');
        $this->putJson('/api/administracion/materiales/items/'.$id, [...$datos, 'nombre' => 'caja renovada'])
            ->assertOk()->assertJsonPath('data.nombre', 'CAJA RENOVADA');
        $this->postJson('/api/administracion/materiales/categorias', ['nombre' => 'CARTON PEQUEÑO', 'activo' => true])->assertUnprocessable();
        $this->assertDatabaseHas('categorias_comerciales_materiales', ['id' => $categoria, 'nombre' => 'CARTÓN PEQUEÑO']);
        $this->postJson('/api/administracion/materiales/unidades', ['codigo' => 'resmas', 'nombre' => '  Resmas   pequeñas ', 'alias' => ['resma'], 'activa' => true, 'version_conocida' => 0, 'motivo' => 'Alta solicitada por bodega'])
            ->assertCreated()->assertJsonPath('data.nombre', 'RESMAS PEQUEÑAS')->assertJsonPath('data.codigo', 'resmas');
        $this->postJson('/api/administracion/materiales/items', [...$datos, 'codigo' => 'MALFORMADO', 'nombre' => ['incorrecto']])->assertUnprocessable();
    }

    public function test_importa_mayusculas_y_el_excel_descargado_se_reimporta_sin_cambios(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $archivo = UploadedFile::fake()->createWithContent('catalogo.csv', "temporada_codigo;cliente_codigo;codigo;nombre;categoria;tipo_item;unidad_medida\nGENERAL;GENERAL;IMPORTADO;  Caja   pequeña ;cartón;material_mp;KG\n");
        $id = $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.filas.0.nombre', 'CAJA PEQUEÑA')->json('data.id');
        $this->postJson('/api/administracion/materiales/importaciones/'.$id.'/confirmar')->assertOk();
        $item = ItemMaterial::where('codigo', 'IMPORTADO')->firstOrFail();
        $this->assertSame('CAJA PEQUEÑA', $item->nombre);
        $this->assertSame('CARTÓN', $item->categoria);
        $respuesta = $this->get('/api/materiales/items/catalogo/exportar/xlsx')->assertOk();
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        try {
            $excel = new UploadedFile($ruta, 'catalogo.xlsx', null, null, true);
            $fila = app(LectorPlanillaMaterial::class)->leer($excel)[0];
            $this->assertSame('CAJA PEQUEÑA', $fila['nombre']);
            $this->assertSame('KG', $fila['unidad_medida']);
            $id = $this->post('/api/administracion/materiales/importaciones/previsualizar', ['archivo' => $excel], ['Accept' => 'application/json'])
                ->assertCreated()->assertJsonPath('data.resumen.sin_cambios_estimados', 1)->json('data.id');
            $this->postJson('/api/administracion/materiales/importaciones/'.$id.'/confirmar')
                ->assertOk()->assertJsonPath('data.resumen.creados', 0)->assertJsonPath('data.resumen.actualizados', 0);
        } finally {
            @unlink($ruta);
        }
        $this->assertDatabaseCount('items_materiales', 1);
        $this->assertDatabaseCount('folios_materiales', 0);
    }
}
