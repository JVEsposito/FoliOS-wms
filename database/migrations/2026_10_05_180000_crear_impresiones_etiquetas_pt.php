<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impresiones_etiquetas_pt', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->string('payload_hash', 64);
            $table->foreignUuid('temporada_id')->constrained('temporadas');
            $table->foreignId('user_id')->constrained('users');
            $table->string('tipo', 20);
            $table->unsignedTinyInteger('copias');
            $table->text('motivo_reimpresion')->nullable();
            $table->json('etiquetas_snapshot');
            $table->timestamps();
        });
        Schema::create('impresion_etiqueta_pt_folios', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('impresion_id')->constrained('impresiones_etiquetas_pt');
            $table->foreignUuid('folio_id')->constrained('folios');
            $table->unique(['impresion_id', 'folio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impresion_etiqueta_pt_folios');
        Schema::dropIfExists('impresiones_etiquetas_pt');
    }
};
