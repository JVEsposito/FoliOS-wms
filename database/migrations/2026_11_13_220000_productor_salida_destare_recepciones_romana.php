<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camión con varios CSG: en el destare se indica a qué productor se entregan los envases que salen.
 * Se guarda la copia del CSG (código, nombre y productor) para atribuir la salida en la cuenta corriente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->json('salida_productor_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->dropColumn('salida_productor_snapshot');
        });
    }
};
