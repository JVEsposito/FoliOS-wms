<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bins_retorno_packing', function (Blueprint $table): void {
            $table->string('folio_por_confirmar', 80)->nullable()->after('folio_provisional');
        });
    }

    public function down(): void
    {
        Schema::table('bins_retorno_packing', function (Blueprint $table): void {
            $table->dropColumn('folio_por_confirmar');
        });
    }
};
