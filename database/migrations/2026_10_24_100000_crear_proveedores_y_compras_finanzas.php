<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'finanzas';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->create('fin_proveedores', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('proveedor_material_id')->nullable()->unique();
            $t->string('rut', 12)->unique();
            $t->string('razon_social', 180);
            $t->string('giro', 180)->nullable();
            $t->string('direccion', 250)->nullable();
            $t->string('contacto', 180)->nullable();
            $t->string('email', 180)->nullable();
            $t->string('telefono', 40)->nullable();
            $t->string('categoria', 80)->nullable();
            $t->boolean('activo')->default(true);
            $t->boolean('pagos_bloqueados')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        $schema->create('fin_cuentas_compra', function (Blueprint $t): void {
            $t->string('codigo', 30)->primary();
            $t->string('nombre', 180);
            $t->string('tipo', 20);
            $t->boolean('activa')->default(true);
            $t->timestamps();
        });
        $schema->create('fin_documentos_compra', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('operacion_id')->unique();
            $t->char('payload_hash', 64);
            $t->uuid('proveedor_id');
            $t->foreign('proveedor_id')->references('id')->on('fin_proveedores');
            $t->json('proveedor_snapshot');
            $t->string('tipo', 20);
            $t->string('folio', 40);
            $t->date('fecha_emision');
            $t->date('fecha_recepcion');
            $t->date('vencimiento');
            $t->string('respaldo_plazo', 500)->nullable();
            foreach (['neto', 'exento', 'iva', 'otros', 'total'] as $campo) {
                $t->decimal($campo, 18, 0);
            }
            $t->json('lineas');
            $t->uuid('referencia_id')->nullable();
            $t->foreign('referencia_id')->references('id')->on('fin_documentos_compra');
            $t->json('guias');
            $t->string('respaldo', 1000);
            $t->string('observacion_iva', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->date('fecha_contable')->nullable();
            $t->dateTime('registrado_at')->nullable();
            $t->dateTime('anulado_at')->nullable();
            $t->date('fecha_anulacion')->nullable();
            $t->string('motivo_anulacion', 500)->nullable();
            $t->timestamps();
            $t->unique(['proveedor_id', 'tipo', 'folio'], 'fin_compra_identidad_unique');
            $t->index(['fecha_contable', 'proveedor_id']);
        });
        $schema->create('fin_asientos_compra', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('proveedor_id');
            $t->foreign('proveedor_id')->references('id')->on('fin_proveedores');
            $t->string('origen_tipo', 30);
            $t->uuid('origen_id');
            $t->unique(['origen_tipo', 'origen_id']);
            $t->uuid('reversa_de_id')->nullable()->unique();
            $t->foreign('reversa_de_id')->references('id')->on('fin_asientos_compra');
            $t->date('fecha');
            $t->string('glosa', 500);
            $t->unsignedBigInteger('user_id');
            $t->string('user_nombre', 180);
            $t->dateTime('created_at');
            $t->index(['proveedor_id', 'fecha']);
        });
        $schema->create('fin_asiento_compra_lineas', function (Blueprint $t): void {
            $t->id();
            $t->uuid('asiento_id');
            $t->foreign('asiento_id')->references('id')->on('fin_asientos_compra');
            $t->string('cuenta', 30);
            $t->foreign('cuenta')->references('codigo')->on('fin_cuentas_compra');
            $t->string('cuenta_nombre', 180);
            $t->string('cuenta_tipo', 20);
            $t->string('descripcion', 250);
            $t->string('ceco', 80)->nullable();
            $t->decimal('debe', 18, 0);
            $t->decimal('haber', 18, 0);
        });
        $schema->create('fin_eventos_compra', function (Blueprint $t): void {
            $t->id();
            $t->string('entidad', 30);
            $t->string('entidad_id', 40);
            $t->string('accion', 30);
            $t->json('datos');
            $t->unsignedBigInteger('user_id');
            $t->string('user_nombre', 180);
            $t->dateTime('created_at');
            $t->index(['entidad', 'entidad_id']);
        });
        $schema->create('fin_periodos_compra', function (Blueprint $t): void {
            $t->char('mes', 7)->primary();
            $t->dateTime('cerrado_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['fin_periodos_compra', 'fin_eventos_compra', 'fin_asiento_compra_lineas', 'fin_asientos_compra', 'fin_documentos_compra', 'fin_cuentas_compra', 'fin_proveedores'] as $tabla) {
            Schema::connection($this->connection)->dropIfExists($tabla);
        }
    }
};
