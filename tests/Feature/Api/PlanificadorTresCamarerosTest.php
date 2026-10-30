<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FuncionCamareroTurno;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaTareaMovimiento;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TurnoPlanificador;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Cargas\CalculadorConcentracionCarga;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlanificadorTresCamarerosTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        // El siguiente caso reconstruye su esquema; no repetir una migración completa
        // al destruir la aplicación. RefreshDatabase verá la base como no migrada.
        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_tres_procesos_no_toman_distintas_tareas_del_mismo_pallet_con_lecturas_anteriores(): void
    {
        $this->requiereMysql();
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $folio = $this->folio($temporada, 'RACE');
        $identidades = [];
        foreach (range(1, 3) as $n) {
            [$usuario, $tablet] = $this->identidad($n);
            $plan = PlanOperacional::create(['temporada_id' => $temporada->id, 'tipo' => 'almacenamiento_pallet',
                'estado' => 'programado', 'prioridad' => 'normal', 'titulo' => 'Alternativa histórica '.$n,
                'creado_por_user_id' => $usuario->id, 'programado_at' => now(), 'contexto' => ['planner_horizon' => 'rolling']]);
            // Datos históricos incompatibles: la toma debe proteger incluso esta situación.
            $tarea = TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'folio_id' => $folio->id,
                'tipo_movimiento' => 'ubicacion_inicial', 'secuencia' => 1, 'estado' => 'pendiente', 'prioridad' => 'normal']);
            $identidades[] = ['usuario' => $usuario->id, 'tablet' => $tablet->id, 'tarea' => $tarea->id];
        }
        $resultados = $this->ejecutar(['escenario' => 'pallet_repetido', 'modo' => 'off', 'identidades' => $identidades]);
        $estados = array_column($resultados, 'estado');
        sort($estados);
        $this->assertSame(['asumida', 'conflicto', 'conflicto'], $estados);
        $this->assertSame(1, ReservaTareaMovimiento::whereNotNull('bloqueo_tarea_id')->count());
        $this->assertSame(1, TareaMovimiento::where('estado', 'asumida')->count());
        $this->assertDatabaseCount('movimientos', 0);
        $nuevo = $this->folio($temporada, 'GENERACION');
        $publicaciones = $this->ejecutar(['escenario' => 'crear_tarea', 'modo' => 'off',
            'folio' => $nuevo->id, 'temporada' => $temporada->id, 'identidades' => $identidades]);
        $estados = array_column($publicaciones, 'estado');
        sort($estados);
        $this->assertSame(['conflicto', 'conflicto', 'creada'], $estados);
        $this->assertSame(1, TareaMovimiento::where('folio_id', $nuevo->id)->count());

        $mismoCamarero = [];
        foreach ($identidades as $n => $identidad) {
            $f = $this->folio($temporada, 'INICIO-'.$n);
            $plan = PlanOperacional::where('creado_por_user_id', $identidad['usuario'])->firstOrFail();
            $t = TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'folio_id' => $f->id,
                'tipo_movimiento' => 'retiro', 'secuencia' => 2, 'estado' => 'asumida', 'prioridad' => 'normal',
                'responsable_user_id' => $identidades[0]['usuario'], 'dispositivo_id' => $identidad['tablet']]);
            ReservaTareaMovimiento::create(['tarea_movimiento_id' => $t->id, 'bloqueo_tarea_id' => $t->id,
                'estado' => 'activa', 'user_id' => $identidades[0]['usuario'], 'dispositivo_id' => $identidad['tablet'],
                'reservada_at' => now(), 'renovada_at' => now(), 'vence_at' => now()->addMinutes(10)]);
            $mismoCamarero[] = ['usuario' => $identidades[0]['usuario'], 'tablet' => $identidad['tablet'], 'tarea' => $t->id];
        }
        $inicios = $this->ejecutar(['escenario' => 'iniciar', 'modo' => 'off', 'identidades' => $mismoCamarero]);
        $estados = array_column($inicios, 'estado');
        sort($estados);
        $this->assertSame(['conflicto', 'conflicto', 'iniciada'], $estados);
        $this->assertSame(1, TareaMovimiento::where('estado', 'en_proceso')->where('responsable_user_id', $identidades[0]['usuario'])->count());
    }

    public function test_tres_camareros_llenan_la_camara_con_84_movimientos_sin_cruzar_labores_pallets_ni_bandas(): void
    {
        $this->requiereMysql();
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'server',
            'planificador.horizon' => 'rolling', 'planificador.generacion_automatica' => true]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $destino = $this->camara($supervisor, 'DESTINO', 6, 14);
        $origen = $this->camara($supervisor, 'ORIGEN', 20, 1);
        $turno = TurnoPlanificador::create(['temporada_id' => $temporada->id, 'nombre' => 'Auditoría tres camareros',
            'inicio_at' => now()->subHour(), 'fin_at' => now()->addHours(8), 'creado_por_user_id' => $supervisor->id,
            'actualizado_por_user_id' => $supervisor->id]);
        $identidades = [];
        $cargas = [];
        foreach ([TipoPlanOperacional::RecepcionTunel, TipoPlanOperacional::AlmacenamientoPallet, TipoPlanOperacional::ConcentracionCarga] as $n => $tipo) {
            [$usuario, $tablet] = $this->identidad($n + 1);
            FuncionCamareroTurno::create(['turno_id' => $turno->id, 'user_id' => $usuario->id,
                'funcion_principal' => ['recepcion_tuneles', 'recepcion_packing', 'separacion_cargas'][$n],
                'funciones_apoyo' => [], 'disponible' => true]);
            $carga = Carga::create(['temporada_id' => $temporada->id, 'codigo' => 'CAR-AUDIT-'.$n,
                'estado' => 'pendiente', 'camara_objetivo_id' => $destino->id,
                'creada_por_user_id' => $supervisor->id, 'actualizada_por_user_id' => $supervisor->id]);
            $cargas[] = $carga;
            $datos = [];
            foreach (range(1, 20) as $j) {
                $folio = $this->folio($temporada, $n.'-'.$j);
                $asignacion = CargaFolio::create(['carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => 'pendiente',
                    'asignado_por_user_id' => $supervisor->id, 'asignado_at' => now()]);
                ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);
                $contexto = ['carga_id' => $carga->id];
                $extremos = [];
                if ($n === 2) {
                    $p = $origen->posiciones()->where('banda', $j)->sole();
                    UbicacionActual::withoutEvents(fn () => UbicacionActual::create(['folio_id' => $folio->id,
                        'camara_id' => $origen->id, 'posicion_id' => $p->id, 'ubicado_at' => now()]));
                    Folio::withoutEvents(fn () => $folio->update(['estado_operacional' => 'disponible']));
                    $extremos = ['camara_origen_id' => $origen->id, 'posicion_origen_id' => $p->id,
                        'camara_destino_id' => $destino->id];
                    $contexto += ['tipo_decision' => 'concentrar_carga', 'camara_objetivo_id' => $destino->id, 'concentracion_puntos' => []];
                }
                $datos[] = ['folio_id' => $folio->id, 'tipo_movimiento' => $n === 2
                    ? TipoMovimiento::TrasladoEntreCamaras : TipoMovimiento::UbicacionInicial, 'contexto' => $contexto, ...$extremos];
            }
            $plan = app(ServicioPlanesOperacionales::class)->crear($temporada, $tipo, 'Labor '.$n, $supervisor, $datos,
                contexto: ['planner_horizon' => 'rolling', 'carga_id' => $carga->id,
                    ...($n === 1 ? ['origen_logico' => 'recepcion_externa'] : [])]);
            $identidades[] = ['usuario' => $usuario->id, 'tablet' => $tablet->id, 'plan' => $plan->id];
        }
        $resultados = $this->ejecutar(['escenario' => 'continuo', 'temporada' => $temporada->id, 'identidades' => $identidades]);
        foreach ($resultados as $n => $resultado) {
            $this->assertSame('completado', $resultado['estado']);
            $movimientos = array_values(array_filter($resultado['eventos'], fn ($e) => $e['evento'] === 'movimiento'));
            $this->assertCount(20, $movimientos);
            $porBanda = array_count_values(array_column($movimientos, 'banda'));
            sort($porBanda);
            $this->assertSame([6, 14], $porBanda, json_encode($movimientos));
            $this->assertCount(12, array_filter($resultado['eventos'], fn ($e) => $e['evento'] === 'intrusion_rechazada'));
            $this->assertSame(20, TareaMovimiento::where('plan_operacional_id', $identidades[$n]['plan'])
                ->where('estado', 'completada')->where('responsable_user_id', $identidades[$n]['usuario'])->count());
        }
        // Continúa desde 60/84 hasta llenar la cámara: ocho pallets más por labor.
        $segundaRonda = [];
        foreach ($identidades as $n => $identidad) {
            $datos = [];
            foreach (range(1, 8) as $j) {
                $folio = $this->folio($temporada, $n.'-EXTRA-'.$j);
                $asignacion = CargaFolio::create(['carga_id' => $cargas[$n]->id, 'folio_id' => $folio->id, 'estado' => 'pendiente',
                    'asignado_por_user_id' => $supervisor->id, 'asignado_at' => now()]);
                ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);
                $extremos = [];
                $contexto = ['carga_id' => $cargas[$n]->id];
                if ($n === 2) {
                    $p = $origen->posiciones()->where('banda', $j)->sole();
                    UbicacionActual::withoutEvents(fn () => UbicacionActual::create(['folio_id' => $folio->id,
                        'camara_id' => $origen->id, 'posicion_id' => $p->id, 'ubicado_at' => now()]));
                    Folio::withoutEvents(fn () => $folio->update(['estado_operacional' => 'disponible']));
                    $extremos = ['camara_origen_id' => $origen->id, 'posicion_origen_id' => $p->id, 'camara_destino_id' => $destino->id];
                    $contexto += ['tipo_decision' => 'concentrar_carga', 'camara_objetivo_id' => $destino->id, 'concentracion_puntos' => []];
                }
                $datos[] = ['folio_id' => $folio->id, 'tipo_movimiento' => $n === 2
                    ? TipoMovimiento::TrasladoEntreCamaras : TipoMovimiento::UbicacionInicial, 'contexto' => $contexto, ...$extremos];
            }
            $tipo = [TipoPlanOperacional::RecepcionTunel, TipoPlanOperacional::AlmacenamientoPallet, TipoPlanOperacional::ConcentracionCarga][$n];
            $plan = app(ServicioPlanesOperacionales::class)->crear($temporada, $tipo, 'Continuación '.$n, $supervisor, $datos,
                contexto: ['planner_horizon' => 'rolling', 'carga_id' => $cargas[$n]->id,
                    ...($n === 1 ? ['origen_logico' => 'recepcion_externa'] : [])]);
            $segundaRonda[] = [...$identidad, 'plan' => $plan->id];
        }
        $finales = $this->ejecutar(['escenario' => 'continuo', 'temporada' => $temporada->id,
            'sincronizar_rondas' => false, 'identidades' => $segundaRonda]);
        foreach ($finales as $resultado) {
            $this->assertSame('completado', $resultado['estado']);
            $this->assertCount(8, array_filter($resultado['eventos'], fn ($e) => $e['evento'] === 'movimiento'));
        }
        foreach ($cargas as $carga) {
            $asignaciones = $carga->asignacionesActuales()->with('folio.ubicacionActual.posicion.camara')->get();
            $this->assertSame(100, app(CalculadorConcentracionCarga::class)->analizar($asignaciones)['porcentaje']);
        }
        $this->assertDatabaseCount('movimientos', 84);
        $this->assertSame(84, UbicacionActual::where('camara_id', $destino->id)->count());
        $this->assertSame(0, UbicacionActual::where('camara_id', $origen->id)->count());
        $this->assertSame(0, ReservaTareaMovimiento::whereNotNull('bloqueo_tarea_id')->count());
        $this->assertDatabaseMissing('reservas_bandas_maniobra', ['liberada_at' => null]);
        $this->assertDatabaseMissing('sesiones_estiba', ['estado' => 'abierta']);
        $this->assertDatabaseMissing('labores_camarero', ['estado' => 'en_curso']);
        $this->assertSame(0, DB::table('ubicaciones_actuales as u')->join('posiciones as p', 'p.id', '=', 'u.posicion_id')
            ->join('carga_folios as cf', 'cf.folio_id', '=', 'u.folio_id')->where('u.camara_id', $destino->id)
            ->select('p.banda')->groupBy('p.banda')->havingRaw('COUNT(DISTINCT cf.carga_id) > 1')->get()->count());
        $exceso = $this->folio($temporada, 'CAMARA-LLENA');
        $asignacion = CargaFolio::create(['carga_id' => $cargas[0]->id, 'folio_id' => $exceso->id,
            'estado' => 'pendiente', 'asignado_por_user_id' => $supervisor->id, 'asignado_at' => now()]);
        ReservaCargaFolio::create(['folio_id' => $exceso->id, 'carga_folio_id' => $asignacion->id]);
        $planExtra = app(ServicioPlanesOperacionales::class)->crear($temporada, TipoPlanOperacional::RecepcionTunel,
            'Recepción sin capacidad', $supervisor, [['folio_id' => $exceso->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'camara_destino_id' => $destino->id]], contexto: ['planner_horizon' => 'rolling', 'carga_id' => $cargas[0]->id]);
        try {
            app(ServicioPlanesOperacionales::class)->asumir($planExtra->tareas()->sole(), User::findOrFail($identidades[0]['usuario']),
                Dispositivo::findOrFail($identidades[0]['tablet']));
            $this->fail('Autorizó un pallet adicional con la cámara llena.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('destino', $error->getMessage());
        }
        $this->assertNull($exceso->refresh()->ubicacionActual);
        $this->assertSame(0, ReservaTareaMovimiento::whereNotNull('bloqueo_tarea_id')->count());
    }

    private function ejecutar(array $entrada): array
    {
        $dir = sys_get_temp_dir().'/planner-audit-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/entrada.json', json_encode($entrada, JSON_THROW_ON_ERROR));
        $procesos = [];
        try {
            foreach (range(0, 2) as $n) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/planificador_concurrencia_worker.php'), base_path(), $dir.'/entrada.json', (string) $n], base_path());
                $p->setTimeout(300);
                $p->start();
                $procesos[] = $p;
            }
            do {
                $activos = false;
                foreach ($procesos as $p) {
                    $p->checkTimeout();
                    $activos = $p->isRunning() || $activos;
                }
                $duplicados = DB::table('reservas_tareas_movimiento as r')->join('tareas_movimiento as t', 't.id', '=', 'r.bloqueo_tarea_id')
                    ->where('r.estado', 'activa')->select('t.folio_id')->groupBy('t.folio_id')->havingRaw('COUNT(DISTINCT t.id) > 1')->get();
                $this->assertCount(0, $duplicados, 'Pallet asignado simultáneamente a más de un camarero');
                usleep(20000);
            } while ($activos);
            $resultados = [];
            $errores = [];
            foreach ($procesos as $p) {
                $p->wait();
                if (! $p->isSuccessful()) {
                    $errores[] = $p->getErrorOutput().$p->getOutput();
                }
                $resultados[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }
            if ($reporte = getenv('WMS_PLANNER_AUDIT_RESULT')) {
                file_put_contents($reporte, json_encode(['escenario' => $entrada['escenario'],
                    'resultados' => $resultados, 'errores' => $errores], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
            }
            $this->assertSame([], $errores, json_encode($errores));

            return $resultados;
        } finally {
            foreach ($procesos as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach (glob($dir.'/*') as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    private function requiereMysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Usa tres procesos y bloqueos reales de MySQL.');
        }
    }

    private function identidad(int $n): array
    {
        return [User::factory()->create(['rol' => RolUsuario::CamareroFrio]),
            Dispositivo::create(['codigo' => 'AUDIT-'.$n, 'nombre' => 'Tablet '.$n])];
    }

    private function folio(Temporada $temporada, string $sufijo): Folio
    {
        return Folio::create(['temporada_id' => $temporada->id, 'numero_folio' => 'AUDIT-'.$sufijo,
            'tipo_bulto' => 'pallet', 'fecha_ingreso' => now(), 'activo' => true, 'exportadora' => 'Cliente compartido',
            'marca' => 'Marca compartida', 'datos_externos' => ['envase' => 'Caja 5'], 'condicion_termica' => 'prefrio_aprobado',
            'habilitacion_almacenamiento' => 'habilitado', 'estado_operacional' => 'pendiente_ubicacion']);
    }

    private function camara(User $supervisor, string $codigo, int $bandas, int $profundidad): Camara
    {
        $c = Camara::create(['codigo' => 'CAM-'.$codigo, 'nombre' => $codigo, 'contenido' => 'productos', 'estado' => 'activa',
            'cantidad_bandas' => $bandas, 'posiciones_por_banda' => $profundidad, 'cantidad_niveles' => 1,
            'creado_por_user_id' => $supervisor->id, 'actualizado_por_user_id' => $supervisor->id]);
        app(ServicioBandasOperacionales::class)->sincronizar($c, $supervisor);
        foreach (range(1, $bandas) as $b) {
            foreach (range(1, $profundidad) as $p) {
                Posicion::create(['camara_id' => $c->id, 'banda' => $b, 'posicion' => $p, 'nivel' => 1,
                    'etiqueta' => sprintf('B%02d-P%02d-N1', $b, $p)]);
            }
        }

        return $c;
    }
}
