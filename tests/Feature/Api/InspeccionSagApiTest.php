<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoEspacioPreparacionSag;
use App\Exceptions\ConflictoOperacion;
use App\Models\ArticuloValidacion;
use App\Models\BandaOperacional;
use App\Models\BloqueMercado;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\CategoriaValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\CondicionSag;
use App\Models\CsgValidacion;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Dispositivo;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\LoteInspeccionSag;
use App\Models\OrigenValidacion;
use App\Models\Pais;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\TareaMovimiento;
use App\Models\TunelPrefrio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Models\VariedadValidacion;
use App\Services\Camaras\ServicioBandasOperacionales;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Existencias\ServicioExistencias;
use App\Services\Prefrio\ServicioGeneracionRecepcionTunel;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InspeccionSagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_recupera_ingreso_sag_iniciado_con_tunel_historico_y_ubicacion_previa_sin_duplicar_movimientos(): void
    {
        [$usuario, $folio, $origen, $zona, $lote, $tarea] = $this->prepararIngresoSagObsoleto();
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-ORIGEN', 'nombre' => 'Tablet SAG']);
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($tarea, $operador, $dispositivo);
        $planes->iniciar($tarea, $operador, $dispositivo);
        $reserva = $tarea->refresh()->reservaActiva;
        $maniobra = $tarea->maniobraOperacional;
        $movimientos = $folio->movimientos()->count();

        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $this->artisan('sag:preparar-traslados')->assertSuccessful();

        $this->assertSame('cancelada', $tarea->refresh()->estado->value);
        $this->assertSame('cancelada', $maniobra->refresh()->estado->value);
        $this->assertSame('liberada', $reserva->refresh()->estado->value);
        $this->assertSame($origen->id, $folio->refresh()->ubicacionActual->posicion_id);
        $this->assertSame($movimientos, $folio->movimientos()->count());
        $nueva = TareaMovimiento::where('plan_operacional_id', $tarea->plan_operacional_id)->where('estado', 'pendiente')->sole();
        $this->assertSame($nueva->id, $tarea->reemplazada_por_tarea_id);
        $this->assertSame('traslado_entre_camaras', $nueva->tipo_movimiento->value);
        $this->assertSame($origen->id, $nueva->posicion_origen_id);
        $this->assertSame($tarea->posicion_destino_id, $nueva->posicion_destino_id);
        $this->assertSame('camara', $nueva->contexto['origen_logico']);
        $this->assertArrayNotHasKey('tunel_codigo', $nueva->contexto);
        $this->assertSame($tarea->id, $nueva->contexto['tarea_origen_corregido_id']);
        $this->assertSame('en_proceso', $tarea->contexto['origen_sag_corregido']['estado_anterior']);
        $this->assertSame($origen->id, $tarea->contexto['origen_sag_corregido']['posicion_id']);
        $this->assertStringContainsString($origen->camara->nombre.' · '.$origen->etiqueta, $nueva->instruccion);

        $planes->asumir($nueva, $operador, $dispositivo);
        $planes->iniciar($nueva, $operador, $dispositivo);
        $sesionOrigen = app(ServicioSesionEstiba::class)->abrir($origen->camara, $operador, $dispositivo);
        $sesionDestino = app(ServicioSesionEstiba::class)->abrir($zona, $operador, $dispositivo);
        app(ServicioMovimientoEstiba::class)->mover(
            operacionId: (string) Str::uuid(), folio: $folio,
            posicionDestino: $nueva->posicionDestino, sesionOrigen: $sesionOrigen, sesionDestino: $sesionDestino,
            usuario: $operador, dispositivo: $dispositivo,
            versionOrigenConocida: $origen->camara->refresh()->version_plano,
            versionDestinoConocida: $zona->refresh()->version_plano,
            generadoDispositivoAt: now(), tareaMovimiento: $nueva->refresh(),
        );
        $this->assertSame('completada', $nueva->refresh()->estado->value);
        $this->assertSame($movimientos + 1, $folio->movimientos()->count());
        $this->assertSame($origen->id, $folio->movimientos()->latest('recibido_servidor_at')->first()->posicion_origen_id);
        $this->actingAs($usuario, 'sanctum')->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/iniciar")->assertOk();
    }

    public function test_recupera_ingreso_sag_pendiente_ya_ubicado_en_el_sector_sin_otro_movimiento(): void
    {
        [, $folio, , , , $tarea] = $this->prepararIngresoSagObsoleto();
        $folio->ubicacionActual->update([
            'camara_id' => $tarea->camara_destino_id,
            'posicion_id' => $tarea->posicion_destino_id,
        ]);
        $movimientos = $folio->movimientos()->count();

        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $this->artisan('sag:preparar-traslados')->assertSuccessful();

        $this->assertSame('cancelada', $tarea->refresh()->estado->value);
        $this->assertSame(1, TareaMovimiento::count());
        $this->assertSame($movimientos, $folio->movimientos()->count());
        $this->assertSame(1, $tarea->planOperacional->contexto['pallets_preparados']);
    }

    public function test_no_reemplaza_ingreso_sag_iniciado_si_la_ubicacion_es_posterior_a_la_orden(): void
    {
        [, $folio, , , , $tarea] = $this->prepararIngresoSagObsoleto();
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-POSTERIOR', 'nombre' => 'Tablet SAG']);
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $operador, $dispositivo);
        app(ServicioPlanesOperacionales::class)->iniciar($tarea, $operador, $dispositivo);
        $folio->ubicacionActual->update(['ubicado_at' => $tarea->created_at->copy()->addMinute()]);

        $this->artisan('sag:preparar-traslados')->assertSuccessful();

        $this->assertSame('en_proceso', $tarea->refresh()->estado->value);
        $this->assertNotNull($tarea->reservaActiva);
        $this->assertNull($tarea->reemplazada_por_tarea_id);
        $this->assertSame(1, TareaMovimiento::count());
    }

    public function test_no_reemplaza_ingreso_sag_con_custodia_fisica_registrada(): void
    {
        [, $folio, $origen, , , $tarea] = $this->prepararIngresoSagObsoleto();
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-CUSTODIA', 'nombre' => 'Tablet SAG']);
        app(ServicioPlanesOperacionales::class)->asumir($tarea, $operador, $dispositivo);
        app(ServicioPlanesOperacionales::class)->iniciar($tarea, $operador, $dispositivo);
        $custodia = CustodiaTemporalManiobra::create([
            'maniobra_operacional_id' => $tarea->refresh()->maniobra_operacional_id,
            'folio_id' => $folio->id,
            'tarea_extraccion_id' => $tarea->id,
            'camara_origen_id' => $origen->camara_id,
            'posicion_origen_id' => $origen->id,
            'banda_origen' => $origen->banda,
            'posicion_origen' => $origen->posicion,
            'nivel_origen' => $origen->nivel,
            'estado' => 'activa',
            'bloqueo_folio_id' => $folio->id,
            'user_id' => $operador->id,
            'dispositivo_id' => $dispositivo->id,
            'extraido_at' => now(),
        ]);

        $this->artisan('sag:preparar-traslados')->assertSuccessful();

        $this->assertSame('en_proceso', $tarea->refresh()->estado->value);
        $this->assertNotNull($tarea->reservaActiva);
        $this->assertSame('activa', $custodia->refresh()->estado->value);
        $this->assertSame(1, TareaMovimiento::count());
    }

    private function prepararIngresoSagObsoleto(): array
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $zona = $this->crearZonaInspeccion($usuario, posiciones: 2);
        $folio = $this->crearFolioUbicado('1000000004');
        $origen = $folio->ubicacionActual->posicion;
        $this->travel(-7)->days();
        $proceso = $this->crearPrefrioSag($usuario, [$folio], aprobado: true);
        $this->travelBack();
        UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $origen->camara_id,
            'posicion_id' => $origen->id, 'ubicado_at' => now()->subDays(6)]);
        $folio->update(['estado_operacional' => EstadoOperacionalFolio::Disponible]);
        $lote = $this->crearLoteEnPreparacion($usuario, [$folio], Pais::where('iso_alpha2', 'CL')->firstOrFail());
        $tarea = TareaMovimiento::sole();
        $this->assertSame('traslado_entre_camaras', $tarea->tipo_movimiento->value);
        // Estado persistido por el error: el histórico de prefrío se usó como origen vigente.
        $tarea->update(['tipo_movimiento' => 'ubicacion_inicial', 'camara_origen_id' => null, 'posicion_origen_id' => null,
            'instruccion' => 'Llevar 1000000004 desde TUNEL 6 para inspección SAG.',
            'contexto' => [...$tarea->contexto, 'origen_logico' => 'tunel_prefrio', 'proceso_prefrio_id' => $proceso->id, 'tunel_codigo' => 'TUN-06']]);

        return [$usuario, $folio, $origen, $zona, $lote, $tarea->refresh()];
    }

    public function test_preparacion_fisica_reserva_tramo_contiguo_idempotente_con_factor_uno_coma_cinco_en_nivel_uno(): void
    {
        $this->activarPlanificadorDirigido();
        $administrador = $this->administradorConTemporada();
        $folios = collect([
            $this->crearFolioUbicado('SAG-PREP-001'),
            $this->crearFolioUbicado('SAG-PREP-002'),
            $this->crearFolioUbicado('SAG-PREP-003'),
        ]);
        $zona = $this->crearZonaInspeccion($administrador, posiciones: 5, niveles: 2);
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();
        $operacionId = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionId,
            'tipo' => 'inspeccion_origen',
            'folios' => $folios->pluck('id')->all(),
            'destinos' => [['tipo' => 'pais', 'id' => $chile->id]],
        ];

        $primera = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', $payload)
            ->assertCreated()
            ->assertJsonPath('preparacion_fisica.estado', 'programado')
            ->assertJsonPath('preparacion_fisica.capacidad.factor_capacidad', 1.5)
            ->assertJsonPath('preparacion_fisica.capacidad.nivel_reservado', 1)
            ->assertJsonPath('preparacion_fisica.capacidad.espacios_requeridos', 5)
            ->assertJsonPath('preparacion_fisica.capacidad.espacios_pallet', 3)
            ->assertJsonPath('preparacion_fisica.capacidad.espacios_separacion', 2)
            ->assertJsonPath('preparacion_fisica.capacidad.capacidad_estado', 'reservada')
            ->json();

        $segunda = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', $payload)
            ->assertCreated()
            ->json();

        $this->assertSame($primera['id'], $segunda['id']);
        $this->assertSame($primera['preparacion_fisica']['plan_id'], $segunda['preparacion_fisica']['plan_id']);
        $this->assertDatabaseCount('planes_operacionales', 1);
        $this->assertSame(5, ReservaPosicionInspeccionSag::query()->count());
        $this->assertSame(5, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());
        $this->assertSame(3, ReservaPosicionInspeccionSag::query()
            ->where('tipo_espacio', TipoEspacioPreparacionSag::Pallet->value)
            ->count());
        $this->assertSame(2, ReservaPosicionInspeccionSag::query()
            ->where('tipo_espacio', TipoEspacioPreparacionSag::Separacion->value)
            ->count());
        $this->assertSame(0, ReservaPosicionInspeccionSag::query()
            ->whereHas('posicion', fn ($consulta) => $consulta->where('nivel', '>', 1))
            ->count());
        $this->assertSame(
            range(1, 5),
            ReservaPosicionInspeccionSag::query()
                ->with('posicion')
                ->orderBy('orden')
                ->get()
                ->pluck('posicion.posicion')
                ->all(),
        );
        $this->assertSame($zona->id, $primera['preparacion_fisica']['capacidad']['sector']['camara_id']);

        $banda = BandaOperacional::query()
            ->where('camara_id', $zona->id)
            ->where('numero', 1)
            ->firstOrFail();
        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$zona->id}/bandas/{$banda->id}", [
                'usos_permitidos' => ['transito_pt'],
                'modo' => 'operativa',
                'version' => $banda->version,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/configuracion/camaras/{$zona->id}", [
                'nombre' => $zona->nombre,
                'tipo' => 'almacenaje',
                'contenido' => $zona->contenido->value,
                'estado' => 'activa',
                'bandas' => $zona->cantidad_bandas,
                'posiciones_por_banda' => 4,
                'niveles' => $zona->cantidad_niveles,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $reservaPallet = ReservaPosicionInspeccionSag::query()
            ->where('tipo_espacio', TipoEspacioPreparacionSag::Pallet->value)
            ->firstOrFail();
        $reservaSeparacion = ReservaPosicionInspeccionSag::query()
            ->where('tipo_espacio', TipoEspacioPreparacionSag::Separacion->value)
            ->firstOrFail();
        $reservasMovimientos = app(ServicioReservasTareasMovimiento::class);
        $reservasMovimientos->validarDestinoManual(
            $reservaPallet->posicion_id,
            $folios->first()->numero_folio,
        );

        foreach ([
            [$reservaSeparacion->posicion_id, $folios->first()->numero_folio],
            [$reservaPallet->posicion_id, 'FOLIO-AJENO-A-SAG'],
        ] as [$posicionId, $numeroFolio]) {
            try {
                $reservasMovimientos->validarDestinoManual($posicionId, $numeroFolio);
                $this->fail('La reserva SAG debía impedir el uso ajeno de la posición.');
            } catch (ConflictoOperacion $exception) {
                $this->assertStringContainsString('inspección SAG', $exception->getMessage());
            }
        }
    }

    public function test_preparacion_completa_el_objetivo_al_iniciar_y_libera_capacidad_al_finalizar_o_cancelar(): void
    {
        $this->activarPlanificadorDirigido();
        $administrador = $this->administradorConTemporada();
        $this->crearZonaInspeccion($administrador, posiciones: 4);
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();
        $folioFinalizado = $this->crearFolioUbicado('SAG-CIERRE-001');
        $loteFinalizado = $this->crearLoteEnPreparacion(
            $administrador,
            [$folioFinalizado],
            $chile,
        );

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$loteFinalizado['id']}/iniciar")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'La inspección no puede comenzar hasta ubicar todos sus pallets en el sector SAG reservado.',
            );
        $destinoPreparacion = ReservaPosicionInspeccionSag::query()
            ->where('lote_inspeccion_sag_id', $loteFinalizado['id'])
            ->where('tipo_espacio', TipoEspacioPreparacionSag::Pallet->value)
            ->with('posicion')
            ->firstOrFail();
        $folioFinalizado->ubicacionActual()->update([
            'camara_id' => $destinoPreparacion->posicion->camara_id,
            'posicion_id' => $destinoPreparacion->posicion_id,
            'ubicado_at' => now(),
        ]);

        $iniciado = $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$loteFinalizado['id']}/iniciar")
            ->assertOk()
            ->assertJsonPath('preparacion_fisica.estado', 'completado')
            ->assertJsonPath('preparacion_fisica.capacidad.porcentaje_preparado', 100)
            ->json();
        $this->assertSame(2, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());

        $resultadoId = $iniciado['folios'][0]['resultados'][0]['id'];
        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$loteFinalizado['id']}/resultados/{$resultadoId}/resolver", [
                'resultado' => 'aprobado',
            ])
            ->assertOk();
        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$loteFinalizado['id']}/finalizar")
            ->assertOk()
            ->assertJsonPath('preparacion_fisica.estado', 'completado')
            ->assertJsonPath('preparacion_fisica.capacidad.capacidad_estado', 'liberada')
            ->assertJsonPath('preparacion_fisica.capacidad.espacios_reservados', 0);
        $this->assertSame(0, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());
        $this->assertSame(2, ReservaPosicionInspeccionSag::query()->whereNotNull('liberada_at')->count());

        $folioCancelado = $this->crearFolioUbicado('SAG-CIERRE-002');
        $loteCancelado = $this->crearLoteEnPreparacion(
            $administrador,
            [$folioCancelado],
            $chile,
        );
        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$loteCancelado['id']}/cancelar")
            ->assertOk()
            ->assertJsonPath('preparacion_fisica.estado', 'cancelado')
            ->assertJsonPath('preparacion_fisica.capacidad.capacidad_estado', 'liberada');
        $this->assertSame(0, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());
    }

    public function test_falta_de_tramo_no_inventa_ocupacion_ni_impide_crear_el_lote(): void
    {
        $this->activarPlanificadorDirigido();
        $administrador = $this->administradorConTemporada();
        $zona = $this->crearZonaInspeccion($administrador, posiciones: 3);
        $bloqueador = $this->crearFolioUbicado('SAG-BLOQUEADOR-001');
        $posicionIntermedia = Posicion::query()
            ->where('camara_id', $zona->id)
            ->where('banda', 1)
            ->where('posicion', 2)
            ->where('nivel', 1)
            ->firstOrFail();
        $bloqueador->ubicacionActual()->update([
            'camara_id' => $zona->id,
            'posicion_id' => $posicionIntermedia->id,
            'ubicado_at' => now(),
        ]);
        $folio = $this->crearFolioUbicado('SAG-SIN-CAPACIDAD-001');
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();

        $lote = $this->crearLoteEnPreparacion($administrador, [$folio], $chile);

        $this->assertSame('pendiente', $lote['preparacion_fisica']['capacidad']['capacidad_estado']);
        $this->assertSame(2, $lote['preparacion_fisica']['capacidad']['espacios_requeridos']);
        $this->assertSame(0, $lote['preparacion_fisica']['capacidad']['espacios_reservados']);
        $this->assertDatabaseCount('reservas_posiciones_inspeccion_sag', 0);
        $this->assertDatabaseCount('ubicaciones_actuales', 2);
        $this->assertSame(
            'programado',
            PlanOperacional::query()->findOrFail($lote['preparacion_fisica']['plan_id'])->estado->value,
        );
    }

    public function test_preparacion_fisica_solo_reserva_en_modo_guided_tablet_rolling(): void
    {
        $administrador = $this->administradorConTemporada();
        $this->crearZonaInspeccion($administrador, posiciones: 2);
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();
        $folio = $this->crearFolioUbicado('SAG-HORIZONTE-BATCH-001');

        config()->set([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'batch',
        ]);

        $lote = $this->crearLoteEnPreparacion($administrador, [$folio], $chile);

        $this->assertNull($lote['preparacion_fisica']);
        $this->assertDatabaseCount('planes_operacionales', 0);
        $this->assertDatabaseCount('reservas_posiciones_inspeccion_sag', 0);
    }

    public function test_catalogo_contiene_todos_los_destinos_y_bloque_ue_con_fotografia_de_27_miembros(): void
    {
        $administrador = $this->administradorConTemporada();

        $this->assertSame(250, Pais::query()->count());
        $this->assertSame(249, Pais::query()->where('es_iso_oficial', true)->count());
        $this->assertSame(27, BloqueMercado::query()->where('codigo', 'UE')->firstOrFail()->paises()->count());

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/inspeccion-sag/catalogos')
            ->assertOk()
            ->assertJsonCount(250, 'paises')
            ->assertJsonPath('bloques.0.codigo', 'UE')
            ->assertJsonCount(27, 'bloques.0.paises')
            ->assertJsonCount(5, 'tipos_lote')
            ->assertJsonFragment(['value' => 'muestreo_usda', 'label' => 'Muestreo USDA', 'tipo_aprobacion' => 'AU'])
            ->assertJsonFragment(['value' => 'inspeccion_origen', 'label' => 'Inspección Origen', 'tipo_aprobacion' => 'AO'])
            ->assertJsonFragment(['value' => 'inspeccion_linea', 'label' => 'Inspección en línea', 'tipo_aprobacion' => 'AO'])
            ->assertJsonFragment(['value' => 'fumigacion', 'label' => 'Fumigación', 'tipo_aprobacion' => 'AF'])
            ->assertJsonFragment(['value' => 'cambio_mercado', 'label' => 'Cambio de mercado', 'tipo_aprobacion' => null])
            ->assertJsonFragment(['value' => 'AO', 'label' => 'Aprobado Origen'])
            ->assertJsonFragment(['value' => 'AU', 'label' => 'Aprobado USDA'])
            ->assertJsonFragment(['value' => 'AF', 'label' => 'Aprobado Fumigación']);
    }

    public function test_cliente_y_especie_son_obligatorios_y_los_otros_cinco_filtros_se_combinan(): void
    {
        $administrador = $this->administradorConTemporada();
        $condicion = CondicionSag::create(['codigo' => 'SAG-OK', 'nombre' => 'Con condición', 'activo' => true]);
        $esperado = $this->crearFolioUbicado('SAG-FILTRO-001', [
            'exportadora' => 'EX-01',
            'variedad' => 'Regina',
            'condicion_sag_id' => $condicion->id,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'fecha_ingreso' => '2026-08-11 08:00:00',
            'datos_externos' => ['especie' => 'Cereza', 'csg' => 'CSG-001', 'cantidad_cajas' => 60],
        ]);
        $this->crearFolioUbicado('SAG-FILTRO-002', [
            'exportadora' => 'EX-01',
            'variedad' => 'Lapins',
            'datos_externos' => ['especie' => 'Cereza', 'csg' => 'CSG-002'],
        ]);
        $this->crearFolioUbicado('SAG-FILTRO-SALDO', [
            'exportadora' => 'EX-01',
            'tipo_bulto' => TipoBulto::Saldo,
            'datos_externos' => ['especie' => 'Cereza', 'csg' => 'CSG-001'],
        ]);
        $esperado->load([
            'validacionPallet.articulo.especieCatalogo',
            'validacionPallet.articulo.variedadCatalogo',
            'validacionPallet.origen.clienteCatalogo.cliente',
            'validacionPallet.origen.csgCatalogo',
        ]);
        $clienteId = $esperado->validacionPallet->origen->clienteCatalogo->cliente->id;
        $especieId = $esperado->validacionPallet->articulo->especieCatalogo->id;
        $variedadId = $esperado->validacionPallet->articulo->variedadCatalogo->id;
        $csgId = $esperado->validacionPallet->origen->csgCatalogo->id;

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/inspeccion-sag/folios/opciones')
            ->assertOk()
            ->assertJsonPath('clientes.0.id', $clienteId)
            ->assertJsonPath('clientes.0.especies.0.id', $especieId)
            ->assertJsonFragment(['id' => $variedadId, 'nombre' => 'Regina'])
            ->assertJsonFragment(['id' => $csgId, 'codigo' => 'CSG-001']);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/inspeccion-sag/folios')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cliente', 'especie']);

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/inspeccion-sag/folios?'.http_build_query([
                'cliente' => $clienteId,
                'especie' => $especieId,
                'variedad' => $variedadId,
                'condicion_sag' => 'con',
                'csg' => $csgId,
                'fecha_ingreso' => '2026-08-11',
                'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado->value,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $esperado->id)
            ->assertJsonPath('data.0.sag.estado', 'SI');
    }

    public function test_cambio_de_mercado_agrega_destino_y_resultados_no_aprobatorios_conservan_aprobaciones_previas(): void
    {
        $administrador = $this->administradorConTemporada();
        $folio = $this->crearFolioUbicado('SAG-MERCADO-001', [
            'exportadora' => 'EX-MERCADO',
            'datos_externos' => ['especie' => 'Kiwi', 'csg' => 'CSG-M'],
        ]);
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();
        $usa = Pais::query()->where('iso_alpha2', 'US')->firstOrFail();

        $primerLote = $this->crearLote($administrador, $folio, 'inspeccion_origen', 'pais', $chile->id);
        $this->resolverLote($administrador, $primerLote, 'aprobado');

        $segundoLote = $this->crearLote($administrador, $folio, 'cambio_mercado', 'pais', $usa->id);
        $this->resolverLote($administrador, $segundoLote, 'aprobado', 'AU');

        $tercerLote = $this->crearLote($administrador, $folio, 'muestreo_usda', 'pais', $usa->id);
        $this->resolverLote($administrador, $tercerLote, 'sin_resolucion');

        $cuartoLote = $this->crearLote($administrador, $folio, 'fumigacion', 'pais', $chile->id);
        $this->resolverLote($administrador, $cuartoLote, 'segregado');

        $this->assertDatabaseCount('autorizaciones_sag_folio', 2);
        $this->assertDatabaseHas('autorizaciones_sag_folio', [
            'folio_id' => $folio->id,
            'tipo_aprobacion' => 'AO',
            'pais_id' => $chile->id,
            'activa' => true,
        ]);
        $this->assertDatabaseHas('autorizaciones_sag_folio', [
            'folio_id' => $folio->id,
            'tipo_aprobacion' => 'AU',
            'pais_id' => $usa->id,
            'activa' => true,
        ]);

        $fila = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::PRODUCTO_TERMINADO)
            ->firstWhere('folio', $folio->numero_folio);
        $this->assertSame('AO · AU', $fila['estado_sag']);
        $this->assertStringContainsString('Chile', $fila['destinos_sag']);
        $this->assertStringContainsString('Estados Unidos', $fila['destinos_sag']);
    }

    public function test_bloque_ue_reemplaza_paises_miembro_y_admite_folio_sin_ubicacion_o_prefrio(): void
    {
        $administrador = $this->administradorConTemporada();
        $ubicado = $this->crearFolioUbicado('SAG-UE-001', [
            'exportadora' => 'EX-UE',
            'datos_externos' => ['especie' => 'Arándano'],
        ]);
        $sinUbicacion = Folio::create([
            'temporada_id' => $ubicado->temporada_id,
            'numero_folio' => 'SAG-SIN-UBICACION',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'exportadora' => 'EX-UE',
            'datos_externos' => ['especie' => 'Arándano'],
        ]);
        $this->vincularCatalogoValidacion($sinUbicacion);
        $ue = BloqueMercado::query()->where('codigo', 'UE')->firstOrFail();
        $francia = Pais::query()->where('iso_alpha2', 'FR')->firstOrFail();

        $this->actingAs($administrador, 'sanctum')
            ->getJson('/api/inspeccion-sag/folios?'.http_build_query([
                'folio' => 'SAG-SIN-UBICACION',
                'per_page' => 1,
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sinUbicacion->id)
            ->assertJsonPath('data.0.camara', null)
            ->assertJsonPath('data.0.posicion', null);

        $respuesta = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', [
                'tipo' => 'muestreo_usda',
                'folios' => [$ubicado->id],
                'destinos' => [
                    ['tipo' => 'bloque', 'id' => $ue->id],
                    ['tipo' => 'pais', 'id' => $francia->id],
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'destinos')
            ->assertJsonPath('destinos.0.codigo', 'UE')
            ->assertJsonCount(27, 'destinos.0.miembros');

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', [
                'tipo' => 'muestreo_usda',
                'folios' => [$sinUbicacion->id],
                'destinos' => [['tipo' => 'bloque', 'id' => $ue->id]],
            ])
            ->assertCreated()
            ->assertJsonPath('folios.0.folio.numero', 'SAG-SIN-UBICACION')
            ->assertJsonPath('folios.0.folio.camara', null)
            ->assertJsonPath('folios.0.folio.posicion', null);
    }

    public function test_inspeccion_en_linea_aprueba_automaticamente_y_genera_ambos_numeros(): void
    {
        $administrador = $this->administradorConTemporada();
        $folio = Folio::create([
            'numero_folio' => 'SAG-LINEA-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'exportadora' => 'MC-001',
            'variedad' => 'Hayward',
            'datos_externos' => ['especie' => 'Kiwi', 'csg' => '123225'],
        ]);
        $this->vincularCatalogoValidacion($folio);
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', [
                'tipo' => 'inspeccion_linea',
                'numero_inspeccion_sag' => 'SAG-2026-7788',
                'folios' => [$folio->id],
                'destinos' => [['tipo' => 'pais', 'id' => $chile->id]],
            ])
            ->assertCreated()
            ->assertJsonPath('codigo', 'IMC0000001')
            ->assertJsonPath('numero_inspeccion_sag', 'SAG-2026-7788')
            ->assertJsonPath('estado', 'finalizado')
            ->assertJsonPath('folios.0.estado', 'resuelto')
            ->assertJsonPath('folios.0.resultados.0.resultado', 'aprobado')
            ->assertJsonPath('folios.0.resultados.0.tipo_aprobacion', 'AO');

        $this->assertDatabaseHas('autorizaciones_sag_folio', [
            'folio_id' => $folio->id,
            'tipo_aprobacion' => 'AO',
            'pais_id' => $chile->id,
            'activa' => true,
        ]);
    }

    public function test_aprobar_prefrio_publica_una_maniobra_sag_tomable_con_destino_fijo_y_sin_recepcion_duplicada(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $zona = $this->crearZonaInspeccion($usuario, posiciones: 2);
        $folio = $this->crearFolioUbicado('SAG-TUNEL-001');
        $proceso = $this->crearPrefrioSag($usuario, [$folio]);
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            [$folio],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $this->assertDatabaseCount('tareas_movimiento', 0);
        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $this->assertDatabaseCount('tareas_movimiento', 0);

        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $proceso->version,
            'ocurrido_at' => now()->toAtomString(),
        ];
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prefrio/procesos/{$proceso->id}/aprobar", $payload)
            ->assertOk();
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prefrio/procesos/{$proceso->id}/aprobar", $payload)
            ->assertOk();
        $this->artisan('sag:preparar-traslados')->assertSuccessful();

        $tarea = TareaMovimiento::query()->sole();
        $this->assertSame($lote['preparacion_fisica']['plan_id'], $tarea->plan_operacional_id);
        $this->assertSame('ubicacion_inicial', $tarea->tipo_movimiento->value);
        $this->assertSame('tunel_prefrio', $tarea->contexto['origen_logico']);
        $this->assertSame($proceso->id, $tarea->contexto['proceso_prefrio_id']);
        $this->assertSame('inspeccion', $tarea->contexto['uso_banda_destino']);
        $this->assertTrue($tarea->contexto['destino_precalculado_inmutable']);
        $this->assertSame($zona->id, $tarea->camara_destino_id);
        $this->assertNull($tarea->posicion_origen_id);
        $this->assertSame('pendiente', $tarea->maniobraOperacional->estado->value);
        $this->assertDatabaseHas('reservas_posiciones_inspeccion_sag', [
            'posicion_id' => $tarea->posicion_destino_id,
            'tipo_espacio' => 'pallet',
        ]);
        $this->assertDatabaseMissing('planes_operacionales', ['tipo' => 'recepcion_tunel']);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);

        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-TUNEL', 'nombre' => 'Tablet SAG']);
        $sesion = app(ServicioSesionEstiba::class)->abrir($zona, $operador, $dispositivo);
        $planes = app(ServicioPlanesOperacionales::class);
        $tarea = $planes->asumir($tarea, $operador, $dispositivo);
        $this->assertSame($tarea->posicion_destino_id, $tarea->reservaActiva->bloqueo_posicion_id);
        $otroOperador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        try {
            $planes->asumir($tarea, $otroOperador, $dispositivo);
            $this->fail('La maniobra SAG no debe poder ser asumida por dos camareros.');
        } catch (ConflictoOperacion $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $separacion = ReservaPosicionInspeccionSag::query()->where('tipo_espacio', 'separacion')->sole();
        try {
            $planes->materializarDestino(
                $tarea,
                $separacion->posicion,
                $operador,
                $dispositivo,
            );
            $this->fail('El destino SAG no debe poder cambiarse por un espacio de separación.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('no puede sustituirse', $error->getMessage());
        }
        $planes->iniciar($tarea, $operador, $dispositivo);
        app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $folio->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $tarea->posicionDestino,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: $zona->refresh()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $tarea->refresh(),
        );
        $this->assertSame('completada', $tarea->refresh()->estado->value);
        $this->assertSame($tarea->posicion_destino_id, $folio->refresh()->ubicacionActual->posicion_id);
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/iniciar")
            ->assertOk()
            ->assertJsonPath('preparacion_fisica.estado', 'completado');
    }

    public function test_sag_reemplaza_recepcion_reversible_y_cancelar_restaura_la_recepcion(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $this->crearZonaInspeccion($usuario, posiciones: 3);
        $folio = $this->crearFolioUbicado('SAG-REEMPLAZO-001');
        $otro = $this->crearFolioUbicado('SAG-RECEPCION-002');
        $proceso = $this->crearPrefrioSag($usuario, [$folio, $otro], aprobado: true);
        $recepcion = app(ServicioGeneracionRecepcionTunel::class)->generar($proceso, $usuario);
        $anterior = $recepcion->tareas()->where('folio_id', $folio->id)->sole();
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            [$folio],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $sag = TareaMovimiento::query()->where('plan_operacional_id', $lote['preparacion_fisica']['plan_id'])->sole();

        $this->assertSame('cancelada', $anterior->refresh()->estado->value);
        $this->assertSame($sag->id, $anterior->reemplazada_por_tarea_id);
        $this->assertSame(2, TareaMovimiento::query()->where('estado', 'pendiente')->count());
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/cancelar")
            ->assertOk();
        $restaurada = $recepcion->tareas()->where('folio_id', $folio->id)->where('estado', 'pendiente')->sole();
        $this->assertSame('cancelada', $sag->refresh()->estado->value);
        $this->assertSame($restaurada->id, $sag->reemplazada_por_tarea_id);
        $this->assertNull($restaurada->posicion_destino_id);
        $this->assertSame(0, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());
        $this->assertSame($recepcion->id, app(ServicioGeneracionRecepcionTunel::class)->generar($proceso, $usuario)->id);
        $this->assertSame(2, TareaMovimiento::query()->where('estado', 'pendiente')->count());
    }

    public function test_cancelar_sag_reabre_recepcion_terminada_solo_para_el_pallet_que_seguia_en_tunel(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $this->crearZonaInspeccion($usuario, posiciones: 2);
        $sag = $this->crearFolioUbicado('SAG-REABRIR-001');
        $normal = $this->crearFolioUbicado('SAG-REABRIR-002');
        $proceso = $this->crearPrefrioSag($usuario, [$sag, $normal], aprobado: true);
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            [$sag],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $recepcion = app(ServicioGeneracionRecepcionTunel::class)->generar($proceso, $usuario);
        $this->assertSame(1, $recepcion->contexto['total_pallets_planificados']);
        $tareaNormal = $recepcion->tareas()->sole();
        $tareaNormal->update([
            'estado' => 'completada',
            'responsable_user_id' => $usuario->id,
            'completada_at' => now(),
        ]);
        $this->assertSame('completado', $recepcion->refresh()->estado->value);

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/cancelar")
            ->assertOk();
        $recepcion->refresh();
        $this->assertSame('programado', $recepcion->estado->value);
        $this->assertNull($recepcion->completado_at);
        $this->assertSame(2, $recepcion->contexto['total_pallets_planificados']);
        $restaurada = $recepcion->tareas()->where('estado', 'pendiente')->sole();
        $this->assertSame($sag->id, $restaurada->folio_id);
        $this->assertSame('completada', $tareaNormal->refresh()->estado->value);
        app(ServicioGeneracionRecepcionTunel::class)->generar($proceso, $usuario);
        $this->assertSame(2, $recepcion->tareas()->count());
    }

    public function test_cancelar_sag_no_libera_el_sector_si_hay_un_traslado_iniciado(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $this->crearZonaInspeccion($usuario, posiciones: 2);
        $folio = $this->crearFolioUbicado('SAG-INICIADO-001');
        $this->crearPrefrioSag($usuario, [$folio], aprobado: true);
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            [$folio],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $tarea = TareaMovimiento::query()->sole();
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-INICIADO', 'nombre' => 'Tablet SAG']);
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($tarea, $operador, $dispositivo);
        $planes->iniciar($tarea, $operador, $dispositivo);

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/cancelar")
            ->assertUnprocessable();
        $this->assertSame('preparacion', LoteInspeccionSag::query()->findOrFail($lote['id'])->estado->value);
        $this->assertSame('en_proceso', $tarea->refresh()->estado->value);
        $this->assertSame(2, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());
        $this->assertNotNull($tarea->reservaActiva);
    }

    public function test_reconciliacion_recupera_capacidad_y_mantiene_destinos_distintos_para_cada_pallet(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $folios = [
            $this->crearFolioUbicado('SAG-RECUPERAR-001'),
            $this->crearFolioUbicado('SAG-RECUPERAR-002'),
        ];
        $this->crearPrefrioSag($usuario, $folios, aprobado: true);
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            $folios,
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $this->assertSame('pendiente', $lote['preparacion_fisica']['capacidad']['capacidad_estado']);
        $this->assertDatabaseCount('tareas_movimiento', 0);
        $this->crearZonaInspeccion($usuario, posiciones: 3);
        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $destinos = TareaMovimiento::query()->orderBy('folio_id')->pluck('posicion_destino_id')->all();
        $this->assertCount(2, array_unique($destinos));
        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $this->assertSame($destinos, TareaMovimiento::query()->orderBy('folio_id')->pluck('posicion_destino_id')->all());
        $this->assertSame(2, ReservaPosicionInspeccionSag::query()
            ->whereIn('posicion_id', $destinos)->where('tipo_espacio', 'pallet')->count());
        $this->assertDatabaseCount('tareas_movimiento', 2);
    }

    public function test_reconciliacion_no_interrumpe_recepcion_iniciada_y_prepara_desde_camara_al_terminar(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $zona = $this->crearZonaInspeccion($usuario, posiciones: 2);
        $folio = $this->crearFolioUbicado('SAG-DIFERIDO-001');
        $origen = $folio->ubicacionActual->posicion;
        $proceso = $this->crearPrefrioSag($usuario, [$folio], aprobado: true);
        $recepcion = app(ServicioGeneracionRecepcionTunel::class)->generar($proceso, $usuario);
        $anterior = $recepcion->tareas()->sole();
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-DIFERIDO', 'nombre' => 'Tablet SAG']);
        $planes = app(ServicioPlanesOperacionales::class);
        $planes->asumir($anterior, $operador, $dispositivo);
        $planes->materializarDestino($anterior, $origen, $operador, $dispositivo);
        $planes->iniciar($anterior, $operador, $dispositivo);
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            [$folio],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $this->assertDatabaseCount('tareas_movimiento', 1);
        $sesion = app(ServicioSesionEstiba::class)->abrir($origen->camara, $operador, $dispositivo);
        app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $folio->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $origen,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: $origen->camara->refresh()->version_plano,
            generadoDispositivoAt: now(),
            tareaMovimiento: $anterior->refresh(),
        );
        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $nueva = TareaMovimiento::query()
            ->where('plan_operacional_id', $lote['preparacion_fisica']['plan_id'])
            ->sole();
        $this->assertSame('completada', $anterior->refresh()->estado->value);
        $this->assertSame('traslado_entre_camaras', $nueva->tipo_movimiento->value);
        $this->assertSame($origen->id, $nueva->posicion_origen_id);
        $this->assertSame($zona->id, $nueva->camara_destino_id);
    }

    public function test_aprobaciones_de_tuneles_distintos_preparan_desde_el_fondo_sin_apartar_destino_para_el_pendiente(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $this->crearZonaInspeccion($usuario, posiciones: 3);
        $pendiente = $this->crearFolioUbicado('SAG-PARCIAL-001');
        $listo = $this->crearFolioUbicado('SAG-PARCIAL-002');
        $procesoPendiente = $this->crearPrefrioSag($usuario, [$pendiente], sufijo: '-PENDIENTE');
        $this->crearPrefrioSag($usuario, [$listo], aprobado: true, sufijo: '-LISTO');
        $lote = $this->crearLoteEnPreparacion(
            $usuario,
            [$pendiente, $listo],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $primera = TareaMovimiento::query()->with('posicionDestino')->sole();
        $this->assertSame($listo->id, $primera->folio_id);
        $this->assertSame(2, $primera->posicionDestino->posicion);
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prefrio/procesos/{$procesoPendiente->id}/aprobar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $procesoPendiente->version,
                'ocurrido_at' => now()->toAtomString(),
            ])
            ->assertOk();
        $segunda = TareaMovimiento::query()->where('folio_id', $pendiente->id)->sole();
        $this->assertSame($lote['preparacion_fisica']['plan_id'], $segunda->plan_operacional_id);
        $this->assertSame(1, $segunda->posicionDestino->posicion);
        $this->assertSame($primera->posicion_destino_id, $primera->refresh()->posicion_destino_id);
        $this->assertDatabaseCount('tareas_movimiento', 2);
    }

    public function test_reconciliacion_no_reinventa_origen_de_tunel_para_pallets_ya_en_anden_o_despachados(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $this->crearZonaInspeccion($usuario, posiciones: 3);
        $anden = $this->crearFolioUbicado('SAG-YA-ANDEN-001');
        $despachado = $this->crearFolioUbicado('SAG-YA-DESPACHADO-002');
        $proceso = $this->crearPrefrioSag($usuario, [$anden, $despachado]);
        $this->crearLoteEnPreparacion(
            $usuario,
            [$anden, $despachado],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $proceso->update(['estado' => 'aprobado']);
        $proceso->folios()->update(['estado' => 'aprobado']);
        foreach ([$anden, $despachado] as $folio) {
            $folio->update([
                'condicion_termica' => 'prefrio_aprobado',
                'habilitacion_almacenamiento' => 'habilitado',
                'estado_operacional' => $folio->id === $despachado->id ? 'despachado' : 'pendiente_ubicacion',
            ]);
        }
        $carga = Carga::create([
            'temporada_id' => $anden->temporada_id,
            'codigo' => 'CAR-SAG-ANDEN',
            'estado' => 'pendiente',
            'prioridad' => 'alta',
            'creada_por_user_id' => $usuario->id,
            'actualizada_por_user_id' => $usuario->id,
        ]);
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id,
            'folio_id' => $anden->id,
            'estado' => 'en_anden',
            'asignado_por_user_id' => $usuario->id,
            'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create([
            'folio_id' => $anden->id,
            'carga_folio_id' => $asignacion->id,
        ]);
        $this->artisan('sag:preparar-traslados')->assertSuccessful();
        $this->assertDatabaseCount('tareas_movimiento', 0);
        $this->assertDatabaseCount('ubicaciones_actuales', 0);
    }

    public function test_liberar_y_vencer_claim_sag_conserva_su_destino_para_otro_camarero(): void
    {
        $this->activarPlanificadorDirigido();
        $usuario = $this->administradorConTemporada();
        $this->crearZonaInspeccion($usuario, posiciones: 2);
        $folio = $this->crearFolioUbicado('SAG-LEASE-001');
        $this->crearPrefrioSag($usuario, [$folio], aprobado: true);
        $this->crearLoteEnPreparacion(
            $usuario,
            [$folio],
            Pais::query()->where('iso_alpha2', 'CL')->firstOrFail(),
        );
        $tarea = TareaMovimiento::query()->sole();
        $destinoId = $tarea->posicion_destino_id;
        $primero = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $segundo = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-SAG-LEASE', 'nombre' => 'Tablet SAG']);
        $planes = app(ServicioPlanesOperacionales::class);
        $reservas = app(ServicioReservasTareasMovimiento::class);

        $planes->asumir($tarea, $primero, $dispositivo);
        $reservas->liberar($tarea, $primero, $dispositivo);
        $this->assertSame('pendiente', $tarea->refresh()->estado->value);
        $this->assertSame($destinoId, $tarea->posicion_destino_id);
        $this->assertNull($tarea->reservaActiva);

        $asumida = $planes->asumir($tarea, $segundo, $dispositivo);
        $this->assertSame($destinoId, $asumida->reservaActiva->bloqueo_posicion_id);
        $asumida->reservaActiva->update(['vence_at' => now()->subMinute()]);
        $this->assertSame(1, $reservas->expirarVencidas());
        $this->assertSame('pendiente', $tarea->refresh()->estado->value);
        $this->assertSame($destinoId, $tarea->posicion_destino_id);
        $this->assertSame(2, ReservaPosicionInspeccionSag::query()->whereNotNull('clave_bloqueo')->count());
        $retomada = $planes->asumir($tarea, $primero, $dispositivo);
        $this->assertSame($destinoId, $retomada->reservaActiva->bloqueo_posicion_id);
    }

    /** @param array<int, Folio> $folios */
    private function crearPrefrioSag(User $usuario, array $folios, bool $aprobado = false, string $sufijo = ''): ProcesoPrefrio
    {
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-SAG'.$sufijo,
            'nombre' => 'Túnel SAG',
            'capacidad_posiciones' => max(2, count($folios)),
            'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
            'version_configuracion' => 1,
            'creado_por_user_id' => $usuario->id,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $folios[0]->temporada_id,
            'codigo' => 'PFR-SAG'.$sufijo,
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'prefrio-sag'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => $aprobado ? 'aprobado' : 'pendiente_verificacion',
            'setpoint' => -1.5,
            'formato_referencia' => 'granel',
            'version' => 5,
            'creado_por_user_id' => $usuario->id,
            'iniciado_por_user_id' => $usuario->id,
            'iniciado_at' => now()->subHours(8),
            'pendiente_verificacion_at' => now()->subMinutes(5),
            'finalizado_por_user_id' => $aprobado ? $usuario->id : null,
            'finalizado_at' => $aprobado ? now() : null,
        ]);
        foreach ($folios as $indice => $folio) {
            $folio->ubicacionActual()->delete();
            $folio->update([
                'estado_operacional' => $aprobado ? 'pendiente_ubicacion' : 'pendiente_prefrio',
                'condicion_termica' => $aprobado ? 'prefrio_aprobado' : 'pendiente_prefrio',
                'habilitacion_almacenamiento' => $aprobado ? 'habilitado' : 'no_habilitado',
            ]);
            $folio->unsetRelation('ubicacionActual');
            $posicion = PosicionTunelPrefrio::create([
                'tunel_prefrio_id' => $tunel->id,
                'numero' => $indice + 1,
                'etiqueta' => 'TUN-SAG-P'.($indice + 1),
                'activa' => true,
            ]);
            ProcesoPrefrioFolio::create([
                'proceso_prefrio_id' => $proceso->id,
                'folio_id' => $folio->id,
                'posicion_tunel_prefrio_id' => $posicion->id,
                'estado' => $aprobado ? 'aprobado' : 'en_proceso',
                'temperatura_inicial' => 8,
                'temperatura_final' => $aprobado ? -0.5 : null,
                'cargado_at' => now()->subHours(8),
                'cargado_por_user_id' => $usuario->id,
            ]);
        }

        return $proceso;
    }

    private function activarPlanificadorDirigido(): void
    {
        config()->set([
            'planificador.generacion_automatica' => true,
            'planificador.mode' => 'guided',
            'planificador.compute' => 'tablet',
            'planificador.horizon' => 'rolling',
        ]);
    }

    private function crearZonaInspeccion(
        User $usuario,
        int $posiciones,
        int $niveles = 1,
    ): Camara {
        $camara = Camara::create([
            'codigo' => 'CAM-INSPECCION',
            'nombre' => 'Zona de inspección SAG',
            'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => $posiciones,
            'cantidad_niveles' => $niveles,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        app(ServicioBandasOperacionales::class)->sincronizar($camara, $usuario);
        BandaOperacional::query()
            ->where('camara_id', $camara->id)
            ->where('numero', 1)
            ->firstOrFail();

        for ($nivel = 1; $nivel <= $niveles; $nivel++) {
            for ($numero = 1; $numero <= $posiciones; $numero++) {
                Posicion::create([
                    'camara_id' => $camara->id,
                    'banda' => 1,
                    'posicion' => $numero,
                    'nivel' => $nivel,
                    'etiqueta' => sprintf('B01-P%02d-N%d', $numero, $nivel),
                ]);
            }
        }

        return $camara;
    }

    /**
     * @param  array<int, Folio>  $folios
     * @return array<string, mixed>
     */
    private function crearLoteEnPreparacion(
        User $usuario,
        array $folios,
        Pais $destino,
    ): array {
        return $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'inspeccion_origen',
                'folios' => collect($folios)->pluck('id')->all(),
                'destinos' => [['tipo' => 'pais', 'id' => $destino->id]],
            ])
            ->assertCreated()
            ->json();
    }

    private function administradorConTemporada(): User
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'TEMP-SAG',
            'nombre' => 'Temporada SAG',
            'activa' => true,
        ], usuarioId: $administrador->id);

        return $administrador;
    }

    /** @param array<string, mixed> $atributos */
    private function crearFolioUbicado(string $numero, array $atributos = []): Folio
    {
        $camara = Camara::query()->firstOrCreate(['codigo' => 'CAM-SAG'], [
            'nombre' => 'Cámara SAG',
            'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 100,
            'cantidad_niveles' => 1,
        ]);
        $numeroPosicion = Posicion::query()->where('camara_id', $camara->id)->count() + 1;
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => $numeroPosicion,
            'nivel' => 1,
            'etiqueta' => 'B01-P'.str_pad((string) $numeroPosicion, 2, '0', STR_PAD_LEFT).'-N1',
        ]);
        $folio = Folio::create(array_merge([
            'numero_folio' => $numero,
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'exportadora' => 'EX-SAG',
            'variedad' => 'Estándar',
            'datos_externos' => ['especie' => 'Cereza'],
        ], $atributos));
        UbicacionActual::create([
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
            'movimiento_id' => null,
            'ubicado_at' => now(),
        ]);
        $this->vincularCatalogoValidacion($folio);

        return $folio;
    }

    private function vincularCatalogoValidacion(Folio $folio): void
    {
        $datos = $folio->datos_externos ?? [];
        $especieNombre = (string) ($datos['especie'] ?? 'Cereza');
        $variedadNombre = (string) ($folio->variedad ?? 'Estándar');
        $csgCodigo = (string) ($datos['csg'] ?? 'CSG-SAG');
        $cliente = Cliente::query()->firstOrCreate([
            'codigo' => (string) $folio->exportadora,
        ], [
            'nombre' => (string) $folio->exportadora,
            'activo' => true,
        ]);
        $codigoCliente = substr((string) preg_replace('/[^A-Z]/', '', mb_strtoupper($cliente->codigo)), 0, 2);
        $cliente->update(['codigo_folio_materiales' => $codigoCliente]);
        $clienteCatalogo = ClienteValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'cliente_id' => $cliente->id,
        ], [
            'nombre' => $cliente->nombre,
            'activo' => true,
        ]);
        $especie = EspecieValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'nombre' => $especieNombre,
        ], ['activo' => true]);
        $variedad = VariedadValidacion::query()->firstOrCreate([
            'especie_validacion_id' => $especie->id,
            'nombre' => $variedadNombre,
        ], ['activo' => true]);
        $csg = CsgValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'codigo' => $csgCodigo,
        ], ['activo' => true]);
        $csg->variedades()->syncWithoutDetaching([$variedad->id]);
        $articulo = ArticuloValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'cliente_validacion_id' => $clienteCatalogo->id,
            'especie' => $especieNombre,
            'variedad' => $variedadNombre,
            'calibre' => (string) ($folio->calibre ?? 'S/C'),
            'envase' => (string) ($datos['envase'] ?? 'Pallet'),
        ], [
            'especie_validacion_id' => $especie->id,
            'variedad_validacion_id' => $variedad->id,
            'activo' => true,
        ]);
        $origen = OrigenValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'cliente' => $cliente->nombre,
            'marca' => (string) ($folio->marca ?? 'Marca SAG'),
            'csg' => $csgCodigo,
        ], [
            'cliente_validacion_id' => $clienteCatalogo->id,
            'csg_validacion_id' => $csg->id,
            'activo' => true,
        ]);
        CombinacionValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'articulo_validacion_id' => $articulo->id,
            'origen_validacion_id' => $origen->id,
        ], ['activo' => true]);
        $categoria = CategoriaValidacion::query()->firstOrCreate([
            'temporada_id' => $folio->temporada_id,
            'nombre' => 'Exportación',
        ], ['activo' => true]);
        $dispositivo = Dispositivo::query()->firstOrCreate([
            'codigo' => 'SAG-TEST',
        ], [
            'nombre' => 'PDA SAG Test',
            'plataforma' => 'android',
            'activo' => true,
        ]);

        ValidacionPallet::query()->create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $folio->numero_folio),
            'numero_folio' => $folio->numero_folio,
            'numero_intento' => 1,
            'tipo_bulto' => $folio->tipo_bulto->value,
            'cantidad_cajas' => (int) ($datos['cantidad_cajas'] ?? 60),
            'linea_proceso' => 1,
            'turno' => 'A',
            'temporada_id' => $folio->temporada_id,
            'articulo_validacion_id' => $articulo->id,
            'origen_validacion_id' => $origen->id,
            'categoria_validacion_id' => $categoria->id,
            'resultado' => ResultadoValidacionPallet::Aprobado,
            'estado' => EstadoValidacionPallet::Aceptada,
            'catalogo_version_dispositivo' => 1,
            'catalogo_version_servidor' => 1,
            'snapshot' => [],
            'user_id' => User::query()->value('id'),
            'dispositivo_id' => $dispositivo->id,
            'folio_id' => $folio->id,
            'generado_dispositivo_at' => now(),
            'recibido_servidor_at' => now(),
        ]);
    }

    private function crearLote(User $usuario, Folio $folio, string $tipo, string $tipoDestino, string $destinoId): array
    {
        $respuesta = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/inspeccion-sag/lotes', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => $tipo,
                'folios' => [$folio->id],
                'destinos' => [['tipo' => $tipoDestino, 'id' => $destinoId]],
            ])
            ->assertCreated()
            ->json();

        return $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$respuesta['id']}/iniciar")
            ->assertOk()
            ->json();
    }

    private function resolverLote(User $usuario, array $lote, string $decision, ?string $tipoAprobacion = null): void
    {
        $resultadoId = $lote['folios'][0]['resultados'][0]['id'];
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/resultados/{$resultadoId}/resolver", [
                'resultado' => $decision,
                'tipo_aprobacion' => $tipoAprobacion,
            ])
            ->assertOk();
        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/inspeccion-sag/lotes/{$lote['id']}/finalizar")
            ->assertOk();
    }
}
