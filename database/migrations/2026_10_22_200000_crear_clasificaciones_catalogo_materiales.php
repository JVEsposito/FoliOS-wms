<?php

use App\Services\Materiales\NombreClasificacionMaterial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categorias_comerciales_materiales', 'tipos_items_materiales'] as $tabla) {
            Schema::create($tabla, function (Blueprint $table) use ($tabla): void {
                $table->uuid('id')->primary();
                $table->string('nombre', 100);
                $table->string('nombre_normalizado', 200)->unique();
                $table->boolean('activo')->default(true);
                if ($tabla === 'tipos_items_materiales') {
                    $table->string('categoria_operacional', 40);
                    $table->string('codigo_base', 40)->nullable()->unique();
                }
                $table->foreignId('creado_por_user_id')->nullable()->constrained('users', indexName: $tabla.'_creador_fk')->restrictOnDelete();
                $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users', indexName: $tabla.'_editor_fk')->restrictOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('items_materiales', function (Blueprint $table): void {
            $table->foreignUuid('tipo_item_material_id')->nullable()
                ->constrained('tipos_items_materiales')->restrictOnDelete();
        });

        DB::transaction(function (): void {
            $nombres = DB::table('items_materiales')->whereNotNull('categoria')->distinct()->pluck('categoria');
            foreach (DB::table('clientes_proveedores_materiales')->whereNotNull('categorias')->pluck('categorias') as $categorias) {
                $nombres = $nombres->merge(json_decode($categorias, true) ?: []);
            }
            foreach ($nombres->filter()->unique(fn ($nombre) => NombreClasificacionMaterial::normalizar($nombre)) as $nombre) {
                $nombre = NombreClasificacionMaterial::limpiar($nombre);
                if ($nombre === '') {
                    continue;
                }
                DB::table('categorias_comerciales_materiales')->insert([
                    'id' => (string) Str::uuid(), 'nombre' => $nombre,
                    'nombre_normalizado' => NombreClasificacionMaterial::normalizar($nombre),
                    'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach (['insumo' => 'Insumo', 'material_mp' => 'Material MP', 'material_pt' => 'Material PT'] as $codigo => $nombre) {
                $id = (string) Str::uuid();
                DB::table('tipos_items_materiales')->insert([
                    'id' => $id, 'nombre' => $nombre,
                    'nombre_normalizado' => NombreClasificacionMaterial::normalizar($nombre),
                    'categoria_operacional' => $codigo, 'codigo_base' => $codigo,
                    'activo' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                // Se agrega el vínculo sin reescribir categorías, fechas ni clasificación histórica.
                DB::table('items_materiales')->where('categoria_operacional', $codigo)
                    ->update(['tipo_item_material_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('items_materiales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tipo_item_material_id');
        });
        Schema::dropIfExists('tipos_items_materiales');
        Schema::dropIfExists('categorias_comerciales_materiales');
    }
};
