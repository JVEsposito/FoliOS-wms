<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('despachos_comerciales_retorno', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->string('numero', 30)->unique();
            $table->string('destinatario', 180);
            $table->string('estado', 20)->index();
            $table->string('numero_guia_sii', 40)->nullable();
            $table->text('observacion')->nullable();
            $table->foreignId('creado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmado_at')->nullable();
            $table->timestamp('cancelado_at')->nullable();
            $table->timestamps();
            $table->unique(['temporada_id', 'numero_guia_sii'], 'desp_comercial_guia_temporada_unique');
            $table->index(['temporada_id', 'created_at'], 'desp_comercial_temporada_fecha_idx');
        });

        Schema::create('despachos_comerciales_retorno_bins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('despacho_comercial_id');
            $table->foreign('despacho_comercial_id', 'dcr_detalle_despacho_fk')
                ->references('id')->on('despachos_comerciales_retorno')->restrictOnDelete();
            $table->foreignUuid('bin_retorno_packing_id');
            $table->foreign('bin_retorno_packing_id', 'dcr_detalle_bin_fk')
                ->references('id')->on('bins_retorno_packing')->restrictOnDelete();
            $table->string('folio_definitivo', 80);
            $table->string('clasificacion', 100);
            $table->decimal('kilos_definitivos', 12, 3);
            $table->timestamps();
            $table->unique(['despacho_comercial_id', 'bin_retorno_packing_id'], 'desp_comercial_bin_unique');
        });

        Schema::table('bins_retorno_packing', function (Blueprint $table): void {
            $table->foreignUuid('despacho_comercial_id')->nullable()->constrained('despachos_comerciales_retorno')->restrictOnDelete();
        });

        DB::table('secuencias_documentos')->updateOrInsert(
            ['clave' => 'despachos_comerciales_retorno'],
            ['ultimo_numero' => 0],
        );

        foreach (DB::table('perfiles_acceso')->where('predeterminado', true)->get() as $perfil) {
            if (! in_array($perfil->rol_base, ['administrador', 'supervisor_frio', 'digitador_materia_prima', 'consulta'], true)) {
                continue;
            }
            $modulos = json_decode($perfil->modulos, true) ?: [];
            if (! in_array('materia-prima.despacho-comercial', $modulos, true)) {
                $modulos[] = 'materia-prima.despacho-comercial';
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update(['modulos' => json_encode($modulos)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('bins_retorno_packing', fn (Blueprint $table) => $table->dropConstrainedForeignId('despacho_comercial_id'));
        Schema::dropIfExists('despachos_comerciales_retorno_bins');
        Schema::dropIfExists('despachos_comerciales_retorno');
        DB::table('secuencias_documentos')->where('clave', 'despachos_comerciales_retorno')->delete();
        foreach (DB::table('perfiles_acceso')->where('predeterminado', true)->get() as $perfil) {
            $modulos = array_values(array_diff(json_decode($perfil->modulos, true) ?: [], ['materia-prima.despacho-comercial']));
            DB::table('perfiles_acceso')->where('id', $perfil->id)->update(['modulos' => json_encode($modulos)]);
        }
    }
};
