<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecturas_stock_origen', function (Blueprint $table) {
            // Null conserva el significado histórico de lectura completa de la bodega.
            $table->string('numero_pallet', 50)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('lecturas_stock_origen')->whereNotNull('numero_pallet')->exists()) {
            throw new RuntimeException('Existen lecturas puntuales de packing. No se puede retirar su identificación y presentarlas como lecturas completas.');
        }
        Schema::table('lecturas_stock_origen', fn (Blueprint $table) => $table->dropColumn('numero_pallet'));
    }
};
