<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoRecepcionMaterial;
use App\Enums\RolUsuario;
use App\Enums\TipoAlmacenMaterial;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Models\AlmacenMaterial;
use App\Models\BultoRecepcionMaterial;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\DetalleRecepcionMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\Temporada;
use App\Models\TemporadaMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultaFolioMaterialTrazabilidadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_expone_recepcion_existencia_y_consumo_del_folio_material(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 1,
        ]);
        $usuario = User::factory()->create([
            'name' => 'Operador Materiales',
            'rol' => RolUsuario::Administrador,
        ]);
        $cliente = Cliente::create([
            'codigo' => 'MACE',
            'nombre' => 'MACE',
            'codigo_folio_materiales' => 'FM',
            'activo' => true,
        ]);
        $temporadaMaterial = TemporadaMaterial::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'MAT-2026',
            'nombre' => 'Materiales 2026',
            'activa' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        $clienteMaterial = new ClienteMaterial([
            'temporada_material_id' => $temporadaMaterial->id,
            'codigo' => 'MACE',
            'nombre' => 'MACE',
            'activo' => true,
        ]);
        $clienteMaterial->cliente_id = $cliente->id;
        $clienteMaterial->creado_por_user_id = $usuario->id;
        $clienteMaterial->actualizado_por_user_id = $usuario->id;
        $clienteMaterial->save();

        $item = ItemMaterial::create([
            'cliente_material_id' => $clienteMaterial->id,
            'codigo' => 'CAP-001',
            'nombre' => 'Capuchón cereza',
            'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'unidades',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        $proveedor = ProveedorMaterial::create([
            'codigo' => 'PROV-01',
            'nombre' => 'Proveedor Uno',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        $recepcion = RecepcionMaterial::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'recepcion-trazabilidad-material'),
            'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-4588',
            'fecha_documento' => '2026-08-04',
            'orden_compra' => 'OC-7788',
            'estado' => EstadoRecepcionMaterial::Confirmada,
            'creado_por_user_id' => $usuario->id,
            'confirmado_por_user_id' => $usuario->id,
            'confirmado_at' => now()->subHours(2),
        ]);
        $detalle = DetalleRecepcionMaterial::create([
            'recepcion_material_id' => $recepcion->id,
            'item_material_id' => $item->id,
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'unidades',
            'cantidad_documental' => 100,
            'cantidad_contada' => 100,
            'cantidad_recibida' => 100,
            'cantidad_rechazada' => 0,
        ]);
        $bulto = BultoRecepcionMaterial::create([
            'detalle_recepcion_material_id' => $detalle->id,
            'cantidad' => 100,
            'lote_proveedor' => 'L-20260804',
            'fecha_fabricacion' => '2026-07-20',
            'fecha_vencimiento' => '2027-07-20',
            'bloqueado' => false,
        ]);
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'FMA0000088',
            'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now()->subHours(2),
            'activo' => true,
            'origen_sistema' => 'recepcion_materiales',
        ]);
        FolioMaterial::create([
            'folio_id' => $folio->id,
            'item_material_id' => $item->id,
            'bulto_recepcion_material_id' => $bulto->id,
            'proveedor_material_id' => $proveedor->id,
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'cantidad_inicial' => 100,
            'cantidad_actual' => 70,
            'cantidad_reservada' => 10,
            'unidad_medida' => 'unidades',
            'lote' => 'L-20260804',
            'fecha_fabricacion' => '2026-07-20',
            'fecha_vencimiento' => '2027-07-20',
            'proveedor' => $proveedor->nombre,
        ]);

        $almacen = new AlmacenMaterial([
            'codigo' => 'CC-PACKING',
            'nombre' => 'Packing Línea 1',
            'tipo' => TipoAlmacenMaterial::Virtual,
            'centro_costo' => 'CC-4100',
            'requiere_ubicacion_fisica' => false,
            'activo' => true,
        ]);
        $almacen->creado_por_user_id = $usuario->id;
        $almacen->actualizado_por_user_id = $usuario->id;
        $almacen->save();
        SaldoMaterialAlmacen::create([
            'folio_id' => $folio->id,
            'almacen_material_id' => $almacen->id,
            'cantidad_actual' => 70,
            'cantidad_reservada' => 10,
            'version' => 1,
        ]);
        MovimientoInventarioMaterial::create([
            'folio_id' => $folio->id,
            'item_material_id' => $item->id,
            'tipo' => TipoMovimientoInventarioMaterial::ConsumoCentroCosto,
            'cantidad' => -30,
            'cantidad_anterior' => 100,
            'cantidad_resultante' => 70,
            'user_id' => $usuario->id,
            'destino_nombre' => 'Packing Línea 1',
            'destino_centro_costo' => 'CC-4100',
            'motivo' => 'Consumo en proceso productivo.',
            'ocurrido_at' => now()->subHour(),
        ]);

        $respuesta = $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/consultas/folios/{$folio->id}")
            ->assertOk()
            ->assertJsonPath('folio.tipo_bulto', 'material')
            ->assertJsonPath('folio.cantidad_actual', 70)
            ->assertJsonPath('material.identidad.codigo', 'CAP-001')
            ->assertJsonPath('material.identidad.item', 'Capuchón cereza')
            ->assertJsonPath('material.identidad.proveedor', 'Proveedor Uno')
            ->assertJsonPath('material.inventario.inicial', 100)
            ->assertJsonPath('material.inventario.actual', 70)
            ->assertJsonPath('material.inventario.reservada', 10)
            ->assertJsonPath('material.inventario.disponible', 60)
            ->assertJsonPath('material.recepcion.numero_guia', 'GD-4588')
            ->assertJsonPath('material.recepcion.orden_compra', 'OC-7788')
            ->assertJsonPath('material.recepcion.confirmado_por', 'Operador Materiales')
            ->assertJsonPath('material.saldos.0.almacen', 'Packing Línea 1')
            ->assertJsonPath('material.saldos.0.centro_costo', 'CC-4100')
            ->assertJsonPath('material.saldos.0.cantidad_disponible', 60)
            ->assertJsonPath('totales.recepciones_material', 1)
            ->assertJsonPath('totales.consumos_material', 1);

        $eventos = collect($respuesta->json('timeline'));
        $recepcionEvento = $eventos->firstWhere('tipo', 'recepcion_material');
        $consumoEvento = $eventos->firstWhere('titulo', 'Consumo por centro de costo');

        $this->assertSame('OC-7788', $recepcionEvento['meta']['Orden de compra']);
        $this->assertSame('CC-4100', $consumoEvento['meta']['Centro de costo']);
        $this->assertSame('Operador Materiales', $consumoEvento['meta']['Registrado por']);
        $this->assertStringContainsString('100 unidades → 70 unidades', $consumoEvento['descripcion']);
    }
}
