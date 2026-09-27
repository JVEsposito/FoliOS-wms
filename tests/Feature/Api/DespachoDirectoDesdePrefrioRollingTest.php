<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\ModalidadSalidaCarga;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Anden;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Prefrio\ServicioGeneracionRecepcionTunel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DespachoDirectoDesdePrefrioRollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_camion_en_anden_reemplaza_recepcion_y_envia_prefrio_directo_sin_camara(): void
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
        ]);

        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => 'TEMP-252-PF',
            'nombre' => 'Temporada PR 252 Prefrío',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
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
            'codigo' => 'TABLET-252-PF',
            'nombre' => 'Tablet PR 252 Prefrío',
        ]);
        $token = $operador
            ->crearTokenParaDispositivo($dispositivo, 'tablet-252-prefrio')
            ->plainTextToken;
        $tokenOficina = $despachador
            ->createToken('oficina-252-prefrio', ['oficina'])
            ->plainTextToken;
        $anden = Anden::create([
            'codigo' => 'AND-252-PF',
            'nombre' => 'Andén Prefrío',
            'activo' => true,
            'creado_por_user_id' => $supervisor->id,
            'actualizado_por_user_id' => $supervisor->id,
        ]);
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-252-PF',
            'nombre' => 'Túnel despacho directo',
            'capacidad_posiciones' => 2,
            'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
            'version_configuracion' => 1,
            'creado_por_user_id' => $supervisor->id,
        ]);
        $posicionTunel = PosicionTunelPrefrio::create([
            'tunel_prefrio_id' => $tunel->id,
            'numero' => 1,
            'etiqueta' => 'TUN-252-PF-P01',
            'activa' => true,
        ]);
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'PAL-252-PF-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now()->subDay(),
            'activo' => true,
            'marca' => 'MACE',
            'exportadora' => 'Exportadora 252',
            'variedad' => 'Santina',
            'calibre' => '2J',
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'PFR-252-PF',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'pfr-252-pf'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => 'aprobado',
            'setpoint' => -1.5,
            'formato_referencia' => 'granel',
            'version' => 5,
            'creado_por_user_id' => $supervisor->id,
            'iniciado_por_user_id' => $supervisor->id,
            'finalizado_por_user_id' => $supervisor->id,
            'iniciado_at' => now()->subHours(8),
            'pendiente_verificacion_at' => now()->subMinutes(5),
            'finalizado_at' => now(),
        ]);
        ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicionTunel->id,
            'estado' => EstadoFolioProcesoPrefrio::Aprobado,
            'temperatura_inicial' => 8.0,
            'temperatura_final' => -0.5,
            'cargado_at' => now()->subHours(8),
            'retirado_at' => now(),
            'cargado_por_user_id' => $supervisor->id,
            'retirado_por_user_id' => $supervisor->id,
        ]);

        $planRecepcion = app(ServicioGeneracionRecepcionTunel::class)->generar(
            $proceso,
            $supervisor,
        );
        $this->assertNotNull($planRecepcion);
        $tareaRecepcion = $planRecepcion->tareas()->sole();
        $this->assertSame('ubicacion_inicial', $tareaRecepcion->tipo_movimiento->value);

        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-252-PF-000001',
            'numero_orden_externa' => 'ORD-252-PF',
            'estado' => EstadoCarga::Pendiente,
            'modalidad_salida' => ModalidadSalidaCarga::DesdeCamara,
            'prioridad' => PrioridadCarga::Alta,
            'anden_previsto_id' => $anden->id,
            'creada_por_user_id' => $despachador->id,
            'actualizada_por_user_id' => $despachador->id,
        ]);
        $carga->refresh();
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'estado' => 'pendiente',
            'asignado_por_user_id' => $despachador->id,
            'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create([
            'folio_id' => $folio->id,
            'carga_folio_id' => $asignacion->id,
        ]);

        $this->conToken($tokenOficina)
            ->postJson("/api/cargas/{$carga->id}/camion-en-anden", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $carga->version,
                'anden_id' => $anden->id,
                'patente' => 'PF2520',
            ])
            ->assertOk();

        $tareaDirecta = TareaMovimiento::query()
            ->whereHas('planOperacional', fn ($consulta) => $consulta
                ->where('tipo', 'despacho_directo'))
            ->where('folio_id', $folio->id)
            ->sole();

        $this->assertSame('cancelada', $tareaRecepcion->refresh()->estado->value);
        $this->assertSame($tareaDirecta->id, $tareaRecepcion->reemplazada_por_tarea_id);
        $this->assertSame('retiro', $tareaDirecta->tipo_movimiento->value);
        $this->assertSame('entrega_anden', $tareaDirecta->tipo_paso_maniobra->value);
        $this->assertSame(1, $tareaDirecta->secuencia_maniobra);
        $this->assertTrue($tareaDirecta->contexto['maniobra_unitaria']);
        $this->assertNull($tareaDirecta->camara_origen_id);
        $this->assertNull($tareaDirecta->posicion_origen_id);
        $this->assertSame('tunel_prefrio', $tareaDirecta->contexto['origen_logico']);
        $this->assertSame($tunel->id, $tareaDirecta->contexto['tunel_prefrio_id']);

        $primeraTareaDirecta = $tareaDirecta;
        $this->conToken($tokenOficina)
            ->postJson("/api/cargas/{$carga->id}/camion-en-anden/finalizar", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $carga->refresh()->version,
                'motivo' => 'El camión se retiró antes de iniciar la carga.',
            ])
            ->assertOk()
            ->assertJsonPath('data.camion_en_anden', null);

        $tareaRestaurada = $planRecepcion->tareas()
            ->where('estado', 'pendiente')
            ->sole();
        $this->assertSame('cancelada', $primeraTareaDirecta->refresh()->estado->value);
        $this->assertSame($tareaRestaurada->id, $primeraTareaDirecta->reemplazada_por_tarea_id);
        $this->assertSame('ubicacion_inicial', $tareaRestaurada->tipo_movimiento->value);
        $this->assertTrue($tareaRestaurada->contexto['restaurada_por_salida_camion']);

        $this->conToken($tokenOficina)
            ->postJson("/api/cargas/{$carga->id}/camion-en-anden", [
                'operacion_id' => (string) Str::uuid(),
                'version_esperada' => $carga->refresh()->version,
                'anden_id' => $anden->id,
                'patente' => 'PF2520',
            ])
            ->assertOk();

        $tareaDirecta = TareaMovimiento::query()
            ->whereHas('planOperacional', fn ($consulta) => $consulta
                ->where('tipo', 'despacho_directo'))
            ->where('folio_id', $folio->id)
            ->where('estado', 'pendiente')
            ->sole();
        $this->assertSame('cancelada', $tareaRestaurada->refresh()->estado->value);
        $this->assertSame($tareaDirecta->id, $tareaRestaurada->reemplazada_por_tarea_id);

        $servicioTareas = app(ServicioPlanesOperacionales::class);
        $servicioTareas->asumir($tareaDirecta, $operador, $dispositivo);
        $servicioTareas->iniciar($tareaDirecta, $operador, $dispositivo);

        $this->conToken($token)
            ->postJson("/api/tareas-movimiento/{$tareaDirecta->id}/completar-prefrio-directo")
            ->assertOk()
            ->assertJsonPath('data.estado', 'completada')
            ->assertJsonPath('data.maniobra.estado', 'completada')
            ->assertJsonPath('data.maniobra.pasos_totales', 1)
            ->assertJsonPath('data.secuencia_maniobra', 1)
            ->assertJsonPath('data.tipo_paso_maniobra', 'entrega_anden')
            ->assertJsonPath('data.destino_logico.tipo', 'anden')
            ->assertJsonPath('data.destino_logico.id', $anden->id);

        $this->assertDatabaseHas('carga_folios', [
            'id' => $asignacion->id,
            'estado' => 'en_anden',
            'anden_id' => $anden->id,
        ]);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        $this->assertDatabaseMissing('movimientos', ['folio_id' => $folio->id]);
        $this->assertSame('completada', $tareaDirecta->refresh()->estado->value);
        $this->assertSame(
            'completada',
            $tareaDirecta->maniobraOperacional()->firstOrFail()->estado->value,
        );
        $this->assertSame('completado', $planRecepcion->refresh()->estado->value);
        $this->assertSame('completado', $tareaDirecta->planOperacional->refresh()->estado->value);
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
