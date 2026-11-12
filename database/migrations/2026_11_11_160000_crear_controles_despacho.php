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
        // POEMP R-24 «Planilla control de despacho área frío»: una por carga. Lo que FoliOS ya
        // sabe se sugiere al leerla; aquí se guarda lo que el equipo de consolidación confirma.
        Schema::create('controles_despacho', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('carga_id')->unique()->constrained('cargas')->restrictOnDelete();
            $table->string('estado', 20)->default('borrador');
            $table->unsignedInteger('version')->default(1);
            $table->json('encabezado')->nullable();
            $table->json('inspeccion')->nullable();
            $table->json('cierre')->nullable();
            // Al cerrar se congela la planilla completa, sugerencias incluidas.
            $table->json('planilla_cerrada')->nullable();
            $table->uuid('operacion_inicio_id')->unique();
            $table->foreignId('iniciado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('iniciado_desde_dispositivo_id')->nullable()->constrained('dispositivos')->nullOnDelete();
            $table->timestamp('iniciado_at');
            $table->foreignId('actualizado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cerrado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cerrado_at')->nullable();
            $table->json('reaperturas')->nullable();
            // Al cerrar, las fotos se archivan en un único PDF y se borran sus archivos sueltos.
            $table->string('fotos_pdf_ruta')->nullable();
            $table->unsignedBigInteger('fotos_pdf_bytes')->nullable();
            $table->char('fotos_pdf_sha256', 64)->nullable();
            $table->unsignedSmallInteger('fotos_pdf_cantidad')->nullable();
            $table->timestamp('fotos_pdf_generado_at')->nullable();
            $table->timestamps();
            $table->index(['temporada_id', 'estado', 'iniciado_at']);
        });

        Schema::create('posiciones_control_despacho', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_despacho_id')->constrained('controles_despacho')->restrictOnDelete();
            $table->foreignUuid('carga_folio_id')->constrained('carga_folios')->restrictOnDelete();
            $table->foreignUuid('folio_id')->constrained('folios')->restrictOnDelete();
            $table->unsignedSmallInteger('posicion');
            $table->string('codigo_pe', 10)->nullable();
            $table->unsignedInteger('cajas')->nullable();
            $table->decimal('temperatura', 4, 1)->nullable();
            $table->foreignId('registrado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('registrado_desde_dispositivo_id')->nullable()
                ->constrained('dispositivos', 'id', 'posiciones_control_despacho_dispositivo_foreign')->nullOnDelete();
            $table->timestamp('registrado_at');
            $table->timestamps();
            $table->unique(['control_despacho_id', 'carga_folio_id'], 'posiciones_control_asignacion_unica');
            $table->index(['control_despacho_id', 'posicion']);
        });

        Schema::create('fotos_control_despacho', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_despacho_id')->constrained('controles_despacho')->restrictOnDelete();
            $table->string('tipo', 40);
            $table->unsignedSmallInteger('orden');
            // JPEG comprimido al subir; null una vez archivada en el PDF del set fotográfico.
            $table->string('ruta')->nullable();
            $table->string('ruta_miniatura')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->unsignedBigInteger('bytes_recibidos');
            $table->unsignedInteger('ancho');
            $table->unsignedInteger('alto');
            // Huella del archivo recibido: el reintento con el mismo operacion_id se reconoce.
            $table->char('sha256', 64);
            $table->timestamp('archivada_at')->nullable();
            $table->unsignedSmallInteger('pdf_pagina')->nullable();
            // Ubicación del JPEG dentro del PDF: permite rearmarlo si la planilla se reabre.
            $table->unsignedBigInteger('pdf_offset')->nullable();
            $table->unsignedInteger('pdf_longitud')->nullable();
            $table->uuid('operacion_id')->unique();
            $table->foreignId('subida_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes('eliminada_at');
            $table->foreignId('eliminada_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['control_despacho_id', 'tipo', 'eliminada_at'], 'fotos_control_despacho_tipo_activas');
        });

        DB::table('formatos_registro')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'codigo' => 'POEMP R-24', 'nombre' => 'Registro planilla control de despacho área frío',
            'version' => '1', 'fecha_vigencia' => '2025-10-01', 'localidad' => 'Rengo, Carretera 5 Sur, km 108, Rosario',
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('perfiles_acceso')->where('predeterminado', true)->whereIn('rol_base', ['administrador', 'supervisor_frio'])->get()
            ->each(function ($perfil): void {
                $modulos = json_decode($perfil->modulos, true) ?: [];
                $tablet = json_decode($perfil->modulos_tablet, true) ?: [];
                DB::table('perfiles_acceso')->where('id', $perfil->id)->update([
                    'modulos' => json_encode(array_values(array_unique([...$modulos, CatalogoModulosAcceso::OFICINA_CONTROL_DESPACHO]))),
                    'modulos_tablet' => json_encode(array_values(array_unique([...$tablet, CatalogoModulosAcceso::TABLET_CONSOLIDACION]))),
                    'updated_at' => now(),
                ]);
            });

        DB::table('perfiles_acceso')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'codigo' => 'CONSOLIDACION', 'nombre' => 'Consolidación de despacho',
            'descripcion' => 'Equipo de consolidación: registra la planilla R-24 desde la PDA cuando la carga está en andén.',
            'rol_base' => 'despachador',
            'modulos' => json_encode([CatalogoModulosAcceso::OFICINA_CONTROL_DESPACHO]),
            'modulos_tablet' => json_encode([CatalogoModulosAcceso::TABLET_CONSOLIDACION]),
            'pantalla_inicio' => '/oficina/frigorifico/control-despacho',
            'activo' => true, 'predeterminado' => false, 'protegido' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Conserva el perfil y los permisos que administración haya asignado o personalizado.
        Schema::dropIfExists('fotos_control_despacho');
        Schema::dropIfExists('posiciones_control_despacho');
        Schema::dropIfExists('controles_despacho');
    }
};
