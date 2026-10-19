<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre mensual de la prefactura. Al cerrar un mes se congela lo que se cobró: las líneas por
 * cliente y lo que aportó cada hecho. Lo que cambia después (en la operación o en el
 * tarifario) no toca el mes cerrado: aparece como diferencia, y cada decisión sobre una
 * diferencia (aceptarla para cobrarla en el primer mes abierto, o descartarla) queda
 * registrada con los hechos que la explican. Nada se borra.
 */
return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        Schema::connection($this->connection)->create('fin_cierres', function (Blueprint $table): void {
            $table->id();
            $table->char('mes', 7);
            // Un mes reabierto y vuelto a cerrar guarda otra versión; la anterior queda reabierta.
            $table->unsignedInteger('version');
            $table->string('estado', 20);
            $table->dateTime('cerrado_at');
            $table->unsignedBigInteger('cerrado_por_user_id')->nullable();
            $table->string('cerrado_por_nombre')->nullable();
            $table->dateTime('reabierto_at')->nullable();
            $table->unsignedBigInteger('reabierto_por_user_id')->nullable();
            $table->string('reabierto_por_nombre')->nullable();
            $table->string('motivo_reapertura', 500)->nullable();
            // Último hecho registrado al cerrar: los posteriores son cambios después del cierre.
            $table->unsignedBigInteger('hechos_hasta_id');
            $table->foreignId('lectura_id')->nullable()->constrained('fin_lecturas')->nullOnDelete();
            $table->dateTime('lectura_at')->nullable();
            $table->json('totales');
            $table->json('resumen');
            $table->json('pendientes');
            // Última revisión de diferencias: evita recalcular un mes en el que nada cambió.
            $table->json('revision')->nullable();
            $table->timestamps();

            $table->unique(['mes', 'version']);
            $table->index(['estado', 'mes']);
        });

        Schema::connection($this->connection)->create('fin_cierres_lineas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('fin_cierres');
            $table->char('cliente_id', 36);
            $table->string('cliente_nombre', 180);
            $table->foreignId('tarifa_id')->constrained('fin_tarifas');
            $table->string('servicio', 40);
            $table->string('unidad', 40);
            $table->string('origen', 10);
            $table->decimal('precio', 18, 6);
            $table->string('moneda', 10);
            $table->string('simbolo', 10);
            $table->unsignedTinyInteger('decimales');
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();
            $table->decimal('cantidad', 24, 6);
            $table->unsignedInteger('dias');
            $table->unsignedInteger('hechos');
            $table->decimal('monto', 24, 6);

            $table->unique(['cierre_id', 'cliente_id', 'tarifa_id']);
        });

        // Lo que aportó cada hecho a cada línea: el detalle del mes cerrado y la base para
        // explicar las diferencias hecho por hecho.
        Schema::connection($this->connection)->create('fin_cierres_aportes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('fin_cierres');
            $table->char('cliente_id', 36);
            $table->unsignedBigInteger('tarifa_id');
            $table->string('moneda', 10);
            $table->unsignedBigInteger('hecho_id');
            $table->string('fuente', 40);
            $table->string('origen_id', 100);
            $table->unsignedInteger('version');
            $table->string('referencia', 100)->nullable();
            $table->date('desde');
            $table->date('hasta');
            $table->unsignedInteger('dias');
            $table->decimal('cantidad', 24, 6);

            $table->index(['cierre_id', 'cliente_id', 'tarifa_id']);
        });

        Schema::connection($this->connection)->create('fin_diferencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cierre_id')->constrained('fin_cierres');
            $table->char('mes', 7);
            $table->char('cliente_id', 36);
            $table->string('cliente_nombre', 180);
            $table->foreignId('tarifa_id')->constrained('fin_tarifas');
            $table->string('servicio', 40);
            $table->string('unidad', 40);
            $table->string('moneda', 10);
            $table->string('simbolo', 10);
            $table->unsignedTinyInteger('decimales');
            // Precio con que la línea estaba cobrada y el que rige al decidir (nulo si no hay).
            $table->decimal('precio_anterior', 18, 6)->nullable();
            $table->decimal('precio', 18, 6)->nullable();
            // La diferencia, con signo: positiva se cobra, negativa se abona.
            $table->decimal('cantidad', 24, 6);
            $table->integer('dias');
            $table->decimal('monto', 24, 6);
            $table->string('decision', 20);
            $table->string('motivo', 500)->nullable();
            // Aceptada: el mes abierto en que se cobra.
            $table->char('mes_destino', 7)->nullable();
            $table->dateTime('decidida_at');
            $table->unsignedBigInteger('decidida_por_user_id')->nullable();
            $table->string('decidida_por_nombre')->nullable();
            $table->dateTime('anulada_at')->nullable();
            $table->unsignedBigInteger('anulada_por_user_id')->nullable();
            $table->string('anulada_por_nombre')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();

            $table->index(['cierre_id', 'anulada_at']);
            $table->index(['mes_destino', 'anulada_at']);
        });

        Schema::connection($this->connection)->create('fin_diferencias_aportes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diferencia_id')->constrained('fin_diferencias');
            $table->string('fuente', 40);
            $table->string('origen_id', 100);
            $table->unsignedBigInteger('hecho_id')->nullable();
            $table->string('referencia', 100)->nullable();
            $table->string('tipo', 20);
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->integer('dias');
            $table->decimal('cantidad', 24, 6);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('fin_diferencias_aportes');
        Schema::connection($this->connection)->dropIfExists('fin_diferencias');
        Schema::connection($this->connection)->dropIfExists('fin_cierres_aportes');
        Schema::connection($this->connection)->dropIfExists('fin_cierres_lineas');
        Schema::connection($this->connection)->dropIfExists('fin_cierres');
    }
};
