<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->string('modo_salida_envases', 24)->nullable()->after('salida_sin_envases');
            $table->string('numero_guia_salida', 80)->nullable()->after('modo_salida_envases');
        });

        Schema::create('salidas_envases_recepcion_romana', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recepcion_romana_id')->constrained('recepciones_romana')->restrictOnDelete();
            $table->string('tipo_envase', 20);
            $table->unsignedInteger('cantidad');
            $table->decimal('tara_unitaria', 8, 3);
            $table->foreignUuid('movimiento_envase_id')->nullable()->constrained('movimientos_envases')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['recepcion_romana_id', 'tipo_envase'], 'salida_recepcion_tipo_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salidas_envases_recepcion_romana');
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->dropColumn(['modo_salida_envases', 'numero_guia_salida']);
        });
    }
};
