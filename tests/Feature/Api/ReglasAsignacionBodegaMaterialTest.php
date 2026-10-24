<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Models\FolioMaterial;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Materiales\ServicioAlmacenMaterial;
use App\Services\Materiales\ServicioAsignacionBodegaMaterial;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReglasAsignacionBodegaMaterialTest extends TestCase
{
    private string $norte;

    private string $central;

    private string $cliente;

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
        Schema::create('clientes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('codigo');
            $t->string('nombre');
            $t->boolean('activo');
        });
        Schema::create('destinos_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('codigo');
            $t->string('nombre');
            $t->string('tipo');
            $t->boolean('activo');
        });
        Schema::create('folios_materiales', function (Blueprint $t) {
            $t->uuid('folio_id')->primary();
        });
        (require database_path('migrations/2026_10_29_120000_asignacion_bodega_recepciones_materiales.php'))->up();
        $this->norte = (string) Str::uuid();
        $this->central = (string) Str::uuid();
        $this->cliente = (string) Str::uuid();
        DB::table('users')->insert(['id' => 1, 'name' => 'Ana Pérez', 'rol' => 'administrador', 'activo' => true]);
        DB::table('clientes')->insert(['id' => $this->cliente, 'codigo' => 'CLI', 'nombre' => 'Cliente de prueba', 'activo' => true]);
        DB::table('destinos_materiales')->insert([['id' => $this->norte, 'codigo' => 'BOD-N', 'nombre' => 'Norte', 'tipo' => 'fisica', 'activo' => true], ['id' => $this->central, 'codigo' => 'BOD-CENTRAL', 'nombre' => 'Central', 'tipo' => 'fisica', 'activo' => true]]);
        $this->actingAs(User::findOrFail(1), 'sanctum');
    }

    private function datos(array $c = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version' => 0, 'nombre' => 'Materiales a Norte', 'prioridad' => 10, 'cliente_id' => null, 'categoria_operacional' => null, 'almacen_material_id' => $this->norte, 'activo' => true, 'motivo' => 'Separar ingreso en bodega Norte'], ...$c];
    }

    private function elegir(string $categoria = 'insumo'): array
    {
        return DB::transaction(fn () => app(ServicioAsignacionBodegaMaterial::class)->elegir($this->cliente, CategoriaOperacionalMaterial::from($categoria), User::findOrFail(1)));
    }

    public function test_migracion_no_reasigna_inventario_y_default_es_central(): void
    {
        $this->assertDatabaseCount('reglas_bodegas_materiales', 0);
        $d = $this->elegir();
        $this->assertSame($this->central, $d['almacen_id']);
        $this->assertSame('central_por_defecto', $d['origen']);
        $this->assertNull($d['regla']);
        $this->assertSame('pendiente', $d['ubicacion_fisica']);
        $this->assertSame('pendiente_verificacion', $d['capacidad']);
        $this->assertDatabaseCount('folios_materiales', 0);
    }

    public function test_alta_reintento_version_y_auditoria_no_duplican_ni_reescriben(): void
    {
        $d = $this->datos();
        $a = $this->postJson('/api/materiales/asignacion-bodegas', $d)->assertCreated()->assertJsonPath('data.version', 1)->json('data');
        $this->postJson('/api/materiales/asignacion-bodegas', $d)->assertCreated()->assertJsonPath('data.id', $a['id']);
        $this->postJson('/api/materiales/asignacion-bodegas', [...$d, 'nombre' => 'Distinto'])->assertConflict();
        $this->putJson('/api/materiales/asignacion-bodegas/'.$a['id'], $this->datos(['version' => 1, 'activo' => false]))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/materiales/asignacion-bodegas/'.$a['id'], $this->datos(['version' => 1]))->assertConflict();
        $this->getJson('/api/materiales/asignacion-bodegas/historial')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.usuario', 'Ana Pérez')->assertDontSee('payload_hash');
        $this->assertDatabaseCount('reglas_bodegas_materiales', 1);
        $this->assertDatabaseCount('eventos_reglas_bodegas_materiales', 2);
    }

    public function test_prioridad_y_filtros_de_cliente_y_tipo_no_se_mezclan(): void
    {
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['cliente_id' => $this->cliente, 'categoria_operacional' => 'material_mp']))->assertCreated();
        $this->assertSame($this->central, $this->elegir()['almacen_id']);
        $this->assertSame($this->norte, $this->elegir('material_mp')['almacen_id']);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['prioridad' => 5, 'almacen_material_id' => $this->central]))->assertCreated();
        $this->assertSame($this->central, $this->elegir('material_mp')['almacen_id']);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos())->assertUnprocessable()->assertJsonValidationErrors('prioridad');
    }

    public function test_cliente_distinto_regla_inactiva_y_bodega_inactiva_no_se_aplican(): void
    {
        $otro = (string) Str::uuid();
        DB::table('clientes')->insert(['id' => $otro, 'codigo' => 'OTR', 'nombre' => 'Otro cliente', 'activo' => true]);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['cliente_id' => $otro]))->assertCreated();
        $this->assertSame($this->central, $this->elegir()['almacen_id']);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['prioridad' => 20, 'activo' => false]))->assertCreated();
        $this->assertSame($this->central, $this->elegir()['almacen_id']);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['prioridad' => 30]))->assertCreated();
        DB::table('destinos_materiales')->where('id', $this->norte)->update(['activo' => false]);
        $this->assertSame($this->central, $this->elegir()['almacen_id']);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['prioridad' => 40]))->assertUnprocessable();
        DB::table('destinos_materiales')->where('id', $this->norte)->update(['activo' => true, 'tipo' => 'virtual']);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['prioridad' => 50]))->assertUnprocessable();
    }

    public function test_consulta_no_concede_escritura_y_regla_exige_motivo_y_version(): void
    {
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['motivo' => 'x']))->assertUnprocessable();
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos(['categoria_operacional' => 'inventada']))->assertUnprocessable();
        $u = User::findOrFail(1);
        $p = (new PerfilAcceso)->forceFill(['id' => (string) Str::uuid(), 'rol_base' => 'administrador', 'modulos' => ['materiales.inventario'], 'modulos_tablet' => [], 'permisos_materiales' => [], 'activo' => true, 'protegido' => false]);
        $u->perfil_acceso_id = $p->id;
        $u->setRelation('perfilAcceso', $p);
        $this->actingAs($u, 'sanctum');
        $this->getJson('/api/materiales/asignacion-bodegas')->assertOk()->assertJsonPath('puede_configurar', false);
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos())->assertForbidden();
        $p->permisos_materiales = ['capacidad.configurar'];
        $this->postJson('/api/materiales/asignacion-bodegas', $this->datos())->assertCreated();
        $u->activo = false;
        $this->getJson('/api/materiales/asignacion-bodegas')->assertForbidden();
    }

    public function test_reintento_de_otro_actor_no_recupera_operacion_anterior(): void
    {
        $d = $this->datos();
        $this->postJson('/api/materiales/asignacion-bodegas', $d)->assertCreated();
        DB::table('users')->insert(['id' => 2, 'name' => 'Otro administrador', 'rol' => 'administrador', 'activo' => true]);
        $this->actingAs(User::findOrFail(2), 'sanctum');
        $this->postJson('/api/materiales/asignacion-bodegas', $d)->assertConflict();
        $this->assertDatabaseCount('eventos_reglas_bodegas_materiales', 1);
    }

    public function test_saldo_y_primera_ubicacion_siguen_bodega_inicial_sin_duplicar_central(): void
    {
        Schema::create('folios', function (Blueprint $t) {
            $t->uuid('id')->primary();
        });
        Schema::create('ubicaciones_actuales', function (Blueprint $t) {
            $t->uuid('folio_id')->primary();
            $t->uuid('camara_id');
            $t->uuid('posicion_id')->nullable();
        });
        Schema::table('folios_materiales', function (Blueprint $t) {
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3);
        });
        Schema::create('saldos_materiales_almacenes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('folio_id');
            $t->uuid('almacen_material_id');
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3);
            $t->uuid('camara_id')->nullable();
            $t->uuid('posicion_id')->nullable();
            $t->unsignedBigInteger('version');
            $t->timestamps();
            $t->unique(['folio_id', 'almacen_material_id']);
        });
        $id = (string) Str::uuid();
        $camara = (string) Str::uuid();
        DB::table('folios')->insert(['id' => $id]);
        DB::table('folios_materiales')->insert(['folio_id' => $id, 'almacen_inicial_id' => $this->norte, 'cantidad_actual' => 25, 'cantidad_reservada' => 0]);
        $f = FolioMaterial::findOrFail($id);
        $s = app(ServicioAlmacenMaterial::class);
        $s->inicializarFolio($f);
        $s->inicializarFolio($f);
        $this->assertDatabaseCount('saldos_materiales_almacenes', 1);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $id, 'almacen_material_id' => $this->norte, 'cantidad_actual' => 25, 'camara_id' => null]);
        DB::table('ubicaciones_actuales')->insert(['folio_id' => $id, 'camara_id' => $camara, 'posicion_id' => null]);
        $s->sincronizarUbicacionDesdeCompatibilidad($f);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['folio_id' => $id, 'almacen_material_id' => $this->norte, 'cantidad_actual' => 25, 'camara_id' => $camara]);
        $this->assertDatabaseCount('saldos_materiales_almacenes', 1);
    }
}
