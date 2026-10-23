<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_materiales', function (Blueprint $table): void {
            $table->string('numero_guia_despacho', 50)->nullable()->change();
            $table->dateTime('fecha_recepcion')->nullable();
            $table->boolean('provisional')->default(false);
            $table->boolean('registro_retroactivo')->default(false);
            $table->text('motivo_registro_tardio')->nullable();
            $table->dateTime('fecha_limite_regularizacion')->nullable();
            $table->foreignId('autorizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('autorizado_at')->nullable();
            $table->text('motivo_autorizacion')->nullable();
            $table->foreignId('regularizado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('regularizado_at')->nullable();
            $table->text('motivo_regularizacion')->nullable();
            $table->index(['temporada_id', 'provisional', 'regularizado_at', 'fecha_limite_regularizacion'], 'recepciones_materiales_regularizacion');
        });
        Schema::table('detalles_recepciones_materiales', fn (Blueprint $table) => $table->decimal('cantidad_documental', 14, 3)->nullable()->change());
        Schema::table('eliminaciones_recepciones_materiales', fn (Blueprint $table) => $table->string('numero_guia_despacho', 50)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('recepciones_materiales', function (Blueprint $table): void {
            $table->dropIndex('recepciones_materiales_regularizacion');
            $table->dropConstrainedForeignId('autorizado_por_user_id');
            $table->dropConstrainedForeignId('regularizado_por_user_id');
            $table->dropColumn(['fecha_recepcion', 'provisional', 'registro_retroactivo', 'motivo_registro_tardio', 'fecha_limite_regularizacion', 'autorizado_at', 'motivo_autorizacion', 'regularizado_at', 'motivo_regularizacion']);
        });
        // Los documentos desconocidos siguen siendo NULL: nunca fabricar una guía o cantidad al revertir.
    }
};
