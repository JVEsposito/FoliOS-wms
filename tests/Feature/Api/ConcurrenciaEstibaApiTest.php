<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\SesionEstiba;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrenciaEstibaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true,
            'planificador.maniobras_simultaneas_max' => 3]);
    }

    public function test_dos_tablets_completan_en_la_misma_camara_con_versiones_anteriores_y_sesiones_independientes(): void
    {
        $c = $this->contexto();
        // Reproduce la captura: un acceso manual conserva su sesión de cámara.
        $manual = app(ServicioSesionEstiba::class)->abrir($c['camara'], $c['supervisor'], $c['dispositivos'][2]);
        $snapshots = [$this->snapshot($c, 0), $this->snapshot($c, 1)];
        $this->materializar($c, 0, $snapshots[0])->assertJsonCount(1, 'data.aceptadas');
        $this->materializar($c, 1, $snapshots[1])->assertJsonCount(1, 'data.aceptadas');
        $sesiones = [];
        foreach ([0, 1] as $i) {
            $sesiones[$i] = $this->token($c['tokens'][$i])->postJson('/api/camaras/'.$c['camara']->id.'/sesiones', [
                'tarea_movimiento_id' => $c['tareas'][$i]->id,
            ])->assertCreated()->assertJsonPath('data.alcance', 'tarea')->json('data.id');
            $this->token($c['tokens'][$i])->postJson('/api/camaras/'.$c['camara']->id.'/sesiones', [
                'tarea_movimiento_id' => $c['tareas'][$i]->id,
            ])->assertCreated()->assertJsonPath('data.id', $sesiones[$i]);
            app(ServicioPlanesOperacionales::class)->iniciar($c['tareas'][$i]->refresh(), $c['usuarios'][$i], $c['dispositivos'][$i]);
        }
        $this->assertDatabaseCount('bloqueos_camara', 1);
        $version = $c['camara']->refresh()->version_plano;
        foreach ([0, 1] as $i) {
            $operacion = (string) Str::uuid();
            $servicio = app(ServicioMovimientoEstiba::class);
            $sesion = SesionEstiba::findOrFail($sesiones[$i]);
            $movimiento = $servicio->ubicar($operacion, $c['folios'][$i]->numero_folio, TipoBulto::Pallet,
                $c['posiciones'][$i], $sesion, $c['usuarios'][$i], $c['dispositivos'][$i], $version, now(),
                tareaMovimiento: $c['tareas'][$i]->refresh());
            $reintento = $servicio->ubicar($operacion, $c['folios'][$i]->numero_folio, TipoBulto::Pallet,
                $c['posiciones'][$i], $sesion, $c['usuarios'][$i], $c['dispositivos'][$i], $version, $movimiento->generado_dispositivo_at,
                tareaMovimiento: $c['tareas'][$i]->refresh());
            $this->assertSame($movimiento->id, $reintento->id);
            $this->assertDatabaseHas('sesiones_estiba', ['id' => $sesion->id, 'estado' => 'cerrada', 'clave_tarea_activa' => null]);
            $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $c['folios'][$i]->id, 'posicion_id' => $c['posiciones'][$i]->id]);
        }
        $this->assertSame($version + 2, $c['camara']->refresh()->version_plano);
        $this->assertSame('abierta', $manual->refresh()->estado->value);
        $this->assertDatabaseCount('movimientos', 2);
        $this->assertDatabaseCount('ubicaciones_actuales', 2);
    }

    public function test_reintento_de_respuesta_perdida_recupera_el_destino_sin_reservarlo_otra_vez(): void
    {
        $c = $this->contexto();
        $snapshot = $this->snapshot($c, 0);
        $this->materializar($c, 0, $snapshot)->assertJsonCount(1, 'data.aceptadas');
        $version = $c['tareas'][0]->refresh()->version;
        $this->materializar($c, 0, $snapshot)->assertJsonCount(1, 'data.aceptadas')
            ->assertJsonPath('data.aceptadas.0.tarea.plan.id', $c['tareas'][0]->plan_operacional_id);
        $this->assertSame($version, $c['tareas'][0]->refresh()->version);
    }

    public function test_competir_por_un_destino_no_duplica_la_reserva(): void
    {
        $c = $this->contexto();
        $a = $this->snapshot($c, 0);
        $b = $this->snapshot($c, 1);
        $this->materializar($c, 0, $a)->assertJsonCount(1, 'data.aceptadas');
        $this->materializar($c, 1, $b, $c['posiciones'][0])->assertJsonCount(0, 'data.aceptadas')
            ->assertJsonCount(1, 'data.rechazadas');
        $this->assertNull($c['tareas'][1]->refresh()->posicion_destino_id);
    }

    public function test_una_sesion_de_tarea_no_puede_usarse_como_acceso_manual_ni_abrirse_por_otro_actor(): void
    {
        $c = $this->contexto();
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $ruta = '/api/camaras/'.$c['camara']->id.'/sesiones';
        $this->token($c['tokens'][1])->postJson($ruta, ['tarea_movimiento_id' => $c['tareas'][0]->id])->assertConflict();
        $id = $this->token($c['tokens'][0])->postJson($ruta, ['tarea_movimiento_id' => $c['tareas'][0]->id])
            ->assertCreated()->json('data.id');
        try {
            app(ServicioMovimientoEstiba::class)->ubicar((string) Str::uuid(), $c['folios'][2]->numero_folio,
                TipoBulto::Pallet, $c['posiciones'][2], SesionEstiba::findOrFail($id), $c['usuarios'][0], $c['dispositivos'][0],
                $c['camara']->refresh()->version_plano, now());
            $this->fail('La sesión de tarea no debe autorizar un movimiento manual.');
        } catch (\DomainException $error) {
            $this->assertStringContainsString('sesión de tarea', $error->getMessage());
        }
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_cerrar_sesion_no_libera_recursos_de_un_pallet_en_movimiento(): void
    {
        $c = $this->contexto();
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $id = $this->token($c['tokens'][0])->postJson('/api/camaras/'.$c['camara']->id.'/sesiones', [
            'tarea_movimiento_id' => $c['tareas'][0]->id,
        ])->assertCreated()->json('data.id');
        app(ServicioPlanesOperacionales::class)->iniciar($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);
        $this->token($c['tokens'][0])->postJson('/api/sesiones/'.$id.'/cerrar', ['motivo' => 'Cerrar pantalla'])->assertConflict();
        $this->assertDatabaseHas('sesiones_estiba', ['id' => $id, 'estado' => 'abierta']);
        $this->assertDatabaseHas('reservas_bandas_maniobra', ['sesion_estiba_id' => $id, 'liberada_at' => null]);
    }

    public function test_liberar_antes_de_iniciar_cierra_sesion_y_deja_la_banda_disponible(): void
    {
        $c = $this->contexto();
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $sesion = app(ServicioSesionEstiba::class)->abrirParaTarea($c['camara'], $c['usuarios'][0], $c['dispositivos'][0], $c['tareas'][0]);
        app(ServicioPlanesOperacionales::class)->liberar($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);
        $this->assertSame('cerrada', $sesion->refresh()->estado->value);
        $this->assertDatabaseMissing('reservas_bandas_maniobra', ['sesion_estiba_id' => $sesion->id, 'liberada_at' => null]);
    }

    public function test_un_movimiento_ajeno_no_invalida_un_destino_independiente_del_snapshot_anterior(): void
    {
        $c = $this->contexto();
        $anterior = $this->snapshot($c, 1);
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $sesion = app(ServicioSesionEstiba::class)->abrirParaTarea($c['camara'], $c['usuarios'][0], $c['dispositivos'][0], $c['tareas'][0]);
        app(ServicioPlanesOperacionales::class)->iniciar($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);
        app(ServicioMovimientoEstiba::class)->ubicar((string) Str::uuid(), $c['folios'][0]->numero_folio, TipoBulto::Pallet,
            $c['posiciones'][0], $sesion, $c['usuarios'][0], $c['dispositivos'][0], $c['camara']->refresh()->version_plano, now(),
            tareaMovimiento: $c['tareas'][0]->refresh());
        $this->materializar($c, 1, $anterior)->assertJsonCount(1, 'data.aceptadas');
        $this->assertSame($c['posiciones'][1]->id, $c['tareas'][1]->refresh()->posicion_destino_id);
    }

    public function test_otra_maniobra_en_la_misma_banda_impide_abrir_la_sesion_sin_afectar_otras_bandas(): void
    {
        $c = $this->contexto();
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        ReservaBandaManiobra::create(['maniobra_operacional_id' => $c['tareas'][1]->maniobra_operacional_id,
            'camara_id' => $c['camara']->id, 'banda' => 1, 'nivel' => 1,
            'clave_bloqueo' => $c['camara']->id.':1:1', 'reservada_at' => now()]);
        $this->token($c['tokens'][0])->postJson('/api/camaras/'.$c['camara']->id.'/sesiones', [
            'tarea_movimiento_id' => $c['tareas'][0]->id,
        ])->assertConflict();
        $this->assertDatabaseMissing('sesiones_estiba', ['tarea_movimiento_id' => $c['tareas'][0]->id]);
    }

    public function test_retomar_labor_con_tres_sesiones_cerradas_no_reactiva_las_reservas_historicas_de_banda(): void
    {
        $c = $this->contexto();
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $sesiones = app(ServicioSesionEstiba::class);
        for ($i = 0; $i < 3; $i++) {
            $sesion = $sesiones->abrirParaTarea($c['camara'], $c['usuarios'][0], $c['dispositivos'][0], $c['tareas'][0]->refresh());
            $sesiones->cerrar($sesion, $c['usuarios'][0], 'Destino revisado antes del retiro.');
        }
        $consulta = fn () => ReservaBandaManiobra::where('maniobra_operacional_id', $c['tareas'][0]->maniobra_operacional_id)->orderBy('id');
        $historial = $consulta()->get()->toArray();
        $this->assertCount(3, $historial);
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->liberar($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);

        $planes->asumir($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);

        $this->assertSame($historial, $consulta()->get()->toArray());
        $this->assertSame('asumida', $c['tareas'][0]->refresh()->estado->value);
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $sesion = $sesiones->abrirParaTarea($c['camara'], $c['usuarios'][0], $c['dispositivos'][0], $c['tareas'][0]->refresh());
        $this->assertSame(1, $consulta()->whereNotNull('clave_bloqueo')->count());
        $this->assertSame($sesion->id, $consulta()->whereNotNull('clave_bloqueo')->sole()->sesion_estiba_id);
        $this->assertSame($historial, $consulta()->where('id', '!=', $consulta()->whereNotNull('clave_bloqueo')->sole()->id)->get()->toArray());

        $planes->iniciar($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);
        app(ServicioMovimientoEstiba::class)->ubicar((string) Str::uuid(), $c['folios'][0]->numero_folio,
            TipoBulto::Pallet, $c['posiciones'][0], $sesion, $c['usuarios'][0], $c['dispositivos'][0],
            $c['camara']->refresh()->version_plano, now(), tareaMovimiento: $c['tareas'][0]->refresh());
        $this->assertSame('completada', $c['tareas'][0]->refresh()->estado->value);
        $this->assertSame(0, $consulta()->whereNotNull('clave_bloqueo')->count());
    }

    public function test_retomar_no_reclama_una_banda_historica_que_ahora_usa_otra_labor(): void
    {
        $c = $this->contexto();
        $this->materializar($c, 0, $this->snapshot($c, 0))->assertJsonCount(1, 'data.aceptadas');
        $sesiones = app(ServicioSesionEstiba::class);
        $sesion = $sesiones->abrirParaTarea($c['camara'], $c['usuarios'][0], $c['dispositivos'][0], $c['tareas'][0]->refresh());
        $sesiones->cerrar($sesion, $c['usuarios'][0], 'Revisión previa al retiro.');
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->liberar($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);
        $this->materializar($c, 1, $this->snapshot($c, 1), $c['posiciones'][0])->assertJsonCount(1, 'data.aceptadas');
        $otraSesion = $sesiones->abrirParaTarea($c['camara'], $c['usuarios'][1], $c['dispositivos'][1], $c['tareas'][1]->refresh());

        $planes->asumir($c['tareas'][0]->refresh(), $c['usuarios'][0], $c['dispositivos'][0]);
        $this->materializar($c, 0, $this->snapshot($c, 0), $c['posiciones'][2])->assertJsonCount(1, 'data.aceptadas');
        $sesiones->abrirParaTarea($c['camara'], $c['usuarios'][0], $c['dispositivos'][0], $c['tareas'][0]->refresh());

        $bloqueo = ReservaBandaManiobra::where('clave_bloqueo', $c['camara']->id.':1:1')->sole();
        $this->assertSame($otraSesion->id, $bloqueo->sesion_estiba_id);
        $this->assertSame($c['tareas'][1]->maniobra_operacional_id, $bloqueo->maniobra_operacional_id);
        $this->assertSame('abierta', $otraSesion->refresh()->estado->value);
    }

    private function contexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create(['codigo' => 'CONC', 'nombre' => 'Concurrencia', 'fecha_inicio' => '2026-09-01', 'activa' => true]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camara = Camara::create(['codigo' => 'CAM-CONC', 'nombre' => 'Cámara concurrente', 'cantidad_bandas' => 3, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $supervisor);
        $usuarios = $dispositivos = $tokens = $folios = $posiciones = $tareas = [];
        foreach ([0, 1, 2] as $i) {
            $usuarios[$i] = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
            $dispositivos[$i] = Dispositivo::create(['codigo' => 'CONC-'.$i, 'nombre' => 'Tablet '.$i, 'activo' => true]);
            $tokens[$i] = $usuarios[$i]->crearTokenParaDispositivo($dispositivos[$i], 'concurrencia')->plainTextToken;
            $folios[$i] = Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'CONC-00'.$i, 'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now()]);
            app(ServicioHabilitacionAlmacenamiento::class)->prepararFolioManual($folios[$i], $supervisor);
            $posiciones[$i] = Posicion::create(['camara_id' => $camara->id, 'banda' => $i + 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B'.($i + 1).'-P01']);
            if ($i < 2) {
                $plan = app(ServicioPlanesOperacionales::class)->crear($temporada, TipoPlanOperacional::AlmacenamientoPallet,
                    'Ubicar '.$folios[$i]->numero_folio, $supervisor, tareas: [['folio_id' => $folios[$i]->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]], contexto: ['planner_horizon' => 'rolling']);
                $tareas[$i] = $plan->tareas()->sole();
                app(ServicioPlanesOperacionales::class)->asumir($tareas[$i], $usuarios[$i], $dispositivos[$i]);
            }
        }

        return compact('temporada', 'supervisor', 'camara', 'usuarios', 'dispositivos', 'tokens', 'folios', 'posiciones', 'tareas');
    }

    private function snapshot(array $c, int $i): array
    {
        return $this->token($c['tokens'][$i])->getJson('/api/frontera-fisica/snapshot')->assertOk()->json('data');
    }

    private function materializar(array $c, int $i, array $snapshot, ?Posicion $destino = null)
    {
        $tarea = collect($snapshot['tareas'])->firstWhere('id', $c['tareas'][$i]->id);

        return $this->token($c['tokens'][$i])->postJson('/api/frontera-fisica/materializar', [
            'contrato_recursos' => 'recursos_v1', 'snapshot_version' => $snapshot['snapshot_version'], 'planner_version' => 'test-concurrencia',
            'propuestas' => [['tarea_id' => $tarea['id'], 'tarea_version' => $tarea['version'], 'plan_version' => $tarea['plan_version'],
                'version_camara_conocida' => $snapshot['camaras'][0]['version_plano'], 'posicion_destino_id' => ($destino ?? $c['posiciones'][$i])->id]],
        ]);
    }

    private function token(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }
}
