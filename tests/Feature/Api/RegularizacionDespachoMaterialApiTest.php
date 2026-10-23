<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\ClienteMaterial;
use App\Models\DestinoMaterial;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\OperacionRetiroMaterial;
use App\Models\PerfilAcceso;
use App\Models\Posicion;
use App\Models\RegularizacionDespachoMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegularizacionDespachoMaterialApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private string $tabletToken;

    private string $sesion;

    private Folio $folio;

    private ItemMaterial $item;

    private DestinoMaterial $destino;

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
        $this->withToken($this->token);
    }

    private function registro(array $cambios = []): array
    {
        return [...['fecha_despacho' => '2026-10-20T10:30:00-03:00', 'provisional' => true, 'motivo' => 'Entrega real con respaldo pendiente', 'motivo_autorizacion' => 'Conteo físico verificado por supervisión'], ...$cambios];
    }

    private function payload(array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'folio_id' => $this->folio->id, 'destino_material_id' => $this->destino->id, 'cantidad' => 4], ...$cambios];
    }

    private function crear(array $cambios = []): array
    {
        return $this->postJson('/api/materiales/despachos/directos', $this->payload(['registro' => $this->registro(), ...$cambios]))->assertCreated()->json('data');
    }

    private function regularizacion(array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1, 'numero_documento' => 'VALE-901', 'fecha_documento' => '2026-10-20', 'motivo' => 'Respaldo cotejado con la entrega original'], ...$cambios];
    }

    private function url(array $despacho, int $index = 0): string
    {
        return '/api/materiales/despachos/'.$despacho['id'].'/entregas/'.$despacho['entregas'][$index]['id'].'/regularizar';
    }

    private function inventario(): array
    {
        return collect(['folios', 'folios_materiales', 'saldos_materiales_almacenes', 'reservas_materiales', 'retiros_materiales', 'despachos_materiales', 'detalles_despacho_materiales', 'movimientos_almacenes_materiales', 'movimientos_inventario_materiales'])
            ->mapWithKeys(fn ($tabla) => [$tabla => DB::table($tabla)->orderBy($tabla === 'folios_materiales' ? 'folio_id' : 'id')->get()->toJson()])->all();
    }

    public function test_cliente_anterior_conserva_la_entrega_normal_y_su_reintento_original(): void
    {
        $datos = $this->payload();
        $despacho = $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated()->assertJsonPath('data.entregas.0.fecha_despacho', null)->assertJsonPath('data.entregas.0.estado', 'no_requerida')->json('data');
        $antes = $this->inventario();
        $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated()->assertJsonPath('data.id', $despacho['id']);
        $this->assertSame($antes, $this->inventario());
        $this->postJson($this->url($despacho), $this->regularizacion())->assertUnprocessable();
    }

    public function test_provisional_autorizada_entrega_una_vez_con_fechas_separadas(): void
    {
        $datos = $this->payload(['registro' => $this->registro()]);
        $despacho = $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated()->assertJsonPath('data.entregas.0.estado', 'pendiente')->assertJsonPath('data.entregas.0.numero_documento', null)->assertJsonPath('data.entregas.0.retroactivo', true)->json('data');
        $this->assertSame('2026-10-20T13:30:00+00:00', $despacho['entregas'][0]['fecha_despacho']);
        $this->assertSame('2026-10-26T02:59:59+00:00', $despacho['entregas'][0]['fecha_limite']);
        $this->assertSame($this->admin->id, $despacho['entregas'][0]['autorizado_por']['id']);
        $this->assertSame('2026-10-23 18:00:00', MovimientoAlmacenMaterial::where('tipo', 'entrega')->firstOrFail()->ocurrido_at->format('Y-m-d H:i:s'));
        $antes = $this->inventario();
        $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated();
        $this->assertSame($antes, $this->inventario());
        $datos['registro']['motivo_autorizacion'] = 'Motivo diferente';
        $this->postJson('/api/materiales/despachos/directos', $datos)->assertConflict();
        $this->assertSame($antes, $this->inventario());
        $this->assertEquals(20, FolioMaterial::firstOrFail()->cantidad_actual);
    }

    public function test_regularizar_con_consumo_posterior_no_toca_existencias_reservas_retiros_ni_snapshot(): void
    {
        $despacho = $this->crear();
        $this->postJson('/api/materiales/almacenes/movimientos', ['operacion_id' => (string) Str::uuid(), 'tipo' => 'consumo', 'folio_id' => $this->folio->id, 'almacen_origen_id' => $this->destino->id, 'cantidad' => 1, 'motivo' => 'Consumo posterior en packing'])->assertCreated();
        $antes = $this->inventario();
        $original = OperacionRetiroMaterial::firstOrFail()->snapshot_registro;
        $datos = $this->regularizacion();
        $this->postJson($this->url($despacho), $datos)->assertOk()->assertJsonPath('data.entregas.0.estado', 'regularizada')->assertJsonPath('data.entregas.0.version', 2)->assertJsonPath('data.entregas.0.regularizaciones.0.antes.numero_documento', null)->assertJsonPath('data.entregas.0.regularizaciones.0.despues.numero_documento', 'VALE-901')->assertDontSee('payload_hash');
        $this->postJson($this->url($despacho), $datos)->assertOk();
        $this->assertSame($antes, $this->inventario());
        $this->assertSame($original, OperacionRetiroMaterial::firstOrFail()->snapshot_registro);
        $this->assertDatabaseCount('regularizaciones_despachos_materiales', 1);
        $this->postJson($this->url($despacho), [...$datos, 'numero_documento' => 'OTRO'])->assertConflict();
        $this->assertSame($antes, $this->inventario());
        $this->getJson('/api/materiales/supervision?vista=almacenes&temporada_id='.$this->folio->temporada_id.'&desde=2026-10-20&hasta=2026-10-23')->assertOk()->assertSee('VALE-901');
    }

    public function test_fuera_de_plazo_exige_reconocimiento_y_conserva_fecha_limite(): void
    {
        $despacho = $this->crear();
        $limite = $despacho['entregas'][0]['fecha_limite'];
        $this->travelTo(Carbon::parse('2026-10-26T04:00:00Z'));
        $this->getJson('/api/materiales/despachos?vista=resumen&regularizacion=vencida')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('regularizacion.pendientes', 1)->assertJsonPath('regularizacion.vencidas', 1)->assertJsonPath('data.0.entregas.0.estado', 'vencida');
        $datos = $this->regularizacion();
        $this->postJson($this->url($despacho), $datos)->assertUnprocessable();
        $this->postJson($this->url($despacho), [...$datos, 'aceptar_fuera_plazo' => true])->assertOk()->assertJsonPath('data.entregas.0.regularizada_fuera_plazo', true)->assertJsonPath('data.entregas.0.fecha_limite', $limite);
        $this->assertTrue(RegularizacionDespachoMaterial::firstOrFail()->fuera_plazo);
        $this->getJson('/api/materiales/despachos?regularizacion=pendiente')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('regularizacion.vencidas', 0);
    }

    public function test_autorizacion_inicial_vencida_no_reinicia_el_plazo(): void
    {
        $datos = $this->payload(['registro' => $this->registro(['fecha_despacho' => '2026-10-10T10:00:00-03:00'])]);
        $antes = $this->inventario();
        $this->postJson('/api/materiales/despachos/directos', $datos)->assertUnprocessable();
        $this->assertSame($antes, $this->inventario());
        $datos['registro']['aceptar_fuera_plazo'] = true;
        $this->postJson('/api/materiales/despachos/directos', $datos)->assertCreated()->assertJsonPath('data.entregas.0.estado', 'vencida')->assertJsonPath('data.entregas.0.fecha_limite', '2026-10-16T02:59:59+00:00');
    }

    public function test_permiso_de_despacho_normal_no_autoriza_provisionales(): void
    {
        $perfil = PerfilAcceso::create(['codigo' => 'SIN-TARDIOS', 'nombre' => 'Despachos normales', 'rol_base' => 'supervisor_materiales', 'modulos' => ['materiales.despachos'], 'modulos_tablet' => [], 'permisos_materiales' => ['despachos.gestionar', 'despachos.retirar'], 'activo' => true]);
        $usuario = User::factory()->create(['rol' => RolUsuario::SupervisorMateriales, 'activo' => true, 'perfil_acceso_id' => $perfil->id]);
        $this->withToken($usuario->createToken('oficina', ['oficina'])->plainTextToken);
        $antes = $this->inventario();
        $this->postJson('/api/materiales/despachos/directos', $this->payload(['registro' => $this->registro()]))->assertForbidden();
        $this->assertSame($antes, $this->inventario());
        $this->withToken($this->token);
        $despacho = $this->crear();
        $this->withToken($usuario->createToken('oficina-2', ['oficina'])->plainTextToken);
        $this->postJson($this->url($despacho), $this->regularizacion())->assertForbidden();
    }

    public function test_fechas_futuras_documento_incompleto_y_saldo_insuficiente_no_dejan_entregas_parciales(): void
    {
        $antes = $this->inventario();
        foreach ([['fecha_despacho' => '2026-10-24T10:00:00-03:00'], ['numero_documento' => 'VALE'], ['numero_documento' => 'VALE', 'fecha_documento' => '2026-10-24'], ['motivo' => null], ['motivo_autorizacion' => null]] as $cambio) {
            $this->postJson('/api/materiales/despachos/directos', $this->payload(['registro' => $this->registro($cambio)]))->assertUnprocessable();
        }
        $this->postJson('/api/materiales/despachos/directos', $this->payload(['cantidad' => 30, 'registro' => $this->registro()]))->assertUnprocessable();
        $this->assertSame($antes, $this->inventario());
        $this->assertDatabaseCount('operaciones_retiro_materiales', 0);
    }

    public function test_regularizacion_rechaza_version_antigua_documento_futuro_y_otro_despacho(): void
    {
        $despacho = $this->crear();
        $this->postJson($this->url($despacho), $this->regularizacion(['version_conocida' => 2]))->assertConflict();
        $this->postJson($this->url($despacho), $this->regularizacion(['fecha_documento' => '2026-10-24']))->assertUnprocessable();
        $otro = $this->crear();
        $this->postJson('/api/materiales/despachos/'.$otro['id'].'/entregas/'.$despacho['entregas'][0]['id'].'/regularizar', $this->regularizacion())->assertNotFound();
        $this->assertDatabaseCount('regularizaciones_despachos_materiales', 0);
    }

    public function test_cada_entrega_parcial_tiene_su_fecha_y_su_regularizacion_independiente(): void
    {
        $despacho = $this->postJson('/api/materiales/despachos', ['operacion_id' => (string) Str::uuid(), 'destino_material_id' => $this->destino->id, 'items' => [['item_material_id' => $this->item->id, 'cantidad' => 10]]])->assertCreated()->json('data');
        $this->withToken($this->tabletToken);
        $url = '/api/materiales/despachos/'.$despacho['id'].'/retirar';
        $datos = ['operacion_id' => (string) Str::uuid(), 'registro' => $this->registro(), 'retiros' => [['folio_id' => $this->folio->id, 'cantidad' => 4, 'sesion_estiba_id' => $this->sesion]]];
        $this->postJson($url, $datos)->assertOk()->assertJsonPath('data.estado', 'parcial');
        $this->postJson($url, $datos)->assertOk();
        $this->travelTo(now()->addMinute());
        $despacho = $this->postJson($url, [...$datos, 'operacion_id' => (string) Str::uuid(), 'registro' => $this->registro(['fecha_despacho' => '2026-10-21T11:00:00-03:00'])])->assertOk()->assertJsonCount(2, 'data.entregas')->json('data');
        $this->assertNotSame($despacho['entregas'][0]['fecha_limite'], $despacho['entregas'][1]['fecha_limite']);
        $antes = $this->inventario();
        $this->withToken($this->token);
        $this->postJson($this->url($despacho), $this->regularizacion())->assertOk()->assertJsonPath('data.entregas.0.estado', 'regularizada')->assertJsonPath('data.entregas.1.estado', 'pendiente')->assertJsonPath('data.items.0.cantidad_despachada', '8.000');
        $this->assertSame($antes, $this->inventario());
        $this->postJson('/api/materiales/despachos/'.$despacho['id'].'/cancelar', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Cancelar solo el saldo aún no entregado'])->assertOk();
        $this->postJson($this->url($despacho, 1), $this->regularizacion())->assertOk()->assertJsonPath('data.estado', 'cancelado')->assertJsonPath('data.entregas.1.estado', 'regularizada');
    }

    public function test_temporada_cerrada_no_admite_regularizacion(): void
    {
        $despacho = $this->crear();
        DB::table('temporadas')->update(['activa' => false]);
        app(ServicioTemporadaActiva::class)->olvidar();
        $this->postJson($this->url($despacho), $this->regularizacion())->assertUnprocessable();
        $this->assertNull(OperacionRetiroMaterial::firstOrFail()->regularizado_at);
    }
}
