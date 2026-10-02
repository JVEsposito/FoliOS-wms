<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\ModalidadSalidaCarga;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
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

    /**
     * @param  array<int, TipoBulto>  $tipos
     * @return array<string, mixed>
     */
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

    private function asignarSalidaDirecta(Folio $folio, Temporada $temporada, User $usuario): void
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
    }
}
