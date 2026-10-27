<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presencias_camareros', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('dispositivo_id')->constrained('dispositivos')->cascadeOnDelete();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->nullable();
            $table->string('estado', 30);
            $table->timestamp('ultima_actividad_at');
            $table->timestamp('desconectada_at')->nullable();
            $table->timestamp('disponible_desde')->nullable();
            $table->uuid('aviso_disponible_id')->nullable();
            $table->timestamps();
            $table->index(['temporada_id', 'ultima_actividad_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presencias_camareros');
    }
};
