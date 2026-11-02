<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $table) {
            $table->text('motivo_cancelacion')->nullable()->after('cancelada_at');
        });

        // Pallet que salió de la cámara por una carga y debe volver a ella. El
        // folio conserva su número; mientras está pendiente no puede asignarse a
        // otra carga ni cuenta como existencia disponible.
        Schema::create('reingresos_folio_camara', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            $table->foreignUuid('folio_id')->constrained('folios')->restrictOnDelete();
            // Igual al folio mientras está pendiente: un folio tiene un solo reingreso abierto.
            $table->foreignUuid('folio_pendiente_id')->nullable()->unique()->constrained('folios')->restrictOnDelete();
            $table->foreignUuid('carga_id')->nullable()->constrained('cargas')->restrictOnDelete();
            $table->foreignUuid('carga_folio_id')->nullable()->constrained('carga_folios')->restrictOnDelete();
            $table->string('origen', 30)->index();
            $table->string('estado', 20)->default('pendiente')->index();
            $table->string('estado_carga_previo', 30)->nullable();
            $table->foreignUuid('anden_id')->nullable()->constrained('andenes')->restrictOnDelete();
            $table->text('motivo');
            $table->foreignId('registrado_por_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('registrado_at');
            $table->foreignUuid('movimiento_ubicacion_id')->nullable()->constrained('movimientos')->restrictOnDelete();
            $table->foreignUuid('camara_ubicacion_id')->nullable()->constrained('camaras')->restrictOnDelete();
            $table->foreignId('ubicado_por_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ubicado_at')->nullable();
            $table->timestamps();

            $table->index(['temporada_id', 'estado', 'registrado_at']);
            $table->index(['carga_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reingresos_folio_camara');
        Schema::table('cargas', function (Blueprint $table) {
            $table->dropColumn('motivo_cancelacion');
        });
    }
};
