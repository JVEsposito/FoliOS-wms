<?php

use App\Finanzas\EsquemaFinanzas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        $finanzas = app(EsquemaFinanzas::class);
        $finanzas->asegurarBase();
        $esquema = $finanzas->conexion()->getSchemaBuilder();
        $tablas = array_filter($esquema->getTables($finanzas->nombreBase()), fn (array $tabla): bool => str_starts_with($tabla['name'], 'fin_'));

        // Repara el borrado anticipado del proveedor anterior: el registro operativo
        // conservó las migraciones, pero desaparecieron todas las tablas financieras.
        // Solo recrea la estructura; los datos borrados requieren un respaldo.
        if ($tablas === []) {
            (require __DIR__.'/2026_10_17_100100_crear_captura_hechos_finanzas.php')->up();
        }
        foreach (['fin_lecturas', 'fin_cursores', 'fin_hechos'] as $tabla) {
            if (! $esquema->hasTable($tabla)) {
                throw new RuntimeException('El esquema de Finanzas está incompleto. Restaure su respaldo antes de migrar; no se borraron sus tablas.');
            }
        }

        $esquema->table('fin_cursores', function (Blueprint $tabla): void {
            $tabla->dateTime('conciliada_at')->nullable();
        });
    }

    public function down(): void
    {
        app(EsquemaFinanzas::class)->conexion()->getSchemaBuilder()->table('fin_cursores', function (Blueprint $tabla): void {
            $tabla->dropColumn('conciliada_at');
        });
    }
};
