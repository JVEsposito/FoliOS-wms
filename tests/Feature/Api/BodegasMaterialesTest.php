<?php

namespace Tests\Feature\Api;

use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Materiales\StockMaterialEnPlano;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BodegasMaterialesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('rol');
            $t->boolean('activo');
        });
        Schema::create('destinos_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('codigo')->unique();
            $t->string('nombre');
            $t->string('tipo');
            $t->string('centro_costo');
            $t->text('descripcion')->nullable();
            $t->boolean('activo');
            $t->boolean('requiere_ubicacion_fisica');
            $t->string('origen_sistema');
            $t->unsignedBigInteger('creado_por_user_id');
            $t->unsignedBigInteger('actualizado_por_user_id');
            $t->timestamps();
        });
        Schema::create('despachos_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('estado');
        });
        Schema::create('retiros_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('camara_id');
        });
        Schema::create('saldos_materiales_almacenes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('almacen_material_id');
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3)->default(0);
            $t->uuid('camara_id')->nullable();
            $t->uuid('posicion_id')->nullable();
            $t->unsignedBigInteger('version')->default(0);
        });
        (require database_path('migrations/2026_10_28_120000_habilitar_bodegas_fisicas_materiales.php'))->up();
        DB::table('users')->insert(['id' => 1, 'name' => 'Ana Pérez', 'rol' => 'administrador', 'activo' => true]);
        $this->actingAs(User::findOrFail(1), 'sanctum');
    }

    private function datos(array $c = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => 'BOD-2', 'nombre' => 'Bodega secundaria', 'activo' => true, 'requiere_ubicacion_fisica' => false, 'motivo' => 'Habilitar recinto de materiales'], ...$c];
    }

    private function crear(array $c = []): array
    {
        return $this->postJson('/api/materiales/bodegas', $this->datos($c))->assertCreated()->json('data');
    }

    public function test_catalogo_audita_actor_reintento_version_y_normaliza_codigo(): void
    {
        $d = $this->datos(['codigo' => ' bod-2 ']);
        $a = $this->postJson('/api/materiales/bodegas', $d)->assertCreated()->assertJsonPath('data.codigo', 'BOD-2')->assertJsonPath('data.version', 1)->json('data');
        $this->postJson('/api/materiales/bodegas', $d)->assertCreated()->assertJsonPath('data.id', $a['id']);
        $this->assertDatabaseCount('destinos_materiales', 1);
        $this->assertDatabaseCount('auditoria_bodegas_materiales', 1);
        $this->postJson('/api/materiales/bodegas', [...$d, 'nombre' => 'Otro recinto'])->assertConflict();
        $this->postJson('/api/materiales/bodegas', $this->datos())->assertUnprocessable();
        $this->putJson('/api/materiales/bodegas/'.$a['id'], $this->datos(['version' => 1, 'nombre' => 'Bodega Embalajes']))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/materiales/bodegas/'.$a['id'], $this->datos(['version' => 1]))->assertConflict();
        $this->putJson('/api/materiales/bodegas/'.$a['id'], $this->datos(['version' => 2, 'codigo' => 'CAMBIADO']))->assertUnprocessable();
        $this->getJson('/api/materiales/bodegas/historial?almacen_id='.$a['id'])->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.usuario', 'Ana Pérez')->assertDontSee('payload_hash');
        $this->getJson('/api/materiales/bodegas')->assertOk()->assertJsonPath('puede_administrar', true)->assertJsonPath('data.0.con_existencias', false);
    }

    public function test_no_se_desactiva_ni_cambia_modo_con_stock_o_ordenes_y_central_es_protegida(): void
    {
        $a = $this->crear();
        $url = '/api/materiales/bodegas/'.$a['id'];
        DB::table('saldos_materiales_almacenes')->insert(['id' => (string) Str::uuid(), 'folio_id' => (string) Str::uuid(), 'almacen_material_id' => $a['id'], 'cantidad_actual' => 10, 'cantidad_reservada' => 3]);
        foreach ([['activo' => false], ['requiere_ubicacion_fisica' => true]] as $c) {
            $this->putJson($url, $this->datos(['version' => 1, ...$c]))->assertUnprocessable();
        }
        $this->getJson('/api/materiales/bodegas')->assertOk()->assertJsonPath('data.0.con_existencias', true);
        DB::table('saldos_materiales_almacenes')->delete();
        DB::table('despachos_materiales')->insert(['id' => (string) Str::uuid(), 'estado' => 'pendiente', 'almacen_origen_id' => $a['id']]);
        $this->putJson($url, $this->datos(['version' => 1, 'activo' => false]))->assertUnprocessable();
        DB::table('despachos_materiales')->update(['estado' => 'completado']);
        $this->putJson($url, $this->datos(['version' => 1, 'activo' => false]))->assertOk();
        DB::table('destinos_materiales')->where('id', $a['id'])->update(['codigo' => 'BOD-CENTRAL', 'activo' => true, 'requiere_ubicacion_fisica' => true]);
        $this->putJson($url, $this->datos(['version' => 2, 'codigo' => 'BOD-CENTRAL', 'activo' => false, 'requiere_ubicacion_fisica' => true]))->assertUnprocessable();
    }

    public function test_no_se_editan_centros_de_costo_y_lecturas_no_crean_saldos(): void
    {
        $a = $this->crear();
        $folio = (string) Str::uuid();
        $url = '/api/materiales/bodegas/'.$a['id'].'/saldo?folio_id='.$folio;
        $this->getJson($url)->assertOk()->assertJsonPath('version', 0)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('saldos_materiales_almacenes', 0);
        DB::table('saldos_materiales_almacenes')->insert(['id' => (string) Str::uuid(), 'folio_id' => $folio, 'almacen_material_id' => $a['id'], 'cantidad_actual' => 0, 'version' => 9]);
        $this->getJson($url)->assertOk()->assertJsonPath('version', 9);
        DB::table('destinos_materiales')->where('id', $a['id'])->update(['tipo' => 'virtual']);
        $this->getJson('/api/materiales/bodegas')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/materiales/bodegas/'.$a['id'], $this->datos(['version' => 1]))->assertUnprocessable();
        $this->getJson($url)->assertNotFound();
    }

    public function test_permiso_historico_no_gana_escritura_y_sesion_inactiva_no_lee(): void
    {
        $a = $this->crear();
        $u = User::findOrFail(1);
        $p = new PerfilAcceso;
        $p->forceFill(['id' => 1, 'codigo' => 'HIST', 'rol_base' => 'administrador', 'modulos' => ['materiales.inventario'], 'modulos_tablet' => [], 'permisos_materiales' => null, 'activo' => true, 'protegido' => false]);
        $u->perfil_acceso_id = $p->id;
        $u->setRelation('perfilAcceso', $p);
        $this->actingAs($u, 'sanctum');
        $this->getJson('/api/materiales/bodegas')->assertOk()->assertJsonPath('puede_administrar', false);
        $this->postJson('/api/materiales/bodegas', $this->datos(['codigo' => 'BOD-3']))->assertForbidden();
        $p->permisos_materiales = ['bodegas.administrar'];
        $this->postJson('/api/materiales/bodegas', $this->datos(['codigo' => 'BOD-3']))->assertCreated();
        $this->assertFalse($u->can('trasladar-bodegas-materiales'));
        $u->activo = false;
        $this->getJson('/api/materiales/bodegas')->assertForbidden();
    }

    public function test_plano_muestra_solo_stock_de_la_camara_y_cachea_por_solicitud(): void
    {
        $a = $this->crear();
        $folio = (string) Str::uuid();
        $camara = (string) Str::uuid();
        $posicion = (string) Str::uuid();
        foreach ([['camara_id' => $camara, 'posicion_id' => $posicion, 'cantidad_actual' => 6, 'cantidad_reservada' => 2], ['camara_id' => null, 'posicion_id' => null, 'cantidad_actual' => 14, 'cantidad_reservada' => 0]] as $s) {
            DB::table('saldos_materiales_almacenes')->insert(['id' => (string) Str::uuid(), 'folio_id' => $folio, 'almacen_material_id' => $a['id'], ...$s]);
        }
        $s = app(StockMaterialEnPlano::class);
        DB::enableQueryLog();
        $this->assertSame(['actual' => '6.000', 'reservada' => '2.000'], $s->cantidades($folio, $camara, $posicion));
        $s->cantidades($folio, $camara, $posicion);
        $s->cantidades((string) Str::uuid(), $camara, null);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        DB::table('saldos_materiales_almacenes')->whereNotNull('camara_id')->update(['cantidad_actual' => 5]);
        $this->app->instance('request',Request::create('/otra'));
        $this->assertSame('5.000',$s->cantidades($folio,$camara,$posicion)['actual']);
    }
}
