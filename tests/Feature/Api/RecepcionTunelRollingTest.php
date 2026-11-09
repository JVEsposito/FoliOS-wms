<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\ModalidadSalidaCarga;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaTareaMovimiento;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use App\Services\Prefrio\ServicioGeneracionRecepcionTunel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecepcionTunelRollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_genera_objetivo_si_la_generacion_automatica_esta_apagada(): void
    {
        config(['planificador.generacion_automatica' => false]);
        $contexto = $this->crearProcesoAprobado(1);

        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar(
            $contexto['proceso'],
            $contexto['usuario'],
        );

        $this->assertNull($plan);
        $this->assertDatabaseCount('planes_operacionales', 0);
    }

    public function test_no_genera_objetivo_si_el_planificador_no_puede_resolver_rolling(): void
    {
        config([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'off',
            'planificador.compute' => 'server',
        ]);
        $contexto = $this->crearProcesoAprobado(1);

        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar(
            $contexto['proceso'],
            $contexto['usuario'],
        );

        $this->assertNull($plan);
        $this->assertDatabaseCount('planes_operacionales', 0);
    }

    public function test_genera_un_objetivo_rolling_sin_preasignar_destinos_y_es_idempotente(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $servicio = app(ServicioGeneracionRecepcionTunel::class);

        $primero = $servicio->generar($contexto['proceso'], $contexto['usuario']);
        $segundo = $servicio->generar($contexto['proceso'], $contexto['usuario']);

        $this->assertNotNull($primero);
        $this->assertSame($primero->id, $segundo?->id);
        $this->assertSame('recepcion_tunel', $primero->tipo->value);
        $this->assertSame('rolling', $primero->contexto['planner_horizon']);
        $this->assertSame($contexto['proceso']->id, $primero->referencia_id);
        $this->assertSame('proceso_prefrio', $primero->referencia_tipo);
        $this->assertSame(2, $primero->tareas()->count());
        $this->assertSame(2, $primero->maniobras()->count());
        $this->assertSame(1, PlanOperacional::query()
            ->where('referencia_tipo', 'proceso_prefrio')
            ->where('referencia_id', $contexto['proceso']->id)
            ->count());

        $primero->tareas()->get()->each(function ($tarea): void {
            $maniobra = $tarea->maniobraOperacional()->firstOrFail();

            $this->assertSame('ubicacion_inicial', $tarea->tipo_movimiento->value);
            $this->assertSame('movimiento_permanente', $tarea->tipo_paso_maniobra->value);
            $this->assertSame(1, $tarea->secuencia_maniobra);
            $this->assertTrue($tarea->contexto['maniobra_unitaria']);
            $this->assertSame(1, $tarea->contexto['pasos_totales']);
            $this->assertSame('pendiente', $maniobra->estado->value);
            $this->assertSame(1, $maniobra->costo_movimientos);
            $this->assertSame(1, $maniobra->objetivos()->count());
            $this->assertNull($tarea->camara_origen_id);
            $this->assertNull($tarea->posicion_origen_id);
            $this->assertNull($tarea->camara_destino_id);
            $this->assertNull($tarea->posicion_destino_id);
            $this->assertNotNull($tarea->contexto['posicion_tunel_prefrio_id'] ?? null);
            $this->assertSame('Exportadora 251', $tarea->contexto['cliente'] ?? null);
            $this->assertSame('Exportadora 251', $tarea->contexto['exportadora'] ?? null);
        });
    }

    public function test_excluye_saldos_e_inactivos_pero_ubica_cargas_legacy_sin_camion(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(4, [
            2 => TipoBulto::Saldo,
        ]);
        $folios = $contexto['folios'];

        $this->asignarSalidaDirecta($folios[2], $contexto['temporada'], $contexto['usuario']);
        $folios[3]->update(['activo' => false]);

        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar(
            $contexto['proceso'],
            $contexto['usuario'],
        );

        $this->assertNotNull($plan);
        $this->assertSame(2, $plan->tareas()->count());
        $this->assertEqualsCanonicalizing([$folios[0]->id, $folios[2]->id], $plan->tareas()->pluck('folio_id')->all());
    }

    public function test_cierra_recepcion_solo_cuando_todos_los_pallets_se_completan(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar(
            $contexto['proceso'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        [$primera, $segunda] = $plan->tareas()->get()->all();

        $primera->update([
            'estado' => EstadoTareaMovimiento::Completada,
            'responsable_user_id' => $contexto['usuario']->id,
            'completada_at' => now(),
            'version' => $primera->version + 1,
        ]);
        $this->assertSame('programado', $plan->refresh()->estado->value);

        $segunda->update([
            'estado' => EstadoTareaMovimiento::Completada,
            'responsable_user_id' => $contexto['usuario']->id,
            'completada_at' => now(),
            'version' => $segunda->version + 1,
        ]);

        $this->assertSame('completado', $plan->refresh()->estado->value);
        $this->assertSame($contexto['usuario']->id, $plan->completado_por_user_id);
        $this->assertNotNull($plan->completado_at);
    }

    public function test_una_tarea_cancelada_no_declara_recibido_el_tunel(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar(
            $contexto['proceso'],
            $contexto['usuario'],
        );
        $this->assertNotNull($plan);
        [$primera, $segunda] = $plan->tareas()->get()->all();

        $primera->update([
            'estado' => EstadoTareaMovimiento::Cancelada,
            'cancelada_at' => now(),
            'version' => $primera->version + 1,
        ]);
        $segunda->update([
            'estado' => EstadoTareaMovimiento::Completada,
            'responsable_user_id' => $contexto['usuario']->id,
            'completada_at' => now(),
            'version' => $segunda->version + 1,
        ]);

        $this->assertNotSame('completado', $plan->refresh()->estado->value);
    }

    public function test_usa_el_envase_individual_y_conserva_aparte_el_formato_termico_del_tunel(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $contexto['folios'][0]->update(['datos_externos' => ['envase' => 'MAFLV225A']]);
        $contexto['folios'][1]->update(['datos_externos' => ['envase' => 'GFL50M']]);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        $tareas = $plan->tareas->keyBy('folio_id');
        $this->assertSame('MAFLV225A', $tareas[$contexto['folios'][0]->id]->contexto['formato']);
        $this->assertSame('GFL50M', $tareas[$contexto['folios'][1]->id]->contexto['formato']);
        $this->assertSame('granel', $tareas->first()->contexto['formato_prefrio']);
    }

    public function test_carga_asignada_antes_de_liberar_tunel_comparte_recepcion_y_acopio_sin_camion(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $carga = $this->asignarSalidaDirecta($contexto['folios'][0], $contexto['temporada'], $contexto['usuario']);
        $camara = Camara::create([
            'codigo' => 'CAM-ACOPIO', 'nombre' => 'Acopio de carga', 'contenido' => 'productos', 'tipo' => 'transito',
            'estado' => 'activa', 'bandas' => 1, 'posiciones_por_banda' => 5, 'niveles' => 1,
            'creada_por_user_id' => $contexto['usuario']->id,
        ]);
        $carga->update(['camara_objetivo_id' => $camara->id]);
        $recepcion = app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        $tarea = $recepcion->tareas()->where('folio_id', $contexto['folios'][0]->id)->sole();
        $objetivo = PlanOperacional::query()->where('referencia_tipo', 'carga_concentracion')
            ->where('referencia_id', $carga->id)->sole();
        $this->assertSame($camara->id, $tarea->camara_destino_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertSame(2, $tarea->maniobraOperacional->objetivos()->count());
        $this->assertSame(0, $objetivo->tareas()->count());
        $this->assertSame($contexto['tunel']->nombre, $objetivo->contexto['dependencias'][0]['origen']);
        $this->assertSame(1, $objetivo->contexto['recepciones_pendientes']);
        $this->assertDatabaseCount('tareas_movimiento', 2);
    }

    /**
     * @param  array<int, TipoBulto>  $tipos
     * @return array<string, mixed>
     */
    public function test_nunca_pide_estibar_un_saldo_aunque_se_corrija_despues_de_generar(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        [$primera, $segunda] = $plan->tareas()->orderBy('created_at')->orderBy('id')->get()->all();
        $saldo = $primera->folio;
        $saldo->update(['tipo_bulto' => TipoBulto::Saldo]);

        $this->assertSame(1, app(ServicioDepuracionIngresosCamara::class)->depurarTemporada($contexto['temporada']));
        $primera->refresh();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $primera->estado);
        $this->assertSame("{$saldo->numero_folio} es saldo: el planificador no pide estibar saldos.", $primera->motivo_cancelacion);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $segunda->refresh()->estado);
        // Volver a generar no lo reincorpora, y un segundo barrido no encuentra nada.
        app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        $this->assertSame(0, $plan->tareas()->where('folio_id', $saldo->id)->where('estado', '!=', 'cancelada')->count());
        $this->assertSame(0, app(ServicioDepuracionIngresosCamara::class)->depurarTemporada($contexto['temporada']));
    }

    public function test_suelta_la_salida_tomada_con_destino_reservado_de_un_saldo(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(1);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        $tarea = $plan->tareas()->firstOrFail();
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TAB-DEP', 'nombre' => 'Tablet depuración', 'activo' => true]);
        // Tomada por el camarero con destino reservado, sin iniciar el retiro (como #1 y #2 en planta).
        $camara = Camara::create(['codigo' => 'CT07', 'nombre' => 'Camara de tránsito 07']);
        $destino = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $tarea->update(['estado' => EstadoTareaMovimiento::Asumida, 'responsable_user_id' => $camarero->id, 'version' => $tarea->version + 1]);
        $reserva = ReservaTareaMovimiento::create([
            'tarea_movimiento_id' => $tarea->id, 'posicion_destino_id' => $destino->id, 'bloqueo_tarea_id' => $tarea->id,
            'bloqueo_posicion_id' => $destino->id, 'estado' => 'activa', 'user_id' => $camarero->id, 'dispositivo_id' => $dispositivo->id,
            'reservada_at' => now(), 'renovada_at' => now(), 'vence_at' => now()->addMinutes(10), 'version' => 1,
        ]);
        $tarea->folio->update(['tipo_bulto' => TipoBulto::Saldo]);

        $this->artisan('planificador:recalcular-arbitraje')->expectsOutputToContain('Ingresos a cámara soltados: 1.')->assertSuccessful();
        $tarea->refresh();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $tarea->estado);
        $this->assertNotSame('activa', $reserva->refresh()->estado->value);
        if ($tarea->maniobra_operacional_id) {
            $this->assertSame('cancelada', ManiobraOperacional::query()->findOrFail($tarea->maniobra_operacional_id)->estado->value);
        }
    }

    public function test_si_el_camarero_estiba_el_pallet_por_su_cuenta_la_salida_se_da_por_cumplida(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        [$primera, $segunda] = $plan->tareas()->orderBy('created_at')->orderBy('id')->get()->all();
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TAB-EST', 'nombre' => 'Tablet estiba', 'activo' => true]);
        $token = $camarero->crearTokenParaDispositivo($dispositivo, 'tablet-estiba')->plainTextToken;
        $camara = Camara::create(['codigo' => 'CT07', 'nombre' => 'Camara de tránsito 07']);
        $destino = Posicion::create(['camara_id' => $camara->id, 'banda' => 9, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B09-P01-N1']);
        $sesion = $this->withToken($token)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/movimientos/ubicar', [
            'operacion_id' => (string) Str::uuid(), 'numero_folio' => $segunda->folio->numero_folio, 'tipo_bulto' => 'pallet',
            'posicion_destino_id' => $destino->id, 'sesion_destino_id' => $sesion['id'], 'version_destino_conocida' => 0,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();

        $segunda->refresh();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $segunda->estado);
        $this->assertSame("{$segunda->folio->numero_folio} ya está ubicado en Camara de tránsito 07 · B09-P01-N1: la salida se da por cumplida.", $segunda->motivo_cancelacion);
        $this->assertSame($camarero->id, $segunda->cancelada_por_user_id);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $primera->refresh()->estado);
    }

    public function test_el_barrido_suelta_salidas_de_pallets_ya_ubicados_pero_respeta_otras_labores(): void
    {
        $this->habilitarGeneracion();
        $contexto = $this->crearProcesoAprobado(2);
        $plan = app(ServicioGeneracionRecepcionTunel::class)->generar($contexto['proceso'], $contexto['usuario']);
        [$primera, $segunda] = $plan->tareas()->orderBy('created_at')->orderBy('id')->get()->all();
        $camara = Camara::create(['codigo' => 'CT09', 'nombre' => 'Camara 09']);
        foreach ([$primera, $segunda] as $i => $tarea) {
            $posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => $i + 1, 'nivel' => 1, 'etiqueta' => 'B01-P0'.($i + 1).'-N1']);
            UbicacionActual::create(['folio_id' => $tarea->folio_id, 'camara_id' => $camara->id, 'posicion_id' => $posicion->id, 'ubicado_at' => now()]);
        }
        // El segundo tiene otra labor activa que lo saca de su posición: su ubicación es transitoria.
        $otro = $plan->replicate(['id'])->fill(['referencia_id' => (string) Str::uuid(), 'tipo' => TipoPlanOperacional::ReordenamientoCamara]);
        $otro->save();
        $segunda->replicate(['id'])->fill(['plan_operacional_id' => $otro->id, 'tipo_movimiento' => TipoMovimiento::Retiro, 'estado' => EstadoTareaMovimiento::Pendiente])->save();

        $this->assertSame(1, app(ServicioDepuracionIngresosCamara::class)->depurarTemporada($contexto['temporada']));
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $primera->refresh()->estado);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $segunda->refresh()->estado);
    }

    private function crearProcesoAprobado(int $cantidad, array $tipos = []): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-251',
            'nombre' => 'Temporada PR 251',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-251',
            'nombre' => 'Túnel 251',
            'capacidad_posiciones' => max(2, $cantidad),
            'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
            'version_configuracion' => 1,
            'creado_por_user_id' => $usuario->id,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'PFR-251',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'recepcion-tunel-251'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => 'aprobado',
            'setpoint' => -1.5,
            'formato_referencia' => 'granel',
            'version' => 5,
            'creado_por_user_id' => $usuario->id,
            'iniciado_por_user_id' => $usuario->id,
            'finalizado_por_user_id' => $usuario->id,
            'iniciado_at' => now()->subHours(8),
            'pendiente_verificacion_at' => now()->subMinutes(5),
            'finalizado_at' => now(),
        ]);

        $folios = [];
        for ($indice = 1; $indice <= $cantidad; $indice++) {
            $posicion = PosicionTunelPrefrio::create([
                'tunel_prefrio_id' => $tunel->id,
                'numero' => $indice,
                'etiqueta' => sprintf('TUN-251-P%02d', $indice),
                'activa' => true,
            ]);
            $folio = Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => sprintf('PAL-251-%03d', $indice),
                'tipo_bulto' => $tipos[$indice] ?? TipoBulto::Pallet,
                'fecha_ingreso' => now()->subDay()->addMinutes($indice),
                'activo' => true,
                'marca' => 'MACE',
                'exportadora' => 'Exportadora 251',
                'variedad' => 'Santina',
                'calibre' => '2J',
            ]);
            ProcesoPrefrioFolio::create([
                'proceso_prefrio_id' => $proceso->id,
                'folio_id' => $folio->id,
                'posicion_tunel_prefrio_id' => $posicion->id,
                'estado' => EstadoFolioProcesoPrefrio::Aprobado,
                'temperatura_inicial' => 8.0,
                'temperatura_final' => -0.5,
                'cargado_at' => now()->subHours(8),
                'retirado_at' => now(),
                'cargado_por_user_id' => $usuario->id,
                'retirado_por_user_id' => $usuario->id,
            ]);
            $folios[] = $folio;
        }

        return compact('temporada', 'usuario', 'tunel', 'proceso', 'folios');
    }

    private function habilitarGeneracion(): void
    {
        config([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
        ]);
    }

    private function asignarSalidaDirecta(Folio $folio, Temporada $temporada, User $usuario): Carga
    {
        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-251-000001',
            'numero_orden_externa' => 'DIRECTA-251',
            'estado' => EstadoCarga::Pendiente,
            'modalidad_salida' => ModalidadSalidaCarga::DirectaPrefrio,
            'prioridad' => PrioridadCarga::Alta,
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
        ]);
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'estado' => 'pendiente',
            'asignado_por_user_id' => $usuario->id,
            'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create([
            'folio_id' => $folio->id,
            'carga_folio_id' => $asignacion->id,
        ]);

        return $carga;
    }
}
