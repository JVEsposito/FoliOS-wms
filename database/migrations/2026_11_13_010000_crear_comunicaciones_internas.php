<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('com_stickers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operacion_id');
            $table->string('nombre', 80);
            $table->string('ruta');
            $table->string('mime', 40);
            $table->string('sha256', 64);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'operacion_id'], 'com_sticker_operacion_unica');
            $table->index(['activo', 'id']);
        });
        Schema::create('com_conversaciones', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 15);
            $table->string('clave', 100)->unique();
            $table->unsignedBigInteger('ultimo_mensaje_id')->default(0);
            $table->timestamps();
        });
        Schema::create('com_participantes', function (Blueprint $table): void {
            $table->foreignId('conversacion_id')->constrained('com_conversaciones')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('ultimo_leido_id')->default(0);
            $table->timestamp('leido_at')->nullable();
            $table->primary(['conversacion_id', 'user_id']);
            $table->index(['user_id', 'conversacion_id']);
        });
        Schema::create('com_mensajes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('com_conversaciones')->restrictOnDelete();
            $table->foreignId('remitente_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operacion_id');
            $table->text('texto')->nullable();
            $table->foreignId('sticker_id')->nullable()->constrained('com_stickers')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['remitente_user_id', 'operacion_id'], 'com_chat_operacion_unica');
            $table->index(['conversacion_id', 'id']);
        });
        Schema::create('com_correos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remitente_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operacion_id');
            $table->string('asunto', 160);
            $table->text('texto');
            $table->foreignId('respuesta_a_id')->nullable()->constrained('com_correos')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['remitente_user_id', 'operacion_id'], 'com_correo_operacion_unica');
            $table->index(['remitente_user_id', 'id']);
        });
        Schema::create('com_destinatarios', function (Blueprint $table): void {
            $table->foreignId('correo_id')->constrained('com_correos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('leido_at')->nullable();
            $table->timestamp('archivado_at')->nullable();
            $table->primary(['correo_id', 'user_id']);
            $table->index(['user_id', 'archivado_at', 'leido_at']);
        });
        DB::table('com_conversaciones')->insert(['tipo' => 'global', 'clave' => 'global', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (['com_destinatarios', 'com_correos', 'com_mensajes', 'com_participantes', 'com_conversaciones', 'com_stickers'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
