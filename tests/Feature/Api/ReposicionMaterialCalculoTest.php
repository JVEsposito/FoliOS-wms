<?php

namespace Tests\Feature\Api;

use App\Models\AlmacenMaterial;
use App\Models\ItemMaterial;
use App\Models\NotificacionOperacional;
use App\Services\Materiales\ServicioReposicionMaterial;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\VerificacionMaterialesFixture;
use Tests\TestCase;

class ReposicionMaterialCalculoTest extends TestCase
{
    use VerificacionMaterialesFixture { setUp as fixtureSetUp; }

    private string $item;

    private string $bodega;

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(15, 0));
        Schema::create('temporadas_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('temporada_id');
        });
        Schema::create('clientes_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('temporada_material_id');
            $t->string('nombre');
            $t->boolean('activo');
        });
        Schema::table('items_materiales', function (Blueprint $t) {
            $t->uuid('cliente_material_id')->nullable();
            $t->string('codigo')->default('SIN-CLIENTE');
            $t->string('nombre')->default('Insumo');
            $t->string('categoria')->default('Insumos');
            $t->boolean('activo')->default(true);
            $t->timestamps();
        });
        Schema::table('folios', fn (Blueprint $t) => $t->string('estado_operacional')->default('disponible'));
        Schema::table('folios_materiales', fn (Blueprint $t) => $t->date('fecha_vencimiento')->nullable());
        Schema::table('movimientos_almacenes_materiales', function (Blueprint $t) {
            $t->uuid('item_material_id');
            $t->uuid('almacen_origen_id')->nullable();
            $t->uuid('almacen_destino_id')->nullable();
            $t->uuid('retiro_material_id')->nullable();
            $t->string('tipo');
            $t->decimal('cantidad', 14, 3);
            $t->timestamp('ocurrido_at');
            $t->text('motivo')->nullable();
        });
        Schema::create('movimientos_inventario_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('item_material_id');
            $t->uuid('retiro_material_id')->nullable();
            $t->string('tipo');
            $t->decimal('cantidad', 14, 3);
            $t->json('metadatos')->nullable();
            $t->text('motivo')->nullable();
            $t->timestamp('ocurrido_at');
        });
        Schema::create('destinos_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('codigo');
        });
        Schema::create('notificaciones_operacionales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('clave')->unique();
            foreach (['tipo', 'audiencia_tipo', 'audiencia_valor', 'severidad', 'titulo', 'mensaje'] as $c) {
                $t->string($c);
            } $t->json('datos');
            $t->timestamps();
        });
        (require database_path('migrations/2026_10_08_180000_agregar_reposicion_materiales.php'))->up();
        $tm = (string) Str::uuid();
        $cliente = (string) Str::uuid();
        $this->item = (string) Str::uuid();
        $this->bodega = (string) Str::uuid();
        DB::table('temporadas_materiales')->insert(['id' => $tm, 'temporada_id' => $this->temporadaId]);
        DB::table('clientes_materiales')->insert(['id' => $cliente, 'temporada_material_id' => $tm, 'nombre' => 'Cliente', 'activo' => true]);
        DB::table('items_materiales')->insert(['id' => $this->item, 'cliente_material_id' => $cliente, 'codigo' => 'FILM', 'nombre' => 'Film', 'unidad_medida' => 'unidad']);
        DB::table('destinos_materiales')->insert(['id' => $this->bodega, 'codigo' => AlmacenMaterial::CODIGO_BODEGA_CENTRAL]);
    }

    private function stock(float $cantidad, float $reserva = 0, ?string $almacen = null, ?string $bloqueo = null, ?string $vence = null): string
    {
        $folio = $this->folioEn($this->posicion(), 'F-'.Str::random(10), $cantidad);
        DB::table('folios_materiales')->where('folio_id', $folio)->update(['item_material_id' => $this->item, 'fecha_vencimiento' => $vence, 'motivo_bloqueo' => $bloqueo]);
        DB::table('saldos_materiales_almacenes')->where('folio_id', $folio)->update(['almacen_material_id' => $almacen ?? $this->bodega, 'cantidad_reservada' => $reserva]);

        return $folio;
    }

    private function fila(int $dias = 30): array
    {
        return app(ServicioReposicionMaterial::class)->filas(['item_id' => $this->item, 'dias' => $dias], false)->sole();
    }

    private function movimiento(string $folio, string $tipo, float $cantidad, ?string $origen, ?string $destino = null, string $fuente = 'almacen', ?string $retiro = null, ?string $fecha = null): string
    {
        $id = (string) Str::uuid();
        $datos = ['id' => $id, 'folio_id' => $folio, 'item_material_id' => $this->item, 'tipo' => $tipo, 'cantidad' => $cantidad, 'retiro_material_id' => $retiro, 'ocurrido_at' => $fecha ?? now()];
        if ($fuente === 'almacen') {
            $datos += ['almacen_origen_id' => $origen, 'almacen_destino_id' => $destino];
        }
        DB::table($fuente === 'almacen' ? 'movimientos_almacenes_materiales' : 'movimientos_inventario_materiales')->insert($datos);

        return $id;
    }

    public function test_disponible_central_descuenta_reservas_bloqueados_y_vencidos_sin_contar_packing(): void
    {
        $this->stock(100, 20);
        $this->stock(30, 5, bloqueo: 'Calidad');
        $this->stock(20, 10, vence: '2026-10-07');
        $this->stock(500, almacen: (string) Str::uuid());
        $f = $this->fila();
        $this->assertSame(80.0, $f['disponible']);
        $this->assertSame(35.0, $f['reservado']);
        $this->assertSame(50.0, $f['bloqueado_vencido']);
        $this->assertSame(650.0, $f['total_empresa']);
        $this->assertSame('sin_niveles', $f['estado']);
        $this->assertCount(0, app(ServicioReposicionMaterial::class)->filas());
    }

    public function test_columnas_informativas_del_catalogo_usan_stock_central_incluso_para_items_inactivos(): void
    {
        $this->stock(100, 20);
        $this->stock(30, bloqueo: 'Calidad');
        $this->stock(20, vence: '2026-10-07');
        $this->stock(500, almacen: (string) Str::uuid());
        DB::table('items_materiales')->where('id', $this->item)->update(['activo' => false, 'stock_minimo' => 90]);
        $item = ItemMaterial::with('cliente.temporada')->findOrFail($this->item);
        $datos = app(ServicioReposicionMaterial::class)->datosCatalogo(collect([$item]));
        $this->assertSame(['stock_bodega' => 150.0, 'disponible_bodega' => 80.0, 'estado_reposicion' => 'bajo_minimo'], $datos[$item->id]);
    }

    public function test_consumo_suma_fuentes_resta_devoluciones_y_no_duplica_espejos_ni_cuenta_regresos(): void
    {
        $folio = $this->stock(60);
        $packing = (string) Str::uuid();
        $retiro = (string) Str::uuid();
        $this->movimiento($folio, 'entrega', 4, $this->bodega, $packing);
        $this->movimiento($folio, 'consumo', 3, $this->bodega);
        $this->movimiento($folio, 'entrega', 2, $this->bodega, $packing, retiro: $retiro);
        $this->movimiento($folio, 'consumo_transformacion', -5, null, fuente: 'inventario');
        $this->movimiento($folio, 'despacho', -2, null, fuente: 'inventario', retiro: $retiro);
        $this->movimiento($folio, 'consumo_centro_costo', -3, null, fuente: 'inventario');
        $this->movimiento($folio, 'devolucion', 2, $packing, $this->bodega);
        $this->movimiento($folio, 'ajuste', -200, $this->bodega);
        $this->movimiento($folio, 'transferencia', 99, $packing, $this->bodega);
        $this->movimiento($folio, 'consumo', 99, $packing);
        $this->movimiento($folio, 'transferencia', 6, $this->bodega, $packing);
        $this->movimiento($folio, 'despacho', -4, null, fuente: 'inventario');
        $reversa = $this->movimiento($folio, 'reversa_transformacion', 2, null, fuente: 'inventario');
        DB::table('movimientos_inventario_materiales')->where('id', $reversa)->update(['metadatos' => json_encode(['sentido' => 'restauracion_entrada'])]);
        $this->movimiento($folio, 'reversa_transformacion', -5, null, fuente: 'inventario');
        $f = $this->fila();
        $this->assertSame(20.0, $f['consumo_periodo']);
        $this->assertSame(0.666667, $f['consumo_diario']);
        $this->assertSame(90.0, $f['dias_cobertura']);
        $this->assertSame(60.0, $f['disponible']);
    }

    public function test_periodos_folios_agotados_y_detalle_semanal_incluyen_todo_el_consumo_valido(): void
    {
        $folio = $this->stock(0);
        $this->movimiento($folio, 'consumo', 30, $this->bodega, fecha: now()->subDays(45)->toDateTimeString());
        DB::table('folios')->where('id', $folio)->update(['activo' => false]);
        $this->assertSame(0.0, $this->fila()['consumo_periodo']);
        $this->assertSame(30.0, $this->fila(60)['consumo_periodo']);
        $d = app(ServicioReposicionMaterial::class)->detalle(ItemMaterial::findOrFail($this->item), 60);
        $this->assertSame(30.0, (float) $d['semanas']->sum('consumo'));
        $this->assertSame(1, $d['movimientos']->total());
        $this->assertNull($this->fila()['dias_cobertura']);
        $this->assertSame('sin consumo', $this->fila()['cobertura_etiqueta']);
    }

    public function test_detalle_agrupa_semana_por_fecha_de_chile_y_no_fecha_utc(): void
    {
        $folio = $this->stock(10);
        $this->movimiento($folio, 'consumo', 5, $this->bodega, fecha: '2026-10-05 02:30:00'); // Domingo 04 a las 23:30 en Chile.
        $d = app(ServicioReposicionMaterial::class)->detalle(ItemMaterial::findOrFail($this->item), 30);
        $this->assertSame(5.0, $d['semanas']->firstWhere('semana', '2026-09-28')['consumo']);
        $this->assertSame(0.0, $d['semanas']->firstWhere('semana', '2026-10-05')['consumo']);
    }

    public function test_devolucion_neta_sin_salidas_no_produce_cobertura_negativa(): void
    {
        $folio = $this->stock(10);
        $this->movimiento($folio, 'devolucion', 5, (string) Str::uuid(), $this->bodega);
        $this->assertSame(-5.0, $this->fila()['consumo_periodo']);
        $this->assertNull($this->fila()['dias_cobertura']);
        $this->assertSame('sin consumo', $this->fila()['cobertura_etiqueta']);
    }

    public function test_resumen_calcula_media_y_omite_items_sin_consumo(): void
    {
        DB::table('items_materiales')->where('id', $this->item)->update(['stock_minimo' => 10, 'punto_reorden' => 20, 'stock_maximo' => 100]);
        $folio = $this->stock(60);
        $this->movimiento($folio, 'consumo', 30, $this->bodega);
        $resumen = app(ServicioReposicionMaterial::class)->resumen();
        $this->assertSame(60.0, $resumen['cobertura_media']);
        $this->assertCount(1, $resumen['menor_cobertura']);
        $this->assertSame($this->item, $resumen['menor_cobertura'][0]['id']);
        $this->assertSame(0, $resumen['quiebre']);
    }

    #[DataProvider('bordes')]
    public function test_estados_y_sugerencias_en_bordes(float $disponible, ?float $min, ?float $reorden, ?float $max, string $estado, ?float $sugerencia): void
    {
        DB::table('items_materiales')->where('id', $this->item)->update(['stock_minimo' => $min, 'punto_reorden' => $reorden, 'stock_maximo' => $max]);
        $this->stock($disponible);
        $f = $this->fila();
        $this->assertSame($estado, $f['estado']);
        $this->assertSame($sugerencia, $f['cantidad_sugerida']);
    }

    public static function bordes(): array
    {
        return [[0, 10, 20, 100, 'quiebre', 100], [9.999, 10, 20, 100, 'bajo_minimo', 91], [10, 10, 20, 100, 'reponer', 90],
            [20, 10, 20, 100, 'reponer', 80], [20.001, 10, 20, 100, 'normal', 80], [100, 10, 20, 100, 'normal', 0],
            [100.001, 10, 20, 100, 'sobre_maximo', 0], [10.001, 10, 20, null, 'reponer', 30], [0, 0, 0, null, 'quiebre', 0],
            [0, null, null, null, 'sin_niveles', null], [1, 10, null, null, 'bajo_minimo', null]];
    }

    public function test_notificacion_idempotente_se_repite_solo_despues_de_recuperacion(): void
    {
        DB::table('items_materiales')->where('id', $this->item)->update(['stock_minimo' => 10, 'punto_reorden' => 20, 'stock_maximo' => 30]);
        $folio = $this->stock(5);
        $servicio = app(ServicioReposicionMaterial::class);
        $this->assertTrue($servicio->recalcularItem($this->item));
        $this->assertFalse($servicio->recalcularItem($this->item));
        $this->assertSame(1, NotificacionOperacional::count());
        $this->assertSame('supervisor_materiales', NotificacionOperacional::sole()->audiencia_valor);
        DB::table('saldos_materiales_almacenes')->where('folio_id', $folio)->update(['cantidad_actual' => 25]);
        $servicio->recalcularItem($this->item);
        DB::table('saldos_materiales_almacenes')->where('folio_id', $folio)->update(['cantidad_actual' => 5]);
        $servicio->recalcularItem($this->item);
        $this->assertSame(2, NotificacionOperacional::count());
        $this->assertFalse($servicio->recalcularItem($this->item));
        $this->assertSame(2, NotificacionOperacional::count());
        $this->artisan('materiales:recalcular-reposicion')->assertExitCode(0);
        $this->artisan('materiales:recalcular-reposicion')->assertExitCode(0);
        $this->assertSame(2, NotificacionOperacional::count());
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $folio, 'cantidad_actual' => 5]);
    }
}
