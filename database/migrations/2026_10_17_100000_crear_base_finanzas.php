<?php

use App\Finanzas\EsquemaFinanzas;
use Illuminate\Database\Migrations\Migration;

/*
 * Finanzas guarda sus datos en una base propia (conexión «finanzas»), separada de la
 * operativa. Esta migración solo la crea si falta; nunca la borra.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(EsquemaFinanzas::class)->asegurarBase();
    }

    public function down(): void
    {
        // La base de Finanzas no se elimina automáticamente: cada migración de Finanzas
        // borra sus propias tablas y la base vacía queda para quien la administra.
    }
};
