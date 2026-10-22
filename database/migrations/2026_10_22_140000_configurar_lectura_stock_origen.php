<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_origen', function (Blueprint $table): void {
            $table->string('clave', 40)->primary();
            $table->foreignUuid('conexion_externa_id')->constrained('conexiones_externas')->restrictOnDelete();
            $table->text('ajustes'); // Cifrados: nombres reales de columnas y valores de filtros.
            $table->unsignedInteger('version');
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('lecturas_stock_origen', function (Blueprint $table): void {
            $table->string('ambito', 64)->default('legacy')->index();
        });
        Schema::table('pallets_stock_origen', function (Blueprint $table): void {
            $table->string('ambito', 64)->default('legacy');
            $table->dropUnique('pallet_stock_origen_unico');
            $table->unique(['ambito', 'bodega', 'numero_pallet'], 'pallet_stock_origen_unico');
        });
    }

    public function down(): void
    {
        if (DB::table('pallets_stock_origen')->select('bodega', 'numero_pallet')->groupBy('bodega', 'numero_pallet')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Hay copias de distintas configuraciones; no se puede retirar su separación sin resolverlas.');
        }
        Schema::table('pallets_stock_origen', function (Blueprint $table): void {
            $table->dropUnique('pallet_stock_origen_unico');
            $table->dropColumn('ambito');
            $table->unique(['bodega', 'numero_pallet'], 'pallet_stock_origen_unico');
        });
        Schema::table('lecturas_stock_origen', fn (Blueprint $table) => $table->dropColumn('ambito'));
        Schema::dropIfExists('configuraciones_origen');
    }
};
