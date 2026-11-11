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
        // Bitácora del módulo de administración: cada cambio guarda el antes, el después y el
        // motivo. No reemplaza los registros de cada dominio (corrección de validación,
        // habilitación de almacenamiento, retenciones): los complementa.
        Schema::create('cambios_atributos_pt', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('folio_id')->constrained('folios')->restrictOnDelete();
            $table->string('numero_folio', 50);
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->string('tipo', 40);
            $table->json('antes');
            $table->json('despues');
            $table->text('motivo');
            $table->uuid('carga_id')->nullable();
            $table->string('carga_codigo', 50)->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['folio_id', 'created_at']);
        });

        // Un cambio aceptado por administración se da por cuadrado frente a planta mientras lo
        // validado siga siendo exactamente lo aceptado.
        Schema::table('conciliaciones_validacion_origen', function (Blueprint $table) {
            $table->char('aceptado_datos_hash', 64)->nullable();
            $table->timestamp('aceptado_at')->nullable();
            $table->foreignId('aceptado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('perfiles_acceso')->where('predeterminado', true)->where('rol_base', 'administrador')->get()
            ->each(function ($perfil): void {
                $modulos = json_decode($perfil->modulos, true) ?: [];
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos' => json_encode(array_values(array_unique([...$modulos, CatalogoModulosAcceso::OFICINA_ATRIBUTOS_PT]))),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('conciliaciones_validacion_origen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aceptado_por_user_id');
            $table->dropColumn(['aceptado_datos_hash', 'aceptado_at']);
        });
        Schema::dropIfExists('cambios_atributos_pt');
    }
};
