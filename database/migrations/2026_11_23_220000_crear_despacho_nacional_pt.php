<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despachos_nacionales_pt', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->string('destinatario', 160);
            $table->string('guia', 60);
            $table->string('patente', 20)->nullable();
            $table->timestamp('despachado_at')->index();
            $table->timestamps();
        });

        Schema::create('partidas_nacionales_pt', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('folio_origen_id')->constrained('folios')->restrictOnDelete();
            $table->foreignUuid('folio_id')->unique()->constrained('folios')->restrictOnDelete();
            $table->foreignUuid('despacho_id')->nullable()->constrained('despachos_nacionales_pt')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('cajas');
            $table->string('motivo', 500);
            $table->char('payload_hash', 64);
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['despacho_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partidas_nacionales_pt');
        Schema::dropIfExists('despachos_nacionales_pt');
    }
};
