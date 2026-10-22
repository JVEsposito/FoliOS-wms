<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\UnidadMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnidadesMaterialesApiTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\PreparaFotoDocumentoRecepcionMaterial;

    private const BASE = '/api/administracion/materiales/unidades';

    private ItemMaterial $item;

    private ProveedorMaterial $proveedor;

    private ClienteMaterial $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->cliente = ClienteMaterial::with('cliente')->where('codigo', 'GENERAL')->whereHas('temporada', fn ($q) => $q->where('activa', true))->firstOrFail();
        $this->cliente->cliente->update(['codigo_folio_materiales' => 'GE']);
        $this->item = ItemMaterial::create(['cliente_material_id' => $this->cliente->id, 'codigo' => 'UNID-TEST', 'nombre' => 'Caja plana fixture', 'categoria' => 'Embalaje', 'categoria_operacional' => 'material_mp', 'unidad_medida' => 'unidades', 'activo' => true]);
        $this->proveedor = ProveedorMaterial::create(['nombre' => 'Proveedor fixture', 'activo' => true]);
        $this->proveedor->clientes()->attach($this->cliente->cliente_id, ['id' => Str::uuid(), 'activo' => true, 'categorias' => json_encode(['Embalaje'])]);
    }

    private function equivalencia(string $factor = '100', int $version = 0): array
    {
        return ['unidad_origen' => 'cajas', 'factor' => $factor, 'activa' => true, 'version_conocida' => $version, 'motivo' => 'Contenido por caja verificado'];
    }

    private function rutaConversion(?ItemMaterial $item = null): string
    {
        return '/api/administracion/materiales/items/'.($item ?? $this->item)->id.'/unidades';
    }

    private function recepcion(array $cambios = []): array
    {
        return [
            'operacion_id' => (string) Str::uuid(), 'cliente_id' => $this->cliente->cliente_id, 'proveedor_material_id' => $this->proveedor->id,
            'numero_guia_despacho' => 'GUIA-'.Str::random(8),
            'detalles' => [[
                'item_material_id' => $this->item->id, 'unidad_ingreso' => 'cajas', 'conversion_version' => 1,
                'cantidad_documental' => 2, 'cantidad_contada' => 2, 'cantidad_aceptada' => 2, 'cantidad_rechazada' => 0,
                'bultos' => [['cantidad' => 1], ['cantidad' => 1]], ...$cambios,
            ]],
        ];
    }

    public function test_catalogo_normaliza_las_variantes_y_controla_versiones_codigo_y_alias(): void
    {
        $unidad = UnidadMaterial::findOrFail($this->item->unidad_material_id);
        $otra = ItemMaterial::create(['cliente_material_id' => $this->cliente->id, 'codigo' => 'OTRO', 'nombre' => 'Otro ítem fixture', 'categoria_operacional' => 'insumo', 'unidad_medida' => 'UN', 'activo' => true]);
        $this->assertSame($unidad->id, $otra->unidad_material_id);
        $this->assertSame('UN', $otra->unidad_medida);
        $datos = ['codigo' => 'resmas', 'nombre' => 'Resmas', 'alias' => ['resma'], 'activa' => true, 'version_conocida' => 0, 'motivo' => 'Nueva unidad del catálogo'];
        $id = $this->postJson(self::BASE, $datos)->assertCreated()->json('data.id');
        $this->postJson(self::BASE, [...$datos, 'codigo' => 'RESMA'])->assertUnprocessable();
        $this->putJson(self::BASE.'/'.$id, [...$datos, 'version_conocida' => 0])->assertConflict();
        $this->putJson(self::BASE.'/'.$id, [...$datos, 'codigo' => 'otra', 'version_conocida' => 1])->assertUnprocessable();
        $this->putJson(self::BASE.'/'.$id, [...$datos, 'alias' => ['kg'], 'version_conocida' => 1])->assertUnprocessable();
        $this->putJson(self::BASE.'/'.$id, [...$datos, 'activa' => false, 'version_conocida' => 1])->assertOk();
        $this->getJson(self::BASE)->assertOk();
        $this->getJson(self::BASE.'/historial')->assertOk()->assertJsonPath('data.0.responsable', auth()->user()->name);
    }

    public function test_equivalencias_son_por_item_y_el_cambio_de_factor_no_modifica_la_recepcion_ya_convertida(): void
    {
        $this->putJson($this->rutaConversion(), $this->equivalencia())->assertOk();
        $datos = $this->recepcion();
        $id = $this->postJson('/api/materiales/recepciones', $datos)->assertCreated()
            ->assertJsonPath('data.detalles.0.cantidad_aceptada', '200.000')
            ->assertJsonPath('data.detalles.0.conversion_unidad.factor', '100.000000')->json('data.id');
        $this->postJson('/api/materiales/recepciones', $datos)->assertCreated();
        $this->assertDatabaseCount('detalles_recepciones_materiales', 1);
        $this->putJson($this->rutaConversion(), $this->equivalencia('120', 1))->assertOk();
        $this->getJson('/api/materiales/recepciones/'.$id)->assertOk()->assertJsonPath('data.detalles.0.cantidad_aceptada', '200.000')->assertJsonPath('data.detalles.0.conversion_unidad.version', 1);
        $this->postJsonConDocumento('/api/materiales/recepciones/'.$id.'/confirmar', ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1])->assertOk();
        $this->assertSame('200.000', (string) DB::table('folios_materiales')->sum('cantidad_actual'));
        $this->assertSame(['unidades'], DB::table('folios_materiales')->distinct()->pluck('unidad_medida')->all());
        $otra = ItemMaterial::create(['cliente_material_id' => $this->cliente->id, 'codigo' => 'SIN-FACTOR', 'nombre' => 'Otro material fixture', 'categoria_operacional' => 'material_mp', 'categoria' => 'Embalaje', 'unidad_medida' => 'unidades', 'activo' => true]);
        $this->postJson('/api/materiales/recepciones', $this->recepcion(['item_material_id' => $otra->id]))->assertUnprocessable();
        $this->assertDatabaseCount('recepciones_materiales', 1);
    }

    public function test_no_cambia_la_unidad_de_un_item_que_ya_tiene_un_borrador_y_conserva_sus_sinonimos(): void
    {
        $this->putJson($this->rutaConversion(), $this->equivalencia())->assertOk();
        $this->postJson('/api/materiales/recepciones', $this->recepcion())->assertCreated();
        $item = ['cliente_material_id' => $this->cliente->id, 'codigo' => $this->item->codigo, 'nombre' => $this->item->nombre, 'categoria' => 'Embalaje', 'categoria_operacional' => 'material_mp', 'unidad_medida' => 'kg'];
        $ruta = '/api/administracion/materiales/items/'.$this->item->id;
        $this->putJson($ruta, $item)->assertUnprocessable();
        $this->putJson($ruta, [...$item, 'unidad_medida' => 'UN'])->assertOk()->assertJsonPath('data.unidad_medida', 'unidades');
        $this->assertSame('unidades', $this->item->fresh()->unidad_medida);
    }

    public function test_editar_antecedentes_conserva_el_factor_historico_pero_cambiar_cantidades_exige_el_vigente(): void
    {
        $this->putJson($this->rutaConversion(), $this->equivalencia())->assertOk();
        $datos = $this->recepcion(['bultos' => [['cantidad' => '1.5'], ['cantidad' => '0.5']]]);
        $creada = $this->postJson('/api/materiales/recepciones', $datos)->assertCreated()->json('data');
        $detalle = $creada['detalles'][0];
        foreach ($detalle['bultos'] as $bulto) {
            $entrada = $detalle['conversion_unidad']['bultos_entrada_por_id'][$bulto['id']];
            $this->assertEquals((float) $bulto['cantidad'], (float) $entrada * 100);
        }
        $this->putJson($this->rutaConversion(), $this->equivalencia('120', 1))->assertOk();
        $datos['version_conocida'] = 1;
        $datos['detalles'][0]['detalle_origen_id'] = $detalle['id'];
        $datos['detalles'][0]['observacion'] = 'Antecedente aclarado sin cambiar cantidades';
        $datos['operacion_id'] = (string) Str::uuid();
        $actualizada = $this->putJson('/api/materiales/recepciones/'.$creada['id'], $datos)->assertOk()
            ->assertJsonPath('data.detalles.0.cantidad_aceptada', '200.000')
            ->assertJsonPath('data.detalles.0.conversion_unidad.version', 1)->json('data');
        $datos['operacion_id'] = (string) Str::uuid();
        $datos['version_conocida'] = 2;
        $datos['detalles'][0]['detalle_origen_id'] = (string) Str::uuid();
        $this->putJson('/api/materiales/recepciones/'.$creada['id'], $datos)->assertUnprocessable();
        $datos['detalles'][0]['detalle_origen_id'] = $actualizada['detalles'][0]['id'];
        foreach (['cantidad_documental', 'cantidad_contada', 'cantidad_aceptada'] as $campo) {
            $datos['detalles'][0][$campo] = 3;
        }
        $datos['detalles'][0]['bultos'] = [['cantidad' => 3]];
        $this->putJson('/api/materiales/recepciones/'.$creada['id'], $datos)->assertConflict();
        $datos['detalles'][0]['conversion_version'] = 2;
        $this->putJson('/api/materiales/recepciones/'.$creada['id'], $datos)->assertOk()
            ->assertJsonPath('data.detalles.0.cantidad_aceptada', '360.000')
            ->assertJsonPath('data.detalles.0.conversion_unidad.version', 2);
        $this->assertDatabaseCount('folios_materiales', 0);
    }

    public function test_rechaza_factores_vencidos_inactivos_y_conversiones_que_exigen_redondear(): void
    {
        $this->putJson($this->rutaConversion(), $this->equivalencia('0.5'))->assertOk();
        $this->postJson('/api/materiales/recepciones', $this->recepcion(['cantidad_documental' => '0.001', 'cantidad_contada' => '0.001', 'cantidad_aceptada' => '0.001', 'bultos' => [['cantidad' => '0.001']]]))->assertUnprocessable();
        $this->putJson($this->rutaConversion(), $this->equivalencia('100', 1))->assertOk();
        $this->postJson('/api/materiales/recepciones', $this->recepcion())->assertConflict();
        $this->putJson($this->rutaConversion(), [...$this->equivalencia('100', 2), 'activa' => false])->assertOk();
        $this->postJson('/api/materiales/recepciones', $this->recepcion(['conversion_version' => 3]))->assertUnprocessable();
        $this->assertDatabaseCount('recepciones_materiales', 0);
        $this->assertDatabaseCount('folios_materiales', 0);
    }

    public function test_respeta_permisos_y_la_inactivacion_no_elimina_historial(): void
    {
        $unidad = UnidadMaterial::findOrFail($this->item->unidad_material_id);
        $datos = ['codigo' => $unidad->codigo, 'nombre' => $unidad->nombre, 'alias' => [], 'activa' => false, 'version_conocida' => 1, 'motivo' => 'Unidad fuera de uso para altas'];
        $this->putJson(self::BASE.'/'.$unidad->id, $datos)->assertOk();
        $this->postJson('/api/administracion/materiales/items', ['cliente_material_id' => $this->cliente->id, 'codigo' => 'NUEVO', 'nombre' => 'Otro ítem', 'categoria_operacional' => 'insumo', 'unidad_medida' => 'un'])->assertUnprocessable();
        $this->assertSame('unidades', $this->item->fresh()->unidad_medida);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, $datos)->assertForbidden();
        $this->putJson($this->rutaConversion(), $this->equivalencia())->assertForbidden();
    }
}
