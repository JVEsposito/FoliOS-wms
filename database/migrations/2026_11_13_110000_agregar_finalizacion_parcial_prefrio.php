<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procesos_prefrio_folios', function (Blueprint $table): void {
            $table->dateTime('finalizado_parcial_at')->nullable()->after('cargado_at');
        });
    }

    public function down(): void
    {
        Schema::table('procesos_prefrio_folios', function (Blueprint $table): void {
            $table->dropColumn('finalizado_parcial_at');
        });
    }
};
