<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Asistente de Oficina (docs/ASISTENTE_OFICINA.md): registro de preguntas, rangos de
 * neto por envase para la revisión de taras y una fila de configuración.
 *
 * Los rangos se guardan por nombre de especie y no por especie de temporada, para que
 * sobrevivan al cambio de temporada. Especie nula significa «todas las especies».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asistente_preguntas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('texto', 500);
            $table->string('interprete', 20);
            $table->string('consulta', 60)->nullable()->index();
            $table->json('parametros')->nullable();
            $table->string('estado', 20)->index();
            $table->decimal('confianza', 4, 3)->nullable();
            $table->dateTime('created_at')->index();
        });

        Schema::create('asistente_rangos_tara', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tipo_envase', 30);
            $table->string('especie', 100)->nullable();
            $table->decimal('minimo_kg', 6, 3);
            $table->decimal('maximo_kg', 6, 3);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['tipo_envase', 'especie'], 'asistente_rangos_tara_envase_especie_unique');
        });

        Schema::create('asistente_configuracion', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->decimal('margen_desviaciones', 4, 2)->default(2);
            $table->string('modo', 20)->default('sin_ia');
            $table->timestamps();
        });

        $ahora = now();
        DB::table('asistente_rangos_tara')->insert([
            'id' => (string) Str::uuid(),
            'tipo_envase' => 'totes',
            'especie' => null,
            'minimo_kg' => 8.2,
            'maximo_kg' => 9.5,
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('asistente_configuracion')->insert([
            'id' => (string) Str::uuid(),
            'margen_desviaciones' => 2,
            'modo' => 'sin_ia',
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('asistente_configuracion');
        Schema::dropIfExists('asistente_rangos_tara');
        Schema::dropIfExists('asistente_preguntas');
    }
};
