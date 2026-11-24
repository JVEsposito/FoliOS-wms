<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El catálogo de Validación deja de bloquear la fila de la temporada.
 *
 * Casi todas las tablas operativas tienen una FK a temporadas, y cada inserción toma un
 * bloqueo compartido de esa fila. Mientras el catálogo recalculaba combinaciones con la
 * temporada bloqueada, Romana, lotes y el resto de la planta esperaban con él.
 *
 * - Las escrituras del catálogo se ordenan entre sí con una fila propia por temporada.
 * - La proyección (artículos, orígenes y combinaciones) es derivada del catálogo y se
 *   escribe por cientos de miles de filas: sin FK a temporadas, escribirla no bloquea la
 *   temporada. Su índice por temporada se conserva.
 */
return new class extends Migration
{
    private const PROYECCION = ['articulos_validacion', 'origenes_validacion', 'combinaciones_validacion'];

    public function up(): void
    {
        Schema::create('bloqueos_catalogo_validacion', function (Blueprint $table): void {
            // Sin FK: crearla no debe bloquear la temporada que protege.
            $table->uuid('temporada_id')->primary();
            $table->timestamps();
        });

        $ahora = now();
        DB::table('bloqueos_catalogo_validacion')->insert(DB::table('temporadas')->pluck('id')
            ->map(fn (string $id): array => ['temporada_id' => $id, 'created_at' => $ahora, 'updated_at' => $ahora])->all());

        foreach (self::PROYECCION as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropForeign(['temporada_id']));
        }
    }

    public function down(): void
    {
        foreach (self::PROYECCION as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->foreign('temporada_id')->references('id')->on('temporadas')->restrictOnDelete());
        }
        Schema::dropIfExists('bloqueos_catalogo_validacion');
    }
};
