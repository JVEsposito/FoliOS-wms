<?php

use App\Services\MateriaPrima\RellenoEnvasesLotes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotes_materia_prima_envases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('lote_materia_prima_id')->constrained('lotes_materia_prima')->restrictOnDelete();
            $table->string('tipo_envase', 20);
            $table->unsignedInteger('cantidad');
            // Las recepciones históricas pueden carecer de tara documentada.
            $table->decimal('tara_unitaria', 8, 3)->nullable();
            $table->timestamps();
            $table->unique(['lote_materia_prima_id', 'tipo_envase'], 'lote_mp_envases_tipo_unique');
        });

        $multiples = app(RellenoEnvasesLotes::class)->ejecutar();
        echo 'Segmentos con varios lotes activos sin modificar: '.count($multiples)
            .($multiples ? ' ('.implode(', ', $multiples).')' : '').PHP_EOL;
    }

    public function down(): void
    {
        Schema::dropIfExists('lotes_materia_prima_envases');
    }
};
