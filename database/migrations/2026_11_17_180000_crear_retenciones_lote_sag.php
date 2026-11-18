<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retenciones_lote_sag', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas');
            $table->foreignUuid('cliente_id')->constrained('clientes');
            $table->string('numero_lote', 100);
            $table->foreignUuid('lote_materia_prima_id')->nullable()->constrained('lotes_materia_prima');
            $table->string('estado', 30);
            $table->date('fecha_deteccion');
            $table->text('observacion');
            $table->string('referencia_informe', 250)->nullable();
            $table->boolean('todos_destinos')->default(false);
            $table->json('destinos');
            $table->text('resolucion')->nullable();
            $table->boolean('definitivo')->default(false);
            $table->text('instruccion_segregacion')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creado_por_user_id')->constrained('users');
            $table->foreignId('actualizado_por_user_id')->constrained('users');
            $table->timestamps();
            $table->unique(['temporada_id', 'cliente_id', 'numero_lote'], 'retencion_sag_lote_unico');
            $table->index(['temporada_id', 'estado']);
        });
        Schema::create('eventos_retencion_lote_sag', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('retencion_id')->constrained('retenciones_lote_sag');
            $table->uuid('operacion_id')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('user_id')->constrained('users');
            $table->string('usuario_nombre');
            $table->unsignedInteger('version');
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->json('folios_afectados');
            $table->timestamp('created_at');
            $table->unique(['retencion_id', 'version']);
        });
        // El módulo nuevo se concede a contrapartes y administradores. No amplía el perfil supervisor.
        DB::table('perfiles_acceso')->where('codigo', 'CONTRAPARTE_SAG')
            ->orWhere(fn ($q) => $q->where('rol_base', 'administrador')->where('predeterminado', true))
            ->get()->each(function ($perfil): void {
                $modulos = json_decode($perfil->modulos, true) ?: [];
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos' => json_encode(array_values(array_unique([...$modulos, CatalogoModulosAcceso::OFICINA_LOTES_RETENIDOS_SAG]))),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_retencion_lote_sag');
        Schema::dropIfExists('retenciones_lote_sag');
    }
};
