<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Models\ConexionExterna;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\LecturaStockOrigen;
use App\Models\PalletStockOrigen;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Integraciones\ConectorMySqlLectura;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;
use ZipArchive;

class StockOrigenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{sql: string, parametros: array<int, mixed>}> */
    private array $consultas = [];

    /** @var list<array<string, mixed>>|\Throwable */
    private array|\Throwable $respuesta = [];

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $prueba = $this;
        $this->app->instance(ConectorMySqlLectura::class, new class($prueba) extends ConectorMySqlLectura
        {
            public function __construct(private readonly StockOrigenTest $prueba) {}

            public function consultar(array $credenciales, string $sql, array $parametros = [], int $segundos = 60): array
            {
                return $this->prueba->responder($sql, $parametros);
            }
        });
    }

    /** @param array<int, mixed> $parametros */
    public function responder(string $sql, array $parametros): array
    {
        $this->consultas[] = ['sql' => $sql, 'parametros' => $parametros];
        if ($this->respuesta instanceof \Throwable) {
            throw $this->respuesta;
        }

        return $this->respuesta;
    }

    public function test_agrupa_las_lineas_del_origen_por_pallet_como_la_validacion(): void
    {
        $this->perfil();
        $this->respuesta = [
            // Pallet con dos procesos y dos CSG: un artículo y dos líneas de composición.
            $this->fila('1000000001', ['cantidad' => 70, 'kilos_netos' => 350, 'codigo_csg_predio' => '100001', 'lote_recepcion' => '11462', 'Proceso' => 'AR01STD10000']),
            $this->fila('1000000001', ['cantidad' => 30, 'kilos_netos' => 150, 'codigo_csg_predio' => '100001', 'lote_recepcion' => '11462', 'Proceso' => 'AR01STD10000']),
            $this->fila('1000000001', ['cantidad' => 20, 'kilos_netos' => 100, 'codigo_csg_predio' => '100002', 'lote_recepcion' => '11463', 'Proceso' => 'ar01std10001']),
            // Saldo (PI) sin lote informado.
            $this->fila('1000000002', ['cantidad' => 24, 'kilos_netos' => 120, 'cod_estado_stock_paletizado' => 'PI', 'lote_recepcion' => '00000', 'Proceso' => null]),
            // Pallet con calibres mezclados.
            $this->fila('1000000003', ['cantidad' => 10, 'cod_calibre' => 'J']),
            $this->fila('1000000003', ['cantidad' => 5, 'cod_calibre' => 'JD']),
        ];

        $this->artisan('integraciones:leer-stock-origen')
            ->expectsOutput('Stock de origen: 3 pallets (3 nuevos, 0 modificados, 0 retirados) desde 6 filas.')
            ->assertSuccessful();

        $this->assertSame(['AR01'], $this->consultas[0]['parametros']);
        $this->assertStringContainsString('FROM `obdc_stock` WHERE `cod_bodega` = ? AND `cod_tipo_articulo` = \'PT\'', $this->consultas[0]['sql']);
        $this->assertStringContainsString('`Pallet`, `cod_bodega`', $this->consultas[0]['sql']);

        $pallet = PalletStockOrigen::query()->where('numero_pallet', '1000000001')->sole();
        $this->assertSame('pallet', $pallet->tipo_bulto);
        $this->assertSame(120, $pallet->cajas);
        $this->assertSame('600.00', $pallet->kilos);
        $this->assertSame(['SANTINA', 'JD', 'C1', 'CJ5KG', 'CAJA GRANEL 5KG', 'MARCA PRUEBA'], [$pallet->variedad, $pallet->calibre, $pallet->categoria, $pallet->envase_codigo, $pallet->envase, $pallet->marca]);
        $this->assertSame([], $pallet->mezclados);
        $this->assertEquals([
            ['csg' => '100001', 'lote' => '11462', 'proceso' => 'AR01STD10000', 'productor_codigo' => '11111111-1P', 'productor' => 'PRODUCTOR DE PRUEBA SPA', 'cajas' => 100, 'kilos' => 500],
            ['csg' => '100002', 'lote' => '11463', 'proceso' => 'AR01STD10001', 'productor_codigo' => '11111111-1P', 'productor' => 'PRODUCTOR DE PRUEBA SPA', 'cajas' => 20, 'kilos' => 100],
        ], array_map(fn (array $linea): array => [...$linea, 'kilos' => (int) $linea['kilos']], $pallet->composicion));
        // 11:59 en planta es 14:59 UTC.
        $this->assertSame('2026-10-05 14:59:00', $pallet->recibido_origen_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-07', $pallet->fecha_embalaje->toDateString());

        $saldo = PalletStockOrigen::query()->where('numero_pallet', '1000000002')->sole();
        $this->assertSame('saldo', $saldo->tipo_bulto);
        $this->assertNull($saldo->composicion[0]['lote']);
        $this->assertNull($saldo->composicion[0]['proceso']);
        $this->assertSame(['calibre'], PalletStockOrigen::query()->where('numero_pallet', '1000000003')->sole()->mezclados);
    }

    public function test_una_nueva_lectura_registra_cambios_y_pallets_que_salieron_de_bodega(): void
    {
        $this->perfil();
        $this->respuesta = [$this->fila('A1', ['cantidad' => 120]), $this->fila('A2', ['cantidad' => 60])];
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();

        $this->travel(5)->minutes();
        $this->respuesta = [$this->fila('A1', ['cantidad' => 112])];
        $this->artisan('integraciones:leer-stock-origen')
            ->expectsOutput('Stock de origen: 1 pallets (0 nuevos, 1 modificados, 1 retirados) desde 1 filas.')
            ->assertSuccessful();
        $retirado = PalletStockOrigen::query()->where('numero_pallet', 'A2')->sole();
        $this->assertFalse($retirado->presente);
        $this->assertNotNull($retirado->ausente_desde);

        // Si vuelve a aparecer, queda presente otra vez.
        $this->respuesta = [$this->fila('A1', ['cantidad' => 112]), $this->fila('A2', ['cantidad' => 60])];
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();
        $this->assertTrue(PalletStockOrigen::query()->where('numero_pallet', 'A2')->sole()->presente);
        $this->assertSame(3, LecturaStockOrigen::query()->where('estado', 'correcta')->count());
    }

    public function test_una_falla_se_registra_con_su_causa_y_conserva_la_lectura_anterior(): void
    {
        $this->perfil();
        $this->respuesta = [$this->fila('A1', ['cantidad' => 120])];
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();

        $error = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'origen.obdc_stock' doesn't exist");
        $error->errorInfo = ['42S02', 1146, "Table 'origen.obdc_stock' doesn't exist"];
        $this->respuesta = $error;
        $this->artisan('integraciones:leer-stock-origen')
            ->expectsOutput('Lectura fallida (tabla_inexistente): No existe la tabla o vista obdc_stock en la base de origen. Revisa WMS_STOCK_ORIGEN_TABLA.')
            ->assertFailed();

        $acceso = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'lector'@'10.0.0.5' (using password: YES)");
        $acceso->errorInfo = ['HY000', 1045, "Access denied for user 'lector'@'10.0.0.5'"];
        $this->respuesta = $acceso;
        $this->artisan('integraciones:leer-stock-origen')->assertFailed();
        $this->assertSame('acceso_denegado', LecturaStockOrigen::query()->where('causa', 'acceso_denegado')->sole()->causa);
        $this->assertStringNotContainsString('lector', LecturaStockOrigen::query()->pluck('mensaje')->implode(' '));

        // Una vista vacía no da todo por retirado.
        $this->respuesta = [];
        $this->artisan('integraciones:leer-stock-origen')->assertFailed();
        $this->assertTrue(PalletStockOrigen::query()->where('numero_pallet', 'A1')->sole()->presente);
        $this->assertSame(1, LecturaStockOrigen::query()->where('causa', 'sin_filas')->count());
    }

    public function test_sin_perfil_unico_o_con_tabla_invalida_no_consulta_el_origen(): void
    {
        $this->artisan('integraciones:leer-stock-origen')
            ->expectsOutput('Lectura del stock de origen sin configurar, deshabilitada o en curso.')
            ->assertSuccessful();
        $this->perfil('SE');
        $this->perfil('OTRO');
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();
        $this->assertSame([], $this->consultas);

        config(['integraciones.stock_pt.conexion' => 'SE', 'integraciones.stock_pt.tabla' => 'obdc_stock; DROP TABLE x']);
        $this->artisan('integraciones:leer-stock-origen')->assertFailed();
        $this->assertSame([], $this->consultas);
        $this->assertSame('tabla_invalida', LecturaStockOrigen::query()->sole()->causa);

        config(['integraciones.stock_pt.tabla' => 'obdc_stock', 'integraciones.stock_pt.temporada' => '00']);
        $this->respuesta = [$this->fila('A1')];
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();
        $this->assertSame(['AR01', '00'], $this->consultas[0]['parametros']);
        $this->assertStringContainsString('AND `cod_temporada` = ?', $this->consultas[0]['sql']);
    }

    public function test_la_lectura_esta_programada_cada_cinco_minutos(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($evento): bool => str_contains((string) $evento->command, 'integraciones:leer-stock-origen'));
        $this->assertNotNull($evento);
        $this->assertSame('*/5 * * * *', $evento->expression);
    }

    public function test_compara_el_stock_de_origen_con_lo_validado_en_folios(): void
    {
        $this->perfil();
        $this->respuesta = [
            $this->fila('P-PENDIENTE', ['cantidad' => 120, 'fecha_recepcion' => '2026-10-15 08:00:00']),
            $this->fila('P-IGUAL', ['cantidad' => 100, 'codigo_csg_predio' => '100001']),
            $this->fila('P-IGUAL', ['cantidad' => 20, 'codigo_csg_predio' => '100002']),
            $this->fila('P-CAJAS', ['cantidad' => 120]),
            $this->fila('P-DESPACHADO', ['cantidad' => 120]),
            $this->fila('P-OBSERVADO', ['cantidad' => 120]),
            $this->fila('P-ANULADO', ['cantidad' => 120]),
        ];
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();

        $this->folio('P-IGUAL', 120, [['csg' => '100001', 'cantidad_cajas' => 100], ['csg' => '100002', 'cantidad_cajas' => 20]]);
        $this->folio('P-CAJAS', 112, [['csg' => '100001', 'cantidad_cajas' => 112]]);
        $this->folio('P-DESPACHADO', 120, [['csg' => '100001', 'cantidad_cajas' => 120]], ['estado_operacional' => 'despachado']);
        $this->folio('P-ANULADO', 120, [], ['estado_operacional' => 'anulado', 'activo' => false]);
        $this->folio('SOLO-FOLIOS', 120, [['csg' => '100001', 'cantidad_cajas' => 120]]);
        $this->intento('P-OBSERVADO', 'observado', 'cantidad_cajas_incorrecta');
        $this->intento('P-ANULADO', 'aprobado', null);

        $respuesta = $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/validacion/pendientes-origen')
            ->assertOk()
            ->assertJsonPath('fuente.conexion', 'SE')
            ->assertJsonPath('lectura.ultima.estado', 'correcta')
            ->assertJsonPath('lectura.desactualizada', false)
            ->assertJsonPath('resumen', [
                'en_origen' => 6,
                'validados' => 3,
                'pendientes' => 1,
                'cajas_pendientes' => 120,
                'por_resolver' => 2,
                'con_diferencias' => 2,
                'solo_en_folios' => 1,
            ]);
        $this->assertSame('P-PENDIENTE', $respuesta->json('pendientes.0.numero'));
        $this->assertSame(['100001'], $respuesta->json('pendientes.0.csgs'));
        $this->assertSame(
            ['P-ANULADO' => 'anulado', 'P-OBSERVADO' => 'observado'],
            collect($respuesta->json('por_resolver'))->sortBy('numero')->pluck('validacion.situacion', 'numero')->all(),
        );
        $this->assertSame('cantidad_cajas_incorrecta', collect($respuesta->json('por_resolver'))->firstWhere('numero', 'P-OBSERVADO')['validacion']['motivo']);
        $diferencias = collect($respuesta->json('diferencias'))->keyBy('numero');
        $this->assertSame([
            ['campo' => 'cajas', 'origen' => 120, 'folios' => 112],
            ['campo' => 'csg', 'origen' => '100001: 120', 'folios' => '100001: 112'],
        ], $diferencias['P-CAJAS']['diferencias']);
        $this->assertSame('estado', $diferencias['P-DESPACHADO']['diferencias'][0]['campo']);
        $this->assertSame('SOLO-FOLIOS', $respuesta->json('solo_en_folios.0.numero'));

        // Más tarde, sin lecturas nuevas, la copia queda desactualizada.
        $this->travel(30)->minutes();
        $this->getJson('/api/validacion/pendientes-origen')->assertJsonPath('lectura.desactualizada', true);

        $excel = $this->get('/api/validacion/pendientes-origen/excel')->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($excel->baseResponse->getFile()->getPathname()));
        $hoja = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        foreach (['Pendiente de validar', 'Validado con diferencias', 'Solo en FoliOS', 'Observado', 'P-PENDIENTE', 'SOLO-FOLIOS'] as $texto) {
            $this->assertStringContainsString($texto, $hoja);
        }

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum')
            ->getJson('/api/validacion/pendientes-origen')
            ->assertForbidden();
    }

    public function test_leer_ahora_lee_el_origen_y_responde_el_resultado(): void
    {
        $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/validacion/pendientes-origen/leer')
            ->assertConflict();
        $this->perfil();
        $this->respuesta = [$this->fila('A1', ['cantidad' => 120])];
        $this->postJson('/api/validacion/pendientes-origen/leer')
            ->assertOk()
            ->assertJsonPath('message', 'Stock de origen leído: 1 pallets.')
            ->assertJsonPath('resumen.pendientes', 1);
        $this->assertSame($this->administrador->id, LecturaStockOrigen::query()->sole()->solicitada_por_user_id);

        $error = new PDOException('SQLSTATE[HY000] [2002] Connection timed out');
        $error->errorInfo = ['HY000', 2002, 'Connection timed out'];
        $this->respuesta = $error;
        $this->postJson('/api/validacion/pendientes-origen/leer')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se pudo leer el stock de origen. El servidor de FoliOS no llega a la base de origen. Revisa la IP, el puerto y el firewall.')
            ->assertJsonPath('resumen.pendientes', 1);
    }

    public function test_la_oficina_de_pendientes_esta_en_frigorifico(): void
    {
        $this->get('/oficina/validacion/pendientes')
            ->assertOk()
            ->assertSee('Pendientes por validar')
            ->assertSee('data-office-panel-switcher="pending-validation"', false)
            ->assertSee('/oficina/validacion/pendientes', false);
    }

    private function perfil(string $nombre = 'SE'): ConexionExterna
    {
        return ConexionExterna::query()->create([
            'nombre' => $nombre,
            'credenciales' => ['host' => '192.0.2.10', 'puerto' => 3306, 'usuario' => 'lector', 'password' => 'clave-prueba', 'base_datos' => 'origen'],
            'activa' => true,
            'version' => 1,
            'creado_por_user_id' => $this->administrador->id,
            'actualizado_por_user_id' => $this->administrador->id,
        ]);
    }

    /** Una fila con la forma de obdc_stock (los nombres de columna respetan sus mayúsculas). */
    private function fila(string $pallet, array $cambios = []): array
    {
        return [
            'cod_empresa' => 'EMP', 'cod_temporada' => '00', 'temporada' => 'TEMPORADA CAPACITACION',
            'cod_articulo' => 'CJ5KG', 'descripcion_articulo' => 'CAJA GRANEL 5KG', 'descripcion_especie' => 'CEREZAS',
            'codigo_entidad' => '11111111-1P', 'razon_social_entidad' => 'PRODUCTOR DE PRUEBA SPA', 'variedad' => 'SANTINA',
            'lote_recepcion' => '11462', 'cod_calibre' => 'JD', 'cod_categoria' => 'C1', 'codigo_csg_predio' => '100001',
            'fecha_embalaje' => '2026-10-07 00:00:00', 'cantidad' => 120, 'kilos_netos' => 600, 'Pallet' => $pallet,
            'cod_bodega' => 'AR01', 'origen_i' => 'Proceso', 'cod_estado_stock_paletizado' => 'PC', 'marca' => 'MARCA PRUEBA',
            'Proceso' => 'AR01STD10000', 'fecha_recepcion' => '2026-10-05 11:59:00',
            ...$cambios,
        ];
    }

    private function folio(string $numero, int $cajas, array $composicion, array $atributos = []): Folio
    {
        return Folio::query()->create([
            'numero_folio' => $numero, 'tipo_bulto' => 'pallet', 'estado_operacional' => 'pendiente_prefrio',
            'condicion_termica' => 'pendiente_prefrio', 'habilitacion_almacenamiento' => 'no_habilitado',
            'fecha_ingreso' => now(), 'activo' => true, 'variedad' => 'SANTINA', 'calibre' => 'JD',
            'origen_sistema' => 'validacion', 'estado_integracion' => 'no_vinculado',
            'datos_externos' => ['cantidad_cajas' => $cajas, 'envase' => 'CAJA GRANEL 5KG', 'composicion' => $composicion],
            ...$atributos,
        ]);
    }

    private function intento(string $numero, string $resultado, ?string $motivo): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $articulo = (string) Str::uuid();
        $origen = (string) Str::uuid();
        DB::table('articulos_validacion')->insert([
            'id' => $articulo, 'temporada_id' => $temporada->id, 'especie' => 'Cereza', 'variedad' => 'Santina',
            'calibre' => 'JD'.$numero, 'envase' => '5 kg', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen, 'temporada_id' => $temporada->id, 'cliente' => 'EMP', 'marca' => 'MARCA PRUEBA',
            'csg' => '100001'.$numero, 'predio' => 'MARCA PRUEBA', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('validaciones_pallet')->insert([
            'id' => (string) Str::uuid(), 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'numero_folio' => $numero, 'numero_intento' => 1, 'tipo_bulto' => 'pallet', 'cantidad_cajas' => 120,
            'linea_proceso' => 1, 'turno' => 'A', 'temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen, 'resultado' => $resultado, 'motivo' => $motivo, 'estado' => 'aceptada',
            'catalogo_version_dispositivo' => 1, 'catalogo_version_servidor' => 1, 'snapshot' => '{}',
            'user_id' => $this->administrador->id,
            'dispositivo_id' => Dispositivo::query()->firstOrCreate(['codigo' => 'PDA-PRUEBA'], ['nombre' => 'PDA de prueba'])->id,
            'generado_dispositivo_at' => now(), 'recibido_servidor_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
