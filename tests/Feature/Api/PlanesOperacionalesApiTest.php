<?php

namespace Tests\Feature\Api;

use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaTareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioSesionEstiba;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanesOperacionalesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_un_plan_ordenado_solo_para_pallets_completos(): void
    {
        $contexto = $this->crearContexto();
        $servicio = app(ServicioPlanesOperacionales::class);

        $plan = $servicio->crear(
            temporada: $contexto['temporada'],
            tipo: TipoPlanOperacional::ReordenamientoCamara,
            titulo: 'Concentrar pallets de Exportadora Norte',
            creadoPor: $contexto['supervisor'],
            tareas: [
                [
                    'folio_id' => $contexto['folios'][0]->id,
                    'tipo_movimiento' => TipoMovimiento::Reubicacion,
                    'camara_origen_id' => $contexto['camara']->id,
                    'posicion_origen_id' => $contexto['posiciones'][0]->id,
                    'camara_destino_id' => $contexto['camara']->id,
                    'posicion_destino_id' => $contexto['posiciones'][1]->id,
                    'instruccion' => 'Mover primero para liberar el acceso.',
                ],
                [
                    'folio_id' => $contexto['folios'][1]->id,
                    'tipo_movimiento' => TipoMovimiento::Retiro,
                    'prioridad' => PrioridadOperacional::Urgente,
                    'camara_origen_id' => $contexto['camara']->id,
                    'posicion_origen_id' => $contexto['posiciones'][2]->id,
                ],
            ],
            prioridad: PrioridadOperacional::Alta,
            motivo: 'Preparación operacional controlada.',
            referenciaTipo: 'operacion_manual',
            referenciaId: (string) Str::uuid(),
        );

        $this->assertSame('programado', $plan->estado->value);
        $this->assertSame([1, 2], $plan->tareas->pluck('secuencia')->all());
        $this->assertSame(2, $plan->maniobras()->count());
        $this->assertTrue($plan->tareas->every(
            fn ($tarea): bool => $tarea->maniobra_operacional_id !== null
                && $tarea->secuencia_maniobra === 1
                && $tarea->tipo_paso_maniobra->value === 'movimiento_permanente',
        ));
        $this->assertSame('alta', $plan->tareas[0]->prioridad->value);
        $this->assertSame('urgente', $plan->tareas[1]->prioridad->value);
        $this->assertDatabaseHas('planes_operacionales', [
            'id' => $plan->id,
            'temporada_id' => $contexto['temporada']->id,
            'tipo' => 'reordenamiento_camara',
        ]);

        $saldo = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => 'SALDO-001',
            'tipo_bulto' => TipoBulto::Saldo,
            'fecha_ingreso' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('solo admiten pallets completos');
        $servicio->crear(
            temporada: $contexto['temporada'],
            tipo: TipoPlanOperacional::AlmacenamientoPallet,
            titulo: 'Intento con saldo',
            creadoPor: $contexto['supervisor'],
            tareas: [[
                'folio_id' => $saldo->id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'camara_destino_id' => $contexto['camara']->id,
                'posicion_destino_id' => $contexto['posiciones'][3]->id,
            ]],
        );
    }

    public function test_bandeja_permite_asumir_y_liberar_una_tarea_de_forma_exclusiva(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlan($contexto);
        $tarea = $plan->tareas->firstOrFail();

        $this->conToken($contexto['token'])
            ->getJson('/api/tareas-movimiento')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $tarea->id)
            ->assertJsonPath('data.0.folio.numero_folio', 'PALLET-001')
            ->assertJsonPath('data.0.destino.camara.nombre', 'Cámara tránsito');
        $planoLibre = $this->conToken($contexto['token'])
            ->getJson("/api/camaras/{$contexto['camara']->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.posiciones.0.reservada', false);
        $etagPlanoLibre = $planoLibre->headers->get('ETag');
        $this->assertNotNull($etagPlanoLibre);

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk()
            ->assertJsonPath('data.estado', 'asumida')
            ->assertJsonPath('data.responsable.id', $contexto['camarero']->id)
            ->assertJsonPath('data.dispositivo.id', $contexto['dispositivo']->id)
            ->assertJsonPath('data.reserva.destino_reservado', true)
            ->assertJsonPath('data.reserva.version', 1);

        $this->assertDatabaseHas('planes_operacionales', [
            'id' => $plan->id,
            'estado' => 'en_ejecucion',
            'iniciado_por_user_id' => $contexto['camarero']->id,
        ]);
        $this->conToken($contexto['token'])
            ->withHeader('If-None-Match', $etagPlanoLibre)
            ->getJson("/api/camaras/{$contexto['camara']->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.reservadas', 1)
            ->assertJsonPath('data.bandas_operacionales.0.capacidad.disponibles', 3)
            ->assertJsonPath('data.posiciones.0.reservada', true)
            ->assertJsonPath(
                'data.posiciones.0.reserva_operacional.tarea_movimiento_id',
                $tarea->id,
            );

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->conToken($contexto['tokenOtro'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->conToken($contexto['tokenOtro'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/liberar")
            ->assertConflict();

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/renovar")
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.reserva.version', 3);

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/liberar")
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.responsable', null)
            ->assertJsonPath('data.version', 3);

        $this->assertDatabaseHas('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'estado' => 'liberada',
            'bloqueo_tarea_id' => null,
            'bloqueo_posicion_id' => null,
        ]);
    }

    public function test_bandeja_detalle_y_asignacion_exponen_los_atributos_reales_del_folio(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlan($contexto);
        $tarea = $plan->tareas->firstOrFail();
        $fechaIngreso = $contexto['folios'][0]->fecha_ingreso->toAtomString();

        $this->conToken($contexto['token'])
            ->getJson('/api/tareas-movimiento')
            ->assertOk()
            ->assertJsonPath('data.0.folio.variedad', 'Santina')
            ->assertJsonPath('data.0.folio.calibre', 'XL')
            ->assertJsonPath('data.0.folio.marca', 'Estiba Premium')
            ->assertJsonPath('data.0.folio.exportadora', 'Exportadora Norte')
            ->assertJsonPath('data.0.folio.fecha_ingreso', $fechaIngreso);

        $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.tareas.0.folio.variedad', 'Santina')
            ->assertJsonPath('data.tareas.0.folio.calibre', 'XL')
            ->assertJsonPath('data.tareas.0.folio.marca', 'Estiba Premium')
            ->assertJsonPath('data.tareas.0.folio.exportadora', 'Exportadora Norte')
            ->assertJsonPath('data.tareas.0.folio.fecha_ingreso', $fechaIngreso);

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk()
            ->assertJsonPath('data.folio.variedad', 'Santina')
            ->assertJsonPath('data.folio.calibre', 'XL')
            ->assertJsonPath('data.folio.marca', 'Estiba Premium')
            ->assertJsonPath('data.folio.exportadora', 'Exportadora Norte')
            ->assertJsonPath('data.folio.fecha_ingreso', $fechaIngreso);
    }

    public function test_dos_tareas_no_pueden_reservar_el_mismo_destino(): void
    {
        $contexto = $this->crearContexto();
        $primera = $this->crearPlan($contexto)->tareas->firstOrFail();
        $segundoPlan = app(ServicioPlanesOperacionales::class)->crear(
            temporada: $contexto['temporada'],
            tipo: TipoPlanOperacional::AlmacenamientoPallet,
            titulo: 'Segundo pallet al mismo destino',
            creadoPor: $contexto['supervisor'],
            tareas: [[
                'folio_id' => $contexto['folios'][1]->id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'camara_destino_id' => $contexto['camara']->id,
                'posicion_destino_id' => $contexto['posiciones'][0]->id,
            ]],
        );
        $segunda = $segundoPlan->tareas->firstOrFail();

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$primera->id}/asumir")
            ->assertOk();

        $this->conToken($contexto['tokenOtro'])
            ->postJson("/api/tareas-movimiento/{$segunda->id}/asumir")
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertSame(1, ReservaTareaMovimiento::query()
            ->whereNotNull('bloqueo_posicion_id')
            ->count());
        $this->assertSame('pendiente', $segunda->refresh()->estado->value);
    }

    public function test_un_lease_vencido_libera_tarea_y_destino_para_otra_tablet(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlan($contexto)->tareas->firstOrFail();

        $this->conToken($contexto['token'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk();

        $this->travel(11)->minutes();

        $this->conToken($contexto['tokenOtro'])
            ->getJson('/api/tareas-movimiento')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.estado', 'pendiente')
            ->assertJsonPath('data.0.reserva', null);

        $this->assertDatabaseHas('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'estado' => 'expirada',
            'bloqueo_tarea_id' => null,
            'bloqueo_posicion_id' => null,
        ]);
        $this->assertSame($contexto['camara']->id, $tarea->refresh()->camara_destino_id);
        $this->assertSame($contexto['posiciones'][3]->id, $tarea->posicion_destino_id);

        $this->conToken($contexto['tokenOtro'])
            ->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk()
            ->assertJsonPath('data.responsable.id', $contexto['otroCamarero']->id);
    }

    public function test_el_movimiento_reservado_completa_tarea_plan_y_lease_atomicamente(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlan($contexto);
        $tarea = $plan->tareas->firstOrFail();

        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $contexto['camara'],
            $contexto['camarero'],
            $contexto['dispositivo'],
        );

        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $contexto['folios'][0]->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $contexto['posiciones'][0],
            sesionDestino: $sesion,
            usuario: $contexto['camarero'],
            dispositivo: $contexto['dispositivo'],
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
            tareaMovimiento: $tarea,
        );

        $this->assertSame($plan->id, $movimiento->plan_operacional_id);
        $this->assertSame($tarea->id, $movimiento->tarea_movimiento_id);
        $this->assertSame('completada', $tarea->refresh()->estado->value);
        $this->assertSame('completado', $plan->refresh()->estado->value);
        $this->assertDatabaseHas('reservas_tareas_movimiento', [
            'tarea_movimiento_id' => $tarea->id,
            'estado' => 'completada',
            'bloqueo_tarea_id' => null,
            'bloqueo_posicion_id' => null,
        ]);
    }

    public function test_un_movimiento_manual_no_puede_ocupar_un_pallet_o_destino_reservado(): void
    {
        $contexto = $this->crearContexto();
        $tarea = $this->crearPlan($contexto)->tareas->firstOrFail();
        app(ServicioPlanesOperacionales::class)->asumir(
            $tarea,
            $contexto['camarero'],
            $contexto['dispositivo'],
        );
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $contexto['camara'],
            $contexto['camarero'],
            $contexto['dispositivo'],
        );

        try {
            app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: $contexto['folios'][0]->numero_folio,
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $contexto['posiciones'][0],
                sesionDestino: $sesion,
                usuario: $contexto['camarero'],
                dispositivo: $contexto['dispositivo'],
                versionDestinoConocida: 0,
                generadoDispositivoAt: now(),
            );
            $this->fail('Se esperaba el rechazo del movimiento sin su tarea reservada.');
        } catch (ConflictoOperacion $exception) {
            $this->assertStringContainsString('reservad', $exception->getMessage());
        }

        try {
            app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: $contexto['folios'][0]->numero_folio,
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $contexto['posiciones'][1],
                sesionDestino: $sesion,
                usuario: $contexto['camarero'],
                dispositivo: $contexto['dispositivo'],
                versionDestinoConocida: 0,
                generadoDispositivoAt: now(),
            );
            $this->fail('Se esperaba el rechazo del pallet reservado hacia otro destino.');
        } catch (ConflictoOperacion $exception) {
            $this->assertStringContainsString('pallet', $exception->getMessage());
        }

        try {
            app(ServicioMovimientoEstiba::class)->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: 'PALLET-MANUAL-NUEVO',
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $contexto['posiciones'][0],
                sesionDestino: $sesion,
                usuario: $contexto['camarero'],
                dispositivo: $contexto['dispositivo'],
                versionDestinoConocida: 0,
                generadoDispositivoAt: now(),
            );
            $this->fail('Se esperaba el rechazo del destino reservado.');
        } catch (ConflictoOperacion $exception) {
            $this->assertStringContainsString('posición', $exception->getMessage());
        }

        $this->assertSame('asumida', $tarea->refresh()->estado->value);
        $this->assertDatabaseMissing('folios', [
            'numero_folio' => 'PALLET-MANUAL-NUEVO',
        ]);
        $this->assertDatabaseMissing('movimientos', [
            'folio_id' => $contexto['folios'][0]->id,
        ]);
    }

    public function test_las_consultas_se_limitan_a_la_temporada_activa_y_al_rol_operacional(): void
    {
        $contexto = $this->crearContexto();
        $plan = $this->crearPlan($contexto);
        $consulta = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'activo' => true,
        ]);
        $tokenConsulta = $consulta->createToken('consulta', ['oficina'])->plainTextToken;

        $this->conToken($contexto['token'])
            ->getJson('/api/planes-operacionales')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $plan->id)
            ->assertJsonPath('data.0.total_tareas', 1);

        $this->conToken($tokenConsulta)
            ->getJson('/api/planes-operacionales')
            ->assertForbidden();

        $contexto['temporada']->update(['activa' => false]);
        Temporada::create([
            'codigo' => 'TEMP-2027',
            'nombre' => 'Temporada 2027',
            'fecha_inicio' => '2027-01-01',
            'activa' => true,
        ]);

        $this->conToken($contexto['token'])
            ->getJson('/api/planes-operacionales')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->conToken($contexto['token'])
            ->getJson("/api/planes-operacionales/{$plan->id}")
            ->assertNotFound();
    }

    public function test_un_conflicto_de_asignacion_no_es_una_regla_generica(): void
    {
        $this->assertTrue(is_subclass_of(ConflictoOperacion::class, DomainException::class));
        $this->assertFalse(config('planificador.generacion_automatica'));
    }

    /** @param array<string, mixed> $contexto */
    private function crearPlan(array $contexto): PlanOperacional
    {
        return app(ServicioPlanesOperacionales::class)->crear(
            temporada: $contexto['temporada'],
            tipo: TipoPlanOperacional::AlmacenamientoPallet,
            titulo: 'Almacenar pallet completo',
            creadoPor: $contexto['supervisor'],
            tareas: [[
                'folio_id' => $contexto['folios'][0]->id,
                'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'camara_destino_id' => $contexto['camara']->id,
                'posicion_destino_id' => $contexto['posiciones'][0]->id,
            ]],
        );
    }

    /** @return array<string, mixed> */
    private function crearContexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-2026',
            'nombre' => 'Temporada 2026',
            'fecha_inicio' => '2026-01-01',
            'activa' => true,
        ]);
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $camarero = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $otroCamarero = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-01',
            'nombre' => 'Tablet 01',
        ]);
        $otroDispositivo = Dispositivo::create([
            'codigo' => 'TABLET-02',
            'nombre' => 'Tablet 02',
        ]);
        $token = $camarero
            ->crearTokenParaDispositivo($dispositivo, 'tablet-01')
            ->plainTextToken;
        $tokenOtro = $otroCamarero
            ->crearTokenParaDispositivo($otroDispositivo, 'tablet-02')
            ->plainTextToken;
        $camara = Camara::create([
            'codigo' => 'CAM-01',
            'nombre' => 'Cámara tránsito',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 4,
            'cantidad_niveles' => 1,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara->refresh(), $supervisor);
        $posiciones = [];

        for ($indice = 1; $indice <= 4; $indice++) {
            $posiciones[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $indice,
                'nivel' => 1,
                'etiqueta' => sprintf('B01-P%02d-N1', $indice),
            ]);
        }

        $folios = [1, 2];
        $folios = array_map(fn (int $indice): Folio => Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => sprintf('PALLET-%03d', $indice),
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now()->subHours($indice),
            'variedad' => $indice === 1 ? 'Santina' : null,
            'calibre' => $indice === 1 ? 'XL' : null,
            'marca' => $indice === 1 ? 'Estiba Premium' : null,
            'exportadora' => $indice === 1 ? 'Exportadora Norte' : null,
        ]), $folios);

        return compact(
            'temporada',
            'supervisor',
            'camarero',
            'otroCamarero',
            'dispositivo',
            'otroDispositivo',
            'token',
            'tokenOtro',
            'camara',
            'posiciones',
            'folios',
        );
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
