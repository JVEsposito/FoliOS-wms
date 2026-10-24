<?php

namespace Tests\Feature\Estiba;

use App\Exceptions\ConflictoOperacion;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Services\Estiba\ServicioDestinosDespeje;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DestinosDespejeReservaPropiaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Solo el esquema de lectura del servicio: no ejecuta migraciones de planta.
        config(['database.default' => 'despeje_test', 'database.connections.despeje_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        foreach ([
            'camaras' => 'id TEXT PRIMARY KEY, estado TEXT, contenido TEXT, cantidad_bandas INTEGER, posiciones_por_banda INTEGER, cantidad_niveles INTEGER',
            'bandas_operacionales' => 'id TEXT PRIMARY KEY, camara_id TEXT, numero INTEGER, modo TEXT, usos_permitidos TEXT',
            'posiciones' => 'id TEXT PRIMARY KEY, camara_id TEXT, banda INTEGER, posicion INTEGER, nivel INTEGER, estado TEXT',
            'ubicaciones_actuales' => 'id TEXT PRIMARY KEY, posicion_id TEXT, folio_id TEXT',
            'folios' => 'id TEXT PRIMARY KEY, activo INTEGER, tipo_bulto TEXT',
            'reservas_tareas_movimiento' => 'id TEXT PRIMARY KEY, tarea_movimiento_id TEXT, bloqueo_posicion_id TEXT, estado TEXT, vence_at TEXT',
            'tareas_movimiento' => 'id TEXT PRIMARY KEY, estado TEXT, maniobra_operacional_id TEXT',
            'maniobras_operacionales' => 'id TEXT PRIMARY KEY',
            'custodias_temporales_maniobra' => 'id TEXT PRIMARY KEY, maniobra_operacional_id TEXT, estado TEXT',
            'reservas_posiciones_inspeccion_sag' => 'id TEXT PRIMARY KEY, clave_bloqueo TEXT',
            'reservas_bandas_maniobra' => 'id TEXT PRIMARY KEY, camara_id TEXT, maniobra_operacional_id TEXT, banda INTEGER, nivel INTEGER, clave_bloqueo TEXT',
        ] as $tabla => $columnas) {
            DB::statement("CREATE TABLE {$tabla} ({$columnas})");
        }
        DB::table('camaras')->insert(['id' => 'cam5', 'estado' => 'activa', 'contenido' => 'productos',
            'cantidad_bandas' => 2, 'posiciones_por_banda' => 3, 'cantidad_niveles' => 1]);
        foreach ([1, 2] as $banda) {
            DB::table('bandas_operacionales')->insert(['id' => 'banda'.$banda, 'camara_id' => 'cam5',
                'numero' => $banda, 'modo' => 'operativa', 'usos_permitidos' => '["transito_pt"]']);
        }
        foreach (['origen' => 1, 'destino' => 2] as $id => $banda) {
            DB::table('posiciones')->insert(['id' => $id, 'camara_id' => 'cam5', 'banda' => $banda,
                'posicion' => 1, 'nivel' => 1, 'estado' => 'activa']);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('despeje_test');
        parent::tearDown();
    }

    private function tarea(): TareaMovimiento
    {
        $tarea = new TareaMovimiento(['estado' => 'asumida', 'camara_origen_id' => 'cam5',
            'posicion_origen_id' => 'origen', 'contexto' => ['tipo_decision' => 'despeje_salida_directa']]);
        $tarea->id = 'tarea-propia';

        return $tarea;
    }

    private function reservar(string $tarea): void
    {
        DB::table('reservas_tareas_movimiento')->insert(['id' => 'reserva', 'tarea_movimiento_id' => $tarea,
            'bloqueo_posicion_id' => 'destino', 'estado' => 'activa', 'vence_at' => now()->addMinutes(10)->toDateTimeString()]);
    }

    public function test_el_destino_local_reservado_por_la_misma_tarea_se_puede_validar_para_iniciar(): void
    {
        $this->reservar('tarea-propia');
        app(ServicioDestinosDespeje::class)->validar($this->tarea(), Posicion::findOrFail('destino'));
        $this->assertSame('tarea-propia', DB::table('reservas_tareas_movimiento')->value('tarea_movimiento_id'));
    }

    public function test_la_reserva_de_otra_tarea_sigue_impidiendo_usar_la_posicion(): void
    {
        $this->reservar('tarea-ajena');
        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('La posición local no está disponible');
        app(ServicioDestinosDespeje::class)->validar($this->tarea(), Posicion::findOrFail('destino'));
    }

    public function test_una_reserva_propia_no_omite_la_ocupacion_fisica_del_destino(): void
    {
        $this->reservar('tarea-propia');
        DB::table('folios')->insert(['id' => 'otro-pallet', 'activo' => 1, 'tipo_bulto' => 'pallet']);
        DB::table('ubicaciones_actuales')->insert(['id' => 'ubicacion', 'posicion_id' => 'destino', 'folio_id' => 'otro-pallet']);
        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('La posición local no está disponible');
        app(ServicioDestinosDespeje::class)->validar($this->tarea(), Posicion::findOrFail('destino'));
    }

    public function test_una_reserva_propia_no_omite_el_bloqueo_sag_del_destino(): void
    {
        $this->reservar('tarea-propia');
        DB::table('reservas_posiciones_inspeccion_sag')->insert(['id' => 'reserva-sag', 'clave_bloqueo' => 'destino']);
        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('La posición local no está disponible');
        app(ServicioDestinosDespeje::class)->validar($this->tarea(), Posicion::findOrFail('destino'));
    }
}
