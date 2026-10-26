<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\Temporada;
use App\Models\TemporadaMaterial;
use App\Models\User;
use App\Services\Materiales\LectorPlanillaMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientesCatalogoMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/materiales/items/catalogo';

    private TemporadaMaterial $activa;

    private TemporadaMaterial $historica;

    private Cliente $maestro;

    private ClienteMaterial $actual;

    private ClienteMaterial $anterior;

    private ClienteMaterial $homonimo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->activa = TemporadaMaterial::where('activa', true)->firstOrFail();
        $global = Temporada::create(['codigo' => 'HIST-CAT', 'nombre' => 'Histórica', ...$this->vigenciaProductiva(), 'activa' => false]);
        $this->historica = TemporadaMaterial::firstOrCreate(['temporada_id' => $global->id], ['codigo' => $global->codigo, 'nombre' => $global->nombre, 'activa' => false]);
        $this->maestro = Cliente::create(['codigo' => 'CLI-A', 'nombre' => 'Cliente común', 'activo' => true]);
        $this->actual = $this->proyectar($this->maestro, $this->activa);
        $this->anterior = $this->proyectar($this->maestro, $this->historica);
        $otro = Cliente::create(['codigo' => 'CLI-B', 'nombre' => 'Cliente común', 'activo' => true]);
        $this->homonimo = $this->proyectar($otro, $this->activa);
        $this->item($this->actual, 'ACTUAL');
        $this->item($this->anterior, 'ANTERIOR');
        $this->item($this->homonimo, 'OTRO-CLIENTE');
    }

    public function test_un_cliente_maestro_se_ofrece_una_vez_y_no_fusiona_homonimos(): void
    {
        $clientes = collect($this->getJson(self::URL)->assertOk()->json('catalogos.clientes_maestros'));
        $this->assertCount(1, $clientes->where('id', $this->maestro->id));
        $this->assertSame(['CLI-A', 'CLI-B'], $clientes->where('nombre', 'Cliente común')->pluck('codigo')->sort()->values()->all());
        $respuesta = $this->getJson(self::URL.'?cliente_global_id='.$this->maestro->id)->assertOk()->assertJsonPath('total', 2);
        $this->assertEqualsCanonicalizing(['ACTUAL', 'ANTERIOR'], array_column($respuesta->json('data'), 'codigo'));
        $this->assertEqualsCanonicalizing(['ACTUAL', 'ANTERIOR'], $respuesta->json('catalogos.categorias'));
    }

    public function test_temporada_actual_es_explicita_y_el_cliente_se_resuelve_en_cada_periodo(): void
    {
        $this->getJson(self::URL.'?temporada_actual=1&cliente_global_id='.$this->maestro->id)
            ->assertOk()->assertJsonPath('temporada_seleccionada_id', $this->activa->id)
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.codigo', 'ACTUAL')
            ->assertJsonPath('catalogos.categorias', ['ACTUAL']);
        $this->getJson(self::URL.'?temporada_id='.$this->historica->id.'&cliente_global_id='.$this->maestro->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.codigo', 'ANTERIOR');
        $this->getJson(self::URL.'?cliente_id='.$this->anterior->id)
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.codigo', 'ANTERIOR');
    }

    public function test_csv_y_excel_respetan_el_cliente_global_y_la_temporada_sin_modificar_datos(): void
    {
        $antes = DB::table('items_materiales')->orderBy('id')->get()->toJson();
        $filtro = '?cliente_global_id='.$this->maestro->id;
        $csv = $this->get(self::URL.'/exportar/csv'.$filtro)->assertOk()->streamedContent();
        $this->assertStringContainsString('ACTUAL', $csv);
        $this->assertStringContainsString('ANTERIOR', $csv);
        $this->assertStringNotContainsString('OTRO-CLIENTE', $csv);
        $respuesta = $this->get(self::URL.'/exportar/xlsx'.$filtro.'&temporada_actual=1')->assertOk();
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        try {
            $archivo = new UploadedFile($ruta, 'catalogo.xlsx', null, null, true);
            $filas = app(LectorPlanillaMaterial::class)->leer($archivo);
            $this->assertSame(['ACTUAL'], array_column($filas, 'codigo'));
        } finally {
            @unlink($ruta);
        }
        $this->assertSame($antes, DB::table('items_materiales')->orderBy('id')->get()->toJson());
    }

    public function test_un_registro_historico_sin_maestro_conserva_su_identidad_y_sus_items(): void
    {
        $legacy = ClienteMaterial::create(['temporada_material_id' => $this->historica->id, 'codigo' => 'LEGADO', 'nombre' => 'Cliente común', 'activo' => false]);
        $this->item($legacy, 'LEGADO-ITEM');
        $respuesta = $this->getJson(self::URL.'?temporada_id='.$this->historica->id)->assertOk();
        $opcion = collect($respuesta->json('catalogos.clientes_maestros'))->firstWhere('id', $legacy->id);
        $this->assertFalse($opcion['es_maestro']);
        $this->getJson(self::URL.'?cliente_id='.$legacy->id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.codigo', 'LEGADO-ITEM');
        $this->getJson(self::URL.'?cliente_global_id='.$this->maestro->id)->assertOk()->assertJsonPath('total', 2);
    }

    public function test_valida_identidades_y_permisos_en_listado_y_exportacion(): void
    {
        $this->getJson(self::URL.'?cliente_global_id='.Str::uuid())->assertUnprocessable();
        $this->getJson(self::URL.'?cliente_global_id='.$this->maestro->id.'&cliente_id='.$this->actual->id)->assertUnprocessable();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorPrefrio]), 'sanctum');
        $this->getJson(self::URL)->assertForbidden();
        $this->get(self::URL.'/exportar/csv')->assertForbidden();
    }

    private function proyectar(Cliente $cliente, TemporadaMaterial $temporada): ClienteMaterial
    {
        return ClienteMaterial::create(['temporada_material_id' => $temporada->id, 'cliente_id' => $cliente->id, 'codigo' => $cliente->codigo, 'nombre' => $cliente->nombre, 'activo' => true]);
    }

    private function item(ClienteMaterial $cliente, string $codigo): ItemMaterial
    {
        return ItemMaterial::create(['cliente_material_id' => $cliente->id, 'codigo' => $codigo, 'nombre' => 'MATERIAL '.$codigo, 'categoria' => $codigo, 'categoria_operacional' => 'insumo', 'unidad_medida' => 'kg', 'activo' => true,
            'creado_por_user_id' => auth()->id(), 'actualizado_por_user_id' => auth()->id()]);
    }
}
