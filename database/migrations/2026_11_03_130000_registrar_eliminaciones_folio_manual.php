<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un administrador puede eliminar un pallet o saldo que la tablet creó sin
 * Validación PT, mientras no haya participado en otra operación. El folio no se
 * borra: queda anulado, libera su posición y su número, y este registro conserva
 * quién, cuándo, por qué y cómo estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eliminaciones_folio_manual', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('folio_id')->unique()->constrained('folios');
            $table->string('numero_folio', 50)->index();
            $table->string('numero_folio_archivado', 50);
            $table->text('motivo');
            $table->foreignId('eliminado_por_user_id')->constrained('users');
            $table->timestamp('eliminado_at');
            $table->json('snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eliminaciones_folio_manual');
    }
};
