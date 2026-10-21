<?php

namespace Tests\Feature\Comex;

use App\Comex\Models\EventoComercial;
use App\Comex\Models\LugarComercial;
use App\Comex\Models\ParteComercial;
use App\Enums\RolUsuario;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogoComercialTest extends TestCase
{
    use PreparaOperacionComex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
        $this->actingAs($this->administradorComex());
    }

    public function test_identidad_chilena_valida_digito_y_no_duplica_rut_formateado(): void
    {
        $d = $this->parte();
        $this->postJson('/api/comex/comercial/partes', [...$d, 'tax_id' => '76000001-0'])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->postJson('/api/comex/comercial/partes', $d)->assertCreated()->assertJsonPath('data.tax_id', '76000001-9')->assertJsonPath('data.roles.1', 'exportador');
        $this->postJson('/api/comex/comercial/partes', [...$d, 'operacion_id' => (string) Str::uuid(), 'codigo' => 'OTRA', 'tax_id' => '76.000.001-9'])->assertUnprocessable();
        $this->postJson('/api/comex/comercial/partes', [...$d, 'codigo' => 'CERO', 'tax_id' => '0-0', 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(1, ParteComercial::query()->count());
    }

    public function test_tax_id_extranjero_se_identifica_por_pais_sin_imponer_rut_chileno(): void
    {
        $d = [...$this->parte(), 'codigo' => 'BUYER_US', 'pais_iso2' => 'US', 'tax_id' => 'AB-123', 'roles' => ['comprador', 'recibidor']];
        $this->postJson('/api/comex/comercial/partes', $d)->assertCreated();
        $this->postJson('/api/comex/comercial/partes', [...$d, 'codigo' => 'BUYER_CN', 'pais_iso2' => 'CN', 'operacion_id' => (string) Str::uuid()])->assertCreated();
        $this->postJson('/api/comex/comercial/partes', [...$d, 'codigo' => 'OTRO', 'tax_id' => 'ab-123', 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/comex/comercial/partes?rol=recibidor')->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_reintentar_devuelve_respuesta_original_y_no_duplica_eventos(): void
    {
        $d = $this->parte();
        $id = $this->postJson('/api/comex/comercial/partes', $d)->assertCreated()->json('data.id');
        $this->postJson('/api/comex/comercial/partes', $d)->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.version', 1);
        $this->postJson('/api/comex/comercial/partes', [...$d, 'razon_social' => 'Otra solicitud'])->assertUnprocessable()->assertJsonValidationErrors('operacion_id');
        $this->assertSame(1, EventoComercial::query()->count());
        $cambio = [...$d, 'operacion_id' => (string) Str::uuid(), 'version' => 1, 'razon_social' => 'Nombre corregido', 'motivo' => 'Corrección contra antecedente verificado'];
        $this->putJson('/api/comex/comercial/partes/'.$id, $cambio)->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson('/api/comex/comercial/partes', $d)->assertCreated()->assertJsonPath('data.razon_social', $d['razon_social']);
        $this->assertSame('Nombre corregido', ParteComercial::query()->findOrFail($id)->razon_social);
    }

    public function test_edicion_exige_version_identidad_permanente_y_motivo_con_actor(): void
    {
        $d = $this->parte();
        $id = $this->postJson('/api/comex/comercial/partes', $d)->assertCreated()->json('data.id');
        $u = [...$d, 'operacion_id' => (string) Str::uuid(), 'version' => 1, 'motivo' => 'Actualización documentada de los antecedentes'];
        $this->putJson('/api/comex/comercial/partes/'.$id, [...$u, 'tax_id' => '76000002-7'])->assertUnprocessable()->assertJsonValidationErrors('tax_id');
        $this->putJson('/api/comex/comercial/partes/'.$id, [...$u, 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson('/api/comex/comercial/partes/'.$id, [...$u, 'activo' => false])->assertOk()->assertJsonPath('data.activo', false);
        $this->getJson('/api/comex/comercial/historial?entidad=parte&entidad_id='.$id)->assertOk()->assertJsonPath('data.data.0.datos.antes.activo', true)->assertJsonPath('data.data.0.datos.despues.activo', false);
        $this->assertNotEmpty(EventoComercial::query()->latest('id')->firstOrFail()->user_nombre);
    }

    public function test_vinculo_con_cliente_es_explicito_unico_y_solo_lee_operacion(): void
    {
        $cliente = $this->clienteOperacion('Cliente de planta');
        $consultas = [];
        DB::listen(function (QueryExecuted $e) use (&$consultas): void {
            if ($e->connectionName === config('database.default')) {
                $consultas[] = $e->sql;
            }
        });
        $d = [...$this->parte(), 'cliente_operacion_id' => $cliente];
        $id = $this->postJson('/api/comex/comercial/partes', $d)->assertCreated()->assertJsonPath('data.cliente_operacion.nombre', 'Cliente de planta')->json('data.id');
        $this->postJson('/api/comex/comercial/partes', [...$d, 'codigo' => 'OTRA', 'tax_id' => '76000002-7', 'operacion_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('cliente_operacion_id');
        $this->putJson('/api/comex/comercial/partes/'.$id, [...$d, 'cliente_operacion_id' => null, 'operacion_id' => (string) Str::uuid(), 'version' => 1, 'motivo' => 'Intento de cambio de vínculo permanente'])->assertUnprocessable();
        $this->getJson('/api/comex/comercial/clientes-operacion?buscar=planta')->assertOk()->assertJsonCount(1, 'data.data');
        $this->assertNotEmpty($consultas);
        foreach ($consultas as $sql) {
            $this->assertMatchesRegularExpression('/^select\s/i', trim($sql), $sql);
        }
    }

    public function test_terminal_exige_puerto_del_mismo_pais_y_lugares_guardan_historia(): void
    {
        $d = ['operacion_id' => (string) Str::uuid(), 'codigo' => 'CL_TEST', 'tipo' => 'puerto', 'nombre' => 'Puerto de prueba', 'pais_iso2' => 'CL', 'localidad' => 'Localidad de prueba', 'activo' => true, 'version' => 0, 'respaldo' => 'Datos del lugar verificados en su fuente'];
        $id = $this->postJson('/api/comex/comercial/lugares', $d)->assertCreated()->json('data.id');
        $terminal = [...$d, 'codigo' => 'TERM_TEST', 'tipo' => 'terminal', 'puerto_id' => $id, 'operacion_id' => (string) Str::uuid()];
        $this->postJson('/api/comex/comercial/lugares', [...$terminal, 'pais_iso2' => 'CN'])->assertUnprocessable()->assertJsonValidationErrors('puerto_id');
        $this->postJson('/api/comex/comercial/lugares', $terminal)->assertCreated();
        $this->postJson('/api/comex/comercial/lugares', [...$d, 'codigo' => 'SIN_PAIS', 'pais_iso2' => 'ZZ', 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(2, LugarComercial::query()->count());
    }

    public function test_perfiles_documentales_no_reciben_comercial_y_consulta_no_escribe(): void
    {
        $this->actingAs($this->conPerfil(RolUsuario::Despachador, ['comex.expedientes']))->getJson('/api/comex/comercial/partes')->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['comex.comercial']))->getJson('/api/comex/comercial/contexto')->assertOk()->assertJsonPath('permisos.gestionar', false)->assertJsonPath('permisos.aprobar', false);
        $this->postJson('/api/comex/comercial/partes', $this->parte())->assertForbidden();
        $this->getJson('/api/acceso-oficina')->assertOk()->assertJsonPath('usuario.puede_consultar_comercial_comex', true);
        $this->actingAs($this->conPerfil(RolUsuario::Despachador, ['comex.comercial']))->postJson('/api/comex/comercial/partes', $this->parte())->assertCreated();
    }

    private function parte(): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'codigo' => 'EXPORTADOR_TEST', 'pais_iso2' => 'CL', 'tax_id' => '76000001-9', 'razon_social' => 'Parte de prueba', 'direccion' => 'Dirección de prueba 123', 'ciudad' => 'Ciudad de prueba', 'roles' => ['vendedor', 'exportador'], 'activo' => true, 'version' => 0, 'respaldo' => 'Antecedentes comerciales verificados'];
    }
}
