<?php

namespace Tests\Feature\Api;

use App\Enums\AccionResolucionDiscrepancia;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\DiscrepanciaManiobra;
use App\Models\Dispositivo;
use App\Models\EstadoArbitrajePlanificador;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\ReservaTareaMovimiento;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Observers\SolicitarArbitrajePlanificadorObserver;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Planificador\ElegibilidadFolioPlanificador;
use App\Services\Planificador\ServicioArbitrajeManiobras;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Planificador\ServicioFronteraFisica;
use App\Services\Planificador\ServicioPuestoMandoPlanificador;
use App\Services\Planificador\ServicioSaludPlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlanificadorFoliosDespachadosTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private User $camarero;

    private Dispositivo $dispositivo;

    private Temporada $temporada;

    private Posicion $posicion;

    private int $indice = 0;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RecalcularArbitrajePlanificador::class]);
        config(['planificador.mode' => 'off', 'planificador.compute' => 'server', 'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $this->temporada = Temporada::where('activa', true)->sole();
        $this->supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $this->camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $this->dispositivo = Dispositivo::create(['codigo' => 'TABLET-NO-DESPACHADOS', 'nombre' => 'Tablet']);
        $this->token = $this->camarero->crearTokenParaDispositivo($this->dispositivo, 'prueba')->plainTextToken;
        $camara = Camara::create(['codigo' => 'CAM-NO-DESPACHADOS', 'nombre' => 'Cámara operativa', 'contenido' => 'productos',
            'estado' => 'activa', 'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        BandaOperacional::create(['camara_id' => $camara->id, 'numero' => 1, 'modo' => 'operativa', 'usos_permitidos' => ['transito_pt']]);
        $this->posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
    }

    public function test_no_crea_tareas_para_folios_terminales_aunque_conserven_activo_true(): void
    {
        foreach (ElegibilidadFolioPlanificador::TERMINALES as $estado) {
            $folio = $this->folio();
            $folio->update(['estado_operacional' => $estado, 'activo' => true]);
            try {
                $this->plan($folio);
                $this->fail('No debe crearse una tarea para '.$estado);
            } catch (\DomainException $e) {
                $this->assertStringContainsString('activos', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('tareas_movimiento', 0);
        $this->assertDatabaseCount('planes_operacionales', 0);
    }

    public function test_todos_los_tipos_de_plan_excluyen_folios_despachados_del_arbitraje(): void
    {
        foreach (TipoPlanOperacional::cases() as $tipo) {
            $folio = $this->folio();
            $this->plan($folio, $tipo);
            $folio->update(['estado_operacional' => 'despachado', 'activo' => true]);
        }
        config(['planificador.mode' => 'guided']);
        $ciclo = app(ServicioArbitrajeManiobras::class)->arbitrar($this->temporada);
        $this->assertCount(0, $ciclo->decisiones);
        $this->assertSame(count(TipoPlanOperacional::cases()), TareaMovimiento::where('estado', 'pendiente')->count());
    }

    public function test_ciclo_anterior_y_bandejas_no_publican_un_folio_que_fue_despachado_despues(): void
    {
        $f = $this->folio();
        $plan = $this->plan($f);
        $tarea = $plan->tareas->first();
        config(['planificador.mode' => 'guided']);
        $arbitraje = app(ServicioArbitrajeManiobras::class);
        $ciclo = $arbitraje->arbitrar($this->temporada);
        $this->assertCount(1, $ciclo->decisiones);
        $estado = app(ServicioEstadoArbitrajePlanificador::class);
        $estado->solicitar($this->temporada, 'prueba');
        $version = $estado->iniciarCalculo($this->temporada->id);
        $estado->completarCalculo($this->temporada->id, $version, $ciclo, 1);
        $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
        $this->assertCount(0, $arbitraje->idsPublicables($ciclo));
        $puesto = app(ServicioPuestoMandoPlanificador::class)->obtener($this->temporada);
        $this->assertSame([], $puesto['arbitraje']['ciclo']['decisiones']);
        config(['planificador.mode' => 'off']);
        $this->withToken($this->token)->getJson('/api/tareas-movimiento?asignacion=disponibles')->assertOk()->assertJsonCount(0, 'data');
        $snapshot = app(ServicioPlanesOperacionales::class)->snapshot($plan);
        $this->assertCount(0, $snapshot['tareas']);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $tarea->refresh()->estado);
    }

    public function test_una_tablet_con_tarea_antigua_no_puede_asumir_en_ningun_modo(): void
    {
        $f = $this->folio();
        $tarea = $this->plan($f)->tareas->first();
        $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
        foreach (['off', 'shadow', 'guided'] as $modo) {
            config(['planificador.mode' => $modo]);
            $this->withToken($this->token)->postJson('/api/tareas-movimiento/'.$tarea->id.'/asumir')->assertConflict();
        }
        $this->assertNull($tarea->refresh()->responsable_user_id);
        $this->assertDatabaseCount('reservas_tareas_movimiento', 0);
    }

    public function test_un_claim_anterior_al_despacho_no_puede_renovarse_iniciarse_materializarse_ni_confirmarse(): void
    {
        $f = $this->folio();
        $plan = $this->plan($f);
        $tarea = $plan->tareas->first();
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $this->camarero, $this->dispositivo);
        $tarea->update(['estado' => EstadoTareaMovimiento::EnProceso]);
        $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
        $reservas = app(ServicioReservasTareasMovimiento::class);
        foreach (['renovar', 'iniciar', 'materializarDestino', 'validarParaMovimiento'] as $metodo) {
            try {
                DB::transaction(fn () => match ($metodo) {
                    'materializarDestino' => $reservas->$metodo($tarea, $this->posicion, $this->camarero, $this->dispositivo),
                    'validarParaMovimiento' => $reservas->$metodo($tarea, $f, TipoMovimiento::UbicacionInicial, null, $this->posicion->id, $this->camarero, $this->dispositivo),
                    default => $reservas->$metodo($tarea, $this->camarero, $this->dispositivo),
                });
                $this->fail('No debe permitirse '.$metodo);
            } catch (ConflictoOperacion $e) {
                $this->assertStringContainsString('despachado', $e->getMessage());
            }
        }
        $snapshot = app(ServicioFronteraFisica::class)->snapshot($this->camarero, $this->dispositivo);
        $this->assertCount(0, $snapshot['tareas']);
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_depura_repa_y_reordenamientos_incluyendo_en_proceso_y_libera_reservas_y_sesion(): void
    {
        foreach ([TipoPlanOperacional::RecepcionRepaletizaje, TipoPlanOperacional::ReordenamientoCamara] as $tipo) {
            $f = $this->folio();
            $tarea = $this->plan($f, $tipo)->tareas->first();
            $this->comprometer($tarea);
            $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
            $this->assertSame(1, app(ServicioDepuracionIngresosCamara::class)->depurarFolio($f->id));
            $this->assertSame(EstadoTareaMovimiento::Cancelada, $tarea->refresh()->estado);
            $this->assertNull($tarea->reservas()->sole()->bloqueo_tarea_id);
            $this->assertNull($tarea->reservas()->sole()->bloqueo_posicion_id);
            $this->assertSame('cerrada', SesionEstiba::where('tarea_movimiento_id', $tarea->id)->sole()->estado->value);
            $this->assertNull($tarea->maniobraOperacional->reservasBandas()->sole()->clave_bloqueo);
        }
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_el_worker_tambien_repara_tareas_heredadas_sin_maniobra(): void
    {
        $f = $this->folio();
        $tarea = $this->plan($f)->tareas->first();
        $tarea->update(['maniobra_operacional_id' => null, 'estado' => 'en_proceso']);
        $f->update(['estado_operacional' => 'despachado', 'activo' => true]);
        config(['planificador.mode' => 'guided']);
        app()->call([new RecalcularArbitrajePlanificador($this->temporada->id), 'handle']);
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $tarea->refresh()->estado);
        $this->assertStringContainsString('despachado', $tarea->motivo_cancelacion);
    }

    public function test_no_abandona_custodias_de_otro_pallet_y_lo_informa_como_incidencia_no_ejecutable(): void
    {
        $f = $this->folio();
        $tarea = $this->plan($f)->tareas->first();
        $aux = $this->folio();
        $tarea->update(['secuencia_maniobra' => 2]);
        $tarea->maniobraOperacional->update(['estado' => 'en_ejecucion', 'secuencia_actual' => 2, 'costo_movimientos' => 3,
            'responsable_user_id' => $this->camarero->id, 'dispositivo_id' => $this->dispositivo->id]);
        $extraccion = TareaMovimiento::create(['plan_operacional_id' => $tarea->plan_operacional_id, 'maniobra_operacional_id' => $tarea->maniobra_operacional_id,
            'folio_id' => $aux->id, 'secuencia' => 2, 'secuencia_maniobra' => 1, 'estado' => 'completada', 'prioridad' => 'normal',
            'tipo_movimiento' => 'retiro', 'tipo_paso_maniobra' => 'extraccion_temporal', 'camara_origen_id' => $this->posicion->camara_id,
            'posicion_origen_id' => $this->posicion->id, 'completada_at' => now()]);
        TareaMovimiento::create(['plan_operacional_id' => $tarea->plan_operacional_id, 'maniobra_operacional_id' => $tarea->maniobra_operacional_id,
            'folio_id' => $aux->id, 'secuencia' => 3, 'secuencia_maniobra' => 3, 'estado' => 'bloqueada', 'prioridad' => 'normal',
            'tipo_movimiento' => 'ubicacion_inicial', 'tipo_paso_maniobra' => 'retorno_banda', 'camara_destino_id' => $this->posicion->camara_id,
            'posicion_destino_id' => $this->posicion->id, 'contexto' => ['camara_retorno_id' => $this->posicion->camara_id,
                'banda_retorno' => 1, 'nivel_retorno' => 1, 'profundidad_resultante' => 1]]);
        $custodia = CustodiaTemporalManiobra::create(['maniobra_operacional_id' => $tarea->maniobra_operacional_id, 'folio_id' => $aux->id,
            'tarea_extraccion_id' => $extraccion->id, 'camara_origen_id' => $this->posicion->camara_id, 'posicion_origen_id' => $this->posicion->id,
            'banda_origen' => 1, 'posicion_origen' => 1, 'nivel_origen' => 1, 'estado' => 'activa', 'bloqueo_folio_id' => $aux->id,
            'user_id' => $this->camarero->id, 'dispositivo_id' => $this->dispositivo->id, 'extraido_at' => now()]);
        $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
        $this->assertSame(0, app(ServicioDepuracionIngresosCamara::class)->depurarFolio($f->id));
        $this->assertSame('activa', $custodia->refresh()->estado->value);
        $this->assertSame(EstadoTareaMovimiento::Bloqueada, $tarea->refresh()->estado);
        $this->withToken($this->token)->getJson('/api/tareas-movimiento?asignacion=disponibles')->assertOk()->assertJsonCount(0, 'data');
        $salud = app(ServicioSaludPlanificador::class)->saludActual($this->temporada->id);
        $this->assertSame(1, $salud['riesgos']['folios_no_operables_con_tareas']);
        $this->assertSame('critico', $salud['estado']);
        $incidencia = DiscrepanciaManiobra::where('maniobra_operacional_id', $tarea->maniobra_operacional_id)->sole();
        $this->assertSame('folio_fuera_operacion', $incidencia->tipo);
        $this->assertSame(0, app(ServicioDepuracionIngresosCamara::class)->depurarFolio($f->id));
        $this->assertDatabaseCount('discrepancias_maniobra', 1);
        app(ServicioManiobrasOperacionales::class)->resolverDiscrepancia($incidencia, $this->supervisor,
            AccionResolucionDiscrepancia::RetornoSeguro, $tarea->maniobraOperacional()->sole()->version, 'Retornar el pallet de custodia a su posición segura.');
        $this->assertSame('activa', $custodia->refresh()->estado->value);
        $this->assertNotSame($tarea->maniobra_operacional_id, $custodia->maniobra_operacional_id);
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $tarea->refresh()->estado);
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_un_retorno_legitimo_rehabilita_el_folio_y_no_se_depura_por_un_despacho_pasado(): void
    {
        $f = $this->folio();
        $tarea = $this->plan($f, TipoPlanOperacional::ReordenamientoCamara)->tareas->first();
        $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
        $f->update(['estado_operacional' => 'pendiente_ubicacion', 'activo' => true]);
        $this->assertSame(0, app(ServicioDepuracionIngresosCamara::class)->depurarFolio($f->id));
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $tarea->refresh()->estado);
        $this->withToken($this->token)->getJson('/api/tareas-movimiento?asignacion=disponibles')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_los_cambios_de_estado_del_folio_invalidan_el_ciclo_pero_los_datos_comerciales_no(): void
    {
        $f = $this->folio()->fresh();
        config(['planificador.mode' => 'guided']);
        $estado = app(ServicioEstadoArbitrajePlanificador::class);
        $estado->solicitar($this->temporada, 'prueba');
        $antes = EstadoArbitrajePlanificador::findOrFail($this->temporada->id)->version_solicitada;
        $f->update(['estado_operacional' => 'despachado', 'activo' => false]);
        app(SolicitarArbitrajePlanificadorObserver::class)->saved($f);
        $despues = EstadoArbitrajePlanificador::findOrFail($this->temporada->id)->version_solicitada;
        $this->assertGreaterThan($antes, $despues);
        $f->update(['marca' => 'Otra marca']);
        app(SolicitarArbitrajePlanificadorObserver::class)->saved($f);
        $this->assertSame($despues, EstadoArbitrajePlanificador::findOrFail($this->temporada->id)->version_solicitada);
    }

    private function folio(): Folio
    {
        return Folio::create(['numero_folio' => 'NO-DESP-'.(++$this->indice), 'tipo_bulto' => TipoBulto::Pallet, 'estado_operacional' => 'pendiente_ubicacion',
            'activo' => true, 'fecha_ingreso' => now()]);
    }

    private function plan(Folio $folio, TipoPlanOperacional $tipo = TipoPlanOperacional::AlmacenamientoPallet): PlanOperacional
    {
        return app(ServicioPlanesOperacionales::class)->crear($this->temporada, $tipo, 'Prueba '.$folio->numero_folio, $this->supervisor,
            [['folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]]);
    }

    private function comprometer(TareaMovimiento $tarea): void
    {
        $tarea->update(['estado' => 'en_proceso', 'responsable_user_id' => $this->camarero->id, 'dispositivo_id' => $this->dispositivo->id, 'iniciada_at' => now()]);
        $tarea->maniobraOperacional->update(['estado' => 'en_ejecucion', 'responsable_user_id' => $this->camarero->id, 'dispositivo_id' => $this->dispositivo->id]);
        ReservaTareaMovimiento::create(['tarea_movimiento_id' => $tarea->id, 'bloqueo_tarea_id' => $tarea->id, 'bloqueo_posicion_id' => $this->posicion->id,
            'posicion_destino_id' => $this->posicion->id, 'estado' => 'activa', 'user_id' => $this->camarero->id, 'dispositivo_id' => $this->dispositivo->id,
            'reservada_at' => now(), 'renovada_at' => now(), 'vence_at' => now()->addMinutes(5)]);
        ReservaBandaManiobra::create(['maniobra_operacional_id' => $tarea->maniobra_operacional_id, 'camara_id' => $this->posicion->camara_id,
            'banda' => 1, 'nivel' => 1, 'clave_bloqueo' => $this->posicion->camara_id.':1:1', 'reservada_at' => now()]);
        SesionEstiba::create(['camara_id' => $this->posicion->camara_id, 'user_id' => $this->camarero->id, 'dispositivo_id' => $this->dispositivo->id,
            'tarea_movimiento_id' => $tarea->id, 'estado' => 'abierta', 'iniciada_at' => now(), 'ultima_actividad_at' => now(), 'version_inicial' => 0]);
    }
}
