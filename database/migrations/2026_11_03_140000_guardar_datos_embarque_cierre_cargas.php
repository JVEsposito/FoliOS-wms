<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $table): void {
            $table->json('datos_embarque_cierre')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cargas', function (Blueprint $table): void {
            $table->dropColumn('datos_embarque_cierre');
        });
    }
};
