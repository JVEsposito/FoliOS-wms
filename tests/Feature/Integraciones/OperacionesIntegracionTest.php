<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Jobs\EjecutarOperacionIntegracion;
use App\Models\ConexionExterna;
use App\Models\ConfiguracionOrigen;
use App\Models\OperacionIntegracion;
use App\Models\PalletStockOrigen;
use App\Models\User;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class OperacionesIntegracionTest extends TestCase
{
    use RefreshDatabase;

    private ConexionExterna $conexion;

    private User $usuario;

    private int $consultas = 0;

    private array $segundosConsultas = [];

    private array $filas = [['Pallet' => '000021', 'cantidad' => '40', 'disponibilidad' => null]];

    private ?\Closure $durante = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->usuario, 'sanctum');
        $this->conexion = ConexionExterna::create(['nombre' => 'Origen fixture', 'activa' => true, 'version' => 1,
            'credenciales' => ['host' => '192.0.2.1', 'puerto' => 3306, 'usuario' => 'lector', 'password' => 'SECRETO_FIXTURE', 'base_datos' => 'origen']]);
        config(['integraciones.stock_pt' => ['habilitada' => true, 'conexion' => $this->conexion->nombre, 'tabla' => 'vista_stock', 'bodega' => 'BODEGA_REAL', 'temporada' => '2026']]);
        $this->mock(ConectorMySqlLectura::class)->shouldReceive('consultar')->andReturnUsing(function ($credenciales, $sql, $parametros = [], $segundos = 60) {
            $this->consultas++;
            $this->segundosConsultas[] = $segundos;
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return [['nombre' => 'folio', 'tipo' => 'varchar'], ['nombre' => 'cajas', 'tipo' => 'decimal'], ['nombre' => 'disponible', 'tipo' => 'varchar']];
            }
            if ($this->durante) {
                ($this->durante)();
            }

            return $this->filas;
        });
    }

    private function ruta(string $accion): string
    {
        return '/api/administracion/conexiones-externas/'.$this->conexion->id.'/'.$accion;
    }

    private function ejecutar(OperacionIntegracion $op): OperacionIntegracion
    {
        (new EjecutarOperacionIntegracion($op->id))->handle(app(ServicioOperacionesIntegracion::class));

        return $op->fresh();
    }

    public function test_acepta_en_cola_incluso_con_driver_general_sync_y_no_consulta_en_http(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $respuesta = $this->postJson($this->ruta('columnas'), ['version_conocida' => 1, 'tabla' => 'vista_stock'], ['Prefer' => 'respond-async'])
            ->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('operacion.estado', 'en_cola');
        $this->assertSame(0, $this->consultas);
        $this->assertDatabaseCount('jobs', 1);
        $job = DB::table('jobs')->sole();
        $this->assertSame('integraciones', $job->queue);
        $this->assertStringContainsString($respuesta->json('operacion.id'), $job->payload);
        $this->assertStringNotContainsString('SECRETO_FIXTURE', $job->payload);
        $this->assertStringNotContainsString('vista_stock', $job->payload);
        $this->postJson($this->ruta('columnas'), ['version_conocida' => 1, 'tabla' => 'vista_stock'], ['Prefer' => 'respond-async'])
            ->assertStatus(202)->assertJsonPath('operacion.id', $respuesta->json('operacion.id'));
        $this->assertDatabaseCount('jobs', 1);
        $op = $this->ejecutar(OperacionIntegracion::sole());
        $this->assertSame('correcta', $op->estado);
        $this->assertSame(200, $op->resultado['codigo_http']);
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertOk()->assertJsonPath('operacion.respuesta.contenido.data.0.nombre', 'folio');
        $this->ejecutar($op);
        $this->assertSame(1, $this->consultas); // Entrega repetida del mismo job no repite la consulta.
    }

    public function test_resultados_temporales_y_filtros_cifrados_sin_acceso_de_otro_administrador(): void
    {
        $this->filas = [['folio' => 'MUESTRA_PRIVADA']];
        $op = app(ServicioOperacionesIntegracion::class)->solicitar('muestra', $this->conexion, [
            'version_conocida' => 1, 'tabla' => 'vista_stock', 'columnas' => ['folio'],
            'filtros' => [['columna' => 'folio', 'valor' => 'FILTRO_PRIVADO']], 'limite' => 10,
        ], $this->usuario);
        $this->assertStringNotContainsString('FILTRO_PRIVADO', DB::table('operaciones_integracion')->value('entrada'));
        $this->assertStringNotContainsString('FILTRO_PRIVADO', DB::table('jobs')->value('payload'));
        $op = $this->ejecutar($op);
        $this->assertSame('correcta', $op->estado);
        $this->assertStringNotContainsString('MUESTRA_PRIVADA', DB::table('operaciones_integracion')->value('resultado'));
        $this->assertNull($op->entrada);
        $this->assertArrayNotHasKey('resultado', $op->toArray());
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertOk()->assertJsonPath('operacion.respuesta.contenido.data.filas.0.folio', 'MUESTRA_PRIVADA');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertForbidden();
        $this->actingAs($this->usuario, 'sanctum');
        $this->travel(6)->minutes();
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertOk()->assertJsonPath('operacion.respuesta.codigo_http', 410);
        app(ServicioOperacionesIntegracion::class)->limpiar();
        $this->assertNull($op->fresh()->getRawOriginal('resultado'));
    }

    public function test_la_prueba_de_conexion_espera_al_worker(): void
    {
        $this->mock(ConectorMySqlLectura::class)->shouldReceive('probar')->once();
        $this->postJson($this->ruta('probar'), ['version_conocida' => 1], ['Prefer' => 'respond-async'])->assertStatus(202);
        $this->assertNull($this->conexion->fresh()->ultima_prueba_estado);
        $this->assertSame('correcta', $this->ejecutar(OperacionIntegracion::sole())->estado);
        $this->assertSame('correcta', $this->conexion->fresh()->ultima_prueba_estado);
    }

    public function test_muestra_en_cola_usa_sesenta_segundos_y_http_no_puede_pedir_el_presupuesto_del_worker(): void
    {
        $this->filas = [['folio' => '21']];
        $datos = ['version_conocida' => 1, 'tabla' => 'vista_stock', 'columnas' => ['folio'], 'filtros' => [], 'limite' => 5];
        $this->postJson($this->ruta('muestra'), $datos, ['Prefer' => 'respond-async'])->assertStatus(202);
        $this->assertSame([], $this->segundosConsultas);
        $op = $this->ejecutar(OperacionIntegracion::sole());
        $this->assertSame('correcta', $op->estado);
        $this->assertSame([15, 60], $this->segundosConsultas);
        $this->assertSame('21', $op->resultado['contenido']['data']['filas'][0]['folio']);

        config(['integraciones.consultas.muestra_segundos' => 120]);
        $this->postJson($this->ruta('muestra'), [...$datos, 'integracion_id' => $op->id, 'segundos' => 120])
            ->assertOk()->assertJsonPath('data.filas.0.folio', '21');
        $this->assertSame([15, 60, 15, 15], $this->segundosConsultas);
    }

    public function test_simulacion_de_mapeo_respeta_presupuesto_configurado_solo_en_cola(): void
    {
        config(['integraciones.consultas.muestra_segundos' => 90]);
        $datos = ['conexion_id' => $this->conexion->id, 'version_conexion' => 1, 'version_conocida' => 0,
            'ajustes' => ['tabla' => 'vista_stock', 'campos' => ['Pallet' => 'folio', 'cantidad' => 'cajas'], 'filtros' => [], 'habilitada' => true]];
        $ruta = '/api/administracion/conexiones-externas/lectura-stock/simular';
        $this->postJson($ruta, $datos, ['Prefer' => 'respond-async'])->assertStatus(202);
        $this->assertSame([], $this->segundosConsultas);
        $op = $this->ejecutar(OperacionIntegracion::sole());
        $this->assertSame('correcta', $op->estado);
        $this->assertSame([15, 90], $this->segundosConsultas);
        $this->assertNull($op->resultado['contenido']['data']['filas'][0]['disponibilidad']);
        $this->assertDatabaseCount('configuraciones_origen', 0);

        $this->postJson($ruta, $datos)->assertOk()->assertJsonPath('data.filas.0.Pallet', '000021');
        $this->assertSame([15, 90, 15, 15], $this->segundosConsultas);
    }

    public function test_fila_invalida_legacy_rechaza_toda_la_lectura_sin_retirar_la_copia(): void
    {
        $servicio = app(ServicioOperacionesIntegracion::class);
        $this->ejecutar($servicio->solicitarStock($this->usuario));
        $this->filas = [['Pallet' => 'OTRO', 'cantidad' => 12], ['Pallet' => null, 'cantidad' => 10]];
        $op = $this->ejecutar($servicio->solicitarStock($this->usuario));
        $this->assertSame('fallida', $op->estado);
        $this->assertDatabaseCount('pallets_stock_origen', 1);
        $this->assertSame('000021', PalletStockOrigen::sole()->numero_pallet);
        $this->assertTrue(PalletStockOrigen::sole()->presente);
        $this->assertSame(40, PalletStockOrigen::sole()->cajas);
    }

    public function test_revalida_la_version_antes_de_consultar(): void
    {
        $op = app(ServicioOperacionesIntegracion::class)->solicitar('columnas', $this->conexion, ['version_conocida' => 1, 'tabla' => 'vista_stock'], $this->usuario);
        $this->conexion->update(['version' => 2]);
        $this->assertSame('obsoleta', $this->ejecutar($op)->estado);
        $this->assertSame(0, $this->consultas);
    }

    public function test_revalida_permisos_revocados_antes_de_consultar(): void
    {
        $op = app(ServicioOperacionesIntegracion::class)->solicitar('columnas', $this->conexion, ['version_conocida' => 1, 'tabla' => 'vista_stock'], $this->usuario);
        $this->usuario->update(['rol' => RolUsuario::SupervisorFrio]);
        $op = $this->ejecutar($op);
        $this->assertSame(403, $op->resultado['codigo_http']);
        $this->assertSame(0, $this->consultas);
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertForbidden();
    }

    public function test_stock_comparte_una_sola_lectura_y_conserva_copia_ante_fallo_y_vacio(): void
    {
        $servicio = app(ServicioOperacionesIntegracion::class);
        $r = $this->postJson('/api/validacion/pendientes-origen/leer', [], ['Prefer' => 'respond-async'])->assertStatus(202);
        $this->assertSame(0, $this->consultas);
        $otro = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->assertSame($r->json('operacion.id'), $servicio->solicitarStock($otro)->id);
        $op = $this->ejecutar(OperacionIntegracion::sole());
        $this->assertSame('correcta', $op->estado);
        $this->assertSame(40, PalletStockOrigen::sole()->cajas);
        $this->assertNull(PalletStockOrigen::sole()->disponibilidad_origen);
        $this->actingAs($otro, 'sanctum');
        $this->getJson('/api/integraciones/operaciones/'.$op->id)->assertOk();
        $this->filas = [];
        $this->assertSame('fallida', $this->ejecutar($servicio->solicitarStock($otro))->estado);
        $this->assertSame(40, PalletStockOrigen::sole()->cajas);
        $this->assertTrue(PalletStockOrigen::sole()->presente);
        $this->durante = fn () => throw new PDOException('SECRETO_FIXTURE servidor_privado');
        $fallida = $this->ejecutar($servicio->solicitarStock($otro));
        $this->assertStringNotContainsString('SECRETO_FIXTURE', json_encode($fallida->resultado));
        $this->assertSame(40, PalletStockOrigen::sole()->cajas);
    }

    public function test_cambio_legacy_durante_la_lectura_no_publica_datos(): void
    {
        $this->durante = fn () => $this->conexion->update(['version' => 2]);
        $op = $this->ejecutar(app(ServicioOperacionesIntegracion::class)->solicitarStock($this->usuario));
        $this->assertSame('fallida', $op->estado);
        $this->assertDatabaseCount('pallets_stock_origen', 0);
        $this->assertSame('configuracion_cambiada', DB::table('lecturas_stock_origen')->value('causa'));
    }

    public function test_operacion_interrumpida_no_publica_tarde_y_permite_reintentar(): void
    {
        $servicio = app(ServicioOperacionesIntegracion::class);
        $op = $servicio->solicitarStock($this->usuario);
        $this->durante = fn () => $servicio->interrumpir($op->id);
        $this->assertSame('fallida', $this->ejecutar($op)->estado);
        $this->assertDatabaseCount('pallets_stock_origen', 0);
        $this->assertNotSame($op->id, $servicio->solicitarStock($this->usuario)->id);
    }

    public function test_scheduler_encola_sin_consultar_y_limpieza_libera_operaciones_huerfanas(): void
    {
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();
        $this->assertSame(0, $this->consultas);
        $op = OperacionIntegracion::sole();
        $this->travel(16)->minutes();
        $this->artisan('integraciones:limpiar-operaciones')->assertSuccessful();
        $this->assertSame('fallida', $op->fresh()->estado);
        $this->assertNull($op->fresh()->clave_activa);
        $this->assertNull($op->fresh()->entrada);
        $this->ejecutar($op);
        $this->assertSame(0, $this->consultas);
    }

    public function test_mapeo_no_se_guarda_hasta_ejecutar_job_y_disponibilidad_null_se_conserva(): void
    {
        $datos = ['conexion_id' => $this->conexion->id, 'version_conexion' => 1, 'version_conocida' => 0,
            'ajustes' => ['tabla' => 'vista_stock', 'campos' => ['Pallet' => 'folio', 'cantidad' => 'cajas', 'disponibilidad' => 'disponible'], 'filtros' => [], 'habilitada' => true]];
        $this->putJson('/api/administracion/conexiones-externas/lectura-stock', $datos, ['Prefer' => 'respond-async'])->assertStatus(202);
        $this->assertDatabaseCount('configuraciones_origen', 0);
        $this->assertSame(0, $this->consultas);
        $op = $this->ejecutar(OperacionIntegracion::sole());
        $this->assertSame('correcta', $op->estado);
        $this->assertSame(1, ConfiguracionOrigen::sole()->version);
        $this->ejecutar(app(ServicioOperacionesIntegracion::class)->solicitarStock($this->usuario));
        $this->assertNull(PalletStockOrigen::sole()->disponibilidad_origen);
        $this->filas[0]['disponibilidad'] = 'PENDIENTE';
        $this->ejecutar(app(ServicioOperacionesIntegracion::class)->solicitarStock($this->usuario));
        $this->assertSame('PENDIENTE', PalletStockOrigen::sole()->disponibilidad_origen);
        $this->filas[0]['disponibilidad'] = '0';
        $this->ejecutar(app(ServicioOperacionesIntegracion::class)->solicitarStock($this->usuario));
        $this->assertSame('0', PalletStockOrigen::sole()->disponibilidad_origen);
        $this->filas[] = [...$this->filas[0], 'disponibilidad' => null];
        $this->ejecutar(app(ServicioOperacionesIntegracion::class)->solicitarStock($this->usuario));
        $this->assertNull(PalletStockOrigen::sole()->disponibilidad_origen);
    }
}
