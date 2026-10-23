<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conciliaciones_validacion_origen', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('validacion_pallet_id')->constrained('validaciones_pallet')->restrictOnDelete();
            $table->string('ambito', 64);
            $table->string('bodega', 30);
            $table->string('numero_folio', 100);
            $table->string('estado', 30);
            $table->string('contexto', 40)->default('vigente');
            $table->string('captura', 40);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('tuvo_diferencias')->default(false);
            $table->json('validacion_inicial');
            $table->json('validacion_actual');
            $table->json('planta_inicial')->nullable();
            $table->json('planta_actual')->nullable();
            $table->json('comparacion');
            // Las lecturas se purgan: el respaldo completo vive en los JSON y en los eventos.
            $table->uuid('lectura_id')->nullable();
            $table->timestamp('lectura_at')->nullable();
            $table->timestamp('validado_at');
            $table->timestamp('comprobado_at');
            $table->timestamp('cuadrado_at')->nullable();
            $table->timestamps();
            $table->unique(['validacion_pallet_id', 'ambito', 'bodega'], 'conciliacion_validacion_fuente_unica');
            $table->index(['temporada_id', 'estado', 'validado_at'], 'conciliacion_validacion_listado');
        });
        Schema::create('eventos_conciliacion_validacion', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('conciliacion_id')->constrained('conciliaciones_validacion_origen')->restrictOnDelete();
            $table->uuid('operacion_id')->nullable()->unique();
            $table->char('payload_hash', 64)->nullable();
            $table->string('tipo', 40);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('usuario_nombre', 150)->nullable();
            $table->text('comentario')->nullable();
            $table->string('referencia', 250)->nullable();
            $table->json('datos');
            $table->timestamp('created_at');
            $table->index(['conciliacion_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_conciliacion_validacion');
        Schema::dropIfExists('conciliaciones_validacion_origen');
    }
};
