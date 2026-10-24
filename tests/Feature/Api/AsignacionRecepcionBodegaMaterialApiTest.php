<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\Dispositivo;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\Posicion;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\User;
use App\Services\Materiales\ServicioAlmacenMaterial;
use App\Services\Materiales\ServicioRecepcionMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AsignacionRecepcionBodegaMaterialApiTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\PreparaFotoDocumentoRecepcionMaterial;

    private function conToken(string $token): static
    {
        return $this->withToken($token);
    }

    private function prepararCatalogo(): array
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $token = $administrador->createToken('oficina-test', ['oficina'])->plainTextToken;
        $catalogo = ClienteMaterial::query()
            ->with(['cliente', 'temporada'])
            ->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))
            ->firstOrFail();
        $cliente = $catalogo->cliente;
        $cliente->update(['codigo_folio_materiales' => 'GE']);
        $item = ItemMaterial::create([
            'cliente_material_id' => $catalogo->id,
            'codigo' => 'FILM-REC',
            'nombre' => 'Film para recepción',
            'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'rollos',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $proveedor = ProveedorMaterial::create([
            'codigo' => 'PROV-REC',
            'nombre' => 'Proveedor recepción',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        DB::table('clientes_proveedores_materiales')->insert([
            'id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'activo' => true,
            'categorias' => json_encode(['Embalaje'], JSON_UNESCAPED_UNICODE),
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$administrador, $token, $cliente, $proveedor, $item];
    }

    private function crearOperador(): array
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-REC-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet recepción',
            'activo' => true,
        ]);
        $token = $usuario
            ->crearTokenParaDispositivo($dispositivo, 'tablet-recepcion')
            ->plainTextToken;

        return [$usuario, $dispositivo, $token];
    }

    private function crearCamaraMateriales(): array
    {
        $camara = Camara::create([
            'codigo' => 'MAT-REC-01',
            'nombre' => 'Cámara recepción',
            'contenido' => ContenidoCamara::Materiales,
        ]);
        $posiciones = collect([1, 2])->map(fn (int $numero): Posicion => Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => $numero,
            'nivel' => 1,
            'etiqueta' => sprintf('B01-P%02d-N1', $numero),
        ]));

        return [$camara, ...$posiciones->all()];
    }

    private function payloadRecepcion(
        Cliente $cliente,
        ProveedorMaterial $proveedor,
        ItemMaterial $item,
        array $bultos,
    ): array {
        return [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-REC-001',
            'fecha_documento' => '2026-07-24',
            'detalles' => [[
                'item_material_id' => $item->id,
                'cantidad_documental' => collect($bultos)->sum('cantidad'),
                'cantidad_recibida' => collect($bultos)->sum('cantidad'),
                'cantidad_rechazada' => 0,
                'bultos' => $bultos,
            ]],
        ];
    }

    private function regla(User $u, ?string $clienteId = null): array
    {
        $b = $this->postJson('/api/materiales/bodegas', ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => 'BOD-AUTO', 'nombre' => 'Bodega automática', 'activo' => true])->assertCreated()->json('data');
        $rule = $this->postJson('/api/materiales/asignacion-bodegas', ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'nombre' => 'Insumos a automática', 'prioridad' => 10, 'cliente_id' => $clienteId, 'categoria_operacional' => 'insumo', 'almacen_material_id' => $b['id'], 'activo' => true, 'motivo' => 'Organizar ingresos por bodega'])->assertCreated()->json('data');

        return [$b, $rule];
    }

    private function recibir(Cliente $cliente, ProveedorMaterial $proveedor, ItemMaterial $item): array
    {
        $rec = $this->postJson('/api/materiales/recepciones', $this->payloadRecepcion($cliente, $proveedor, $item, [['cantidad' => 20], ['cantidad' => 10]]))->assertCreated()->json('data');
        $d = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $rec['version']];
        $rec = $this->postJsonConDocumento('/api/materiales/recepciones/'.$rec['id'].'/confirmar', $d)->assertOk()->json('data');

        return [$rec, $d];
    }

    public function test_aprobar_asigna_cada_folio_sin_inventar_ubicacion_y_reintentar_no_duplica(): void
    {
        [$u,$token,$cli,$prov,$item] = $this->prepararCatalogo();
        $this->withToken($token);
        [$b,$rule] = $this->regla($u, $cli->id);
        [$rec,$d] = $this->recibir($cli, $prov, $item);
        $this->assertCount(2, $rec['snapshot_confirmacion']['folios']);
        foreach ($rec['snapshot_confirmacion']['folios'] as $f) {
            $this->assertSame($b['id'], $f['asignacion_bodega']['almacen_id']);
            $this->assertSame($rule['id'], $f['asignacion_bodega']['regla']['id']);
            $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $f['id'], 'almacen_material_id' => $b['id'], 'camara_id' => null, 'cantidad_reservada' => 0]);
            $this->assertDatabaseHas('folios', ['id' => $f['id'], 'estado_operacional' => 'pendiente_ubicacion']);
        }
        $this->assertDatabaseCount('ubicaciones_actuales', 0);
        $this->assertDatabaseCount('folios_materiales', 2);
        $this->postJson('/api/materiales/recepciones/'.$rec['id'].'/confirmar', $d)->assertOk();
        $this->assertDatabaseCount('folios_materiales', 2);
        $this->getJson('/api/materiales/recepciones/folios-pendientes')->assertOk()->assertJsonPath('data.0.asignacion_bodega.almacen_id', $b['id']);
        $this->getJson('/api/materiales/inventario?almacen_origen_id='.$b['id'])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.reservable', false);
        $this->assertEquals(30, SaldoMaterialAlmacen::where('almacen_material_id', $b['id'])->sum('cantidad_actual'));
        $this->assertDatabaseCount('movimientos_almacenes_materiales', 0);
        // Cambiar o desactivar la regla no redistribuye ingresos ya confirmados.
        DB::table('reglas_bodegas_materiales')->where('id', $rule['id'])->update(['activo' => false, 'nombre' => 'Nombre nuevo']);
        $again = $this->getJson('/api/materiales/recepciones/'.$rec['id'])->assertOk()->json('data');
        $this->assertSame('Insumos a automática', $again['snapshot_confirmacion']['folios'][0]['asignacion_bodega']['regla']['nombre']);
        $this->assertDatabaseCount('folios_materiales', 2);
    }

    public function test_primera_ubicacion_desde_tablet_ubica_saldo_asignado_y_conserva_total(): void
    {
        [$u,$token,$cli,$prov,$item] = $this->prepararCatalogo();
        $this->withToken($token);
        [$b] = $this->regla($u);
        [$rec] = $this->recibir($cli, $prov, $item);
        $f = $rec['snapshot_confirmacion']['folios'][0];
        [$cam,$pos] = $this->crearCamaraMateriales();
        [,,$tablet] = $this->crearOperador();
        $this->withToken($tablet);
        $ses = $this->postJson('/api/camaras/'.$cam->id.'/sesiones')->assertCreated()->json('data.id');
        $d = ['operacion_id' => (string) Str::uuid(), 'numero_folio' => $f['numero_folio'], 'tipo_bulto' => 'material', 'camara_destino_id' => $cam->id, 'posicion_destino_id' => $pos->id, 'sesion_destino_id' => $ses, 'version_destino_conocida' => $cam->fresh()->version_plano, 'generado_dispositivo_at' => now()->toAtomString()];
        $this->postJson('/api/movimientos/ubicar', $d)->assertOk();
        $this->postJson('/api/movimientos/ubicar', $d)->assertOk();
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $f['id'], 'almacen_material_id' => $b['id'], 'cantidad_actual' => 20, 'camara_id' => $cam->id, 'posicion_id' => $pos->id]);
        $this->assertEquals(20, FolioMaterial::findOrFail($f['id'])->cantidad_actual);
        $this->assertEquals(20, SaldoMaterialAlmacen::where('folio_id', $f['id'])->sum('cantidad_actual'));
        $this->withToken($token);
        $this->getJson('/api/materiales/inventario?almacen_origen_id='.$b['id'])->assertOk()->assertSee('20.000');
        $this->getJson('/api/camaras/'.$cam->id.'/plano')->assertOk()->assertSee($f['numero_folio']);
    }

    public function test_anular_sin_movimientos_vacia_solo_bodega_inicial_y_no_repite_stock(): void
    {
        [$u,$token,$cli,$prov,$item] = $this->prepararCatalogo();
        $this->withToken($token);
        [$b] = $this->regla($u);
        [$rec] = $this->recibir($cli, $prov, $item);
        $d = ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Documento duplicado antes de ubicar'];
        $this->postJson('/api/materiales/recepciones/'.$rec['id'].'/anular', $d)->assertOk();
        $this->postJson('/api/materiales/recepciones/'.$rec['id'].'/anular', $d)->assertOk();
        $this->assertEquals(0, SaldoMaterialAlmacen::where('almacen_material_id', $b['id'])->sum('cantidad_actual'));
        $this->assertDatabaseCount('folios_materiales', 2);
        $this->assertDatabaseCount('ubicaciones_actuales', 0);
    }

    public function test_sin_reglas_conserva_central_y_foto_sigue_siendo_obligatoria(): void
    {
        [$u,$token,$cli,$prov,$item] = $this->prepararCatalogo();
        $this->withToken($token);
        $rec = $this->postJson('/api/materiales/recepciones', $this->payloadRecepcion($cli, $prov, $item, [['cantidad' => 10]]))->assertCreated()->json('data');
        $d = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $rec['version']];
        $this->postJson('/api/materiales/recepciones/'.$rec['id'].'/confirmar', $d)->assertUnprocessable();
        $this->assertDatabaseCount('folios_materiales', 0);
        $rec = $this->postJsonConDocumento('/api/materiales/recepciones/'.$rec['id'].'/confirmar', $d)->assertOk()->json('data');
        $this->assertSame('central_por_defecto', $rec['snapshot_confirmacion']['folios'][0]['asignacion_bodega']['origen']);
        $central = app(ServicioAlmacenMaterial::class)->bodegaCentral();
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['almacen_material_id' => $central->id, 'cantidad_actual' => 10]);
    }

    public function test_edicion_o_eliminacion_administrativa_sin_ubicar_no_pierde_evidencia_de_asignacion(): void
    {
        [$u,$token,$cli,$prov,$item] = $this->prepararCatalogo();
        $this->withToken($token);
        [$b] = $this->regla($u);
        [$rec] = $this->recibir($cli, $prov, $item);
        $actual = RecepcionMaterial::findOrFail($rec['id']);
        app(ServicioRecepcionMaterial::class)->eliminarAdministrativamente($actual,(string) Str::uuid(),$actual->version,'Duplicado detectado antes de ubicación',$u);
        $this->assertDatabaseCount('folios_materiales',0);
        $this->assertDatabaseCount('saldos_materiales_almacenes',0);
        $this->assertDatabaseCount('eliminaciones_recepciones_materiales',1);
    }
}
