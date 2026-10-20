<?php

use App\Comex\EsquemaComex;
use Illuminate\Database\Migrations\Migration;

/*
 * Comex guarda sus datos en una base propia (conexión «comex»), separada de la operativa.
 * Esta migración solo la crea si falta; nunca la borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(EsquemaComex::class)->asegurarBase();
    }

    public function down(): void
    {
        // La base de Comex no se elimina automáticamente: cada migración de Comex borra sus
        // propias tablas y la base vacía queda para quien la administra.
    }
};
