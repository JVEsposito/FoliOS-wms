<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guias_despacho_envases', function (Blueprint $table): void {
            $table->foreignUuid('productor_csg_id')->nullable()
                ->constrained('productores_csg')->restrictOnDelete();
            $table->json('productor_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guias_despacho_envases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('productor_csg_id');
            $table->dropColumn('productor_snapshot');
        });
    }
};
