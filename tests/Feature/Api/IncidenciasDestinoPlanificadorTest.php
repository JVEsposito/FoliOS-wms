<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\IncidenciaDestinoPlanificador;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Planificador\ServicioSaludPlanificador;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los destinos rechazados y los pallets sin posición quedan registrados para Oficina, en vez de
 * conocerse solo cuando alguien lo informa desde la planta.
 */
class IncidenciasDestinoPlanificadorTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private Camara $camara;

    /** @var array<int, Posicion> */
    private array $posiciones = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.generacion_automatica' => true,
            'planificador.frontier_max' => 4,
            'planificador.maniobras_simultaneas_max' => 3,
        ]);
        Temporada::query()->update(['activa' => false]);
        $this->temporada = Temporada::create(['codigo' => 'TEMP-INCIDENCIAS', 'nombre' => 'Temporada incidencias', 'fecha_inicio' => '2026-09-01', 'activa' => true]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $this->camara = Camara::create(['codigo' => 'CAM-INCIDENCIAS', 'nombre' => 'Cámara de tránsito 03', 'cantidad_bandas' => 1, 'posiciones_por_banda' => 4, 'cantidad_niveles' => 1]);
        app(ServicioBandasOperacionales::class)->sincronizar($this->camara->refresh(), $supervisor);
        foreach (range(1, 4) as $posicion) {
            $this->posiciones[$posicion] = Posicion::create([
                'camara_id' => $this->camara->id, 'banda' => 1, 'posicion' => $posicion, 'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $posicion),
            ]);
        }
    }

    public function test_un_destino_rechazado_queda_registrado_y_se_agrupa_si_se_repite(): void
    {
        [$tarea, $token] = $this->tareaTomada('A');
        $ocupante = $this->folio('INC-OCUPA');
        UbicacionActual::withoutEvents(fn () => UbicacionActual::create([
            'folio_id' => $ocupante->id, 'camara_id' => $this->camara->id, 'posicion_id' => $this->posiciones[1]->id, 'ubicado_at' => now(),
        ]));

        foreach ([1, 2] as $intento) {
            $this->proponer($token, $tarea, $this->posiciones[1])
                ->assertOk()
                ->assertJsonCount(1, 'data.rechazadas')
                ->assertJsonPath('data.rechazadas.0.motivo', 'La posición propuesta ya se encuentra ocupada.');
        }

        $incidencia = IncidenciaDestinoPlanificador::query()->sole();
        $this->assertSame(IncidenciaDestinoPlanificador::ORIGEN_RECHAZO_SERVIDOR, $incidencia->origen);
        $this->assertSame(2, $incidencia->repeticiones);
        $this->assertSame('rolling-prueba-7', $incidencia->planner_version);
        $this->assertSame($this->posiciones[1]->id, $incidencia->posicion_id);
        $this->assertSame($this->camara->id, $incidencia->camara_id);
        $this->assertSame($tarea->folio_id, $incidencia->folio_id);
    }

    public function test_la_tablet_informa_que_no_encontro_destino_y_salud_lo_advierte(): void
    {
        [$tarea, $token] = $this->tareaTomada('A');
        [, $otroToken] = $this->tareaTomada('B');

        foreach (range(1, 3) as $intento) {
            $this->withToken($token)->postJson('/api/frontera-fisica/sin-destino', [
                'tarea_id' => $tarea->id, 'planner_version' => 'rolling-prueba-7', 'camaras_evaluadas' => 2,
            ])->assertStatus(202)->assertJsonPath('data.registrada', true)->assertJsonPath('data.repeticiones', $intento);
            $this->app['auth']->forgetGuards();
        }
        // Solo quien tiene tomada la tarea puede informar por ella.
        $this->withToken($otroToken)->postJson('/api/frontera-fisica/sin-destino', [
            'tarea_id' => $tarea->id, 'planner_version' => 'rolling-prueba-7',
        ])->assertStatus(409);

        $incidencia = IncidenciaDestinoPlanificador::query()->sole();
        $this->assertSame(IncidenciaDestinoPlanificador::ORIGEN_SIN_DESTINO_TABLET, $incidencia->origen);
        $this->assertSame('La tablet no encontró una posición libre y compatible. Cámaras evaluadas: 2.', $incidencia->motivo);

        $salud = app(ServicioSaludPlanificador::class);
        $this->assertSame(1, $salud->saludActual($this->temporada->id)['riesgos']['destinos_rechazados_repetidos']);
        $this->assertSame('advertencia', $salud->saludActual($this->temporada->id)['estado']);
        $metricas = $salud->snapshot(CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addMinute())['metricas']['destinos'];
        $this->assertSame(['sin_destino_tablet' => 3], $metricas['por_origen']);
        $this->assertSame(1, $metricas['tareas_afectadas']);
        $this->assertSame(3, $metricas['motivos_frecuentes'][0]['repeticiones']);
    }

    /** @return array{TareaMovimiento, string} */
    private function tareaTomada(string $sufijo): array
    {
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-INC-'.$sufijo, 'nombre' => 'Tablet '.$sufijo]);
        $token = $camarero->crearTokenParaDispositivo($dispositivo, 'incidencias-'.$sufijo)->plainTextToken;
        $supervisor = User::query()->where('rol', RolUsuario::SupervisorFrio)->firstOrFail();
        $tarea = app(ServicioPlanesOperacionales::class)->crear(
            temporada: $this->temporada,
            tipo: TipoPlanOperacional::RecepcionTunel,
            titulo: 'Recibir túnel '.$sufijo,
            creadoPor: $supervisor,
            tareas: [['folio_id' => $this->folio('INC-'.$sufijo)->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]],
            contexto: ['planner_horizon' => 'rolling'],
        )->tareas()->sole();
        $this->actualizarArbitraje();
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $camarero, $dispositivo);
        $this->actualizarArbitraje();

        return [$tarea->refresh(), $token];
    }

    private function proponer(string $token, TareaMovimiento $tarea, Posicion $posicion)
    {
        $this->app['auth']->forgetGuards();
        $snapshot = $this->withToken($token)->getJson('/api/frontera-fisica/snapshot')->assertOk()->json('data');
        $propia = collect($snapshot['tareas'])->firstWhere('id', $tarea->id);
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/api/frontera-fisica/materializar', [
            'snapshot_version' => $snapshot['snapshot_version'],
            'planner_version' => 'rolling-prueba-7',
            'propuestas' => [[
                'tarea_id' => $tarea->id, 'posicion_destino_id' => $posicion->id,
                'tarea_version' => $propia['version'], 'plan_version' => $propia['plan_version'],
                'version_camara_conocida' => $this->camara->refresh()->version_plano,
            ]],
        ]);
    }

    private function folio(string $numero): Folio
    {
        return Folio::create(['temporada_id' => $this->temporada->id, 'numero_folio' => $numero, 'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now()]);
    }

    private function actualizarArbitraje(): void
    {
        app(ServicioEstadoArbitrajePlanificador::class)->solicitar($this->temporada, 'prueba_incidencias');
        RecalcularArbitrajePlanificador::dispatchSync($this->temporada->id);
    }
}
