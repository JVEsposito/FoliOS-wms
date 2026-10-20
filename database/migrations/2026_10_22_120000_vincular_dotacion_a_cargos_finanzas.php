<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::connection($this->connection)->create('fin_dotacion', function (Blueprint $t): void {
            $t->id();
            $t->uuid('asignacion_id');
            $t->string('servicio', 40);
            $t->uuid('cargo_id');
            $t->unsignedInteger('revision');
            $t->string('huella', 64);
            $t->timestamps();
            $t->unique(['asignacion_id', 'servicio']);
            $t->foreign('cargo_id')->references('id')->on('fin_cargos');
            $t->foreign('servicio')->references('clave')->on('fin_servicios');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('fin_dotacion');
    }
};
