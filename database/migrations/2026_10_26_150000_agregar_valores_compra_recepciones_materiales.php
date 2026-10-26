<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalles_recepciones_materiales', function (Blueprint $t): void {
            $t->decimal('precio_unitario_compra', 15, 6)->nullable();
            $t->string('moneda_compra', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('detalles_recepciones_materiales', function (Blueprint $t): void {
            $t->dropColumn(['precio_unitario_compra', 'moneda_compra']);
        });
    }
};
