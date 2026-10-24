<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['despachos_materiales', 'ordenes_transformacion_materiales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                // NULL conserva Bodega Central para documentos anteriores.
                $t->foreignUuid('almacen_origen_id')->nullable()->constrained('destinos_materiales')->restrictOnDelete();
            });
        }
        Schema::table('destinos_materiales', fn (Blueprint $t) => $t->unsignedBigInteger('version_bodega')->default(0));
        Schema::create('eventos_bodegas_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->uuid('almacen_material_id')->index();
            $t->unsignedBigInteger('user_id');
            $t->string('usuario');
            $t->string('payload_hash', 64);
            $t->json('anterior')->nullable();
            $t->json('resultado');
            $t->timestamp('ocurrido_at', 6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_bodegas_materiales');
        Schema::table('destinos_materiales', fn (Blueprint $t) => $t->dropColumn('version_bodega'));
        foreach (['despachos_materiales', 'ordenes_transformacion_materiales'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropConstrainedForeignId('almacen_origen_id'));
        }
    }
};
