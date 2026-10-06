<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/** Solo fixtures: no despliega ni sustituye las migraciones del PR 1. */
trait ContratoRecepcionEmbaladaPrueba
{
    use RefreshDatabase { migrateDatabases as private migrarProyecto; }

    protected function beforeRefreshingDatabase(): void
    {
        if (! Schema::hasTable('recepciones_fruta_embalada')) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    protected function migrateDatabases(): void
    {
        $this->migrarProyecto();
        if (Schema::hasTable('recepciones_fruta_embalada')) {
            return;
        }
        Schema::table('especies_validacion', fn (Blueprint $t) => $t->decimal('umbral_prefrio', 6, 2)->nullable());
        Schema::create('plantas_origen', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('nombre');
            $t->string('codigo');
            $t->boolean('activa');
        });
        Schema::create('recepciones_fruta_embalada', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('temporada_id');
            $t->uuid('cliente_validacion_id');
            $t->uuid('planta_origen_id');
            $t->string('numero_guia');
            $t->string('servicio');
            $t->string('estado');
            $t->boolean('llega_con_prefrio');
            $t->dateTime('recibido_at');
            $t->string('turno')->nullable();
            $t->unsignedBigInteger('validador_id')->nullable();
            $t->dateTime('salida_at')->nullable();
            $t->string('chofer')->nullable();
            $t->string('rut_chofer')->nullable();
            $t->string('patente_delantera')->nullable();
            $t->string('patente_carro')->nullable();
            $t->text('observacion')->nullable();
            $t->timestamps();
        });
        Schema::create('recepciones_fruta_embalada_pallets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('recepcion_id');
            $t->integer('orden');
            $t->string('folio_origen');
            $t->string('tipo_bulto');
            foreach (['especie', 'variedad', 'envase', 'calibre', 'csg'] as $nombre) {
                $t->uuid($nombre.'_validacion_id');
            }
            $t->integer('cantidad_cajas');
            $t->string('csp')->nullable();
            $t->date('fecha_proceso_origen');
            $t->decimal('temperatura_pulpa', 6, 2);
            $t->uuid('condicion_sag_id')->nullable();
            $t->timestamps();
        });
    }
}
