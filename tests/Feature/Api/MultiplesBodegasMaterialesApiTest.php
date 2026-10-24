<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\AlmacenMaterial;
use App\Models\Camara;
use App\Models\ClienteMaterial;
use App\Models\DestinoMaterial;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\PerfilAcceso;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\User;
use App\Services\Materiales\ServicioProcesamientoVencimientosMaterial;
use App\Services\Materiales\StockMaterialEnPlano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiplesBodegasMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private string $tabletToken;

    private string $sesion;

    private Folio $folio;

    private ItemMaterial $item;

    private DestinoMaterial $destino;

    private Camara $camara;

    private Posicion $posicion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-23T18:00:00Z'));
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->token = $this->admin->createToken('oficina', ['oficina'])->plainTextToken;
        $cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        $this->item = ItemMaterial::create(['cliente_material_id' => $cliente->id, 'codigo' => 'FILM-REG', 'nombre' => 'Film de prueba', 'categoria' => 'Embalaje', 'categoria_operacional' => 'insumo', 'unidad_medida' => 'kg', 'origen_sistema' => 'manual', 'activo' => true, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        $this->destino = DestinoMaterial::create(['nombre' => 'Packing de prueba', 'centro_costo' => 'PACK-REG', 'origen_sistema' => 'manual', 'activo' => true, 'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        $camara = Camara::create(['codigo' => 'MAT-REG', 'nombre' => 'Bodega prueba', 'contenido' => ContenidoCamara::Materiales]);
        $posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $this->folio = Folio::create(['temporada_id' => $cliente->temporada->temporada_id, 'numero_folio' => 'FRG0000001', 'tipo_bulto' => 'material', 'estado_operacional' => 'pendiente_ubicacion', 'fecha_ingreso' => now()->subDays(10), 'activo' => true, 'origen_sistema' => 'recepcion_materiales', 'estado_integracion' => 'no_vinculado']);
        FolioMaterial::create(['folio_id' => $this->folio->id, 'item_material_id' => $this->item->id, 'categoria_operacional' => 'insumo', 'cantidad_inicial' => 20, 'cantidad_actual' => 20, 'cantidad_reservada' => 0, 'unidad_medida' => 'kg']);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-REG', 'nombre' => 'Tablet prueba', 'activo' => true]);
        $this->tabletToken = $this->admin->crearTokenParaDispositivo($dispositivo, 'tablet-reg')->plainTextToken;
        $this->withToken($this->tabletToken);
        $this->sesion = $this->postJson('/api/camaras/'.$camara->id.'/sesiones')->assertCreated()->json('data.id');
        $this->postJson('/api/movimientos/ubicar', ['operacion_id' => (string) Str::uuid(), 'numero_folio' => $this->folio->numero_folio, 'tipo_bulto' => 'material', 'camara_destino_id' => $camara->id, 'posicion_destino_id' => $posicion->id, 'sesion_destino_id' => $this->sesion, 'version_destino_conocida' => (int) $camara->fresh()->version_plano, 'generado_dispositivo_at' => now()->toAtomString()])->assertOk();
        $this->camara = $camara;
        $this->posicion = $posicion;
        $this->postJson('/api/sesiones/'.$this->sesion.'/cerrar')->assertOk();
        $this->withToken($this->token);
    }

    private function bodega(bool $plano = false, string $codigo = 'BOD-SEC'): string
    {
        return $this->postJson('/api/materiales/bodegas', ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => $codigo, 'nombre' => 'Bodega '.$codigo,
            'activo' => true, 'requiere_ubicacion_fisica' => $plano, 'motivo' => 'Habilitar recinto de prueba'])->assertCreated()->json('data.id');
    }

    private function central(): string
    {
        return AlmacenMaterial::where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->value('id');
    }

    private function saldo(string $bodega, ?Folio $folio = null): ?SaldoMaterialAlmacen
    {
        return SaldoMaterialAlmacen::where('folio_id', ($folio ?? $this->folio)->id)->where('almacen_material_id', $bodega)->first();
    }

    private function traslado(string $origen, string $destino, float $cantidad, array $c = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'tipo' => 'transferencia', 'folio_id' => $this->folio->id, 'almacen_origen_id' => $origen, 'almacen_destino_id' => $destino, 'cantidad' => $cantidad,
            'version_origen' => $this->saldo($origen)?->version ?? 0, 'version_destino' => $this->saldo($destino)?->version ?? 0, 'motivo' => 'Traslado físico verificado'], ...$c];
    }

    private function mover(string $origen, string $destino, float $cantidad, array $c = []): array
    {
        return $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($origen, $destino, $cantidad, $c))->assertCreated()->json('data');
    }

    private function orden(string $origen, float $cantidad = 6): array
    {
        return $this->postJson('/api/materiales/despachos', ['operacion_id' => (string) Str::uuid(), 'almacen_origen_id' => $origen, 'destino_material_id' => $this->destino->id, 'items' => [['item_material_id' => $this->item->id, 'cantidad' => $cantidad]]])->assertCreated()->assertJsonPath('data.almacen_origen.id', $origen)->json('data');
    }

    private function proyeccion(float $total, float $reservada): void
    {
        $m = FolioMaterial::findOrFail($this->folio->id);
        $this->assertEquals($total, (float) $m->cantidad_actual);
        $this->assertEquals($reservada, (float) $m->cantidad_reservada);
        $this->assertEquals($total, (float) SaldoMaterialAlmacen::where('folio_id', $m->folio_id)->sum('cantidad_actual'));
        $this->assertEquals($reservada, (float) SaldoMaterialAlmacen::where('folio_id', $m->folio_id)->sum('cantidad_reservada'));
    }

    public function test_traslado_reintento_versiones_y_reservas_conservan_total_y_auditoria(): void
    {
        $b = $this->bodega();
        $d = $this->traslado($this->central(), $b, 8);
        $this->postJson('/api/materiales/almacenes/movimientos', $d)->assertCreated();
        $this->postJson('/api/materiales/almacenes/movimientos', $d)->assertCreated();
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 1);
        $this->proyeccion(20, 0);
        $this->assertEquals(12, $this->saldo($this->central())->cantidad_actual);
        $this->assertEquals(8, $this->saldo($b)->cantidad_actual);
        $this->postJson('/api/materiales/almacenes/movimientos', [...$d, 'cantidad' => 7])->assertConflict();
        $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($this->central(), $b, 1, ['version_origen' => 0]))->assertConflict();
        $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($this->central(), $b, 1, ['version_destino' => 0]))->assertConflict();
        $this->orden($b, 6);
        $this->proyeccion(20, 6);
        $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($b, $this->central(), 3, ['camara_destino_id' => $this->camara->id, 'posicion_destino_id' => $this->posicion->id]))->assertUnprocessable();
        $this->proyeccion(20, 6);
        $this->getJson('/api/materiales/inventario?almacen_origen_id='.$b)->assertOk()->assertJsonPath('data.0.cantidad_actual', '8.000');
        $this->assertSame(['actual' => '12.000', 'reservada' => '0.000'], app(StockMaterialEnPlano::class)->cantidades($this->folio->id, $this->camara->id, $this->posicion->id));
    }

    public function test_entrega_parcial_sin_plano_y_cancelacion_liberan_la_bodega_exacta(): void
    {
        $b = $this->bodega();
        $this->mover($this->central(), $b, 8);
        $orden = $this->orden($b, 6);
        $this->assertEquals(0, $this->saldo($this->central())->cantidad_reservada);
        $this->assertEquals(6, $this->saldo($b)->cantidad_reservada);
        $this->withToken($this->tabletToken);
        $url = '/api/materiales/despachos/'.$orden['id'].'/retirar';
        $d = ['operacion_id' => (string) Str::uuid(), 'retiros' => [['folio_id' => $this->folio->id, 'cantidad' => 2]]];
        $this->postJson($url, $d)->assertOk()->assertJsonPath('data.estado', 'parcial')->assertJsonPath('data.items.0.retiros.0.camara', null);
        $this->postJson($url, $d)->assertOk();
        $this->assertDatabaseCount('retiros_materiales', 1);
        $this->proyeccion(20, 4);
        $this->assertEquals(6, $this->saldo($b)->cantidad_actual);
        $this->assertEquals(2, $this->saldo($this->destino->id)->cantidad_actual);
        $this->withToken($this->token);
        $this->postJson('/api/materiales/despachos/'.$orden['id'].'/cancelar', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Cancelar el saldo aún no entregado'])->assertOk();
        $this->proyeccion(20, 0);
        $this->assertEquals(0, $this->saldo($b)->cantidad_reservada);
        $this->assertEquals(12, $this->saldo($this->central())->cantidad_actual);
        $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $this->folio->id, 'camara_id' => $this->camara->id]);
    }

    public function test_sin_stock_en_origen_no_reserva_otra_bodega_y_cliente_anterior_sigue_central(): void
    {
        $b = $this->bodega();
        $antes = DB::table('reservas_materiales')->count();
        $this->postJson('/api/materiales/despachos', ['operacion_id' => (string) Str::uuid(), 'almacen_origen_id' => $b, 'destino_material_id' => $this->destino->id, 'items' => [['item_material_id' => $this->item->id, 'cantidad' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('reservas_materiales', $antes);
        $this->proyeccion(20, 0);
        $o = $this->postJson('/api/materiales/despachos', ['operacion_id' => (string) Str::uuid(), 'destino_material_id' => $this->destino->id, 'items' => [['item_material_id' => $this->item->id, 'cantidad' => 1]]])->assertCreated()->assertJsonPath('data.almacen_origen.id', $this->central())->json('data');
        $this->withToken($this->tabletToken);
        $this->postJson('/api/materiales/despachos/'.$o['id'].'/retirar', ['operacion_id' => (string) Str::uuid(), 'retiros' => [['folio_id' => $this->folio->id, 'cantidad' => 1]]])->assertUnprocessable();
        $this->proyeccion(20, 1);
    }

    public function test_bodega_inactiva_centro_costo_y_folio_vencido_no_son_origen(): void
    {
        $b = $this->bodega();
        $this->mover($this->central(), $b, 8);
        foreach ([$this->destino->id, $b] as $id) {
            if ($id === $b) {
                DB::table('destinos_materiales')->where('id', $b)->update(['activo' => false]);
            }
            $this->postJson('/api/materiales/despachos', ['operacion_id' => (string) Str::uuid(), 'almacen_origen_id' => $id, 'destino_material_id' => $this->destino->id, 'items' => [['item_material_id' => $this->item->id, 'cantidad' => 1]]])->assertUnprocessable();
        }
        DB::table('destinos_materiales')->where('id', $b)->update(['activo' => true]);
        DB::table('folios_materiales')->where('folio_id', $this->folio->id)->update(['fecha_vencimiento' => now()->subDay()->toDateString()]);
        $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($b, $this->central(), 1, ['camara_destino_id' => $this->camara->id, 'posicion_destino_id' => $this->posicion->id]))->assertUnprocessable();
        $this->proyeccion(20, 0);
    }

    public function test_plano_nuevo_exige_traslado_completo_y_sesion_al_entregar(): void
    {
        $b = $this->bodega(true);
        $cam = Camara::create(['codigo' => 'MAT-SEC', 'nombre' => 'Segunda bodega', 'contenido' => ContenidoCamara::Materiales]);
        $pos = Posicion::create(['camara_id' => $cam->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $ubic = ['camara_destino_id' => $cam->id, 'posicion_destino_id' => $pos->id];
        $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($this->central(), $b, 8, $ubic))->assertUnprocessable();
        $this->proyeccion(20, 0);
        $this->assertNull($this->saldo($b));
        $this->mover($this->central(), $b, 20, $ubic);
        $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $this->folio->id, 'camara_id' => $cam->id, 'posicion_id' => $pos->id]);
        $o = $this->orden($b, 2);
        $this->assertSame($cam->id, $o['items'][0]['sugerencias_fifo'][0]['camara']['id']);
        $this->withToken($this->tabletToken);
        $ses = $this->postJson('/api/camaras/'.$cam->id.'/sesiones')->assertCreated()->json('data.id');
        $this->postJson('/api/materiales/despachos/'.$o['id'].'/retirar', ['operacion_id' => (string) Str::uuid(), 'retiros' => [['folio_id' => $this->folio->id, 'cantidad' => 2, 'sesion_estiba_id' => $ses]]])->assertOk()->assertJsonPath('data.estado', 'completado');
        $this->proyeccion(20, 0);
        $this->assertEquals(18, $this->saldo($b)->cantidad_actual);
    }

    public function test_estiba_abierta_impide_traslado_de_oficina(): void
    {
        $b = $this->bodega();
        $this->withToken($this->tabletToken);
        $this->postJson('/api/camaras/'.$this->camara->id.'/sesiones')->assertCreated();
        $this->withToken($this->token);
        $this->postJson('/api/materiales/almacenes/movimientos', $this->traslado($this->central(), $b, 1))->assertUnprocessable();
        $this->proyeccion(20, 0);
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
    }

    public function test_reasignacion_por_vencimiento_conserva_bodega_y_no_toma_stock_central(): void
    {
        $b = $this->bodega();
        $this->mover($this->central(), $b, 8);
        $o = $this->orden($b, 6);
        // Otro lote vigente queda solo en Central; no debe cubrir una orden de la secundaria.
        $cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        $otro = Folio::create(['temporada_id' => $this->folio->temporada_id, 'numero_folio' => 'FRG0000002', 'tipo_bulto' => 'material', 'estado_operacional' => 'pendiente_ubicacion', 'fecha_ingreso' => now(), 'activo' => true, 'origen_sistema' => 'recepcion_materiales', 'estado_integracion' => 'no_vinculado']);
        FolioMaterial::create(['folio_id' => $otro->id, 'item_material_id' => $this->item->id, 'categoria_operacional' => 'insumo', 'cantidad_inicial' => 10, 'cantidad_actual' => 10, 'cantidad_reservada' => 0, 'unidad_medida' => 'kg']);
        $this->saldo($this->central(), $otro)->update(['camara_id' => $this->camara->id, 'posicion_id' => null]);
        DB::table('folios_materiales')->where('folio_id', $this->folio->id)->update(['fecha_vencimiento' => now()->subDay()->toDateString()]);
        $r = app(ServicioProcesamientoVencimientosMaterial::class)->procesar();
        $this->assertSame(0, $r['reservas_reasignadas']);
        $this->assertEquals(6, $r['lineas_insuficientes'][0]['cantidad']);
        $this->assertEquals(0, $this->saldo($this->central(), $otro)->cantidad_reservada);
        $this->proyeccion(20, 0);
    }

    public function test_permiso_de_traslado_no_habilita_consumo_ni_centros_de_costo(): void
    {
        $b = $this->bodega();
        $p = PerfilAcceso::create(['codigo' => 'TRASLADO', 'nombre' => 'Traslados físicos', 'rol_base' => 'supervisor_materiales', 'modulos' => ['materiales.inventario'], 'modulos_tablet' => [], 'permisos_materiales' => ['bodegas.trasladar'], 'activo' => true]);
        $u = User::factory()->create(['rol' => RolUsuario::SupervisorMateriales, 'activo' => true, 'perfil_acceso_id' => $p->id]);
        $this->withToken($u->createToken('traslados', ['oficina'])->plainTextToken);
        $this->mover($this->central(), $b, 1);
        $this->postJson('/api/materiales/almacenes/movimientos',$this->traslado($b,$this->destino->id,1))->assertForbidden();
        $this->postJson('/api/materiales/almacenes/movimientos',[...$this->traslado($b,$this->destino->id,1), 'tipo' => 'consumo'])->assertForbidden();
        $this->postJson('/api/materiales/bodegas',['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => 'BOD-X', 'nombre' => 'No autorizado', 'activo' => true, 'requiere_ubicacion_fisica' => false, 'motivo' => 'No tiene permiso de catálogo'])->assertForbidden();
        $this->proyeccion(20,0);
    }
}
