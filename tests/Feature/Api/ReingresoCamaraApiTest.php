<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoTareaMovimiento;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\Carga;
use App\Models\Folio;
use App\Models\LaborCamarero;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReingresoFolioCamara;
use App\Models\TareaMovimiento;
use App\Models\TurnoPlanificador;
use App\Models\User;
use App\Services\Cargas\ServicioReingresoCamara;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Existencias\ServicioExistencias;
use App\Services\Planificador\CatalogoLaborCamarero;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class ReingresoCamaraApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public function test_el_reingreso_publica_una_tarea_del_planificador_con_destino_del_servidor(): void
    {
        $this->planificadorGuiado();
        [$contexto, $carga, $folio] = $this->cargaCanceladaConPalletEnAnden();

        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $this->assertNotNull($reingreso->plan_operacional_id);
        $plan = PlanOperacional::query()->with('tareas')->findOrFail($reingreso->plan_operacional_id);
        $this->assertSame('almacenamiento_pallet', $plan->tipo->value);
        $this->assertSame('alta', $plan->prioridad->value);
        $this->assertSame('reingreso_camara', $plan->referencia_tipo);
        $this->assertSame($reingreso->id, $plan->referencia_id);
        $this->assertSame('rolling', $plan->contexto['planner_horizon']);
        $tarea = $plan->tareas->sole();
        $this->assertSame('ubicacion_inicial', $tarea->tipo_movimiento->value);
        $this->assertSame($folio->id, $tarea->folio_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertSame('reingreso_despacho', $tarea->contexto['origen_logico']);
        $this->assertSame($contexto['anden']->codigo, $tarea->contexto['anden']);
        $this->assertTrue($tarea->contexto['pedir_temperatura_pulpa']);

        $fila = $this->conToken($contexto['token'])->getJson('/api/cargas/reingresos')
            ->assertOk()
            ->assertJsonPath('data.0.estado', 'pendiente')
            ->assertJsonPath('data.0.origen_etiqueta', 'Carga cancelada')
            ->assertJsonPath('data.0.carga.codigo', $carga->codigo)
            ->assertJsonPath('data.0.anden', $contexto['anden']->codigo)
            ->assertJsonPath('data.0.tarea.estado', 'pendiente')
            ->assertJsonPath('data.0.programacion.estado', 'programada')
            ->json('data.0');
        $this->assertSame($folio->numero_folio, $fila['folio']['numero_folio']);

        // Repetir la programación no duplica la tarea.
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")->assertOk();
        $this->assertSame(1, TareaMovimiento::query()->where('folio_id', $folio->id)->where('estado', 'pendiente')->count());
        $this->assertSame('reingreso_camara', app(CatalogoLaborCamarero::class)->describir($tarea->fresh('planOperacional'))['funcion']);
    }

    public function test_un_reingreso_anterior_sin_labor_se_recupera_sin_duplicar_el_retorno(): void
    {
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $this->assertNull($reingreso->plan_operacional_id);
        $this->planificadorGuiado();

        $this->conToken($contexto['token'])->getJson('/api/cargas/reingresos')
            ->assertOk()->assertJsonPath('data.0.programacion.puede_programar', true);
        $this->artisan('cargas:recuperar-reingresos')->assertSuccessful();
        $this->artisan('cargas:recuperar-reingresos')->assertSuccessful();

        $this->assertNotNull($reingreso->refresh()->plan_operacional_id);
        $this->assertSame(1, ReingresoFolioCamara::query()->where('folio_id', $folio->id)->count());
        $this->assertSame(1, TareaMovimiento::query()->where('folio_id', $folio->id)->whereIn('estado', ['pendiente', 'asumida', 'en_proceso'])->count());
        $this->assertNull($folio->refresh()->ubicacionActual);
    }

    public function test_la_labor_recuperada_se_ofrece_y_el_camarero_la_puede_tomar(): void
    {
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $this->planificadorGuiado();
        config(['verificaciones.habilitada' => false]);
        $temporadaId = $folio->temporada_id;
        TurnoPlanificador::create([
            'temporada_id' => $temporadaId, 'nombre' => 'Reingresos', 'inicio_at' => now()->subHour(),
            'fin_at' => now()->addHours(8), 'version' => 1, 'creado_por_user_id' => $contexto['administrador']->id,
            'actualizado_por_user_id' => $contexto['administrador']->id,
        ]);
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")
            ->assertOk()->assertJsonPath('data.programacion.estado', 'programada');
        $tarea = $reingreso->refresh()->plan->tareas()->sole();
        app()->call([new RecalcularArbitrajePlanificador($temporadaId), 'handle']);
        $disponibles = $this->conToken($contexto['token'])->getJson('/api/tareas-movimiento?asignacion=disponibles')->assertOk();
        $this->assertContains($tarea->id, array_column($disponibles->json('data'), 'id'));
        $this->conToken($contexto['token'])->postJson("/api/tareas-movimiento/{$tarea->id}/asumir")
            ->assertOk()->assertJsonPath('data.estado', 'asumida');
        $this->assertSame('reingreso_camara', LaborCamarero::query()->where('user_id', $contexto['operador']->id)->sole()->funcion);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")->assertOk();
        $this->assertSame($contexto['operador']->id, $tarea->refresh()->responsable_user_id);
    }

    public function test_reprogramar_una_labor_cancelada_conserva_el_historial_y_no_choca_con_la_referencia(): void
    {
        $this->planificadorGuiado();
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $anterior = $reingreso->plan;
        $tarea = $anterior->tareas()->sole();
        app(ServicioPlanesOperacionales::class)->cancelarPorReplanificacion($tarea, $contexto['administrador'], 'Reprogramar retorno');

        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")
            ->assertOk()->assertJsonPath('data.programacion.estado', 'programada');
        $this->assertNotSame($anterior->id, $reingreso->refresh()->plan_operacional_id);
        $this->assertSame(2, $reingreso->plan->ciclo_referencia);
        $this->assertSame('cancelada', $tarea->refresh()->estado->value);
    }

    public function test_una_tarea_bloqueada_del_mismo_folio_se_informa_y_no_se_duplica(): void
    {
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $this->planificadorGuiado();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $plan = app(ServicioPlanesOperacionales::class)->crear(
            temporada: $folio->temporada,
            tipo: TipoPlanOperacional::AlmacenamientoPallet,
            titulo: 'Otra labor comprometida', creadoPor: $contexto['administrador'],
            tareas: [['folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial]],
            contexto: ['planner_horizon' => 'rolling'],
        );
        $plan->tareas()->sole()->update(['estado' => EstadoTareaMovimiento::Bloqueada]);

        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")->assertStatus(409);
        $this->conToken($contexto['token'])->getJson('/api/cargas/reingresos')
            ->assertOk()->assertJsonPath('data.0.programacion.estado', 'bloqueada')
            ->assertJsonPath('data.0.programacion.puede_programar', false);
        $this->assertStringContainsString('otra labor activa', $reingreso->refresh()->programacion_error);
        $this->assertSame(1, TareaMovimiento::query()->where('folio_id', $folio->id)->count());
    }

    public function test_un_error_de_programacion_se_muestra_sin_exponer_detalles_internos_y_se_puede_reintentar(): void
    {
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $this->planificadorGuiado();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $this->mock(ServicioPlanesOperacionales::class)->shouldReceive('crear')->once()->andThrow(new \RuntimeException('SQL privado'));
        try {
            app(ServicioReingresoCamara::class)->programarUbicacion($reingreso->id);
            $this->fail('La preparación debía fallar.');
        } catch (\RuntimeException $error) {
            $this->assertSame('SQL privado', $error->getMessage());
        }
        $this->assertStringNotContainsString('SQL', $reingreso->refresh()->programacion_error);
        $this->assertNotNull($reingreso->programacion_intentada_at);
        $this->app->forgetInstance(ServicioPlanesOperacionales::class);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")
            ->assertOk()->assertJsonPath('data.programacion.estado', 'programada');
        $this->assertNull($reingreso->refresh()->programacion_error);
    }

    public function test_la_temperatura_de_pulpa_es_opcional_y_queda_como_registro_interno(): void
    {
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        // Sin planificador no hay tarea guiada, pero el pallet sigue en la bandeja.
        $this->assertNull($reingreso->plan_operacional_id);

        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/reingresos/{$reingreso->id}/temperatura", ['temperatura_pulpa' => 55])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('temperatura_pulpa');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->postJson("/api/cargas/reingresos/{$reingreso->id}/temperatura", ['temperatura_pulpa' => 2])
            ->assertForbidden();

        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/reingresos/{$reingreso->id}/temperatura", ['temperatura_pulpa' => 2.36])
            ->assertOk()
            ->assertJsonPath('data.temperatura_pulpa', 2.4)
            ->assertJsonPath('data.temperatura_registrada_por', $contexto['operador']->name)
            ->assertJsonPath('data.tarea', null);

        // El registro no cambia el folio ni lo saca de la bandeja.
        $this->assertSame('pendiente', $reingreso->refresh()->estado->value);
        $this->assertSame($folio->numero_folio, $folio->refresh()->numero_folio);
    }

    public function test_al_ubicarlo_a_mano_se_cierra_el_reingreso_y_se_cancela_la_tarea_que_nadie_tomo(): void
    {
        $this->planificadorGuiado();
        [$contexto, , $folio] = $this->cargaCanceladaConPalletEnAnden();
        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $folio->id)->firstOrFail();
        $posicion = Posicion::query()->where('camara_id', $contexto['camara']->id)->where('posicion', 2)->firstOrFail();

        app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $folio->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $contexto['sesion'],
            usuario: $contexto['operador'],
            dispositivo: $contexto['dispositivo'],
            versionDestinoConocida: $contexto['camara']->refresh()->version_plano,
            generadoDispositivoAt: now(),
        );

        $this->assertSame('ubicado', $reingreso->refresh()->estado->value);
        $this->assertSame('cancelada', TareaMovimiento::query()->where('plan_operacional_id', $reingreso->plan_operacional_id)->value('estado')->value);
        $this->conToken($contexto['token'])->getJson('/api/cargas/reingresos')->assertOk()->assertJsonCount(0, 'data');
        $this->conToken($contexto['token'])->getJson('/api/cargas/reingresos?incluir_ubicados=1')
            ->assertOk()
            ->assertJsonPath('data.0.estado', 'ubicado')
            ->assertJsonPath('data.0.ubicado_en', $contexto['camara']->codigo);
    }

    public function test_operacion_ahora_y_existencias_muestran_el_pallet_pendiente_de_reingreso(): void
    {
        [, $carga, $folio] = $this->cargaCanceladaConPalletEnAnden();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.despacho.resumen.pendientes_reingreso', 1)
            ->assertJsonPath('data.despacho.reingresos.0.folio.numero_folio', $folio->numero_folio)
            ->assertJsonPath('data.despacho.reingresos.0.motivo', 'El cliente anuló el pedido');

        $fila = app(ServicioExistencias::class)->filas(ServicioExistencias::PRODUCTO_TERMINADO, [])
            ->firstWhere('folio', $folio->numero_folio);
        $this->assertSame("Pendiente de reingreso a cámara ({$carga->codigo})", $fila['etapa_actual']);
        $this->assertSame('Pendiente de reingreso', $fila['estado_operacional']);
    }

    /** @return array{0: array<string, mixed>, 1: Carga, 2: Folio} */
    private function cargaCanceladaConPalletEnAnden(): array
    {
        $contexto = $this->crearContextoFrio(2);
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo]);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cancelar", [
            'version_esperada' => $carga->refresh()->version,
            'motivo' => 'El cliente anuló el pedido',
        ])->assertOk();

        return [$contexto, $carga->refresh(), $segundo];
    }

    private function planificadorGuiado(): void
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.compute' => 'server',
            'planificador.horizon' => 'rolling',
            'planificador.generacion_automatica' => true,
        ]);
    }
}
