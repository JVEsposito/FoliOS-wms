<?php

namespace Tests\Feature\Comex;

use App\Comex\Comercial\NotasVenta;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\EventoComercial;
use App\Comex\Models\NotaVentaRevision;
use App\Comex\Models\ParteComercial;
use App\Enums\RolUsuario;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotasVentaTest extends TestCase
{
    use PreparaComercial;
    use PreparaOperacionComex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
        $this->actingAs($this->administradorComex());
    }

    public function test_aprobacion_requiere_otro_actor_y_conserva_acuerdo_al_preparar_reemplazo(): void
    {
        $d = $this->datosNota();
        $data = $this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->assertJsonPath('data.propuesta.datos.valorizacion.total', '1215.00')->json('data');
        $data = $this->pasoNota($data, 'enviar_revision');
        $this->postJson('/api/comex/comercial/notas/'.$data['nota']['id'].'/transiciones', ['operacion_id' => (string) Str::uuid(), 'version' => 2, 'accion' => 'aprobar', 'motivo' => 'Intento de aprobación por el preparador'])->assertUnprocessable()->assertJsonValidationErrors('aprobador');
        $this->actingAs($this->administradorComex());
        $data = $this->pasoNota($data, 'aprobar');
        $primera = $data['aprobada']['id'];
        $data = $this->pasoNota($data, 'nueva_revision');
        $this->assertSame($primera, $data['nota']['revision_activa_id']);
        $this->assertSame('aprobada', $data['aprobada']['estado']);
        $this->assertSame('borrador', $data['propuesta']['estado']);
        $this->assertSame(2, $data['propuesta']['numero']);
        $this->assertNotEmpty(EventoComercial::query()->latest('id')->firstOrFail()->user_nombre);
    }

    public function test_reintento_no_duplica_y_rechazo_no_elimina_version_aprobada(): void
    {
        config(['comex.aprobador_distinto' => false]);
        $d = $this->datosNota();
        $data = $this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->json('data');
        $this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->assertJsonPath('data.nota.id', $data['nota']['id']);
        $data = $this->pasoNota($this->pasoNota($data, 'enviar_revision'), 'aprobar');
        $id = $data['aprobada']['id'];
        $data = $this->pasoNota($data, 'nueva_revision');
        $data = $this->pasoNota($this->pasoNota($data, 'enviar_revision'), 'rechazar');
        $this->assertSame($id, $data['aprobada']['id']);
        $this->assertSame('rechazada', $data['propuesta']['estado']);
        $this->assertSame(2, NotaVentaRevision::query()->count());
    }

    public function test_identidad_cambiada_exige_actualizar_borrador_y_copia_aprobada_no_muta(): void
    {
        config(['comex.aprobador_distinto' => false]);
        $d = $this->datosNota();
        $data = $this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->json('data');
        $data = $this->pasoNota($this->pasoNota($data, 'enviar_revision'), 'aprobar');
        ParteComercial::query()->findOrFail($d['partes']['vendedor'])->update(['razon_social' => 'Nueva denominación', 'version' => 2]);
        $this->getJson('/api/comex/comercial/notas/'.$data['nota']['id'])->assertOk()->assertJsonPath('data.aprobada.datos.partes.vendedor.razon_social', 'Comercial ficticia 商业');
        $data = $this->pasoNota($data, 'nueva_revision');
        $url = '/api/comex/comercial/notas/'.$data['nota']['id'];
        $this->postJson($url.'/transiciones', ['operacion_id' => (string) Str::uuid(), 'version' => $data['nota']['version'], 'accion' => 'enviar_revision', 'motivo' => 'Propuesta con antecedente ya modificado'])->assertUnprocessable()->assertJsonValidationErrors('partes');
        $this->putJson($url.'/borrador', [...$d, 'operacion_id' => (string) Str::uuid(), 'version' => $data['nota']['version'], 'motivo' => 'Actualizar antecedentes comerciales verificados'])->assertOk()->assertJsonPath('data.propuesta.datos.partes.vendedor.razon_social', 'Nueva denominación')->assertJsonPath('data.aprobada.datos.partes.vendedor.razon_social', 'Comercial ficticia 商业');
    }

    public function test_no_infiere_exportacion_propia_ni_roles_y_bloquea_cliente_inactivo(): void
    {
        $d = $this->datosNota();
        $this->postJson('/api/comex/comercial/notas', [...$d, 'gestion' => 'propia'])->assertUnprocessable();
        ParteComercial::query()->findOrFail($d['partes']['comprador'])->update(['roles' => ['vendedor']]);
        $this->postJson('/api/comex/comercial/notas', $d)->assertUnprocessable()->assertJsonValidationErrors('partes');
        ParteComercial::query()->findOrFail($d['partes']['comprador'])->update(['roles' => NotasVenta::ROLES]);
        DB::table('clientes')->where('id', $d['cliente_operacion_id'])->update(['activo' => false]);
        $this->postJson('/api/comex/comercial/notas', $d)->assertUnprocessable()->assertJsonValidationErrors('cliente_operacion_id');
    }

    public function test_gestion_configurada_propia_se_conserva_y_nota_no_escribe_en_operacion(): void
    {
        $d = $this->datosNota();
        ClienteComex::query()->create(['cliente_id' => $d['cliente_operacion_id'], 'cliente_nombre' => 'Cliente de prueba', 'gestion' => 'propia', 'version' => 1]);
        $consultas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            if ($q->connectionName === config('database.default')) {
                $consultas[] = $q->sql;
            }
        });
        $this->postJson('/api/comex/comercial/notas', [...$d, 'gestion' => 'propia'])->assertCreated()->assertJsonPath('data.nota.gestion', 'propia');
        $this->assertNotEmpty($consultas);
        foreach ($consultas as $sql) {
            $this->assertMatchesRegularExpression('/^select\s/i', trim($sql));
        }
    }

    public function test_no_modifica_folio_ni_lineas_ajenas_ni_precio_aprobado(): void
    {
        config(['comex.aprobador_distinto' => false]);
        $d = $this->datosNota();
        $data = $this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->json('data');
        $u = [...$d, 'operacion_id' => (string) Str::uuid(), 'version' => 1, 'motivo' => 'Modificación comercial documentada'];
        $url = '/api/comex/comercial/notas/'.$data['nota']['id'];
        $this->putJson($url.'/borrador', [...$u, 'folio' => 'OTRA'])->assertUnprocessable();
        $this->putJson($url.'/borrador', [...$u, 'lineas' => [[...$d['lineas'][0], 'id' => (string) Str::uuid()]]])->assertUnprocessable();
        $data = $this->pasoNota($this->pasoNota($data, 'enviar_revision'), 'aprobar');
        $this->putJson($url.'/borrador', [...$u, 'version' => $data['nota']['version']])->assertUnprocessable();
        $this->putJson($url.'/borrador', [...$u, 'version' => 1])->assertUnprocessable();
    }

    public function test_precio_abierto_y_modalidad_marcan_limites_del_acuerdo(): void
    {
        $d = $this->datosNota();
        $this->postJson('/api/comex/comercial/notas', [...$d, 'modalidad' => 'aereo'])->assertUnprocessable()->assertJsonValidationErrors('incoterm');
        unset($d['lineas'][0]['precio']);
        $this->postJson('/api/comex/comercial/notas', $d)->assertUnprocessable();
        $this->postJson('/api/comex/comercial/notas', [...$d, 'negocio' => 'consignacion'])->assertCreated()->assertJsonPath('data.propuesta.datos.valorizacion.total', null);
    }

    public function test_permisos_separan_consulta_preparacion_y_decision(): void
    {
        $d = $this->datosNota();
        $data = $this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->json('data');
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['comex.comercial']));
        $this->getJson('/api/comex/comercial/notas')->assertOk();
        $this->postJson('/api/comex/comercial/notas', $d)->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Despachador, ['comex.comercial']));
        $data = $this->pasoNota($data, 'enviar_revision');
        $this->postJson('/api/comex/comercial/notas/'.$data['nota']['id'].'/transiciones', ['operacion_id' => (string) Str::uuid(), 'version' => $data['nota']['version'], 'accion' => 'aprobar', 'motivo' => 'Sin habilitación para aprobar acuerdos'])->assertForbidden();
    }
}
