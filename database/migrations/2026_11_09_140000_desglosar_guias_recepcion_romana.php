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
        Schema::create('guias_recepcion_romana', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recepcion_romana_id')->constrained('recepciones_romana')->restrictOnDelete();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->unsignedSmallInteger('secuencia')->default(1);
            $table->string('numero_guia_despacho', 80);
            $table->json('envases');
            $table->json('envases_validados')->nullable();
            $table->unsignedTinyInteger('vigente')->nullable()->default(1);
            $table->timestamps(6);
            $table->unique(['temporada_id', 'cliente_id', 'numero_guia_despacho', 'vigente'], 'guia_romana_documento_vigente_unique');
            $table->index(['recepcion_romana_id', 'vigente'], 'guia_romana_recepcion_vigente');
        });
        Schema::table('segmentos_validacion_mp', function (Blueprint $table): void {
            $table->foreignUuid('guia_recepcion_romana_id')->nullable()->constrained('guias_recepcion_romana')->restrictOnDelete();
        });
        // Los textos históricos se conservan literalmente: no se adivinan separadores.
        DB::table('recepciones_romana')->orderBy('id')->chunkById(250, function ($recepciones): void {
            foreach ($recepciones as $recepcion) {
                $detalles = DB::table('detalles_envases_recepcion_romana')->where('recepcion_romana_id', $recepcion->id)->orderBy('tipo_envase')->get();
                $id = (string) Str::uuid();
                DB::table('guias_recepcion_romana')->insert([
                    'id' => $id, 'recepcion_romana_id' => $recepcion->id, 'temporada_id' => $recepcion->temporada_id,
                    'cliente_id' => $recepcion->cliente_id, 'numero_guia_despacho' => $recepcion->numero_guia_despacho,
                    'secuencia' => 1, 'vigente' => $recepcion->eliminada_at === null ? 1 : null,
                    'envases' => json_encode($detalles->map(fn ($e) => ['tipo_envase' => $e->tipo_envase, 'cantidad' => (int) $e->cantidad_declarada])->all(), JSON_THROW_ON_ERROR),
                    'envases_validados' => $detalles->isNotEmpty() && $detalles->every(fn ($e) => $e->cantidad_validada !== null)
                        ? json_encode($detalles->map(fn ($e) => ['tipo_envase' => $e->tipo_envase, 'cantidad' => (int) $e->cantidad_validada])->all(), JSON_THROW_ON_ERROR) : null,
                    'created_at' => $recepcion->created_at, 'updated_at' => $recepcion->updated_at,
                ]);
                DB::table('segmentos_validacion_mp')->whereIn('validacion_mp_id', DB::table('validaciones_mp')->select('id')->where('recepcion_romana_id', $recepcion->id))->update(['guia_recepcion_romana_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('segmentos_validacion_mp', fn (Blueprint $table) => $table->dropConstrainedForeignId('guia_recepcion_romana_id'));
        Schema::dropIfExists('guias_recepcion_romana');
    }
};
