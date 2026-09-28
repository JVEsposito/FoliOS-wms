<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoEnvase;
use App\Models\Posicion;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\RecepcionRomana;
use App\Models\ReservaCargaFolio;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Observers\InvalidarPanelGerencialObserver;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use App\Services\Gerencia\ServicioPanelGerencial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelGerencialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrega_una_instantanea_gerencial_con_capacidad_stock_y_disponibilidad(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $gerencia = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->crearProductoUbicado('PROD-001');
        Folio::create([
            'numero_folio' => 'PROD-BLOQUEADO',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $this->crearStockMaterial($administrador);
        $folioPrefrio = Folio::create([
            'numero_folio' => 'PROD-PREFRIO',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $this->crearTunel($administrador, $folioPrefrio);

        $respuesta = $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.actualizacion_segundos', 30)
            ->assertJsonPath('data.temporada.id', $temporada->id)
            ->assertJsonPath('data.temporada.activa', true)
            ->assertJsonPath('data.temporadas.0.id', $temporada->id)
            ->assertJsonPath('data.camaras.resumen.operativas', 3)
            ->assertJsonPath('data.camaras.resumen.ocupadas', 2)
            ->assertJsonPath('data.camaras.resumen.disponibles', 1)
            ->assertJsonPath('data.camaras.resumen.ocupacion_porcentaje', 66.7)
            ->assertJsonPath('data.productos.total_activos', 3)
            ->assertJsonPath('data.productos.disponibles_despacho', 1)
            ->assertJsonPath('data.productos.pendientes_prefrio', 1)
            ->assertJsonPath('data.productos.bloqueados', 1)
            ->assertJsonPath('data.productos.pendientes_ubicacion', 0)
            ->assertJsonPath('data.productos.ingresados_hoy', 3)
            ->assertJsonPath('data.productos.pallets', 3)
            ->assertJsonPath('data.productos.saldos', 0)
            ->assertJsonPath('data.cargas.activas', 0)
            ->assertJsonPath('data.cargas.folios_con_incidencia', 0)
            ->assertJsonPath('data.validacion.procesados_hoy', 0)
            ->assertJsonPath('data.validacion.conflictos_hoy', 0)
            ->assertJsonPath('data.materiales.items_con_stock', 1)
            ->assertJsonPath('data.materiales.folios_con_stock', 3)
            ->assertJsonPath('data.materiales.unidades_medida.0.unidad_medida', 'unidad')
            ->assertJsonPath('data.materiales.unidades_medida.0.cantidad_actual', 155)
            ->assertJsonPath('data.materiales.unidades_medida.0.cantidad_reservada', 25)
            ->assertJsonPath('data.materiales.unidades_medida.0.cantidad_disponible', 100)
            ->assertJsonPath('data.materiales.unidades_medida.0.cantidad_bloqueada', 20)
            ->assertJsonPath('data.materiales.unidades_medida.0.cantidad_pendiente_ubicacion', 10)
            ->assertJsonPath('data.materiales.unidades_medida.0.cantidad_no_disponible', 0)
            ->assertJsonPath('data.materiales.unidades_medida.0.items.0.cliente.codigo', 'GENERAL')
            ->assertJsonPath('data.materiales.unidades_medida.0.items.0.temporada.activa', true)
            ->assertJsonPath('data.materiales.despachos_abiertos', 0)
            ->assertJsonPath('data.materiales.recepciones_borrador', 0)
            ->assertJsonPath('data.prefrio.tuneles_operativos', 1)
            ->assertJsonPath('data.prefrio.capacidad', 2)
            ->assertJsonPath('data.prefrio.ocupadas', 1)
            ->assertJsonPath('data.prefrio.disponibles', 1)
            ->assertJsonPath('data.prefrio.tuneles.0.proceso_activo.estado', EstadoProcesoPrefrio::Cargando->value)
            ->assertJsonPath('data.prefrio.procesos_atrasados', 0)
            ->assertJsonPath('data.prefrio.duracion_promedio_minutos_7d', null)
            ->assertJsonPath('data.romana.en_bascula_ingreso', 0)
            ->assertJsonPath('data.romana.en_pesaje_envases', 0)
            ->assertJsonPath('data.romana.pendientes_destare', 0)
            ->assertJsonPath('data.romana.cerradas_hoy', 0)
            ->assertJsonPath('data.romana.peso_neto_hoy', 0)
            ->assertJsonCount(7, 'data.romana.tendencia_diaria')
            ->assertJsonPath('data.materia_prima.lotes_activos', 0)
            ->assertJsonPath('data.materia_prima.pendientes_hidrocooler', 0)
            ->assertJsonPath('data.envases.movimientos_hoy', 0)
            ->assertJsonPath('data.envases.pendientes_revision', 0);

        $this->assertNotNull($respuesta->json('data.generado_at'));
    }

    public function test_filtra_por_temporada_activa_y_permite_consultar_una_historica(): void
    {
        $gerencia = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $vigente = Temporada::query()->where('activa', true)->firstOrFail();
        $anterior = Temporada::create([
            'codigo' => '2025-2026',
            'nombre' => 'Temporada cerezas 2025–2026',
            'fecha_inicio' => '2025-10-01',
            'fecha_fin' => '2026-02-28',
            'activa' => false,
        ]);
        Folio::create([
            'temporada_id' => $vigente->id,
            'numero_folio' => 'VIGENTE-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        Folio::create([
            'temporada_id' => $anterior->id,
            'numero_folio' => 'HISTORICO-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
            'fecha_ingreso' => now()->subYear(),
            'activo' => true,
        ]);

        $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertJsonPath('data.temporada.id', $vigente->id)
            ->assertJsonPath('data.temporada.activa', true)
            ->assertJsonPath('data.productos.total_activos', 1)
            ->assertJsonPath('data.productos.pendientes_ubicacion', 1)
            ->assertJsonPath('data.productos.bloqueados', 0)
            ->assertJsonPath('data.temporadas.0.id', $vigente->id)
            ->assertJsonPath('data.temporadas.1.id', $anterior->id);

        $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen?temporada_id='.$anterior->id)
            ->assertOk()
            ->assertJsonPath('data.temporada.id', $anterior->id)
            ->assertJsonPath('data.temporada.activa', false)
            ->assertJsonPath('data.productos.total_activos', 1)
            ->assertJsonPath('data.productos.pendientes_ubicacion', 0)
            ->assertJsonPath('data.productos.bloqueados', 1);
    }

    public function test_conserva_los_conteos_pt_y_desglosa_cargas_antiguedad_y_bultos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00'));
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $gerencia = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $ubicado = $this->crearProductoUbicado('PT-PANEL-UBICADO');
        $ubicado->update(['fecha_ingreso' => now()->subDays(7)]);

        $folio = function (string $codigo, EstadoOperacionalFolio $estado, TipoBulto $tipo, int $dias) use ($temporada): Folio {
            return Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => $codigo,
                'tipo_bulto' => $tipo,
                'estado_operacional' => $estado,
                'fecha_ingreso' => now()->subDays($dias),
                'activo' => true,
            ]);
        };
        $comprometido = $folio('PT-PANEL-COMPROMETIDO', EstadoOperacionalFolio::Disponible, TipoBulto::Pallet, 14);
        $comprometidoSaldo = $folio('PT-PANEL-SALDO', EstadoOperacionalFolio::Disponible, TipoBulto::Saldo, 0);
        $comprometidoOtraCarga = $folio('PT-PANEL-OTRA-CARGA', EstadoOperacionalFolio::Disponible, TipoBulto::Pallet, 2);
        $folio('PT-PANEL-PREFRIO', EstadoOperacionalFolio::PendientePrefrio, TipoBulto::Pallet, 15);
        $folio('PT-PANEL-BLOQUEADO', EstadoOperacionalFolio::Bloqueado, TipoBulto::Pallet, 30);
        $folio('PT-PANEL-SIN-UBICACION', EstadoOperacionalFolio::PendienteUbicacion, TipoBulto::Pallet, 31);
        $folio('PT-PANEL-OTRO', EstadoOperacionalFolio::Disponible, TipoBulto::Pallet, 2);
        $inactivo = $folio('PT-PANEL-INACTIVO', EstadoOperacionalFolio::Bloqueado, TipoBulto::Pallet, 31);
        $inactivo->update(['activo' => false]);

        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-PANEL-PT',
            'estado' => EstadoCarga::Pendiente,
            'creada_por_user_id' => $administrador->id,
            'actualizada_por_user_id' => $administrador->id,
        ]);
        foreach ([$comprometido, $comprometidoSaldo] as $producto) {
            $asignacion = CargaFolio::create([
                'carga_id' => $carga->id,
                'folio_id' => $producto->id,
                'asignado_por_user_id' => $administrador->id,
                'asignado_at' => now(),
            ]);
            ReservaCargaFolio::create(['folio_id' => $producto->id, 'carga_folio_id' => $asignacion->id]);
        }

        $segundaCarga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-PANEL-PT-02',
            'estado' => EstadoCarga::Pendiente,
            'creada_por_user_id' => $administrador->id,
            'actualizada_por_user_id' => $administrador->id,
        ]);
        $segundaAsignacion = CargaFolio::create([
            'carga_id' => $segundaCarga->id,
            'folio_id' => $comprometidoOtraCarga->id,
            'asignado_por_user_id' => $administrador->id,
            'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create([
            'folio_id' => $comprometidoOtraCarga->id,
            'carga_folio_id' => $segundaAsignacion->id,
        ]);

        $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertJsonPath('data.productos.total_activos', 8)
            ->assertJsonPath('data.productos.disponibles_despacho', 1)
            ->assertJsonPath('data.productos.comprometidos_carga', 3)
            ->assertJsonPath('data.productos.cargas_comprometidas', 2)
            ->assertJsonPath('data.productos.pendientes_prefrio', 1)
            ->assertJsonPath('data.productos.bloqueados', 1)
            ->assertJsonPath('data.productos.pendientes_ubicacion', 1)
            ->assertJsonPath('data.productos.ingresados_hoy', 1)
            ->assertJsonPath('data.productos.pallets', 7)
            ->assertJsonPath('data.productos.saldos', 1)
            ->assertJsonPath('data.productos.otros', 1)
            ->assertJsonPath('data.productos.disponibilidad_porcentaje', 12.5)
            ->assertJsonPath('data.productos.antiguedad.umbral_alerta_dias', 30)
            ->assertJsonPath('data.productos.antiguedad.tramos.0.total', 4)
            ->assertJsonPath('data.productos.antiguedad.tramos.1.total', 1)
            ->assertJsonPath('data.productos.antiguedad.tramos.2.total', 2)
            ->assertJsonPath('data.productos.antiguedad.tramos.3.total', 1)
            ->assertJsonPath('data.productos.antiguedad.sobre_umbral', 1)
            ->assertJsonPath('data.productos.antiguedad.sin_fecha', 0);
    }

    public function test_productos_consulta_la_base_como_maximo_tres_veces(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $metodo = new \ReflectionMethod(ServicioPanelGerencial::class, 'productos');

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $metodo->invoke(app(ServicioPanelGerencial::class), $temporada->id);
            $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_desglosa_envases_por_tipo_stock_fisico_cuenta_cliente_y_siete_dias(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00'));
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $anterior = Temporada::create([
            'codigo' => '2025-2026',
            'nombre' => 'Temporada anterior',
            'fecha_inicio' => '2025-10-01',
            'fecha_fin' => '2026-02-28',
            'activa' => false,
        ]);
        $cliente = Cliente::create(['codigo' => 'CLI-PANEL', 'nombre' => 'Cliente panel', 'activo' => true]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $gerencia = User::factory()->create(['rol' => RolUsuario::Consulta]);

        $movimiento = function (string $tipo, int $cantidad, int $existencia, int $cuenta, string $fecha, string $temporadaId) use ($cliente, $administrador): void {
            MovimientoEnvase::create([
                'operacion_id' => (string) Str::uuid(),
                'temporada_id' => $temporadaId,
                'cliente_id' => $cliente->id,
                'documento_tipo' => 'prueba',
                'numero_documento' => (string) Str::uuid(),
                'tipo_movimiento' => $existencia < 0 ? 'despacho_cliente' : 'recepcion_compra',
                'tipo_envase' => $tipo,
                'cantidad' => $cantidad,
                'signo_cuenta' => $cuenta,
                'signo_existencia' => $existencia,
                'propiedad' => 'cliente',
                'ocurrido_at' => $fecha,
                'estado_revision' => 'pendiente',
                'creado_por_user_id' => $administrador->id,
            ]);
        };

        $movimiento('bins', 20, 1, 0, '2026-09-17 09:00:00', $temporada->id);
        $movimiento('bins', 8, -1, -1, '2026-09-23 10:00:00', $temporada->id);
        $movimiento('totes', 5, 1, 1, '2026-09-23 11:00:00', $temporada->id);
        $movimiento('bins', 99, 1, 1, '2026-09-23 10:00:00', $anterior->id);
        $movimiento('bins', 40, 1, 1, '2026-09-16 09:00:00', $temporada->id);

        $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertJsonPath('data.envases.movimientos_hoy', 2)
            ->assertJsonPath('data.envases.tipos.bins.existencia', 52)
            ->assertJsonPath('data.envases.tipos.bins.entradas_hoy', 0)
            ->assertJsonPath('data.envases.tipos.bins.salidas_hoy', 8)
            ->assertJsonPath('data.envases.tipos.bins.saldos_clientes.0.cliente', 'Cliente panel')
            ->assertJsonPath('data.envases.tipos.bins.saldos_clientes.0.saldo', 32)
            ->assertJsonPath('data.envases.tipos.bins.tendencia_diaria.0.entradas', 20)
            ->assertJsonPath('data.envases.tipos.bins.tendencia_diaria.6.fecha', '2026-09-23')
            ->assertJsonPath('data.envases.tipos.bins.tendencia_diaria.6.salidas', 8)
            ->assertJsonCount(7, 'data.envases.tipos.bins.tendencia_diaria')
            ->assertJsonPath('data.envases.tipos.totes.existencia', 5)
            ->assertJsonPath('data.envases.tipos.esponjas.existencia', 0);
    }

    public function test_restringe_el_panel_a_perfiles_gerenciales_de_solo_consulta(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        $this->getJson('/api/gerencia/resumen')->assertUnauthorized();
        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertForbidden();
    }

    public function test_reutiliza_la_instantanea_y_la_invalida_ante_cambios_operacionales(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $servicio = app(ServicioPanelGerencial::class);
        $servicio->invalidar();
        $claveCache = $servicio->claveCache($temporada->id);
        $this->travelTo(CarbonImmutable::parse('2026-07-29 09:00:00'));
        $gerencia = User::factory()->create(['rol' => RolUsuario::Consulta]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $primera = $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertJsonPath('data.productos.total_activos', 0)
            ->json('data');
        $consultasPrimera = count(DB::getQueryLog());

        $this->travel(10)->seconds();
        DB::flushQueryLog();
        $segunda = $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->json('data');
        $consultasSegunda = count(DB::getQueryLog());

        $this->assertSame($primera['generado_at'], $segunda['generado_at']);
        $this->assertTrue(Cache::has($claveCache));
        $this->assertGreaterThan(0, $consultasPrimera);
        $this->assertLessThan($consultasPrimera, $consultasSegunda);

        $folio = Folio::create([
            'numero_folio' => 'PROD-CACHE-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $observador = app(InvalidarPanelGerencialObserver::class);
        $this->assertInstanceOf(ShouldHandleEventsAfterCommit::class, $observador);
        $observador->saved($folio);
        $this->assertNotSame($claveCache, $servicio->claveCache($temporada->id));
        $this->assertFalse(Cache::has($servicio->claveCache($temporada->id)));

        $this->travel(1)->second();
        $tercera = $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertJsonPath('data.productos.total_activos', 1)
            ->json('data');

        $this->assertNotSame($primera['generado_at'], $tercera['generado_at']);
        $this->assertContains(
            RecepcionRomana::class,
            InvalidarPanelGerencialObserver::modelosObservados(),
        );
        $this->assertContains(
            Carga::class,
            InvalidarPanelGerencialObserver::modelosObservados(),
        );
        $this->assertContains(
            ValidacionPallet::class,
            InvalidarPanelGerencialObserver::modelosObservados(),
        );
        $this->assertContains(
            Temporada::class,
            InvalidarPanelGerencialObserver::modelosObservados(),
        );
    }

    public function test_el_acceso_de_oficina_expone_la_capacidad_gerencial(): void
    {
        $gerencia = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'email' => 'gerencia@estiba.local',
            'password' => 'password123',
        ]);

        $this->postJson('/api/acceso-oficina', [
            'email' => $gerencia->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_consultar_panel_gerencial', true)
            ->assertJsonPath('usuario.capacidades.puede_consultar_panel_gerencial', true);
    }

    private function crearProductoUbicado(string $numeroFolio): Folio
    {
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet de prueba',
        ]);
        $camara = Camara::create([
            'codigo' => 'CAM-GE-01',
            'nombre' => 'Cámara gerencial',
            'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 2,
            'cantidad_niveles' => 1,
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $sesion = app(ServicioSesionEstiba::class)->abrir($camara, $operador, $dispositivo);
        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $numeroFolio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
        );

        return $movimiento->folio;
    }

    private function crearStockMaterial(User $administrador): void
    {
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = ItemMaterial::create([
            'cliente_material_id' => $cliente->id,
            'codigo' => 'MAT-GE-01',
            'nombre' => 'Caja de prueba',
            'categoria' => 'Envases',
            'unidad_medida' => 'unidad',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-MAT-GE',
            'nombre' => 'Tablet materiales gerencia',
        ]);
        $camara = Camara::create([
            'codigo' => 'MAT-GE-01',
            'nombre' => 'Cámara materiales gerencia',
            'contenido' => ContenidoCamara::Materiales,
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
            $operador,
            $dispositivo,
        );
        $folioDisponible = Folio::create([
            'numero_folio' => 'MAT-FOLIO-001',
            'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'fecha_ingreso' => now(),
            'activo' => true,
            'origen_sistema' => 'recepcion_materiales',
        ]);
        FolioMaterial::create([
            'folio_id' => $folioDisponible->id,
            'item_material_id' => $item->id,
            'cantidad_inicial' => 125,
            'cantidad_actual' => 125,
            'cantidad_reservada' => 0,
            'unidad_medida' => $item->unidad_medida,
        ]);
        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $folioDisponible->numero_folio,
            tipoBulto: TipoBulto::Material,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
        );
        $movimiento->folio->material->update(['cantidad_reservada' => 25]);

        $folioBloqueado = Folio::create([
            'numero_folio' => 'MAT-FOLIO-BLOQ',
            'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::Bloqueado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        FolioMaterial::create([
            'folio_id' => $folioBloqueado->id,
            'item_material_id' => $item->id,
            'cantidad_inicial' => 20,
            'cantidad_actual' => 20,
            'cantidad_reservada' => 0,
            'unidad_medida' => 'unidad',
            'motivo_bloqueo' => 'Pendiente de revisión.',
        ]);

        $folioPendiente = Folio::create([
            'numero_folio' => 'MAT-FOLIO-PEND',
            'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        FolioMaterial::create([
            'folio_id' => $folioPendiente->id,
            'item_material_id' => $item->id,
            'cantidad_inicial' => 10,
            'cantidad_actual' => 10,
            'cantidad_reservada' => 0,
            'unidad_medida' => 'unidad',
        ]);
    }

    private function crearTunel(User $administrador, Folio $folio): void
    {
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-GE-01',
            'nombre' => 'Túnel gerencial',
            'capacidad_posiciones' => 2,
            'creado_por_user_id' => $administrador->id,
        ]);

        foreach (range(1, 2) as $numero) {
            PosicionTunelPrefrio::create([
                'tunel_prefrio_id' => $tunel->id,
                'numero' => $numero,
                'etiqueta' => "P{$numero}",
                'activa' => true,
            ]);
        }

        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $folio->temporada_id,
            'codigo' => 'PF-GE-01',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'panel-gerencial'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => EstadoProcesoPrefrio::Cargando,
            'setpoint' => -1.5,
            'creado_por_user_id' => $administrador->id,
        ]);

        ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $tunel->posiciones()->orderBy('numero')->value('id'),
            'estado' => EstadoFolioProcesoPrefrio::Cargado,
            'cargado_at' => now(),
            'cargado_por_user_id' => $administrador->id,
        ]);
    }
}
