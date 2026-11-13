<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una recepción de solo envases puede ser la devolución de envases despachados a un productor:
 * el productor CSG (con su copia al registrar) y la guía de salida que se devuelve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->foreignUuid('devolucion_productor_csg_id')->nullable()
                ->constrained('productores_csg')->restrictOnDelete();
            $table->json('devolucion_productor_snapshot')->nullable();
            $table->string('devolucion_guia_salida', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('devolucion_productor_csg_id');
            $table->dropColumn(['devolucion_productor_snapshot', 'devolucion_guia_salida']);
        });
    }
};
