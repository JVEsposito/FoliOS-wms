<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frigorifico_personas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nombre', 120);
            $table->string('identificador', 40)->nullable()->unique();
            $table->string('sector', 80);
            $table->string('funcion', 80)->nullable();
            $table->unsignedSmallInteger('jornada_base_minutos');
            $table->boolean('activo')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('frigorifico_jornadas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('persona_id')->constrained('frigorifico_personas');
            $table->dateTime('ingreso_at');
            $table->dateTime('salida_at')->nullable();
            $table->unsignedSmallInteger('jornada_base_minutos');
            $table->unsignedSmallInteger('colacion_minutos')->default(0);
            $table->string('sector', 80);
            $table->text('observacion')->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['persona_id', 'ingreso_at']);
            $table->index('ingreso_at');
        });
        Schema::create('frigorifico_control_personal_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('persona_id')->constrained('frigorifico_personas');
            $table->foreignUuid('jornada_id')->nullable()->constrained('frigorifico_jornadas');
            $table->foreignId('user_id')->constrained('users');
            $table->string('accion', 40);
            $table->string('motivo', 500)->nullable();
            $table->json('anterior')->nullable();
            $table->json('nuevo');
            $table->timestamp('created_at');
        });
        // Solo los perfiles iniciales de supervisión de frío y administración.
        DB::table('perfiles_acceso')->where('predeterminado', true)
            ->whereIn('rol_base', ['administrador', 'supervisor_frio'])->orderBy('id')
            ->each(function (object $perfil): void {
                $modulos = json_decode((string) $perfil->modulos, true) ?: [];
                if (! in_array('frigorifico.control-personal', $modulos, true)) {
                    DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                        'modulos' => json_encode([...$modulos, 'frigorifico.control-personal'], JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('frigorifico_control_personal_eventos');
        Schema::dropIfExists('frigorifico_jornadas');
        Schema::dropIfExists('frigorifico_personas');
    }
};
