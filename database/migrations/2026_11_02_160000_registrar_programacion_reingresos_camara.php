<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reingresos_folio_camara', function (Blueprint $table): void {
            $table->string('programacion_error', 500)->nullable();
            $table->timestamp('programacion_intentada_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reingresos_folio_camara', function (Blueprint $table): void {
            $table->dropColumn(['programacion_error', 'programacion_intentada_at']);
        });
    }
};
