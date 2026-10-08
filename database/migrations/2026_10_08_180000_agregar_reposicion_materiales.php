<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items_materiales', function (Blueprint $t) {
            foreach (['stock_minimo', 'punto_reorden', 'stock_maximo'] as $campo) {
                $t->decimal($campo, 14, 3)->nullable();
            }
            $t->string('estado_reposicion', 30)->nullable();
            $t->unsignedBigInteger('reposicion_revision')->default(0);
            $t->timestamp('reposicion_calculada_at')->nullable();
        });
        Schema::table('movimientos_almacenes_materiales', fn (Blueprint $t) => $t->index(['item_material_id', 'ocurrido_at'], 'reposicion_almacen_item_fecha'));
        Schema::table('movimientos_inventario_materiales', fn (Blueprint $t) => $t->index(['item_material_id', 'ocurrido_at'], 'reposicion_inventario_item_fecha'));
        Schema::create('cambios_niveles_stock_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('item_material_id')->constrained('items_materiales')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('anteriores');
            $t->json('nuevos');
            $t->timestamp('ocurrido_at');
            $t->index(['item_material_id', 'ocurrido_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cambios_niveles_stock_materiales');
        Schema::table('movimientos_almacenes_materiales', fn (Blueprint $t) => $t->dropIndex('reposicion_almacen_item_fecha'));
        Schema::table('movimientos_inventario_materiales', fn (Blueprint $t) => $t->dropIndex('reposicion_inventario_item_fecha'));
        Schema::table('items_materiales', fn (Blueprint $t) => $t->dropColumn(['stock_minimo', 'punto_reorden', 'stock_maximo', 'estado_reposicion', 'reposicion_revision', 'reposicion_calculada_at']));
    }
};
