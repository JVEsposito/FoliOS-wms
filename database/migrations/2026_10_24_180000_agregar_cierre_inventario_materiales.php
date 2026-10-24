<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tomas_inventario_materiales', function (Blueprint $t): void {
            $t->string('finalidad')->default('operativo');
            $t->foreignUuid('almacen_id')->nullable()->constrained('destinos_materiales', indexName: 'toma_cierre_almacen_fk');
            $t->json('cierre_snapshot')->nullable();
            $t->index(['temporada_id', 'finalidad', 'estado'], 'toma_cierre_temporada');
        });
        Schema::table('tomas_inventario_materiales_resultados', function (Blueprint $t): void {
            $t->string('clasificacion')->nullable();
            $t->foreignId('decidida_por_user_id')->nullable()->constrained('users', indexName: 'toma_decidida_usuario_fk');
            $t->timestamp('decidida_at')->nullable();
            $t->json('historial_decisiones')->nullable();
            $t->json('aplicacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tomas_inventario_materiales_resultados', function (Blueprint $t): void {
            $t->dropForeign('toma_decidida_usuario_fk');
            $t->dropColumn('decidida_por_user_id');
            $t->dropColumn(['clasificacion', 'decidida_at', 'historial_decisiones', 'aplicacion']);
        });
        Schema::table('tomas_inventario_materiales', function (Blueprint $t): void {
            $t->dropIndex('toma_cierre_temporada');
            $t->dropForeign('toma_cierre_almacen_fk');
            $t->dropColumn('almacen_id');
            $t->dropColumn(['finalidad', 'cierre_snapshot']);
        });
    }
};
