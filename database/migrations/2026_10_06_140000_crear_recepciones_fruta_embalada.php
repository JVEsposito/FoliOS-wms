<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plantas_origen', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->boolean('activa')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('umbrales_prefrio_especies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // El nombre normalizado permite compartir el umbral entre temporadas.
            $table->string('especie', 150)->unique();
            $table->decimal('temperatura_maxima_c', 5, 2);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('eventos_catalogo_fruta_embalada', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tipo', 30);
            $table->uuid('registro_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->timestamps();
            $table->index(['tipo', 'registro_id'], 'rfe_catalogo_eventos_idx');
        });
        Schema::create('recepciones_fruta_embalada', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique('rfe_operacion_unique');
            $table->foreignUuid('temporada_id')->constrained('temporadas', indexName: 'rfe_temporada_fk')->restrictOnDelete();
            $table->foreignUuid('cliente_id')->constrained('clientes', indexName: 'rfe_cliente_fk')->restrictOnDelete();
            $table->foreignUuid('planta_origen_id')->constrained('plantas_origen', indexName: 'rfe_planta_fk')->restrictOnDelete();
            $table->string('numero_guia', 50);
            $table->string('servicio', 20);
            $table->string('turno', 30);
            $table->foreignId('validador_id')->constrained('users', indexName: 'rfe_validador_fk')->restrictOnDelete();
            $table->dateTime('recepcion_at');
            $table->dateTime('salida_at')->nullable();
            $table->string('chofer', 150);
            $table->string('rut_chofer', 20)->nullable();
            $table->string('patente_delantera', 20);
            $table->string('patente_carro', 20)->nullable();
            $table->boolean('llega_con_prefrio');
            $table->foreignUuid('condicion_sag_id')->nullable()->constrained('condiciones_sag', indexName: 'rfe_sag_fk')->restrictOnDelete();
            $table->text('observacion')->nullable();
            $table->string('estado', 20)->default('borrador');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('creado_por_user_id')->constrained('users', indexName: 'rfe_creador_fk')->restrictOnDelete();
            $table->foreignId('actualizado_por_user_id')->constrained('users', indexName: 'rfe_editor_fk')->restrictOnDelete();
            $table->timestamps();
            $table->index(['cliente_id', 'planta_origen_id', 'numero_guia'], 'rfe_guia_idx');
            $table->index(['temporada_id', 'recepcion_at', 'estado'], 'rfe_listado_idx');
        });
        Schema::create('recepciones_fruta_embalada_pallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recepcion_fruta_embalada_id')->constrained('recepciones_fruta_embalada', indexName: 'rfe_pallet_recepcion_fk')->restrictOnDelete();
            $table->unsignedInteger('orden');
            $table->string('folio_origen', 50)->index('rfe_folio_origen_idx');
            $table->string('tipo_bulto', 20);
            $table->foreignUuid('articulo_validacion_id')->constrained('articulos_validacion', indexName: 'rfe_pallet_articulo_fk')->restrictOnDelete();
            $table->foreignUuid('origen_validacion_id')->constrained('origenes_validacion', indexName: 'rfe_pallet_origen_fk')->restrictOnDelete();
            $table->string('embalaje', 150);
            $table->string('especie', 150);
            $table->string('variedad', 150);
            $table->string('csg', 50);
            $table->string('csp', 50)->nullable();
            $table->string('calibre', 100);
            $table->unsignedInteger('cantidad_cajas');
            $table->date('fecha_proceso_origen');
            $table->decimal('temperatura_pulpa_c', 5, 2);
            $table->foreignUuid('condicion_sag_id')->nullable()->constrained('condiciones_sag', indexName: 'rfe_pallet_sag_fk')->restrictOnDelete();
            $table->boolean('condicion_sag_personalizada')->default(false);
            $table->timestamps();
        });
        Schema::create('eventos_recepcion_fruta_embalada', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recepcion_fruta_embalada_id')->constrained('recepciones_fruta_embalada', indexName: 'rfe_evento_recepcion_fk')->restrictOnDelete();
            $table->uuid('operacion_id')->unique('rfe_evento_operacion_unique');
            $table->char('payload_hash', 64);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('dispositivo_id')->nullable()->constrained('dispositivos', indexName: 'rfe_evento_dispositivo_fk')->restrictOnDelete();
            $table->json('antes')->nullable();
            $table->json('despues');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_recepcion_fruta_embalada');
        Schema::dropIfExists('recepciones_fruta_embalada_pallets');
        Schema::dropIfExists('recepciones_fruta_embalada');
        Schema::dropIfExists('eventos_catalogo_fruta_embalada');
        Schema::dropIfExists('umbrales_prefrio_especies');
        Schema::dropIfExists('plantas_origen');
    }
};
