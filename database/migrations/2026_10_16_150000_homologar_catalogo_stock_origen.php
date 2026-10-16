<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculos entre los códigos del sistema de origen y el catálogo de validación de la
 * temporada («CEREZAS» → especie Cereza, «C1» y «CAT1» → Categoría 1), y los datos del
 * origen que hacen falta para incorporar al catálogo lo que todavía no está.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pallets_stock_origen', function (Blueprint $table): void {
            $table->string('especie_codigo', 30)->nullable()->after('especie');
            $table->string('variedad_codigo', 30)->nullable()->after('variedad');
            $table->decimal('envase_kilos', 8, 3)->nullable()->after('envase');
        });

        Schema::create('homologaciones_stock_origen', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->string('dimension', 20);
            // Elemento del catálogo que acota el valor: especie (variedad, calibre, envase), cliente (marca, envase) o CSG.
            $table->string('contexto', 80)->default('');
            $table->string('valor', 150);
            $table->uuid('entidad_id');
            $table->string('accion', 20);
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['temporada_id', 'dimension', 'contexto', 'valor'], 'homologacion_stock_origen_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homologaciones_stock_origen');
        Schema::table('pallets_stock_origen', function (Blueprint $table): void {
            $table->dropColumn(['especie_codigo', 'variedad_codigo', 'envase_kilos']);
        });
    }
};
