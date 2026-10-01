<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPlanOperacional;
use App\Enums\EstadoRetencionOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\FuenteHabilitacionAlmacenamiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ModoBandaOperacional;
use App\Enums\PrioridadCarga;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\RetencionOperacionalFolio;
use App\Models\SesionEstiba;
use App\Models\Temporada;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Camaras\ServicioDesocupacionProgramada;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Planificador\ServicioRecalculosPendientesPlanificador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DesocupacionProgramadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'planificador.mode' => 'guided',
            'planificador.generacion_automatica' => true,
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.frontier_max' => 4,
        ]);
    }

    public function test_recomienda_primero_la_camara_elegible_de_menor_costo(): void
    {
        $contexto = $this->crearContexto();
        $otra = $this->crearCamara($contexto['supervisor'], 'CAM-258-B', 3);
        $contexto['destino']->bandasOperacionales()->update([
            'modo' => ModoBandaOperacional::Bloqueada,
            'motivo_estado' => 'Fuera de la recomendación de prueba.',
        ]);
        $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-A1');
        $this->ubicar($contexto, $otra, 1, 'PAL-258-B1');
        $this->ubicar($contexto, $otra, 2, 'PAL-258-B2');

        $respuesta = $this->actingAs($contexto['supervisor'], 'sanctum')
            ->getJson('/api/desocupaciones-camara/candidatas')
            ->assertOk();

        $this->assertSame($contexto['origen']->id, $respuesta->json('data.0.camara.id'));
        $this->assertSame(1, $respuesta->json('data.0.costo_movimientos_estimado'));
        $this->assertTrue($respuesta->json('data.0.elegible'));
    }

    public function test_inicio_bloquea_ingresos_y_publica_solo_el_pallet_accesible(): void
    {
        $contexto = $this->crearContexto();
        $interior = $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-INTERIOR');
        $exterior = $this->ubicar($contexto, $contexto['origen'], 2, 'PAL-258-EXTERIOR');

        $respuesta = $this->actingAs($contexto['supervisor'], 'sanctum')
            ->postJson("/api/desocupaciones-camara/{$contexto['origen']->id}", [
                'motivo' => 'Apagado programado por mantención.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipo', TipoPlanOperacional::DesocupacionCamara->value)
            ->assertJsonPath('data.prioridad', PrioridadOperacional::Alta->value)
            ->assertJsonPath('data.contexto.estado_desocupacion', 'publicada')
            ->assertJsonPath('data.contexto.pallets_restantes', 2)
            ->assertJsonPath('data.contexto.porcentaje_actual', 0);

        $this->assertSame(
            ModoBandaOperacional::EnVaciado,
            $contexto['origen']->bandasOperacionales()->sole()->modo,
        );
        $planId = $respuesta->json('data.id');
        $tarea = $exterior->tareasMovimiento()->where('plan_operacional_id', $planId)->sole();
        $this->assertSame($exterior->id, $tarea->folio_id);
        $this->assertNotSame($interior->id, $tarea->folio_id);
        $this->assertSame($contexto['destino']->id, $tarea->camara_destino_id);
        $this->assertSame(TipoPasoManiobra::MovimientoPermanente, $tarea->tipo_paso_maniobra);
        $this->assertTrue($tarea->contexto['destino_precalculado_inmutable']);
    }

    public function test_recalcula_tras_cada_movimiento_y_completa_solo_al_cien_por_ciento(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-ROLLING-1');
        $this->ubicar($contexto, $contexto['origen'], 2, 'PAL-258-ROLLING-2');
        $plan = app(ServicioDesocupacionProgramada::class)->iniciar(
            $contexto['origen'],
            $contexto['supervisor'],
            'Vaciado rolling completo.',
        );
        [$operador, $dispositivo] = $this->crearOperador();
        $sesionOrigen = app(ServicioSesionEstiba::class)->abrir(
            $contexto['origen'],
            $operador,
            $dispositivo,
        );
        $sesionDestino = app(ServicioSesionEstiba::class)->abrir(
            $contexto['destino'],
            $operador,
            $dispositivo,
        );

        $this->ejecutarSiguiente($plan, $operador, $dispositivo, $sesionOrigen, $sesionDestino);
        $this->assertSame(EstadoPlanOperacional::EnEjecucion, $plan->refresh()->estado);
        $this->assertSame(50, $plan->contexto['porcentaje_actual']);
        $this->assertSame(1, $plan->contexto['pallets_restantes']);

        $this->ejecutarSiguiente($plan, $operador, $dispositivo, $sesionOrigen, $sesionDestino);
        $this->assertSame(EstadoPlanOperacional::Completado, $plan->refresh()->estado);
        $this->assertSame(100, $plan->contexto['porcentaje_actual']);
        $this->assertSame(0, $plan->contexto['pallets_restantes']);
        $this->assertTrue($plan->contexto['lista_para_apagar']);
        $this->actingAs($contexto['supervisor'], 'sanctum')
            ->getJson("/api/camaras/{$contexto['origen']->id}/plano")
            ->assertOk()
            ->assertJsonPath('data.desocupacion.estado', 'completada')
            ->assertJsonPath('data.desocupacion.activa', false)
            ->assertJsonPath('data.desocupacion.pallets_evacuados', 2)
            ->assertJsonPath('data.desocupacion.pallets_restantes', 0)
            ->assertJsonPath('data.desocupacion.lista_para_apagar', true);
        $this->assertSame(2, $plan->maniobras()->count());
        $this->assertSame(2, $plan->maniobras()
            ->where('estado', EstadoManiobraOperacional::Completada->value)
            ->count());
        $this->assertSame(
            ModoBandaOperacional::EnVaciado,
            $contexto['origen']->bandasOperacionales()->sole()->modo,
        );
    }

    #[DataProvider('cierresDeCiclo')]
    public function test_nuevo_ciclo_conserva_el_historial_y_opera_solo_el_plan_vigente(string $cierre): void
    {
        $this->freezeTime();
        $contexto = $this->crearContexto();
        $camara = $contexto['origen'];
        $usuario = $contexto['supervisor'];
        $servicio = app(ServicioDesocupacionProgramada::class);
        $this->ubicar($contexto, $camara, 1, 'PAL-263-DESOCUPACION-1');
        $anterior = $servicio->iniciar($camara, $usuario, 'Primera mantención.');

        if ($cierre === 'completado') {
            [$operador, $dispositivo] = $this->crearOperador();
            $sesiones = app(ServicioSesionEstiba::class);
            $origen = $sesiones->abrir($camara, $operador, $dispositivo);
            $destino = $sesiones->abrir($contexto['destino'], $operador, $dispositivo);
            $this->ejecutarSiguiente($anterior, $operador, $dispositivo, $origen, $destino);
            $sesiones->cerrar($origen, $operador);
            $sesiones->cerrar($destino, $operador);
            $banda = $camara->bandasOperacionales()->sole();
            app(ServicioBandasOperacionales::class)->configurar($camara, $banda, [
                'modo' => 'operativa',
                'usos_permitidos' => $banda->usos_permitidos,
                'version' => $banda->version,
            ], $usuario);
            $this->ubicar($contexto, $camara, 1, 'PAL-263-DESOCUPACION-2');
        } else {
            $servicio->cancelar($camara, $usuario, 'Mantención postergada.');
        }

        $this->assertSame($cierre, $anterior->refresh()->estado->value);
        $historial = $anterior->getAttributes();
        $tareas = $anterior->tareas()->get()->map->getAttributes()->all();
        $maniobras = $anterior->maniobras()->get()->map->getAttributes()->all();
        $movimientos = $anterior->movimientos()->pluck('id')->all();
        $nuevo = $servicio->iniciar($camara, $usuario, 'Segunda mantención.');

        $this->assertNotSame($anterior->id, $nuevo->id);
        $this->assertSame(1, $anterior->ciclo_referencia);
        $this->assertSame(2, $nuevo->ciclo_referencia);
        $this->assertSame('Segunda mantención.', $nuevo->motivo);
        $this->assertSame($nuevo->id, $servicio->iniciar($camara, $usuario, 'Aviso repetido.')->id);
        $this->assertSame($nuevo->id, $servicio->sincronizar($camara, $usuario)->id);
        $this->assertSame(2, PlanOperacional::query()
            ->where('referencia_tipo', ServicioDesocupacionProgramada::REFERENCIA)
            ->where('referencia_id', $camara->id)->count());
        $this->assertSame(1, $nuevo->tareas()->count());
        $this->assertSame(1, $nuevo->maniobras()->count());
        $this->assertSame(0, $nuevo->movimientos()->count());

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/desocupaciones-camara/{$camara->id}/cancelar", [
                'motivo' => 'Cancelar únicamente la segunda mantención.',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $nuevo->id)
            ->assertJsonPath('data.estado', 'cancelado');
        $this->assertSame($historial, $anterior->refresh()->getAttributes());
        $this->assertSame($tareas, $anterior->tareas()->get()->map->getAttributes()->all());
        $this->assertSame($maniobras, $anterior->maniobras()->get()->map->getAttributes()->all());
        $this->assertSame($movimientos, $anterior->movimientos()->pluck('id')->all());
    }

    public static function cierresDeCiclo(): array
    {
        return [
            'tras completar' => ['completado'],
            'tras cancelar' => ['cancelado'],
        ];
    }

    public function test_sin_destino_compatible_conserva_el_objetivo_sin_publicar_trabajo_incompleto(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-SIN-DESTINO');
        $contexto['destino']->bandasOperacionales()->sole()->update([
            'usos_permitidos' => [UsoBandaOperacional::Inspeccion->value],
        ]);

        $plan = app(ServicioDesocupacionProgramada::class)->iniciar(
            $contexto['origen'],
            $contexto['supervisor'],
            'Esperar capacidad compatible.',
        );

        $this->assertSame('pendiente', $plan->contexto['estado_desocupacion']);
        $this->assertSame('sin_destino_compatible', $plan->contexto['motivo_pendiente']);
        $this->assertSame(0, $plan->tareas()->count());
        $this->assertSame(1, $plan->contexto['pallets_restantes']);
    }

    public function test_preserva_la_carga_prefiriendo_una_banda_con_pallets_de_la_misma_carga(): void
    {
        $contexto = $this->crearContexto();
        $destinoCarga = $this->crearCamara($contexto['supervisor'], 'ZZ-CAM-258-CARGA', 3);
        $objetivo = $this->ubicar(
            $contexto,
            $contexto['origen'],
            1,
            'PAL-258-CARGA-1',
            'Cliente carga',
        );
        $companero = $this->ubicar(
            $contexto,
            $destinoCarga,
            1,
            'PAL-258-CARGA-2',
            'Cliente carga',
        );
        $this->ubicar(
            $contexto,
            $contexto['destino'],
            1,
            'PAL-258-AFINIDAD-1',
            'Cliente carga',
        );
        $this->ubicar(
            $contexto,
            $contexto['destino'],
            2,
            'PAL-258-AFINIDAD-2',
            'Cliente carga',
        );
        $carga = Carga::create([
            'temporada_id' => $contexto['temporada']->id,
            'codigo' => 'CAR-258-000001',
            'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Alta,
            'version' => 1,
            'creada_por_user_id' => $contexto['supervisor']->id,
            'actualizada_por_user_id' => $contexto['supervisor']->id,
            'publicada_por_user_id' => $contexto['supervisor']->id,
            'publicada_at' => now(),
        ]);
        foreach ([$objetivo, $companero] as $folio) {
            $asignacion = CargaFolio::create([
                'carga_id' => $carga->id,
                'folio_id' => $folio->id,
                'estado' => EstadoCargaFolio::Pendiente,
                'asignado_por_user_id' => $contexto['supervisor']->id,
                'asignado_at' => now(),
            ]);
            ReservaCargaFolio::create([
                'folio_id' => $folio->id,
                'carga_folio_id' => $asignacion->id,
            ]);
        }

        $plan = app(ServicioDesocupacionProgramada::class)->iniciar(
            $contexto['origen'],
            $contexto['supervisor'],
            'Preservar la carga durante el vaciado.',
        );
        $tarea = $plan->tareas()->sole();

        $this->assertSame($destinoCarga->id, $tarea->camara_destino_id);
        $this->assertSame($carga->id, $tarea->contexto['carga_id']);
        $this->assertSame($carga->id, $plan->contexto['siguiente']['carga_id']);
    }

    public function test_retencion_expuesta_mantiene_prioridad_critica_y_no_se_duplica(): void
    {
        $contexto = $this->crearContexto();
        $contexto['destino']->bandasOperacionales()->sole()->update([
            'usos_permitidos' => [UsoBandaOperacional::Retenidos->value],
        ]);
        $retenido = $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-RETENIDO');
        $retenido->update([
            'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Retenido,
        ]);
        RetencionOperacionalFolio::create([
            'folio_id' => $retenido->id,
            'bloqueo_folio_id' => $retenido->id,
            'estado' => EstadoRetencionOperacional::Activa,
            'motivo' => 'Retención de calidad prioritaria.',
            'estado_operacional_anterior' => EstadoOperacionalFolio::Disponible,
            'condicion_termica_anterior' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento_anterior' => HabilitacionAlmacenamientoFolio::Habilitado,
            'retenido_por_user_id' => $contexto['supervisor']->id,
            'retenido_at' => now(),
        ]);

        $plan = app(ServicioDesocupacionProgramada::class)->iniciar(
            $contexto['origen'],
            $contexto['supervisor'],
            'Vaciado con retención preservada.',
        );
        $tareaRetencion = $retenido->tareasMovimiento()->sole();

        $this->assertSame(PrioridadOperacional::Critica, $tareaRetencion->prioridad);
        $this->assertNotSame($plan->id, $tareaRetencion->plan_operacional_id);
        $this->assertSame('retencion_prioritaria', $plan->contexto['motivo_pendiente']);
        $this->assertSame(1, $plan->contexto['pendientes_retencion']);
        $this->assertSame(0, $plan->tareas()->count());
    }

    public function test_cancelacion_reversible_reabre_bandas_sin_deshacer_movimientos_confirmados(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-CANCELAR-1');
        $this->ubicar($contexto, $contexto['origen'], 2, 'PAL-258-CANCELAR-2');
        $plan = app(ServicioDesocupacionProgramada::class)->iniciar(
            $contexto['origen'],
            $contexto['supervisor'],
            'Vaciado que luego será cancelado.',
        );
        [$operador, $dispositivo] = $this->crearOperador();
        $sesionOrigen = app(ServicioSesionEstiba::class)->abrir(
            $contexto['origen'],
            $operador,
            $dispositivo,
        );
        $sesionDestino = app(ServicioSesionEstiba::class)->abrir(
            $contexto['destino'],
            $operador,
            $dispositivo,
        );
        $this->ejecutarSiguiente($plan, $operador, $dispositivo, $sesionOrigen, $sesionDestino);

        $this->actingAs($contexto['supervisor'], 'sanctum')
            ->postJson("/api/desocupaciones-camara/{$contexto['origen']->id}/cancelar", [
                'motivo' => 'Mantención reprogramada por operaciones.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoPlanOperacional::Cancelado->value)
            ->assertJsonPath('data.contexto.movimientos_completados', 1)
            ->assertJsonPath('data.contexto.bandas_restauradas', 1);

        $this->assertSame(1, UbicacionActual::query()
            ->where('camara_id', $contexto['destino']->id)
            ->count());
        $this->assertSame(
            ModoBandaOperacional::Operativa,
            $contexto['origen']->bandasOperacionales()->sole()->modo,
        );
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $plan->tareas()
            ->where('estado', EstadoTareaMovimiento::Cancelada->value)
            ->sole()
            ->estado);
    }

    public function test_shadow_simula_sin_bloquear_bandas_y_off_no_inicia(): void
    {
        $contexto = $this->crearContexto();
        $this->ubicar($contexto, $contexto['origen'], 1, 'PAL-258-SHADOW');
        config(['planificador.mode' => 'shadow']);

        $plan = app(ServicioDesocupacionProgramada::class)->iniciar(
            $contexto['origen'],
            $contexto['supervisor'],
            'Simulación de vaciado.',
        );

        $this->assertSame('shadow', $plan->contexto['estado_desocupacion']);
        $this->assertSame(0, $plan->tareas()->count());
        $this->assertSame(
            ModoBandaOperacional::Operativa,
            $contexto['origen']->bandasOperacionales()->sole()->modo,
        );

        config(['planificador.mode' => 'off']);
        $otra = $this->crearCamara($contexto['supervisor'], 'CAM-258-OFF', 2);
        $this->actingAs($contexto['supervisor'], 'sanctum')
            ->postJson("/api/desocupaciones-camara/{$otra->id}", [
                'motivo' => 'No debe comenzar en off.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertDatabaseMissing('planes_operacionales', [
            'referencia_tipo' => ServicioDesocupacionProgramada::REFERENCIA,
            'referencia_id' => $otra->id,
        ]);
    }

    public function test_plano_expone_el_ultimo_ciclo_y_su_avance_sin_recalcular(): void
    {
        $contexto = $this->crearContexto();
        $camara = $contexto['origen'];
        $this->ubicar($contexto, $camara, 1, 'PAL-PLANO');
        $this->actingAs($contexto['supervisor'], 'sanctum');
        $url = "/api/camaras/{$camara->id}/plano";
        $inicial = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.desocupacion', null)
            ->assertJsonPath('data.desocupacion_habilitada', true);
        $plan = app(ServicioDesocupacionProgramada::class)->iniciar($camara, $contexto['supervisor'], 'Mantención programada.');
        $respuesta = $this->withHeader('If-None-Match', $inicial->headers->get('ETag'))->getJson($url)->assertOk()
            ->assertJsonPath('data.desocupacion.id', $plan->id)
            ->assertJsonPath('data.desocupacion.activa', true)
            ->assertJsonPath('data.desocupacion.estado', 'publicada')
            ->assertJsonPath('data.desocupacion.pallets_restantes', 1);
        $cantidad = $plan->maniobras()->count();
        $version = $plan->version;
        $this->withHeader('If-None-Match', $respuesta->headers->get('ETag'))->getJson($url)->assertStatus(304);
        $this->assertSame($cantidad, $plan->maniobras()->count());
        $this->assertSame($version, $plan->refresh()->version);

        // El avance del plan invalida el ETag aun sin cambiar el plano físico.
        $plan->update(['contexto' => [...$plan->contexto, 'estado_desocupacion' => 'pendiente', 'motivo_pendiente' => 'sin_destino_compatible'], 'version' => $version + 1]);
        $pendiente = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.desocupacion.motivo_pendiente', 'sin_destino_compatible');
        app(ServicioDesocupacionProgramada::class)->cancelar($camara, $contexto['supervisor'], 'Cancelar mantención.');
        $this->withHeader('If-None-Match', $pendiente->headers->get('ETag'))->getJson($url)->assertOk()
            ->assertJsonPath('data.desocupacion.activa', false)
            ->assertJsonPath('data.desocupacion.estado', 'cancelada');
        $nuevo = app(ServicioDesocupacionProgramada::class)->iniciar($camara, $contexto['supervisor'], 'Nueva mantención.');
        $this->withHeader('If-None-Match', '')->getJson($url)->assertOk()
            ->assertJsonPath('data.desocupacion.id', $nuevo->id);
    }

    public function test_plano_invalida_cache_al_deshabilitar_ejecucion_del_planificador(): void
    {
        $contexto = $this->crearContexto();
        $this->actingAs($contexto['supervisor'], 'sanctum');
        $url = "/api/camaras/{$contexto['origen']->id}/plano";
        $respuesta = $this->getJson($url)->assertOk()->assertJsonPath('data.desocupacion_habilitada', true);
        config(['planificador.compute' => 'server']);
        $this->withHeader('If-None-Match', $respuesta->headers->get('ETag'))->getJson($url)->assertOk()
            ->assertJsonPath('data.desocupacion_habilitada', false);
        $this->assertSame(0, PlanOperacional::query()->count());
    }

    /** @return array<string, mixed> */
    private function crearContexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create([
            'codigo' => 'TEMP-258-'.Str::upper(Str::random(5)),
            'nombre' => 'Temporada desocupación programada',
            'fecha_inicio' => '2026-09-01',
            'activa' => true,
        ]);
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
            'activo' => true,
        ]);
        $origen = $this->crearCamara($supervisor, 'CAM-258-ORIGEN', 3);
        $destino = $this->crearCamara($supervisor, 'CAM-258-DESTINO', 4);

        return compact('temporada', 'supervisor', 'origen', 'destino');
    }

    private function crearCamara(User $usuario, string $codigo, int $profundidad): Camara
    {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => $codigo,
            'contenido' => ContenidoCamara::Productos,
            'estado' => 'activa',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => $profundidad,
            'cantidad_niveles' => 1,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $usuario);
        $camara->bandasOperacionales()->update([
            'usos_permitidos' => [UsoBandaOperacional::TransitoProductoTerminado->value],
        ]);
        for ($posicion = 1; $posicion <= $profundidad; $posicion++) {
            Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $posicion,
                'nivel' => 1,
                'etiqueta' => sprintf('%s-B01-P%02d-N1', $codigo, $posicion),
            ]);
        }

        return $camara;
    }

    private function ubicar(
        array $contexto,
        Camara $camara,
        int $profundidad,
        string $numero,
        string $cliente = 'Cliente común',
    ): Folio {
        $folio = Folio::create([
            'temporada_id' => $contexto['temporada']->id,
            'numero_folio' => $numero,
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fuente_habilitacion_almacenamiento' => FuenteHabilitacionAlmacenamiento::PrefrioAprobado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'exportadora' => $cliente,
            'marca' => 'Marca común',
            'datos_externos' => ['envase' => 'Caja común'],
        ]);
        $posicion = $camara->posiciones()->where('posicion', $profundidad)->sole();
        UbicacionActual::withoutEvents(fn (): UbicacionActual => UbicacionActual::create([
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
            'ubicado_at' => now(),
        ]));

        return $folio;
    }

    /** @return array{User, Dispositivo} */
    private function crearOperador(): array
    {
        return [
            User::factory()->create([
                'rol' => RolUsuario::CamareroFrio,
                'activo' => true,
            ]),
            Dispositivo::create([
                'codigo' => 'TABLET-258-'.Str::upper(Str::random(5)),
                'nombre' => 'Tablet desocupación',
            ]),
        ];
    }

    private function ejecutarSiguiente(
        PlanOperacional $plan,
        User $operador,
        Dispositivo $dispositivo,
        SesionEstiba $sesionOrigen,
        SesionEstiba $sesionDestino,
    ): void {
        $tarea = $plan->tareas()
            ->where('estado', EstadoTareaMovimiento::Pendiente->value)
            ->firstOrFail();
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($tarea, $operador, $dispositivo);
        $planes->iniciar($tarea, $operador, $dispositivo);
        $movimiento = app(ServicioMovimientoEstiba::class)->mover(
            operacionId: (string) Str::uuid(),
            folio: $tarea->folio,
            posicionDestino: $tarea->posicionDestino,
            sesionOrigen: $sesionOrigen,
            sesionDestino: $sesionDestino,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionOrigenConocida: $sesionOrigen->camara()->firstOrFail()->version_plano,
            versionDestinoConocida: $sesionDestino->camara()->firstOrFail()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $tarea->refresh(),
        );
        app(ServicioRecalculosPendientesPlanificador::class)->ejecutar(
            ServicioRecalculosPendientesPlanificador::DESOCUPACION,
            $movimiento->id,
        );
    }
}
