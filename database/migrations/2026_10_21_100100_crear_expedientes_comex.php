<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Expediente de embarque. Todo vive en la base de Comex: el embarque y el cliente son
 * referencias a la operación sin llave foránea, con una copia de lo leído.
 *
 * - cx_tipos_documento: qué documentos se piden, a quién y cuándo (configurable por planta).
 * - cx_tipos_documento_paises: destinos que exigen un documento (código ISO del país).
 * - cx_clientes: cómo sigue Comex a cada cliente (servicio, exportación propia o sin seguimiento).
 * - cx_documentos: el estado de cada documento de cada embarque, con su historial y respaldos.
 *
 * No carga datos: los documentos sugeridos se agregan desde la configuración de Comex.
 */
return new class extends Migration
{
    protected $connection = 'comex';

    public function up(): void
    {
        Schema::connection($this->connection)->create('cx_tipos_documento', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nombre', 120);
            $table->string('descripcion', 500)->nullable();
            // antes_salida: debe estar antes de que la carga salga; despues_salida: se completa después.
            $table->string('momento', 20);
            // siempre | paises (solo los destinos listados) | opcional (se registra, pero no se exige).
            $table->string('exigencia', 20);
            // Modalidades de transporte y gestiones del cliente donde aplica; vacío = todas.
            $table->json('transportes');
            $table->json('gestiones');
            $table->boolean('pide_numero')->default(true);
            $table->boolean('pide_fecha')->default(false);
            $table->boolean('pide_archivo')->default(true);
            // Dato del embarque que la operación ya registra (booking, DUS...) y se muestra como referencia.
            $table->string('dato_operacion', 30)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->boolean('sugerido')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('actualizado_por_user_id')->nullable();
            $table->string('actualizado_por_nombre', 150)->nullable();
            $table->timestamps();
            $table->index(['activo', 'orden']);
        });

        Schema::connection($this->connection)->create('cx_tipos_documento_paises', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tipo_documento_id')->constrained('cx_tipos_documento')->cascadeOnDelete();
            $table->char('pais_iso2', 2);
            $table->string('pais_nombre', 150);
            $table->unique(['tipo_documento_id', 'pais_iso2']);
        });

        Schema::connection($this->connection)->create('cx_clientes', function (Blueprint $table): void {
            $table->char('cliente_id', 36)->primary();
            $table->string('cliente_nombre', 180);
            // servicio | propia | sin_seguimiento
            $table->string('gestion', 20);
            $table->string('observacion', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('actualizado_por_user_id')->nullable();
            $table->string('actualizado_por_nombre', 150)->nullable();
            $table->timestamps();
        });

        Schema::connection($this->connection)->create('cx_documentos', function (Blueprint $table): void {
            $table->id();
            $table->char('embarque_id', 36);
            $table->string('embarque_codigo', 20);
            $table->char('cliente_id', 36)->nullable();
            $table->foreignId('tipo_documento_id')->constrained('cx_tipos_documento')->restrictOnDelete();
            // en_tramite | listo | no_aplica (sin registro, el documento está pendiente)
            $table->string('estado', 20);
            $table->string('numero', 120)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->string('observacion', 1000)->nullable();
            $table->string('motivo_no_aplica', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('actualizado_por_user_id')->nullable();
            $table->string('actualizado_por_nombre', 150)->nullable();
            $table->timestamps();
            $table->unique(['embarque_id', 'tipo_documento_id']);
            $table->index('numero');
        });

        Schema::connection($this->connection)->create('cx_documentos_archivos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('documento_id')->constrained('cx_documentos')->restrictOnDelete();
            $table->string('ruta', 300);
            $table->string('nombre_original', 250);
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('subido_por_user_id')->nullable();
            $table->string('subido_por_nombre', 150)->nullable();
            $table->dateTime('subido_at');
            $table->dateTime('reemplazado_at')->nullable();
            $table->index(['documento_id', 'reemplazado_at']);
        });

        Schema::connection($this->connection)->create('cx_documentos_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('documento_id')->constrained('cx_documentos')->restrictOnDelete();
            $table->string('tipo', 40);
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->json('datos')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_nombre', 150)->nullable();
            $table->dateTime('created_at');
            $table->index(['documento_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('cx_documentos_eventos');
        Schema::connection($this->connection)->dropIfExists('cx_documentos_archivos');
        Schema::connection($this->connection)->dropIfExists('cx_documentos');
        Schema::connection($this->connection)->dropIfExists('cx_clientes');
        Schema::connection($this->connection)->dropIfExists('cx_tipos_documento_paises');
        Schema::connection($this->connection)->dropIfExists('cx_tipos_documento');
    }
};
