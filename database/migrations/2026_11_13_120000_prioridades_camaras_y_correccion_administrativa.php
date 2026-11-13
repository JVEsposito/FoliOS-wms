<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camaras', function (Blueprint $table): void {
            $table->string('prioridad_operacional', 30)->default('general');
            $table->unsignedInteger('version_prioridad')->default(1);
        });
        // Una corrección de Oficina no inventa una tablet ni una sesión de estiba.
        foreach (['operaciones_sincronizacion', 'movimientos'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $table) => $table->uuid('dispositivo_id')->nullable()->change());
        }
    }

    public function down(): void
    {
        Schema::table('camaras', fn (Blueprint $table) => $table->dropColumn(['prioridad_operacional', 'version_prioridad']));
        // Se conserva nullable: las correcciones auditadas existentes no se eliminan al retroceder.
    }
};
