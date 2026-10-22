<?php

namespace Tests\Feature\Database;

use App\Exceptions\ConflictoOperacion;
use App\Models\ConductorTransporte;
use App\Models\RecepcionMaterial;
use App\Models\User;
use App\Services\Materiales\ServicioDirectorioTransporte;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectorioTransporteMigracionTest extends TestCase
{
    public function test_migracion_preserva_textos_y_directorio_conserva_viajes_y_revierte_conflictos(): void
    {
        // Aísla el contrato de esta migración de los esquemas externos de Finanzas,
        // RRHH y Comex. El circuito API completo se prueba además en MySQL.
        config(['database.default' => 'transporte_prueba', 'database.connections.transporte_prueba' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('recepciones_materiales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('patente')->nullable();
            $table->string('transportista')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'Ana Pérez']);
        $legacy = RecepcionMaterial::create(['patente' => 'CAMIÓN ANTIGUO', 'transportista' => 'Texto original']);
        $antes = $legacy->getAttributes();
        $migracion = require database_path('migrations/2026_10_22_220000_directorio_transporte_materiales.php');
        $migracion->up();
        $this->assertEquals($antes, array_intersect_key($legacy->fresh()->getAttributes(), $antes));
        $this->assertDatabaseCount('conductores_transporte', 0);

        $servicio = app(ServicioDirectorioTransporte::class);
        $actor = User::findOrFail(1);
        $compat = $servicio->capturar(['patente' => '  TEXTO ANTIGUO  ', 'transportista' => '  Empresa  '], $actor);
        $this->assertSame(['patente' => 'TEXTO ANTIGUO', 'transportista' => 'Empresa'], $compat);
        $datos = ['operacion_id' => (string) Str::uuid(), 'transporte' => [
            'conductor' => ['rut' => '11.111.111-1', 'nombre' => 'Juan', 'telefono' => '123'],
            'empresa' => ['rut' => '76123456-0', 'nombre' => 'Empresa'],
            'vehiculo' => ['patente' => 'AB-CD 12', 'descripcion' => 'Camión'],
        ]];
        $captura = DB::transaction(fn () => $servicio->capturar($datos, $actor));
        $datosHistoricos = $datos;
        $legacy->update($captura);
        $this->assertSame('11111111-1', $captura['transporte_snapshot']['conductor']['rut']);
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['transporte']['conductor'] = [...$datos['transporte']['conductor'], 'nombre' => 'Juan corregido',
            'version_conocida' => 1, 'actualizar_directorio' => true];
        DB::transaction(fn () => $servicio->capturar($datos, $actor));
        $this->assertSame(2, ConductorTransporte::firstOrFail()->version);
        $this->assertSame($captura['transporte_snapshot'], $legacy->fresh()->transporte_snapshot);
        // El orden de claves de un objeto JSON puede cambiar al persistirlo.
        // La comparación debe reconocer los mismos datos sin adoptar la v2.
        $legacy->update(['transporte_snapshot' => array_map(
            fn ($ficha) => is_array($ficha) ? array_reverse($ficha, true) : $ficha,
            $captura['transporte_snapshot'],
        )]);
        $historico = DB::transaction(fn () => $servicio->capturar($datosHistoricos, $actor, $legacy->fresh()));
        $this->assertSame(1, $historico['transporte_snapshot']['conductor']['version']);
        try {
            DB::transaction(fn () => $servicio->capturar($datos, $actor));
            $this->fail('Una versión obsoleta no puede reemplazar la ficha.');
        } catch (ConflictoOperacion) {
            $this->assertSame('Juan corregido', ConductorTransporte::firstOrFail()->nombre);
        }
        $this->assertDatabaseCount('eventos_directorio_transporte', 4);
        $this->assertDatabaseHas('eventos_directorio_transporte', ['user_id' => 1, 'usuario' => 'Ana Pérez']);
        $this->assertSame($antes['created_at'], $legacy->fresh()->getRawOriginal('created_at'));
        $migracion->down();
        $this->assertFalse(Schema::hasTable('conductores_transporte'));
        $this->assertSame('ABCD12', $legacy->fresh()->patente);
        DB::purge('transporte_prueba');
    }
}
