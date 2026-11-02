<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carga_folios', function (Blueprint $table) {
            $table->foreignId('cargado_por_user_id')
                ->nullable()
                ->after('enviado_anden_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignUuid('cargado_desde_dispositivo_id')
                ->nullable()
                ->after('cargado_por_user_id')
                ->constrained('dispositivos')
                ->restrictOnDelete();
            $table->timestamp('cargado_at')->nullable()->after('cargado_desde_dispositivo_id');
        });
    }

    public function down(): void
    {
        Schema::table('carga_folios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cargado_desde_dispositivo_id');
            $table->dropConstrainedForeignId('cargado_por_user_id');
            $table->dropColumn('cargado_at');
        });
    }
};
