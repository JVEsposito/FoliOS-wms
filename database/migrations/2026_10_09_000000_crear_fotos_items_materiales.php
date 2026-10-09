<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items_materiales', fn (Blueprint $t) => $t->unsignedInteger('fotos_version')->default(0));
        Schema::create('fotos_items_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('item_material_id')->constrained('items_materiales');
            // Siempre apunta al registro original, conservado con borrado lógico.
            $t->uuid('archivo_origen_id')->index();
            $t->string('ruta');
            $t->string('ruta_miniatura');
            $t->string('mime', 40);
            $t->unsignedInteger('tamano_bytes');
            $t->unsignedInteger('ancho');
            $t->unsignedInteger('alto');
            $t->unsignedSmallInteger('orden');
            $t->boolean('principal')->default(false);
            $t->foreignId('subida_por_user_id')->nullable()->constrained('users');
            $t->timestamp('subida_at');
            $t->foreignId('eliminada_por_user_id')->nullable()->constrained('users');
            $t->softDeletes();
            $t->timestamps();
            $t->string('principal_item_id', 36)->nullable()->storedAs('CASE WHEN principal = 1 AND deleted_at IS NULL THEN item_material_id ELSE NULL END');
            $t->unique('principal_item_id', 'foto_item_principal_unica');
            $t->index(['item_material_id', 'deleted_at', 'orden'], 'foto_item_orden_activa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotos_items_materiales');
        Schema::table('items_materiales', fn (Blueprint $t) => $t->dropColumn('fotos_version'));
    }
};
