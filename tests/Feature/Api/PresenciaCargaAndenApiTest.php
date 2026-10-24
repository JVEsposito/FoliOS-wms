<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\Anden;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PresenciaCargaAnden;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Cargas\ServicioCarga;
use App\Services\Cargas\ServicioPlanDespachoDirecto;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PresenciaCargaAndenApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_camion_en_anden_crea_una_tarea_critica_de_retiro_directo_e_idempotente(): void
    {
        $contexto = $this->crearContexto(1, 2);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);
        $operacionId = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionId,
            'version_esperada' => $carga->version,
            'anden_id' => $contexto['anden']->id,
            'patente' => 'ab-cd-12',
            'conductor' => 'María Pérez',
        ];
        $ruta = "/api/cargas/{$carga->id}/camion-en-anden";

        $respuesta = $this->conToken($contexto['tokenOficina'])
            ->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.camion_en_anden.patente', 'AB-CD-12')
            ->assertJsonPath('data.camion_en_anden.anden.nombre', 'Andén principal');

        $presenciaId = $respuesta->json('data.camion_en_anden.id');
        $plan = PlanOperacional::query()
            ->where('referencia_tipo', 'presencia_carga_anden')
            ->where('referencia_id', $presenciaId)
            ->sole();
        $tarea = $plan->tareas()->sole();

        $this->assertSame('despacho_directo', $plan->tipo->value);
        $this->assertSame('rolling', $plan->contexto['planner_horizon']);
        $this->assertSame('critica', $tarea->prioridad->value);
        $this->assertSame('retiro', $tarea->tipo_movimiento->value);
        $this->assertSame('entrega_anden', $tarea->tipo_paso_maniobra->value);
        $this->assertSame(1, $tarea->secuencia_maniobra);
        $this->assertTrue($tarea->contexto['maniobra_unitaria']);
        $this->assertSame(1, $plan->maniobras()->count());
        $this->assertSame($contexto['folios'][0]->id, $tarea->folio_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertSame('retiro_directo_anden', $tarea->contexto['tipo_decision']);
        $this->assertSame($contexto['anden']->id, $tarea->contexto['anden_id']);
        app(ServicioEstadoArbitrajePlanificador::class)
            ->solicitar($plan->temporada_id, 'prueba_despacho_directo');
        RecalcularArbitrajePlanificador::dispatchSync($plan->temporada_id);

        $this->conToken($contexto['token'])
            ->getJson('/api/tareas-movimiento?asignacion=disponibles')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tarea->id)
            ->assertJsonPath('data.0.maniobra.pasos_totales', 1)
            ->assertJsonPath('data.0.secuencia_maniobra', 1)
            ->assertJsonPath('data.0.tipo_paso_maniobra', 'entrega_anden')
            ->assertJsonPath('data.0.destino_logico.tipo', 'anden')
            ->assertJsonPath('data.0.destino_logico.nombre', 'Andén principal');

        $this->conToken($contexto['tokenOficina'])
            ->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.camion_en_anden.id', $presenciaId);

        $this->assertSame(1, PresenciaCargaAnden::query()->count());
        $this->assertSame(1, TareaMovimiento::query()->count());
        $this->assertSame(1, $plan->maniobras()->count());
    }

    public function test_publica_varios_retiros_independientes_para_camareros_disponibles(): void
    {
        $contexto = $this->crearContexto(2, 3);
        $posicionSegundaBanda = Posicion::create([
            'camara_id' => $contexto['camara']->id,
            'banda' => 2,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B02-P01-N1',
        ]);
        $contexto['folios'][1]->ubicacionActual()->update([
            'posicion_id' => $posicionSegundaBanda->id,
        ]);
        $carga = $this->crearCargaPublicada($contexto, $contexto['folios']);

        $this->registrarPresencia($contexto, $carga, $contexto['anden']);

        $tareas = TareaMovimiento::query()
            ->where('estado', EstadoTareaMovimiento::Pendiente->value)
            ->orderBy('secuencia')
            ->get();
        $this->assertCount(2, $tareas);
        $this->assertTrue($tareas->every(
            fn (TareaMovimiento $tarea): bool => $tarea->tipo_movimiento->value === 'retiro',
        ));
        $this->assertTrue($tareas->every(
            fn (TareaMovimiento $tarea): bool => $tarea->tipo_paso_maniobra->value === 'entrega_anden'
                && $tarea->maniobra_operacional_id !== null,
        ));

        $segundoOperador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $segundoDispositivo = Dispositivo::create([
            'codigo' => 'TABLET-252-B',
            'nombre' => 'Tablet PR 252 B',
        ]);
        $servicio = app(ServicioPlanesOperacionales::class);
        $servicio->asumir($tareas[0], $contexto['operador'], $contexto['dispositivo']);
        $servicio->asumir($tareas[1], $segundoOperador, $segundoDispositivo);

        $this->assertSame('asumida', $tareas[0]->refresh()->estado->value);
        $this->assertSame('asumida', $tareas[1]->refresh()->estado->value);
        $this->assertNotSame(
            $tareas[0]->responsable_user_id,
            $tareas[1]->responsable_user_id,
        );
    }

    public function test_un_anden_y_una_carga_solo_admiten_una_presencia_activa(): void
    {
        $contexto = $this->crearContexto(2, 3);
        $primera = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);
        $segunda = $this->crearCargaPublicada($contexto, [$contexto['folios'][1]]);

        $this->registrarPresencia($contexto, $primera, $contexto['anden']);

        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/{$segunda->id}/camion-en-anden", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $segunda->version,
                'anden_id' => $contexto['anden']->id,
                'patente' => 'EFGH34',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $primera->refresh();
        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/{$primera->id}/camion-en-anden", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $primera->version,
                'anden_id' => $contexto['andenAlternativo']->id,
                'patente' => 'IJKL56',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertSame(1, PresenciaCargaAnden::query()
            ->whereNotNull('bloqueo_carga_id')
            ->whereNotNull('bloqueo_anden_id')
            ->count());
    }

    public function test_la_pda_completa_el_retiro_directo_sin_crear_una_ubicacion_ficticia_en_anden(): void
    {
        $contexto = $this->crearContexto(1, 2);
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto, [$folio]);
        $this->registrarPresencia($contexto, $carga, $contexto['anden']);
        $tarea = TareaMovimiento::query()->sole();
        $servicioTareas = app(ServicioPlanesOperacionales::class);
        $servicioTareas->asumir($tarea, $contexto['operador'], $contexto['dispositivo']);
        $servicioTareas->iniciar($tarea, $contexto['operador'], $contexto['dispositivo']);
        $asignacion = $carga->asignacionesActuales()->sole();

        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/asignaciones/{$asignacion->id}/enviar-anden", [
                'operacion_id' => (string) Str::uuid(),
                'anden_id' => $contexto['anden']->id,
                'sesion_estiba_id' => $contexto['sesion']->id,
                'tarea_movimiento_id' => $tarea->id,
                'version_camara_conocida' => $contexto['camara']->refresh()->version_plano,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'despachada')
            ->assertJsonPath('data.folios.0.estado_carga', 'en_anden')
            ->assertJsonPath('data.camion_en_anden.patente', 'ABCD12');

        $this->assertSame('completada', $tarea->refresh()->estado->value);
        $this->assertSame('completado', $tarea->planOperacional->refresh()->estado->value);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        $this->assertDatabaseHas('movimientos', [
            'folio_id' => $folio->id,
            'tarea_movimiento_id' => $tarea->id,
            'tipo_movimiento' => 'retiro',
        ]);

        $versionAntesCierre = $carga->refresh()->version;
        $this->travel(1)->minutes();
        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/{$carga->id}/cerrar-despacho", [
                'operacion_id' => (string) Str::uuid(),
                'patente' => 'ABCD12',
                'conductor' => 'María Pérez',
                'ocurrido_at' => now()->toAtomString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->assertJsonPath('data.camion_en_anden', null)
            ->assertJsonPath('data.version', $versionAntesCierre + 1);
        $this->travelBack();

        $this->assertDatabaseHas('presencias_carga_anden', [
            'carga_id' => $carga->id,
            'bloqueo_carga_id' => null,
            'bloqueo_anden_id' => null,
            'estado' => 'finalizada',
        ]);
    }

    public function test_si_el_pallet_asignado_esta_bloqueado_primero_genera_un_despeje(): void
    {
        $contexto = $this->crearContexto(2, 3);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);

        $this->registrarPresencia($contexto, $carga, $contexto['anden']);

        $tarea = TareaMovimiento::query()->sole();
        $this->assertSame($contexto['folios'][1]->id, $tarea->folio_id);
        $this->assertSame('traslado_entre_camaras', $tarea->tipo_movimiento->value);
        $this->assertSame('movimiento_permanente', $tarea->tipo_paso_maniobra->value);
        $this->assertSame(1, $tarea->secuencia_maniobra);
        $this->assertNotNull($tarea->maniobra_operacional_id);
        $this->assertSame('critica', $tarea->prioridad->value);
        $this->assertSame('despeje_salida_directa', $tarea->contexto['tipo_decision']);
        $this->assertSame($contexto['folios'][0]->id, $tarea->contexto['habilita_folio_id']);
        $this->assertNull($tarea->posicion_destino_id);

        $destino = Posicion::create([
            'camara_id' => $contexto['camara']->id,
            'banda' => 2,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B02-P01-N1',
        ]);
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['operador'],
            $contexto['dispositivo'],
        );
        app(ServicioPlanesOperacionales::class)->materializarDestino(
            $tarea->refresh(),
            $destino,
            $contexto['operador'],
            $contexto['dispositivo'],
        );

        $this->assertSame('reubicacion', $tarea->refresh()->tipo_movimiento->value);
        $this->assertSame($destino->id, $tarea->posicion_destino_id);
    }

    public function test_reemplaza_la_tarea_pendiente_si_cambia_la_accesibilidad_fisica(): void
    {
        $contexto = $this->crearContexto(2, 3);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);
        $this->registrarPresencia($contexto, $carga, $contexto['anden']);
        $anterior = TareaMovimiento::query()->sole();
        $posicionOtraBanda = Posicion::create([
            'camara_id' => $contexto['camara']->id,
            'banda' => 2,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B02-P01-N1',
        ]);
        $contexto['folios'][1]->ubicacionActual()->update([
            'posicion_id' => $posicionOtraBanda->id,
        ]);
        $presencia = PresenciaCargaAnden::query()->sole();

        app(ServicioPlanDespachoDirecto::class)->sincronizar(
            $presencia,
            $contexto['operador'],
        );

        $nueva = TareaMovimiento::query()
            ->where('estado', EstadoTareaMovimiento::Pendiente->value)
            ->sole();
        $this->assertSame('cancelada', $anterior->refresh()->estado->value);
        $this->assertSame($nueva->id, $anterior->reemplazada_por_tarea_id);
        $this->assertSame($contexto['folios'][0]->id, $nueva->folio_id);
        $this->assertSame('retiro', $nueva->tipo_movimiento->value);
        $this->assertSame('retiro_directo_anden', $nueva->contexto['tipo_decision']);
    }

    public function test_liberar_el_anden_cancela_trabajo_asumido_pero_no_un_pallet_en_movimiento(): void
    {
        $contexto = $this->crearContexto(1, 2);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);
        $this->registrarPresencia($contexto, $carga, $contexto['anden']);
        $tarea = TareaMovimiento::query()->sole();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['operador'],
            $contexto['dispositivo'],
        );

        $carga->refresh();
        $this->finalizarPresencia($contexto, $carga)
            ->assertOk()
            ->assertJsonPath('data.camion_en_anden', null);

        $this->assertSame('cancelada', $tarea->refresh()->estado->value);
        $this->assertNotNull($tarea->cancelada_por_user_id);
        $this->assertNotNull($tarea->motivo_cancelacion);
        $this->assertSame('cancelado', $tarea->planOperacional->refresh()->estado->value);
        $this->assertDatabaseMissing('presencias_carga_anden', [
            'carga_id' => $carga->id,
            'bloqueo_carga_id' => $carga->id,
        ]);

        $carga->refresh();
        $this->registrarPresencia($contexto, $carga, $contexto['anden']);
        $nuevaTarea = TareaMovimiento::query()
            ->where('estado', EstadoTareaMovimiento::Pendiente->value)
            ->sole();
        $servicioTareas = app(ServicioPlanesOperacionales::class);
        $servicioTareas->asumir($nuevaTarea, $contexto['operador'], $contexto['dispositivo']);
        $servicioTareas->iniciar($nuevaTarea, $contexto['operador'], $contexto['dispositivo']);

        $carga->refresh();
        $this->finalizarPresencia($contexto, $carga)
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertSame('en_proceso', $nuevaTarea->refresh()->estado->value);
        $this->assertDatabaseHas('presencias_carga_anden', [
            'carga_id' => $carga->id,
            'bloqueo_carga_id' => $carga->id,
            'bloqueo_anden_id' => $contexto['anden']->id,
        ]);
    }

    public function test_con_el_planificador_apagado_registra_presencia_sin_generar_tareas(): void
    {
        config([
            'planificador.mode' => 'off',
            'planificador.generacion_automatica' => true,
        ]);
        $contexto = $this->crearContexto(1, 2, configurarPlanificador: false);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);

        $this->registrarPresencia($contexto, $carga, $contexto['anden']);

        $this->assertDatabaseHas('presencias_carga_anden', [
            'carga_id' => $carga->id,
            'bloqueo_carga_id' => $carga->id,
        ]);
        $this->assertDatabaseCount('planes_operacionales', 0);
        $this->assertDatabaseCount('tareas_movimiento', 0);
    }

    public function test_guided_server_registra_presencia_sin_publicar_despejes_inmaterializables(): void
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'server',
        ]);
        $contexto = $this->crearContexto(2, 3, configurarPlanificador: false);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);

        $this->registrarPresencia($contexto, $carga, $contexto['anden']);

        $this->assertDatabaseHas('presencias_carga_anden', [
            'carga_id' => $carga->id,
            'bloqueo_carga_id' => $carga->id,
        ]);
        $this->assertDatabaseCount('planes_operacionales', 0);
        $this->assertDatabaseCount('tareas_movimiento', 0);
    }

    public function test_servidor_rechaza_destino_en_banda_bloqueada(): void
    {
        $contexto = $this->crearContexto(2, 3);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);
        $this->registrarPresencia($contexto, $carga, $contexto['anden']);
        $tarea = TareaMovimiento::query()->sole();
        $destino = Posicion::create([
            'camara_id' => $contexto['camara']->id,
            'banda' => 2,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B02-P01-N1',
        ]);
        $banda = $contexto['camara']->bandasOperacionales()
            ->where('numero', 2)
            ->firstOrFail();
        $banda->update([
            'modo' => 'bloqueada',
            'motivo_estado' => 'Bloqueo de prueba.',
        ]);
        $servicio = app(ServicioPlanesOperacionales::class);
        $servicio->asumir($tarea, $contexto['operador'], $contexto['dispositivo']);

        $this->expectException(ConflictoOperacion::class);
        $this->expectExceptionMessage(
            'La banda propuesta no admite nuevos ingresos de producto terminado.',
        );
        $servicio->materializarDestino(
            $tarea->refresh(),
            $destino,
            $contexto['operador'],
            $contexto['dispositivo'],
        );
    }

    public function test_shadow_registra_candidatos_sin_dirigir_trabajo(): void
    {
        $this->freezeTime();
        config([
            'planificador.mode' => 'shadow',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
        ]);
        $contexto = $this->crearContexto(1, 2, configurarPlanificador: false);
        $carga = $this->crearCargaPublicada($contexto, [$contexto['folios'][0]]);

        $this->registrarPresencia($contexto, $carga, $contexto['anden']);

        $this->assertDatabaseCount('planes_operacionales', 0);
        $this->assertDatabaseCount('tareas_movimiento', 0);
        $evento = EventoCarga::query()
            ->where('carga_id', $carga->id)
            ->where('tipo', 'tareas_generadas')
            ->where('datos->planner_mode', 'shadow')
            ->where('datos->presencia_carga_anden_id', PresenciaCargaAnden::query()->sole()->id)
            ->sole();
        $this->assertSame('shadow', $evento->datos['planner_mode']);
        $this->assertSame('tablet', $evento->datos['planner_compute']);
        $this->assertCount(1, $evento->datos['candidatos']);
    }

    public function test_despeje_rechaza_otra_camara_si_hay_espacio_local_y_no_rebloquea_la_salida(): void
    {
        $ctx = $this->crearContexto(2, 3);
        $carga = $this->crearCargaPublicada($ctx, [$ctx['folios'][0]]);
        $this->registrarPresencia($ctx, $carga, $ctx['anden']);
        $tarea = TareaMovimiento::query()->sole();
        $otra = Camara::create(['codigo' => 'CAM-ALTERNATIVA', 'nombre' => 'Cámara 1', 'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        app(ServicioBandasOperacionales::class)->sincronizar($otra, $ctx['administrador']);
        $remota = Posicion::create(['camara_id' => $otra->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1]);
        $local = Posicion::create(['camara_id' => $ctx['camara']->id, 'banda' => 2, 'posicion' => 1, 'nivel' => 1]);
        $planes = app(ServicioPlanesOperacionales::class);
        $asumida = $planes->asumir($tarea, $ctx['operador'], $ctx['dispositivo']);
        try {
            $planes->materializarDestino($asumida, $remota, $ctx['operador'], $ctx['dispositivo']);
            $this->fail('Se aceptó cruzar de cámara teniendo un destino local válido.');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('cámara de origen', $e->getMessage());
        }
        $frente = $ctx['posiciones'][2];
        try {
            $planes->materializarDestino($asumida->fresh(), $frente, $ctx['operador'], $ctx['dispositivo']);
            $this->fail('Se aceptó volver a bloquear la banda de extracción.');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('extraer la carga', $e->getMessage());
        }
        $materializada = $planes->materializarDestino($asumida->fresh(), $local, $ctx['operador'], $ctx['dispositivo']);
        $this->assertSame('reubicacion', $materializada->tipo_movimiento->value);
        $this->assertSame($ctx['camara']->id, $materializada->camara_destino_id);
        $movimientosAntes = Movimiento::count();
        $iniciada = $planes->iniciar($materializada, $ctx['operador'], $ctx['dispositivo']);
        $this->assertSame(EstadoTareaMovimiento::EnProceso, $iniciada->estado);
        $this->assertSame($local->id, $iniciada->reservaActiva->bloqueo_posicion_id);
        $this->assertSame($movimientosAntes, Movimiento::count());
    }

    public function test_despeje_permite_otra_camara_si_no_queda_destino_local_valido(): void
    {
        $ctx = $this->crearContexto(2, 3);
        $carga = $this->crearCargaPublicada($ctx, [$ctx['folios'][0]]);
        $this->registrarPresencia($ctx, $carga, $ctx['anden']);
        $tarea = TareaMovimiento::query()->sole();
        $otra = Camara::create(['codigo' => 'CAM-RESPALDO', 'nombre' => 'Cámara respaldo', 'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        app(ServicioBandasOperacionales::class)->sincronizar($otra, $ctx['administrador']);
        $remota = Posicion::create(['camara_id' => $otra->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1]);
        $planes = app(ServicioPlanesOperacionales::class);
        $asumida = $planes->asumir($tarea, $ctx['operador'], $ctx['dispositivo']);
        $materializada = $planes->materializarDestino($asumida, $remota, $ctx['operador'], $ctx['dispositivo']);
        $this->assertSame('traslado_entre_camaras', $materializada->tipo_movimiento->value);
    }

    /** @return array<string, mixed> */
    private function crearContexto(
        int $cantidadFolios,
        int $cantidadPosiciones,
        bool $configurarPlanificador = true,
    ): array {
        if ($configurarPlanificador) {
            config([
                'planificador.mode' => 'guided',
                'planificador.generacion_automatica' => true,
                'planificador.compute' => 'tablet',
                'planificador.horizon' => 'rolling',
            ]);
        }
        Temporada::query()->update(['activa' => false]);
        Temporada::create([
            'codigo' => 'TEMP-252',
            'nombre' => 'Temporada PR 252',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $despachador = User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-252',
            'nombre' => 'Tablet PR 252',
        ]);
        $token = $operador
            ->crearTokenParaDispositivo($dispositivo, 'tablet-252')
            ->plainTextToken;
        $tokenOficina = $despachador
            ->createToken('oficina-pr-252', ['oficina'])
            ->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-252',
            'nombre' => 'Cámara de tránsito',
            'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 2,
            'posiciones_por_banda' => $cantidadPosiciones,
            'cantidad_niveles' => 1,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $administrador);
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $camara,
            $operador,
            $dispositivo,
        );
        $posiciones = [];
        for ($indice = 1; $indice <= $cantidadPosiciones; $indice++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $indice),
            ]);
        }
        $folios = [];
        for ($indice = 1; $indice <= $cantidadFolios; $indice++) {
            $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: sprintf('FOLIO-ANDEN-%02d', $indice),
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $posiciones[$indice - 1],
                sesionDestino: $sesion,
                usuario: $operador,
                dispositivo: $dispositivo,
                versionDestinoConocida: $indice - 1,
                generadoDispositivoAt: now(),
            );
            $folios[] = $movimiento->folio;
        }
        $anden = $this->crearAnden('AND-252', 'Andén principal', $administrador);
        $andenAlternativo = $this->crearAnden('AND-253', 'Andén alternativo', $administrador);

        return compact(
            'administrador',
            'despachador',
            'operador',
            'dispositivo',
            'token',
            'tokenOficina',
            'camara',
            'sesion',
            'posiciones',
            'folios',
            'anden',
            'andenAlternativo',
        );
    }

    private function crearAnden(string $codigo, string $nombre, User $usuario): Anden
    {
        return Anden::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  array<int, Folio>  $folios
     */
    private function crearCargaPublicada(array $contexto, array $folios): Carga
    {
        $servicio = app(ServicioCarga::class);
        $carga = $servicio->crear([
            'anden_previsto_id' => $contexto['anden']->id,
        ], $contexto['despachador']);
        $carga = $servicio->agregarFolios(
            $carga,
            collect($folios)->pluck('numero_folio')->all(),
            $contexto['despachador'],
            $carga->version,
        );

        return $servicio->publicar($carga, $contexto['despachador'], $carga->version);
    }

    /** @param array<string, mixed> $contexto */
    private function registrarPresencia(array $contexto, Carga $carga, Anden $anden): void
    {
        $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/{$carga->id}/camion-en-anden", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $carga->version,
                'anden_id' => $anden->id,
                'patente' => 'ABCD12',
            ])
            ->assertOk();
    }

    /** @param array<string, mixed> $contexto */
    private function finalizarPresencia(array $contexto, Carga $carga): TestResponse
    {
        return $this->conToken($contexto['tokenOficina'])
            ->postJson("/api/cargas/{$carga->id}/camion-en-anden/finalizar", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $carga->version,
                'motivo' => 'Camión retirado del andén por despacho.',
            ]);
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
