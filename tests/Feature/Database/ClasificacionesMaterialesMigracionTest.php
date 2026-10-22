<?php

namespace Tests\Feature\Database;

use App\Models\ClienteMaterial;
use App\Models\ClienteProveedorMaterial;
use App\Models\ProveedorMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClasificacionesMaterialesMigracionTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_incorpora_las_categorias_y_tipos_sin_reescribir_datos_historicos_o_tipificar_pendientes(): void
    {
        $migracion = require database_path('migrations/2026_10_22_200000_crear_clasificaciones_catalogo_materiales.php');
        $migracion->down();
        $usuario = User::factory()->create();
        $cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        $items = [];
        foreach ([[' Cajas ', 'material_mp'], ['CAJAS', 'material_pt'], ['Adhesivos', 'insumo'], [null, null]] as $indice => [$categoria, $tipo]) {
            $id = (string) Str::uuid();
            $items[$id] = [
                'id' => $id, 'cliente_material_id' => $cliente->id, 'codigo' => 'HISTORICO-'.$indice,
                'nombre' => 'Ítem histórico '.$indice, 'categoria' => $categoria, 'categoria_operacional' => $tipo,
                'unidad_medida' => 'unidades', 'codigo_externo' => 'ERP-'.$indice, 'activo' => $indice !== 1,
                'creado_por_user_id' => $usuario->id, 'actualizado_por_user_id' => $usuario->id,
                'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-02-01 10:00:00',
            ];
            DB::table('items_materiales')->insert($items[$id]);
        }
        $proveedor = ProveedorMaterial::create(['nombre' => 'Proveedor histórico', 'codigo' => 'CSF', 'activo' => true]);
        $asignacion = ClienteProveedorMaterial::create([
            'cliente_id' => $cliente->cliente_id, 'proveedor_material_id' => $proveedor->id,
            'activo' => false, 'categorias' => [' Cajas ', 'Etiquetas'],
        ]);

        $migracion->up();

        foreach ($items as $id => $datos) {
            $this->assertDatabaseHas('items_materiales', $datos);
            $actual = DB::table('items_materiales')->where('id', $id)->first();
            if ($datos['categoria_operacional'] === null) {
                $this->assertNull($actual->tipo_item_material_id);
            } else {
                $this->assertDatabaseHas('tipos_items_materiales', ['id' => $actual->tipo_item_material_id, 'categoria_operacional' => $datos['categoria_operacional']]);
            }
        }
        $this->assertDatabaseCount('items_materiales', 4);
        $this->assertDatabaseCount('tipos_items_materiales', 3);
        $this->assertDatabaseCount('categorias_comerciales_materiales', 3);
        $this->assertDatabaseCount('folios_materiales', 0);
        $this->assertSame([' Cajas ', 'Etiquetas'], $asignacion->refresh()->categorias);
        $this->assertSame('CSF', $proveedor->refresh()->codigo);
    }
}
