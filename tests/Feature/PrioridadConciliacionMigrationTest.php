<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrioridadConciliacionMigrationTest extends TestCase
{
    public function test_normaliza_plan_maniobra_y_tarea_vivos_sin_reescribir_finalizados_y_es_idempotente(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['planes_operacionales', 'maniobras_operacionales', 'tareas_movimiento'] as $tabla) {
            Schema::create($tabla, function (Blueprint $table) use ($tabla): void {
                $table->string('id')->primary();
                if ($tabla === 'planes_operacionales') {
                    $table->string('referencia_tipo');
                    $table->string('estado');
                } else {
                    $table->string('plan_operacional_id');
                }
                $table->string('prioridad');
                $table->integer('version')->default(1);
            });
        }
        DB::table('planes_operacionales')->insert([
            ['id' => 'vivo', 'referencia_tipo' => 'folio_pendiente_ubicacion', 'estado' => 'programado', 'prioridad' => 'alta', 'version' => 1],
            ['id' => 'final', 'referencia_tipo' => 'folio_pendiente_ubicacion', 'estado' => 'completado', 'prioridad' => 'alta', 'version' => 1],
            ['id' => 'otro', 'referencia_tipo' => 'recepcion_tunel', 'estado' => 'programado', 'prioridad' => 'alta', 'version' => 1],
        ]);
        foreach (['maniobras_operacionales', 'tareas_movimiento'] as $tabla) {
            DB::table($tabla)->insert(array_map(fn ($id) => [
                'id' => $id, 'plan_operacional_id' => $id, 'prioridad' => 'alta', 'version' => 1,
            ], ['vivo', 'final', 'otro']));
        }

        $migracion = require database_path('migrations/2026_09_29_100000_normalizar_prioridad_conciliacion_historica.php');
        $migracion->up();
        $migracion->up();

        foreach (['planes_operacionales', 'maniobras_operacionales', 'tareas_movimiento'] as $tabla) {
            $this->assertSame('normal', DB::table($tabla)->where('id', 'vivo')->value('prioridad'));
            $this->assertSame(2, DB::table($tabla)->where('id', 'vivo')->value('version'));
            $this->assertSame('alta', DB::table($tabla)->where('id', 'final')->value('prioridad'));
            $this->assertSame('alta', DB::table($tabla)->where('id', 'otro')->value('prioridad'));
        }
    }
}
