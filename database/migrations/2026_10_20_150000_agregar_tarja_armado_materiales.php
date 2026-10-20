<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correlativo del folio único de armado: AR + código del ítem + código de bodega +
        // 5 dígitos. Se lleva por texto del código (no por id) porque el folio resultante es
        // único en toda la tabla de folios y dos clientes podrían usar el mismo código.
        Schema::create('correlativos_folio_armado_materiales', function (Blueprint $table): void {
            $table->string('codigo_item', 64);
            $table->string('codigo_bodega', 10);
            $table->unsignedInteger('ultimo_numero')->default(0);
            $table->timestamps();
            $table->primary(['codigo_item', 'codigo_bodega'], 'correlativos_folio_armado_pk');
        });

        Schema::table('ordenes_transformacion_materiales', function (Blueprint $table): void {
            // Datos de la tarja de armado fijados al planificar: bodega, guía y proveedor de
            // las cajas y el lote. Nulo cuando la orden no se arma con tarja.
            $table->json('datos_armado')->nullable()->after('snapshot_receta');
        });
    }

    public function down(): void
    {
        Schema::table('ordenes_transformacion_materiales', function (Blueprint $table): void {
            $table->dropColumn('datos_armado');
        });
        Schema::dropIfExists('correlativos_folio_armado_materiales');
    }
};
