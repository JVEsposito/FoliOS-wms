<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesos_referencia_envases', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('especie_validacion_id')->constrained('especies_validacion')->restrictOnDelete();
            $table->string('tipo_envase', 30);
            $table->decimal('peso_referencia', 10, 3);
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['especie_validacion_id', 'tipo_envase'], 'referencia_especie_envase_unico');
        });
        Schema::create('preferencias_envase_especie', function (Blueprint $table): void {
            $table->foreignUuid('especie_validacion_id')->primary()->constrained('especies_validacion')->restrictOnDelete();
            $table->string('tipo_envase', 30)->nullable();
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('recepciones_romana', fn (Blueprint $table) => $table->json('reparto_neto_envases')->nullable());
        foreach (DB::table('especies_validacion')->whereIn(DB::raw('LOWER(nombre)'), ['cereza', 'cerezas'])->pluck('id') as $id) {
            foreach (['bins' => 210, 'totes' => 8.75] as $tipo => $peso) {
                DB::table('pesos_referencia_envases')->insert(['especie_validacion_id' => $id, 'tipo_envase' => $tipo,
                    'peso_referencia' => $peso, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('preferencias_envase_especie')->insert(['especie_validacion_id' => $id, 'tipo_envase' => 'totes', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('recepciones_romana', fn (Blueprint $table) => $table->dropColumn('reparto_neto_envases'));
        Schema::dropIfExists('preferencias_envase_especie');
        Schema::dropIfExists('pesos_referencia_envases');
    }
};
