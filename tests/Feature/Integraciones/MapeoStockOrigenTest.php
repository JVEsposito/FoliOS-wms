<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Models\ConexionExterna;
use App\Models\ConfiguracionOrigen;
use App\Models\Folio;
use App\Models\PalletStockOrigen;
use App\Models\User;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ServicioLecturaStockOrigen;
use App\Services\Validacion\ServicioPendientesValidacionOrigen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

class MapeoStockOrigenTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/administracion/conexiones-externas/lectura-stock';

    private ConexionExterna $conexion;

    private array $filas = [['Pallet' => '0000123', 'cantidad' => '40', 'descripcion_especie' => 'FRUTA FIXTURE']];

    private array $consultas = [];

    private ?\Closure $duranteLectura = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $this->conexion = ConexionExterna::create(['nombre' => 'Origen fixture', 'activa' => true, 'version' => 1, 'credenciales' => ['password' => 'clave_fixture']]);
        $this->mock(ConectorMySqlLectura::class)->shouldReceive('consultar')->andReturnUsing(function ($credenciales, $sql, $parametros = []) {
            $this->consultas[] = [$sql, $parametros];
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return [['nombre' => 'folio_real', 'tipo' => 'varchar'], ['nombre' => 'cajas_reales', 'tipo' => 'decimal'], ['nombre' => 'bodega_real', 'tipo' => 'varchar'], ['nombre' => 'fruta_real', 'tipo' => 'varchar']];
            }
            if ($this->duranteLectura) {
                ($this->duranteLectura)();
            }

            return $this->filas;
        });
    }

    private function datos(array $filtros = []): array
    {
        return [
            'conexion_id' => $this->conexion->id, 'version_conexion' => 1, 'version_conocida' => 0,
            'ajustes' => ['tabla' => 'vista_existencias', 'campos' => ['Pallet' => 'folio_real', 'cantidad' => 'cajas_reales', 'descripcion_especie' => 'fruta_real'], 'filtros' => $filtros, 'habilitada' => true],
        ];
    }

    public function test_guarda_cifrado_sin_credenciales_en_respuestas_y_controla_version_y_permisos(): void
    {
        $this->getJson(self::RUTA)->assertOk()->assertJsonPath('data.version', 0);
        $datos = $this->datos([['columna' => 'bodega_real', 'valor' => 'BODEGA-PRIVADA']]);
        $this->putJson(self::RUTA, $datos)->assertOk()->assertJsonPath('data.version', 1);
        $respuesta = $this->getJson(self::RUTA)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.ajustes.filtros.0.valor', 'BODEGA-PRIVADA');
        $this->assertStringNotContainsString('clave_fixture', $respuesta->getContent());
        $this->assertStringNotContainsString('BODEGA-PRIVADA', DB::table('configuraciones_origen')->value('ajustes'));
        $this->assertStringNotContainsString('BODEGA-PRIVADA', $this->conexion->eventos()->get()->toJson());
        $this->assertSame(auth()->id(), ConfiguracionOrigen::find(ConfiguracionOrigen::STOCK)->actualizado_por_user_id);
        $this->putJson(self::RUTA, $datos)->assertConflict();
        $this->conexion->update(['version' => 2]);
        $this->putJson(self::RUTA, [...$datos, 'version_conocida' => 1])->assertConflict();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum');
        $this->getJson(self::RUTA)->assertForbidden();
        $this->putJson(self::RUTA, $datos)->assertForbidden();
        $this->postJson(self::RUTA.'/simular', $datos)->assertForbidden();
    }

    public function test_la_simulacion_no_importa_y_la_lectura_programada_usa_las_columnas_elegidas_sin_filtros_fijos(): void
    {
        $this->postJson(self::RUTA.'/simular', $this->datos())->assertOk()->assertJsonPath('data.filas.0.Pallet', '0000123');
        $this->assertDatabaseCount('pallets_stock_origen', 0);
        $this->assertDatabaseCount('configuraciones_origen', 0);
        $this->putJson(self::RUTA, $this->datos())->assertOk();
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();
        $pallet = PalletStockOrigen::sole();
        $this->assertSame('0000123', $pallet->numero_pallet);
        $this->assertSame(40, $pallet->cajas);
        $this->assertSame('FRUTA FIXTURE', $pallet->especie);
        $this->assertSame(0, Folio::count());
        [$sql, $parametros] = end($this->consultas);
        $this->assertStringContainsString('`folio_real` AS CHAR), 501) AS `Pallet`', $sql);
        $this->assertStringNotContainsString(' WHERE ', $sql);
        $this->assertStringContainsString('NULL AS `codigo_csg_predio`', $sql);
        $this->assertSame([], $parametros);
    }

    public function test_una_lectura_erronea_o_vacia_conserva_la_copia_y_un_cambio_no_mezcla_fuentes(): void
    {
        $this->putJson(self::RUTA, $this->datos())->assertOk();
        $lector = app(ServicioLecturaStockOrigen::class);
        $this->assertSame('correcta', $lector->leer()->estado);
        $primera = PalletStockOrigen::sole();
        $this->filas = [['Pallet' => '0000123', 'cantidad' => 'no son cajas']];
        $this->assertSame('mapeo_invalido', $lector->leer()->causa);
        $this->filas = [];
        $this->assertSame('sin_filas', $lector->leer()->causa);
        $this->assertTrue($primera->fresh()->presente);
        $this->assertSame(40, $primera->fresh()->cajas);
        $this->putJson(self::RUTA, [...$this->datos([['columna' => 'bodega_real', 'valor' => 'OTRA']]), 'version_conocida' => 1])->assertOk();
        $this->assertSame(0, app(ServicioPendientesValidacionOrigen::class)->resumen()['resumen']['en_origen']);
        $this->filas = [['Pallet' => '0000123', 'cantidad' => 10]];
        $this->assertSame('correcta', $lector->leer()->estado);
        $this->assertDatabaseCount('pallets_stock_origen', 2);
        $this->assertSame(1, app(ServicioPendientesValidacionOrigen::class)->resumen()['resumen']['en_origen']);
        $this->assertSame(40, $primera->fresh()->cajas);
        $this->assertNotSame($primera->ambito, $lector->configuracion()['ambito']);
        $this->assertSame(['OTRA'], end($this->consultas)[1]);
    }

    public function test_descarta_una_lectura_si_cambian_la_conexion_o_el_mapeo_mientras_responde(): void
    {
        $this->putJson(self::RUTA, $this->datos())->assertOk();
        $this->duranteLectura = function () {
            $this->conexion->update(['version' => 2]);
        };
        $this->assertSame('configuracion_cambiada', app(ServicioLecturaStockOrigen::class)->leer()->causa);
        $this->assertDatabaseCount('pallets_stock_origen', 0);
        $this->duranteLectura = function () {
            ConfiguracionOrigen::find(ConfiguracionOrigen::STOCK)->increment('version');
        };
        $this->assertSame('configuracion_cambiada', app(ServicioLecturaStockOrigen::class)->leer()->causa);
        $this->assertDatabaseCount('pallets_stock_origen', 0);
    }

    public function test_inhabilitar_no_vuelve_a_la_configuracion_antigua_y_sanitiza_errores(): void
    {
        $datos = $this->datos();
        $datos['ajustes']['habilitada'] = false;
        $this->putJson(self::RUTA, $datos)->assertOk();
        $this->assertNull(app(ServicioLecturaStockOrigen::class)->leer());
        $this->duranteLectura = fn () => throw new PDOException('servidor_privado clave_fixture');
        $respuesta = $this->postJson(self::RUTA.'/simular', $datos)->assertUnprocessable();
        $this->assertStringNotContainsString('servidor_privado', $respuesta->getContent());
        $this->assertStringNotContainsString('clave_fixture', $respuesta->getContent());
    }
}
