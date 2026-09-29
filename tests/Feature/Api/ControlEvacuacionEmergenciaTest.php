<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoLoteInspeccionSag;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoRetencionOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ModoBandaOperacional;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoLoteInspeccionSag;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\LoteInspeccionSagFolio;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\RetencionOperacionalFolio;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Camaras\ServicioControlEvacuacionEmergencia;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControlEvacuacionEmergenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_plano_expone_emergencia_activa_y_deja_de_mostrarla_tras_cancelar(): void
    {
        $contexto = $this->crearContexto();
        $camara = $contexto['emergencia'];
        $this->ubicar($contexto, $camara, 1, 1, 'PAL-ESTADO-EMERGENCIA');
        $url = "/api/camaras/{$camara->id}/plano";
        $inicial = $this->withToken($contexto['token'])->getJson($url)
            ->assertOk()->assertJsonPath('data.emergencia', null);
        $etagInicial = $inicial->headers->get('ETag');

        app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $camara, $contexto['supervisor'], 'Incidente que obliga a evacuar.',
        );
        $this->withToken($contexto['token'])->withHeader('If-None-Match', $etagInicial)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.emergencia.motivo', 'Incidente que obliga a evacuar.')
            ->assertJsonPath('data.emergencia.declarada_por', $contexto['supervisor']->name)
            ->assertJsonPath('data.emergencia.pallets_objetivo', 1);

        app(ServicioControlEvacuacionEmergencia::class)->cancelar(
            $camara, $contexto['supervisor'], 'Incidente resuelto.',
        );
        $this->withToken($contexto['token'])->withHeader('If-None-Match', '')->getJson($url)
            ->assertOk()->assertJsonPath('data.emergencia', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
        ]);
    }

    public function test_rollout_fuera_de_la_camara_registra_shadow_sin_bloquear_ni_publicar(): void
    {
        $contexto = $this->crearContexto();
        config([
            'planificador.rollout_camaras' => [$contexto['otra']->codigo],
        ]);

        $plan = app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Simulación fuera del rollout dirigido.',
            $contexto['dispositivo']->id,
        );

        $this->assertSame('programado', $plan->estado->value);
        $this->assertSame('shadow', $plan->contexto['planner_mode']);
        $this->assertFalse($plan->contexto['ingreso_bloqueado']);
        $this->assertDatabaseCount('maniobras_operacionales', 0);
        $this->assertSame(
            0,
            $contexto['emergencia']->bandasOperacionales()
                ->where('modo', ModoBandaOperacional::Bloqueada->value)
                ->count(),
        );
    }

    public function test_guided_incompleto_rechaza_la_emergencia_antes_de_mutar_la_operacion(): void
    {
        $contexto = $this->crearContexto();
        $planNormal = $this->crearPlan($contexto, PrioridadOperacional::Normal);
        $tareaReversible = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::Pendiente,
            PrioridadOperacional::Normal,
            $contexto['otra'],
            $contexto['emergencia'],
        );
        $casos = [
            ['planificador.generacion_automatica' => false],
            ['planificador.compute' => 'server'],
            ['planificador.horizon' => 'batch'],
        ];

        foreach ($casos as $indice => $configuracionInvalida) {
            config(array_merge([
                'planificador.mode' => 'guided',
                'planificador.generacion_automatica' => true,
                'planificador.compute' => 'tablet',
                'planificador.horizon' => 'rolling',
                'planificador.rollout_camaras' => [],
            ], $configuracionInvalida));
            $camara = $indice === 0
                ? $contexto['emergencia']
                : $this->crearCamara($contexto['supervisor'], "CAM-262-INVALIDA-{$indice}");

            $this->withToken($contexto['token'])
                ->postJson("/api/evacuaciones-emergencia/{$camara->id}", [
                    'motivo' => 'La configuración incompleta no debe modificar la operación.',
                ])
                ->assertUnprocessable()
                ->assertJsonPath(
                    'message',
                    'La emergencia dirigida requiere generación automática, cálculo tablet y horizonte rolling.',
                );

            $this->assertDatabaseMissing('planes_operacionales', [
                'referencia_tipo' => ServicioControlEvacuacionEmergencia::REFERENCIA,
                'referencia_id' => $camara->id,
            ]);
            $this->assertTrue($camara->bandasOperacionales()
                ->get()
                ->every(fn ($banda): bool => $banda->modo === ModoBandaOperacional::Operativa));
        }

        $this->assertDatabaseCount('maniobras_operacionales', 0);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $tareaReversible->refresh()->estado);
        $this->assertSame(EstadoPlanOperacional::Programado, $planNormal->refresh()->estado);
    }

    public function test_declara_bloquea_y_antepone_solo_trabajo_reversible_de_menor_prioridad(): void
    {
        $contexto = $this->crearContexto();
        $planNormal = $this->crearPlan($contexto, PrioridadOperacional::Normal);
        $reversible = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::Pendiente,
            PrioridadOperacional::Normal,
            $contexto['otra'],
            $contexto['emergencia'],
        );
        $enProceso = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::EnProceso,
            PrioridadOperacional::Normal,
            $contexto['otra'],
            $contexto['emergencia'],
        );
        $critica = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::Pendiente,
            PrioridadOperacional::Critica,
            $contexto['emergencia'],
            $contexto['otra'],
        );
        $this->ubicarFolio(
            $critica->folio()->firstOrFail(),
            $contexto['emergencia']->posiciones()->where('banda', 1)->where('posicion', 1)->sole(),
        );
        $maniobra = ManiobraOperacional::create([
            'plan_operacional_id' => $planNormal->id,
            'creado_por_user_id' => $contexto['supervisor']->id,
            'estado' => EstadoManiobraOperacional::PausadaDiscrepancia,
            'prioridad' => PrioridadOperacional::Normal,
            'candidate_key' => 'emergencia:discrepancia',
            'titulo' => 'Maniobra con discrepancia',
            'secuencia_actual' => 1,
            'costo_movimientos' => 1,
        ]);
        $conDiscrepancia = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::Pendiente,
            PrioridadOperacional::Normal,
            $contexto['emergencia'],
            $contexto['otra'],
            $maniobra,
        );
        $maniobraCustodia = ManiobraOperacional::create([
            'plan_operacional_id' => $planNormal->id,
            'creado_por_user_id' => $contexto['supervisor']->id,
            'estado' => EstadoManiobraOperacional::EnEjecucion,
            'prioridad' => PrioridadOperacional::Normal,
            'candidate_key' => 'emergencia:custodia',
            'titulo' => 'Maniobra con custodia temporal',
            'secuencia_actual' => 1,
            'costo_movimientos' => 1,
        ]);
        $conCustodia = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::Pendiente,
            PrioridadOperacional::Normal,
            $contexto['emergencia'],
            $contexto['otra'],
            $maniobraCustodia,
        );
        CustodiaTemporalManiobra::create([
            'maniobra_operacional_id' => $maniobraCustodia->id,
            'folio_id' => $conCustodia->folio_id,
            'tarea_extraccion_id' => $conCustodia->id,
            'camara_origen_id' => $contexto['emergencia']->id,
            'posicion_origen_id' => $contexto['emergencia']->posiciones()->firstOrFail()->id,
            'banda_origen' => 1,
            'posicion_origen' => 1,
            'nivel_origen' => 1,
            'estado' => EstadoCustodiaTemporal::Activa,
            'bloqueo_folio_id' => $conCustodia->folio_id,
            'user_id' => $contexto['supervisor']->id,
            'dispositivo_id' => $contexto['dispositivo']->id,
            'extraido_at' => now(),
        ]);

        $respuesta = $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}", [
                'motivo' => 'Fuga de refrigerante detectada en el sector norte.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipo', TipoPlanOperacional::EvacuacionEmergencia->value)
            ->assertJsonPath('data.estado', EstadoPlanOperacional::EnEjecucion->value)
            ->assertJsonPath('data.prioridad', PrioridadOperacional::Critica->value)
            ->assertJsonPath('data.contexto.ingreso_bloqueado', true)
            ->assertJsonPath('data.contexto.genera_destinos', true)
            ->assertJsonPath('data.contexto.genera_tareas', true)
            ->assertJsonPath('data.contexto.tareas_canceladas', 1)
            ->assertJsonPath('data.contexto.total_impedimentos', 4)
            ->assertJsonPath(
                'data.contexto.declarado_desde_dispositivo_id',
                $contexto['dispositivo']->id,
            );

        $this->assertSame(EstadoTareaMovimiento::Cancelada, $reversible->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::EnProceso, $enProceso->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $critica->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $conDiscrepancia->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $conCustodia->refresh()->estado);
        $this->assertEqualsCanonicalizing(
            ['en_proceso', 'prioridad_critica', 'discrepancia', 'custodia_temporal'],
            collect($respuesta->json('data.contexto.impedimentos'))->pluck('motivo')->all(),
        );
        $this->assertNotEmpty($respuesta->json('data.contexto.declarado_at'));
        $this->assertSame(0, PlanOperacional::query()
            ->findOrFail($respuesta->json('data.id'))
            ->tareas()
            ->count());
        $this->assertTrue($contexto['emergencia']->bandasOperacionales()
            ->get()
            ->every(fn ($banda): bool => $banda->modo === ModoBandaOperacional::EnVaciado));

        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}", [
                'motivo' => 'Segundo aviso del mismo incidente.',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.id', $respuesta->json('data.id'));
        $this->assertSame(1, PlanOperacional::query()
            ->where('tipo', TipoPlanOperacional::EvacuacionEmergencia->value)
            ->count());
    }

    public function test_shadow_registra_impacto_sin_bloquear_ni_cancelar_y_off_no_declara(): void
    {
        $contexto = $this->crearContexto();
        $planNormal = $this->crearPlan($contexto, PrioridadOperacional::Normal);
        $tarea = $this->crearTarea(
            $contexto,
            $planNormal,
            EstadoTareaMovimiento::Pendiente,
            PrioridadOperacional::Normal,
            $contexto['otra'],
            $contexto['emergencia'],
        );
        config(['planificador.mode' => 'shadow']);

        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}", [
                'motivo' => 'Simular respuesta ante alarma de temperatura.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.estado', EstadoPlanOperacional::Programado->value)
            ->assertJsonPath('data.contexto.estado_emergencia', 'shadow')
            ->assertJsonPath('data.contexto.ingreso_bloqueado', false)
            ->assertJsonPath('data.contexto.tareas_reversibles_detectadas', 1)
            ->assertJsonPath('data.contexto.tareas_canceladas', 0);

        $this->assertSame(EstadoTareaMovimiento::Pendiente, $tarea->refresh()->estado);
        $this->assertTrue($contexto['emergencia']->bandasOperacionales()
            ->get()
            ->every(fn ($banda): bool => $banda->modo === ModoBandaOperacional::Operativa));

        config(['planificador.mode' => 'off']);
        $otraCamara = $this->crearCamara($contexto['supervisor'], 'CAM-259-OFF');
        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$otraCamara->id}", [
                'motivo' => 'No debe persistirse con el planificador apagado.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertDatabaseMissing('planes_operacionales', [
            'referencia_tipo' => ServicioControlEvacuacionEmergencia::REFERENCIA,
            'referencia_id' => $otraCamara->id,
        ]);
    }

    public function test_cancelacion_restaura_solo_el_estado_de_banda_que_la_emergencia_conserva(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['emergencia'], 1, 1, 'PAL-260-CANCELAR');
        $bandas = $contexto['emergencia']->bandasOperacionales()->get()->values();
        $bandas[0]->update([
            'modo' => ModoBandaOperacional::EnVaciado,
            'motivo_estado' => 'Desocupación programada previa.',
        ]);
        $bandas[1]->update([
            'modo' => ModoBandaOperacional::Bloqueada,
            'motivo_estado' => 'Mantención de puerta.',
        ]);
        $plan = app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Alarma confirmada en la cámara.',
        );
        $bandas[1]->refresh()->update([
            'motivo_estado' => 'Intervención posterior de mantenimiento.',
            'version' => $bandas[1]->version + 1,
        ]);

        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}/cancelar", [
                'motivo' => 'Alarma descartada por el responsable de seguridad.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.estado', EstadoPlanOperacional::Cancelado->value)
            ->assertJsonPath('data.contexto.bandas_restauradas', 1)
            ->assertJsonPath('data.contexto.bandas_no_restauradas', 1)
            ->assertJsonPath(
                'data.contexto.cancelado_desde_dispositivo_id',
                $contexto['dispositivo']->id,
            );

        $this->assertSame(ModoBandaOperacional::EnVaciado, $bandas[0]->refresh()->modo);
        $this->assertSame('Desocupación programada previa.', $bandas[0]->motivo_estado);
        $this->assertSame(ModoBandaOperacional::Bloqueada, $bandas[1]->refresh()->modo);
        $this->assertSame('Intervención posterior de mantenimiento.', $bandas[1]->motivo_estado);
    }

    public function test_emergencia_impide_regenerar_labor_normal_en_la_camara(): void
    {
        $contexto = $this->crearContexto();
        $planNormal = $this->crearPlan($contexto, PrioridadOperacional::Normal);
        $this->ubicar($contexto, $contexto['emergencia'], 1, 1, 'PAL-260-REGENERAR-ORIGEN');
        app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Controlar el sector antes de generar más trabajo.',
        );
        $folio = $this->crearFolio($contexto['temporada'], 'PAL-259-REGENERAR');

        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('suspendió nuevas labores normales');
        app(ServicioPlanesOperacionales::class)->agregarTareaRolling($planNormal, [
            'folio_id' => $folio->id,
            'tipo_movimiento' => TipoMovimiento::Retiro,
            'camara_origen_id' => $contexto['emergencia']->id,
            'posicion_origen_id' => $contexto['emergencia']->posiciones()->firstOrFail()->id,
        ]);
    }

    public function test_bloqueo_impide_un_ingreso_manual_nuevo(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['emergencia'], 1, 1, 'PAL-260-BLOQUEO-ACTIVO');
        app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Cerrar inmediatamente los ingresos a la cámara.',
        );
        $folio = $this->crearFolio($contexto['temporada'], 'PAL-259-MANUAL');
        [$operador, $dispositivo] = $this->crearOperador();

        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage('no admite nuevos ingresos');
        app(ServicioReservasTareasMovimiento::class)->validarParaMovimiento(
            tarea: null,
            folio: $folio,
            tipo: TipoMovimiento::TrasladoEntreCamaras,
            posicionOrigenId: $contexto['otra']->posiciones()->firstOrFail()->id,
            posicionDestinoId: $contexto['emergencia']->posiciones()->firstOrFail()->id,
            usuario: $operador,
            dispositivo: $dispositivo,
        );
    }

    public function test_publica_una_maniobra_critica_permanente_y_relaja_marca_y_formato(): void
    {
        $contexto = $this->crearContexto();
        $objetivo = $this->ubicar(
            $contexto,
            $contexto['emergencia'],
            1,
            2,
            'PAL-260-OBJETIVO',
            'Cliente emergencia',
            'Marca origen',
            'Envase origen',
        );
        $this->ubicar(
            $contexto,
            $contexto['otra'],
            1,
            1,
            'PAL-260-COMPANERO',
            'Cliente emergencia',
            'Marca distinta',
            'Envase distinto',
        );

        $plan = app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Evacuar producto ante una alarma confirmada.',
            $contexto['dispositivo']->id,
        );
        $tarea = $plan->tareas()->sole();

        $this->assertSame(EstadoPlanOperacional::EnEjecucion, $plan->estado);
        $this->assertSame('publicada', $plan->contexto['estado_emergencia']);
        $this->assertSame(1, $plan->contexto['pallets_restantes']);
        $this->assertSame(0, $plan->contexto['porcentaje_actual']);
        $this->assertSame(['marca', 'formato'], $plan->contexto['afinidad_relajada']);
        $this->assertSame($objetivo->id, $tarea->folio_id);
        $this->assertSame(PrioridadOperacional::Critica, $tarea->prioridad);
        $this->assertSame(TipoPasoManiobra::MovimientoPermanente, $tarea->tipo_paso_maniobra);
        $this->assertSame(PrioridadOperacional::Critica, $plan->maniobras()->sole()->prioridad);
        $this->assertSame($contexto['otra']->id, $tarea->camara_destino_id);
        $this->assertSame(1, $tarea->posicionDestino()->sole()->banda);
        $this->assertSame(2, $tarea->posicionDestino()->sole()->posicion);
        $this->assertTrue($tarea->contexto['destino_precalculado_inmutable']);
        $this->assertTrue($plan->maniobras()->sole()->contexto['sin_custodia_temporal']);
        $this->assertSame(0, CustodiaTemporalManiobra::query()
            ->where('maniobra_operacional_id', $tarea->maniobra_operacional_id)
            ->count());
    }

    public function test_recalcula_rolling_y_completa_solo_al_evacuarse_el_cien_por_ciento(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['emergencia'], 1, 1, 'PAL-260-ROLLING-1');
        $this->ubicar($contexto, $contexto['emergencia'], 1, 2, 'PAL-260-ROLLING-2');
        $plan = app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Evacuación rolling hasta alcanzar estado seguro.',
        );
        [$operador, $dispositivo] = $this->crearOperador();
        $sesionOrigen = app(ServicioSesionEstiba::class)->abrir(
            $contexto['emergencia'],
            $operador,
            $dispositivo,
        );
        $sesionDestino = app(ServicioSesionEstiba::class)->abrir(
            $contexto['otra'],
            $operador,
            $dispositivo,
        );

        $this->ejecutarSiguiente($plan, $operador, $dispositivo, $sesionOrigen, $sesionDestino);
        $this->assertSame(EstadoPlanOperacional::EnEjecucion, $plan->refresh()->estado);
        $this->assertSame(50, $plan->contexto['porcentaje_actual']);
        $this->assertSame(1, $plan->contexto['pallets_restantes']);
        $this->assertSame(1, $plan->tareas()
            ->where('estado', EstadoTareaMovimiento::Pendiente->value)
            ->count());

        $this->ejecutarSiguiente($plan, $operador, $dispositivo, $sesionOrigen, $sesionDestino);
        $this->assertSame(EstadoPlanOperacional::Completado, $plan->refresh()->estado);
        $this->assertSame('completada', $plan->contexto['estado_emergencia']);
        $this->assertSame(100, $plan->contexto['porcentaje_actual']);
        $this->assertSame(0, $plan->contexto['pallets_restantes']);
        $this->assertTrue($plan->contexto['lista_para_apagar']);
        $this->assertSame(2, $plan->maniobras()->count());
        $this->assertSame(2, $plan->maniobras()
            ->where('estado', EstadoManiobraOperacional::Completada->value)
            ->count());
        $this->assertSame(0, CustodiaTemporalManiobra::query()
            ->whereIn('maniobra_operacional_id', $plan->maniobras()->pluck('id'))
            ->count());
        $this->assertSame(0, UbicacionActual::query()
            ->where('camara_id', $contexto['emergencia']->id)
            ->count());
        $this->assertTrue($contexto['emergencia']->bandasOperacionales()
            ->get()
            ->every(fn ($banda): bool => $banda->modo !== ModoBandaOperacional::Operativa));
    }

    #[DataProvider('cierresDeCiclo')]
    public function test_nueva_emergencia_conserva_el_historial_y_cancela_solo_el_ciclo_vigente(string $cierre): void
    {
        $this->freezeTime();
        $contexto = $this->crearContexto();
        $camara = $contexto['emergencia'];
        $usuario = $contexto['supervisor'];
        $servicio = app(ServicioControlEvacuacionEmergencia::class);
        $this->ubicar($contexto, $camara, 1, 1, 'PAL-263-EMERGENCIA-1');
        $anterior = $servicio->declarar($camara, $usuario, 'Primer incidente.');

        if ($cierre === 'completado') {
            [$operador, $dispositivo] = $this->crearOperador();
            $sesiones = app(ServicioSesionEstiba::class);
            $origen = $sesiones->abrir($camara, $operador, $dispositivo);
            $destino = $sesiones->abrir($contexto['otra'], $operador, $dispositivo);
            $this->ejecutarSiguiente($anterior, $operador, $dispositivo, $origen, $destino);
            $sesiones->cerrar($origen, $operador);
            $sesiones->cerrar($destino, $operador);
            foreach ($camara->bandasOperacionales()->get() as $banda) {
                app(ServicioBandasOperacionales::class)->configurar($camara, $banda, [
                    'modo' => 'operativa',
                    'usos_permitidos' => $banda->usos_permitidos,
                    'version' => $banda->version,
                ], $usuario);
            }
            $this->ubicar($contexto, $camara, 1, 1, 'PAL-263-EMERGENCIA-2');
        } else {
            $servicio->cancelar($camara, $usuario, 'Primer incidente resuelto antes de mover.');
        }

        $this->assertSame($cierre, $anterior->refresh()->estado->value);
        $historial = $anterior->getAttributes();
        $tareas = $anterior->tareas()->get()->map->getAttributes()->all();
        $maniobras = $anterior->maniobras()->get()->map->getAttributes()->all();
        $movimientos = $anterior->movimientos()->pluck('id')->all();
        $nuevo = $servicio->declarar($camara, $usuario, 'Segundo incidente.');

        $this->assertNotSame($anterior->id, $nuevo->id);
        $this->assertSame(1, $anterior->ciclo_referencia);
        $this->assertSame(2, $nuevo->ciclo_referencia);
        $this->assertSame('Segundo incidente.', $nuevo->motivo);
        $this->assertSame($nuevo->id, $servicio->declarar($camara, $usuario, 'Aviso repetido.')->id);
        $this->assertSame(2, PlanOperacional::query()
            ->where('referencia_tipo', ServicioControlEvacuacionEmergencia::REFERENCIA)
            ->where('referencia_id', $camara->id)->count());
        $this->assertSame(1, $nuevo->tareas()->count());
        $this->assertSame(1, $nuevo->maniobras()->count());
        $this->assertSame(0, $nuevo->movimientos()->count());

        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$camara->id}/cancelar", [
                'motivo' => 'Cancelar únicamente el segundo incidente.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $nuevo->id)
            ->assertJsonPath('data.estado', 'cancelado');
        $this->assertSame($historial, $anterior->refresh()->getAttributes());
        $this->assertSame($tareas, $anterior->tareas()->get()->map->getAttributes()->all());
        $this->assertSame($maniobras, $anterior->maniobras()->get()->map->getAttributes()->all());
        $this->assertSame($movimientos, $anterior->movimientos()->pluck('id')->all());
    }

    public static function cierresDeCiclo(): array
    {
        return [
            'tras completar' => ['completado'],
            'tras cancelar' => ['cancelado'],
        ];
    }

    public function test_descarta_bandas_con_retencion_o_inspeccion_sag_activa(): void
    {
        $contexto = $this->crearContexto();
        $segura = $this->crearCamara($contexto['supervisor'], 'ZZ-CAM-260-SEGURA');
        $this->ubicar(
            $contexto,
            $contexto['emergencia'],
            1,
            1,
            'PAL-260-SALIDA-SEGURA',
            'Cliente protegido',
        );
        $sag = $this->ubicar(
            $contexto,
            $contexto['otra'],
            1,
            1,
            'PAL-260-SAG',
            'Cliente protegido',
        );
        $retenido = $this->ubicar(
            $contexto,
            $contexto['otra'],
            2,
            1,
            'PAL-260-RETENIDO',
            'Cliente protegido',
        );
        $retenido->update([
            'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Retenido,
        ]);
        RetencionOperacionalFolio::create([
            'folio_id' => $retenido->id,
            'bloqueo_folio_id' => $retenido->id,
            'estado' => EstadoRetencionOperacional::Activa,
            'motivo' => 'Retención activa en banda de destino.',
            'estado_operacional_anterior' => EstadoOperacionalFolio::Disponible,
            'condicion_termica_anterior' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento_anterior' => HabilitacionAlmacenamientoFolio::Habilitado,
            'retenido_por_user_id' => $contexto['supervisor']->id,
            'retenido_at' => now(),
        ]);
        $lote = LoteInspeccionSag::create([
            'temporada_id' => $contexto['temporada']->id,
            'codigo' => 'SAG-260-0001',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'SAG-260-0001'),
            'tipo' => TipoLoteInspeccionSag::MuestreoUsda,
            'estado' => EstadoLoteInspeccionSag::Preparacion,
            'cantidad_solicitada' => 1,
            'creado_por_user_id' => $contexto['supervisor']->id,
        ]);
        LoteInspeccionSagFolio::create([
            'lote_inspeccion_sag_id' => $lote->id,
            'folio_id' => $sag->id,
            'estado_sag_anterior' => [],
        ]);

        $plan = app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Usar solamente ubicaciones sanitariamente seguras.',
        );
        $tarea = $plan->tareas()->sole();

        $this->assertSame($segura->id, $tarea->camara_destino_id);
        $this->assertNotSame($contexto['otra']->id, $tarea->camara_destino_id);
    }

    public function test_no_permite_cancelar_una_maniobra_que_cruzo_el_punto_de_no_retorno(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['emergencia'], 1, 1, 'PAL-260-EN-PROCESO');
        $plan = app(ServicioControlEvacuacionEmergencia::class)->declarar(
            $contexto['emergencia'],
            $contexto['supervisor'],
            'Evacuación que iniciará movimiento físico.',
        );
        [$operador, $dispositivo] = $this->crearOperador();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($plan->tareas()->sole(), $operador, $dispositivo);
        $planes->iniciar($tarea, $operador, $dispositivo);

        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}/cancelar", [
                'motivo' => 'Intento de cancelación con pallet ya retirado.',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertSame(EstadoPlanOperacional::EnEjecucion, $plan->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::EnProceso, $tarea->refresh()->estado);
        $this->assertTrue($contexto['emergencia']->bandasOperacionales()
            ->get()
            ->every(fn ($banda): bool => $banda->modo !== ModoBandaOperacional::Operativa));
    }

    public function test_requiere_permiso_de_supervision_y_motivo_auditable(): void
    {
        $contexto = $this->crearContexto();
        $consulta = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'activo' => true,
        ]);

        $this->actingAs($consulta, 'sanctum')
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}", [
                'motivo' => 'Alarma operacional.',
            ])
            ->assertForbidden();
        $this->withToken($contexto['token'])
            ->postJson("/api/evacuaciones-emergencia/{$contexto['emergencia']->id}", [
                'motivo' => 'x',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');
    }

    /** @return array<string, mixed> */
    private function crearContexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-259-'.Str::upper(Str::random(5)),
            'nombre' => 'Temporada control de emergencia',
            'fecha_inicio' => '2026-09-05',
            'activa' => true,
        ]);
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-259-'.Str::upper(Str::random(5)),
            'nombre' => 'Tablet control emergencia',
            'activo' => true,
        ]);
        $token = $supervisor
            ->crearTokenParaDispositivo($dispositivo, 'tablet-control-emergencia')
            ->plainTextToken;
        $emergencia = $this->crearCamara($supervisor, 'CAM-259-EMERGENCIA');
        $otra = $this->crearCamara($supervisor, 'CAM-259-APOYO');

        return compact('temporada', 'supervisor', 'dispositivo', 'token', 'emergencia', 'otra');
    }

    private function crearCamara(User $usuario, string $codigo): Camara
    {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => $codigo,
            'contenido' => ContenidoCamara::Productos,
            'estado' => 'activa',
            'cantidad_bandas' => 2,
            'posiciones_por_banda' => 2,
            'cantidad_niveles' => 1,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $usuario);
        $camara->bandasOperacionales()->update([
            'usos_permitidos' => [UsoBandaOperacional::TransitoProductoTerminado->value],
        ]);
        foreach (range(1, 2) as $banda) {
            foreach (range(1, 2) as $profundidad) {
                Posicion::create([
                    'camara_id' => $camara->id,
                    'banda' => $banda,
                    'posicion' => $profundidad,
                    'nivel' => 1,
                    'etiqueta' => sprintf('%s-B%02d-P%02d-N1', $codigo, $banda, $profundidad),
                ]);
            }
        }

        return $camara;
    }

    private function crearPlan(array $contexto, PrioridadOperacional $prioridad): PlanOperacional
    {
        return PlanOperacional::create([
            'temporada_id' => $contexto['temporada']->id,
            'tipo' => TipoPlanOperacional::ReordenamientoCamara,
            'estado' => EstadoPlanOperacional::Programado,
            'prioridad' => $prioridad,
            'titulo' => 'Trabajo normal previo',
            'motivo' => 'Trabajo reversible.',
            'creado_por_user_id' => $contexto['supervisor']->id,
            'programado_at' => now(),
            'contexto' => ['planner_horizon' => 'rolling'],
        ]);
    }

    private function crearTarea(
        array $contexto,
        PlanOperacional $plan,
        EstadoTareaMovimiento $estado,
        PrioridadOperacional $prioridad,
        Camara $origen,
        Camara $destino,
        ?ManiobraOperacional $maniobra = null,
    ): TareaMovimiento {
        $secuencia = ((int) $plan->tareas()->max('secuencia')) + 1;

        return TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'maniobra_operacional_id' => $maniobra?->id,
            'secuencia' => $secuencia,
            'secuencia_maniobra' => $maniobra ? 1 : null,
            'tipo_movimiento' => TipoMovimiento::TrasladoEntreCamaras,
            'estado' => $estado,
            'prioridad' => $prioridad,
            'folio_id' => $this->crearFolio(
                $contexto['temporada'],
                'PAL-259-'.Str::upper(Str::random(8)),
            )->id,
            'camara_origen_id' => $origen->id,
            'posicion_origen_id' => $origen->posiciones()->firstOrFail()->id,
            'camara_destino_id' => $destino->id,
            'posicion_destino_id' => $destino->posiciones()->firstOrFail()->id,
            'iniciada_at' => $estado === EstadoTareaMovimiento::EnProceso ? now() : null,
        ]);
    }

    private function crearFolio(Temporada $temporada, string $numero): Folio
    {
        return Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numero,
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fuente_habilitacion_almacenamiento' => FuenteHabilitacionAlmacenamiento::PrefrioAprobado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
    }

    private function ubicar(
        array $contexto,
        Camara $camara,
        int $banda,
        int $profundidad,
        string $numero,
        string $cliente = 'Cliente común',
        string $marca = 'Marca común',
        string $envase = 'Caja común',
    ): Folio {
        $folio = $this->crearFolio($contexto['temporada'], $numero);
        $folio->update([
            'exportadora' => $cliente,
            'marca' => $marca,
            'datos_externos' => ['envase' => $envase],
        ]);
        $posicion = $camara->posiciones()
            ->where('banda', $banda)
            ->where('posicion', $profundidad)
            ->where('nivel', 1)
            ->sole();
        $this->ubicarFolio($folio, $posicion);

        return $folio;
    }

    private function ubicarFolio(Folio $folio, Posicion $posicion): void
    {
        UbicacionActual::withoutEvents(fn (): UbicacionActual => UbicacionActual::create([
            'folio_id' => $folio->id,
            'camara_id' => $posicion->camara_id,
            'posicion_id' => $posicion->id,
            'ubicado_at' => now(),
        ]));
    }

    /** @return array{User, Dispositivo} */
    private function crearOperador(): array
    {
        return [
            User::factory()->create([
                'rol' => RolUsuario::CamareroFrio,
                'activo' => true,
            ]),
            Dispositivo::create([
                'codigo' => 'TABLET-259-OPERADOR-'.Str::upper(Str::random(4)),
                'nombre' => 'Tablet operador emergencia',
                'activo' => true,
            ]),
        ];
    }

    private function ejecutarSiguiente(
        PlanOperacional $plan,
        User $operador,
        Dispositivo $dispositivo,
        SesionEstiba $sesionOrigen,
        SesionEstiba $sesionDestino,
    ): void {
        $tarea = $plan->tareas()
            ->where('estado', EstadoTareaMovimiento::Pendiente->value)
            ->firstOrFail();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($tarea, $operador, $dispositivo);
        $planes->iniciar($tarea, $operador, $dispositivo);
        $movimiento = app(ServicioMovimientoEstiba::class)->mover(
            operacionId: (string) Str::uuid(),
            folio: $tarea->folio,
            posicionDestino: $tarea->posicionDestino,
            sesionOrigen: $sesionOrigen,
            sesionDestino: $sesionDestino,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionOrigenConocida: $sesionOrigen->camara()->firstOrFail()->version_plano,
            versionDestinoConocida: $sesionDestino->camara()->firstOrFail()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $tarea->refresh(),
        );
        app(ServicioRecalculosPendientesPlanificador::class)->ejecutar(
            ServicioRecalculosPendientesPlanificador::DESOCUPACION,
            $movimiento->id,
        );
    }
}
