<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CapacidadMaterialTest extends TestCase
{
    private string $almacen;

    private string $temporada;

    private string $saldo;

    private string $folio;

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
        Schema::create('temporadas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('activa');
            $t->date('fecha_inicio');
            $t->timestamps();
        });
        Schema::create('destinos_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('codigo')->unique();
            $t->string('nombre');
            $t->string('tipo');
            $t->boolean('activo');
        });
        Schema::create('folios', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('temporada_id');
            $t->string('numero_folio');
            $t->boolean('activo');
        });
        Schema::create('items_materiales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nombre');
        });
        Schema::create('folios_materiales', function (Blueprint $t) {
            $t->uuid('folio_id')->primary();
            $t->foreign('folio_id')->references('id')->on('folios')->cascadeOnDelete();
            $t->uuid('item_material_id');
            $t->string('unidad_medida');
        });
        Schema::create('saldos_materiales_almacenes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('folio_id')->constrained('folios_materiales', 'folio_id')->cascadeOnDelete();
            $t->uuid('almacen_material_id');
            $t->decimal('cantidad_actual', 14, 3);
            $t->decimal('cantidad_reservada', 14, 3)->default(0);
            $t->unsignedBigInteger('version');
        });
        (require database_path('migrations/2026_10_27_120000_crear_capacidad_materiales.php'))->up();
        $this->almacen = (string) Str::uuid();
        $this->temporada = (string) Str::uuid();
        $this->saldo = (string) Str::uuid();
        $this->folio = (string) Str::uuid();
        DB::table('users')->insert(['id' => 1, 'name' => 'Ana Pérez', 'rol' => 'administrador', 'activo' => true]);
        DB::table('temporadas')->insert(['id' => $this->temporada, 'activa' => true, 'fecha_inicio' => '2026-01-01', 'created_at' => now()]);
        DB::table('destinos_materiales')->insert(['id' => $this->almacen, 'codigo' => 'BOD-1', 'nombre' => 'Bodega 1', 'tipo' => 'fisica', 'activo' => true]);
        DB::table('folios')->insert(['id' => $this->folio, 'temporada_id' => $this->temporada, 'numero_folio' => 'FM-0001', 'activo' => true]);
        $item = (string) Str::uuid();
        DB::table('items_materiales')->insert(['id' => $item, 'nombre' => 'Cajas']);
        DB::table('folios_materiales')->insert(['folio_id' => $this->folio, 'item_material_id' => $item, 'unidad_medida' => 'unidades']);
        DB::table('saldos_materiales_almacenes')->insert(['id' => $this->saldo, 'folio_id' => $this->folio, 'almacen_material_id' => $this->almacen, 'cantidad_actual' => 120, 'cantidad_reservada' => 20, 'version' => 8]);
        $this->actingAs(User::findOrFail(1), 'sanctum');
    }

    private function tipo(array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => 'P-1', 'nombre' => 'Pallet de referencia',
            'largo_m' => 1.51, 'ancho_m' => .8, 'altura_total_m' => 1.5, 'max_niveles' => 2, 'peso_maximo_kg' => 500, 'activo' => true], ...$cambios];
    }

    private function crearTipo(array $cambios = []): array
    {
        return $this->postJson('/api/materiales/capacidad/tipos-pallet', $this->tipo($cambios))->assertCreated()->json('data');
    }

    private function zona(string $tipo, array $cambios = []): array
    {
        return [...['id' => (string) Str::uuid(), 'nombre' => 'Zona A', 'x_m' => 0, 'y_m' => 0, 'largo_m' => 4.53, 'ancho_m' => 1.6,
            'altura_util_m' => 3, 'max_niveles' => 2, 'permite_giro' => false, 'limite_pallets' => null, 'peso_maximo_kg' => 400, 'tipo_pallet_ids' => [$tipo]], ...$cambios];
    }

    private function configuracion(string $tipo, array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version' => 0, 'largo_m' => 10, 'ancho_m' => 8, 'altura_util_m' => 4, 'zonas' => [$this->zona($tipo)]], ...$cambios];
    }

    private function medidas(string $tipo, array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version' => 0, 'saldo_version' => 8, 'tipo_pallet_id' => $tipo,
            'largo_m' => 1.6, 'ancho_m' => .9, 'altura_total_m' => 2, 'max_niveles' => 1, 'bultos_fisicos' => 2, 'peso_unitario_kg' => 350], ...$cambios];
    }

    public function test_alta_normaliza_codigo_reintenta_sin_duplicar_y_registra_actor_y_version(): void
    {
        $datos = $this->tipo(['codigo' => ' p-1 ']);
        $tipo = $this->postJson('/api/materiales/capacidad/tipos-pallet', $datos)->assertCreated()->assertJsonPath('data.codigo', 'P-1')->json('data');
        $this->postJson('/api/materiales/capacidad/tipos-pallet', $datos)->assertCreated()->assertJsonPath('data.id', $tipo['id']);
        $this->assertDatabaseCount('tipos_pallet_materiales', 1);
        $this->assertDatabaseCount('eventos_capacidad_materiales', 1);
        $this->postJson('/api/materiales/capacidad/tipos-pallet', [...$datos, 'nombre' => 'Otra carga'])->assertConflict();
        $this->postJson('/api/materiales/capacidad/tipos-pallet', $this->tipo())->assertUnprocessable();
        $this->putJson('/api/materiales/capacidad/tipos-pallet/'.$tipo['id'], $this->tipo(['version' => 1, 'altura_total_m' => 2]))->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/materiales/capacidad/tipos-pallet/'.$tipo['id'], $this->tipo(['version' => 1]))->assertConflict();
        $this->getJson('/api/materiales/capacidad/historial')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.usuario', 'Ana Pérez')->assertDontSee('payload_hash');
    }

    public function test_zonas_utiles_validan_limites_altura_y_superposicion_y_admiten_mismo_tipo_en_varias_zonas(): void
    {
        $tipo = $this->crearTipo();
        $datos = $this->configuracion($tipo['id'], ['zonas' => [$this->zona($tipo['id']), $this->zona($tipo['id'], ['nombre' => 'Zona B', 'x_m' => 4.53])]]);
        $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, $datos)->assertOk()->assertJsonPath('data.version', 1)->assertJsonCount(2, 'data.zonas');
        $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, $datos)->assertOk();
        foreach ([['x_m' => 9], ['altura_util_m' => 5], ['x_m' => 4.529]] as $cambio) {
            $nuevo = [...$datos, 'operacion_id' => (string) Str::uuid(), 'version' => 1];
            $nuevo['zonas'][1] = [...$nuevo['zonas'][1], ...$cambio];
            $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, $nuevo)->assertUnprocessable();
        }
        $this->getJson('/api/materiales/capacidad/catalogo')->assertOk()->assertJsonPath('almacenes.0.configuracion.version', 1)->assertJsonPath('almacenes.0.configuracion.volumen_util_m3', 43.488);
        DB::table('destinos_materiales')->where('id', $this->almacen)->update(['tipo' => 'virtual']);
        $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, [...$datos, 'version' => 1, 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_simulacion_es_solo_lectura_y_no_cuenta_peso_desconocido_como_verificado(): void
    {
        $tipo = $this->crearTipo();
        $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, $this->configuracion($tipo['id']))->assertOk();
        $url = '/api/materiales/capacidad/almacenes/'.$this->almacen.'/simular?tipo_pallet_id='.$tipo['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.pallets_referencia', 0)->assertJsonPath('data.zonas.0.estado', 'pendiente_peso');
        $this->getJson($url.'&peso_unitario_kg=350')->assertOk()->assertJsonPath('data.pallets_referencia', 12);
        $this->getJson($url.'&peso_unitario_kg=401')->assertOk()->assertJsonPath('data.pallets_referencia', 0);
        $this->getJson($url.'&peso_unitario_kg=350&largo_m=10')->assertOk()->assertJsonPath('data.pallets_referencia', 0);
        $this->assertDatabaseCount('eventos_capacidad_materiales', 2);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['id' => $this->saldo, 'cantidad_actual' => 120, 'cantidad_reservada' => 20, 'version' => 8]);
    }

    public function test_racks_exigen_huecos_y_una_cantidad_fisicamente_posible(): void
    {
        $tipo = $this->crearTipo();
        $zona = $this->zona($tipo['id'], ['modo' => 'rack', 'limite_pallets' => 6]);
        $url = '/api/materiales/capacidad/almacenes/'.$this->almacen;
        $this->putJson($url, $this->configuracion($tipo['id'], ['zonas' => [$zona]]))->assertUnprocessable();
        $zona = [...$zona, 'hueco_largo_m' => 1.51, 'hueco_ancho_m' => .8, 'hueco_altura_m' => 1.5];
        $this->putJson($url, $this->configuracion($tipo['id'], ['zonas' => [$zona]]))->assertOk();
        $this->getJson($url.'/simular?tipo_pallet_id='.$tipo['id'].'&peso_unitario_kg=350')->assertOk()->assertJsonPath('data.pallets_referencia', 6);
        $zona['limite_pallets'] = 10000;
        $this->putJson($url, $this->configuracion($tipo['id'], ['version' => 1, 'zonas' => [$zona]]))->assertUnprocessable();
    }

    public function test_todas_las_lecturas_exigen_sesion_y_el_usuario_inactivo_no_puede_consultar(): void
    {
        $usuario = User::findOrFail(1);
        $usuario->activo = false;
        $this->actingAs($usuario, 'sanctum');
        $this->getJson('/api/materiales/capacidad/catalogo')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/materiales/capacidad/catalogo')->assertUnauthorized();
    }

    public function test_medicion_conserva_dimensiones_reales_y_cambios_de_saldo_exigen_verificar(): void
    {
        $tipo = $this->crearTipo();
        $datos = $this->medidas($tipo['id']);
        $url = '/api/materiales/capacidad/cargas/'.$this->saldo;
        $this->putJson($url, $datos)->assertOk()->assertJsonPath('data.largo_m', 1.6)->assertJsonPath('data.bultos_fisicos', 2);
        $this->putJson($url, $datos)->assertOk();
        $this->assertDatabaseCount('dimensiones_cargas_materiales', 1);
        $this->putJson('/api/materiales/capacidad/tipos-pallet/'.$tipo['id'], $this->tipo(['version' => 1, 'largo_m' => 2]))->assertOk();
        $listado = '/api/materiales/capacidad/cargas?almacen_id='.$this->almacen;
        $this->getJson($listado)->assertOk()->assertJsonPath('data.0.largo_m', 1.6)->assertJsonPath('data.0.medidas_vigentes', true);
        DB::table('saldos_materiales_almacenes')->where('id', $this->saldo)->update(['version' => 9]);
        $this->getJson($listado)->assertOk()->assertJsonPath('data.0.medidas_vigentes', false);
        $this->putJson($url, $this->medidas($tipo['id'], ['version' => 1]))->assertConflict();
        $this->putJson($url, $this->medidas($tipo['id'], ['version' => 1, 'saldo_version' => 9]))->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('saldos_materiales_almacenes', ['id' => $this->saldo, 'cantidad_actual' => 120, 'cantidad_reservada' => 20, 'version' => 9]);
        $this->getJson('/api/materiales/capacidad/historial?entidad=carga&registro_id='.$this->saldo)->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_eliminacion_de_saldo_no_se_bloquea_y_conserva_auditoria_de_medidas(): void
    {
        $tipo = $this->crearTipo();
        $this->putJson('/api/materiales/capacidad/cargas/'.$this->saldo, $this->medidas($tipo['id']))->assertOk();
        DB::table('folios')->where('id', $this->folio)->delete();
        $this->assertDatabaseCount('dimensiones_cargas_materiales', 0);
        $this->getJson('/api/materiales/capacidad/historial?entidad=carga&registro_id='.$this->saldo)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.resultado.folio', 'FM-0001');
    }

    public function test_mediciones_rechazan_temporadas_cerradas_saldos_virtuales_y_cantidades_invalidas(): void
    {
        $tipo = $this->crearTipo();
        $url = '/api/materiales/capacidad/cargas/'.$this->saldo;
        foreach ([['bultos_fisicos' => 0], ['largo_m' => 0], ['altura_total_m' => .0001], ['peso_unitario_kg' => -1]] as $cambio) {
            $this->putJson($url, $this->medidas($tipo['id'], $cambio))->assertUnprocessable();
        }
        DB::table('destinos_materiales')->where('id', $this->almacen)->update(['tipo' => 'virtual']);
        $this->putJson($url, $this->medidas($tipo['id']))->assertUnprocessable();
        DB::table('destinos_materiales')->where('id', $this->almacen)->update(['tipo' => 'fisica']);
        DB::table('folios')->where('id', $this->folio)->update(['temporada_id' => (string) Str::uuid()]);
        $this->putJson($url, $this->medidas($tipo['id']))->assertUnprocessable();
        $this->getJson('/api/materiales/capacidad/cargas?almacen_id='.$this->almacen)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_permisos_separan_lectura_configuracion_y_medicion_sin_ampliar_perfiles_existentes(): void
    {
        $tipo = $this->crearTipo();
        $usuario = User::findOrFail(1);
        $usuario->rol = RolUsuario::SupervisorMateriales;
        $perfil = new PerfilAcceso;
        $perfil->forceFill(['id' => (string) Str::uuid(), 'rol_base' => RolUsuario::SupervisorMateriales, 'activo' => true, 'modulos' => ['materiales.inventario'], 'permisos_materiales' => null, 'protegido' => false]);
        $usuario->perfil_acceso_id = $perfil->id;
        $usuario->setRelation('perfilAcceso', $perfil);
        $this->actingAs($usuario, 'sanctum');
        $this->getJson('/api/materiales/capacidad/catalogo')->assertOk()->assertJsonPath('permisos.configurar', false)->assertJsonPath('permisos.medir', false);
        $this->postJson('/api/materiales/capacidad/tipos-pallet', $this->tipo())->assertForbidden();
        $this->putJson('/api/materiales/capacidad/cargas/'.$this->saldo, $this->medidas($tipo['id']))->assertForbidden();
        $perfil->permisos_materiales = ['capacidad.medir'];
        $this->putJson('/api/materiales/capacidad/cargas/'.$this->saldo, $this->medidas($tipo['id']))->assertOk();
        $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, $this->configuracion($tipo['id']))->assertForbidden();
        $perfil->permisos_materiales = ['capacidad.configurar'];
        $this->putJson('/api/materiales/capacidad/almacenes/'.$this->almacen, $this->configuracion($tipo['id']))->assertOk();
        $this->putJson('/api/materiales/capacidad/cargas/'.$this->saldo, $this->medidas($tipo['id']))->assertForbidden();
        $perfil->rol_base = RolUsuario::Consulta;
        $usuario->rol = RolUsuario::Consulta;
        $this->getJson('/api/materiales/capacidad/catalogo')->assertOk()->assertJsonPath('permisos.configurar', false);
        $this->postJson('/api/materiales/capacidad/tipos-pallet', $this->tipo())->assertForbidden();
        $perfil->modulos = [];
        foreach (['catalogo', 'historial', 'cargas?almacen_id='.$this->almacen] as $ruta) {
            $this->getJson('/api/materiales/capacidad/'.$ruta)->assertForbidden();
        }
    }
}
