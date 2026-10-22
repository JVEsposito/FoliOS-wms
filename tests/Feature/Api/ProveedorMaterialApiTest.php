<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ClienteProveedorMaterial;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProveedorMaterialApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ClienteMaterial $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($this->admin, 'sanctum');
        $this->cliente = ClienteMaterial::query()->where('codigo', 'GENERAL')->firstOrFail();
        ItemMaterial::create([
            'cliente_material_id' => $this->cliente->id,
            'codigo' => 'CAJA-PRUEBA', 'nombre' => 'Caja prueba', 'categoria' => 'Embalaje',
            'categoria_operacional' => 'material_mp', 'unidad_medida' => 'unidades',
            'activo' => true, 'origen_sistema' => 'manual',
        ]);
    }

    public function test_genera_correlativos_automaticos_sin_exigir_un_codigo_manual(): void
    {
        foreach (['PRV-001', 'PRV-002'] as $codigo) {
            $this->postJson('/api/administracion/materiales/proveedores', $this->datos())
                ->assertCreated()
                ->assertJsonPath('data.codigo_interno', $codigo)
                ->assertJsonPath('data.codigo', $codigo);
        }

        $this->assertDatabaseCount('proveedores_materiales', 2);
    }

    public function test_conserva_el_codigo_comercial_y_el_correlativo_al_editar(): void
    {
        $id = $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo' => ' csf ']))
            ->assertCreated()->assertJsonPath('data.codigo', 'CSF')->json('data.id');

        $this->putJson('/api/administracion/materiales/proveedores/'.$id, $this->datos(['nombre' => 'Proveedor actualizado']))
            ->assertOk()->assertJsonPath('data.codigo', 'CSF')
            ->assertJsonPath('data.codigo_interno', 'PRV-001');

        $this->putJson('/api/administracion/materiales/proveedores/'.$id, $this->datos(['codigo' => '']))
            ->assertOk()->assertJsonPath('data.codigo', 'PRV-001')
            ->assertJsonPath('data.codigo_interno', 'PRV-001');
    }

    public function test_rechaza_duplicados_comerciales_aunque_cambien_los_separadores_o_el_proveedor_este_inactivo(): void
    {
        $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo' => 'CSF-01', 'activo' => false]))
            ->assertCreated();

        foreach (['csf01', 'CSF.01', 'CSF_01'] as $codigo) {
            $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo' => $codigo]))
                ->assertUnprocessable()->assertJsonValidationErrors('codigo');
        }

        $this->assertDatabaseCount('proveedores_materiales', 1);
    }

    public function test_no_permite_escoger_o_cambiar_el_codigo_interno(): void
    {
        $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo_interno' => 'PRV-999']))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo_interno');

        $id = $this->postJson('/api/administracion/materiales/proveedores', $this->datos())
            ->assertCreated()->assertJsonPath('data.codigo_interno', 'PRV-001')->json('data.id');

        $this->putJson('/api/administracion/materiales/proveedores/'.$id, $this->datos(['codigo_interno' => 'PRV-999']))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo_interno');

        $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo' => 'prv001']))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo');
    }

    public function test_salta_codigos_historicos_equivalentes_y_continua_mas_alla_de_999(): void
    {
        DB::table('proveedores_materiales')->insert([
            'id' => (string) Str::uuid(), 'codigo' => 'PRV001', 'nombre' => 'Proveedor histórico',
            'activo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/administracion/materiales/proveedores', $this->datos())
            ->assertCreated()->assertJsonPath('data.codigo_interno', 'PRV-002');

        DB::table('correlativos_proveedores_materiales')->where('id', 1)->update(['ultimo_numero' => 999]);
        $this->postJson('/api/administracion/materiales/proveedores', $this->datos())
            ->assertCreated()->assertJsonPath('data.codigo_interno', 'PRV-1000');
    }

    public function test_una_operacion_fallida_no_deja_un_proveedor_ni_un_correlativo_confirmado(): void
    {
        try {
            DB::transaction(function (): void {
                ProveedorMaterial::create(['nombre' => 'Proveedor no confirmado', 'activo' => true]);
                throw new RuntimeException('Falla posterior al alta.');
            });
        } catch (RuntimeException) {
            // Se comprueba el efecto observable de la reversión completa.
        }

        $this->assertDatabaseCount('proveedores_materiales', 0);
        $this->postJson('/api/administracion/materiales/proveedores', $this->datos())
            ->assertCreated()->assertJsonPath('data.codigo_interno', 'PRV-001');
    }

    public function test_busca_por_codigo_nombre_y_cliente_y_combina_estado_y_vinculos_activos(): void
    {
        $primero = $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo' => 'CSF-01', 'nombre' => 'Cartonera Norte']))
            ->assertCreated()->json('data.id');
        $segundo = $this->postJson('/api/administracion/materiales/proveedores', $this->datos(['codigo' => 'SUR', 'nombre' => 'Adhesivos Sur', 'activo' => false]))
            ->assertCreated()->json('data.id');
        $otroCliente = Cliente::create(['codigo' => 'FAL', 'nombre' => 'Falco', 'activo' => true]);
        ClienteProveedorMaterial::create([
            'cliente_id' => $otroCliente->id, 'proveedor_material_id' => $segundo,
            'activo' => true, 'categorias' => ['Embalaje'],
        ]);

        foreach (['prv001', 'csf01', 'norte'] as $texto) {
            $this->getJson('/api/administracion/materiales/proveedores?q='.$texto)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $primero);
        }
        $this->getJson('/api/administracion/materiales/proveedores?q=Falco&estado=inactivo&cliente_id='.$otroCliente->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $segundo);
        $this->getJson('/api/administracion/materiales/proveedores?estado=activo')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $primero);

        DB::table('clientes_proveedores_materiales')->where('cliente_id', $otroCliente->id)->update(['activo' => false]);
        $this->getJson('/api/administracion/materiales/proveedores?cliente_id='.$otroCliente->id)
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/administracion/materiales/proveedores?q=Falco')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/administracion/materiales/proveedores')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_los_filtros_y_las_altas_conservan_la_autorizacion_del_catalogo(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales, 'activo' => true]), 'sanctum');
        $this->getJson('/api/administracion/materiales/proveedores?q=PRV001')->assertForbidden();
        $this->postJson('/api/administracion/materiales/proveedores', $this->datos())->assertForbidden();
        $this->assertDatabaseCount('proveedores_materiales', 0);
    }

    private function datos(array $cambios = []): array
    {
        return [
            'nombre' => 'Proveedor de materiales', 'activo' => true,
            'cliente_ids' => [$this->cliente->cliente_id],
            'categorias' => [['cliente_id' => $this->cliente->cliente_id, 'categoria' => 'Embalaje']],
            ...$cambios,
        ];
    }
}
