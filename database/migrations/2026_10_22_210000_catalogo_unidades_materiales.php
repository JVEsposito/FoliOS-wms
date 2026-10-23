<?php

use App\Services\Materiales\UnidadesMateriales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('codigo', 40)->unique();
            $t->string('nombre', 100);
            $t->boolean('activa')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('alias_unidades_materiales', function (Blueprint $t): void {
            $t->string('clave', 40)->primary();
            $t->foreignUuid('unidad_material_id')->constrained('unidades_materiales')->restrictOnDelete();
        });
        Schema::table('items_materiales', function (Blueprint $t): void {
            $t->foreignUuid('unidad_material_id')->nullable()->constrained('unidades_materiales')->restrictOnDelete();
        });
        Schema::create('conversiones_unidad_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('item_material_id')->constrained('items_materiales')->restrictOnDelete();
            $t->foreignUuid('unidad_origen_id')->constrained('unidades_materiales')->restrictOnDelete();
            $t->foreignUuid('unidad_base_id')->constrained('unidades_materiales')->restrictOnDelete();
            $t->decimal('factor', 14, 6);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('activa')->default(true);
            $t->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['item_material_id', 'unidad_origen_id'], 'conversion_unidad_item_unica');
        });
        Schema::create('eventos_unidad_materiales', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('unidad_material_id')->nullable()->constrained('unidades_materiales')->restrictOnDelete();
            $t->foreignUuid('item_material_id')->nullable()->constrained('items_materiales')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('accion', 40);
            $t->json('antes')->nullable();
            $t->json('despues');
            $t->string('motivo', 500)->nullable();
            $t->timestamps();
        });
        Schema::table('detalles_recepciones_materiales', fn (Blueprint $t) => $t->json('conversion_unidad')->nullable());

        $ids = [];
        $crear = function (string $texto) use (&$ids): string {
            $codigo = UnidadesMateriales::canonica($texto);
            if (! isset($ids[$codigo])) {
                $id = $ids[$codigo] = (string) Str::uuid();
                DB::table('unidades_materiales')->insert(['id' => $id, 'codigo' => $codigo, 'nombre' => $codigo, 'activa' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                foreach (array_unique([$codigo, ...(UnidadesMateriales::ALIAS[$codigo] ?? [])]) as $alias) {
                    DB::table('alias_unidades_materiales')->insert(['clave' => $alias, 'unidad_material_id' => $id]);
                }
            }

            return $ids[$codigo];
        };
        foreach (array_keys(UnidadesMateriales::ALIAS) as $codigo) {
            $crear($codigo);
        }
        DB::table('items_materiales')->select(['id', 'unidad_medida'])->orderBy('id')->chunkById(500, function ($items) use ($crear): void {
            foreach ($items as $item) {
                $texto = trim($item->unidad_medida);
                if ($texto !== '') {
                    DB::table('items_materiales')->where('id', $item->id)->update(['unidad_material_id' => $crear($texto)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('detalles_recepciones_materiales', fn (Blueprint $t) => $t->dropColumn('conversion_unidad'));
        Schema::dropIfExists('eventos_unidad_materiales');
        Schema::dropIfExists('conversiones_unidad_materiales');
        Schema::table('items_materiales', fn (Blueprint $t) => $t->dropConstrainedForeignId('unidad_material_id'));
        Schema::dropIfExists('alias_unidades_materiales');
        Schema::dropIfExists('unidades_materiales');
    }
};
