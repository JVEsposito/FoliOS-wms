<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['conductores_transporte', 'empresas_transporte', 'vehiculos_transporte'] as $tabla) {
            Schema::create($tabla, function (Blueprint $table) use ($tabla): void {
                $table->uuid('id')->primary();
                if ($tabla === 'vehiculos_transporte') {
                    $table->string('patente', 12)->unique();
                    $table->string('descripcion', 150)->nullable();
                } else {
                    $table->string('rut', 10)->unique();
                    $table->string('nombre', 150);
                    $table->string('telefono', 40)->nullable();
                }
                $table->unsignedInteger('version')->default(1);
                $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::create('eventos_directorio_transporte', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tipo', 20);
            $table->uuid('registro_id');
            $table->index(['tipo', 'registro_id']);
            $table->uuid('operacion_id');
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario', 255);
            $table->timestamps();
        });

        Schema::table('recepciones_materiales', function (Blueprint $table): void {
            $table->foreignUuid('conductor_transporte_id')->nullable()->constrained('conductores_transporte')->restrictOnDelete();
            $table->foreignUuid('empresa_transporte_id')->nullable()->constrained('empresas_transporte')->restrictOnDelete();
            $table->foreignUuid('vehiculo_transporte_id')->nullable()->constrained('vehiculos_transporte')->restrictOnDelete();
            $table->json('transporte_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_materiales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('conductor_transporte_id');
            $table->dropConstrainedForeignId('empresa_transporte_id');
            $table->dropConstrainedForeignId('vehiculo_transporte_id');
            $table->dropColumn('transporte_snapshot');
        });
        Schema::dropIfExists('eventos_directorio_transporte');
        Schema::dropIfExists('vehiculos_transporte');
        Schema::dropIfExists('empresas_transporte');
        Schema::dropIfExists('conductores_transporte');
    }
};
