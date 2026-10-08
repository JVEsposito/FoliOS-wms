<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoReservaMaterial;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\AlmacenMaterial;
use App\Models\Camara;
use App\Models\ClienteMaterial;
use App\Models\DetalleDespachoMaterial;
use App\Models\EventoBloqueoMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\ReservaMaterial;
use App\Models\ReservaTransformacionMaterial;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Materiales\ServicioBloqueoMaterial;
use App\Services\Materiales\ServicioProcesamientoVencimientosMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class VencimientoMaterialApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrador;

    private string $token;

    private ItemMaterial $item;

    private Camara $camara;

    private AlmacenMaterial $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->token = $this->administrador->createToken('oficina', ['oficina'])->plainTextToken;
        $this->item = ItemMaterial::create([
            'cliente_material_id' => ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail()->id,
            'codigo' => 'VENCE-01', 'nombre' => 'Material con vencimiento', 'categoria' => 'Cajas',
            'categoria_operacional' => CategoriaOperacionalMaterial::MaterialMp, 'unidad_medida' => 'unidad',
            'activo' => true, 'creado_por_user_id' => $this->administrador->id,
            'actualizado_por_user_id' => $this->administrador->id,
        ]);
        $this->camara = Camara::create(['codigo' => 'MAT-VENCE', 'nombre' => 'Materiales',
            'contenido' => ContenidoCamara::Materiales, 'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        $this->destino = AlmacenMaterial::create(['codigo' => 'PACK-VENCE', 'nombre' => 'Packing',
            'tipo' => 'virtual', 'centro_costo' => 'PACK', 'activo' => true, 'requiere_ubicacion_fisica' => false,
            'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
    }

    public function test_fefo_no_reserva_el_vencido_y_conserva_el_que_vence_hoy_y_sin_fecha(): void
    {
        $vencido = $this->material('2026-10-04', 10);
        $hoy = $this->material('2026-10-05', 2);
        $sinFecha = $this->material(null, 10);
        $id = $this->despacho(5);
        $reservas = ReservaMaterial::query()->whereHas('detalle', fn ($q) => $q->where('despacho_material_id', $id))->get();
        $this->assertEqualsCanonicalizing([$hoy->folio_id, $sinFecha->folio_id], $reservas->pluck('folio_id')->all());
        $this->assertSame(0.0, (float) $vencido->refresh()->cantidad_reservada);
        $this->assertSame(2.0, (float) $hoy->refresh()->cantidad_reservada);
    }

    public function test_retiro_directo_y_movimientos_rechazan_vencido_incluso_con_excepcion_fifo(): void
    {
        $material = $this->material('2026-10-04', 10);
        $mensaje = $material->mensajeVencimiento();
        $this->withToken($this->token)->postJson('/api/materiales/despachos/directos', [
            'operacion_id' => (string) Str::uuid(), 'destino_material_id' => $this->destino->id,
            'folio_id' => $material->folio_id, 'cantidad' => 1,
            'motivo_excepcion_fifo' => 'Excepción autorizada de orden FIFO.',
        ])->assertUnprocessable()->assertJsonPath('message', $mensaje);
        $bodega = $this->bodega();
        foreach (['consumo', 'transferencia'] as $tipo) {
            $this->withToken($this->token)->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(), 'tipo' => $tipo, 'folio_id' => $material->folio_id,
                'almacen_origen_id' => $bodega->id, 'almacen_destino_id' => $this->destino->id,
                'cantidad' => 1, 'motivo' => 'Intento de material vencido.',
                'motivo_excepcion_fifo' => 'Se intenta saltar el orden.',
            ])->assertUnprocessable()->assertJsonPath('message', $mensaje);
        }
        $this->assertDatabaseCount('retiros_materiales', 0);
        $this->assertSame(10.0, (float) $material->refresh()->cantidad_actual);
    }

    public function test_proceso_diario_libera_reasigna_bloquea_sin_mover_stock_y_es_idempotente(): void
    {
        $vence = $this->material('2026-10-05', 10);
        $respaldo = $this->material('2026-10-10', 10);
        $this->despacho(8);
        $this->travelTo(Carbon::parse('2026-10-06 00:15', 'America/Santiago'));
        $this->artisan('materiales:procesar-vencimientos')->assertSuccessful();
        $this->assertSame('Vencido', $vence->refresh()->motivo_bloqueo);
        $this->assertSame(EstadoOperacionalFolio::Bloqueado, $vence->folio->refresh()->estado_operacional);
        $this->assertSame(10.0, (float) $vence->cantidad_actual);
        $this->assertSame(0.0, (float) $vence->cantidad_reservada);
        $this->assertSame(8.0, (float) $respaldo->refresh()->cantidad_reservada);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $vence->folio_id,
            'camara_id' => $this->camara->id, 'cantidad_actual' => 10, 'cantidad_reservada' => 0]);
        $this->assertDatabaseHas('reservas_materiales', ['folio_id' => $vence->folio_id, 'estado' => 'liberada']);
        $this->assertDatabaseHas('eventos_bloqueos_materiales', ['folio_id' => $vence->folio_id,
            'motivo' => 'Vencido', 'user_id' => null, 'tipo' => 'bloqueado']);
        $this->assertDatabaseHas('procesamientos_vencimientos_materiales', ['folios_procesados' => 1,
            'reservas_liberadas' => 1, 'reservas_reasignadas' => 1]);
        $this->artisan('materiales:procesar-vencimientos')->assertSuccessful();
        $this->assertSame(1, EventoBloqueoMaterial::query()->count());
        $this->assertSame(2, ReservaMaterial::query()->count());
        $this->assertSame(8.0, (float) $respaldo->refresh()->cantidad_reservada);
    }

    public function test_reserva_insuficiente_queda_parcial_y_con_alerta_en_api(): void
    {
        $this->material('2026-10-05', 10);
        $valido = $this->material('2026-10-07', 3);
        $id = $this->despacho(8);
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'America/Santiago'));
        $resumen = app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        $this->assertSame(5.0, $resumen['lineas_insuficientes'][0]['cantidad']);
        $this->assertSame(3.0, (float) $valido->refresh()->cantidad_reservada);
        $this->assertSame(5.0, (float) DetalleDespachoMaterial::query()->firstOrFail()->cantidad_sin_reserva_por_vencimiento);
        $this->withToken($this->token)->getJson("/api/materiales/despachos/{$id}")->assertOk()
            ->assertJsonPath('data.items.0.alerta_vencimiento', 'Reserva insuficiente por vencimiento')
            ->assertJsonPath('data.items.0.cantidad_reservada', '3.000');
    }

    public function test_devolucion_vencida_y_descarte_supervisado_se_permiten_sin_liberar_bloqueo(): void
    {
        $material = $this->material('2026-10-05', 10);
        $this->withToken($this->token)->postJson('/api/materiales/despachos/directos', [
            'operacion_id' => (string) Str::uuid(), 'destino_material_id' => $this->destino->id,
            'folio_id' => $material->folio_id, 'cantidad' => 4,
        ])->assertCreated();
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'America/Santiago'));
        app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        $bodega = $this->bodega();
        $this->withToken($this->token)->postJson('/api/materiales/almacenes/movimientos', [
            'operacion_id' => (string) Str::uuid(), 'tipo' => 'devolucion', 'folio_id' => $material->folio_id,
            'almacen_origen_id' => $this->destino->id, 'almacen_destino_id' => $bodega->id,
            'cantidad' => 4, 'motivo' => 'Retorno de material vencido.', 'camara_destino_id' => $this->camara->id,
        ])->assertCreated();
        $this->assertSame(10.0, (float) $material->refresh()->cantidad_actual);
        $this->assertTrue($material->bloqueado_por_vencimiento);
        $this->withToken($this->token)->postJson('/api/materiales/almacenes/movimientos', [
            'operacion_id' => (string) Str::uuid(), 'tipo' => 'ajuste', 'folio_id' => $material->folio_id,
            'almacen_origen_id' => $bodega->id, 'cantidad' => -10, 'motivo' => 'Descarte por vencimiento.',
        ])->assertCreated();
        $this->assertSame(0.0, (float) $material->refresh()->cantidad_actual);
    }

    public function test_no_se_libera_un_vencido_y_corregir_fecha_restaura_el_estado_con_auditoria(): void
    {
        $material = $this->material('2026-10-04', 10);
        app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        $this->withToken($this->token)->postJson("/api/materiales/inventario/{$material->folio_id}/liberar-bloqueo", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Liberación normal no válida.',
        ])->assertUnprocessable()->assertJsonPath('message', $material->mensajeVencimiento());
        $ruta = "/api/materiales/inventario/{$material->folio_id}/corregir-vencimiento";
        $payload = ['operacion_id' => (string) Str::uuid(), 'fecha_vencimiento' => '2026-11-05', 'motivo' => 'Extensión respaldada por reanálisis.'];
        $this->withToken($this->token)->postJson($ruta, [...$payload, 'motivo' => ' '])->assertUnprocessable();
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroMateriales, 'activo' => true]);
        $token = $operador->createToken('oficina', ['oficina'])->plainTextToken;
        $this->withToken($token)->postJson($ruta, $payload)->assertForbidden();
        $this->withToken($this->token)->postJson($ruta, $payload)->assertOk();
        $this->withToken($this->token)->postJson($ruta, $payload)->assertOk();
        $this->withToken($this->token)->postJson($ruta, [...$payload, 'fecha_vencimiento' => '2026-12-01'])->assertConflict();
        $this->assertSame(EstadoOperacionalFolio::Disponible, $material->folio->refresh()->estado_operacional);
        $this->assertFalse($material->refresh()->bloqueado_por_vencimiento);
        $evento = EventoBloqueoMaterial::query()->where('tipo', 'fecha_corregida')->sole();
        $this->assertSame('2026-10-04', $evento->metadatos['fecha_anterior']);
        $this->assertSame('2026-11-05', $evento->metadatos['fecha_nueva']);
        $this->assertSame($this->administrador->id, $evento->user_id);
    }

    public function test_correccion_sin_ubicacion_queda_pendiente_y_conserva_bloqueos_manuales(): void
    {
        $pendiente = $this->material('2026-10-04', 10, false);
        $manual = $this->material('2026-10-04', 10);
        app(ServicioBloqueoMaterial::class)->bloquear($manual, (string) Str::uuid(), 'Pendiente certificado.', $this->administrador);
        app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        foreach ([$pendiente, $manual] as $material) {
            $this->withToken($this->token)->postJson("/api/materiales/inventario/{$material->folio_id}/corregir-vencimiento", [
                'operacion_id' => (string) Str::uuid(), 'fecha_vencimiento' => '2026-11-01', 'motivo' => 'Reanálisis de laboratorio aprobado.',
            ])->assertOk();
        }
        $this->assertSame(EstadoOperacionalFolio::PendienteUbicacion, $pendiente->folio->refresh()->estado_operacional);
        $this->assertSame(EstadoOperacionalFolio::Bloqueado, $manual->folio->refresh()->estado_operacional);
        $this->assertSame('Pendiente certificado.', $manual->refresh()->motivo_bloqueo);
    }

    public function test_transformacion_excluye_vencido_y_reasigna_reserva_parcial_sin_perder_consumos(): void
    {
        $vencido = $this->material('2026-10-04', 10);
        $vence = $this->material('2026-10-05', 10);
        $orden = $this->orden(8);
        $this->assertDatabaseHas('reservas_transformacion_materiales', ['orden_transformacion_material_id' => $orden->id, 'folio_id' => $vence->folio_id, 'estado' => 'activa']);
        $this->assertSame(0.0, (float) $vencido->refresh()->cantidad_reservada);
        $respaldo = $this->material('2026-10-10', 3);
        $reserva = ReservaTransformacionMaterial::query()->where('folio_id', $vence->folio_id)->sole();
        // Dos unidades ya consumidas; el saldo y su reserva quedan en seis.
        $vence->update(['cantidad_actual' => 8, 'cantidad_reservada' => 6]);
        $reserva->update(['cantidad_consumida' => 2]);
        $this->travelTo(Carbon::parse('2026-10-06 12:00', 'America/Santiago'));
        app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        $this->assertSame(2.0, (float) $reserva->refresh()->cantidad_consumida);
        $this->assertSame(EstadoReservaMaterial::Liberada, $reserva->estado);
        $this->assertSame(3.0, (float) $respaldo->refresh()->cantidad_reservada);
        $this->assertSame(3.0, (float) $orden->refresh()->faltantes_por_vencimiento[$this->item->id]);
        $this->withToken($this->token)->getJson("/api/materiales/transformaciones/ordenes/{$orden->id}")->assertOk()
            ->assertJsonPath('data.alerta_vencimiento', 'Reserva insuficiente por vencimiento');
    }

    public function test_cierre_de_lote_rechaza_consumo_que_vencio_durante_la_operacion(): void
    {
        $material = $this->material('2026-10-05', 10);
        $orden = $this->orden(8);
        $orden = $this->withToken($this->token)->postJson("/api/materiales/transformaciones/ordenes/{$orden->id}/iniciar", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $orden->version,
        ])->assertOk()->json('data');
        $orden = $this->withToken($this->token)->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $orden['version'], 'cantidad_planificada_salida' => 8,
        ])->assertOk()->json('data');
        $this->travelTo(Carbon::parse('2026-10-06 00:01', 'America/Santiago'));
        $this->withToken($this->token)->postJson("/api/materiales/transformaciones/lotes/{$orden['lotes'][0]['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $orden['version'], 'cantidad_real_salida' => 8,
            'consumos' => [['folio_id' => $material->folio_id, 'cantidad' => 8]],
        ])->assertUnprocessable()->assertJsonPath('message', $material->mensajeVencimiento());
        $this->assertDatabaseCount('consumos_transformacion_materiales', 0);
        $this->assertSame(10.0, (float) $material->refresh()->cantidad_actual);
    }

    public function test_vista_panel_filtros_y_excel_respetan_ventana_por_item_y_custodia_distribuida(): void
    {
        $this->material('2026-10-04', 2);
        $proximo = $this->material('2026-10-07', 3);
        $this->material('2026-10-15', 7);
        $this->material(null, 99);
        $this->item->update(['dias_alerta_vencimiento' => 2]);
        $respuesta = $this->withToken($this->token)->getJson('/api/materiales/vencimientos?estado=por_vencer')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero_folio', $proximo->folio->numero_folio)
            ->assertJsonPath('data.0.vencimiento.etiqueta', 'Vence en 2 días')->assertJsonPath('resumen.por_vencer.folios', 1)
            ->assertJsonPath('resumen.por_vencer.cantidades.0.cantidad', '3.000')->assertJsonPath('resumen.vencido.folios', 1);
        $this->withToken($this->token)->getJson('/api/materiales/vencimientos?categoria=Otra')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->token)->getJson('/api/materiales/vencimientos?almacen_id='.$this->destino->id)->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($this->token)->getJson('/api/gerencia/resumen')->assertOk()->assertJsonPath('data.materiales.vencimientos.por_vencer.folios', 1);
        $archivo = $this->withToken($this->token)->get('/api/materiales/vencimientos/exportar?estado=por_vencer')->assertOk()->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivo));
        $contenido = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString($proximo->folio->numero_folio, $contenido);
        $this->assertStringContainsString('2026-10-07', $contenido);
        @unlink($archivo);
        $this->withToken($this->token)->get('/oficina/materiales/vencimientos')->assertOk()->assertSee('materialExpiryBody');
    }

    public function test_consulta_pda_informa_razon_de_vencimiento_y_stock_no_es_reservable(): void
    {
        $material = $this->material('2026-10-04', 10);
        $this->withToken($this->token)->getJson('/api/movimientos/consultar-folio?numero_folio='.$material->folio->numero_folio)
            ->assertOk()->assertJsonPath('data.mensaje_disponibilidad', $material->mensajeVencimiento())
            ->assertJsonPath('data.mensaje_vencimiento', $material->mensajeVencimiento());
        $this->withToken($this->token)->getJson('/api/materiales/inventario')->assertOk()
            ->assertJsonPath('data.0.reservable', false)->assertJsonPath('data.0.cantidad_disponible', '0.000')
            ->assertJsonPath('data.0.vencimiento.etiqueta', 'Vencido');
    }

    private function material(?string $fecha, float $cantidad, bool $ubicar = true): FolioMaterial
    {
        $folio = Folio::create(['numero_folio' => 'FGE'.str_pad((string) (FolioMaterial::query()->count() + 1), 7, '0', STR_PAD_LEFT),
            'tipo_bulto' => TipoBulto::Material, 'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'fecha_ingreso' => now(), 'activo' => true]);
        $material = FolioMaterial::create(['folio_id' => $folio->id, 'item_material_id' => $this->item->id,
            'cantidad_inicial' => $cantidad, 'cantidad_actual' => $cantidad, 'cantidad_reservada' => 0,
            'unidad_medida' => 'unidad', 'categoria_operacional' => CategoriaOperacionalMaterial::MaterialMp,
            'fecha_vencimiento' => $fecha]);
        if ($ubicar) {
            UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $this->camara->id, 'ubicado_at' => now()]);
            $folio->update(['estado_operacional' => EstadoOperacionalFolio::Disponible]);
        }

        return $material->refresh()->load('folio');
    }

    private function despacho(float $cantidad): string
    {
        return $this->withToken($this->token)->postJson('/api/materiales/despachos', [
            'operacion_id' => (string) Str::uuid(), 'destino_material_id' => $this->destino->id,
            'items' => [['item_material_id' => $this->item->id, 'cantidad' => $cantidad]],
        ])->assertCreated()->json('data.id');
    }

    private function orden(float $cantidad): OrdenTransformacionMaterial
    {
        $receta = $this->withToken($this->token)->postJson('/api/materiales/transformaciones/recetas', [
            'cliente_id' => $this->item->cliente->cliente_id, 'item_salida_id' => $this->item->id,
            'nombre' => 'Preparar material vigente', 'cantidad_base_salida' => 1,
            'componentes' => [['item_entrada_id' => $this->item->id, 'cantidad_estandar' => 1, 'es_componente_principal' => true, 'factor_conversion' => 1]],
        ])->assertCreated()->json('data');
        $orden = $this->withToken($this->token)->postJson('/api/materiales/transformaciones/ordenes', [
            'operacion_id' => (string) Str::uuid(), 'version_receta_material_id' => $receta['versiones'][0]['id'],
            'cantidad_planificada_salida' => $cantidad, 'fecha_operacional' => '2026-10-05',
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $orden['version'],
        ])->assertOk();

        return OrdenTransformacionMaterial::query()->findOrFail($orden['id']);
    }

    private function bodega(): AlmacenMaterial
    {
        return AlmacenMaterial::query()->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->firstOrFail();
    }
}
