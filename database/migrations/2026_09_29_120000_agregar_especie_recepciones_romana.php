<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->foreignUuid('especie_validacion_id')->nullable()
                ->constrained('especies_validacion')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recepciones_romana', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('especie_validacion_id');
        });
    }
};
