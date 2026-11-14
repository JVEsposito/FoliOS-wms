<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendarios_operativos', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->date('vigente_desde')->nullable();
            $tabla->unsignedInteger('version');
            $tabla->string('inicio_jornada', 5);
            $tabla->string('inicio_turno_b', 5);
            $tabla->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->timestamp('created_at');
            $tabla->unique(['vigente_desde', 'version']);
        });

        DB::table('calendarios_operativos')->insert([
            'version' => 1, 'inicio_jornada' => '07:00', 'inicio_turno_b' => '19:00', 'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('calendarios_operativos');
    }
};
