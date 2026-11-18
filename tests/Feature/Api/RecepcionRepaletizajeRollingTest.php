<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\Repaletizaje;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;
use App\Services\Validacion\ServicioPrioridadBufferRepaletizaje;
use App\Services\Validacion\ServicioRepaletizaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecepcionRepaletizajeRollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_genera_objetivo_si_la_generacion_automatica_esta_apagada(): void
    {
        config(['planificador.generacion_automatica' => false]);
        [$temporada, $usuario] = $this->contexto();

        $this->registrar(
            $temporada,
            $usuario,
            'OFF',
            CondicionTermicaFolio::PrefrioAprobado,
        );

        $this->assertDatabaseCount('planes_operacionales', 0);
    }

    public function test_genera_retiro_rolling_con_origen_real_sin_carga_y_es_idempotente(): void
    {
        $this->habilitarGeneracion();
        [$temporada, $usuario] = $this->contexto();
        [$repa, $payload] = $this->registrar(
            $temporada,
            $usuario,
            'REAL',
            CondicionTermicaFolio::PrefrioAprobado,
            conUbicacion: true,
        );

        $repetido = app(ServicioRepaletizaje::class)->registrar($payload, $usuario);
        $plan = PlanOperacional::query()
            ->where('referencia_tipo', 'repaletizaje')
            ->where('referencia_id', $repa->id)
            ->firstOrFail();
        $tarea = $plan->tareas()->firstOrFail();
        $ubicacion = $repa->resultados()->firstOrFail()->folio->ubicacionActual;

        $this->assertSame($repa->id, $repetido->id);
        $this->assertSame('recepcion_repaletizaje', $plan->tipo->value);
        $this->assertSame('normal', $plan->prioridad->value);
        $this->assertSame('rolling', $plan->contexto['planner_horizon']);
        $this->assertSame('repaletizaje', $plan->contexto['origen_logico']);
        $this->assertTrue($plan->contexto['origen_repa_sin_blockers']);
        $this->assertSame(1, $plan->tareas()->count());
        $this->assertSame(1, $plan->maniobras()->count());
        $this->assertSame(1, PlanOperacional::query()
            ->where('referencia_tipo', 'repaletizaje')
            ->where('referencia_id', $repa->id)
            ->count());
        $this->assertSame('traslado_entre_camaras', $tarea->tipo_movimiento->value);
        $this->assertSame('movimiento_permanente', $tarea->tipo_paso_maniobra->value);
        $this->assertSame(1, $tarea->secuencia_maniobra);
        $this->assertTrue($tarea->contexto['maniobra_unitaria']);
        $this->assertSame(1, $tarea->contexto['pasos_totales']);
        $this->assertSame('pendiente', $tarea->maniobraOperacional()->firstOrFail()->estado->value);
        $this->assertSame($ubicacion?->camara_id, $tarea->camara_origen_id);
        $this->assertSame($ubicacion?->posicion_id, $tarea->posicion_origen_id);
        $this->assertNull($tarea->camara_destino_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertSame($repa->id, $tarea->contexto['repaletizaje_id']);
        $this->assertTrue($tarea->contexto['origen_repa_sin_blockers']);

        $tarea->update([
            'estado' => EstadoTareaMovimiento::Completada,
            'responsable_user_id' => $usuario->id,
            'completada_at' => now(),
            'version' => $tarea->version + 1,
        ]);

        $this->assertSame('completado', $plan->refresh()->estado->value);
    }

    public function test_sin_ubicacion_fisica_crea_ubicacion_inicial_sin_preasignar_destino(): void
    {
        $this->habilitarGeneracion();
        [$temporada, $usuario] = $this->contexto();
        [$repa] = $this->registrar(
            $temporada,
            $usuario,
            'LOGICO',
            CondicionTermicaFolio::PrefrioAprobado,
        );

        $tarea = PlanOperacional::query()
            ->where('referencia_id', $repa->id)
            ->firstOrFail()
            ->tareas()
            ->firstOrFail();

        $this->assertSame('ubicacion_inicial', $tarea->tipo_movimiento->value);
        $this->assertSame('movimiento_permanente', $tarea->tipo_paso_maniobra->value);
        $this->assertSame(1, $tarea->secuencia_maniobra);
        $this->assertNull($tarea->camara_origen_id);
        $this->assertNull($tarea->posicion_origen_id);
        $this->assertNull($tarea->camara_destino_id);
        $this->assertNull($tarea->posicion_destino_id);
        $this->assertSame('repaletizaje', $tarea->contexto['origen_logico']);
    }

    public function test_escala_todo_el_buffer_a_alta_desde_ocho_y_a_urgente_desde_diez_pallets(): void
    {
        $this->habilitarGeneracion();
        [$temporada, $usuario] = $this->contexto();

        foreach (range(1, 7) as $indice) {
            $this->registrar(
                $temporada,
                $usuario,
                sprintf('BUFFER-%02d', $indice),
                CondicionTermicaFolio::PrefrioAprobado,
            );
        }

        $this->assertSame(
            7,
            PlanOperacional::query()->where('prioridad', 'normal')->count(),
        );

        $this->registrar(
            $temporada,
            $usuario,
            'BUFFER-08',
            CondicionTermicaFolio::PrefrioAprobado,
        );

        $planesAltos = PlanOperacional::query()->get();
        $this->assertCount(8, $planesAltos);
        // La lectura del tablero usa la misma regla que el recálculo.
        $estadoTablero = app(ServicioPrioridadBufferRepaletizaje::class)->estado($temporada->id);
        $this->assertSame(8, $estadoTablero['pallets_pendientes']);
        $this->assertSame('alta', $estadoTablero['prioridad']->value);
        $this->assertSame(10, $estadoTablero['maximo']);
        $this->assertTrue($planesAltos->every(
            fn (PlanOperacional $plan): bool => $plan->prioridad->value === 'alta'
                && $plan->contexto['buffer_repa_pallets_pendientes'] === 8
                && $plan->contexto['buffer_repa_umbral_alta'] === 8
                && $plan->contexto['buffer_repa_maximo'] === 10,
        ));
        $this->assertSame(
            8,
            $planesAltos->flatMap->tareas->where('prioridad.value', 'alta')->count(),
        );
        $this->assertSame(
            8,
            $planesAltos->flatMap->maniobras->where('prioridad.value', 'alta')->count(),
        );

        $this->registrar(
            $temporada,
            $usuario,
            'BUFFER-09',
            CondicionTermicaFolio::PrefrioAprobado,
        );
        $this->registrar(
            $temporada,
            $usuario,
            'BUFFER-10',
            CondicionTermicaFolio::PrefrioAprobado,
        );

        $planesUrgentes = PlanOperacional::query()->with('tareas')->get();
        $this->assertCount(10, $planesUrgentes);
        $this->assertTrue($planesUrgentes->every(
            fn (PlanOperacional $plan): bool => $plan->prioridad->value === 'urgente'
                && $plan->contexto['buffer_repa_prioridad'] === 'urgente'
                && $plan->contexto['buffer_repa_pallets_pendientes'] === 10,
        ));
        $this->assertSame(
            10,
            $planesUrgentes->flatMap->tareas->where('prioridad.value', 'urgente')->count(),
        );
        $this->assertSame(
            10,
            $planesUrgentes->flatMap->maniobras->where('prioridad.value', 'urgente')->count(),
        );
    }

    public function test_desescala_las_labores_pendientes_cuando_el_movimiento_libera_el_buffer(): void
    {
        $this->habilitarGeneracion();
        config([
            'planificador.repa_buffer_high_from_pallets' => 2,
            'planificador.repa_buffer_max_pallets' => 3,
        ]);
        [$temporada, $usuario] = $this->contexto();

        foreach (range(1, 3) as $indice) {
            $this->registrar(
                $temporada,
                $usuario,
                "LIBERA-{$indice}",
                CondicionTermicaFolio::PrefrioAprobado,
            );
        }

        $tareas = PlanOperacional::query()
            ->with('tareas')
            ->orderBy('created_at')
            ->get()
            ->map->tareas
            ->flatten()
            ->values();
        $this->assertTrue($tareas->every(
            fn ($tarea): bool => $tarea->prioridad->value === 'urgente',
        ));

        $tareas[0]->update(['estado' => EstadoTareaMovimiento::Asumida]);
        $tareas[0]->update(['estado' => EstadoTareaMovimiento::EnProceso]);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => ServicioRecalculosPendientesPlanificador::BUFFER_REPA,
            'fuente_id' => $tareas[0]->id,
            'objetivo_id' => $temporada->id,
            'version_calculada' => 0,
        ]);
        app(ServicioRecalculosPendientesPlanificador::class)
            ->ejecutar(ServicioRecalculosPendientesPlanificador::BUFFER_REPA, $tareas[0]->id);

        $restantes = PlanOperacional::query()
            ->whereKeyNot($tareas[0]->plan_operacional_id)
            ->with('tareas')
            ->get();
        $this->assertTrue($restantes->every(
            fn (PlanOperacional $plan): bool => $plan->prioridad->value === 'alta'
                && $plan->contexto['buffer_repa_pallets_pendientes'] === 2
                && $plan->tareas->first()->prioridad->value === 'alta',
        ));

        $tareas[1]->refresh()->update(['estado' => EstadoTareaMovimiento::Asumida]);
        $tareas[1]->refresh()->update(['estado' => EstadoTareaMovimiento::EnProceso]);
        app(ServicioRecalculosPendientesPlanificador::class)
            ->ejecutar(ServicioRecalculosPendientesPlanificador::BUFFER_REPA, $tareas[1]->id);

        $ultimo = PlanOperacional::query()
            ->whereKeyNot($tareas[0]->plan_operacional_id)
            ->whereKeyNot($tareas[1]->plan_operacional_id)
            ->with('tareas')
            ->sole();
        $this->assertSame('normal', $ultimo->prioridad->value);
        $this->assertSame(1, $ultimo->contexto['buffer_repa_pallets_pendientes']);
        $this->assertSame('normal', $ultimo->tareas->first()->prioridad->value);
    }

    public function test_excluye_resultados_saldo_y_pallets_pendientes_de_prefrio(): void
    {
        $this->habilitarGeneracion();
        [$temporada, $usuario] = $this->contexto();
        [$pendiente] = $this->registrar(
            $temporada,
            $usuario,
            'PEND',
            CondicionTermicaFolio::PendientePrefrio,
        );
        [$saldo] = $this->registrar(
            $temporada,
            $usuario,
            'SALDO',
            CondicionTermicaFolio::PrefrioAprobado,
            tipoResultado: TipoBulto::Saldo,
        );

        $this->assertFalse(PlanOperacional::query()
            ->where('referencia_tipo', 'repaletizaje')
            ->whereIn('referencia_id', [$pendiente->id, $saldo->id])
            ->exists());
    }

    public function test_anular_repa_cancela_su_objetivo_pendiente(): void
    {
        $this->habilitarGeneracion();
        [$temporada, $usuario] = $this->contexto();
        [$repa] = $this->registrar(
            $temporada,
            $usuario,
            'ANULA',
            CondicionTermicaFolio::PrefrioAprobado,
        );
        $plan = PlanOperacional::query()->where('referencia_id', $repa->id)->firstOrFail();

        app(ServicioRepaletizaje::class)->anular(
            $repa,
            (string) Str::uuid(),
            'Operación REPA ingresada por error.',
            $usuario,
        );

        $this->assertSame('cancelado', $plan->refresh()->estado->value);
        $this->assertSame('cancelada', $plan->tareas()->firstOrFail()->estado->value);
        $this->assertSame($usuario->id, $plan->cancelado_por_user_id);
    }

    public function test_si_el_camarero_ubica_el_pallet_de_repa_el_retiro_se_cumple_y_baja_la_prioridad(): void
    {
        $this->habilitarGeneracion();
        config(['planificador.repa_buffer_high_from_pallets' => 2, 'planificador.repa_buffer_max_pallets' => 3]);
        [$temporada, $usuario] = $this->contexto();
        foreach (range(1, 3) as $indice) {
            $this->registrar($temporada, $usuario, "DIRECTO-{$indice}", CondicionTermicaFolio::PrefrioAprobado);
        }
        $tareas = TareaMovimiento::query()->with('planOperacional')->orderBy('created_at')->orderBy('id')->get();
        $this->assertTrue($tareas->every(fn (TareaMovimiento $tarea): bool => $tarea->prioridad->value === 'urgente'));
        $retiro = $tareas->firstWhere('folio.numero_folio', 'RES-DIRECTO-1');
        $this->assertSame('ubicacion_inicial', $retiro->tipo_movimiento->value);

        // El camarero lo ubica directamente, sin tomar la labor del planificador.
        [$camarero, $token, $camara] = $this->camarero('CAM-09', 'Camara 09');
        $posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 2, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B02-P01-N1']);
        $sesion = $this->withToken($token)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data');
        $this->withToken($token)->postJson('/api/movimientos/ubicar', [
            'operacion_id' => (string) Str::uuid(), 'numero_folio' => 'RES-DIRECTO-1', 'tipo_bulto' => 'pallet',
            'posicion_destino_id' => $posicion->id, 'sesion_destino_id' => $sesion['id'], 'version_destino_conocida' => 0,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();

        $retiro->refresh();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $retiro->estado);
        $this->assertSame('RES-DIRECTO-1 ya está ubicado en Camara 09 · B02-P01-N1: el retiro de REPA se da por cumplido.', $retiro->motivo_cancelacion);
        $this->assertTrue($retiro->contexto['cumplida_fuera_planificador']);
        $this->assertSame($camarero->id, $retiro->cancelada_por_user_id);
        $this->assertSame('cancelada', $retiro->maniobraOperacional()->firstOrFail()->estado->value);
        $plan = $retiro->planOperacional->refresh();
        $this->assertSame('completado', $plan->estado->value);
        $this->assertSame($camarero->id, $plan->completado_por_user_id);

        // Quedan dos pallets en REPA: la prioridad compartida baja de urgente a alta.
        $restantes = $tareas->reject(fn (TareaMovimiento $tarea): bool => $tarea->id === $retiro->id);
        foreach ($restantes as $tarea) {
            $tarea->refresh();
            $this->assertSame(EstadoTareaMovimiento::Pendiente, $tarea->estado);
            $this->assertSame('alta', $tarea->prioridad->value);
            $this->assertSame(2, $tarea->planOperacional->refresh()->contexto['buffer_repa_pallets_pendientes']);
        }
    }

    public function test_el_barrido_cierra_retiros_de_repa_de_pallets_que_ya_salieron_y_respeta_los_que_siguen_en_repa(): void
    {
        $this->habilitarGeneracion();
        [$temporada, $usuario] = $this->contexto();
        $this->registrar($temporada, $usuario, 'UBICADO', CondicionTermicaFolio::PrefrioAprobado);
        $this->registrar($temporada, $usuario, 'ENREPA', CondicionTermicaFolio::PrefrioAprobado);
        [$repaConOrigen] = $this->registrar($temporada, $usuario, 'ORIGEN', CondicionTermicaFolio::PrefrioAprobado, conUbicacion: true);
        $retiros = TareaMovimiento::query()->with('folio')->get()->keyBy('folio.numero_folio');
        $camara = Camara::create(['codigo' => 'CAM-09', 'nombre' => 'Camara 09']);
        $lugar = fn (int $posicion): Posicion => Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => $posicion, 'nivel' => 1, 'etiqueta' => "B01-P0{$posicion}-N1"]);

        // Como en planta: el pallet quedó ubicado antes de que existiera el cierre automático.
        UbicacionActual::create(['folio_id' => $retiros['RES-UBICADO']->folio_id, 'camara_id' => $camara->id, 'posicion_id' => $lugar(1)->id, 'ubicado_at' => now()]);
        $this->assertSame('traslado_entre_camaras', $retiros['RES-ORIGEN']->tipo_movimiento->value);

        $this->artisan('planificador:recalcular-arbitraje')->expectsOutputToContain('Ingresos a cámara soltados: 1.')->assertSuccessful();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $retiros['RES-UBICADO']->refresh()->estado);
        $this->assertSame('completado', $retiros['RES-UBICADO']->planOperacional->estado->value);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $retiros['RES-ENREPA']->refresh()->estado);
        // Sigue en la posición donde lo dejó la repa: su retiro sigue vigente.
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $retiros['RES-ORIGEN']->refresh()->estado);

        // El camarero lo mueve por su cuenta desde esa posición: el retiro también queda cumplido.
        [, $token] = $this->camarero('CAM-ORIGEN', null);
        $origen = UbicacionActual::query()->where('folio_id', $retiros['RES-ORIGEN']->folio_id)->firstOrFail();
        $sesionOrigen = $this->withToken($token)->postJson("/api/camaras/{$origen->camara_id}/sesiones")->assertCreated()->json('data');
        $sesionDestino = $this->withToken($token)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data');
        $this->withToken($token)->postJson('/api/movimientos/mover', [
            'operacion_id' => (string) Str::uuid(), 'folio_id' => $retiros['RES-ORIGEN']->folio_id,
            'posicion_destino_id' => $lugar(2)->id, 'sesion_origen_id' => $sesionOrigen['id'], 'sesion_destino_id' => $sesionDestino['id'],
            'version_origen_conocida' => Camara::findOrFail($origen->camara_id)->version_plano, 'version_destino_conocida' => $camara->refresh()->version_plano,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();

        $this->assertSame(EstadoTareaMovimiento::Cancelada, $retiros['RES-ORIGEN']->refresh()->estado);
        $this->assertSame('RES-ORIGEN ya está ubicado en Camara 09 · B01-P02-N1: el retiro de REPA se da por cumplido.', $retiros['RES-ORIGEN']->motivo_cancelacion);
        $this->assertSame('completado', PlanOperacional::query()->where('referencia_id', $repaConOrigen->id)->firstOrFail()->estado->value);
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $retiros['RES-ENREPA']->refresh()->estado);
    }

    /** @return array{User, string, ?Camara} */
    private function camarero(string $codigo, ?string $nombreCamara): array
    {
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TAB-'.Str::upper(Str::random(6)), 'nombre' => 'Tablet cámara', 'activo' => true]);
        $token = $camarero->crearTokenParaDispositivo($dispositivo, 'tablet-estiba')->plainTextToken;

        return [$camarero, $token, $nombreCamara ? Camara::create(['codigo' => $codigo, 'nombre' => $nombreCamara]) : null];
    }

    /** @return array{Temporada, User} */
    private function contexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-276',
            'nombre' => 'Temporada PR 276',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::Validador,
            'activo' => true,
        ]);

        return [$temporada, $usuario];
    }

    /**
     * @return array{Repaletizaje, array<string, mixed>}
     */
    private function registrar(
        Temporada $temporada,
        User $usuario,
        string $sufijo,
        CondicionTermicaFolio $condicion,
        bool $conUbicacion = false,
        TipoBulto $tipoResultado = TipoBulto::Pallet,
    ): array {
        $cantidadPrimera = 60;
        $cantidadSegunda = $tipoResultado === TipoBulto::Pallet ? 60 : 50;
        $primero = $this->folio($temporada, "SAL-{$sufijo}-1", $cantidadPrimera, $condicion);
        $segundo = $this->folio($temporada, "SAL-{$sufijo}-2", $cantidadSegunda, $condicion);

        if ($conUbicacion) {
            $camara = Camara::create([
                'codigo' => "CAM-{$sufijo}",
                'nombre' => "Cámara {$sufijo}",
            ]);
            $posicion = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => 1,
                'nivel' => 1,
                'etiqueta' => 'B01-P01-N1',
            ]);
            UbicacionActual::create([
                'folio_id' => $primero->id,
                'camara_id' => $camara->id,
                'posicion_id' => $posicion->id,
                'ubicado_at' => now(),
            ]);
        }

        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => $tipoResultado->value,
            'estrategia_folio' => 'nuevo',
            'numero_folio_resultante' => "RES-{$sufijo}",
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $primero->id, 'cantidad_aportada' => $cantidadPrimera],
                ['folio_id' => $segundo->id, 'cantidad_aportada' => $cantidadSegunda],
            ],
        ];

        return [
            app(ServicioRepaletizaje::class)->registrar($payload, $usuario),
            $payload,
        ];
    }

    private function folio(
        Temporada $temporada,
        string $numero,
        int $cantidad,
        CondicionTermicaFolio $condicion,
    ): Folio {
        return Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numero,
            'tipo_bulto' => TipoBulto::Saldo,
            'estado_operacional' => $condicion === CondicionTermicaFolio::PrefrioAprobado
                ? EstadoOperacionalFolio::PendienteUbicacion
                : EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => $condicion,
            'habilitacion_almacenamiento' => $condicion === CondicionTermicaFolio::PrefrioAprobado
                ? HabilitacionAlmacenamientoFolio::Habilitado
                : HabilitacionAlmacenamientoFolio::NoHabilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'variedad' => 'Santina',
            'calibre' => '2J',
            'marca' => 'Marca 276',
            'exportadora' => 'Exportadora 276',
            'origen_sistema' => 'validacion',
            'identificador_externo' => (string) Str::uuid(),
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
            'datos_externos' => [
                'especie' => 'Cereza',
                'categoria' => 'Exportación',
                'envase' => 'Caja 5 kg',
                'csg' => '111',
                'predio' => 'Predio 276',
                'cuartel' => 'Cuartel 276',
                'cantidad_cajas' => $cantidad,
            ],
        ]);
    }

    private function habilitarGeneracion(): void
    {
        config([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
        ]);
    }
}
