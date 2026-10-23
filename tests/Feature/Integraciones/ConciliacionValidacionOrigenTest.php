<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\ClienteValidacion;
use App\Models\ConciliacionValidacionOrigen;
use App\Models\ConexionExterna;
use App\Models\Dispositivo;
use App\Models\OrigenValidacion;
use App\Models\PerfilAcceso;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Clientes\ServicioCliente;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use App\Services\Validacion\ServicioConciliacionValidacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ConciliacionValidacionOrigenTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private array $catalogo;

    private array|\Throwable $filas;

    private string $token;

    private const URL = '/api/validacion/pendientes-origen/conciliaciones';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'America/Santiago'));
        $this->usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = app(ServicioCliente::class)->guardarMaestro(['codigo' => 'EXP', 'nombre' => 'Cliente prueba'], $this->usuario);
        $cv = ClienteValidacion::where('cliente_id', $cliente->id)->where('temporada_id', $temporada->id)->sole();
        $c = app(ServicioCatalogoJerarquicoValidacion::class);
        $especie = $c->guardarEspecie(['temporada_id' => $temporada->id, 'nombre' => 'Cereza', 'codigo_externo' => 'CER']);
        $variedad = $c->guardarVariedad(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina']);
        foreach (['2J', '3J'] as $calibre) {
            $c->guardarCalibre(['especie_validacion_id' => $especie->id, 'nombre' => $calibre]);
        }
        foreach (['Marca A', 'Marca B'] as $marca) {
            $c->guardarMarca(['cliente_validacion_id' => $cv->id, 'nombre' => $marca]);
        }
        foreach (['001234', '001567'] as $csg) {
            $c->guardarCsg(['temporada_id' => $temporada->id, 'codigo' => $csg, 'variedad_ids' => [$variedad->id]]);
        }
        $envase = $c->guardarEnvase(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cv->id, 'nombre' => 'Caja 5 kg', 'codigo_externo' => 'CJ5', 'kilos_netos_por_caja' => 5]);
        $categoria = $c->guardarCategoria(['temporada_id' => $temporada->id, 'nombre' => 'Exportación']);
        $articulo = ArticuloValidacion::where('temporada_id', $temporada->id)->where('calibre', '2J')->where('envase_validacion_id', $envase->id)->firstOrFail();
        $origen = OrigenValidacion::where('temporada_id', $temporada->id)->where('marca', 'Marca A')->where('csg', '001234')->firstOrFail();
        $this->catalogo = ['temporada_id' => $temporada->id, 'catalogo_version' => $temporada->fresh()->version_catalogo,
            'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id, 'categoria_validacion_id' => $categoria->id];
        $d = Dispositivo::create(['codigo' => 'PDA-CUADRATURA', 'nombre' => 'PDA prueba', 'plataforma' => 'android', 'activo' => true]);
        $this->token = $this->usuario->crearTokenParaDispositivo($d, 'prueba')->plainTextToken;
        ConexionExterna::create(['nombre' => 'Planta prueba', 'credenciales' => ['host' => '192.0.2.10', 'puerto' => 3306, 'usuario' => 'lector', 'password' => 'fixture', 'base_datos' => 'origen'],
            'activa' => true, 'version' => 1, 'creado_por_user_id' => $this->usuario->id, 'actualizado_por_user_id' => $this->usuario->id]);
        $prueba = $this;
        $this->app->instance(ConectorMySqlLectura::class, new class($prueba) extends ConectorMySqlLectura
        {
            public function __construct(private ConciliacionValidacionOrigenTest $prueba) {}

            public function consultar(array $credenciales, string $sql, array $parametros = [], int $segundos = 60): array
            {
                return $this->prueba->respuesta();
            }
        });
        $this->filas = [$this->fila()];
        $this->withToken($this->token);
    }

    public function respuesta(): array
    {
        if ($this->filas instanceof \Throwable) {
            throw $this->filas;
        }

        return $this->filas;
    }

    private function fila(array $cambios = []): array
    {
        return ['Pallet' => '000123', 'cantidad' => 240, 'kilos_netos' => 1200, 'marca' => 'Marca B', 'cod_calibre' => '3J',
            'codigo_csg_predio' => '001567', 'cod_empresa' => 'EXP', 'cod_especie' => 'CER', 'descripcion_especie' => 'CEREZA',
            'variedad' => 'Santina', 'codigo_variedad' => 'SAN', 'cod_articulo' => 'CJ5', 'descripcion_articulo' => 'Caja 5 kg', 'peso_neto_articulo' => 5,
            'cod_categoria' => 'Exportación', 'cod_estado_stock_paletizado' => 'PC', 'cod_bodega' => 'AR01', 'cod_temporada' => '00',
            'fecha_embalaje' => '2026-10-23', 'fecha_recepcion' => '2026-10-23 10:00:00', ...$cambios];
    }

    private function leer(): void
    {
        $this->travel(1)->minutes();
        $this->postJson('/api/validacion/pendientes-origen/leer')->assertOk();
    }

    private function validar(): ConciliacionValidacionOrigen
    {
        $payload = ['operacion_id' => (string) Str::uuid(), 'numero_folio' => '000123', 'tipo_bulto' => 'pallet', 'cantidad_cajas' => 236,
            'linea_proceso' => 1, 'turno' => 'A', ...$this->catalogo, 'resultado' => 'aprobado', 'generado_dispositivo_at' => now()->toAtomString()];
        $this->postJson('/api/validacion/pallets', $payload)->assertCreated();
        $this->postJson('/api/validacion/pallets', $payload)->assertOk();

        return ConciliacionValidacionOrigen::sole();
    }

    public function test_registra_cuatro_diferencias_y_respalda_la_copia_sin_duplicar_reintentos(): void
    {
        $this->leer();
        $caso = $this->validar();
        $this->assertSame('registro_servidor', $caso->captura);
        $this->assertSame(240, $caso->planta_inicial['cajas']);
        $this->assertSame(236, $caso->validacion_inicial['datos']['cajas']);
        $campos = array_keys($caso->comparacion);
        sort($campos);
        $this->assertSame(['cajas', 'calibre', 'csg', 'marca'], $campos);
        $this->assertSame(['diferente'], array_values(array_unique(array_column($caso->comparacion, 'estado'))));
        $this->getJson(self::URL.'?campo=marca&folio=000123')->assertOk()->assertJsonPath('casos.total', 1)->assertJsonPath('casos.data.0.numero_folio', '000123');
        $this->assertDatabaseCount('eventos_conciliacion_validacion', 1);
        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL.'/'.$caso->id)->assertOk();
        $this->assertDatabaseCount('eventos_conciliacion_validacion', 1);
        $this->assertSame(1, $caso->fresh()->version);
    }

    public function test_gestion_idempotente_no_cierra_y_lectura_posterior_cuadra_y_reabre(): void
    {
        $this->leer();
        $caso = $this->validar();
        $gestion = ['operacion_id' => (string) Str::uuid(), 'version' => 1, 'accion' => 'gestionada', 'comentario' => 'Corrección ingresada en sistema planta', 'referencia' => 'Folio 000123'];
        $url = self::URL.'/'.$caso->id.'/gestiones';
        $this->postJson($url, $gestion)->assertOk()->assertJsonPath('estado', 'en_revision');
        $this->postJson($url, $gestion)->assertOk()->assertJsonPath('version', 2);
        $this->postJson($url, [...$gestion, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson($url, [...$gestion, 'comentario' => 'Otra gestión'])->assertConflict();
        $inicial = $caso->planta_inicial;
        $this->filas = [$this->fila(['cantidad' => 236, 'marca' => 'Marca A', 'cod_calibre' => '2J', 'codigo_csg_predio' => '001234'])];
        $this->leer();
        $this->assertSame('cuadrado', $caso->fresh()->estado);
        $this->assertEquals($inicial, $caso->fresh()->planta_inicial);
        $this->filas = [$this->fila(['cantidad' => 235])];
        $this->leer();
        $this->assertSame('reabierto', $caso->fresh()->estado);
        $this->assertSame(236, $caso->fresh()->validacion_inicial['datos']['cajas']);
    }

    public function test_falla_ausencia_y_antiguedad_no_se_presentan_como_cuadratura(): void
    {
        $this->leer();
        $caso = $this->validar();
        $version = $caso->version;
        $this->filas = new \PDOException('fixture conexión caída');
        $this->postJson('/api/validacion/pendientes-origen/leer')->assertUnprocessable();
        $this->assertSame($version, $caso->fresh()->version);
        $this->getJson(self::URL)->assertJsonPath('casos.data.0.comprobacion_vigente', false);
        $this->filas = [$this->fila(['Pallet' => 'OTRO'])];
        $this->leer();
        $this->assertSame('pendiente', $caso->fresh()->estado);
        $this->assertFalse($caso->fresh()->planta_actual['presente']);
        $this->travel(30)->minutes();
        $this->getJson(self::URL)->assertJsonPath('fuente.vigente', false);
    }

    public function test_cambios_operacionales_no_reescriben_validacion_ni_reabren_un_caso_cuadrado(): void
    {
        $this->leer();
        $caso = $this->validar();
        $this->filas = [$this->fila(['cantidad' => 236, 'marca' => 'Marca A', 'cod_calibre' => '2J', 'codigo_csg_predio' => '001234'])];
        $this->leer();
        $folio = $caso->validacion->folio;
        $f = $folio->datos_externos;
        $f['cantidad_cajas'] = 100;
        $folio->update(['datos_externos' => $f]);
        $this->filas = [$this->fila(['cantidad' => 100])];
        $this->leer();
        $this->assertSame('cuadrado', $caso->fresh()->estado);
        $this->getJson(self::URL)->assertJsonPath('casos.data.0.contexto_actual', 'folio_modificado')->assertJsonPath('casos.data.0.comprobacion_vigente', false);
        $this->assertSame(236, $caso->fresh()->validacion_actual['datos']['cajas']);
        $folio->update(['activo' => false, 'estado_operacional' => 'despachado']);
        $this->getJson(self::URL)->assertJsonPath('casos.data.0.contexto_actual', 'salida_o_transformacion');
    }

    public function test_consulta_no_gestiona_y_la_temporada_cerrada_sigue_consultable(): void
    {
        $this->leer();
        $caso = $this->validar();
        $perfil = PerfilAcceso::create(['codigo' => 'CONS-VAL', 'nombre' => 'Consulta validación', 'rol_base' => RolUsuario::Consulta,
            'modulos' => ['frigorifico.validacion'], 'modulos_tablet' => [], 'activo' => true]);
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta, 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($consulta, 'sanctum')->getJson(self::URL)->assertOk()->assertJsonPath('puede_gestionar', false);
        $this->postJson(self::URL.'/'.$caso->id.'/gestiones', [])->assertForbidden();
        $this->postJson(self::URL.'/recomprobar')->assertForbidden();
        $this->actingAs($this->usuario, 'sanctum');
        $this->desactivarTemporadasDePrueba();
        $this->getJson(self::URL.'?temporada_id='.$caso->temporada_id)->assertOk()->assertJsonPath('casos.total', 1);
        $this->postJson(self::URL.'/'.$caso->id.'/gestiones', [])->assertConflict();
    }

    public function test_excel_incluye_los_cuatro_campos_y_respaldo_original_con_ceros(): void
    {
        $this->leer();
        $this->validar();
        $r = $this->get(self::URL.'/excel?campo=cajas')->assertOk();
        $zip = new ZipArchive;
        $zip->open($r->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        foreach (['000123', '001234', '001567', 'Marca', 'Calibre', '240', '236'] as $texto) {
            $this->assertStringContainsString($texto, $xml);
        }
        $this->get(self::URL.'/excel?folio=NO-EXISTE')->assertOk();
    }

    public function test_correccion_conserva_el_respaldo_y_exige_otra_lectura_para_cuadrar(): void
    {
        $this->leer();
        $caso = $this->validar();
        $articulo = ArticuloValidacion::where('temporada_id', $caso->temporada_id)->where('calibre', '3J')->firstOrFail();
        $origen = OrigenValidacion::where('temporada_id', $caso->temporada_id)->where('marca', 'Marca B')->where('csg', '001567')->firstOrFail();
        $payload = ['operacion_id' => (string) Str::uuid(), 'tipo_bulto' => 'pallet', 'cantidad_cajas' => 240,
            'linea_proceso' => 1, 'turno' => 'A', 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen->id,
            'categoria_validacion_id' => $this->catalogo['categoria_validacion_id'], 'motivo_correccion' => 'Corrección autorizada de la captura física'];
        $this->withToken($this->usuario->createToken('oficina-prueba', ['oficina'])->plainTextToken);
        $url = '/api/validacion/pallets/'.$caso->validacion_pallet_id.'/corregir';
        $this->putJson($url, $payload)->assertOk();
        $this->putJson($url, $payload)->assertOk();
        $caso->refresh();
        $this->assertSame(236, $caso->validacion_inicial['datos']['cajas']);
        $this->assertSame(240, $caso->validacion_actual['datos']['cajas']);
        $this->assertSame('pendiente', $caso->estado);
        $this->assertDatabaseCount('eventos_conciliacion_validacion', 2);
        $this->assertDatabaseHas('eventos_conciliacion_validacion', ['conciliacion_id' => $caso->id,
            'tipo' => 'correccion_validacion', 'user_id' => $this->usuario->id, 'comentario' => $payload['motivo_correccion']]);
        $this->leer();
        $this->assertSame('cuadrado', $caso->fresh()->estado);
    }

    public function test_incorpora_validaciones_anteriores_sin_inventar_una_copia_al_validar(): void
    {
        config(['integraciones.stock_pt.habilitada' => false]);
        $payload = ['operacion_id' => (string) Str::uuid(), 'numero_folio' => '000123', 'tipo_bulto' => 'pallet', 'cantidad_cajas' => 236,
            'linea_proceso' => 1, 'turno' => 'A', ...$this->catalogo, 'resultado' => 'aprobado', 'generado_dispositivo_at' => now()->toAtomString()];
        $this->postJson('/api/validacion/pallets', $payload)->assertCreated();
        $this->assertDatabaseCount('conciliaciones_validacion_origen', 0);
        config(['integraciones.stock_pt.habilitada' => true]);
        $this->leer();
        $caso = ConciliacionValidacionOrigen::sole();
        $this->assertSame('primera_comparacion', $caso->captura);
        $this->assertTrue($caso->lectura_at->gt($caso->validado_at));
        $this->getJson(self::URL.'?hasta=2026-10-23')->assertOk()->assertJsonPath('casos.total', 1);
        $this->getJson(self::URL.'?desde=2026-10-24')->assertOk()->assertJsonPath('casos.total', 0);
        $this->getJson(self::URL.'?desde=2026-10-24&hasta=2026-10-23')->assertUnprocessable();
    }

    public function test_un_error_del_reporte_no_invalida_la_copia_correcta_y_puede_reintentarse(): void
    {
        $this->leer();
        $caso = $this->validar();
        $this->mock(ServicioConciliacionValidacion::class, function ($mock): void {
            $mock->shouldReceive('recomprobar')->once()->andThrow(new \RuntimeException('fixture reporte'));
        });
        $this->leer();
        $this->app->forgetInstance(ServicioConciliacionValidacion::class);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('fuente.vigente', true)
            ->assertJsonPath('fuente.conciliacion_pendiente', true)->assertJsonPath('casos.data.0.comprobacion_vigente', false);
        $this->postJson(self::URL.'/recomprobar')->assertOk();
        $this->getJson(self::URL)->assertJsonPath('fuente.conciliacion_pendiente', false)
            ->assertJsonPath('casos.data.0.comprobacion_vigente', true);
        $this->assertSame(236, $caso->fresh()->validacion_inicial['datos']['cajas']);
    }

    public function test_un_cambio_de_fuente_legacy_no_mezcla_casos_ni_reutiliza_su_copia(): void
    {
        $this->leer();
        $caso = $this->validar();
        $this->travel(1)->minutes();
        ConexionExterna::sole()->update(['version' => 2]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('casos.total', 0)->assertJsonPath('fuente.vigente', false);
        $this->getJson(self::URL.'?otras_fuentes=1')->assertJsonPath('casos.total', 1)->assertJsonPath('casos.data.0.comprobacion_vigente', false);
        $this->postJson(self::URL.'/'.$caso->id.'/gestiones', ['operacion_id' => (string) Str::uuid(),
            'version' => 1, 'accion' => 'revisar', 'comentario' => 'No corresponde a esta fuente'])->assertConflict();
        $this->postJson(self::URL.'/recomprobar')->assertOk();
        $nuevo = ConciliacionValidacionOrigen::where('id', '!=', $caso->id)->sole();
        $this->assertNull($nuevo->planta_inicial);
        $this->assertSame('por_revisar', $nuevo->estado);
        $this->leer();
        $this->assertSame('pendiente', $nuevo->fresh()->estado);
        $this->assertSame(240, $caso->fresh()->planta_inicial['cajas']);
        $this->assertDatabaseCount('conciliaciones_validacion_origen', 2);
    }
}
