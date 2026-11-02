<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $table) {
            $table->text('motivo_retorno')->nullable()->after('cierre_registrado_at');
            $table->foreignId('retornada_por_user_id')->nullable()->after('motivo_retorno')->constrained('users')->restrictOnDelete();
            $table->timestamp('retornada_at')->nullable()->after('retornada_por_user_id');
        });

        Schema::table('carga_folios', function (Blueprint $table) {
            $table->foreignId('retornado_por_user_id')->nullable()->after('motivo_finalizacion')->constrained('users')->restrictOnDelete();
            $table->timestamp('retornado_at')->nullable()->after('retornado_por_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('carga_folios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retornado_por_user_id');
            $table->dropColumn('retornado_at');
        });
        Schema::table('cargas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retornada_por_user_id');
            $table->dropColumn(['motivo_retorno', 'retornada_at']);
        });
    }
};
