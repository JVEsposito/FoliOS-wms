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
        Schema::create('productos_hidrocooler', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 150)->unique();
            $table->string('unidad_dosis', 30);
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('eventos_producto_hidrocooler', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('producto_hidrocooler_id')->constrained('productos_hidrocooler')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->timestamps();
        });
        Schema::table('procesos_hidrocooler_materia_prima', function (Blueprint $table): void {
            $table->decimal('temperatura_ambiente_c', 6, 2)->nullable();
            $table->decimal('humedad_relativa_pct', 5, 2)->nullable();
            $table->decimal('pozo_accutab_mv', 9, 2)->nullable();
            $table->boolean('recarga_pastilla')->nullable();
            $table->decimal('correccion_cloro_ppm', 7, 2)->nullable();
            $table->boolean('aplicacion_producto')->nullable();
            $table->foreignUuid('producto_hidrocooler_id')->nullable()
                ->constrained('productos_hidrocooler', indexName: 'hidro_mp_producto_fk')->restrictOnDelete();
            $table->string('producto_nombre_snapshot', 150)->nullable();
            $table->decimal('producto_dosis', 12, 4)->nullable();
            $table->string('producto_unidad_dosis', 30)->nullable();
            $table->json('formato_registro_snapshot')->nullable();
            $table->decimal('cloro_min_ppm_snapshot', 7, 2)->nullable();
            $table->decimal('cloro_max_ppm_snapshot', 7, 2)->nullable();
        });
        DB::table('formatos_registro')->insert([
            'id' => (string) Str::uuid(), 'codigo' => 'POEMP-R3',
            'nombre' => 'Registro control hidrocooler', 'version' => '2',
            'fecha_vigencia' => '2026-08-31',
            'localidad' => 'Rengo, Carretera 5 Sur, km 108, Rosario, comuna de Rengo',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Conservar la auditoría: no retirar un formato que ya tenga modificaciones.
        $formato = DB::table('formatos_registro')->where('codigo', 'POEMP-R3')->first();
        if ($formato && ! DB::table('eventos_formato_registro')->where('formato_registro_id', $formato->id)->exists()) {
            DB::table('formatos_registro')->where('id', $formato->id)->delete();
        }
        Schema::table('procesos_hidrocooler_materia_prima', function (Blueprint $table): void {
            $table->dropForeign('hidro_mp_producto_fk');
            $table->dropColumn([
                'temperatura_ambiente_c', 'humedad_relativa_pct', 'pozo_accutab_mv', 'recarga_pastilla',
                'correccion_cloro_ppm', 'aplicacion_producto', 'producto_hidrocooler_id', 'producto_nombre_snapshot',
                'producto_dosis', 'producto_unidad_dosis', 'formato_registro_snapshot',
                'cloro_min_ppm_snapshot', 'cloro_max_ppm_snapshot',
            ]);
        });
        Schema::dropIfExists('eventos_producto_hidrocooler');
        Schema::dropIfExists('productos_hidrocooler');
    }
};
