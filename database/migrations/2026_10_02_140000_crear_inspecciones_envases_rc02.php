<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspecciones_envases_recepcion', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('recepcion_romana_id')->constrained('recepciones_romana')->restrictOnDelete();
            $t->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $t->string('tipo', 15);
            foreach (['coincide_especie_variedad', 'coincide_cantidad_bins', 'bins_bien_etiquetados'] as $campo) {
                $t->boolean($campo)->nullable();
            }
            $t->text('observacion')->nullable();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('inspeccionada_at');
            $t->unsignedInteger('version')->default(1);
            $t->json('formato');
            $t->timestamps();
            $t->unique(['recepcion_romana_id', 'tipo'], 'inspeccion_envases_recepcion_tipo_unica');
        });
        Schema::create('items_inspeccion_envases', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('inspeccion_envases_id')->constrained('inspecciones_envases_recepcion')->restrictOnDelete();
            $t->string('tipo_envase', 30);
            $t->unsignedInteger('cantidad');
            $t->boolean('limpieza');
            $t->string('condicion', 10);
            $t->string('nota', 500)->nullable();
            $t->timestamps();
            $t->unique(['inspeccion_envases_id', 'tipo_envase'], 'item_inspeccion_envase_unico');
        });
        Schema::create('eventos_inspeccion_envases', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('inspeccion_envases_id')->constrained('inspecciones_envases_recepcion')->restrictOnDelete();
            $t->uuid('operacion_id')->unique();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('motivo', 2000)->nullable();
            $t->json('antes')->nullable();
            $t->json('despues');
            $t->string('payload_hash', 64);
            $t->timestamps();
        });
        DB::table('formatos_registro')->insert(['id' => (string) Str::uuid(), 'codigo' => 'RC-02', 'nombre' => 'Control de envases', 'version' => '1', 'fecha_vigencia' => '2025-09-15', 'localidad' => 'Rengo, Carretera 5 Sur, km 108, Rosario, comuna de Rengo', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_inspeccion_envases');
        Schema::dropIfExists('items_inspeccion_envases');
        Schema::dropIfExists('inspecciones_envases_recepcion');
        DB::table('formatos_registro')->where('codigo', 'RC-02')->delete();
    }
};
