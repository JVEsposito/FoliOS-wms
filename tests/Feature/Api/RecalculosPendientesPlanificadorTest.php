<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Jobs\EjecutarRecalculoPendientePlanificador;
use App\Models\Carga;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Cargas\ServicioPlanConcentracionCarga;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RecalculosPendientesPlanificadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambio_de_carga_queda_pendiente_hasta_que_el_worker_confirma_su_version(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $carga = Carga::create([
            'temporada_id' => Temporada::query()->where('activa', true)->firstOrFail()->id,
            'codigo' => 'CAR-PROY-001',
            'estado' => 'borrador',
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
        ]);
        $carga->update(['version' => 2]);
        $tipo = ServicioRecalculosPendientesPlanificador::CARGA;

        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => $tipo,
            'fuente_id' => $carga->id,
            'version_solicitada' => 1,
            'version_calculada' => 0,
        ]);

        app(ServicioRecalculosPendientesPlanificador::class)->ejecutar($tipo, $carga->id);

        $this->assertDatabaseMissing('recalculos_pendientes_planificador', [
            'tipo' => $tipo,
            'fuente_id' => $carga->id,
        ]);

        $carga->update(['version' => 3]);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => $tipo,
            'fuente_id' => $carga->id,
            'version_solicitada' => 1,
            'version_calculada' => 0,
            'pendiente' => true,
        ]);
    }

    public function test_fallo_al_publicar_el_job_conserva_el_commit_y_la_solicitud_recuperable(): void
    {
        config(['queue.default' => 'database']);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Cola temporalmente inaccesible'));
        $id = (string) Str::uuid();
        DB::transaction(fn () => app(ServicioRecalculosPendientesPlanificador::class)
            ->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $id));
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => ServicioRecalculosPendientesPlanificador::CARGA,
            'fuente_id' => $id, 'pendiente' => true, 'version_solicitada' => 1,
        ]);
    }

    public function test_una_mutacion_revertida_no_deja_solicitudes_huerfanas(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $carga = Carga::create([
            'temporada_id' => Temporada::query()->where('activa', true)->firstOrFail()->id,
            'codigo' => 'CAR-PROY-002',
            'estado' => 'borrador',
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
        ]);

        try {
            DB::transaction(function () use ($carga): void {
                $carga->update(['version' => 2]);
                throw new RuntimeException('Transacción abortada');
            });
        } catch (RuntimeException) {
            // El movimiento y la solicitud pendiente deben revertirse juntos.
        }

        $this->assertSame(1, $carga->fresh()->version);
        $this->assertDatabaseMissing('recalculos_pendientes_planificador', [
            'tipo' => ServicioRecalculosPendientesPlanificador::CARGA,
            'fuente_id' => $carga->id,
        ]);
    }

    public function test_una_fuente_eliminada_se_descarta_sin_reintentos_ni_ruido_en_salud(): void
    {
        $fuenteId = (string) Str::uuid();
        $tipo = ServicioRecalculosPendientesPlanificador::CARGA;
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $servicio->solicitar($tipo, $fuenteId);

        $servicio->ejecutar($tipo, $fuenteId);

        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $fuenteId,
            'pendiente' => false,
            'version_solicitada' => 1,
            'version_calculada' => 0,
        ]);
        $this->assertSame(0, $servicio->salud()['pendientes']);
        $this->assertSame(0, $servicio->salud()['fallidos']);
        $this->assertSame(1, $servicio->salud()['descartados']);
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->assertSame(0, $servicio->recuperar());
        Queue::assertNothingPushed();

        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/administracion/planificador/salud')
            ->assertOk()
            ->assertJsonPath('data.proyecciones_pendientes.pendientes', 0)
            ->assertJsonPath('data.proyecciones_pendientes.fallidos', 0)
            ->assertJsonPath('data.proyecciones_pendientes.descartados', 1);
    }

    public function test_solicitar_usa_una_sola_sentencia_atomica_por_version(): void
    {
        $consultas = [];
        DB::listen(function (QueryExecuted $consulta) use (&$consultas): void {
            $sql = strtolower($consulta->sql);
            if (str_starts_with($sql, 'insert')
                && str_contains($sql, 'recalculos_pendientes_planificador')) {
                $consultas[] = $consulta->sql;
            }
        });
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $fuenteId = (string) Str::uuid();

        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $fuenteId);
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $fuenteId);

        $this->assertCount(2, $consultas);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $fuenteId,
            'version_solicitada' => 2,
            'pendiente' => true,
        ]);
    }

    public function test_la_cola_database_se_publica_solo_despues_del_commit(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $fuenteId = (string) Str::uuid();

        DB::transaction(function () use ($fuenteId): void {
            app(ServicioRecalculosPendientesPlanificador::class)
                ->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $fuenteId);
            Queue::assertNothingPushed();
        });

        Queue::assertPushed(
            EjecutarRecalculoPendientePlanificador::class,
            fn (EjecutarRecalculoPendientePlanificador $job): bool => $job->fuenteId === $fuenteId,
        );
    }

    public function test_repa_usa_una_fila_por_tarea_aunque_compartan_temporada(): void
    {
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $temporadaId = Temporada::query()->where('activa', true)->firstOrFail()->id;
        $tareaUno = (string) Str::uuid();
        $tareaDos = (string) Str::uuid();

        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::BUFFER_REPA, $tareaUno, $temporadaId);
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::BUFFER_REPA, $tareaDos, $temporadaId);

        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => ServicioRecalculosPendientesPlanificador::BUFFER_REPA,
            'fuente_id' => $tareaUno,
            'objetivo_id' => $temporadaId,
        ]);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'tipo' => ServicioRecalculosPendientesPlanificador::BUFFER_REPA,
            'fuente_id' => $tareaDos,
            'objetivo_id' => $temporadaId,
        ]);
        $this->assertSame(2, DB::table('recalculos_pendientes_planificador')
            ->where('tipo', ServicioRecalculosPendientesPlanificador::BUFFER_REPA)
            ->count());
    }

    public function test_un_fallo_permanente_se_agota_y_el_watchdog_deja_de_reenviarlo(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $carga = Carga::create([
            'temporada_id' => Temporada::query()->where('activa', true)->firstOrFail()->id,
            'codigo' => 'CAR-PROY-FALLA',
            'estado' => 'borrador',
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
        ]);
        $this->mock(ServicioPlanConcentracionCarga::class)
            ->shouldReceive('sincronizar')
            ->times(ServicioRecalculosPendientesPlanificador::MAX_INTENTOS_FALLIDOS)
            ->andThrow(new RuntimeException('Fallo permanente de prueba.'));
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $carga->id);

        for ($intento = 0; $intento < ServicioRecalculosPendientesPlanificador::MAX_INTENTOS_FALLIDOS; $intento++) {
            try {
                $servicio->ejecutar(ServicioRecalculosPendientesPlanificador::CARGA, $carga->id);
            } catch (RuntimeException) {
                // El worker reintenta hasta que la solicitud queda agotada.
            }
        }

        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $carga->id,
            'pendiente' => false,
            'intentos_fallidos' => ServicioRecalculosPendientesPlanificador::MAX_INTENTOS_FALLIDOS,
        ]);
        $this->assertSame(0, $servicio->salud()['fallidos']);
        $this->assertSame(1, $servicio->salud()['agotados']);
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->assertSame(0, $servicio->recuperar());
        Queue::assertNothingPushed();

        $this->assertSame(0, Artisan::call('planificador:recuperar-proyecciones', [
            '--reintentar-agotados' => true,
        ]));
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $carga->id,
            'pendiente' => true,
            'intentos_fallidos' => 0,
            'agotado_at' => null,
        ]);
        Queue::assertPushed(
            EjecutarRecalculoPendientePlanificador::class,
            fn (EjecutarRecalculoPendientePlanificador $job): bool => $job->fuenteId === $carga->id,
        );

        app()->forgetInstance(ServicioPlanConcentracionCarga::class);
        $servicio->ejecutar(ServicioRecalculosPendientesPlanificador::CARGA, $carga->id);
        $this->assertDatabaseMissing('recalculos_pendientes_planificador', ['fuente_id' => $carga->id]);
    }

    public function test_reintento_manual_respeta_limite_y_no_reactiva_fuentes_descartadas(): void
    {
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $tipo = ServicioRecalculosPendientesPlanificador::CARGA;
        $fuentes = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        foreach ($fuentes as $fuenteId) {
            $servicio->solicitar($tipo, $fuenteId);
        }

        DB::table('recalculos_pendientes_planificador')
            ->whereIn('fuente_id', array_slice($fuentes, 0, 2))
            ->update([
                'pendiente' => false,
                'agotado_at' => now(),
                'intentos_fallidos' => ServicioRecalculosPendientesPlanificador::MAX_INTENTOS_FALLIDOS,
            ]);
        $servicio->ejecutar($tipo, $fuentes[2]);

        Queue::fake();
        config(['queue.default' => 'database']);
        $this->assertSame(0, Artisan::call('planificador:recuperar-proyecciones', ['--limite' => 1]));
        Queue::assertNothingPushed();

        $this->assertSame(0, Artisan::call('planificador:recuperar-proyecciones', [
            '--limite' => 1,
            '--reintentar-agotados' => true,
        ]));
        $this->assertSame(1, $servicio->salud()['pendientes']);
        $this->assertSame(1, $servicio->salud()['agotados']);
        $this->assertSame(1, $servicio->salud()['descartados']);
        Queue::assertPushed(EjecutarRecalculoPendientePlanificador::class, 1);

        $this->assertSame(0, Artisan::call('planificador:recuperar-proyecciones', [
            '--limite' => 1,
            '--reintentar-agotados' => true,
        ]));
        $this->assertSame(0, $servicio->salud()['agotados']);
        $this->assertSame(1, $servicio->salud()['descartados']);
    }

    public function test_no_reactiva_agotados_con_cola_sin_worker(): void
    {
        $fuenteId = (string) Str::uuid();
        app(ServicioRecalculosPendientesPlanificador::class)
            ->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $fuenteId);
        DB::table('recalculos_pendientes_planificador')
            ->where('fuente_id', $fuenteId)
            ->update(['pendiente' => false, 'agotado_at' => now()]);

        config(['queue.default' => 'sync']);
        $this->assertSame(1, Artisan::call('planificador:recuperar-proyecciones', [
            '--reintentar-agotados' => true,
        ]));
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $fuenteId,
            'pendiente' => false,
        ]);
    }

    public function test_reintento_por_tipo_solo_reactiva_y_reenvia_el_tipo_indicado_y_deja_rastro(): void
    {
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $cargaId = (string) Str::uuid();
        $repaId = (string) Str::uuid();
        $otroPendienteId = (string) Str::uuid();
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $cargaId);
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::BUFFER_REPA, $repaId);
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::SEGREGACION, $otroPendienteId);

        DB::table('recalculos_pendientes_planificador')
            ->whereIn('fuente_id', [$cargaId, $repaId])
            ->update(['pendiente' => false, 'agotado_at' => now(), 'intentos_fallidos' => 5]);

        Queue::fake();
        Log::spy();
        config(['queue.default' => 'database']);
        $this->assertSame(0, Artisan::call('planificador:recuperar-proyecciones', [
            '--tipo' => ServicioRecalculosPendientesPlanificador::CARGA,
            '--reintentar-agotados' => true,
        ]));

        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $cargaId,
            'pendiente' => true,
            'agotado_at' => null,
            'intentos_fallidos' => 0,
        ]);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $repaId,
            'pendiente' => false,
            'intentos_fallidos' => 5,
        ]);
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $otroPendienteId,
            'pendiente' => true,
            'ultimo_reenvio_at' => null,
        ]);
        Queue::assertPushed(EjecutarRecalculoPendientePlanificador::class, 1);
        Queue::assertPushed(EjecutarRecalculoPendientePlanificador::class,
            fn (EjecutarRecalculoPendientePlanificador $job): bool => $job->fuenteId === $cargaId);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $mensaje, array $contexto): bool => str_contains($mensaje, 'Reintento manual')
                && $contexto['tipo'] === ServicioRecalculosPendientesPlanificador::CARGA
                && $contexto['reactivados'] === 1
                && $contexto['limite'] === 200
                && $contexto['usuario_sistema'] !== ''
                && $contexto['servidor'] !== '',
        );
    }

    public function test_el_tipo_invalido_o_sin_reintento_no_actua_sobre_las_proyecciones(): void
    {
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $fuenteId = (string) Str::uuid();
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $fuenteId);
        DB::table('recalculos_pendientes_planificador')
            ->where('fuente_id', $fuenteId)
            ->update(['pendiente' => false, 'agotado_at' => now()]);

        Queue::fake();
        config(['queue.default' => 'database']);
        $this->assertSame(1, Artisan::call('planificador:recuperar-proyecciones', [
            '--tipo' => ServicioRecalculosPendientesPlanificador::CARGA,
        ]));
        $this->assertSame(1, Artisan::call('planificador:recuperar-proyecciones', [
            '--tipo' => 'tipo_inexistente',
            '--reintentar-agotados' => true,
        ]));
        $this->assertDatabaseHas('recalculos_pendientes_planificador', [
            'fuente_id' => $fuenteId,
            'pendiente' => false,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_reintento_manual_se_escribe_en_el_log_con_nivel_warning_de_planta(): void
    {
        $servicio = app(ServicioRecalculosPendientesPlanificador::class);
        $fuenteId = (string) Str::uuid();
        $servicio->solicitar(ServicioRecalculosPendientesPlanificador::CARGA, $fuenteId);
        DB::table('recalculos_pendientes_planificador')
            ->where('fuente_id', $fuenteId)
            ->update(['pendiente' => false, 'agotado_at' => now()]);

        Queue::fake();
        config(['queue.default' => 'database']);
        $ruta = storage_path('logs/test-reintento-'.Str::uuid().'.log');
        $original = [
            'logging.default' => config('logging.default'),
            'logging.channels.single.path' => config('logging.channels.single.path'),
            'logging.channels.single.level' => config('logging.channels.single.level'),
        ];

        try {
            config([
                'logging.default' => 'single',
                'logging.channels.single.path' => $ruta,
                'logging.channels.single.level' => 'warning',
            ]);
            Log::forgetChannel('single');
            Log::notice('Control de filtro de nivel');

            $this->assertSame(0, Artisan::call('planificador:recuperar-proyecciones', [
                '--reintentar-agotados' => true,
                '--tipo' => ServicioRecalculosPendientesPlanificador::CARGA,
            ]));

            $contenido = File::get($ruta);
            $this->assertStringNotContainsString('Control de filtro de nivel', $contenido);
            $this->assertStringContainsString('Reintento manual de proyecciones agotadas', $contenido);
            $this->assertStringContainsString('"reactivados":1', $contenido);
            $this->assertStringContainsString('"tipo":"concentracion_carga"', $contenido);
        } finally {
            config($original);
            Log::forgetChannel('single');
            File::delete($ruta);
        }
    }
}
