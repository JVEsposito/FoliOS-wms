<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion_taras_romana', function (Blueprint $table): void {
            $table->string('tipo_envase', 40)->primary();
            $table->decimal('tara_unitaria', 8, 3);
            $table->uuid('actualizado_por_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('historial_configuracion_taras_romana', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tipo_envase', 40);
            $table->decimal('tara_anterior', 8, 3);
            $table->decimal('tara_nueva', 8, 3);
            $table->string('motivo', 500);
            $table->uuid('modificado_por_user_id');
            $table->timestamp('created_at');
            $table->index(['tipo_envase', 'created_at'], 'idx_historial_taras_romana');
        });

        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->json('taras_romana_snapshot')->nullable();
        });

        $ahora = now();
        DB::table('configuracion_taras_romana')->insert([
            ['tipo_envase' => 'bins', 'tara_unitaria' => 43.000, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['tipo_envase' => 'totes', 'tara_unitaria' => 0.890, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->dropColumn('taras_romana_snapshot');
        });
        Schema::dropIfExists('historial_configuracion_taras_romana');
        Schema::dropIfExists('configuracion_taras_romana');
    }
};
