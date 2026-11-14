<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('com_conversaciones', function (Blueprint $table): void {
            $table->string('nombre', 100)->nullable();
            $table->foreignId('creador_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('revision_moderacion')->default(0);
        });
        Schema::table('com_mensajes', function (Blueprint $table): void {
            $table->foreignId('eliminado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('eliminado_at')->nullable();
            $table->unsignedBigInteger('revision_eliminacion')->nullable();
            $table->index(['conversacion_id', 'revision_eliminacion'], 'com_chat_revision_eliminacion');
        });
        Schema::create('com_silencios_chat', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversacion_id')->constrained('com_conversaciones')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('moderador_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('hasta');
            $table->timestamp('revocado_at')->nullable();
            $table->foreignId('revocado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['conversacion_id', 'user_id', 'revocado_at', 'hasta'], 'com_chat_silencio_vigente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('com_silencios_chat');
        Schema::table('com_mensajes', function (Blueprint $table): void {
            $table->dropIndex('com_chat_revision_eliminacion');
            $table->dropConstrainedForeignId('eliminado_por_user_id');
            $table->dropColumn(['eliminado_at', 'revision_eliminacion']);
        });
        Schema::table('com_conversaciones', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('creador_user_id');
            $table->dropColumn(['nombre', 'revision_moderacion']);
        });
    }
};
