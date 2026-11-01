<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_proceso_mp', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique('orden_pmp_operacion_unique');
            $table->char('payload_hash', 64);
            $table->uuid('temporada_id');
            $table->unsignedInteger('correlativo');
            $table->string('codigo', 20)->unique('orden_pmp_codigo_unique');
            $table->uuid('linea_proceso_id');
            $table->date('fecha');
            $table->string('turno_inicio', 1);
            $table->string('estado', 20);
            $table->string('tipo_cierre', 20)->nullable();
            $table->uuid('version_vigente_id')->nullable();
            $table->unsignedSmallInteger('numero_version_vigente')->default(1);
            // Lotes de la versión vigente. Se leen con la orden bloqueada, sin abrir un snapshot
            // antes de bloquear los lotes.
            $table->json('lote_ids_vigentes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creado_por_user_id');
            $table->foreignId('cerrado_por_user_id')->nullable();
            $table->timestamp('cerrado_at', precision: 6)->nullable();
            $table->text('motivo_cancelacion')->nullable();
            $table->timestamps(precision: 6);

            $table->foreign('temporada_id', 'orden_pmp_temporada_fk')
                ->references('id')->on('temporadas')->restrictOnDelete();
            $table->foreign('linea_proceso_id', 'orden_pmp_linea_fk')
                ->references('id')->on('lineas_proceso')->restrictOnDelete();
            $table->foreign('creado_por_user_id', 'orden_pmp_creado_por_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cerrado_por_user_id', 'orden_pmp_cerrado_por_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index(['temporada_id', 'estado', 'linea_proceso_id'], 'orden_pmp_temporada_estado_linea_index');
            $table->index(['fecha', 'linea_proceso_id'], 'orden_pmp_fecha_linea_index');
        });

        Schema::create('versiones_orden_proceso_mp', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orden_proceso_mp_id');
            $table->unsignedSmallInteger('numero');
            $table->uuid('operacion_id')->unique('version_opmp_operacion_unique');
            $table->char('payload_hash', 64);
            $table->string('origen', 20);
            $table->time('hora_inicio');
            $table->time('colacion_desde')->nullable();
            $table->time('colacion_hasta')->nullable();
            $table->decimal('rendimiento_kg_hora', 10, 2);
            $table->text('motivo_cambio')->nullable();
            $table->foreignId('creada_por_user_id');
            $table->timestamp('creada_at', precision: 6);
            $table->timestamps(precision: 6);

            $table->foreign('orden_proceso_mp_id', 'version_opmp_orden_fk')
                ->references('id')->on('ordenes_proceso_mp')->restrictOnDelete();
            $table->foreign('creada_por_user_id', 'version_opmp_creada_por_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['orden_proceso_mp_id', 'numero'], 'version_opmp_orden_numero_unique');
        });

        Schema::table('ordenes_proceso_mp', function (Blueprint $table): void {
            $table->foreign('version_vigente_id', 'orden_pmp_version_vigente_fk')
                ->references('id')->on('versiones_orden_proceso_mp')->restrictOnDelete();
        });

        Schema::create('lotes_version_orden_mp', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('version_orden_proceso_mp_id');
            $table->uuid('lote_materia_prima_id');
            $table->unsignedSmallInteger('secuencia');
            $table->string('numero_proceso', 40)->nullable();
            $table->unsignedInteger('bins_programados');
            $table->unsignedInteger('totes_programados')->nullable();
            $table->decimal('kilos_programados', 12, 3);
            $table->string('embalaje', 150)->nullable();
            $table->decimal('kilos_por_caja', 6, 3)->nullable();
            $table->decimal('cajas_estimadas', 10, 1)->nullable();
            $table->string('mercado_destino', 150)->nullable();
            $table->dateTime('inicio_estimado');
            $table->dateTime('termino_estimado');
            // Copias al publicar: la PRE-SIF exportada no cambia si después se corrige un maestro.
            $table->uuid('cliente_id');
            $table->string('cliente_snapshot', 150);
            $table->string('numero_lote_snapshot', 80);
            $table->string('especie_snapshot', 100);
            $table->string('variedad_snapshot', 100);
            $table->date('fecha_cosecha_snapshot');
            $table->string('csg_snapshot', 50);
            $table->string('productor_snapshot', 200)->nullable();
            $table->string('comuna', 100)->nullable();
            $table->string('provincia', 100)->nullable();
            $table->timestamps(precision: 6);

            $table->foreign('version_orden_proceso_mp_id', 'lote_vopmp_version_fk')
                ->references('id')->on('versiones_orden_proceso_mp')->restrictOnDelete();
            $table->foreign('lote_materia_prima_id', 'lote_vopmp_lote_fk')
                ->references('id')->on('lotes_materia_prima')->restrictOnDelete();
            $table->unique(['version_orden_proceso_mp_id', 'lote_materia_prima_id'], 'lote_vopmp_version_lote_unique');
            $table->unique(['version_orden_proceso_mp_id', 'secuencia'], 'lote_vopmp_version_secuencia_unique');
            $table->index(['csg_snapshot', 'created_at'], 'lote_vopmp_csg_index');
        });

        // Compromiso vigente de cada lote con cada orden. Solo cambia con el lote bloqueado,
        // por eso la suma por lote es la fuente para no programar más bins de los que existen.
        Schema::create('reservas_lote_orden_proceso_mp', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('orden_proceso_mp_id');
            $table->uuid('lote_materia_prima_id');
            $table->unsignedInteger('bins_programados');
            $table->unsignedInteger('bins_entregados')->default(0);
            $table->unsignedInteger('bins_pendientes');
            $table->timestamps(precision: 6);

            $table->foreign('orden_proceso_mp_id', 'reserva_lopmp_orden_fk')
                ->references('id')->on('ordenes_proceso_mp')->restrictOnDelete();
            $table->foreign('lote_materia_prima_id', 'reserva_lopmp_lote_fk')
                ->references('id')->on('lotes_materia_prima')->restrictOnDelete();
            $table->unique(['orden_proceso_mp_id', 'lote_materia_prima_id'], 'reserva_lopmp_orden_lote_unique');
            $table->index(['lote_materia_prima_id', 'bins_pendientes'], 'reserva_lopmp_lote_pendientes_index');
        });

        Schema::table('entregas_fruta_proceso', function (Blueprint $table): void {
            $table->uuid('orden_proceso_mp_id')->nullable()->after('lote_materia_prima_id');
            $table->uuid('version_orden_proceso_mp_id')->nullable()->after('orden_proceso_mp_id');
            $table->boolean('fuera_de_orden')->default(false)->after('version_orden_proceso_mp_id');
            $table->foreignId('autorizado_por_user_id')->nullable()->after('fuera_de_orden');

            $table->foreign('orden_proceso_mp_id', 'entrega_fp_orden_fk')
                ->references('id')->on('ordenes_proceso_mp')->restrictOnDelete();
            $table->foreign('version_orden_proceso_mp_id', 'entrega_fp_version_orden_fk')
                ->references('id')->on('versiones_orden_proceso_mp')->restrictOnDelete();
            $table->foreign('autorizado_por_user_id', 'entrega_fp_autorizado_por_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::table('secuencias_documentos')->updateOrInsert(
            ['clave' => 'ordenes_proceso_mp'],
            ['ultimo_numero' => 0],
        );
    }

    public function down(): void
    {
        Schema::table('entregas_fruta_proceso', function (Blueprint $table): void {
            $table->dropForeign('entrega_fp_orden_fk');
            $table->dropForeign('entrega_fp_version_orden_fk');
            $table->dropForeign('entrega_fp_autorizado_por_fk');
            $table->dropColumn(['orden_proceso_mp_id', 'version_orden_proceso_mp_id', 'fuera_de_orden', 'autorizado_por_user_id']);
        });
        Schema::dropIfExists('reservas_lote_orden_proceso_mp');
        Schema::dropIfExists('lotes_version_orden_mp');
        Schema::table('ordenes_proceso_mp', function (Blueprint $table): void {
            $table->dropForeign('orden_pmp_version_vigente_fk');
        });
        Schema::dropIfExists('versiones_orden_proceso_mp');
        Schema::dropIfExists('ordenes_proceso_mp');
        DB::table('secuencias_documentos')->where('clave', 'ordenes_proceso_mp')->delete();
    }
};
