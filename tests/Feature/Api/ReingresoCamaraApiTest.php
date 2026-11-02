<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReingresoFolioCamara;
use App\Models\TareaMovimiento;
use App\Models\User;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Existencias\ServicioExistencias;
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
            ->json('data.0');
        $this->assertSame($folio->numero_folio, $fila['folio']['numero_folio']);

        // Repetir la programación no duplica la tarea.
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/reingresos/{$reingreso->id}/programar")->assertOk();
        $this->assertSame(1, TareaMovimiento::query()->where('folio_id', $folio->id)->where('estado', 'pendiente')->count());
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
