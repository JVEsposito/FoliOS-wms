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
        Schema::create('lineas_proceso', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 10)->unique('linea_proceso_codigo_unique');
            $table->string('nombre', 60);
            $table->decimal('rendimiento_kg_hora', 10, 2)->default(6000);
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('actualizado_por_user_id')->nullable()
                ->constrained('users', indexName: 'linea_proceso_actualizado_por_fk')
                ->restrictOnDelete();
            $table->timestamps(precision: 6);
        });

        // La planta opera cinco líneas. El rendimiento de 6.000 kg/h es el que usa hoy la PRE-SIF.
        $ahora = now();
        DB::table('lineas_proceso')->insert(array_map(
            fn (int $numero): array => [
                'id' => (string) Str::uuid(),
                'codigo' => "L{$numero}",
                'nombre' => "Línea {$numero}",
                'rendimiento_kg_hora' => 6000,
                'activa' => true,
                'orden' => $numero * 10,
                'version' => 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            range(1, 5),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('lineas_proceso');
    }
};
