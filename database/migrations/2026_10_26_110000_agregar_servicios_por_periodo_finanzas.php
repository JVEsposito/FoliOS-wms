<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::connection($this->connection)->table('fin_servicios', fn (Blueprint $t) => $t->json('filtros')->nullable());
        Schema::connection($this->connection)->table('fin_cargos', fn (Blueprint $t) => $t->date('fecha_hasta')->nullable());
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('fin_cargos', fn (Blueprint $t) => $t->dropColumn('fecha_hasta'));
        Schema::connection($this->connection)->table('fin_servicios', fn (Blueprint $t) => $t->dropColumn('filtros'));
    }
};
