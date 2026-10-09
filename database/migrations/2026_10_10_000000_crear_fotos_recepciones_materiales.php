<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotos_recepciones_materiales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recepcion_material_id')->constrained('recepciones_materiales')->restrictOnDelete();
            $table->enum('tipo', ['documento', 'referencial']);
            $table->unsignedSmallInteger('orden');
            $table->string('ruta_original');
            $table->string('ruta_miniatura');
            $table->string('mime', 50);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('ancho');
            $table->unsignedInteger('alto');
            $table->char('sha256', 64);
            $table->uuid('operacion_id')->unique();
            $table->foreignId('subida_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes('eliminada_at');
            $table->foreignId('eliminada_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('motivo_eliminacion')->nullable();
            $table->index(['recepcion_material_id', 'tipo', 'eliminada_at'], 'fotos_recepcion_tipo_activas');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotos_recepciones_materiales');
    }
};
