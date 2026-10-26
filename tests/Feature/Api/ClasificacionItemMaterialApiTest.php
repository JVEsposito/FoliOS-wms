<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClasificacionItemMaterialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tipo_operacional_es_obligatorio_y_puede_agregarse_a_un_item_existente(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/materiales/items', [
                'cliente_material_id' => $cliente->id,
                'codigo' => 'SIN-TIPO-NUEVO',
                'nombre' => 'Material sin clasificación',
                'categoria' => 'Cajas',
                'unidad_medida' => 'unidades',
                'activo' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('categoria_operacional');

        $item = ItemMaterial::create([
            'cliente_material_id' => $cliente->id,
            'codigo' => 'EXISTENTE-SIN-TIPO',
            'nombre' => 'Caja existente sin tipo',
            'categoria' => 'Cajas',
            'categoria_operacional' => null,
            'unidad_medida' => 'unidades',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/materiales/items/{$item->id}", [
                'cliente_material_id' => $cliente->id,
                'codigo' => $item->codigo,
                'nombre' => $item->nombre,
                'categoria' => $item->categoria,
                'categoria_operacional' => CategoriaOperacionalMaterial::MaterialMp->value,
                'unidad_medida' => $item->unidad_medida,
                'codigo_externo' => null,
                'activo' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.categoria_operacional', 'material_mp')
            ->assertJsonPath('data.categoria_operacional_etiqueta', 'Material de embalaje sin preparar');

        $this->assertDatabaseHas('items_materiales', [
            'id' => $item->id,
            'categoria_operacional' => 'material_mp',
        ]);
    }

    public function test_proveedor_puede_configurar_categoria_comercial_antes_de_tipificar_sus_items(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $cliente = ClienteMaterial::query()->with('cliente')->where('codigo', 'GENERAL')->firstOrFail();
        ItemMaterial::create([
            'cliente_material_id' => $cliente->id,
            'codigo' => 'CAJA-PENDIENTE-TIPO',
            'nombre' => 'Caja pendiente de clasificación',
            'categoria' => 'Cajas',
            'categoria_operacional' => null,
            'unidad_medida' => 'unidades',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/materiales/proveedores', [
                'codigo' => 'PRV-PENDIENTE',
                'nombre' => 'Proveedor pendiente de clasificación',
                'codigo_externo' => null,
                'activo' => true,
                'cliente_ids' => [$cliente->cliente_id],
                'categorias' => [[
                    'cliente_id' => $cliente->cliente_id,
                    'categoria' => 'Cajas',
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.categorias.0.categoria', 'CAJAS');
    }
}
