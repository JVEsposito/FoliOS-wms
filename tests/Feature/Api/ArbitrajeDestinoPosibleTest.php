<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\DecisionArbitrajeManiobra;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\PrioridadCarga;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\CicloArbitrajeManiobras;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Planificador\MotorReplayArbitrajeV5;
use App\Services\Planificador\MotorReplayArbitrajeV7;
use App\Services\Planificador\ServicioArbitrajeManiobras;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Planificador\ServicioReplayCiclosArbitraje;
use App\Services\Planificador\ServicioSaludPlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Caso de planta del 26-10-2026: las cuatro maniobras que el arbitraje dejaba tomar eran pallets
 * de túnel de CAR-000024 sin destino posible, y detrás esperaban todas las demás labores.
 * Una maniobra sin destino no debe ocupar cupo de la frontera.
 */
class ArbitrajeDestinoPosibleTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private User $supervisor;

    /** @var array<string, Camara> */
    private array $camaras = [];

    /** @var array<string, Posicion> */
    private array $posiciones = [];

    private int $folios = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.frontier_max' => 4,
            'planificador.maniobras_simultaneas_max' => 3,
        ]);
        Temporada::query()->update(['activa' => false]);
        $this->temporada = Temporada::create(['codigo' => 'TEMP-DESTINO', 'nombre' => 'Temporada destino posible', 'fecha_inicio' => '2026-09-01', 'activa' => true]);
        $this->supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
    }

    public function test_una_carga_sin_espacio_no_ocupa_la_frontera_y_deja_pasar_a_las_demas_recepciones(): void
    {
        $llena = $this->camara('CAM-LLENA', 'Cámara de tránsito 07', bandas: 2, profundidad: 2);
        $this->camara('CAM-LIBRE', 'Cámara de tránsito 05', bandas: 2, profundidad: 3);
        foreach ([[1, 1], [1, 2], [2, 1], [2, 2]] as [$banda, $posicion]) {
            $this->ubicar($this->folio(null), $this->posicion('CAM-LLENA', $banda, $posicion));
        }
        $carga = $this->carga('CAR-000024', $llena);
        $urgentes = $this->recepcion(collect(range(1, 4))->map(fn (): Folio => $this->folio($carga)), PrioridadOperacional::Urgente);
        $normales = $this->recepcion(collect(range(1, 2))->map(fn (): Folio => $this->folio(null)));

        $servicio = app(ServicioArbitrajeManiobras::class);
        $ciclo = $servicio->arbitrar($this->temporada);
        $decisiones = $ciclo->decisiones->keyBy('maniobra_operacional_id');

        foreach ($urgentes->tareas()->get() as $tarea) {
            $decision = $decisiones[$tarea->maniobra_operacional_id];
            $this->assertSame(DecisionArbitrajeManiobra::SinDestino, $decision->decision);
            $this->assertSame('sin_destino', $decision->explicacion['factor_decisivo']['codigo']);
            $this->assertStringContainsString('CAR-000024', $decision->motivo);
            $this->assertStringContainsString('Cámara de tránsito 07', $decision->motivo);
            $this->assertFalse($decision->explicacion['componentes']['destino']['posible']);
        }
        $publicadas = $servicio->idsPublicables($ciclo)->all();
        $this->assertEqualsCanonicalizing($normales->tareas()->pluck('maniobra_operacional_id')->all(), $publicadas);
        $this->assertSame(MotorReplayArbitrajeV7::VERSION_REGLAS, $ciclo->contexto['version_reglas']);

        // Un cliente anterior que intente tomarla igual recibe el rechazo del servidor.
        [$camarero, $tablet] = $this->camarero('A');
        try {
            app(ServicioPlanesOperacionales::class)->asumir($urgentes->tareas()->first(), $camarero, $tablet);
            $this->fail('Se tomó una maniobra sin destino posible.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('CAR-000024', $error->getMessage());
        }

        $salud = app(ServicioSaludPlanificador::class)->saludActual($this->temporada->id);
        $this->assertSame(4, $salud['riesgos']['maniobras_sin_destino']);
        $this->assertSame('advertencia', $salud['estado']);

        $estado = app(ServicioEstadoArbitrajePlanificador::class);
        $version = $estado->iniciarCalculo($this->temporada->id);
        $estado->completarCalculo($this->temporada->id, $version, $ciclo, 1);
        $replay = app(ServicioReplayCiclosArbitraje::class)->verificar($this->temporada, 'actual');
        $this->assertSame('coincide', $replay['estado'], json_encode($replay['verificaciones'] ?? []));
    }

    public function test_cuando_se_libera_espacio_la_maniobra_vuelve_a_la_frontera(): void
    {
        $llena = $this->camara('CAM-LLENA', 'Cámara de tránsito 07', bandas: 1, profundidad: 2);
        $this->ubicar($this->folio(null), $this->posicion('CAM-LLENA', 1, 1));
        $frente = $this->folio(null);
        $this->ubicar($frente, $this->posicion('CAM-LLENA', 1, 2));
        $carga = $this->carga('CAR-000024', $llena);
        $urgente = $this->recepcion(collect([$this->folio($carga)]), PrioridadOperacional::Urgente)->tareas()->sole();
        $servicio = app(ServicioArbitrajeManiobras::class);
        $antes = $servicio->arbitrar($this->temporada);
        $this->assertSame(DecisionArbitrajeManiobra::SinDestino, $antes->decisiones->firstWhere('maniobra_operacional_id', $urgente->maniobra_operacional_id)->decision);

        // Sale el pallet del frente: el movimiento cambia la versión del plano de la cámara.
        UbicacionActual::query()->where('folio_id', $frente->id)->delete();
        $llena->increment('version_plano');

        $despues = $servicio->arbitrar($this->temporada);
        $this->assertNotSame($antes->id, $despues->id, 'Un cambio físico produce un ciclo nuevo.');
        $this->assertSame(DecisionArbitrajeManiobra::Seleccionada, $despues->decisiones->firstWhere('maniobra_operacional_id', $urgente->maniobra_operacional_id)->decision);
    }

    public function test_una_posicion_detras_de_un_pallet_no_cuenta_como_destino(): void
    {
        $this->camara('CAM-UNICA', 'Cámara de tránsito 01', bandas: 1, profundidad: 3);
        // P01 está libre, pero P02 y P03 están ocupadas delante: no se puede llegar a P01.
        $this->ubicar($this->folio(null), $this->posicion('CAM-UNICA', 1, 2));
        $this->ubicar($this->folio(null), $this->posicion('CAM-UNICA', 1, 3));
        $tarea = $this->recepcion(collect([$this->folio(null)]))->tareas()->sole();

        $ciclo = app(ServicioArbitrajeManiobras::class)->arbitrar($this->temporada);
        $decision = $ciclo->decisiones->firstWhere('maniobra_operacional_id', $tarea->maniobra_operacional_id);

        $this->assertSame(DecisionArbitrajeManiobra::SinDestino, $decision->decision);
        $this->assertSame('Cámara de tránsito 01 no tiene posiciones libres y accesibles.', $decision->motivo);
    }

    public function test_sin_la_regla_se_conserva_el_arbitraje_anterior(): void
    {
        config(['planificador.arbitraje_destino_posible' => false]);
        $llena = $this->camara('CAM-LLENA', 'Cámara de tránsito 07', bandas: 1, profundidad: 1);
        $this->ubicar($this->folio(null), $this->posicion('CAM-LLENA', 1, 1));
        $carga = $this->carga('CAR-000024', $llena);
        $tarea = $this->recepcion(collect([$this->folio($carga)]), PrioridadOperacional::Urgente)->tareas()->sole();

        $ciclo = app(ServicioArbitrajeManiobras::class)->arbitrar($this->temporada);

        $this->assertSame(MotorReplayArbitrajeV5::VERSION_REGLAS, $ciclo->contexto['version_reglas']);
        $this->assertSame(DecisionArbitrajeManiobra::Seleccionada, $ciclo->decisiones->firstWhere('maniobra_operacional_id', $tarea->maniobra_operacional_id)->decision);
        $this->assertSame(1, CicloArbitrajeManiobras::query()->count());
    }

    public function test_servidor_elige_perfil_real_antes_que_camara_preferente_y_no_duplica_la_reserva(): void
    {
        config(['planificador.compute' => 'server', 'planificador.camara_preferente_despacho' => 'CAM-PREFERENTE']);
        $this->camara('CAM-PREFERENTE', 'Vacía preferente', 1, 3);
        $compatible = $this->camara('CAM-COMPATIBLE', 'Perfil existente', 1, 3);
        $existente = $this->folio(null);
        $existente->update(['datos_externos' => ['envase' => 'Caja 5']]);
        $this->ubicar($existente, $this->posicion('CAM-COMPATIBLE', 1, 1));
        $nuevo = $this->folio(null);
        $nuevo->update(['datos_externos' => ['envase' => 'Caja 5']]);
        $tarea = $this->recepcion(collect([$nuevo]))->tareas()->sole();
        [$operador, $tablet] = $this->camarero('PERFIL');
        $planes = app(ServicioPlanesOperacionales::class);
        $tomada = $planes->asumir($tarea, $operador, $tablet);
        $this->assertSame($compatible->id, $tomada->camara_destino_id);
        $this->assertSame($this->posicion('CAM-COMPATIBLE', 1, 2)->id, $tomada->posicion_destino_id);
        $this->assertNotNull($tomada->reservaActiva->bloqueo_posicion_id);
        $token = $operador->crearTokenParaDispositivo($tablet, 'server')->plainTextToken;
        $this->withToken($token)->postJson('/api/tareas-movimiento/'.$tarea->id.'/resolver-destino', [
            'posicion_destino_id' => $this->posicion('CAM-PREFERENTE', 1, 1)->id,
        ])->assertOk()->assertJsonPath('data.destino.posicion.id', $tomada->posicion_destino_id);
        $this->assertDatabaseCount('reservas_tareas_movimiento', 1);
        $this->assertDatabaseCount('ubicaciones_actuales', 1);
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_servidor_respeta_nueva_programacion_de_oficina_al_volver_a_tomar(): void
    {
        config(['planificador.compute' => 'server']);
        $a = $this->camara('CAM-A', 'Programada A', 1, 3);
        $b = $this->camara('CAM-B', 'Programada B', 1, 3);
        $carga = $this->carga('CAR-PROGRAMA', $a);
        $tarea = $this->recepcion(collect([$this->folio($carga)]))->tareas()->sole();
        [$operador, $tablet] = $this->camarero('OFICINA');
        $planes = app(ServicioPlanesOperacionales::class);
        $tomada = $planes->asumir($tarea, $operador, $tablet);
        $this->assertSame($a->id, $tomada->camara_destino_id);
        $planes->liberar($tomada, $operador, $tablet);
        $carga->update(['camara_objetivo_id' => $b->id]);
        $tomada = $planes->asumir($tarea->refresh(), $operador, $tablet);
        $this->assertSame($b->id, $tomada->camara_destino_id);
        $this->assertDatabaseMissing('reservas_bandas_maniobra', ['camara_id' => $a->id, 'liberada_at' => null]);
        $this->assertDatabaseHas('reservas_bandas_maniobra', ['camara_id' => $b->id, 'liberada_at' => null]);
    }

    public function test_servidor_no_ofrece_a_otro_operador_una_banda_ya_protegida(): void
    {
        config(['planificador.compute' => 'server']);
        $this->camara('CAM-UNICA', 'Única banda', 1, 3);
        $primera = $this->recepcion(collect([$this->folio(null)]))->tareas()->sole();
        $segunda = $this->recepcion(collect([$this->folio(null)]))->tareas()->sole();
        [$a, $tabletA] = $this->camarero('A');
        [$b, $tabletB] = $this->camarero('B');
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($primera, $a, $tabletA);
        try {
            $planes->asumir($segunda, $b, $tabletB);
            $this->fail('Dos operadores recibieron la misma banda protegida.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('destino', $error->getMessage());
        }
        $this->assertDatabaseCount('reservas_tareas_movimiento', 1);
        $this->assertNull($segunda->refresh()->responsable_user_id);
        $planes->liberar($primera->refresh(), $a, $tabletA);
        $tomada = $planes->asumir($segunda->refresh(), $b, $tabletB);
        $this->assertSame($this->posicion('CAM-UNICA', 1, 1)->id, $tomada->posicion_destino_id);
    }

    public function test_resolver_una_reserva_vencida_sin_custodia_libera_sus_recursos(): void
    {
        config(['planificador.compute' => 'server']);
        $this->camara('CAM-UNICA', 'Única banda', 1, 3);
        $tarea = $this->recepcion(collect([$this->folio(null)]))->tareas()->sole();
        [$operador, $tablet] = $this->camarero('VENCIDA');
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $operador, $tablet);
        $token = $operador->crearTokenParaDispositivo($tablet, 'server')->plainTextToken;
        $this->travel(30)->minutes();
        $this->withToken($token)->postJson('/api/tareas-movimiento/'.$tarea->id.'/resolver-destino')->assertConflict();
        $this->assertDatabaseMissing('reservas_tareas_movimiento', ['bloqueo_tarea_id' => $tarea->id]);
        $this->assertDatabaseMissing('reservas_bandas_maniobra', ['maniobra_operacional_id' => $tarea->maniobra_operacional_id, 'liberada_at' => null]);
        $this->assertNull($tarea->refresh()->responsable_user_id);
        $this->assertSame('pendiente', $tarea->estado->value);
        $this->travelBack();
    }

    private function camara(string $codigo, string $nombre, int $bandas, int $profundidad): Camara
    {
        $camara = Camara::create([
            'codigo' => $codigo, 'nombre' => $nombre, 'contenido' => ContenidoCamara::Productos,
            'estado' => 'activa', 'cantidad_bandas' => $bandas, 'posiciones_por_banda' => $profundidad, 'cantidad_niveles' => 1,
            'creado_por_user_id' => $this->supervisor->id, 'actualizado_por_user_id' => $this->supervisor->id,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $this->supervisor);
        foreach (range(1, $bandas) as $banda) {
            foreach (range(1, $profundidad) as $posicion) {
                $this->posiciones["{$codigo}:{$banda}:{$posicion}"] = Posicion::create([
                    'camara_id' => $camara->id, 'banda' => $banda, 'posicion' => $posicion, 'nivel' => 1,
                    'etiqueta' => sprintf('B%02d-P%02d-N1', $banda, $posicion),
                ]);
            }
        }

        return $this->camaras[$codigo] = $camara->refresh();
    }

    private function carga(string $codigo, Camara $objetivo): Carga
    {
        return Carga::create([
            'temporada_id' => $this->temporada->id, 'codigo' => $codigo, 'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Urgente, 'version' => 1, 'camara_objetivo_id' => $objetivo->id,
            'creada_por_user_id' => $this->supervisor->id, 'actualizada_por_user_id' => $this->supervisor->id,
            'publicada_por_user_id' => $this->supervisor->id, 'publicada_at' => now(),
        ]);
    }

    private function folio(?Carga $carga): Folio
    {
        $this->folios++;
        $folio = Folio::create([
            'temporada_id' => $this->temporada->id, 'numero_folio' => sprintf('PAL-DES-%03d', $this->folios),
            'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now()->subDay()->addMinutes($this->folios), 'activo' => true,
            'marca' => 'FLA', 'exportadora' => 'LA AGUADA',
        ]);
        if ($carga) {
            $asignacion = CargaFolio::create([
                'carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
                'asignado_por_user_id' => $this->supervisor->id, 'asignado_at' => now()->addSeconds($this->folios),
            ]);
            ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);
        }

        return $folio;
    }

    private function ubicar(Folio $folio, Posicion $posicion): void
    {
        UbicacionActual::withoutEvents(fn (): UbicacionActual => UbicacionActual::create([
            'folio_id' => $folio->id, 'camara_id' => $posicion->camara_id, 'posicion_id' => $posicion->id, 'ubicado_at' => now(),
        ]));
    }

    /** @param  Collection<int, Folio>  $folios */
    private function recepcion($folios, PrioridadOperacional $prioridad = PrioridadOperacional::Normal): PlanOperacional
    {
        return app(ServicioPlanesOperacionales::class)->crear(
            temporada: $this->temporada, tipo: TipoPlanOperacional::RecepcionTunel,
            titulo: 'Recibir túnel 2', creadoPor: $this->supervisor,
            tareas: $folios->map(fn (Folio $folio): array => [
                'folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
            ])->values()->all(), prioridad: $prioridad, contexto: ['planner_horizon' => 'rolling'],
        );
    }

    private function posicion(string $camara, int $banda, int $posicion): Posicion
    {
        return $this->posiciones["{$camara}:{$banda}:{$posicion}"];
    }

    /** @return array{User, Dispositivo} */
    private function camarero(string $sufijo): array
    {
        return [
            User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]),
            Dispositivo::create(['codigo' => 'TABLET-DESTINO-'.$sufijo, 'nombre' => "Tablet {$sufijo}", 'activo' => true]),
        ];
    }
}
