<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\PerfilAcceso;
use App\Models\Posicion;
use App\Models\ReservaMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Materiales\ServicioProcesamientoVencimientosMaterial;
use App\Services\Materiales\ServicioReposicionMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MultibodegaMaterialFixture;
use Tests\TestCase;

class MultibodegaMaterialesApiTest extends TestCase
{
    use MultibodegaMaterialFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        $this->prepararMultibodega();
    }

    public function test_alta_y_edicion_conservan_codigo_version_historial_y_reintentos(): void
    {
        $datos = $this->datosBodega(['codigo' => 'BOD-SUR', 'nombre' => 'Bodega Sur']);
        $creada = $this->postJson('/api/materiales/bodegas', $datos)->assertCreated()->json('data');
        $this->postJson('/api/materiales/bodegas', $datos)->assertCreated()->assertJsonPath('data.id', $creada['id']);
        $this->postJson('/api/materiales/bodegas', [...$datos, 'nombre' => 'Otro nombre'])->assertConflict();
        $this->postJson('/api/materiales/bodegas', $this->datosBodega(['codigo' => 'BOD-SUR']))->assertUnprocessable();
        $cambio = $this->datosBodega(['codigo' => 'BOD-SUR', 'nombre' => 'Bodega Sur nueva', 'version' => 1]);
        $this->putJson('/api/materiales/bodegas/'.$creada['id'], $cambio)->assertOk()->assertJsonPath('data.version_bodega', 2);
        $this->putJson('/api/materiales/bodegas/'.$creada['id'], $cambio)->assertOk()->assertJsonPath('data.version_bodega', 2);
        $this->putJson('/api/materiales/bodegas/'.$creada['id'], [...$cambio, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->putJson('/api/materiales/bodegas/'.$creada['id'], [...$cambio, 'operacion_id' => (string) Str::uuid(), 'version' => 2, 'codigo' => 'OTRO'])->assertUnprocessable();
        $this->getJson('/api/materiales/bodegas/historial?almacen_id='.$creada['id'])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.usuario', $this->admin->name);
        $this->assertSame(100.0, (float) $this->origen->fresh()->cantidad_actual);
    }

    public function test_consulta_y_catalogos_no_eluden_el_permiso_de_configurar_bodegas(): void
    {
        $perfil = PerfilAcceso::create(['codigo' => 'SOLO-MB', 'nombre' => 'Solo inventario MB', 'rol_base' => 'administrador', 'activo' => true,
            'modulos' => ['materiales.inventario', 'materiales.catalogos'], 'modulos_tablet' => [], 'permisos_materiales' => ['catalogos.administrar']]);
        $this->admin->update(['perfil_acceso_id' => $perfil->id]);
        $this->withToken($this->tokenMultibodega)->getJson('/api/materiales/bodegas')->assertOk()->assertJsonPath('puede_configurar', false);
        $this->postJson('/api/materiales/bodegas', $this->datosBodega(['codigo' => 'OTRA']))->assertForbidden();
        $this->putJson('/api/materiales/bodegas/'.$this->norte['id'], $this->datosBodega(['version' => 1]))->assertForbidden();
        $this->putJson('/api/administracion/materiales/destinos/'.$this->norte['id'], ['nombre' => 'Eludir protección', 'centro_costo' => 'NORTE', 'activo' => false])->assertForbidden();
        $this->assertDatabaseHas('destinos_materiales', ['id' => $this->norte['id'], 'activo' => true, 'version_bodega' => 1]);
    }

    public function test_no_desactiva_bodegas_con_stock_o_documentos_pendientes_ni_la_central(): void
    {
        $orden = $this->ordenNorte();
        $cambio = $this->datosBodega(['version' => 1, 'activo' => false]);
        $this->putJson('/api/materiales/bodegas/'.$this->norte['id'], $cambio)->assertUnprocessable();
        $this->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Cambio de programa'])->assertOk();
        $this->putJson('/api/materiales/bodegas/'.$this->norte['id'], $cambio)->assertOk();
        $this->postJson('/api/materiales/despachos', ['operacion_id' => (string) Str::uuid(), 'almacen_origen_id' => $this->norte['id'],
            'destino_material_id' => $this->centroCosto->id, 'items' => [['item_material_id' => $this->itemA->id, 'cantidad' => 1]]])->assertUnprocessable();
        $this->putJson('/api/materiales/bodegas/'.$this->central->id, $this->datosBodega(['codigo' => $this->central->codigo, 'activo' => false]))->assertUnprocessable();
        $this->putJson('/api/materiales/bodegas/'.$this->norte['id'], $this->datosBodega(['version' => 2]))->assertOk();
        $this->repartir();
        $this->putJson('/api/materiales/bodegas/'.$this->norte['id'], $this->datosBodega(['version' => 3, 'activo' => false]))->assertUnprocessable();
    }

    public function test_reserva_cancela_y_filtra_el_saldo_exacto_sin_usar_la_otra_bodega(): void
    {
        $movimiento = $this->datosMovimiento();
        $id = $this->postJson('/api/materiales/almacenes/movimientos', $movimiento)->assertCreated()->json('data.id');
        $this->postJson('/api/materiales/almacenes/movimientos', $movimiento)->assertCreated()->assertJsonPath('data.id', $id);
        $despacho = $this->despacho($this->norte['id'], 30);
        $this->assertSame('30.000', $this->saldo($this->norte['id'])->cantidad_reservada);
        $this->assertSame('0.000', $this->saldo($this->central->id)->cantidad_reservada);
        $this->assertSame('MAT-NORTE', $despacho['items'][0]['sugerencias_fifo'][0]['camara']['codigo']);
        $this->getJson('/api/camaras/'.$this->camaraNorte->id.'/plano')->assertOk()
            ->assertJsonPath('data.ocupacion.ocupadas', 1)
            ->assertJsonPath('data.posiciones.0.folio.material.cantidad_actual', '40.000')
            ->assertJsonPath('data.posiciones.0.folio.material.cantidad_reservada', '30.000');
        $this->getJson('/api/camaras/'.$this->camara->id.'/plano')->assertOk()
            ->assertJsonPath('data.posiciones.0.folio.material.cantidad_actual', '60.000');
        $this->getJson('/api/materiales/inventario?almacen_id='.$this->norte['id'])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.saldo_id', $this->saldo($this->norte['id'])->id)->assertJsonPath('data.0.cantidad_actual', '40.000')->assertJsonPath('data.0.cantidad_disponible', '10.000');
        $this->postJson('/api/materiales/almacenes/movimientos', [...$this->datosMovimiento(20), 'almacen_origen_id' => $this->norte['id'],
            'almacen_destino_id' => $this->central->id, 'camara_destino_id' => $this->camara->id, 'posicion_destino_id' => $this->posicion->id])->assertUnprocessable();
        $cancelacion = ['operacion_id' => (string) Str::uuid(), 'motivo' => 'No se requiere el despacho'];
        foreach ([1, 2] as $_) {
            $this->postJson("/api/materiales/despachos/{$despacho['id']}/cancelar", $cancelacion)->assertOk();
        }
        $this->assertSame('0.000', $this->saldo($this->norte['id'])->cantidad_reservada);
        $this->assertSame('60.000', $this->saldo($this->central->id)->cantidad_actual);
        $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
    }

    public function test_retiro_parcial_en_tablet_recompone_la_reserva_solo_en_su_bodega(): void
    {
        $this->repartir();
        $this->despacho($this->central->id, 10);
        $despacho = $this->despacho($this->norte['id'], 30);
        $operador = User::factory()->create(['rol' => RolUsuario::CamareroMateriales, 'activo' => true]);
        $tablet = Dispositivo::create(['codigo' => 'TABLET-MB', 'nombre' => 'Tablet multibodega', 'activo' => true]);
        $this->withToken($operador->crearTokenParaDispositivo($tablet, 'mb')->plainTextToken);
        $sesion = $this->postJson('/api/camaras/'.$this->camaraNorte->id.'/sesiones')->assertCreated()->json('data.id');
        $etag = $this->getJson('/api/camaras/'.$this->camaraNorte->id.'/plano')->assertOk()->headers->get('ETag');
        $datos = ['operacion_id' => (string) Str::uuid(), 'retiros' => [['folio_id' => $this->origen->folio_id, 'cantidad' => 10, 'sesion_estiba_id' => $sesion]]];
        foreach ([1, 2] as $_) {
            $this->postJson("/api/materiales/despachos/{$despacho['id']}/retirar", $datos)->assertOk()->assertJsonPath('data.estado', 'parcial');
        }
        $this->assertSame('30.000', $this->saldo($this->norte['id'])->cantidad_actual);
        $this->assertSame('20.000', $this->saldo($this->norte['id'])->cantidad_reservada);
        $this->assertSame('60.000', $this->saldo($this->central->id)->cantidad_actual);
        $this->assertSame('10.000', $this->saldo($this->central->id)->cantidad_reservada);
        $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
        $this->assertSame('30.000', $this->origen->fresh()->cantidad_reservada);
        $this->assertDatabaseCount('retiros_materiales', 1);
        $this->withHeader('If-None-Match', $etag)->getJson('/api/camaras/'.$this->camaraNorte->id.'/plano')->assertOk()
            ->assertJsonPath('data.posiciones.0.folio.material.cantidad_actual', '30.000');
    }

    public function test_consumo_directo_de_insumo_descuenta_norte_y_no_crea_saldo_en_el_ceco(): void
    {
        $this->repartir();
        $datos = ['operacion_id' => (string) Str::uuid(), 'tipo' => 'consumo', 'folio_id' => $this->origen->folio_id,
            'almacen_origen_id' => $this->norte['id'], 'centro_costo_id' => $this->centroCosto->id, 'cantidad' => 5, 'motivo' => 'Consumo de la línea norte'];
        foreach ([1, 2] as $_) {
            $this->postJson('/api/materiales/almacenes/movimientos', $datos)->assertCreated();
        }
        $this->assertSame('35.000', $this->saldo($this->norte['id'])->cantidad_actual);
        $this->assertSame('60.000', $this->saldo($this->central->id)->cantidad_actual);
        $this->assertSame('95.000', $this->origen->fresh()->cantidad_actual);
        $this->assertDatabaseMissing('saldos_materiales_almacenes', ['almacen_material_id' => $this->centroCosto->id]);
    }

    public function test_entrega_provisional_desde_norte_se_regulariza_sin_repetir_el_stock(): void
    {
        $this->repartir();
        $datos = ['operacion_id' => (string) Str::uuid(), 'folio_id' => $this->origen->folio_id, 'cantidad' => 10,
            'almacen_origen_id' => $this->norte['id'], 'destino_material_id' => $this->centroCosto->id,
            'registro' => ['fecha_despacho' => now()->subDay()->toAtomString(), 'provisional' => true,
                'motivo' => 'Entrega física con guía pendiente', 'motivo_autorizacion' => 'Supervisión cotejó la salida de Norte']];
        $despacho = $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated()
            ->assertJsonPath('data.almacen_origen_id', $this->norte['id'])->assertJsonPath('data.entregas.0.estado', 'pendiente')->json('data');
        $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated();
        $url = '/api/materiales/despachos/'.$despacho['id'].'/entregas/'.$despacho['entregas'][0]['id'].'/regularizar';
        $regularizar = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'numero_documento' => 'GUIA-NORTE-1',
            'fecha_documento' => now(config('app.operational_timezone'))->toDateString(), 'motivo' => 'Respaldo cotejado con la entrega original'];
        foreach ([1, 2] as $_) {
            $this->postJson($url, $regularizar)->assertOk()->assertJsonPath('data.entregas.0.estado', 'regularizada');
        }
        $this->assertSame('30.000', $this->saldo($this->norte['id'])->cantidad_actual);
        $this->assertSame('60.000', $this->saldo($this->central->id)->cantidad_actual);
        $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
        $this->assertDatabaseCount('retiros_materiales', 1);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 2);
    }

    public function test_no_asigna_dos_saldos_fisicos_a_la_misma_posicion(): void
    {
        $datos = [...$this->datosMovimiento(), 'camara_destino_id' => $this->camara->id, 'posicion_destino_id' => $this->posicion->id];
        $this->postJson('/api/materiales/almacenes/movimientos', $datos)->assertUnprocessable();
        $this->assertSame('100.000', $this->saldo($this->central->id)->cantidad_actual);
        $this->assertDatabaseMissing('movimientos_almacenes_materiales', ['operacion_id' => $datos['operacion_id']]);
    }

    public function test_reposicion_suma_bodegas_fisicas_y_no_cuenta_su_traslado_como_consumo(): void
    {
        $this->repartir();
        $fila = app(ServicioReposicionMaterial::class)->filas(['item_id' => $this->itemA->id], false)->sole();
        $this->assertSame(100.0, $fila['disponible']);
        $this->assertSame(100.0, $fila['total_empresa']);
        $this->assertSame(0.0, $fila['consumo_periodo']);
    }

    #[DataProvider('cantidadesEnNorte')]
    public function test_lote_y_reversa_restauran_el_origen_aunque_no_haya_ubicacion_de_compatibilidad(float $transferida): void
    {
        $this->repartir($transferida);
        $orden = $this->planificarNorte(10);
        $id = $orden['id'];
        $this->postJson("/api/materiales/transformaciones/ordenes/$id/iniciar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 2])->assertOk();
        $lote = $this->postJson("/api/materiales/transformaciones/ordenes/$id/lotes", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 3, 'cantidad_planificada_salida' => 10])->assertOk()->json('data.lotes.0.id');
        $this->postJson("/api/materiales/transformaciones/lotes/$lote/cerrar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 4,
            'cantidad_real_salida' => 10, 'consumos' => [['folio_id' => $this->origen->folio_id, 'cantidad' => 10]]])->assertOk();
        $this->assertSame(number_format($transferida - 10, 3, '.', ''), $this->saldo($this->norte['id'])->cantidad_actual);
        $this->postJson("/api/materiales/transformaciones/lotes/$lote/revertir", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 5, 'motivo' => 'Corrección de producción'])->assertOk();
        $this->assertSame(number_format($transferida, 3, '.', ''), $this->saldo($this->norte['id'])->cantidad_actual);
        $this->assertSame('10.000', $this->saldo($this->norte['id'])->cantidad_reservada);
        $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
        if ($transferida === 10.0) {
            $this->assertNull($this->saldo($this->norte['id'])->camara_id);
            $this->despacho($this->central->id, 15);
            $this->assertSame('15.000', $this->saldo($this->central->id)->cantidad_reservada);
        }
    }

    public static function cantidadesEnNorte(): array
    {
        return ['todo fuera de Central' => [100.0], 'origen agotado con saldo en Central' => [10.0]];
    }

    public function test_cuadratura_devuelve_lo_no_armado_a_norte_y_libera_la_reserva(): void
    {
        $this->repartir();
        $orden = $this->planificarNorte();
        $id = $orden['id'];
        $this->postJson("/api/materiales/transformaciones/ordenes/$id/iniciar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 2,
            'modo_operacion' => 'cuadratura_cierre', 'folios_retirados' => [$this->origen->folio_id]])->assertOk();
        $this->postJson("/api/materiales/transformaciones/ordenes/$id/folios-salida/confirmar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 3,
            'folio_salida' => $orden['folios_salida'][0]['numero_folio'], 'cantidad_real_salida' => 10])->assertOk();
        $datos = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 4, 'folios_creados' => 1, 'motivo_desviacion' => 'Se reduce la producción planificada',
            'cuadratura' => [['item_id' => $this->itemA->id, 'cantidad_merma' => 0, 'cantidad_sin_armar' => 10]]];
        foreach ([1, 2] as $_) {
            $this->postJson("/api/materiales/transformaciones/ordenes/$id/cerrar", $datos)->assertOk()->assertJsonPath('data.estado', 'cerrada');
        }
        $this->assertSame('30.000', $this->saldo($this->norte['id'])->cantidad_actual);
        $this->assertSame('0.000', $this->saldo($this->norte['id'])->cantidad_reservada);
        $this->assertSame('60.000', $this->saldo($this->central->id)->cantidad_actual);
        $this->assertSame('90.000', $this->origen->fresh()->cantidad_actual);
    }

    #[DataProvider('momentosDeVencimiento')]
    public function test_vencimiento_respeta_el_dia_chileno_y_no_reasigna_a_otra_bodega(string $instante, string $fechaVencimiento, bool $vencido): void
    {
        $this->travelTo(Carbon::parse($instante, 'UTC'));
        $this->repartir();
        $despacho = $this->despacho($this->norte['id'], 10);
        $folio = Folio::create(['temporada_id' => $this->origen->folio->temporada_id, 'numero_folio' => 'FAA0000002', 'tipo_bulto' => 'material',
            'estado_operacional' => 'pendiente_ubicacion', 'activo' => true, 'fecha_ingreso' => now()->subDays(20)]);
        FolioMaterial::create(['folio_id' => $folio->id, 'item_material_id' => $this->itemA->id, 'categoria_operacional' => 'insumo', 'cantidad_inicial' => 100,
            'cantidad_actual' => 100, 'cantidad_reservada' => 0, 'unidad_medida' => 'rollos', 'fecha_vencimiento' => now()->addDays(20)->toDateString()]);
        $posicion = Posicion::create(['camara_id' => $this->camara->id, 'banda' => 1, 'posicion' => 2, 'nivel' => 1, 'etiqueta' => 'B01-P02-N1']);
        UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $this->camara->id, 'posicion_id' => $posicion->id, 'ubicado_at' => now()]);
        $this->origen->update(['fecha_vencimiento' => $fechaVencimiento]);
        $resumen = app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        $this->assertSame($vencido ? 1 : 0, $resumen['reservas_liberadas']);
        $this->assertSame($vencido ? '0.000' : '10.000', $this->saldo($this->norte['id'])->cantidad_reservada);
        $this->assertSame($vencido ? 0 : 1, ReservaMaterial::query()->where('detalle_despacho_material_id', $despacho['items'][0]['detalle_id'])->where('estado', 'activa')->count());
        $this->assertDatabaseHas('detalles_despacho_materiales', ['id' => $despacho['items'][0]['detalle_id'], 'cantidad_sin_reserva_por_vencimiento' => $vencido ? 10 : 0]);
        $this->assertSame('0.000', SaldoMaterialAlmacen::query()->where('folio_id', $folio->id)->firstOrFail()->cantidad_reservada);
    }

    public static function momentosDeVencimiento(): array
    {
        return [
            'vencido en Chile después de medianoche UTC' => ['2026-10-24 00:30:00', '2026-10-22', true],
            'vigente en Chile después de medianoche UTC' => ['2026-10-24 00:30:00', '2026-10-23', false],
            'último segundo del día chileno' => ['2026-10-24 02:59:59', '2026-10-23', false],
            'inicio del siguiente día chileno' => ['2026-10-24 03:00:00', '2026-10-23', true],
        ];
    }
}
