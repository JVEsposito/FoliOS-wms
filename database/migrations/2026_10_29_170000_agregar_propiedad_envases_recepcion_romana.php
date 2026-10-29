<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detalles_envases_recepcion_romana', function (Blueprint $table): void {
            $table->string('propiedad', 20)->default('cliente');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_envases_recepcion_romana', function (Blueprint $table): void {
            $table->dropColumn('propiedad');
        });
    }
};
