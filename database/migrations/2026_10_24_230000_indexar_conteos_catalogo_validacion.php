<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDICES = ['articulos_validacion' => 'art_val_temporada_activo', 'origenes_validacion' => 'ori_val_temporada_activo', 'combinaciones_validacion' => 'comb_val_temporada_activo'];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $nombre) {
            Schema::table($tabla, fn (Blueprint $t) => $t->index(['temporada_id', 'activo'], $nombre));
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $nombre) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex($nombre));
        }
    }
};
