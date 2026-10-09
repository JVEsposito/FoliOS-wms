<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Models\AlmacenMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\FolioMaterialLiberado;
use App\Models\FolioTrabajoImpresionMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\PerfilImpresionEtiqueta;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\TomaInventarioMaterial;
use App\Models\TrabajoImpresionMaterial;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\VerificacionUbicacion;
use App\Models\VerificacionUbicacionItem;
use App\Services\Materiales\ServicioAlmacenMaterial;
use App\Services\Materiales\ServicioCorrelativoFolioMaterial;
use App\Services\Materiales\ServicioReposicionMaterial;
use App\Services\Materiales\ServicioReservaFifoMaterial;
use App\Services\Materiales\ServicioTransferenciaClienteMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransferenciaClienteMaterialFixture;
use Tests\TestCase;

class TransferenciaClienteMaterialApiTest extends TestCase
{
    use RefreshDatabase, TransferenciaClienteMaterialFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTransferencia();
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_total_retira_origen_ocupa_misma_posicion_y_registra_dos_movimientos_y_fechas(): void
    {
        $plano = $this->camara->fresh()->version_plano;
        $t = $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk()->assertJsonPath('data.modalidad', 'total')->json('data');
        $origen = $this->origen->fresh('folio');
        $destino = FolioMaterial::with('folio.ubicacionActual')->findOrFail($t['folio_destino']['id']);
        $this->assertSame(EstadoOperacionalFolio::RetiradoDefinitivo, $origen->folio->estado_operacional);
        $this->assertFalse($origen->folio->activo);
        $this->assertSame('0.000', $origen->cantidad_actual);
        $this->assertSame($this->itemA->id, $origen->item_material_id);
        $this->assertSame('FBB0000001', $destino->folio->numero_folio);
        $this->assertSame($this->itemB->id, $destino->item_material_id);
        $this->assertNull($destino->bulto_recepcion_material_id);
        $this->assertSame($this->posicion->id, $destino->folio->ubicacionActual->posicion_id);
        $this->assertSame('100.000', $destino->cantidad_actual);
        foreach (['lote', 'proveedor', 'proveedor_material_id', 'fecha_fabricacion', 'fecha_vencimiento'] as $campo) {
            $this->assertEquals($origen->$campo, $destino->$campo);
        }
        $this->assertEquals($origen->folio->fecha_ingreso, $destino->folio->fecha_ingreso);
        $this->assertSame('transferencia_cliente_materiales', $destino->folio->origen_sistema);
        $this->assertSame($t['id'], $destino->folio->identificador_externo);
        $this->assertGreaterThan($plano, $this->camara->fresh()->version_plano);
        $this->assertSame(100.0, (float) SaldoMaterialAlmacen::where('folio_id', $destino->folio_id)->sum('cantidad_actual'));
        $movimientos = MovimientoInventarioMaterial::where('transferencia_cliente_material_id', $t['id'])->get();
        $this->assertCount(2, $movimientos);
        $this->assertSame(0.0, (float) $movimientos->sum('cantidad'));
        $this->assertSame(100.0, (float) $t['snapshot']['origen_antes']['cantidad_actual']);
        $this->assertSame(0.0, (float) $t['snapshot']['origen_despues']['cantidad_actual']);
    }

    public function test_parcial_conserva_origen_y_destino_aparece_en_pendientes_y_solo_debita_bodega(): void
    {
        $bodega = app(ServicioAlmacenMaterial::class)->bodegaCentral();
        $otro = AlmacenMaterial::create(['codigo' => 'PACK-TR', 'nombre' => 'Packing', 'tipo' => 'virtual', 'centro_costo' => 'PACKING', 'activo' => true, 'requiere_ubicacion_fisica' => false, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        SaldoMaterialAlmacen::where('folio_id', $this->origen->folio_id)->where('almacen_material_id', $bodega->id)->firstOrFail()->update(['cantidad_actual' => 70, 'cantidad_reservada' => 20]);
        SaldoMaterialAlmacen::create(['folio_id' => $this->origen->folio_id, 'almacen_material_id' => $otro->id, 'cantidad_actual' => 30, 'cantidad_reservada' => 0]);
        app(ServicioAlmacenMaterial::class)->sincronizarProyeccion($this->origen);
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia(51))->assertUnprocessable()->assertSee('Solo se transfiere stock en Bodega Central');
        $t = $this->postJson($this->rutaTransferencia(), $this->datosTransferencia(50))->assertOk()->assertJsonPath('data.modalidad', 'parcial')->assertJsonPath('data.folio_destino.estado_operacional', 'pendiente_ubicacion')->json('data');
        $this->assertSame($this->posicion->id, UbicacionActual::where('folio_id', $this->origen->folio_id)->value('posicion_id'));
        $this->assertSame('50.000', $this->origen->fresh()->cantidad_actual);
        $this->assertSame('20.000', $this->origen->fresh()->cantidad_reservada);
        $this->assertSame('30.000', SaldoMaterialAlmacen::where('folio_id', $this->origen->folio_id)->where('almacen_material_id', $otro->id)->value('cantidad_actual'));
        $this->getJson('/api/materiales/recepciones/folios-pendientes')->assertOk()->assertJsonFragment(['numero_folio' => $t['folio_destino']['numero_folio']]);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $t['folio_destino']['id']]);
    }

    public function test_idempotencia_y_conflicto_no_consumen_otro_correlativo(): void
    {
        $datos = $this->datosTransferencia(30);
        $t = $this->postJson($this->rutaTransferencia(), $datos)->assertOk()->json('data');
        $this->postJson($this->rutaTransferencia(), $datos)->assertOk()->assertJsonPath('data.id', $t['id']);
        $datos['cantidad'] = 31;
        $this->postJson($this->rutaTransferencia(), $datos)->assertConflict()->assertJsonPath('codigo', 'conflicto_operacional');
        $this->assertDatabaseCount('transferencias_clientes_materiales', 1);
        $this->assertSame(1, (int) DB::table('correlativos_materiales_clientes')->where('cliente_id', $this->clienteB->id)->value('ultimo_numero'));
        $this->assertSame('70.000', $this->origen->fresh()->cantidad_actual);
    }

    public static function rechazos(): array
    {
        return array_map(fn ($c) => [$c], ['mismo_cliente', 'item_otro_cliente', 'unidad', 'bloqueo', 'vencimiento', 'cantidad', 'reserva', 'codigo', 'cliente_inactivo', 'item_inactivo']);
    }

    #[DataProvider('rechazos')]
    public function test_rechazos_no_cambian_inventario(string $caso): void
    {
        $datos = $this->datosTransferencia();
        match ($caso) {
            'mismo_cliente' => $datos['cliente_destino_id'] = $this->clienteA->id,
            'item_otro_cliente' => $datos['item_destino_id'] = $this->itemA->id,
            'unidad' => $this->itemB->update(['unidad_medida' => 'kg']),
            'bloqueo' => $this->origen->update(['motivo_bloqueo' => 'Retenido']),
            'vencimiento' => $this->origen->update(['fecha_vencimiento' => now()->subDays(1)->toDateString()]),
            'cantidad' => $datos['cantidad'] = 101,
            'reserva' => $this->origen->update(['cantidad_reservada' => 1]),
            'codigo' => $this->clienteB->update(['codigo_folio_materiales' => null]),
            'cliente_inactivo' => $this->clienteB->update(['activo' => false]),
            'item_inactivo' => $this->itemB->update(['activo' => false]),
        };
        $this->postJson($this->rutaTransferencia(), $datos)->assertUnprocessable();
        $this->assertDatabaseCount('transferencias_clientes_materiales', 0);
        $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
    }

    public function test_toma_y_verificacion_abiertas_impiden_renumerar(): void
    {
        $toma = TomaInventarioMaterial::create(['temporada_id' => $this->origen->folio->temporada_id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'camara_ids' => [$this->camara->id], 'estado' => 'en_conteo', 'creada_por_user_id' => $this->admin->id, 'version' => 1, 'foto' => [['folio_id' => $this->origen->folio_id]]]);
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia(10))->assertUnprocessable()->assertSee('toma de inventario abierta');
        $toma->update(['estado' => 'anulada']);
        $ronda = VerificacionUbicacion::create(['temporada_id' => $this->origen->folio->temporada_id, 'user_id' => $this->admin->id, 'contenido' => 'materiales', 'turno_inicio_at' => now(), 'turno_fin_at' => now()->addHours(8), 'vence_at' => now()->addHours(8), 'estado' => 'pendiente', 'objetivo' => 1, 'version' => 1]);
        VerificacionUbicacionItem::create(['verificacion_ubicacion_id' => $ronda->id, 'posicion_id' => $this->posicion->id, 'resultado' => 'pendiente', 'version' => 1]);
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia(10))->assertUnprocessable()->assertJsonPath('message', 'El folio o su posición participa en una verificación abierta. Finaliza la ronda antes de transferir.');
        $this->assertDatabaseCount('transferencias_clientes_materiales', 0);
    }

    public function test_opciones_sugiere_codigo_compatible_y_permisos_de_listado_csv_y_escritura(): void
    {
        $this->getJson('/api/materiales/transferencias-clientes/opciones?folio='.$this->origen->folio_id)->assertOk()->assertJsonPath('data.clientes.0.item_sugerido_id', $this->itemB->id)->assertJsonPath('data.disponible_bodega', 100);
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk();
        $this->getJson('/api/materiales/transferencias-clientes?cliente_id='.$this->clienteB->id.'&folio=FBB')->assertOk()->assertJsonCount(1, 'data');
        $csv = $this->get('/api/materiales/transferencias-clientes/exportar.csv?folio=FBB')->assertOk();
        $this->assertStringContainsString('FBB0000001', $csv->streamedContent());
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta, 'activo' => true]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/materiales/transferencias-clientes')->assertOk();
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia(10))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana, 'activo' => true]), 'sanctum');
        $this->getJson('/api/materiales/transferencias-clientes')->assertForbidden();
        $this->getJson('/api/materiales/transferencias-clientes/exportar.csv')->assertForbidden();
    }

    public function test_transferencia_inversa_genera_tercer_folio_y_encadena_kardex(): void
    {
        $t = $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk()->json('data');
        $datos = $this->datosTransferencia();
        $datos['cliente_destino_id'] = $this->clienteA->id;
        $datos['item_destino_id'] = $this->itemA->id;
        // El folio original permanece en el correlativo de A, sin reutilizar números retirados.
        DB::table('correlativos_materiales_clientes')->insert(['cliente_id' => $this->clienteA->id, 'ultimo_numero' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $inversa = $this->postJson($this->rutaTransferencia($t['folio_destino']['id']), $datos)->assertOk()->assertJsonPath('data.folio_destino.numero_folio', 'FAA0000002')->json('data');
        $this->assertDatabaseCount('transferencias_clientes_materiales', 2);
        $this->assertDatabaseCount('movimientos_inventario_materiales', 4);
        $this->getJson('/api/materiales/kardex?folio_id='.$t['folio_destino']['id'])->assertOk()->assertJsonFragment(['otro_folio' => 'FAA0000001'])->assertJsonFragment(['otro_folio' => 'FAA0000002']);
        $this->assertSame($this->posicion->id, UbicacionActual::where('folio_id', $inversa['folio_destino']['id'])->value('posicion_id'));
    }

    public function test_etiqueta_destino_conserva_evidencia_y_reimpresion_y_lectura_antigua_advierte(): void
    {
        $t = $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk()->json('data');
        $this->getJson('/api/movimientos/consultar-folio?numero_folio=FAA0000001')->assertOk()->assertJsonPath('data.disponible_ubicacion', false)->assertJsonPath('data.mensaje_disponibilidad', ServicioTransferenciaClienteMaterial::mensajeEtiquetaAntigua($this->origen->folio_id));
        $this->getJson('/api/movimientos/folios-materiales-disponibles?prefijo=FAA0000001&camara_id='.$this->camara->id)->assertUnprocessable()->assertSee('Reemplaza la etiqueta');
        $perfil = PerfilImpresionEtiqueta::create(['codigo' => 'TR-100', 'nombre' => 'Transferencia 100x80', 'fabricante' => 'Zebra', 'modelo' => 'ZT', 'lenguaje' => 'zpl', 'dpi' => 203, 'ancho_mm' => 100, 'alto_mm' => 80, 'orientacion' => 'vertical', 'predeterminado' => true, 'activo' => true, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        $datos = ['operacion_id' => (string) Str::uuid(), 'perfil_id' => $perfil->id, 'formato' => 'zpl', 'simbologia' => 'code128', 'canal' => 'oficina_descarga', 'copias' => 1, 'folio_ids' => [$t['folio_destino']['id']]];
        $ruta = '/api/materiales/transferencias-clientes/'.$t['id'].'/etiquetas';
        $this->postJson($ruta, $datos)->assertOk()->assertSee('FBB0000001');
        $this->postJson($ruta, $datos)->assertOk();
        $this->assertDatabaseCount('trabajos_impresion_materiales', 1);
        $trabajo = TrabajoImpresionMaterial::firstOrFail();
        $this->assertSame([$t['folio_destino']['id']], $trabajo->folios->pluck('folio_id')->all());
        $this->assertSame($this->origen->fecha_vencimiento->toDateString(), $trabajo->contenido_snapshot[0]['fecha_vencimiento']);
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['folio_ids'] = [$this->origen->folio_id];
        $this->postJson($ruta, $datos)->assertUnprocessable();
        $datos['folio_ids'] = [$t['folio_destino']['id']];
        $this->postJson($ruta, $datos)->assertUnprocessable()->assertSee('motivo');
        $datos['motivo_reimpresion'] = 'Etiqueta dañada en el pallet.';
        $this->postJson($ruta, $datos)->assertOk();
        $this->assertDatabaseCount('trabajos_impresion_materiales', 2);
        $this->assertSame(1, FolioTrabajoImpresionMaterial::where('es_reimpresion', true)->count());
    }

    public function test_correlativo_reutiliza_folio_liberado_del_cliente_destino(): void
    {
        DB::table('correlativos_materiales_clientes')->insert(['cliente_id' => $this->clienteB->id, 'ultimo_numero' => 12, 'created_at' => now(), 'updated_at' => now()]);
        FolioMaterialLiberado::create(['cliente_id' => $this->clienteB->id, 'numero_folio' => 'FBB0000007', 'numero_correlativo' => 7,
            'recepcion_material_id_original' => (string) Str::uuid(), 'motivo' => 'Folio liberado por eliminación anterior.', 'liberado_por_user_id' => $this->admin->id]);
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk()->assertJsonPath('data.folio_destino.numero_folio', 'FBB0000007');
        $this->assertDatabaseMissing('folios_materiales_liberados', ['numero_folio' => 'FBB0000007']);
        $this->assertSame(12, (int) DB::table('correlativos_materiales_clientes')->where('cliente_id', $this->clienteB->id)->value('ultimo_numero'));
    }

    public function test_fallo_tras_descontar_restaura_saldo_ubicacion_y_estado(): void
    {
        $correlativo = \Mockery::mock(ServicioCorrelativoFolioMaterial::class);
        $correlativo->shouldReceive('siguiente')->once()->andThrow(new \DomainException('No se pudo generar el correlativo.'));
        $this->app->instance(ServicioCorrelativoFolioMaterial::class, $correlativo);
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertUnprocessable();
        $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
        $this->assertTrue($this->origen->folio->fresh()->activo);
        $this->assertSame($this->posicion->id, UbicacionActual::where('folio_id', $this->origen->folio_id)->value('posicion_id'));
        $this->assertDatabaseCount('transferencias_clientes_materiales', 0);
        $this->assertDatabaseCount('movimientos_inventario_materiales', 0);
    }

    public function test_fifo_del_cliente_destino_reserva_el_transferido_antes_del_stock_nuevo(): void
    {
        $t = $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk()->json('data');
        $nuevo = Folio::create(['temporada_id' => $this->origen->folio->temporada_id, 'numero_folio' => 'FBB0000002', 'tipo_bulto' => 'material', 'activo' => true, 'fecha_ingreso' => now(), 'estado_operacional' => 'pendiente_ubicacion']);
        FolioMaterial::create(['folio_id' => $nuevo->id, 'item_material_id' => $this->itemB->id, 'cantidad_inicial' => 100, 'cantidad_actual' => 100, 'cantidad_reservada' => 0,
            'unidad_medida' => 'rollos', 'categoria_operacional' => 'insumo', 'fecha_vencimiento' => $this->origen->fecha_vencimiento]);
        $posicion = Posicion::create(['camara_id' => $this->camara->id, 'banda' => 1, 'posicion' => 2, 'nivel' => 1, 'etiqueta' => 'B01-P02-N1']);
        UbicacionActual::create(['folio_id' => $nuevo->id, 'camara_id' => $this->camara->id, 'posicion_id' => $posicion->id, 'ubicado_at' => now()]);
        $seleccionados = [];
        DB::transaction(function () use (&$seleccionados) {
            $faltante = app(ServicioReservaFifoMaterial::class)->reservar($this->itemB->id, 10,
                function ($folio) use (&$seleccionados) {
                    $seleccionados[] = $folio->folio_id;
                });
            $this->assertSame(0.0, $faltante);
        });
        $this->assertSame([$t['folio_destino']['id']], $seleccionados);
    }

    public function test_proceso_de_vencimientos_bloquea_el_folio_transferido(): void
    {
        $t = $this->postJson($this->rutaTransferencia(), $this->datosTransferencia())->assertOk()->json('data');
        $this->travel(12)->days();
        try {
            $this->artisan('materiales:procesar-vencimientos')->assertSuccessful();
            $destino = FolioMaterial::with('folio')->findOrFail($t['folio_destino']['id']);
            $this->assertTrue($destino->bloqueado_por_vencimiento);
            $this->assertSame(EstadoOperacionalFolio::Bloqueado, $destino->folio->estado_operacional);
        } finally {
            $this->travelBack();
        }
    }

    public function test_reposicion_cambia_por_cliente_y_transferencia_no_es_consumo(): void
    {
        foreach ([$this->itemA, $this->itemB] as $item) {
            $item->update(['stock_minimo' => 80, 'punto_reorden' => 80, 'stock_maximo' => 150]);
        }
        $this->postJson($this->rutaTransferencia(), $this->datosTransferencia(40))->assertOk();
        $filas = app(ServicioReposicionMaterial::class)->filas([], false)->keyBy('id');
        $this->assertSame(60.0, $filas[$this->itemA->id]['disponible']);
        $this->assertSame(40.0, $filas[$this->itemB->id]['disponible']);
        $this->assertSame(90.0, $filas[$this->itemA->id]['cantidad_sugerida']);
        $this->assertSame(110.0, $filas[$this->itemB->id]['cantidad_sugerida']);
        $this->assertSame(0.0, $filas[$this->itemA->id]['consumo_periodo']);
        $this->assertSame(0.0, $filas[$this->itemB->id]['consumo_periodo']);
    }
}
