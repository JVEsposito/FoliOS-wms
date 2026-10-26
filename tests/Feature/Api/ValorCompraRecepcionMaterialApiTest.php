<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\DetalleRecepcionMaterial;
use App\Models\EventoRecepcionMaterial;
use App\Models\ItemMaterial;
use App\Models\PerfilAcceso;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValorCompraRecepcionMaterialApiTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\PreparaFotoDocumentoRecepcionMaterial;

    private User $admin;

    private string $token;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $this->withToken($this->token);
        $this->payload = ['operacion_id' => (string) Str::uuid(), 'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id, 'numero_guia_despacho' => 'GD-COMPRA', 'fecha_documento' => now()->toDateString(),
            'detalles' => [['item_material_id' => $item->id, 'cantidad_documental' => 12, 'cantidad_contada' => 12,
                'cantidad_aceptada' => 10, 'cantidad_rechazada' => 2, 'bultos' => [['cantidad' => 10]]]]];
    }

    private function crear(bool $confirmar = true): array
    {
        $r = $this->postJson('/api/materiales/recepciones', [...$this->payload, 'operacion_id' => (string) Str::uuid()])->assertCreated()->json('data');

        return $confirmar ? $this->postJsonConDocumento('/api/materiales/recepciones/'.$r['id'].'/confirmar', ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $r['version']])->assertOk()->json('data') : $r;
    }

    private function datos(array $r, ?string $precio = '123.456789', ?string $moneda = 'CLP'): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $r['version'], 'motivo' => 'Valor neto confirmado según compra',
            'detalles' => [['id' => $r['detalles'][0]['id'], 'precio_unitario_neto' => $precio, 'moneda' => $moneda]]];
    }

    private function url(array $r): string
    {
        return '/api/materiales/recepciones/'.$r['id'].'/valorizacion';
    }

    private function inventario(): array
    {
        return collect(['folios', 'folios_materiales', 'movimientos_inventario_materiales', 'movimientos_almacenes_materiales', 'bultos_recepciones_materiales'])
            ->mapWithKeys(fn ($tabla) => [$tabla => DB::table($tabla)->orderBy($tabla === 'folios_materiales' ? 'folio_id' : 'id')->get()->toJson()])->all();
    }

    public function test_recibir_sin_precio_es_valido_y_valorizar_despues_no_cambia_stock_ni_snapshot(): void
    {
        $r = $this->crear();
        $this->assertNull($r['detalles'][0]['valor_compra']['precio_unitario_neto']);
        $antes = $this->inventario();
        $snapshot = $r['snapshot_confirmacion'];
        $datos = $this->datos($r);
        $result = $this->postJson($this->url($r), $datos)->assertOk()->assertJsonPath('data.detalles.0.valor_compra.total_neto', '1235')->json('data');
        $this->assertSame($antes, $this->inventario());
        $this->assertSame($snapshot, $result['snapshot_confirmacion']);
        $this->assertSame($r['version'] + 1, $result['version']);
        $this->postJson($this->url($r), $datos)->assertOk();
        $this->assertDatabaseCount('eventos_recepciones_materiales', 3);
        $evento = EventoRecepcionMaterial::where('tipo', 'valorizada')->firstOrFail();
        $this->assertNull($evento->datos['antes'][0]['precio_unitario_neto']);
        $this->assertSame('123.456789', $evento->datos['despues'][0]['precio_unitario_neto']);
        $this->assertSame($this->admin->id, $evento->user_id);
        $this->postJson($this->url($r), [...$datos, 'motivo' => 'Cambio del mismo UUID'])->assertConflict();
    }

    public function test_cero_es_valorizado_pendiente_es_null_y_correcciones_preservan_historial(): void
    {
        $r = $this->crear();
        $r = $this->postJson($this->url($r), $this->datos($r, '0', 'USD'))->assertOk()->assertJsonPath('data.detalles.0.valor_compra.total_neto', '0.00')->json('data');
        $this->getJson('/api/materiales/recepciones?valorizacion=completa')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/materiales/recepciones?valorizacion=pendiente')->assertOk()->assertJsonPath('meta.total', 0);
        $r = $this->postJson($this->url($r), $this->datos($r, null, null))->assertOk()->assertJsonPath('data.detalles.0.valor_compra.total_neto', null)->json('data');
        $this->getJson('/api/materiales/recepciones?valorizacion=pendiente')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.valorizacion.lineas_pendientes', 1);
        $this->assertSame(2, EventoRecepcionMaterial::where('tipo', 'valorizada')->count());
    }

    public function test_calculo_decimal_por_cantidad_aceptada_y_moneda_sin_sumar_rechazos(): void
    {
        $r = $this->crear();
        $r = $this->postJson($this->url($r), $this->datos($r, '1.2345', 'USD'))->assertOk()->assertJsonPath('data.detalles.0.valor_compra.total_neto', '12.35')->json('data');
        $this->postJson($this->url($r), $this->datos($r, '999999999.999999', 'EUR'))->assertOk()->assertJsonPath('data.detalles.0.valor_compra.total_neto', '10000000000.00');
    }

    public function test_validaciones_y_detalles_ajenos_no_modifican_nada(): void
    {
        $r = $this->crear();
        $otro = $this->crear();
        $antes = $this->inventario();
        foreach ([['-1', 'CLP'], ['1.1234567', 'CLP'], ['1000000000', 'CLP'], ['1', 'UF'], [null, 'CLP'], ['1', null]] as [$precio, $moneda]) {
            $this->postJson($this->url($r), $this->datos($r, $precio, $moneda))->assertUnprocessable();
        }
        $datos = $this->datos($r);
        $datos['detalles'][] = ['id' => $otro['detalles'][0]['id'], 'precio_unitario_neto' => '1', 'moneda' => 'CLP'];
        $this->postJson($this->url($r), $datos)->assertUnprocessable();
        $datos['detalles'][1] = $datos['detalles'][0];
        $this->postJson($this->url($r), $datos)->assertUnprocessable();
        $this->assertNull(DetalleRecepcionMaterial::findOrFail($r['detalles'][0]['id'])->precio_unitario_compra);
        $this->assertSame($antes, $this->inventario());
        $this->assertSame(0, EventoRecepcionMaterial::where('tipo', 'valorizada')->count());
    }

    public function test_version_obsoleta_borrador_y_temporada_cerrada_rechazan(): void
    {
        $draft = $this->crear(false);
        $this->postJson($this->url($draft), $this->datos($draft))->assertUnprocessable();
        $r = $this->crear();
        $this->postJson($this->url($r), [...$this->datos($r), 'version_conocida' => 1])->assertConflict();
        DB::table('temporadas')->where('id', $r['temporada']['id'])->update(['activa' => false]);
        $this->postJson($this->url($r), $this->datos($r))->assertUnprocessable();
    }

    public function test_admin_de_bodega_y_supervisor_nivel_uno_con_permiso_explicito_pueden_valorizar(): void
    {
        $r = $this->crear();
        foreach ([[RolUsuario::Administrador, 'ADMIN-BODEGA', 'Administrador de bodega'], [RolUsuario::SupervisorMateriales, 'SUP-BOD-N1', 'Supervisor de bodega nivel 1']] as [$rol, $codigo, $nombre]) {
            $perfil = PerfilAcceso::create(['codigo' => $codigo, 'nombre' => $nombre, 'rol_base' => $rol, 'modulos' => ['materiales.etiquetas'], 'modulos_tablet' => [], 'permisos_materiales' => ['recepciones.valorizar'], 'activo' => true]);
            $u = User::factory()->create(['rol' => $rol, 'activo' => true, 'perfil_acceso_id' => $perfil->id]);
            app('auth')->forgetGuards();
            $this->withToken($u->createToken('oficina', ['oficina'])->plainTextToken);
            $r = $this->postJson($this->url($r), $this->datos($r))->assertOk()->json('data');
            $this->assertSame($u->id, EventoRecepcionMaterial::where('tipo', 'valorizada')->latest('ocurrido_at')->latest('id')->first()->user_id);
        }
    }

    public function test_confirmar_no_concede_permiso_de_valorizar_y_operador_no_ve_precios_ni_auditoria(): void
    {
        $r = $this->crear();
        $r = $this->postJson($this->url($r), $this->datos($r))->assertOk()->json('data');
        foreach ([RolUsuario::SupervisorMateriales, RolUsuario::CamareroMateriales] as $rol) {
            $perfil = PerfilAcceso::create(['codigo' => (string) Str::uuid(), 'nombre' => 'Solo recepción', 'rol_base' => $rol, 'modulos' => ['materiales.etiquetas'], 'modulos_tablet' => [], 'permisos_materiales' => ['recepciones.confirmar'], 'activo' => true]);
            $u = User::factory()->create(['rol' => $rol, 'activo' => true, 'perfil_acceso_id' => $perfil->id]);
            app('auth')->forgetGuards();
            $this->withToken($u->createToken('oficina', ['oficina'])->plainTextToken);
            $this->postJson($this->url($r), $this->datos($r))->assertForbidden();
            if ($rol === RolUsuario::CamareroMateriales) {
                $shown = $this->getJson('/api/materiales/recepciones/'.$r['id'])->assertOk()->json('data');
                $this->assertArrayNotHasKey('valor_compra', $shown['detalles'][0], json_encode($shown['detalles']));
                $this->assertFalse(collect($shown['eventos'])->contains('tipo', 'valorizada'), json_encode($shown['eventos']));
                $this->getJson('/api/materiales/recepciones/valores-compra.csv')->assertForbidden();
                $this->getJson('/api/materiales/recepciones?valorizacion=pendiente')->assertForbidden();
            }
        }
    }

    public function test_exportacion_aplica_filtros_y_cero_no_es_pendiente(): void
    {
        $r = $this->crear();
        $r = $this->postJson($this->url($r), $this->datos($r, '0', 'CLP'))->assertOk()->json('data');
        $this->crear();
        $csv = $this->get('/api/materiales/recepciones/valores-compra.csv?valorizacion=completa')->assertOk()->streamedContent();
        $this->assertStringContainsString('PRECIO UNITARIO NETO', $csv);
        $this->assertStringContainsString('0.000000;CLP;0;VALORIZADA', $csv);
        $this->assertStringNotContainsString(';PENDIENTE', $csv);
        $this->get('/api/materiales/recepciones/valores-compra.csv?guia=INEXISTENTE')->assertOk();
        $this->assertCount(2, array_filter(explode("\n", $csv)));
    }

    public function test_reordenar_un_reintento_no_duplica_y_falla_de_una_linea_revierte_todas(): void
    {
        $r = $this->crear();
        $original = DetalleRecepcionMaterial::findOrFail($r['detalles'][0]['id']);
        $segunda = $original->replicate();
        $segunda->save();
        $datos = $this->datos($r);
        $datos['detalles'][] = ['id' => $segunda->id, 'precio_unitario_neto' => '2', 'moneda' => 'USD'];
        $result = $this->postJson($this->url($r), $datos)->assertOk()->json('data');
        $datos['detalles'] = array_reverse($datos['detalles']);
        $this->postJson($this->url($r), $datos)->assertOk();
        $this->assertSame(1, EventoRecepcionMaterial::where('tipo', 'valorizada')->count());
        $invalido = $this->datos($result, '999', 'CLP');
        $invalido['detalles'][] = ['id' => $segunda->id, 'precio_unitario_neto' => null, 'moneda' => 'USD'];
        $this->postJson($this->url($r), $invalido)->assertUnprocessable();
        $this->assertSame('123.456789', $original->fresh()->precio_unitario_compra);
        $this->assertSame('2.000000', $segunda->fresh()->precio_unitario_compra);
        $this->assertSame($result['version'], RecepcionMaterial::findOrFail($r['id'])->version);
    }

    public function test_descarga_recoge_todas_las_paginas_y_protege_textos_con_formulas(): void
    {
        $r = $this->crear();
        $original = RecepcionMaterial::findOrFail($r['id']);
        $detalle = DetalleRecepcionMaterial::findOrFail($r['detalles'][0]['id']);
        $detalle->item->update(['nombre' => '=SUM(A1:A2)']);
        for ($i = 0; $i < 100; $i++) {
            $copia = $original->replicate();
            $copia->operacion_id = (string) Str::uuid();
            $copia->confirmacion_operacion_id = (string) Str::uuid();
            $copia->save();
            $linea = $detalle->replicate();
            $linea->recepcion_material_id = $copia->id;
            $linea->save();
        }
        $this->getJson('/api/materiales/recepciones?per_page=10&valorizacion=pendiente')->assertOk()->assertJsonPath('meta.total', 101)->assertJsonCount(10, 'data');
        $csv = $this->get('/api/materiales/recepciones/valores-compra.csv?per_page=10&valorizacion=pendiente')->assertOk()->streamedContent();
        $this->assertCount(102, array_filter(explode("\n", $csv)));
        $this->assertStringContainsString("'=SUM(A1:A2)", $csv);
    }

    public function test_precio_no_reconstruye_una_recepcion_y_sin_cantidad_aceptada_no_se_valoriza(): void
    {
        $r = $this->crear();
        $r = $this->postJson($this->url($r), $this->datos($r))->assertOk()->json('data');
        $datos = [...$this->payload, 'operacion_id' => (string) Str::uuid(), 'version_conocida' => $r['version'], 'motivo_correccion' => 'Corrección administrativa del documento', 'confirmacion_operacion_id' => (string) Str::uuid()];
        $antes = $this->inventario();
        $this->putJson('/api/materiales/recepciones/'.$r['id'].'/administrar', $datos)->assertUnprocessable();
        $this->assertSame($antes, $this->inventario());
        $this->assertSame('123.456789', DetalleRecepcionMaterial::findOrFail($r['detalles'][0]['id'])->precio_unitario_compra);
        $detalle = DetalleRecepcionMaterial::findOrFail($r['detalles'][0]['id']);
        $sinAceptado = $detalle->replicate();
        $sinAceptado->cantidad_recibida = '0';
        $sinAceptado->precio_unitario_compra = null;
        $sinAceptado->moneda_compra = null;
        $sinAceptado->save();
        $nuevo = $this->datos($r);
        $nuevo['detalles'][0]['id'] = $sinAceptado->id;
        $this->postJson($this->url($r), $nuevo)->assertUnprocessable();
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
}
