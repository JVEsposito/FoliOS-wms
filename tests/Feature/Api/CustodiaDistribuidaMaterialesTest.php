<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Models\AlmacenMaterial;
use App\Models\Camara;
use App\Models\ClienteMaterial;
use App\Models\DestinoMaterial;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\IncidenciaVerificacionUbicacion;
use App\Models\ItemMaterial;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\Posicion;
use App\Models\ReservaMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\User;
use App\Services\Existencias\ServicioExistencias;
use App\Services\Materiales\ServicioAlmacenMaterial;
use App\Services\Materiales\ServicioConsultaAlmacenesMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustodiaDistribuidaMaterialesTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrega_parcial_conserva_ubicacion_y_consumo_reduce_total_empresa(): void
    {
        [$administrador, $tokenOficina] = $this->crearAdministrador();
        [, , $tokenTablet] = $this->crearCamarero();
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = $this->crearItem($administrador, $cliente);
        $destino = $this->crearDestino($administrador, 'Packing Línea 1', 'PACK-01');
        [$camara, $posicion] = $this->crearCamara();
        $folio = $this->crearFolio($item, 10);
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        $this->ubicar($tokenTablet, $folio, $camara, $posicion, $sesion);
        $despachoId = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/despachos', [
                'operacion_id' => (string) Str::uuid(),
                'destino_material_id' => $destino->id,
                'items' => [[
                    'item_material_id' => $item->id,
                    'cantidad' => 10,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $bodega = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->firstOrFail();
        $saldoBodega = SaldoMaterialAlmacen::query()
            ->where('folio_id', $folio->id)
            ->where('almacen_material_id', $bodega->id)
            ->firstOrFail();
        $reserva = ReservaMaterial::query()->firstOrFail();

        $this->assertSame($saldoBodega->id, $reserva->saldo_material_almacen_id);
        $this->assertSame(10.0, (float) $saldoBodega->cantidad_reservada);
        $this->assertProyeccion($folio->id, 10, 10);

        $this->entregar($tokenTablet, $despachoId, $folio, $sesion, 4)
            ->assertJsonPath('data.estado', 'parcial');

        $this->assertDatabaseHas('saldos_materiales_almacenes', [
            'folio_id' => $folio->id,
            'almacen_material_id' => $bodega->id,
            'cantidad_actual' => 6,
            'cantidad_reservada' => 6,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
        ]);
        $this->assertDatabaseHas('saldos_materiales_almacenes', [
            'folio_id' => $folio->id,
            'almacen_material_id' => $destino->id,
            'cantidad_actual' => 4,
            'cantidad_reservada' => 0,
            'camara_id' => null,
            'posicion_id' => null,
        ]);
        $this->assertDatabaseHas('ubicaciones_actuales', [
            'folio_id' => $folio->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
        ]);
        $this->assertProyeccion($folio->id, 10, 6);

        $filasParciales = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::MATERIALES)
            ->filter(fn (array $fila): bool => $fila['folio'] === $folio->numero_folio)
            ->values()
            ->all();
        $coleccionParcial = collect($filasParciales);
        $filaVirtualParcial = $coleccionParcial->firstWhere('tipo_almacen', 'Virtual');

        $this->assertCount(2, $filasParciales);
        $this->assertSame(10.0, (float) $coleccionParcial->sum('cantidad_actual'));
        $this->assertSame(
            10.0,
            (float) $coleccionParcial->sum(
                fn (array $fila): float => (float) ($fila['cantidad_total_empresa'] ?? 0),
            ),
        );
        $this->assertCount(
            1,
            $coleccionParcial->filter(
                fn (array $fila): bool => $fila['cantidad_total_empresa'] !== null,
            ),
        );
        $this->assertNotNull($filaVirtualParcial);
        $this->assertSame(4.0, $filaVirtualParcial['cantidad_disponible']);
        $this->assertSame('No', $filaVirtualParcial['reservable']);

        $this->entregar($tokenTablet, $despachoId, $folio, $sesion, 6)
            ->assertJsonPath('data.estado', 'completado');

        $this->assertDatabaseHas('saldos_materiales_almacenes', [
            'folio_id' => $folio->id,
            'almacen_material_id' => $bodega->id,
            'cantidad_actual' => 0,
            'cantidad_reservada' => 0,
            'camara_id' => null,
            'posicion_id' => null,
        ]);
        $this->assertDatabaseHas('saldos_materiales_almacenes', [
            'folio_id' => $folio->id,
            'almacen_material_id' => $destino->id,
            'cantidad_actual' => 10,
            'camara_id' => null,
            'posicion_id' => null,
        ]);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        $this->assertDatabaseHas('folios', [
            'id' => $folio->id,
            'estado_operacional' => EstadoOperacionalFolio::Disponible->value,
            'activo' => true,
        ]);
        $this->assertProyeccion($folio->id, 10, 0);

        $operacionConsumo = (string) Str::uuid();
        $payloadConsumo = [
            'operacion_id' => $operacionConsumo,
            'tipo' => 'consumo',
            'folio_id' => $folio->id,
            'almacen_origen_id' => $destino->id,
            'cantidad' => 3,
            'motivo' => 'Producción turno noche',
            'documento_relacionado' => 'TURNO-NOCHE',
        ];

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', $payloadConsumo)
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'consumo')
            ->assertJsonPath('data.saldo_origen_resultante', '7.000');
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', $payloadConsumo)
            ->assertCreated()
            ->assertJsonPath('data.saldo_origen_resultante', '7.000');

        $this->assertSame(
            1,
            MovimientoAlmacenMaterial::query()
                ->where('operacion_id', $operacionConsumo)
                ->count(),
        );
        $this->assertProyeccion($folio->id, 7, 0);

        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/almacenes')
            ->assertOk()
            ->assertJsonPath('perspectivas.bodega', [])
            ->assertJsonPath('perspectivas.centros_costo.0.numero_folio', 'FCU0000001')
            ->assertJsonPath('perspectivas.centros_costo.0.cantidad_actual', '7.000')
            ->assertJsonPath('perspectivas.total_empresa.0.total_empresa', '7.000');

        $exportacion = $this->conToken($tokenOficina)
            ->get('/api/materiales/almacenes/exportar?perspectiva=centros_costo&q=PACK-01')
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        $this->assertStringContainsString(
            'Inventario_CC_Centros_Costo_',
            (string) $exportacion->headers->get('content-disposition'),
        );
        $consultaInventarioCc = app(ServicioConsultaAlmacenesMaterial::class);
        $this->assertCount(1, $consultaInventarioCc->exportacion(
            'centros_costo',
            ['q' => 'PACK-01'],
        )['filas']);
        $this->assertCount(0, $consultaInventarioCc->exportacion(
            'centros_costo',
            ['q' => 'folio inexistente'],
        )['filas']);

        $filasExportacion = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::MATERIALES)
            ->filter(fn (array $fila): bool => $fila['folio'] === $folio->numero_folio)
            ->values()
            ->all();

        $this->assertCount(1, $filasExportacion);
        $this->assertSame('Virtual', $filasExportacion[0]['tipo_almacen']);
        $this->assertSame($destino->codigo, $filasExportacion[0]['codigo_almacen']);
        $this->assertSame('Packing Línea 1', $filasExportacion[0]['almacen']);
        $this->assertSame('PACK-01', $filasExportacion[0]['centro_costo']);
        $this->assertSame(7.0, $filasExportacion[0]['cantidad_actual']);
        $this->assertSame(7.0, $filasExportacion[0]['cantidad_disponible']);
        $this->assertSame(7.0, $filasExportacion[0]['cantidad_total_empresa']);
        $this->assertSame('Almacén virtual', $filasExportacion[0]['estado_ubicacion']);
        $this->assertSame('No', $filasExportacion[0]['reservable']);

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'consumo',
                'folio_id' => $folio->id,
                'almacen_origen_id' => $destino->id,
                'cantidad' => 7,
                'motivo' => 'Consumo final de la línea',
            ])
            ->assertCreated();

        $this->assertProyeccion($folio->id, 0, 0);
        $this->assertDatabaseHas('folios', [
            'id' => $folio->id,
            'estado_operacional' => EstadoOperacionalFolio::Agotado->value,
            'activo' => false,
        ]);
    }

    public function test_consumo_directo_desde_bodega_imputa_insumo_sin_crear_saldo_virtual(): void
    {
        [$administrador, $tokenOficina] = $this->crearAdministrador();
        [, , $tokenTablet] = $this->crearCamarero();
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = $this->crearItem($administrador, $cliente);
        $centroCosto = $this->crearDestino($administrador, 'Mantención', 'MANT-01');
        [$camara, $posicion] = $this->crearCamara();
        $folio = $this->crearFolio($item, 10);
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->ubicar($tokenTablet, $folio, $camara, $posicion, $sesion);
        $bodega = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->firstOrFail();
        $operacionId = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionId,
            'tipo' => 'consumo',
            'folio_id' => $folio->id,
            'almacen_origen_id' => $bodega->id,
            'centro_costo_id' => $centroCosto->id,
            'cantidad' => 4,
            'motivo' => 'Mantención preventiva del turno',
            'documento_relacionado' => 'VALE-001',
        ];

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'consumo')
            ->assertJsonPath('data.centro_costo', 'MANT-01')
            ->assertJsonPath('data.metadatos.modalidad_consumo', 'directo_bodega')
            ->assertJsonPath('data.metadatos.centro_costo_id', $centroCosto->id)
            ->assertJsonPath('data.saldo_origen_resultante', '6.000');
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', $payload)
            ->assertCreated()
            ->assertJsonPath('data.saldo_origen_resultante', '6.000');

        $movimiento = MovimientoAlmacenMaterial::query()
            ->where('operacion_id', $operacionId)
            ->firstOrFail();
        $this->assertSame($bodega->id, $movimiento->almacen_origen_id);
        $this->assertNull($movimiento->almacen_destino_id);
        $this->assertSame('MANT-01', $movimiento->centro_costo);
        $this->assertSame($administrador->id, $movimiento->user_id);
        $this->assertSame('VALE-001', $movimiento->documento_relacionado);
        $this->assertSame('Mantención', $movimiento->metadatos['centro_costo_nombre']);
        $this->assertSame(1, MovimientoAlmacenMaterial::query()
            ->where('operacion_id', $operacionId)
            ->count());
        $this->assertDatabaseHas('saldos_materiales_almacenes', [
            'folio_id' => $folio->id,
            'almacen_material_id' => $bodega->id,
            'cantidad_actual' => 6,
        ]);
        $this->assertDatabaseMissing('saldos_materiales_almacenes', [
            'folio_id' => $folio->id,
            'almacen_material_id' => $centroCosto->id,
        ]);
        $this->assertProyeccion($folio->id, 6, 0);
    }

    public function test_consumo_directo_rechaza_salida_sin_imputacion_o_de_categoria_invalida(): void
    {
        [$administrador, $tokenOficina] = $this->crearAdministrador();
        [, , $tokenTablet] = $this->crearCamarero();
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = $this->crearItem($administrador, $cliente);
        $centroCosto = $this->crearDestino($administrador, 'Packing', 'PACK-01');
        [$camara, $posicion] = $this->crearCamara();
        $folio = $this->crearFolio($item, 10);
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->ubicar($tokenTablet, $folio, $camara, $posicion, $sesion);
        $bodega = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->firstOrFail();
        $payload = [
            'tipo' => 'consumo',
            'folio_id' => $folio->id,
            'almacen_origen_id' => $bodega->id,
            'cantidad' => 2,
            'motivo' => 'Consumo de prueba controlado',
        ];

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable();
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
                'centro_costo_id' => $bodega->id,
            ])
            ->assertUnprocessable();

        $item->update(['categoria_operacional' => CategoriaOperacionalMaterial::MaterialMp]);
        FolioMaterial::query()->findOrFail($folio->id)->update([
            'categoria_operacional' => CategoriaOperacionalMaterial::MaterialMp,
        ]);
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
                'centro_costo_id' => $centroCosto->id,
            ])
            ->assertUnprocessable();

        $item->update(['categoria_operacional' => CategoriaOperacionalMaterial::Insumo]);
        FolioMaterial::query()->findOrFail($folio->id)->update([
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
        ]);
        $centroCosto->update(['activo' => false]);
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
                'centro_costo_id' => $centroCosto->id,
            ])
            ->assertUnprocessable();

        $centroCosto->update(['activo' => true]);
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
                'centro_costo_id' => $centroCosto->id,
                'cantidad' => 11,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        $this->assertProyeccion($folio->id, 10, 0);
    }

    public function test_consumo_directo_respeta_fifo_de_bodega_y_audita_la_excepcion(): void
    {
        [$administrador, $tokenOficina] = $this->crearAdministrador();
        [, , $tokenTablet] = $this->crearCamarero();
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = $this->crearItem($administrador, $cliente);
        $centroCosto = $this->crearDestino($administrador, 'Packing', 'PACK-01');
        [$camara, $primeraPosicion] = $this->crearCamara();
        $segundaPosicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 2,
            'nivel' => 1,
            'etiqueta' => 'B01-P02-N1',
        ]);
        $folioAntiguo = $this->crearFolio($item, 10);
        $folioNuevo = $this->crearFolio($item, 10, 'FCU0000002');
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->ubicar(
            $tokenTablet,
            $folioAntiguo,
            $camara,
            $primeraPosicion,
            $sesion,
        );
        $this->ubicar($tokenTablet, $folioNuevo, $camara, $segundaPosicion, $sesion);
        $bodega = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->firstOrFail();
        $payload = [
            'tipo' => 'consumo',
            'folio_id' => $folioNuevo->id,
            'almacen_origen_id' => $bodega->id,
            'centro_costo_id' => $centroCosto->id,
            'cantidad' => 2,
            'motivo' => 'Consumo con control FIFO',
        ];

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable();
        $operacionId = (string) Str::uuid();
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                ...$payload,
                'operacion_id' => $operacionId,
                'motivo_excepcion_fifo' => 'Lote solicitado expresamente por Packing',
            ])
            ->assertCreated();

        $movimiento = MovimientoAlmacenMaterial::query()
            ->where('operacion_id', $operacionId)
            ->firstOrFail();
        $this->assertSame(
            'Lote solicitado expresamente por Packing',
            $movimiento->metadatos['motivo_excepcion_fifo'],
        );
        $this->assertProyeccion($folioAntiguo->id, 10, 0);
        $this->assertProyeccion($folioNuevo->id, 8, 0);
    }

    public function test_almacen_virtual_rechaza_ubicacion_y_movimientos_son_inmutables(): void
    {
        [$administrador, $tokenOficina] = $this->crearAdministrador();
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = $this->crearItem($administrador, $cliente);
        $origen = $this->crearDestino($administrador, 'Packing Línea 1', 'PACK-01');
        $destino = $this->crearDestino($administrador, 'Frigorífico', 'FRIO-01');
        [$camara, $posicion] = $this->crearCamara();
        $folio = $this->crearFolio($item, 5);
        $bodega = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->firstOrFail();
        $saldoBodega = SaldoMaterialAlmacen::query()
            ->where('folio_id', $folio->id)
            ->where('almacen_material_id', $bodega->id)
            ->firstOrFail();
        $saldoBodega->update([
            'cantidad_actual' => 0,
            'camara_id' => null,
            'posicion_id' => null,
        ]);
        SaldoMaterialAlmacen::create([
            'folio_id' => $folio->id,
            'almacen_material_id' => $origen->id,
            'cantidad_actual' => 5,
            'cantidad_reservada' => 0,
        ]);

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'transferencia',
                'folio_id' => $folio->id,
                'almacen_origen_id' => $origen->id,
                'almacen_destino_id' => $destino->id,
                'cantidad' => 2,
                'camara_destino_id' => $camara->id,
                'posicion_destino_id' => $posicion->id,
            ])
            ->assertUnprocessable();

        $movimientoId = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'transferencia',
                'folio_id' => $folio->id,
                'almacen_origen_id' => $origen->id,
                'almacen_destino_id' => $destino->id,
                'cantidad' => 2,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->expectException(LogicException::class);
        MovimientoAlmacenMaterial::query()->findOrFail($movimientoId)->update([
            'motivo' => 'Intento de modificación histórica',
        ]);
    }

    public function test_exporta_movimientos_consumos_y_ajustes_con_rango_de_fechas(): void
    {
        [$administrador, $tokenOficina] = $this->crearAdministrador();
        $cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        $item = $this->crearItem($administrador, $cliente);
        $origen = $this->crearDestino($administrador, 'Packing Línea 1', 'PACK-01');
        $destino = $this->crearDestino($administrador, 'Frigorífico', 'FRIO-01');
        $folio = $this->crearFolio($item, 10);
        $bodega = AlmacenMaterial::query()
            ->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)
            ->firstOrFail();
        SaldoMaterialAlmacen::query()
            ->where('folio_id', $folio->id)
            ->where('almacen_material_id', $bodega->id)
            ->update(['cantidad_actual' => 0]);
        SaldoMaterialAlmacen::create([
            'folio_id' => $folio->id,
            'almacen_material_id' => $origen->id,
            'cantidad_actual' => 10,
            'cantidad_reservada' => 0,
        ]);

        Carbon::setTestNow('2026-08-09 09:00:00');
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'consumo',
                'folio_id' => $folio->id,
                'almacen_origen_id' => $origen->id,
                'cantidad' => 2,
                'motivo' => 'Consumo de prueba exportable',
                'documento_relacionado' => 'CONSUMO-01',
            ])
            ->assertCreated();

        Carbon::setTestNow('2026-08-10 10:00:00');
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'ajuste',
                'folio_id' => $folio->id,
                'almacen_origen_id' => $origen->id,
                'cantidad' => -1,
                'motivo' => 'Ajuste de prueba exportable',
                'documento_relacionado' => 'AJUSTE-01',
            ])
            ->assertCreated();

        Carbon::setTestNow('2026-08-11 11:00:00');
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/almacenes/movimientos', [
                'operacion_id' => (string) Str::uuid(),
                'tipo' => 'transferencia',
                'folio_id' => $folio->id,
                'almacen_origen_id' => $origen->id,
                'almacen_destino_id' => $destino->id,
                'cantidad' => 3,
                'documento_relacionado' => 'TRANSFERENCIA-01',
            ])
            ->assertCreated();

        $consulta = app(ServicioConsultaAlmacenesMaterial::class);
        $this->assertSame(
            ['Consumo'],
            $consulta->exportacionKardex(['categoria' => 'consumos'])['filas']
                ->pluck('tipo')
                ->all(),
        );
        $this->assertSame(
            ['Ajuste'],
            $consulta->exportacionKardex(['categoria' => 'ajustes'])['filas']
                ->pluck('tipo')
                ->all(),
        );
        $this->assertSame(
            ['Transferencia'],
            $consulta->exportacionKardex(['categoria' => 'movimientos'])['filas']
                ->pluck('tipo')
                ->all(),
        );
        $this->assertSame(
            ['Ajuste'],
            $consulta->exportacionKardex([
                'categoria' => 'todos',
                'desde' => '2026-08-10',
                'hasta' => '2026-08-10',
            ])['filas']->pluck('tipo')->all(),
        );

        foreach ([
            'todos' => 'Inventario_CC_Historial_completo_',
            'movimientos' => 'Inventario_CC_Movimientos_',
            'consumos' => 'Inventario_CC_Consumos_',
            'ajustes' => 'Inventario_CC_Ajustes_',
        ] as $categoria => $archivo) {
            $respuesta = $this->conToken($tokenOficina)
                ->get("/api/materiales/almacenes/movimientos/exportar?categoria={$categoria}")
                ->assertOk()
                ->assertHeader(
                    'content-type',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                );
            $this->assertStringContainsString(
                $archivo,
                (string) $respuesta->headers->get('content-disposition'),
            );
        }

        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/almacenes/movimientos/exportar?desde=2026-08-11&hasta=2026-08-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hasta');

        Carbon::setTestNow();
    }

    public function test_esquema_vincula_todas_las_reservas_al_saldo_concreto(): void
    {
        $this->assertTrue(
            Schema::hasColumn('reservas_materiales', 'saldo_material_almacen_id'),
        );
        $this->assertTrue(
            Schema::hasColumn(
                'reservas_transformacion_materiales',
                'saldo_material_almacen_id',
            ),
        );
        $this->assertTrue(
            Schema::hasColumn('saldos_materiales_almacenes', 'version'),
        );
    }

    public function test_verificacion_materiales_autenticada_no_ajusta_y_supervision_enlaza_el_ajuste_idempotente(): void
    {
        config(['verificaciones.habilitada' => true, 'verificaciones.materiales.habilitada' => true,
            'verificaciones.materiales.posiciones_por_ronda' => 1]);
        [$admin, $tokenOficina] = $this->crearAdministrador();
        [$camarero, , $tokenTablet] = $this->crearCamarero();
        $item = $this->crearItem($admin, ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail());
        [$camara, $posicion] = $this->crearCamara();
        $folio = $this->crearFolio($item, 100);
        $sesion = $this->conToken($tokenTablet)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data.id');
        $this->ubicar($tokenTablet, $folio, $camara, $posicion, $sesion);
        $ronda = $this->conToken($tokenTablet)->getJson('/api/verificaciones-ubicacion/actual')->assertOk()
            ->assertJsonPath('data.contenido', 'materiales')->json('data');
        $this->assertStringNotContainsString($folio->numero_folio, json_encode($ronda));
        $this->conToken($tokenTablet)->postJson("/api/verificaciones-ubicacion/items/{$ronda['items'][0]['id']}/resultado", [
            'operacion_id' => (string) Str::uuid(), 'version' => 1, 'respuesta' => 'folios',
            'folios' => [['numero_folio' => $folio->numero_folio, 'cantidad_contada' => 95]],
        ])->assertOk()->assertJsonPath('resultado', 'diferencia_cantidad');
        $this->assertProyeccion($folio->id, 100, 0);
        $incidencia = IncidenciaVerificacionUbicacion::query()->sole();
        $this->conToken($tokenOficina)->getJson('/api/operacion-ahora')->assertOk()
            ->assertJsonPath('data.verificaciones.por_contenido.materiales.completadas', 1);
        $this->conToken($tokenOficina)->getJson('/api/gerencia/resumen')->assertOk()
            ->assertJsonPath('data.materiales.verificaciones.periodos.7.camaras.0.ubicacion.porcentaje', 100)
            ->assertJsonPath('data.materiales.verificaciones.periodos.7.camaras.0.cantidad.porcentaje', 0);
        $payload = ['operacion_id' => (string) Str::uuid(), 'tipo' => 'ajuste', 'folio_id' => $folio->id,
            'almacen_origen_id' => AlmacenMaterial::query()->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->value('id'),
            'cantidad' => -5, 'motivo' => 'Conteo físico revisado y aprobado.', 'incidencia_verificacion_id' => $incidencia->id];
        $this->conToken($tokenTablet)->postJson('/api/materiales/almacenes/movimientos', $payload)->assertForbidden();
        $this->conToken($tokenOficina)->postJson('/api/materiales/almacenes/movimientos', $payload)->assertCreated()
            ->assertJsonPath('data.incidencia_verificacion_id', $incidencia->id);
        $this->conToken($tokenOficina)->postJson('/api/materiales/almacenes/movimientos', $payload)->assertCreated();
        $this->assertProyeccion($folio->id, 95, 0);
        $this->assertSame('resuelta', $incidencia->refresh()->estado);
        $this->assertSame($admin->id, $incidencia->resuelto_por_user_id);
        $this->assertSame(1, MovimientoAlmacenMaterial::query()->where('incidencia_verificacion_id', $incidencia->id)->count());
        $payload['operacion_id'] = (string) Str::uuid();
        $this->conToken($tokenOficina)->postJson('/api/materiales/almacenes/movimientos', $payload)->assertUnprocessable();
    }

    public function test_toma_autenticada_aplica_diferencia_aprobada_y_conserva_movimientos_posteriores_al_conteo(): void
    {
        [$admin, $office] = $this->crearAdministrador();
        [$camarero, , $tablet] = $this->crearCamarero();
        $item = $this->crearItem($admin, ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail());
        [$camara, $posicion] = $this->crearCamara();
        $folio = $this->crearFolio($item, 100);
        $sesion = $this->conToken($tablet)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data.id');
        $this->ubicar($tablet, $folio, $camara, $posicion, $sesion);
        $bodega = AlmacenMaterial::where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->firstOrFail();
        $destino = $this->crearDestino($admin, 'Packing', 'PACK-01');
        $almacenPacking = app(ServicioAlmacenMaterial::class)->almacenDesdeDestino($destino, $admin);
        $toma = $this->conToken($office)->postJson('/api/materiales/tomas', ['operacion_id' => (string) Str::uuid(), 'camara_ids' => [$camara->id]])->assertCreated()->json('data');
        $ruta = '/api/materiales/tomas/'.$toma['id'];
        $toma = $this->conToken($office)->postJson($ruta.'/abrir', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version'], 'camarero_ids' => [$camarero->id]])->assertOk()->json('data');
        $transferir = function () use ($office, $folio, $bodega, $almacenPacking) {
            $this->conToken($office)->postJson('/api/materiales/almacenes/movimientos', ['operacion_id' => (string) Str::uuid(), 'tipo' => 'transferencia',
                'folio_id' => $folio->id, 'almacen_origen_id' => $bodega->id, 'almacen_destino_id' => $almacenPacking->id, 'cantidad' => 10, 'motivo' => 'Operación durante conteo'])->assertCreated();
        };
        $transferir(); // Foto 100, saldo local 90, total empresa 100.
        $ciego = $this->conToken($tablet)->getJson('/api/materiales/tomas/conteo')->assertOk()->json('data.0');
        $count = ['operacion_id' => (string) Str::uuid(), 'posicion_version' => $ciego['items'][0]['version'], 'vacia' => false,
            'folios' => [['numero_folio' => $folio->numero_folio, 'cantidad_contada' => 85]]];
        $countRuta = '/api/materiales/tomas/posiciones/'.$ciego['items'][0]['id'].'/contar';
        $this->conToken($tablet)->postJson($countRuta, $count)->assertOk();
        $this->conToken($tablet)->postJson($countRuta, $count)->assertOk();
        $transferir(); // No se pierde esta salida posterior: el ajuste es -5, no +5.
        $toma = $this->conToken($office)->getJson($ruta)->assertOk()->json('data');
        $this->assertSame(90, $toma['diferencias'][0]['esperado']);
        $toma = $this->conToken($office)->postJson($ruta.'/revisar', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version']])->assertOk()->json('data');
        $toma = $this->conToken($office)->postJson('/api/materiales/tomas/resultados/'.$toma['diferencias'][0]['id'].'/decidir', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version'], 'accion' => 'ajustar', 'motivo' => 'Diferencia física revisada'])->assertOk()->json('data');
        $approve = ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version']];
        $this->conToken($tablet)->postJson($ruta.'/aprobar', $approve)->assertForbidden();
        $this->conToken($office)->postJson($ruta.'/aprobar', $approve)->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $this->conToken($office)->postJson($ruta.'/aprobar', $approve)->assertOk();
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio->id, 'almacen_material_id' => $bodega->id, 'cantidad_actual' => 75]);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio->id, 'almacen_material_id' => $almacenPacking->id, 'cantidad_actual' => 20]);
        $this->assertProyeccion($folio->id, 95, 0);
        $ajuste = MovimientoAlmacenMaterial::where('toma_inventario_id', $toma['id'])->sole();
        $this->assertSame(-5.0, (float) $ajuste->cantidad);
        $this->assertSame($admin->id, $ajuste->user_id);
        $this->conToken($office)->postJson($ruta.'/anular', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version'] + 1, 'motivo' => 'No permitido'])->assertConflict();
        $pdf = $this->conToken($office)->get($ruta.'/acta')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringContainsString('Esperado 90 / contado 85', $pdf);
        $this->assertStringContainsString($ajuste->id, $pdf);
        $excel = $this->conToken($office)->get($ruta.'/excel')->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($excel->baseResponse->getFile()->getPathname()) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        foreach (['<v>90</v>', '<v>85</v>', '<v>-5</v>'] as $valor) {
            $this->assertStringContainsString($valor, $hoja);
        }
        $zip->close();
    }

    #[DataProvider('casosReubicacionToma')]
    public function test_toma_reubica_y_ajusta_segun_decision_con_auditoria_e_idempotencia(string $accion, float $contada, float $retiro, float $esperado, float $delta): void
    {
        [$admin, $office] = $this->crearAdministrador();
        [$camarero, , $tablet] = $this->crearCamarero();
        $item = $this->crearItem($admin, ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail());
        [$camara, $original] = $this->crearCamara();
        $camara->update(['posiciones_por_banda' => 2]);
        $encontrada = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 2, 'nivel' => 1, 'etiqueta' => 'B01-P02-N1']);
        $folio = $this->crearFolio($item, 100);
        $sesion = $this->conToken($tablet)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data.id');
        $this->ubicar($tablet, $folio, $camara, $original, $sesion);
        $toma = $this->conToken($office)->postJson('/api/materiales/tomas', ['operacion_id' => (string) Str::uuid(), 'camara_ids' => [$camara->id]])->assertCreated()->json('data');
        $ruta = '/api/materiales/tomas/'.$toma['id'];
        $toma = $this->conToken($office)->postJson($ruta.'/abrir', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version'], 'camarero_ids' => [$camarero->id]])->assertOk()->json('data');
        foreach ($toma['posiciones'] as $p) {
            $lecturas = $p['posicion_id'] === $encontrada->id ? [['numero_folio' => $folio->numero_folio, 'cantidad_contada' => $contada]] : [];
            $this->conToken($tablet)->postJson('/api/materiales/tomas/posiciones/'.$p['id'].'/contar', ['operacion_id' => (string) Str::uuid(), 'posicion_version' => 1, 'vacia' => ! $lecturas, 'folios' => $lecturas])->assertOk();
        }
        $bodega = AlmacenMaterial::where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->firstOrFail();
        if ($retiro > 0) {
            $packing = app(ServicioAlmacenMaterial::class)->almacenDesdeDestino($this->crearDestino($admin, 'Packing', 'PACK-01'), $admin);
            $this->conToken($office)->postJson('/api/materiales/almacenes/movimientos', ['operacion_id' => (string) Str::uuid(), 'tipo' => 'transferencia',
                'folio_id' => $folio->id, 'almacen_origen_id' => $bodega->id, 'almacen_destino_id' => $packing->id,
                'cantidad' => $retiro, 'motivo' => 'Salida legítima posterior al conteo'])->assertCreated();
        }
        $toma = $this->conToken($office)->getJson($ruta)->assertOk()->json('data');
        $sobrante = collect($toma['diferencias'])->firstWhere('tipo', 'sobrante');
        $faltante = collect($toma['diferencias'])->firstWhere('tipo', 'faltante');
        $this->assertSame($folio->id, $faltante['folio_id']);
        $this->assertSame($folio->id, $sobrante['folio_id']);
        $this->assertSame(100.0, (float) $sobrante['cantidad_saldo_al_contar']);
        $this->assertSame($delta, (float) $sobrante['diferencia_reubicacion']);
        $toma = $this->conToken($office)->postJson($ruta.'/revisar', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version']])->assertOk()->json('data');
        foreach ($toma['diferencias'] as $r) {
            $toma = $this->conToken($office)->postJson('/api/materiales/tomas/resultados/'.$r['id'].'/decidir', ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version'],
                'accion' => $r['tipo'] === 'sobrante' ? $accion : 'aceptar_sin_ajuste', 'motivo' => 'Folio localizado en otra posición',
                ...($r['tipo'] === 'sobrante' ? ['posicion_destino_id' => $encontrada->id] : [])])->assertOk()->json('data');
        }
        $aprobar = ['operacion_id' => (string) Str::uuid(), 'version' => $toma['version']];
        $this->conToken($tablet)->postJson($ruta.'/aprobar', $aprobar)->assertForbidden();
        $this->conToken($office)->postJson($ruta.'/aprobar', $aprobar)->assertOk()->assertJsonPath('data.estado', 'aprobada');
        $this->conToken($office)->postJson($ruta.'/aprobar', $aprobar)->assertOk();
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio->id, 'posicion_id' => $encontrada->id, 'cantidad_actual' => $esperado, 'almacen_material_id' => $bodega->id]);
        $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $folio->id, 'posicion_id' => $encontrada->id]);
        $this->assertDatabaseHas('reubicaciones_toma_inventario_materiales', ['posicion_origen_id' => $original->id, 'posicion_destino_id' => $encontrada->id, 'user_id' => $admin->id, 'cantidad' => 100 - $retiro, 'resultado_id' => $sobrante['id']]);
        $this->assertSame(1, DB::table('reubicaciones_toma_inventario_materiales')->where('resultado_id', $sobrante['id'])->count());
        $this->assertSame($delta !== 0.0 ? 1 : 0, MovimientoAlmacenMaterial::where('toma_inventario_id', $toma['id'])->count());
        if ($delta !== 0.0) {
            $movimiento = MovimientoAlmacenMaterial::where('toma_inventario_id', $toma['id'])->sole();
            $this->assertSame($delta, (float) $movimiento->cantidad);
            $this->assertSame($admin->id, $movimiento->user_id);
            $this->assertDatabaseHas('tomas_inventario_materiales_resultados', ['id' => $sobrante['id'], 'movimiento_almacen_id' => $movimiento->id, 'accion' => $accion]);
            $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio->id, 'almacen_material_id' => $packing->id, 'cantidad_actual' => $retiro]);
            $pdf = $this->conToken($office)->get($ruta.'/acta')->assertOk()->getContent();
            $this->assertStringContainsString('reubicar y ajustar', $pdf);
            $this->assertStringContainsString($movimiento->id, $pdf);
        }
        $this->assertProyeccion($folio->id, 100 + $delta, 0);
    }

    public static function casosReubicacionToma(): array
    {
        return [
            'reubicar sin ajuste' => ['reubicar', 100, 0, 100, 0],
            'reubicar y descontar con salida posterior' => ['reubicar_y_ajustar', 95, 10, 85, -5],
            'reubicar y agregar con salida posterior' => ['reubicar_y_ajustar', 105, 10, 95, 5],
        ];
    }

    private function crearAdministrador(): array
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);

        return [
            $usuario,
            $usuario->createToken('oficina-custodia', ['oficina'])->plainTextToken,
        ];
    }

    private function crearCamarero(): array
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-CUSTODIA',
            'nombre' => 'Tablet custodia',
            'activo' => true,
        ]);

        return [
            $usuario,
            $dispositivo,
            $usuario
                ->crearTokenParaDispositivo($dispositivo, 'tablet-custodia')
                ->plainTextToken,
        ];
    }

    private function crearItem(User $usuario, ClienteMaterial $cliente): ItemMaterial
    {
        return ItemMaterial::create([
            'cliente_material_id' => $cliente->id,
            'codigo' => 'FILM-CUSTODIA',
            'nombre' => 'Film de prueba',
            'categoria' => 'Embalaje',
            'categoria_operacional' => 'insumo',
            'unidad_medida' => 'kg',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }

    private function crearDestino(
        User $usuario,
        string $nombre,
        string $centroCosto,
    ): DestinoMaterial {
        return DestinoMaterial::create([
            'nombre' => $nombre,
            'centro_costo' => $centroCosto,
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }

    private function crearCamara(): array
    {
        $camara = Camara::create([
            'codigo' => 'MAT-CUST',
            'nombre' => 'Cámara materiales custodia',
            'contenido' => ContenidoCamara::Materiales,
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);

        return [$camara, $posicion];
    }

    private function crearFolio(
        ItemMaterial $item,
        float $cantidad,
        string $numeroFolio = 'FCU0000001',
    ): Folio {
        $item->loadMissing('cliente.temporada');
        $folio = Folio::create([
            'temporada_id' => $item->cliente->temporada->temporada_id,
            'numero_folio' => $numeroFolio,
            'tipo_bulto' => 'material',
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
            'fecha_ingreso' => now()->subDay(),
            'activo' => true,
            'origen_sistema' => 'recepcion_materiales',
            'estado_integracion' => 'no_vinculado',
        ]);
        FolioMaterial::create([
            'folio_id' => $folio->id,
            'item_material_id' => $item->id,
            'categoria_operacional' => 'insumo',
            'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad,
            'cantidad_reservada' => 0,
            'unidad_medida' => 'kg',
            'lote' => 'LOTE-01',
            'proveedor' => 'Proveedor prueba',
        ]);

        return $folio;
    }

    private function ubicar(
        string $token,
        Folio $folio,
        Camara $camara,
        Posicion $posicion,
        string $sesion,
    ): void {
        $this->conToken($token)
            ->postJson('/api/movimientos/ubicar', [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => $folio->numero_folio,
                'tipo_bulto' => 'material',
                'camara_destino_id' => $camara->id,
                'posicion_destino_id' => $posicion->id,
                'sesion_destino_id' => $sesion,
                'version_destino_conocida' => (int) $camara->fresh()->version_plano,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertOk();
    }

    private function entregar(
        string $token,
        string $despachoId,
        Folio $folio,
        string $sesion,
        float $cantidad,
    ) {
        return $this->conToken($token)
            ->postJson("/api/materiales/despachos/{$despachoId}/retirar", [
                'operacion_id' => (string) Str::uuid(),
                'retiros' => [[
                    'folio_id' => $folio->id,
                    'cantidad' => $cantidad,
                    'sesion_estiba_id' => $sesion,
                ]],
            ])
            ->assertOk();
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function assertProyeccion(
        string $folioId,
        float $cantidadActual,
        float $cantidadReservada,
    ): void {
        $material = FolioMaterial::query()->findOrFail($folioId);
        $this->assertSame($cantidadActual, (float) $material->cantidad_actual);
        $this->assertSame($cantidadReservada, (float) $material->cantidad_reservada);
        $this->assertSame(
            $cantidadActual,
            (float) SaldoMaterialAlmacen::query()
                ->where('folio_id', $folioId)
                ->sum('cantidad_actual'),
        );
        $this->assertSame(
            $cantidadReservada,
            (float) SaldoMaterialAlmacen::query()
                ->where('folio_id', $folioId)
                ->sum('cantidad_reservada'),
        );
    }
}
