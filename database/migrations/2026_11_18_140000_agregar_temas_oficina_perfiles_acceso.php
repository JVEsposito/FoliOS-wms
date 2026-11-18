<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $tabla): void {
            $tabla->json('temas_oficina')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('perfiles_acceso', function (Blueprint $tabla): void {
            $tabla->dropColumn('temas_oficina');
        });
    }
};
