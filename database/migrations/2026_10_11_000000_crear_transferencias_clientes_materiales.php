<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias_clientes_materiales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('operacion_id')->unique();
            $table->char('payload_hash', 64);
            $table->foreignUuid('temporada_id')->constrained('temporadas')->restrictOnDelete();
            foreach (['origen', 'destino'] as $lado) {
                $table->uuid("folio_{$lado}_id");
                $table->foreign("folio_{$lado}_id", "tr_cliente_folio_{$lado}_fk")->references('folio_id')->on('folios_materiales')->restrictOnDelete();
                $table->foreignUuid("cliente_{$lado}_id")->constrained('clientes')->restrictOnDelete();
                $table->foreignUuid("item_{$lado}_id")->constrained('items_materiales')->restrictOnDelete();
                $table->index(["cliente_{$lado}_id", 'ocurrido_at'], "tr_cliente_{$lado}_fecha_idx");
            }
            $table->unique('folio_destino_id', 'tr_cliente_destino_unique');
            $table->decimal('cantidad', 14, 3);
            $table->string('unidad_medida', 40);
            $table->enum('modalidad', ['total', 'parcial']);
            $table->text('motivo');
            $table->string('documento_respaldo', 150)->nullable();
            $table->json('snapshot');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('dispositivo_id')->nullable()->constrained('dispositivos')->restrictOnDelete();
            $table->timestamp('ocurrido_at');
            $table->timestamps();
        });
        foreach (['movimientos_inventario_materiales', 'trabajos_impresion_materiales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla): void {
                $table->uuid('transferencia_cliente_material_id')->nullable();
                $table->foreign('transferencia_cliente_material_id', $tabla === 'movimientos_inventario_materiales' ? 'mov_inv_tr_cliente_fk' : 'imp_tr_cliente_fk')
                    ->references('id')->on('transferencias_clientes_materiales')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['movimientos_inventario_materiales', 'trabajos_impresion_materiales'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla): void {
                $table->dropForeign($tabla === 'movimientos_inventario_materiales' ? 'mov_inv_tr_cliente_fk' : 'imp_tr_cliente_fk');
                $table->dropColumn('transferencia_cliente_material_id');
            });
        }
        Schema::dropIfExists('transferencias_clientes_materiales');
    }
};
