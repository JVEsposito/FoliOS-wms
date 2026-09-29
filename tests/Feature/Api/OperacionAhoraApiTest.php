<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoIncidenciaCarga;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\EstadoTecnicoTunelPrefrio;
use App\Enums\PrioridadCarga;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoIncidenciaCarga;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Jobs\RecalcularArbitrajePlanificador;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\CicloArbitrajeManiobras;
use App\Models\DecisionArbitrajeManiobra;
use App\Models\DiscrepanciaManiobra;
use App\Models\Dispositivo;
use App\Models\EstadoArbitrajePlanificador;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\RegistroControlAmbiental;
use App\Models\SesionEstiba;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Planificador\ServicioEstadoArbitrajePlanificador;
use App\Services\Verificaciones\VentanasVerificacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperacionAhoraApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lectura_guided_no_calcula_ni_persiste_arbitraje(): void
    {
        config([
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
        ]);
        $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $estadosAntes = EstadoArbitrajePlanificador::query()->count();
        $ciclosAntes = CicloArbitrajeManiobras::query()->count();
        $decisionesAntes = DecisionArbitrajeManiobra::query()->count();

        $respuestaPuestoMando = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planificador.arbitraje.activo', true)
            ->assertJsonPath('data.planificador.arbitraje.vigencia.estado', 'pendiente')
            ->assertJsonPath('data.planificador.arbitraje.vigencia.vigente', false)
            ->assertJsonPath('data.planificador.arbitraje.ciclo', null);

        $this->assertSame($estadosAntes, EstadoArbitrajePlanificador::query()->count());
        $this->assertSame($ciclosAntes, CicloArbitrajeManiobras::query()->count());
        $this->assertSame($decisionesAntes, DecisionArbitrajeManiobra::query()->count());
    }

    public function test_requiere_un_perfil_gerencial_y_expone_solo_la_temporada_activa(): void
    {
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        $this->getJson('/api/operacion-ahora')->assertUnauthorized();
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertForbidden();

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.temporada.id', $temporada->id)
            ->assertJsonPath('data.jornada.zona_horaria', 'America/Santiago')
            ->assertJsonPath('data.jornada.turno', app(VentanasVerificacion::class)->actual()['nombre'])
            ->assertJsonPath('data.sincronizacion.estado', 'sin_actividad')
            ->assertJsonPath('data.sincronizacion.ultima_operacion', null)
            ->assertJsonPath('data.actualizacion_sugerida_segundos', 30)
            ->assertJsonCount(0, 'data.camareros')
            ->assertJsonPath('data.prefrio.resumen.tuneles_totales', 0)
            ->assertJsonCount(0, 'data.prefrio.tuneles')
            ->assertJsonPath('data.incidencias.resumen.total_abiertas', 0)
            ->assertJsonPath('data.incidencias.resumen.mas_antigua_at', null)
            ->assertJsonCount(0, 'data.incidencias.abiertas')
            ->assertJsonPath('data.planificador.despliegue.mode_global', 'off')
            ->assertJsonPath('data.planificador.salud.estado', 'saludable')
            ->assertJsonPath('data.planificador.arbitraje.activo', false)
            ->assertJsonPath('data.planificador.arbitraje.ciclo', null)
            ->assertJsonCount(0, 'data.camaras');
    }

    public function test_consolida_ocupacion_control_ambiental_y_evidencia_de_sincronizacion(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00 UTC'));
        $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $camarero = User::factory()->create([
            'name' => 'Camarero operación actual',
            'rol' => RolUsuario::CamareroFrio,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TAB-AHORA-01',
            'nombre' => 'Tablet operación ahora',
        ]);
        $camara = Camara::create([
            'codigo' => 'CAM-AHORA-01',
            'nombre' => 'Cámara operación ahora',
            'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $camara,
            $camarero,
            $dispositivo,
        );
        app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: 'PAL-AHORA-001',
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $camarero,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
        );
        RegistroControlAmbiental::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64),
            'camara_id' => $camara->id,
            'registrado_por_user_id' => $camarero->id,
            'dispositivo_id' => $dispositivo->id,
            'temperatura_inicio_c' => -1.20,
            'temperatura_medio_c' => -1.00,
            'temperatura_fondo_c' => -0.80,
            'capturado_at' => now()->subMinutes(30),
            'recibido_servidor_at' => now()->subMinutes(29),
        ]);
        RegistroControlAmbiental::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64),
            'camara_id' => $camara->id,
            'registrado_por_user_id' => $camarero->id,
            'dispositivo_id' => $dispositivo->id,
            'temperatura_inicio_c' => 9,
            'temperatura_medio_c' => 9,
            'temperatura_fondo_c' => 9,
            'capturado_at' => now()->addMinutes(5),
            'recibido_servidor_at' => now(),
        ]);

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.sincronizacion.estado', 'aceptada')
            ->assertJsonPath('data.sincronizacion.operaciones_hoy.aceptada', 1)
            ->assertJsonPath('data.sincronizacion.ultima_operacion.dispositivo.codigo', 'TAB-AHORA-01')
            ->assertJsonCount(1, 'data.camareros')
            ->assertJsonPath('data.camareros.0.tarea_actual', null)
            ->assertJsonPath('data.camaras.0.codigo', 'CAM-AHORA-01')
            ->assertJsonPath('data.camaras.0.ocupacion_porcentaje', 100)
            ->assertJsonPath('data.camaras.0.nivel_ocupacion', 'critica')
            ->assertJsonPath('data.camaras.0.alertas.0', 'ocupacion_critica')
            ->assertJsonPath('data.camaras.0.control_ambiental.estado', 'vigente')
            ->assertJsonPath('data.camaras.0.control_ambiental.temperaturas_c.promedio', -1);
    }

    public function test_expone_ubicacion_y_tarea_actual_de_cada_camarero_activo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 12:00:00 UTC'));
        $temporada = $this->crearTemporada();
        $temporadaAnterior = Temporada::create([
            'codigo' => 'ANTERIOR',
            'nombre' => 'Temporada anterior',
            'fecha_inicio' => '2025-08-01',
            'fecha_fin' => '2026-03-31',
            'activa' => false,
        ]);
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $camarero = User::factory()->create([
            'name' => 'Camarero activo',
            'rol' => RolUsuario::CamareroFrio,
        ]);
        $camareroCerrado = User::factory()->create([
            'name' => 'Camarero cerrado',
            'rol' => RolUsuario::CamareroFrio,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TAB-ACTIVA-01',
            'nombre' => 'Tablet activa',
        ]);
        $dispositivoCerrado = Dispositivo::create([
            'codigo' => 'TAB-CERRADA-01',
            'nombre' => 'Tablet cerrada',
        ]);
        $camaraSesion = $this->crearCamara('CAM-SESION', 'Cámara de la sesión');
        $camaraOrigen = $this->crearCamara('CAM-ORIGEN', 'Cámara origen');
        $camaraDestino = $this->crearCamara('CAM-DESTINO', 'Cámara destino');
        $camaraCerrada = $this->crearCamara('CAM-CERRADA', 'Cámara cerrada');
        $sesiones = app(ServicioSesionEstiba::class);
        $sesionActiva = $sesiones->abrir($camaraSesion, $camarero, $dispositivo);
        $sesionCerrada = $sesiones->abrir(
            $camaraCerrada,
            $camareroCerrado,
            $dispositivoCerrado,
        );
        $sesiones->cerrar($sesionCerrada, $camareroCerrado);
        $planActual = $this->crearPlan($temporada, $consulta, 'Plan actual');
        $planAnterior = $this->crearPlan($temporadaAnterior, $consulta, 'Plan anterior');

        $this->crearTarea(
            $planActual,
            $temporada,
            $camarero,
            $dispositivo,
            $camaraOrigen,
            $camaraDestino,
            EstadoTareaMovimiento::Asumida,
            PrioridadOperacional::Critica,
            'PAL-ASUMIDO-001',
            now()->subMinutes(20),
        );
        $tareaActual = $this->crearTarea(
            $planActual,
            $temporada,
            $camarero,
            $dispositivo,
            $camaraOrigen,
            $camaraDestino,
            EstadoTareaMovimiento::EnProceso,
            PrioridadOperacional::Normal,
            'PAL-ACTUAL-001',
            now()->subMinutes(5),
        );
        $this->crearTarea(
            $planAnterior,
            $temporadaAnterior,
            $camarero,
            $dispositivo,
            $camaraOrigen,
            $camaraDestino,
            EstadoTareaMovimiento::EnProceso,
            PrioridadOperacional::Critica,
            'PAL-ANTERIOR-001',
            now()->subHour(),
        );

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonCount(1, 'data.camareros')
            ->assertJsonPath('data.camareros.0.usuario.nombre', 'Camarero activo')
            ->assertJsonPath('data.camareros.0.dispositivo.codigo', 'TAB-ACTIVA-01')
            ->assertJsonPath('data.camareros.0.sesion.id', $sesionActiva->id)
            ->assertJsonPath('data.camareros.0.sesion.estado', 'abierta')
            ->assertJsonPath('data.camareros.0.ubicacion_actual.tipo', 'transito')
            ->assertJsonPath('data.camareros.0.ubicacion_actual.camara', null)
            ->assertJsonPath('data.camareros.0.tarea_actual.id', $tareaActual->id)
            ->assertJsonPath('data.camareros.0.tarea_actual.estado', 'en_proceso')
            ->assertJsonPath('data.camareros.0.tarea_actual.prioridad', 'normal')
            ->assertJsonPath('data.camareros.0.tarea_actual.plan.titulo', 'Plan actual')
            ->assertJsonPath('data.camareros.0.tarea_actual.folio.numero_folio', 'PAL-ACTUAL-001')
            ->assertJsonPath('data.camareros.0.tarea_actual.origen.camara.codigo', 'CAM-ORIGEN')
            ->assertJsonPath('data.camareros.0.tarea_actual.destino.camara.codigo', 'CAM-DESTINO');
    }

    public function test_muestra_tarea_asumida_sin_sesion_y_oculta_sesion_inactiva(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 12:00:00 UTC'));
        config(['planificador.camarero_sesion_inactiva_minutos' => 30]);
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $activo = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $inactivo = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $tabletActiva = Dispositivo::create(['codigo' => 'TAB-SIN-SESION', 'nombre' => 'Tablet sin sesión']);
        $tabletInactiva = Dispositivo::create(['codigo' => 'TAB-INACTIVA', 'nombre' => 'Tablet inactiva']);
        $origen = $this->crearCamara('CAM-ORIGEN-02', 'Cámara origen');
        $destino = $this->crearCamara('CAM-DESTINO-02', 'Cámara destino');
        $sesion = app(ServicioSesionEstiba::class)->abrir($origen, $inactivo, $tabletInactiva);
        $sesion->update(['ultima_actividad_at' => now()->subDays(4)]);
        $tarea = $this->crearTarea(
            $this->crearPlan($temporada, $consulta, 'Plan sin sesión'),
            $temporada, $activo, $tabletActiva, $origen, $destino,
            EstadoTareaMovimiento::Asumida, PrioridadOperacional::Alta,
            'PAL-SIN-SESION', now()->subMinutes(3),
        );

        $this->actingAs($consulta, 'sanctum')->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonCount(1, 'data.camareros')
            ->assertJsonPath('data.camareros.0.sesion', null)
            ->assertJsonPath('data.camareros.0.dispositivo.codigo', 'TAB-SIN-SESION')
            ->assertJsonPath('data.camareros.0.ubicacion_actual.tipo', 'origen')
            ->assertJsonPath('data.camareros.0.ubicacion_actual.camara.codigo', 'CAM-ORIGEN-02')
            ->assertJsonPath('data.camareros.0.tarea_actual.id', $tarea->id);
    }

    public function test_consolida_estado_y_avance_temporal_de_prefrio(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 12:00:00 UTC'));
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $tunelProceso = $this->crearTunelPrefrio($consulta, 'TUN-AHORA-01', 4);
        $tunelVerificacion = $this->crearTunelPrefrio($consulta, 'TUN-AHORA-02', 2);
        $this->crearTunelPrefrio($consulta, 'TUN-AHORA-03', 2);
        $this->crearTunelPrefrio(
            $consulta,
            'TUN-AHORA-04',
            2,
            EstadoTecnicoTunelPrefrio::Mantenimiento,
        );
        $proceso = $this->crearProcesoPrefrio(
            $temporada,
            $consulta,
            $tunelProceso,
            'PF-AHORA-001',
            EstadoProcesoPrefrio::EnProceso,
            now()->subMinutes(540),
        );
        $posiciones = $tunelProceso->posiciones()->orderBy('numero')->get();
        $this->cargarFolioPrefrio(
            $temporada,
            $consulta,
            $proceso,
            $posiciones[0],
            'PAL-PF-AHORA-001',
            TipoBulto::Pallet,
            'Pera',
            'Packham',
        );
        $this->cargarFolioPrefrio(
            $temporada,
            $consulta,
            $proceso,
            $posiciones[1],
            'SALDO-PF-AHORA-001',
            TipoBulto::Saldo,
            'Pera',
            'Packham',
        );
        $this->cargarFolioPrefrio(
            $temporada,
            $consulta,
            $proceso,
            $posiciones[1],
            'SALDO-PF-AHORA-002',
            TipoBulto::Saldo,
            'Pera',
            'Packham',
        );
        $pendiente = $this->crearProcesoPrefrio(
            $temporada,
            $consulta,
            $tunelVerificacion,
            'PF-AHORA-002',
            EstadoProcesoPrefrio::PendienteVerificacion,
            now()->subMinutes(600),
            now()->subMinutes(120),
        );
        $this->cargarFolioPrefrio(
            $temporada,
            $consulta,
            $pendiente,
            $tunelVerificacion->posiciones()->firstOrFail(),
            'PAL-PF-AHORA-002',
            TipoBulto::Pallet,
            'Manzana',
            'Gala',
        );

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.prefrio.resumen.tuneles_totales', 4)
            ->assertJsonPath('data.prefrio.resumen.tuneles_operables', 3)
            ->assertJsonPath('data.prefrio.resumen.tuneles_disponibles', 1)
            ->assertJsonPath('data.prefrio.resumen.procesos_activos', 2)
            ->assertJsonPath('data.prefrio.resumen.procesos_fuera_objetivo', 1)
            ->assertJsonPath('data.prefrio.resumen.folios_en_tunel', 4)
            ->assertJsonPath('data.prefrio.resumen.capacidad_operativa', 8)
            ->assertJsonPath('data.prefrio.resumen.posiciones_ocupadas', 3)
            ->assertJsonPath('data.prefrio.resumen.ocupacion_porcentaje', 37.5)
            ->assertJsonPath('data.prefrio.tuneles.0.estado_operacional', 'en_proceso')
            ->assertJsonPath('data.prefrio.tuneles.0.posiciones_ocupadas', 2)
            ->assertJsonPath('data.prefrio.tuneles.0.posiciones_disponibles', 0)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.folios_cargados', 3)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.productos.0.etiqueta', 'Pera Packham')
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.productos.0.folios', 3)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.transcurridos_minutos', 540)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.avance_tiempo_objetivo_porcentaje', 100)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.objetivo_excedido', true)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.minutos_sobre_objetivo', 60)
            ->assertJsonPath('data.prefrio.tuneles.1.estado_operacional', 'pendiente_verificacion')
            ->assertJsonPath('data.prefrio.tuneles.1.proceso_activo.productos.0.etiqueta', 'Manzana Gala')
            ->assertJsonPath('data.prefrio.tuneles.1.proceso_activo.productos.0.folios', 1)
            ->assertJsonPath('data.prefrio.tuneles.1.proceso_activo.transcurridos_minutos', 480)
            ->assertJsonPath('data.prefrio.tuneles.1.proceso_activo.objetivo_excedido', false)
            ->assertJsonPath('data.prefrio.tuneles.2.estado_operacional', 'disponible')
            ->assertJsonPath('data.prefrio.tuneles.2.posiciones_disponibles', 2)
            ->assertJsonPath('data.prefrio.tuneles.3.estado_operacional', 'mantenimiento')
            ->assertJsonPath('data.prefrio.tuneles.3.posiciones_disponibles', 0);
    }

    public function test_consolida_incidencias_abiertas_de_carga_y_maniobra(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-08 12:00:00 UTC'));
        $temporada = $this->crearTemporada();
        $temporadaAnterior = Temporada::create([
            'codigo' => 'ANTERIOR-INC',
            'nombre' => 'Temporada anterior de incidencias',
            'fecha_inicio' => '2025-08-01',
            'fecha_fin' => '2026-03-31',
            'activa' => false,
        ]);
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $reportante = User::factory()->create([
            'name' => 'Camarero que reporta',
            'rol' => RolUsuario::CamareroFrio,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TAB-INC-01',
            'nombre' => 'Tablet incidencias',
        ]);
        $camara = $this->crearCamara('CAM-INC-01', 'Cámara incidencias');
        $sesion = app(ServicioSesionEstiba::class)->abrir(
            $camara,
            $reportante,
            $dispositivo,
        );
        $reportadaCargaAt = now()->subMinutes(30);
        $reportadaManiobraAt = now()->subMinutes(10);
        $incidenciaCarga = $this->crearIncidenciaCarga(
            $temporada,
            $reportante,
            $dispositivo,
            $sesion,
            EstadoIncidenciaCarga::Abierta,
            $reportadaCargaAt,
            'ACTIVA',
        );
        $discrepancia = $this->crearDiscrepanciaManiobra(
            $temporada,
            $reportante,
            $dispositivo,
            $camara,
            EstadoDiscrepanciaManiobra::Abierta,
            $reportadaManiobraAt,
            'ACTIVA',
        );
        $this->crearIncidenciaCarga(
            $temporada,
            $reportante,
            $dispositivo,
            $sesion,
            EstadoIncidenciaCarga::Resuelta,
            now()->subMinutes(2),
            'RESUELTA',
        );
        $this->crearDiscrepanciaManiobra(
            $temporadaAnterior,
            $reportante,
            $dispositivo,
            $camara,
            EstadoDiscrepanciaManiobra::Abierta,
            now()->subMinutes(5),
            'ANTERIOR',
        );

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.incidencias.resumen.total_abiertas', 2)
            ->assertJsonPath('data.incidencias.resumen.carga', 1)
            ->assertJsonPath('data.incidencias.resumen.maniobra', 1)
            ->assertJsonPath('data.incidencias.resumen.mas_antigua_at', $reportadaCargaAt->toAtomString())
            ->assertJsonPath('data.incidencias.resumen.antiguedad_maxima_minutos', 30)
            ->assertJsonCount(2, 'data.incidencias.abiertas')
            ->assertJsonPath('data.incidencias.abiertas.0.id', $discrepancia->id)
            ->assertJsonPath('data.incidencias.abiertas.0.origen', 'maniobra')
            ->assertJsonPath('data.incidencias.abiertas.0.estado', 'abierta')
            ->assertJsonPath('data.incidencias.abiertas.0.prioridad', 'critica')
            ->assertJsonPath('data.incidencias.abiertas.0.folio.numero_folio', 'PAL-MAN-ACTIVA')
            ->assertJsonPath('data.incidencias.abiertas.0.contexto.plan.titulo', 'Plan ACTIVA')
            ->assertJsonPath('data.incidencias.abiertas.0.contexto.maniobra.estado', 'pausada_discrepancia')
            ->assertJsonPath('data.incidencias.abiertas.0.contexto.tarea.origen.camara.codigo', 'CAM-INC-01')
            ->assertJsonPath('data.incidencias.abiertas.0.contexto.tarea.destino.camara.codigo', 'CAM-INC-01')
            ->assertJsonPath('data.incidencias.abiertas.0.antiguedad_minutos', 10)
            ->assertJsonPath('data.incidencias.abiertas.1.id', $incidenciaCarga->id)
            ->assertJsonPath('data.incidencias.abiertas.1.origen', 'carga')
            ->assertJsonPath('data.incidencias.abiertas.1.tipo', 'pallet_inestable')
            ->assertJsonPath('data.incidencias.abiertas.1.prioridad', 'urgente')
            ->assertJsonPath('data.incidencias.abiertas.1.contexto.carga.codigo', 'CAR-INC-ACTIVA')
            ->assertJsonPath('data.incidencias.abiertas.1.contexto.ubicacion_reportada.camara.codigo', 'CAM-INC-01')
            ->assertJsonPath('data.incidencias.abiertas.1.reportado_por.nombre', 'Camarero que reporta')
            ->assertJsonPath('data.incidencias.abiertas.1.dispositivo.codigo', 'TAB-INC-01')
            ->assertJsonPath('data.incidencias.abiertas.1.antiguedad_minutos', 30);
    }

    public function test_expone_el_puesto_de_mando_con_el_ciclo_y_movimiento_vigentes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00:00 UTC'));
        config([
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
            'planificador.generacion_automatica' => true,
            'planificador.rollout_camaras' => ['CAM-MANDO-01'],
            'planificador.frontier_max' => 4,
            'planificador.maniobras_simultaneas_max' => 3,
        ]);
        $temporada = $this->crearTemporada();
        $consulta = User::factory()->create([
            'name' => 'Supervisión puesto de mando',
            'rol' => RolUsuario::Consulta,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TAB-MANDO-01',
            'nombre' => 'Tablet puesto de mando',
        ]);
        $camara = $this->crearCamara('CAM-MANDO-01', 'Cámara puesto de mando');
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'PAL-MANDO-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]);
        $plan = PlanOperacional::create([
            'temporada_id' => $temporada->id,
            'tipo' => TipoPlanOperacional::ConcentracionCarga,
            'estado' => 'en_ejecucion',
            'prioridad' => PrioridadOperacional::Alta,
            'titulo' => 'Concentrar carga de exportación',
            'contexto' => ['planner_horizon' => 'rolling'],
            'creado_por_user_id' => $consulta->id,
            'programado_at' => now(),
            'iniciado_at' => now(),
        ]);
        $maniobra = ManiobraOperacional::create([
            'plan_operacional_id' => $plan->id,
            'creado_por_user_id' => $consulta->id,
            'estado' => EstadoManiobraOperacional::Pendiente,
            'prioridad' => PrioridadOperacional::Alta,
            'candidate_key' => 'puesto-mando-001',
            'titulo' => 'Llevar pallet a cámara de despacho',
            'secuencia_actual' => 1,
            'costo_movimientos' => 1,
            'beneficio_estimado' => 500,
            'riesgo_operacional' => 20,
            'responsable_user_id' => $consulta->id,
            'dispositivo_id' => $dispositivo->id,
        ]);
        TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'maniobra_operacional_id' => $maniobra->id,
            'secuencia' => 1,
            'secuencia_maniobra' => 1,
            'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
            'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
            'estado' => EstadoTareaMovimiento::Pendiente,
            'prioridad' => PrioridadOperacional::Alta,
            'folio_id' => $folio->id,
            'camara_destino_id' => $camara->id,
            'posicion_destino_id' => $camara->posiciones()->sole()->id,
            'instruccion' => 'Ubicar el pallet en cámara de despacho',
        ]);
        app(ServicioEstadoArbitrajePlanificador::class)
            ->solicitar($temporada, 'prueba_puesto_mando');
        RecalcularArbitrajePlanificador::dispatchSync($temporada->id);

        $respuestaPuestoMando = $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planificador.despliegue.mode_global', 'guided')
            ->assertJsonPath('data.planificador.despliegue.rollout_limitado', true)
            ->assertJsonPath('data.planificador.salud.estado', 'saludable')
            ->assertJsonPath('data.planificador.arbitraje.activo', true)
            ->assertJsonPath('data.planificador.arbitraje.vigencia.estado', 'actual')
            ->assertJsonPath('data.planificador.arbitraje.vigencia.vigente', true)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.capacidad_ejecucion', 3)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.cupos_ocupados', 0)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.frontera_max', 4)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.resumen.en_curso', 0)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.resumen.pausadas', 0)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.resumen.seleccionada', 1)
            ->assertJsonCount(1, 'data.planificador.arbitraje.ciclo.decisiones')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.maniobra_id', $maniobra->id)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.decision', 'seleccionada')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.beneficio_neto', 479)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.progreso.pasos_total', 1)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.paso_actual.folio.numero_folio', 'PAL-MANDO-001')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.paso_actual.destino.codigo', 'CAM-MANDO-01')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.paso_actual.destino.nombre', 'Cámara puesto de mando')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.paso_actual.destino.posicion.etiqueta', 'CAM-MANDO-01-B01-P01-N1')
            ->assertJsonCount(1, 'data.planificador.arbitraje.ciclo.decisiones.0.pasos')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.pasos.0.destino.nombre', 'Cámara puesto de mando')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.responsable.nombre', 'Supervisión puesto de mando')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.dispositivo.codigo', 'TAB-MANDO-01')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.version', 1)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.factor_decisivo.codigo', 'cupo_disponible')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.factor_decisivo.etiqueta', 'Cupo de ejecución disponible')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.componentes.prioridad.valor', 'alta')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.componentes.prioridad.peso', 20)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.componentes.objetivo.titulo', 'Concentrar carga de exportación')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.componentes.beneficio.neto', 479)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.capacidad.capacidad_ejecucion', 3)
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.snapshot.maniobra.titulo', 'Llevar pallet a cámara de despacho')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.snapshot.pasos.0.folio', 'PAL-MANDO-001')
            ->assertJsonPath('data.planificador.arbitraje.ciclo.decisiones.0.explicacion.snapshot.pasos.0.destino', 'Cámara puesto de mando · CAM-MANDO-01-B01-P01-N1');

        $explicacionPublica = $respuestaPuestoMando->json(
            'data.planificador.arbitraje.ciclo.decisiones.0.explicacion',
        );
        $this->assertStringNotContainsString(
            $maniobra->id,
            json_encode($explicacionPublica, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            $camara->id,
            json_encode($explicacionPublica, JSON_THROW_ON_ERROR),
        );

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planificador.arbitraje.vigencia.estado', 'actual');

        $this->assertDatabaseCount('ciclos_arbitraje_maniobras', 1);
        $this->assertDatabaseCount('decisiones_arbitraje_maniobras', 1);
    }

    private function crearCamara(string $codigo, string $nombre): Camara
    {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => $codigo.'-B01-P01-N1',
        ]);

        return $camara;
    }

    private function crearIncidenciaCarga(
        Temporada $temporada,
        User $reportante,
        Dispositivo $dispositivo,
        SesionEstiba $sesion,
        EstadoIncidenciaCarga $estado,
        CarbonInterface $reportadaAt,
        string $sufijo,
    ): IncidenciaCargaFolio {
        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => "CAR-INC-{$sufijo}",
            'numero_orden_externa' => "ORD-INC-{$sufijo}",
            'estado' => EstadoCarga::EnPreparacion,
            'prioridad' => PrioridadCarga::Urgente,
            'creada_por_user_id' => $reportante->id,
            'actualizada_por_user_id' => $reportante->id,
        ]);
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => "PAL-CAR-{$sufijo}",
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]);
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'estado' => EstadoCargaFolio::ConIncidencia,
            'asignado_por_user_id' => $reportante->id,
            'asignado_at' => now(),
        ]);

        return IncidenciaCargaFolio::create([
            'operacion_reporte_id' => (string) Str::uuid(),
            'reporte_payload_hash' => hash('sha256', "incidencia-carga-{$sufijo}"),
            'carga_folio_id' => $asignacion->id,
            'tipo' => TipoIncidenciaCarga::PalletInestable,
            'descripcion' => "Pallet inestable {$sufijo}",
            'estado' => $estado,
            'camara_id' => $sesion->camara_id,
            'posicion_id' => $sesion->camara->posiciones()->firstOrFail()->id,
            'reportado_por_user_id' => $reportante->id,
            'dispositivo_id' => $dispositivo->id,
            'sesion_estiba_id' => $sesion->id,
            'reportada_at' => $reportadaAt,
        ]);
    }

    private function crearDiscrepanciaManiobra(
        Temporada $temporada,
        User $reportante,
        Dispositivo $dispositivo,
        Camara $camara,
        EstadoDiscrepanciaManiobra $estado,
        CarbonInterface $reportadaAt,
        string $sufijo,
    ): DiscrepanciaManiobra {
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => "PAL-MAN-{$sufijo}",
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]);
        $plan = $this->crearPlan($temporada, $reportante, "Plan {$sufijo}");
        $maniobra = ManiobraOperacional::create([
            'plan_operacional_id' => $plan->id,
            'creado_por_user_id' => $reportante->id,
            'estado' => EstadoManiobraOperacional::PausadaDiscrepancia,
            'prioridad' => PrioridadOperacional::Critica,
            'candidate_key' => "maniobra-incidencia-{$sufijo}",
            'titulo' => "Maniobra {$sufijo}",
            'costo_movimientos' => 1,
        ]);
        $posicion = $camara->posiciones()->firstOrFail();
        $tarea = TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'maniobra_operacional_id' => $maniobra->id,
            'secuencia' => 1,
            'secuencia_maniobra' => 1,
            'tipo_movimiento' => TipoMovimiento::Reubicacion,
            'tipo_paso_maniobra' => TipoPasoManiobra::MovimientoPermanente,
            'estado' => EstadoTareaMovimiento::EnProceso,
            'prioridad' => PrioridadOperacional::Critica,
            'folio_id' => $folio->id,
            'camara_origen_id' => $camara->id,
            'posicion_origen_id' => $posicion->id,
            'camara_destino_id' => $camara->id,
            'posicion_destino_id' => $posicion->id,
        ]);

        return DiscrepanciaManiobra::create([
            'maniobra_operacional_id' => $maniobra->id,
            'tarea_movimiento_id' => $tarea->id,
            'folio_id' => $folio->id,
            'tipo' => 'posicion_no_coincide',
            'detalle' => "La posición no coincide {$sufijo}",
            'estado' => $estado,
            'reportada_por_user_id' => $reportante->id,
            'dispositivo_id' => $dispositivo->id,
            'reportada_at' => $reportadaAt,
        ]);
    }

    private function crearTunelPrefrio(
        User $creador,
        string $codigo,
        int $capacidad,
        EstadoTecnicoTunelPrefrio $estadoTecnico = EstadoTecnicoTunelPrefrio::Operativo,
    ): TunelPrefrio {
        $tunel = TunelPrefrio::create([
            'codigo' => $codigo,
            'nombre' => 'Túnel '.$codigo,
            'capacidad_posiciones' => $capacidad,
            'setpoint_habitual' => -1.5,
            'estado_tecnico' => $estadoTecnico,
            'creado_por_user_id' => $creador->id,
        ]);

        foreach (range(1, $capacidad) as $numero) {
            PosicionTunelPrefrio::create([
                'tunel_prefrio_id' => $tunel->id,
                'numero' => $numero,
                'etiqueta' => $codigo.'-P'.str_pad((string) $numero, 2, '0', STR_PAD_LEFT),
                'activa' => true,
            ]);
        }

        return $tunel;
    }

    private function crearProcesoPrefrio(
        Temporada $temporada,
        User $creador,
        TunelPrefrio $tunel,
        string $codigo,
        EstadoProcesoPrefrio $estado,
        CarbonInterface $iniciadoAt,
        ?CarbonInterface $pendienteVerificacionAt = null,
    ): ProcesoPrefrio {
        return ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => $codigo,
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $codigo),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => $estado,
            'setpoint' => -1.5,
            'duracion_objetivo_minutos' => 480,
            'formato_referencia' => 'Granel 5 kg',
            'creado_por_user_id' => $creador->id,
            'iniciado_por_user_id' => $creador->id,
            'iniciado_at' => $iniciadoAt,
            'pendiente_verificacion_at' => $pendienteVerificacionAt,
        ]);
    }

    private function cargarFolioPrefrio(
        Temporada $temporada,
        User $usuario,
        ProcesoPrefrio $proceso,
        PosicionTunelPrefrio $posicion,
        string $numeroFolio,
        TipoBulto $tipoBulto = TipoBulto::Pallet,
        ?string $especie = null,
        ?string $variedad = null,
    ): void {
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numeroFolio,
            'tipo_bulto' => $tipoBulto,
            'fecha_ingreso' => now(),
            'variedad' => $variedad,
            'datos_externos' => $especie ? ['especie' => $especie] : null,
        ]);
        ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'estado' => EstadoFolioProcesoPrefrio::EnProceso,
            'temperatura_inicial' => 8,
            'cargado_at' => $proceso->iniciado_at,
            'cargado_por_user_id' => $usuario->id,
        ]);
    }

    private function crearPlan(Temporada $temporada, User $creador, string $titulo): PlanOperacional
    {
        return PlanOperacional::create([
            'temporada_id' => $temporada->id,
            'tipo' => TipoPlanOperacional::ReordenamientoCamara,
            'estado' => 'en_ejecucion',
            'prioridad' => PrioridadOperacional::Normal,
            'titulo' => $titulo,
            'creado_por_user_id' => $creador->id,
            'programado_at' => now()->subHour(),
            'iniciado_at' => now()->subHour(),
        ]);
    }

    private function crearTarea(
        PlanOperacional $plan,
        Temporada $temporada,
        User $camarero,
        Dispositivo $dispositivo,
        Camara $origen,
        Camara $destino,
        EstadoTareaMovimiento $estado,
        PrioridadOperacional $prioridad,
        string $numeroFolio,
        CarbonInterface $fecha,
    ): TareaMovimiento {
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numeroFolio,
            'tipo_bulto' => TipoBulto::Pallet,
            'fecha_ingreso' => now(),
        ]);

        return TareaMovimiento::create([
            'plan_operacional_id' => $plan->id,
            'secuencia' => ((int) $plan->tareas()->max('secuencia')) + 1,
            'tipo_movimiento' => TipoMovimiento::TrasladoEntreCamaras,
            'estado' => $estado,
            'prioridad' => $prioridad,
            'folio_id' => $folio->id,
            'camara_origen_id' => $origen->id,
            'posicion_origen_id' => $origen->posiciones()->firstOrFail()->id,
            'camara_destino_id' => $destino->id,
            'posicion_destino_id' => $destino->posiciones()->firstOrFail()->id,
            'responsable_user_id' => $camarero->id,
            'dispositivo_id' => $dispositivo->id,
            'instruccion' => 'Mover '.$numeroFolio,
            'asumida_at' => $fecha,
            'iniciada_at' => $estado === EstadoTareaMovimiento::EnProceso ? $fecha : null,
        ]);
    }

    private function crearTemporada(): Temporada
    {
        return $this->crearTemporadaActivaPrueba([
            'codigo' => 'ACTUAL',
            'nombre' => 'Temporada actual',
            'fecha_inicio' => '2026-08-01',
            'fecha_fin' => '2027-03-31',
            'activa' => true,
        ]);
    }
}
