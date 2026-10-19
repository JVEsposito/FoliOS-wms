<?php

use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingresos_porteria', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('cliente_nombre', 180);
            $table->boolean('cliente_es_otro')->default(false);
            $table->string('motivo', 20);
            $table->string('patente', 12);
            $table->string('patente_carro', 12)->nullable();
            $table->string('tipo_camion', 80);
            $table->string('nombre_conductor', 180);
            // Una llegada admite varias guías; cada guía puede volver en otros camiones.
            $table->json('guias');
            $table->unsignedInteger('cantidad')->nullable();
            $table->string('unidad', 10)->nullable();
            $table->string('observacion', 500)->nullable();
            $table->dateTime('ingreso_at');
            $table->foreignId('creado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('creado_por_nombre');
            $table->dateTime('revisado_at')->nullable();
            $table->foreignId('revisado_por_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revisado_por_nombre')->nullable();
            $table->dateTime('anulado_at')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['ingreso_at', 'id']);
            $table->index(['anulado_at', 'revisado_at', 'id'], 'porteria_pendientes');
            $table->index(['cliente_id', 'ingreso_at']);
        });
        Schema::create('eventos_ingreso_porteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingreso_porteria_id')->constrained('ingresos_porteria');
            $table->string('accion', 20);
            $table->json('datos');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_nombre');
            $table->dateTime('created_at');
        });
        DB::table('perfiles_acceso')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'codigo' => 'GUARDIA_PORTERIA',
            'nombre' => 'Guardia de portería', 'descripcion' => 'Registro de llegadas de camiones desde celular o PDA.',
            'rol_base' => 'guardia_porteria', 'modulos' => json_encode([CatalogoModulosAcceso::OFICINA_PORTERIA]),
            'modulos_tablet' => '[]', 'pantalla_inicio' => '/oficina/porteria',
            'activo' => true, 'predeterminado' => true, 'protegido' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('perfiles_acceso')->where('predeterminado', true)->where('rol_base', 'administrador')->orderBy('id')->each(function (object $perfil): void {
            $modulos = json_decode($perfil->modulos, true) ?: [];
            DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                'modulos' => json_encode(array_values(array_unique([...$modulos, CatalogoModulosAcceso::OFICINA_PORTERIA]))),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_ingreso_porteria');
        Schema::dropIfExists('ingresos_porteria');
        // Conserva perfiles y asignaciones posteriores del administrador.
    }
};
