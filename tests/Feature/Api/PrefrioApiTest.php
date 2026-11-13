<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\EventoPrefrio;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\RegistroHabilitacionAlmacenamiento;
use App\Models\TareaMovimiento;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Folios\ServicioHabilitacionAlmacenamiento;
use App\Services\Prefrio\RegistroPrefrioR41;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrefrioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_crea_tunel_configurable_y_operador_no_administra(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        [, $tokenOperador] = $this->acceso(RolUsuario::OperadorPrefrio, 'PF-OP-CONF');

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/prefrio/tuneles/siguiente-codigo')
            ->assertOk()
            ->assertJsonPath('data.codigo', 'TUN-01');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $tunelId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/prefrio/tuneles', [
                'nombre' => 'Túnel California grande',
                'capacidad_posiciones' => 40,
                'setpoint_habitual' => -1.5,
                'estado_tecnico' => 'operativo',
            ])
            ->assertCreated()
            ->assertJsonPath('data.codigo', 'TUN-01')
            ->assertJsonPath('data.capacidad_posiciones', 40)
            ->assertJsonCount(40, 'data.posiciones')
            ->assertJsonPath('data.posiciones.39.etiqueta', 'TUN-01-P40')
            ->json('data.id');
        $consultas = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $consulta): string => strtolower($consulta));
        DB::disableQueryLog();
        DB::flushQueryLog();

        $this->assertDatabaseHas('tuneles_prefrio', [
            'id' => $tunelId,
            'capacidad_posiciones' => 40,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
        ]);
        $this->assertDatabaseHas('secuencias_documentos', [
            'clave' => 'tuneles_prefrio',
            'ultimo_numero' => 1,
        ]);
        $this->assertFalse(
            $consultas->contains(fn (string $consulta): bool => str_contains(
                $consulta,
                'tuneles_prefrio',
            ) && str_contains($consulta, 'order by')
                && str_contains($consulta, 'codigo')),
            'Crear un túnel no debe recorrer ni bloquear el catálogo de códigos.',
        );

        $this->conToken($tokenOperador)
            ->postJson('/api/administracion/prefrio/tuneles', [
                'nombre' => 'Túnel no autorizado',
                'capacidad_posiciones' => 22,
            ])
            ->assertForbidden();
    }

    public function test_tunel_admite_un_solo_proceso_activo_y_creacion_es_idempotente(): void
    {
        [$tunel, , $token] = $this->contexto();
        $operacionId = (string) Str::uuid();
        $payload = $this->payloadProceso($tunel->id, $operacionId);

        $procesoId = $this->conToken($token)
            ->postJson('/api/prefrio/procesos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.temporada.activa', true)
            ->assertJsonPath('data.version', 0)
            ->json('data.id');

        $this->conToken($token)
            ->postJson('/api/prefrio/procesos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $procesoId);

        $payloadConOtraHora = $payload;
        $payloadConOtraHora['ocurrido_at'] = now()->subMinute()->toAtomString();
        $this->conToken($token)
            ->postJson('/api/prefrio/procesos', $payloadConOtraHora)
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $payloadDistinto = $payload;
        $payloadDistinto['setpoint'] = -2;

        $this->conToken($token)
            ->postJson('/api/prefrio/procesos', $payloadDistinto)
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->conToken($token)
            ->postJson('/api/prefrio/procesos', $this->payloadProceso(
                $tunel->id,
                (string) Str::uuid(),
            ))
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertSame(1, ProcesoPrefrio::query()->count());
    }

    public function test_bandejas_operacionales_usan_etag_y_evitan_relaciones_pesadas_sin_cambios(): void
    {
        [$tunel, $posicion, $token] = $this->contexto();
        $proceso = $this->crearProceso($token, $tunel);

        $tunelesIniciales = $this->conToken($token)
            ->getJson('/api/prefrio/tuneles')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonPath('data.0.proceso_activo.id', $proceso['id']);
        $procesosIniciales = $this->conToken($token)
            ->getJson('/api/prefrio/procesos?per_page=50&solo_activos=1')
            ->assertOk()
            ->assertHeader('Access-Control-Expose-Headers', 'ETag')
            ->assertJsonPath('data.0.id', $proceso['id']);
        $etagTuneles = $tunelesIniciales->headers->get('ETag');
        $etagProcesos = $procesosIniciales->headers->get('ETag');

        $this->assertNotNull($etagTuneles);
        $this->assertNotNull($etagProcesos);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->conToken($token)
            ->withHeader('If-None-Match', $etagTuneles)
            ->get('/api/prefrio/tuneles')
            ->assertStatus(304)
            ->assertHeader('ETag', $etagTuneles);
        $consultasTuneles = collect(DB::getQueryLog());

        DB::flushQueryLog();
        $this->conToken($token)
            ->withHeader('If-None-Match', $etagProcesos)
            ->get('/api/prefrio/procesos?per_page=50&solo_activos=1')
            ->assertStatus(304)
            ->assertHeader('ETag', $etagProcesos);
        $consultasProcesos = collect(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        $this->assertFalse($consultasTuneles->contains(function (array $consulta): bool {
            $sql = Str::lower(Str::squish(str_replace(['`', '"'], '', $consulta['query'])));

            return str_contains($sql, 'from tuneles_prefrio')
                || str_contains($sql, 'from posiciones_tunel_prefrio')
                || str_contains($sql, 'from procesos_prefrio')
                || str_contains($sql, 'from folios');
        }), 'Un 304 de túneles no debe consultar la bandeja operacional.');
        $this->assertFalse($consultasProcesos->contains(function (array $consulta): bool {
            $sql = Str::lower(Str::squish(str_replace(['`', '"'], '', $consulta['query'])));

            return str_contains($sql, 'from procesos_prefrio')
                || str_contains($sql, 'from procesos_prefrio_folios')
                || str_contains($sql, 'from tuneles_prefrio')
                || str_contains($sql, 'from folios')
                || str_contains($sql, 'from eventos_prefrio');
        }), 'Un 304 de procesos no debe consultar ni paginar la bandeja operacional.');

        $otroTamano = $this->conToken($token)
            ->withHeader('If-None-Match', $etagProcesos)
            ->getJson('/api/prefrio/procesos?per_page=10&solo_activos=1')
            ->assertOk();

        $this->assertNotSame($etagProcesos, $otroTamano->headers->get('ETag'));

        $folio = $this->folioPendiente('PAL-PF-ETAG-001');
        $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'ocurrido_at' => now()->toAtomString(),
        ]);

        $tunelesActualizados = $this->conToken($token)
            ->withHeader('If-None-Match', $etagTuneles)
            ->getJson('/api/prefrio/tuneles')
            ->assertOk()
            ->assertJsonPath('data.0.proceso_activo.folios_cargados', 1);
        $procesosActualizados = $this->conToken($token)
            ->withHeader('If-None-Match', $etagProcesos)
            ->getJson('/api/prefrio/procesos?per_page=50&solo_activos=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.folios');

        $this->assertNotSame($etagTuneles, $tunelesActualizados->headers->get('ETag'));
        $this->assertNotSame($etagProcesos, $procesosActualizados->headers->get('ETag'));
    }

    public function test_posicion_admite_varios_saldos_y_mantiene_pallet_completo_exclusivo(): void
    {
        [$tunel, $primeraPosicion, $token] = $this->contexto();
        $segundaPosicion = $tunel->posiciones()->orderBy('numero')->skip(1)->firstOrFail();
        $primerSaldo = $this->folioPendiente('SALDO-PF-001', TipoBulto::Saldo);
        $segundoSaldo = $this->folioPendiente('SALDO-PF-002', TipoBulto::Saldo);
        $tercerSaldo = $this->folioPendiente('SALDO-PF-003', TipoBulto::Saldo);
        $cuartoSaldo = $this->folioPendiente('SALDO-PF-004', TipoBulto::Saldo);
        $pallet = $this->folioPendiente('PAL-PF-EXCLUSIVO');
        $primerSaldo->update(['exportadora' => 'Cliente A', 'variedad' => 'Hayward']);
        $segundoSaldo->update(['exportadora' => 'Cliente B', 'variedad' => 'Duke']);
        $tercerSaldo->update(['exportadora' => 'Cliente C', 'variedad' => 'Santina']);

        $proceso = $this->crearProceso($token, $tunel);
        foreach ([$primerSaldo, $segundoSaldo, $tercerSaldo] as $version => $saldo) {
            $proceso = $this->accion(
                $token,
                "/api/prefrio/procesos/{$proceso['id']}/folios",
                [
                    'operacion_id' => (string) Str::uuid(),
                    'version_conocida' => $version,
                    'folio_id' => $saldo->id,
                    'posicion_tunel_prefrio_id' => $primeraPosicion->id,
                    'ocurrido_at' => now()->toAtomString(),
                ],
            );
        }

        $this->assertCount(3, $proceso['folios']);
        $this->assertSame(
            [$primerSaldo->id, $segundoSaldo->id, $tercerSaldo->id],
            collect($proceso['folios'])->pluck('folio.id')->all(),
        );
        $this->assertDatabaseCount('procesos_prefrio_folios', 3);

        $this->conToken($token)
            ->postJson("/api/prefrio/procesos/{$proceso['id']}/folios", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'folio_id' => $pallet->id,
                'posicion_tunel_prefrio_id' => $primeraPosicion->id,
                'ocurrido_at' => now()->toAtomString(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/folios",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'folio_id' => $pallet->id,
                'posicion_tunel_prefrio_id' => $segundaPosicion->id,
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $this->conToken($token)
            ->postJson("/api/prefrio/procesos/{$proceso['id']}/folios", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'folio_id' => $cuartoSaldo->id,
                'posicion_tunel_prefrio_id' => $segundaPosicion->id,
                'ocurrido_at' => now()->toAtomString(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $asignacionRetirada = collect($proceso['folios'])
            ->firstWhere('folio.id', $segundoSaldo->id);
        $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/folios/{$asignacionRetirada['id']}/retirar",
            $this->payloadAccion(4),
        );

        $this->assertSame(2, DB::table('procesos_prefrio_folios')
            ->where('proceso_prefrio_id', $proceso['id'])
            ->where('posicion_tunel_prefrio_id', $primeraPosicion->id)
            ->where('estado', 'cargado')
            ->count());
        $this->assertDatabaseHas('procesos_prefrio_folios', [
            'proceso_prefrio_id' => $proceso['id'],
            'folio_id' => $segundoSaldo->id,
            'estado' => 'retirado',
        ]);
    }

    public function test_cancelacion_de_proceso_vacio_libera_tunel_y_excluye_historial_operacional(): void
    {
        [$tunel, , $tokenOperador] = $this->contexto();
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-SUP-CANCEL-EMPTY');
        $proceso = $this->crearProceso($tokenOperador, $tunel);

        $cancelado = $this->accion(
            $tokenSupervisor,
            "/api/prefrio/procesos/{$proceso['id']}/cancelar",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 0,
                'motivo' => 'Prueba de cancelación sin folios.',
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $this->assertSame('cancelado', $cancelado['estado']);
        $this->conToken($tokenOperador)
            ->getJson('/api/prefrio/procesos?solo_activos=1&per_page=50')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $nuevo = $this->crearProceso($tokenOperador, $tunel);

        $this->assertNotSame($proceso['id'], $nuevo['id']);
        $this->assertSame('borrador', $nuevo['estado']);
        $this->assertSame($tunel->id, $nuevo['tunel']['id']);
        $this->conToken($tokenOperador)
            ->getJson('/api/prefrio/procesos?solo_activos=1&per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nuevo['id']);
    }

    public function test_bandeja_y_resumen_prefrio_no_mezclan_temporadas_anteriores(): void
    {
        [$tunel, , $token] = $this->contexto();
        $proceso = $this->crearProceso($token, $tunel);
        ProcesoPrefrio::query()->findOrFail($proceso['id'])->update([
            'estado' => 'en_proceso',
        ]);

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'PF-NUEVA',
            'nombre' => 'Temporada nueva de prefrío',
            'activa' => true,
        ]);

        $this->conToken($token)
            ->getJson('/api/prefrio/procesos?per_page=25')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->conToken($token)
            ->getJson('/api/prefrio/resumen')
            ->assertOk()
            ->assertJsonPath('en_proceso', 0)
            ->assertJsonPath('folios_activos', 0);
    }

    public function test_cancelacion_antes_de_iniciar_libera_tunel_y_folio_cargado(): void
    {
        [$tunel, $posicion, $tokenOperador] = $this->contexto();
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-SUP-CANCEL-LOAD');
        $folio = $this->folioPendiente('PAL-PF-CANCEL-001');
        $proceso = $this->crearProceso($tokenOperador, $tunel);

        $proceso = $this->accion(
            $tokenOperador,
            "/api/prefrio/procesos/{$proceso['id']}/folios",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 0,
                'folio_id' => $folio->id,
                'posicion_tunel_prefrio_id' => $posicion->id,
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $cancelado = $this->accion(
            $tokenSupervisor,
            "/api/prefrio/procesos/{$proceso['id']}/cancelar",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
                'motivo' => 'Prueba de cancelación durante carga.',
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $this->assertSame('cancelado', $cancelado['estado']);
        $this->assertDatabaseHas('procesos_prefrio_folios', [
            'proceso_prefrio_id' => $proceso['id'],
            'folio_id' => $folio->id,
            'estado' => 'cancelado',
        ]);

        $nuevo = $this->crearProceso($tokenOperador, $tunel);
        $recargado = $this->accion(
            $tokenOperador,
            "/api/prefrio/procesos/{$nuevo['id']}/folios",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 0,
                'folio_id' => $folio->id,
                'posicion_tunel_prefrio_id' => $posicion->id,
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $this->assertSame('cargando', $recargado['estado']);
        $this->assertSame($folio->id, $recargado['folios'][0]['folio']['id']);
    }

    public function test_aprobacion_genera_un_solo_objetivo_rolling_reintentable(): void
    {
        config([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
        ]);
        [$tunel, $posicion, $tokenOperador] = $this->contexto();
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-SUP-ROLL');
        $folio = $this->folioPendiente('PAL-PF-ROLL');
        $folio->update(['exportadora' => 'Exportadora rolling']);
        $proceso = $this->llevarAVerificacion(
            $tokenOperador,
            $tunel,
            $posicion,
            $folio,
        );
        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 4,
            'resultados' => [[
                'folio_id' => $folio->id,
                'temperatura_final' => -0.5,
                'observacion' => 'Pulpa conforme.',
            ]],
            'ocurrido_at' => now()->toAtomString(),
        ];

        $primera = $this->accion(
            $tokenSupervisor,
            "/api/prefrio/procesos/{$proceso['id']}/aprobar",
            $payload,
        );
        $segunda = $this->accion(
            $tokenSupervisor,
            "/api/prefrio/procesos/{$proceso['id']}/aprobar",
            $payload,
        );

        $this->assertSame('aprobado', $primera['estado']);
        $this->assertSame($primera['id'], $segunda['id']);
        $plan = PlanOperacional::query()
            ->where('referencia_tipo', 'proceso_prefrio')
            ->where('referencia_id', $proceso['id'])
            ->firstOrFail();
        $this->assertSame('rolling', $plan->contexto['planner_horizon']);
        $this->assertSame(1, $plan->tareas()->count());
        $this->assertSame(
            'Exportadora rolling',
            $plan->tareas()->firstOrFail()->contexto['cliente'] ?? null,
        );
    }

    public function test_finaliza_folios_por_separado_y_conserva_el_ciclo_y_la_ocupacion(): void
    {
        config(['planificador.generacion_automatica' => true, 'planificador.mode' => 'guided', 'planificador.compute' => 'tablet']);
        [$tunel, $posicion, $operador] = $this->contexto();
        [, $supervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-PARCIAL-SUP');
        $posiciones = $tunel->posiciones()->orderBy('numero')->get();
        $pallet = $this->folioPendiente('PF-PARCIAL-PAL');
        $saldo = $this->folioPendiente('PF-PARCIAL-SALDO', TipoBulto::Saldo);
        $restante = $this->folioPendiente('PF-PARCIAL-RESTANTE', TipoBulto::Saldo);
        $retenido = $this->folioPendiente('PF-PARCIAL-RETENIDO');
        $proceso = $this->iniciarConFolios($operador, $tunel, [
            [$saldo, $posicion], [$restante, $posicion], [$pallet, $posiciones[1]], [$retenido, $posiciones[2]],
        ]);
        $ruta = "/api/prefrio/procesos/{$proceso['id']}";
        $resultados = [
            ['folio_id' => $pallet->id, 'resultado' => 'prefrio_aprobado', 'temperatura_final' => -0.5],
            ['folio_id' => $saldo->id, 'resultado' => 'pendiente_prefrio', 'motivo' => 'Separación por formato'],
            ['folio_id' => $retenido->id, 'resultado' => 'retenido'],
        ];
        $decision = [...$this->payloadAccion($proceso['version']), 'motivo' => 'Retiro programado', 'resultados' => $resultados];
        $this->conToken($operador)->postJson("{$ruta}/finalizar-parcial", $decision)->assertForbidden();
        $this->conToken($supervisor)->postJson("{$ruta}/finalizar-parcial", [
            ...$decision, 'resultados' => [$resultados[0], $resultados[0]],
        ])->assertUnprocessable();
        $this->conToken($supervisor)->postJson("{$ruta}/finalizar-parcial", [
            ...$decision, 'resultados' => [...$resultados, ['folio_id' => $restante->id, 'resultado' => 'prefrio_aprobado']],
        ])->assertUnprocessable();
        $this->conToken($supervisor)->postJson("{$ruta}/finalizar-parcial", [
            ...$decision, 'resultados' => [...$resultados, ['folio_id' => (string) Str::uuid(), 'resultado' => 'prefrio_aprobado']],
        ])->assertConflict();

        $parcial = $this->accion($supervisor, "{$ruta}/finalizar-parcial", $decision);
        $this->assertSame($parcial['version'], $this->accion($supervisor, "{$ruta}/finalizar-parcial", $decision)['version']);
        $this->assertSame('en_proceso', $parcial['estado']);
        $this->assertSame($proceso['iniciado_at'], $parcial['iniciado_at']);
        $this->assertNull($parcial['finalizado_at']);
        $asignaciones = collect($parcial['folios'])->keyBy('folio.id');
        $this->assertSame('aprobado', $asignaciones[$pallet->id]['estado']);
        $this->assertSame(-0.5, $asignaciones[$pallet->id]['temperatura_final']);
        $this->assertSame('Separación por formato', $asignaciones[$saldo->id]['motivo_resultado']);
        $this->assertTrue($asignaciones[$saldo->id]['en_tunel']);
        $this->assertSame('en_proceso', $asignaciones[$restante->id]['estado']);
        $this->assertSame(CondicionTermicaFolio::EnProceso, $restante->refresh()->condicion_termica);
        $this->assertSame(HabilitacionAlmacenamientoFolio::Habilitado, $pallet->refresh()->habilitacion_almacenamiento);
        $this->assertSame(HabilitacionAlmacenamientoFolio::Retenido, $retenido->refresh()->habilitacion_almacenamiento);
        $this->conToken($operador)->getJson('/api/prefrio/folios-disponibles?folio=PF-PARCIAL-SALDO')->assertJsonCount(0, 'data');
        $this->assertSame(1, EventoPrefrio::query()->where('operacion_id', $decision['operacion_id'])->count());
        $tarea = TareaMovimiento::query()->where('folio_id', $pallet->id)->sole();
        $this->assertSame($posiciones[1]->etiqueta, $tarea->contexto['posicion_tunel']);

        $retiro = [...$this->payloadAccion($parcial['version']), 'folios' => [$saldo->id, $pallet->id]];
        $retirado = $this->accion($operador, "{$ruta}/confirmar-retiro-parcial", $retiro);
        $this->assertSame($retirado['version'], $this->accion($operador, "{$ruta}/confirmar-retiro-parcial", $retiro)['version']);
        $asignaciones = collect($retirado['folios'])->keyBy('folio.id');
        $this->assertFalse($asignaciones[$saldo->id]['en_tunel']);
        $this->assertTrue($asignaciones[$restante->id]['en_tunel']);
        $this->assertSame($posicion->id, $asignaciones[$restante->id]['posicion']['id']);
        $this->assertNotNull($asignaciones[$saldo->id]['retirado_por']);
        $this->assertSame('Punto de entrega de Prefrío', $tarea->refresh()->contexto['tunel_nombre']);
        $this->assertArrayNotHasKey('posicion_tunel', $tarea->contexto);
        $this->conToken($operador)->getJson('/api/prefrio/folios-disponibles?folio=PF-PARCIAL-SALDO')->assertJsonPath('data.0.id', $saldo->id);

        $lectura = $this->accion($operador, "{$ruta}/eventos/lectura", [
            ...$this->payloadAccion($retirado['version']), 'datos' => ['sectores' => self::lecturaCompleta()],
        ]);
        $verificacion = $this->accion($operador, "{$ruta}/verificar", $this->payloadAccion($lectura['version']));
        $cierre = $this->accion($supervisor, "{$ruta}/aprobar", $this->payloadAccion($verificacion['version']));
        $this->assertSame('aprobado', $cierre['estado']);
        $asignaciones = collect($cierre['folios'])->keyBy('folio.id');
        $this->assertSame('aprobado', $asignaciones[$restante->id]['estado']);
        $this->assertSame('retenido', $asignaciones[$retenido->id]['estado']);
        $this->assertSame('pendiente_prefrio', $asignaciones[$saldo->id]['estado']);
        $this->assertSame('Retiro programado', $asignaciones[$pallet->id]['motivo_resultado']);
        $this->assertSame(1, TareaMovimiento::query()->where('folio_id', $pallet->id)->count());
        $detalle = RegistroPrefrioR41::detalle(ProcesoPrefrio::findOrFail($proceso['id']));
        $this->assertStringContainsString('finalización parcial de 3 folios', implode(' ', $detalle['observaciones']));
        $this->conToken($operador)->getJson('/api/prefrio/procesos?solo_activos=1')
            ->assertJsonPath('data.0.id', $proceso['id']);
        $this->conToken($operador)->postJson('/api/prefrio/procesos', $this->payloadProceso($tunel->id, (string) Str::uuid()))
            ->assertConflict();
        $this->accion($operador, "{$ruta}/confirmar-retiro-parcial", [
            ...$this->payloadAccion($cierre['version']), 'folios' => [$retenido->id],
        ]);
        $this->conToken($operador)->getJson('/api/prefrio/procesos?solo_activos=1')->assertJsonCount(0, 'data');
        $this->crearProceso($operador, $tunel);
    }

    public function test_ubicar_un_pallet_aprobado_parcialmente_confirma_su_retiro(): void
    {
        config(['planificador.generacion_automatica' => false]);
        [$tunel, $posicion, $operador] = $this->contexto();
        [, $supervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-PARCIAL-CAM');
        $pallet = $this->folioPendiente('PF-PARCIAL-A-CAMARA');
        $saldo = $this->folioPendiente('PF-PARCIAL-EN-TUNEL', TipoBulto::Saldo);
        $proceso = $this->iniciarConFolios($operador, $tunel, [
            [$pallet, $posicion], [$saldo, $tunel->posiciones()->where('numero', 2)->sole()],
        ]);
        $ruta = "/api/prefrio/procesos/{$proceso['id']}";
        $parcial = $this->accion($supervisor, "{$ruta}/finalizar-parcial", [
            ...$this->payloadAccion($proceso['version']), 'motivo' => 'Ingreso a cámara',
            'resultados' => [['folio_id' => $pallet->id, 'resultado' => 'prefrio_aprobado']],
        ]);
        $camara = Camara::create(['codigo' => 'CAM-PF-PARCIAL', 'nombre' => 'Cámara posterior a prefrío']);
        $destino = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $sesion = $this->conToken($supervisor)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data.id');
        $this->conToken($supervisor)->postJson('/api/movimientos/ubicar', [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $pallet->numero_folio,
            'tipo_bulto' => 'pallet',
            'posicion_destino_id' => $destino->id,
            'sesion_destino_id' => $sesion,
            'version_destino_conocida' => 0,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();
        $detalle = $this->conToken($operador)->getJson($ruta)->assertOk()->assertJsonPath('data.estado', 'en_proceso')->json('data');
        $asignaciones = collect($detalle['folios'])->keyBy('folio.id');
        $this->assertFalse($asignaciones[$pallet->id]['en_tunel']);
        $this->assertNotNull($asignaciones[$pallet->id]['retirado_at']);
        $this->assertTrue($asignaciones[$saldo->id]['en_tunel']);
        $this->conToken($operador)->postJson("{$ruta}/confirmar-retiro-parcial", [
            ...$this->payloadAccion($parcial['version']), 'folios' => [$pallet->id],
        ])->assertConflict();
    }

    public function test_inicio_real_anterior_al_armado_y_avisos_en_hora_de_planta(): void
    {
        config(['app.operational_timezone' => 'America/Santiago']);
        [$tunel, $posicion, $operador] = $this->contexto();
        $folio = $this->folioPendiente('PF-INICIO-RETRO');
        $captura = now()->toImmutable()->subHours(3)->startOfMinute()->addSeconds(25);
        $apertura = $captura->setTimezone('America/Santiago');
        $proceso = $this->conToken($operador)->postJson('/api/prefrio/procesos', [
            ...$this->payloadProceso($tunel->id, (string) Str::uuid()), 'ocurrido_at' => $apertura->toAtomString(),
        ])->assertCreated()->assertJsonPath('data.eventos.0.ocurrido_at', $captura->toAtomString())->json('data');
        $ruta = "/api/prefrio/procesos/{$proceso['id']}";
        $carga = [
            ...$this->payloadAccion(0), 'folio_id' => $folio->id, 'posicion_tunel_prefrio_id' => $posicion->id,
        ];
        $this->conToken($operador)->postJson("{$ruta}/folios", [
            ...$carga, 'ocurrido_at' => $captura->subSecond()->toAtomString(),
        ])->assertConflict()->assertJsonPath('message', sprintf(
            'La fecha y hora debe ser igual o posterior al último evento (%s, hora de planta).', $apertura->format('d-m-Y H:i:s'),
        ));
        $this->accion($operador, "{$ruta}/folios", [
            ...$carga, 'operacion_id' => (string) Str::uuid(), 'ocurrido_at' => $captura->addMinute()->toAtomString(),
        ]);
        $this->accion($operador, "{$ruta}/confirmar-armado", [
            ...$this->payloadAccion(1), 'ocurrido_at' => $captura->addMinutes(2)->toAtomString(),
        ]);

        $inicio = $captura->subHour();
        $iniciado = $this->accion($operador, "{$ruta}/iniciar", [
            ...$this->payloadAccion(2), 'ocurrido_at' => $inicio->setTimezone('America/Santiago')->format('Y-m-d\TH:i:s'),
        ]);
        $this->assertSame($inicio->toAtomString(), $iniciado['iniciado_at']);
        $this->assertSame('en_proceso', $iniciado['estado']);
        $this->accion($operador, "{$ruta}/eventos/lectura", [
            ...$this->payloadAccion(3), 'ocurrido_at' => $inicio->addMinutes(30)->toAtomString(),
            'datos' => ['sectores' => self::lecturaCompleta()],
        ]);
        $this->conToken($operador)->postJson("{$ruta}/eventos/pausa", [
            ...$this->payloadAccion(4), 'ocurrido_at' => $inicio->subSecond()->toAtomString(),
        ])->assertConflict();
    }

    public function test_aprobacion_habilita_almacenamiento_y_permite_ingreso_inicial_a_camara(): void
    {
        [$tunel, $posicion, $tokenOperador] = $this->contexto();
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-SUP-01');
        $folio = $this->folioPendiente('PAL-PF-001');
        $proceso = $this->llevarAVerificacion(
            $tokenOperador,
            $tunel,
            $posicion,
            $folio,
        );

        $resultado = $this->accion(
            $tokenSupervisor,
            "/api/prefrio/procesos/{$proceso['id']}/aprobar",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'resultados' => [[
                    'folio_id' => $folio->id,
                    'temperatura_final' => -0.5,
                    'observacion' => 'Pulpa conforme.',
                ]],
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $this->assertSame('aprobado', $resultado['estado']);
        $folio->refresh();
        $this->assertSame(CondicionTermicaFolio::PrefrioAprobado, $folio->condicion_termica);
        $this->assertSame(
            HabilitacionAlmacenamientoFolio::Habilitado,
            $folio->habilitacion_almacenamiento,
        );
        $this->assertSame(EstadoOperacionalFolio::PendienteUbicacion, $folio->estado_operacional);

        $registro = RegistroHabilitacionAlmacenamiento::query()
            ->where('folio_id', $folio->id)
            ->where('estado_resultante', HabilitacionAlmacenamientoFolio::Habilitado)
            ->firstOrFail();
        $this->assertSame(HabilitacionAlmacenamientoFolio::Habilitado, $registro->estado_resultante);
        $this->assertSame(FuenteHabilitacionAlmacenamiento::PrefrioAprobado, $registro->fuente);
        $this->assertSame('prefrio', $registro->proceso_origen);
        $this->assertSame($proceso['id'], $registro->referencia_origen);
        $this->assertNotNull($registro->user_id);
        $this->assertNotNull($registro->dispositivo_id);

        app(ServicioHabilitacionAlmacenamiento::class)->validarIngresoCamara($folio);

        $camara = Camara::create([
            'codigo' => 'CAM-PF-01',
            'nombre' => 'Cámara posterior a prefrío',
        ]);
        $posicionCamara = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $sesionId = $this->conToken($tokenSupervisor)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        $this->conToken($tokenSupervisor)
            ->postJson('/api/movimientos/ubicar', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => $folio->numero_folio,
                'tipo_bulto' => 'pallet',
                'posicion_destino_id' => $posicionCamara->id,
                'sesion_destino_id' => $sesionId,
                'version_destino_conocida' => 0,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.folio.id', $folio->id);

        $this->assertSame(
            EstadoOperacionalFolio::Disponible,
            $folio->refresh()->estado_operacional,
        );
        $this->assertDatabaseHas('ubicaciones_actuales', [
            'folio_id' => $folio->id,
            'posicion_id' => $posicionCamara->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('historial de habilitaciones es inmutable');
        $registro->update(['motivo' => 'Intento de sobrescritura']);
    }

    public function test_reproceso_retiene_folio_y_conserva_historial_para_segundo_proceso(): void
    {
        [$tunel, $posicion, $tokenOperador] = $this->contexto();
        [, $tokenSupervisor] = $this->acceso(RolUsuario::SupervisorFrio, 'PF-SUP-02');
        $folio = $this->folioPendiente('PAL-PF-002');
        $proceso = $this->llevarAVerificacion(
            $tokenOperador,
            $tunel,
            $posicion,
            $folio,
        );

        $resultado = $this->accion(
            $tokenSupervisor,
            "/api/prefrio/procesos/{$proceso['id']}/reprocesar",
            [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'motivo' => 'temperatura_fuera_rango',
                'resultados' => [[
                    'folio_id' => $folio->id,
                    'temperatura_final' => 1.4,
                ]],
                'ocurrido_at' => now()->toAtomString(),
            ],
        );

        $this->assertSame('requiere_reproceso', $resultado['estado']);
        $folio->refresh();
        $this->assertSame(CondicionTermicaFolio::RequiereReproceso, $folio->condicion_termica);
        $this->assertSame(HabilitacionAlmacenamientoFolio::Retenido, $folio->habilitacion_almacenamiento);
        $this->assertSame(EstadoOperacionalFolio::Bloqueado, $folio->estado_operacional);
        $this->assertDatabaseHas('historial_habilitaciones_almacenamiento', [
            'folio_id' => $folio->id,
            'estado_resultante' => HabilitacionAlmacenamientoFolio::Retenido->value,
            'condicion_termica' => CondicionTermicaFolio::RequiereReproceso->value,
            'proceso_origen' => 'prefrio',
            'referencia_origen' => $proceso['id'],
            'motivo' => 'temperatura_fuera_rango',
        ]);

        $nuevo = $this->crearProceso($tokenOperador, $tunel);
        $this->accion($tokenOperador, "/api/prefrio/procesos/{$nuevo['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'ocurrido_at' => now()->toAtomString(),
        ]);

        $this->assertSame(2, $folio->procesosPrefrio()->count());
    }

    public function test_camara_usa_habilitacion_generica_y_no_exige_prefrio_para_saldos(): void
    {
        $servicio = app(ServicioHabilitacionAlmacenamiento::class);
        $pendiente = $this->folioPendiente('PAL-PF-003');

        try {
            $servicio->validarIngresoCamara($pendiente);
            $this->fail('El pallet pendiente de prefrío no debe ingresar a cámara.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('habilitado', $exception->getMessage());
        }

        $saldo = Folio::create([
            'numero_folio' => 'SALDO-REP-001',
            'tipo_bulto' => TipoBulto::Saldo,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now(),
            'activo' => true,
            'origen_sistema' => 'repaletizaje',
        ]);

        $servicio->prepararFolioManual($saldo);
        $saldo->refresh();
        $servicio->validarIngresoCamara($saldo);

        $this->assertSame(CondicionTermicaFolio::CondicionHeredada, $saldo->condicion_termica);
        $this->assertSame(
            HabilitacionAlmacenamientoFolio::Habilitado,
            $saldo->habilitacion_almacenamiento,
        );
        $this->assertSame(
            FuenteHabilitacionAlmacenamiento::CondicionHeredadaRepaletizaje,
            $saldo->fuente_habilitacion_almacenamiento,
        );
        $this->assertDatabaseHas('historial_habilitaciones_almacenamiento', [
            'folio_id' => $saldo->id,
            'fuente' => FuenteHabilitacionAlmacenamiento::CondicionHeredadaRepaletizaje->value,
            'proceso_origen' => 'repaletizaje',
        ]);
    }

    public function test_folio_terminal_no_puede_reingresar_a_camara_aunque_figure_habilitado(): void
    {
        $folio = Folio::create([
            'numero_folio' => 'PAL-PF-TERMINAL',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Despachado,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estado operacional');

        app(ServicioHabilitacionAlmacenamiento::class)->validarIngresoCamara($folio);
    }

    public function test_tunel_puede_reducirse_y_ampliarse_sin_duplicar_posiciones_inactivas(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $tunelId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/prefrio/tuneles', [
                'nombre' => 'Túnel redimensionable',
                'capacidad_posiciones' => 22,
                'estado_tecnico' => 'operativo',
            ])
            ->assertCreated()
            ->json('data.id');

        $payload = [
            'nombre' => 'Túnel redimensionable',
            'capacidad_posiciones' => 20,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
        ];

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/prefrio/tuneles/{$tunelId}", $payload)
            ->assertOk()
            ->assertJsonPath('data.capacidad_posiciones', 20);

        $payload['capacidad_posiciones'] = 22;
        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/prefrio/tuneles/{$tunelId}", $payload)
            ->assertOk()
            ->assertJsonPath('data.capacidad_posiciones', 22)
            ->assertJsonCount(22, 'data.posiciones');

        $this->assertSame(22, PosicionTunelPrefrio::query()
            ->where('tunel_prefrio_id', $tunelId)
            ->count());
        $this->assertSame(22, PosicionTunelPrefrio::query()
            ->where('tunel_prefrio_id', $tunelId)
            ->where('activa', true)
            ->count());
    }

    public function test_tunel_rechaza_capacidad_impar_para_conservar_dos_lados_por_profundidad(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/prefrio/tuneles', [
                'nombre' => 'Túnel ambiguo',
                'capacidad_posiciones' => 21,
                'estado_tecnico' => 'operativo',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacidad_posiciones');
    }

    public function test_agregar_folio_a_proceso_listo_reabre_armado_y_no_permite_cargar_durante_el_ciclo(): void
    {
        [$tunel, $primeraPosicion, $token] = $this->contexto();
        $segundaPosicion = $tunel->posiciones()->orderBy('numero')->skip(1)->firstOrFail();
        $terceraPosicion = $tunel->posiciones()->orderBy('numero')->skip(2)->firstOrFail();
        $primerFolio = $this->folioPendiente('PAL-PF-REABRIR-001');
        $segundoFolio = $this->folioPendiente('PAL-PF-REABRIR-002');
        $tercerFolio = $this->folioPendiente('PAL-PF-REABRIR-003');
        $proceso = $this->crearProceso($token, $tunel);

        $proceso = $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $primerFolio->id,
            'posicion_tunel_prefrio_id' => $primeraPosicion->id,
            'ocurrido_at' => now()->toAtomString(),
        ]);
        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
            $this->payloadAccion(1),
        );

        $this->assertSame('listo_para_iniciar', $proceso['estado']);
        $this->assertSame(2, $proceso['version']);

        $proceso = $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 2,
            'folio_id' => $segundoFolio->id,
            'posicion_tunel_prefrio_id' => $segundaPosicion->id,
            'ocurrido_at' => now()->toAtomString(),
        ]);

        $this->assertSame('cargando', $proceso['estado']);
        $this->assertSame(3, $proceso['version']);
        $this->assertCount(2, $proceso['folios']);
        $this->assertDatabaseHas('procesos_prefrio_folios', [
            'proceso_prefrio_id' => $proceso['id'],
            'folio_id' => $segundoFolio->id,
            'posicion_tunel_prefrio_id' => $segundaPosicion->id,
            'estado' => 'cargado',
        ]);

        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
            $this->payloadAccion(3),
        );
        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/iniciar",
            $this->payloadAccion(4),
        );

        $this->assertSame('en_proceso', $proceso['estado']);

        $this->conToken($token)
            ->postJson("/api/prefrio/procesos/{$proceso['id']}/folios", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $proceso['version'],
                'folio_id' => $tercerFolio->id,
                'posicion_tunel_prefrio_id' => $terceraPosicion->id,
                'ocurrido_at' => now()->toAtomString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseMissing('procesos_prefrio_folios', [
            'proceso_prefrio_id' => $proceso['id'],
            'folio_id' => $tercerFolio->id,
        ]);
    }

    public function test_eventos_son_idempotentes_y_operador_prefrio_permanece_aislado(): void
    {
        [$tunel, $posicion, $token] = $this->contexto();
        $folio = $this->folioPendiente('PAL-PF-004');
        $proceso = $this->crearProceso($token, $tunel);
        $proceso = $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'ocurrido_at' => now()->toAtomString(),
        ]);
        $operacionId = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionId,
            'version_conocida' => 1,
            'observacion' => 'Armado verificado.',
            'ocurrido_at' => now()->toAtomString(),
        ];

        $primera = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
            $payload,
        );
        $segunda = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
            $payload,
        );

        $this->assertSame($primera['version'], $segunda['version']);

        $payload['observacion'] = 'Payload diferente.';
        $this->conToken($token)
            ->postJson(
                "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
                $payload,
            )
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->conToken($token)
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->conToken($token)->getJson('/api/cargas')->assertForbidden();
        $this->conToken($token)->getJson('/api/materiales/inventario')->assertForbidden();
        $this->conToken($token)->getJson('/api/validacion/pallets')->assertForbidden();
    }

    public function test_permite_hora_operacional_manual_y_rechaza_cronologia_invalida(): void
    {
        [$tunel, $posicion, $token] = $this->contexto();
        $folio = $this->folioPendiente('PAL-PF-HORA-MANUAL');
        $apertura = now()->toImmutable()->subHours(5)->startOfMinute();
        $carga = $apertura->addMinutes(15);
        $armado = $apertura->addMinutes(30);
        $inicio = $apertura->addHour();
        $termino = $apertura->addHours(4);

        $proceso = $this->conToken($token)
            ->postJson('/api/prefrio/procesos', [
                ...$this->payloadProceso($tunel->id, (string) Str::uuid()),
                'ocurrido_at' => $apertura->toAtomString(),
            ])
            ->assertCreated()
            ->json('data');
        $proceso = $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'ocurrido_at' => $carga->toAtomString(),
        ]);
        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
            [
                ...$this->payloadAccion(1),
                'ocurrido_at' => $armado->toAtomString(),
            ],
        );
        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/iniciar",
            [
                ...$this->payloadAccion(2),
                'ocurrido_at' => $inicio->toAtomString(),
            ],
        );

        $this->assertSame($inicio->toAtomString(), $proceso['iniciado_at']);
        $this->conToken($token)
            ->postJson("/api/prefrio/procesos/{$proceso['id']}/eventos/pausa", [
                ...$this->payloadAccion(3),
                'ocurrido_at' => $armado->subMinute()->toAtomString(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/verificar",
            [
                ...$this->payloadAccion(3),
                'ocurrido_at' => $termino->toAtomString(),
                'datos' => ['lectura_final' => self::lecturaCompleta()],
            ],
        );

        $this->assertSame('pendiente_verificacion', $proceso['estado']);
        $this->assertSame($termino->toAtomString(), $proceso['pendiente_verificacion_at']);
        $this->assertDatabaseHas('eventos_prefrio', [
            'proceso_prefrio_id' => $proceso['id'],
            'tipo' => 'proceso_iniciado',
            'ocurrido_at' => $inicio->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseHas('eventos_prefrio', [
            'proceso_prefrio_id' => $proceso['id'],
            'tipo' => 'verificacion_final',
            'ocurrido_at' => $termino->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_oficina_y_pda_exponen_fecha_hora_manual_de_prefrio(): void
    {
        $this->get('/oficina/prefrio')
            ->assertOk()
            ->assertSee('Registrar acción con fecha y hora real')
            ->assertSee('Fecha y hora de la acción');

        $office = file_get_contents(resource_path('js/office-prefrio.js'));
        $mobile = file_get_contents(base_path('mobile/src/screens/PrefrioScreen.tsx'));
        $this->assertIsString($office);
        $this->assertIsString($mobile);
        $this->assertStringContainsString('localDateTimeValue', $office);
        $this->assertStringContainsString('Finalizar y enviar a verificación', $office);
        $this->assertStringContainsString('parseOperationalDateTime', $mobile);
        $this->assertStringContainsString('DD-MM-AAAA HH:mm', $mobile);
    }

    public function test_pda_bloquea_duplicados_y_recupera_conflictos_de_la_bandeja_offline(): void
    {
        $mobile = file_get_contents(base_path('mobile/src/screens/PrefrioScreen.tsx'));
        $store = file_get_contents(base_path('mobile/src/services/prefrioOfflineStore.ts'));

        $this->assertIsString($mobile);
        $this->assertIsString($store);
        $this->assertStringContainsString('hasEquivalentPendingCommand', $mobile);
        $this->assertStringContainsString('processHasBlockingIssue', $mobile);
        $this->assertStringContainsString('operationInFlight', $mobile);
        $this->assertStringContainsString('fue confirmado por el servidor', $mobile);
        $this->assertStringContainsString('quedó guardado en la bandeja y se enviará automáticamente', $mobile);
        $this->assertStringContainsString('Descartar operaciones y refrescar', $mobile);
        $this->assertStringContainsString('Las operaciones posteriores de este proceso fueron descartadas', $mobile);
        $this->assertStringContainsString('removePrefrioProcessCommands', $mobile);
        $this->assertStringContainsString('removePrefrioProcessCommands', $store);
        $this->assertStringNotContainsString('quedó registrado${baseUrl', $mobile);
    }

    public function test_pda_reutiliza_revisiones_de_prefrio_sin_vaciar_la_bandeja(): void
    {
        $api = file_get_contents(base_path('mobile/src/services/prefrioApi.ts'));
        $estibaApi = file_get_contents(base_path('mobile/src/services/estibaApi.ts'));
        $http = file_get_contents(base_path('mobile/src/services/httpClient.ts'));
        $tuneles = file_get_contents(base_path('mobile/src/screens/PrefrioScreen.tsx'));
        $pendientes = file_get_contents(base_path('mobile/src/screens/PrefrioWorkspaceScreen.tsx'));

        $this->assertIsString($api);
        $this->assertIsString($estibaApi);
        $this->assertIsString($http);
        $this->assertIsString($tuneles);
        $this->assertIsString($pendientes);
        $this->assertStringContainsString('DEFAULT_API_TIMEOUT_MS = 15_000', $http);
        $this->assertStringContainsString('new AbortController()', $http);
        $this->assertStringContainsString('fetchWithTimeout', $api);
        $this->assertStringContainsString('fetchWithTimeout', $estibaApi);
        $this->assertStringNotContainsString('response = await fetch(', $api);
        $this->assertStringNotContainsString('response = await fetch(', $estibaApi);
        $this->assertStringContainsString("headers.set('If-None-Match', etag)", $api);
        $this->assertStringContainsString('response.status === 304', $api);
        $this->assertStringContainsString('tunnels.data ?? current.tunnels', $tuneles);
        $this->assertStringContainsString('nextFolios.data ?? current.eligible_folios', $pendientes);
        $this->assertStringContainsString('revisions:', $tuneles);
        $this->assertStringContainsString('revisions:', $pendientes);
    }

    public function test_proceso_de_otra_temporada_bloquea_el_tunel_y_no_admite_acciones(): void
    {
        [$tunel, $posicion, $token] = $this->contexto();
        $proceso = $this->crearProceso($token, $tunel);
        $folio = $this->folioPendiente('PAL-PF-TEMP-ANTERIOR');
        $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'temperatura_inicial' => 9.2,
            'ocurrido_at' => now()->toAtomString(),
        ]);
        ProcesoPrefrio::query()->findOrFail($proceso['id'])->update(['estado' => 'en_proceso']);

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'PF-SIGUIENTE',
            'nombre' => 'Temporada siguiente de prefrío',
            'activa' => true,
        ]);

        $this->conToken($token)
            ->postJson("/api/prefrio/procesos/{$proceso['id']}/cancelar", [
                ...$this->payloadAccion(0),
                'motivo' => 'Cierre fuera de la temporada activa.',
            ])
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa');

        // Antes el túnel figuraba «disponible» aunque el proceso lo seguía ocupando.
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $puesto = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.prefrio.resumen.tuneles_bloqueados_otra_temporada', 1)
            ->assertJsonPath('data.prefrio.resumen.posiciones_ocupadas', 1)
            ->assertJsonPath('data.prefrio.resumen.posiciones_sin_cerrar', 1)
            ->assertJsonPath('data.prefrio.resumen.folios_en_tunel', 1)
            ->assertJsonPath('data.prefrio.resumen.folios_sin_cerrar', 1);
        $tunelEnPuesto = collect($puesto->json('data.prefrio.tuneles'))
            ->firstWhere('id', $tunel->id);

        $this->assertSame('bloqueado_otra_temporada', $tunelEnPuesto['estado_operacional']);
        $this->assertSame(1, $tunelEnPuesto['posiciones_ocupadas']);
        $this->assertSame(1, $tunelEnPuesto['posiciones_sin_cerrar']);
        $this->assertSame(0, $tunelEnPuesto['posiciones_disponibles']);
        $this->assertSame($proceso['codigo'], $tunelEnPuesto['proceso_otra_temporada']['codigo']);
        $this->assertNull($tunelEnPuesto['proceso_activo']);
    }

    /**
     * @return array{TunelPrefrio, PosicionTunelPrefrio, string}
     */
    private function contexto(): array
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        [, $token] = $this->acceso(RolUsuario::OperadorPrefrio, 'PF-OP-'.Str::random(6));
        $tunelId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/prefrio/tuneles', [
                'nombre' => 'Túnel de prueba',
                'capacidad_posiciones' => 22,
                'setpoint_habitual' => -1.5,
                'estado_tecnico' => 'operativo',
            ])
            ->assertCreated()
            ->json('data.id');
        $tunel = TunelPrefrio::query()->findOrFail($tunelId);
        $posicion = $tunel->posiciones()->orderBy('numero')->firstOrFail();

        return [$tunel, $posicion, $token];
    }

    private function folioPendiente(
        string $numero,
        TipoBulto $tipoBulto = TipoBulto::Pallet,
    ): Folio {
        return Folio::create([
            'numero_folio' => $numero,
            'tipo_bulto' => $tipoBulto,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function crearProceso(string $token, TunelPrefrio $tunel): array
    {
        return $this->conToken($token)
            ->postJson('/api/prefrio/procesos', $this->payloadProceso(
                $tunel->id,
                (string) Str::uuid(),
            ))
            ->assertCreated()
            ->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function llevarAVerificacion(
        string $token,
        TunelPrefrio $tunel,
        PosicionTunelPrefrio $posicion,
        Folio $folio,
    ): array {
        $proceso = $this->crearProceso($token, $tunel);
        $proceso = $this->accion($token, "/api/prefrio/procesos/{$proceso['id']}/folios", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'temperatura_inicial' => 9.2,
            'ocurrido_at' => now()->toAtomString(),
        ]);
        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/confirmar-armado",
            $this->payloadAccion(1),
        );
        $proceso = $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/iniciar",
            $this->payloadAccion(2),
        );

        return $this->accion(
            $token,
            "/api/prefrio/procesos/{$proceso['id']}/verificar",
            [...$this->payloadAccion(3), 'datos' => ['lectura_final' => self::lecturaCompleta()]],
        );
    }

    /**
     * Lectura POEMP R-41 completa: ventilador, medio y puerta, exterior e interior a cada lado.
     *
     * @return array<string, array<string, float>>
     */
    public static function lecturaCompleta(float $base = 0.0): array
    {
        $lectura = [];
        foreach (['ventilador', 'medio', 'puerta'] as $fila => $sector) {
            foreach (['izq_ex', 'izq_in', 'der_in', 'der_ex'] as $columna => $punto) {
                $lectura[$sector][$punto] = round($base + $fila * 0.3 - $columna * 0.1, 1);
            }
        }

        return $lectura;
    }

    /**
     * @param  list<array{Folio, PosicionTunelPrefrio}>  $cargas
     * @return array<string, mixed>
     */
    private function iniciarConFolios(string $token, TunelPrefrio $tunel, array $cargas): array
    {
        $proceso = $this->crearProceso($token, $tunel);
        $ruta = "/api/prefrio/procesos/{$proceso['id']}";
        foreach ($cargas as [$folio, $posicion]) {
            $proceso = $this->accion($token, "{$ruta}/folios", [
                ...$this->payloadAccion($proceso['version']), 'folio_id' => $folio->id, 'posicion_tunel_prefrio_id' => $posicion->id,
            ]);
        }
        $proceso = $this->accion($token, "{$ruta}/confirmar-armado", $this->payloadAccion($proceso['version']));

        return $this->accion($token, "{$ruta}/iniciar", $this->payloadAccion($proceso['version']));
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadProceso(string $tunelId, string $operacionId): array
    {
        return [
            'operacion_id' => $operacionId,
            'tunel_prefrio_id' => $tunelId,
            'setpoint' => -1.5,
            'duracion_objetivo_minutos' => 720,
            'formato_referencia' => 'Granel 5 kg',
            'ocurrido_at' => now()->toAtomString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadAccion(int $version): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $version,
            'ocurrido_at' => now()->toAtomString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function accion(string $token, string $ruta, array $payload): array
    {
        return $this->conToken($token)
            ->postJson($ruta, $payload)
            ->assertOk()
            ->json('data');
    }

    /**
     * @return array{User, string}
     */
    private function acceso(RolUsuario $rol, string $codigo): array
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $dispositivo = Dispositivo::create([
            'codigo' => mb_strtoupper($codigo),
            'nombre' => "PDA {$codigo}",
            'plataforma' => 'android',
            'activo' => true,
        ]);
        $token = $usuario->crearTokenParaDispositivo(
            $dispositivo,
            "test-{$codigo}",
        )->plainTextToken;

        return [$usuario, $token];
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
