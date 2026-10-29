<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Jobs\EjecutarOperacionIntegracion;
use App\Models\ConexionExterna;
use App\Models\ConfiguracionOrigen;
use App\Models\Folio;
use App\Models\LecturaStockOrigen;
use App\Models\OperacionIntegracion;
use App\Models\PalletStockOrigen;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\MapeoStockOrigen;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDOException;
use Tests\TestCase;

class ConsultaPalletPackingTest extends TestCase
{
    use RefreshDatabase;

    private ConexionExterna $conexion;

    private User $usuario;

    private string $temporada;

    private array $consultas = [];

    private array|\Throwable $respuesta = [['Pallet' => '000021', 'cantidad' => '40', 'codigo_csg_predio' => '100001']];

    private ?\Closure $durante = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(12));
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Validador]);
        $this->actingAs($this->usuario, 'sanctum');
        $this->temporada = Temporada::where('activa', true)->sole()->id;
        $this->conexion = ConexionExterna::create(['nombre' => 'Packing fixture', 'activa' => true, 'version' => 1,
            'credenciales' => ['host' => '192.0.2.1', 'puerto' => 3306, 'usuario' => 'lector', 'password' => 'SECRETO_FIXTURE', 'base_datos' => 'origen']]);
        config(['integraciones.stock_pt' => ['habilitada' => true, 'conexion' => $this->conexion->nombre, 'tabla' => 'vista_stock', 'bodega' => 'AR01', 'temporada' => 'TEMP']]);
        $this->mock(ConectorMySqlLectura::class)->shouldReceive('consultar')->andReturnUsing(function ($credenciales, $sql, $parametros = []) {
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return [['nombre' => 'folio real', 'tipo' => 'varchar'], ['nombre' => 'cajas', 'tipo' => 'decimal'], ['nombre' => 'bodega', 'tipo' => 'varchar']];
            }
            $this->consultas[] = compact('sql', 'parametros');
            if ($this->durante) {
                ($this->durante)();
            }
            if ($this->respuesta instanceof \Throwable) {
                throw $this->respuesta;
            }

            return $this->respuesta;
        });
    }

    private function solicitar(string $numero = '000021'): TestResponse
    {
        return $this->postJson('/api/validacion/precarga-origen', ['numero_folio' => $numero, 'temporada_id' => $this->temporada]);
    }

    private function ejecutar(TestResponse $respuesta): OperacionIntegracion
    {
        $op = OperacionIntegracion::findOrFail($respuesta->json('operacion.id'));
        (new EjecutarOperacionIntegracion($op->id))->handle(app(ServicioOperacionesIntegracion::class));

        return $op->fresh();
    }

    public function test_folio_nuevo_consulta_solo_su_pallet_en_cola_sin_crear_folios_y_deduplica_solicitudes(): void
    {
        $folios = Folio::count();
        $r = $this->solicitar()->assertStatus(202);
        $this->solicitar()->assertStatus(202)->assertJsonPath('operacion.id', $r->json('operacion.id'));
        $this->assertSame([], $this->consultas);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertStringNotContainsString('SECRETO_FIXTURE', DB::table('jobs')->value('payload'));
        $op = $this->ejecutar($r);
        $this->assertSame('correcta', $op->estado);
        $this->assertSame(1, count($this->consultas));
        $this->assertStringContainsString('UPPER(TRIM(CAST(`Pallet` AS CHAR))) = ?', $this->consultas[0]['sql']);
        $this->assertSame(['AR01', 'TEMP', '000021'], $this->consultas[0]['parametros']);
        $this->assertSame('000021', LecturaStockOrigen::sole()->numero_pallet);
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertOk()
            ->assertJsonPath('operacion.respuesta.contenido.data.numero_folio', '000021')
            ->assertJsonPath('operacion.respuesta.contenido.data.cantidad_cajas', 40);
        $this->assertSame($folios, Folio::count());
        $this->assertDatabaseCount('validaciones_pallet', 0);
        $this->solicitar()->assertOk()->assertJsonPath('data.disponible', true);
        $this->assertSame(1, count($this->consultas));
        $this->ejecutar($r);
        $this->assertSame(1, count($this->consultas));
    }

    public function test_lectura_puntual_no_retira_otros_pallets_ni_renueva_la_vigencia_global(): void
    {
        $this->respuesta[] = ['Pallet' => 'OTRO', 'cantidad' => 20];
        $completa = app(ServicioLecturaStockOrigen::class)->leer($this->usuario);
        $this->travel(25)->minutes();
        $this->respuesta = [['Pallet' => '000021', 'cantidad' => 38]];
        $op = $this->ejecutar($this->solicitar()->assertStatus(202));
        $this->assertTrue(PalletStockOrigen::where('numero_pallet', 'OTRO')->sole()->presente);
        $this->assertSame(38, PalletStockOrigen::where('numero_pallet', '000021')->sole()->cajas);
        $this->assertFalse($op->resultado['contenido']['data']['desactualizada']);
        $fuente = $this->getJson('/api/validacion/pendientes-origen')->assertOk()->json('lectura');
        $this->assertTrue($fuente['desactualizada']);
        $this->assertSame($completa->terminada_at->toAtomString(), $fuente['ultima_correcta_at']);
    }

    public function test_no_encontrado_es_distinto_de_error_y_no_retira_el_resto_de_la_bodega(): void
    {
        $this->respuesta = [['Pallet' => 'OTRO', 'cantidad' => 20]];
        app(ServicioLecturaStockOrigen::class)->leer($this->usuario);
        $this->respuesta = [];
        $op = $this->ejecutar($this->solicitar()->assertStatus(202));
        $this->assertSame('correcta', $op->estado);
        $this->assertFalse($op->resultado['contenido']['data']['disponible']);
        $this->assertStringContainsString('Packing no tiene este folio', $op->resultado['contenido']['data']['motivo']);
        $this->assertTrue(PalletStockOrigen::sole()->presente);
        $error = new PDOException('usuario y password que nunca deben llegar a la PDA');
        $error->errorInfo = ['HY000', 2002, 'secreto'];
        $this->respuesta = $error;
        $op = $this->ejecutar($this->solicitar()->assertStatus(202));
        $this->assertSame('fallida', $op->estado);
        $this->assertSame(422, $op->resultado['codigo_http']);
        $this->assertSame('servidor_inaccesible', $op->resultado['contenido']['causa']);
        $this->assertStringNotContainsString('password', $op->resultado['contenido']['message']);
        $this->assertTrue(PalletStockOrigen::sole()->presente);
    }

    public function test_consulta_mapeada_mantiene_filtros_y_folio_parametrizado_con_ceros(): void
    {
        ConfiguracionOrigen::create(['clave' => ConfiguracionOrigen::STOCK, 'conexion_externa_id' => $this->conexion->id, 'version' => 1,
            'ajustes' => ['tabla' => 'Stock real', 'campos' => ['Pallet' => 'folio real', 'cantidad' => 'cajas'],
                'filtros' => [['columna' => 'bodega', 'valor' => 'BOD']], 'habilitada' => true]]);
        $folio = "000021' OR 1=1 --";
        $this->respuesta = [['Pallet' => $folio, 'cantidad' => 40]];
        $op = $this->ejecutar($this->solicitar($folio)->assertStatus(202));
        $this->assertSame('correcta', $op->estado);
        $this->assertStringContainsString('WHERE `bodega` = ? AND UPPER(TRIM(CAST(`folio real` AS CHAR))) = ?', $this->consultas[0]['sql']);
        $this->assertStringNotContainsString($folio, $this->consultas[0]['sql']);
        $this->assertSame(['BOD', $folio], $this->consultas[0]['parametros']);
    }

    public function test_permisos_resultado_privado_y_folio_existente(): void
    {
        $r = $this->solicitar()->assertStatus(202);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Validador]), 'sanctum');
        $this->getJson('/api/integraciones/operaciones/'.$r->json('operacion.id'))->assertForbidden();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum');
        $this->solicitar()->assertForbidden();
        $this->actingAs($this->usuario, 'sanctum');
        Folio::create(['numero_folio' => 'EXISTENTE', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'pendiente_prefrio',
            'fecha_ingreso' => now(), 'activo' => true]);
        $this->solicitar('EXISTENTE')->assertConflict();
        $this->assertSame([], $this->consultas);
    }

    public function test_cambios_de_fuente_temporada_o_permisos_invalidan_la_consulta_pendiente(): void
    {
        $r = $this->solicitar()->assertStatus(202);
        $this->conexion->update(['version' => 2]);
        $this->assertSame('obsoleta', $this->ejecutar($r)->estado);
        $r = $this->solicitar()->assertStatus(202);
        $this->usuario->update(['rol' => RolUsuario::CamareroMateriales]);
        $this->assertSame(403, $this->ejecutar($r)->resultado['codigo_http']);
        $this->usuario->update(['rol' => RolUsuario::Validador]);
        $this->actingAs($this->usuario, 'sanctum');
        $r = $this->solicitar()->assertStatus(202);
        Temporada::whereKey($this->temporada)->update(['activa' => false]);
        $this->assertSame('obsoleta', $this->ejecutar($r)->estado);
        $this->assertSame([], $this->consultas);
    }

    public function test_cambio_de_fuente_durante_la_lectura_no_publica_ni_reemplaza_la_copia(): void
    {
        $this->durante = fn () => $this->conexion->update(['version' => 2]);
        $op = $this->ejecutar($this->solicitar()->assertStatus(202));
        $this->assertSame('fallida', $op->estado);
        $this->assertDatabaseCount('pallets_stock_origen', 0);
    }

    public function test_una_lectura_puntual_no_declara_otros_folios_ausentes_en_packing(): void
    {
        Folio::create(['numero_folio' => 'SOLO-FOLIOS', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'pendiente_prefrio',
            'fecha_ingreso' => now(), 'activo' => true, 'origen_sistema' => 'validacion']);
        $this->ejecutar($this->solicitar()->assertStatus(202));
        $this->getJson('/api/validacion/pendientes-origen')->assertOk()
            ->assertJsonPath('resumen.solo_en_folios', 0)->assertJsonCount(0, 'solo_en_folios');
    }

    public function test_mysql_distingue_folios_con_ceros_y_respeta_los_filtros_del_packing(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Contrato de consulta MySQL del sistema de origen.');
        }
        DB::statement('CREATE TEMPORARY TABLE packing_puntual_fixture (`folio real` VARCHAR(50), cajas INT, bodega VARCHAR(20))');
        try {
            DB::table('packing_puntual_fixture')->insert([
                ['folio real' => '000021', 'cajas' => 40, 'bodega' => 'AR01'],
                ['folio real' => '21', 'cajas' => 99, 'bodega' => 'AR01'],
                ['folio real' => '000021', 'cajas' => 70, 'bodega' => 'OTRA'],
            ]);
            [$sql, $parametros] = app(MapeoStockOrigen::class)->consulta([
                'tabla' => 'packing_puntual_fixture', 'campos' => ['Pallet' => 'folio real', 'cantidad' => 'cajas'],
                'filtros' => [['columna' => 'bodega', 'valor' => 'AR01']],
            ], [['nombre' => 'folio real', 'tipo' => 'varchar'], ['nombre' => 'cajas', 'tipo' => 'int'], ['nombre' => 'bodega', 'tipo' => 'varchar']], numeroPallet: '000021');
            $filas = DB::select($sql, $parametros);
            $this->assertCount(1, $filas);
            $this->assertSame('000021', $filas[0]->Pallet);
            $this->assertSame('40', $filas[0]->cantidad);
        } finally {
            DB::statement('DROP TEMPORARY TABLE packing_puntual_fixture');
        }
    }
}
