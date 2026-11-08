<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Días sin jornada del control interno del supervisor. No se comparte con RRHH.
        Schema::create('frigorifico_ausencias', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('persona_id')->constrained('frigorifico_personas');
            $table->date('desde');
            $table->date('hasta');
            $table->string('tipo', 20);
            $table->text('observacion')->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['persona_id', 'desde']);
            $table->index(['desde', 'hasta']);
        });
        Schema::table('frigorifico_control_personal_eventos', function (Blueprint $table): void {
            $table->foreignUuid('ausencia_id')->nullable()->after('jornada_id')->constrained('frigorifico_ausencias');
        });
    }

    public function down(): void
    {
        Schema::table('frigorifico_control_personal_eventos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ausencia_id');
        });
        Schema::dropIfExists('frigorifico_ausencias');
    }
};
