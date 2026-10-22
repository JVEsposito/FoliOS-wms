<?php

namespace Tests\Feature\Database;

use App\Models\ClienteMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnidadesMaterialesMigracionTest extends TestCase
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

    public function test_vincula_las_variantes_sin_reescribir_textos_ni_fechas_historicas(): void
    {
        $migracion = require database_path('migrations/2026_10_22_210000_catalogo_unidades_materiales.php');
        $migracion->down();
        $cliente = ClienteMaterial::firstOrFail();
        $usuario = User::factory()->create();
        $ids = [];
        foreach ([' UN ', 'unidad', 'Unidades', 'KG', 'Kilógramos', 'presentación propia'] as $i => $texto) {
            $id = $ids[] = (string) Str::uuid();
            DB::table('items_materiales')->insert(['id' => $id, 'cliente_material_id' => $cliente->id, 'codigo' => 'LEGADO-'.$i, 'nombre' => 'Material existente '.$i, 'unidad_medida' => $texto, 'activo' => true, 'origen_sistema' => 'manual', 'creado_por_user_id' => $usuario->id, 'actualizado_por_user_id' => $usuario->id, 'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-02-01 09:00:00']);
        }
        $antes = DB::table('items_materiales')->whereIn('id', $ids)->orderBy('id')->get()->map(fn ($i) => (array) $i)->all();
        $migracion->up();
        $despues = DB::table('items_materiales')->whereIn('id', $ids)->orderBy('id')->get()->map(function ($i) {
            $datos = (array) $i;
            unset($datos['unidad_material_id']);

            return $datos;
        })->all();
        $this->assertSame($antes, $despues);
        $unidades = DB::table('items_materiales')->whereIn('id', array_slice($ids, 0, 3))->pluck('unidad_material_id')->unique();
        $this->assertCount(1, $unidades);
        $this->assertNotNull($unidades->first());
        $this->assertSame(DB::table('items_materiales')->where('id', $ids[3])->value('unidad_material_id'), DB::table('items_materiales')->where('id', $ids[4])->value('unidad_material_id'));
        $this->assertDatabaseHas('unidades_materiales', ['codigo' => 'presentacion propia']);
    }
}
