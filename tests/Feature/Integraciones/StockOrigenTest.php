<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Models\AliasCliente;
use App\Models\CalibreValidacion;
use App\Models\ClienteValidacion;
use App\Models\ConexionExterna;
use App\Models\CsgValidacion;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\HomologacionStockOrigen;
use App\Models\LecturaStockOrigen;
use App\Models\MarcaValidacion;
use App\Models\PalletStockOrigen;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Clientes\ServicioCliente;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
        $this->assertStringContainsString('FROM `odbc_stock` WHERE `cod_bodega` = ? AND `cod_tipo_articulo` = \'PT\'', $this->consultas[0]['sql']);
        $this->assertStringContainsString('`Pallet`, `cod_bodega`', $this->consultas[0]['sql']);

        $pallet = PalletStockOrigen::query()->where('numero_pallet', '1000000001')->sole();
        $this->assertSame('pallet', $pallet->tipo_bulto);
        $this->assertSame(120, $pallet->cajas);
        $this->assertSame('600.00', $pallet->kilos);
        $this->assertSame(['SANTINA', 'JD', 'C1', 'CJ5KG', 'CAJA GRANEL 5KG', 'MARCA PRUEBA'], [$pallet->variedad, $pallet->calibre, $pallet->categoria, $pallet->envase_codigo, $pallet->envase, $pallet->marca]);
        $this->assertSame(['EMP', 'CER', 'CEREZAS', 'SAN', '5.000'], [$pallet->empresa, $pallet->especie_codigo, $pallet->especie, $pallet->variedad_codigo, $pallet->envase_kilos]);
        $this->assertSame([], $pallet->mezclados);
        $this->assertEquals([
            ['csg' => '100001', 'predio' => 'PREDIO DE PRUEBA', 'variedad' => 'SANTINA', 'lote' => '11462', 'proceso' => 'AR01STD10000', 'productor_codigo' => '11111111-1P', 'productor' => 'PRODUCTOR DE PRUEBA SPA', 'cajas' => 100, 'kilos' => 500],
            ['csg' => '100002', 'predio' => 'PREDIO DE PRUEBA', 'variedad' => 'SANTINA', 'lote' => '11463', 'proceso' => 'AR01STD10001', 'productor_codigo' => '11111111-1P', 'productor' => 'PRODUCTOR DE PRUEBA SPA', 'cajas' => 20, 'kilos' => 100],
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

        $error = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'origen.odbc_stock' doesn't exist");
        $error->errorInfo = ['42S02', 1146, "Table 'origen.odbc_stock' doesn't exist"];
        $this->respuesta = $error;
        $this->artisan('integraciones:leer-stock-origen')
            ->expectsOutput('Lectura fallida (tabla_inexistente): No existe la tabla o vista odbc_stock en la base de origen. Revisa WMS_STOCK_ORIGEN_TABLA.')
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

        config(['integraciones.stock_pt.conexion' => 'SE', 'integraciones.stock_pt.tabla' => 'odbc_stock; DROP TABLE x']);
        $this->artisan('integraciones:leer-stock-origen')->assertFailed();
        $this->assertSame([], $this->consultas);
        $this->assertSame('tabla_invalida', LecturaStockOrigen::query()->sole()->causa);

        config(['integraciones.stock_pt.tabla' => 'odbc_stock', 'integraciones.stock_pt.temporada' => '00']);
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
                // Catálogo vacío: cliente, marca, especie, variedad, calibre, embalaje, categoría y dos CSG.
                'catalogo_pendiente' => 9,
                'pallets_sin_catalogo' => 6,
            ]);
        $this->assertSame('P-PENDIENTE', $respuesta->json('pendientes.0.numero'));
        $this->assertSame(['100001'], $respuesta->json('pendientes.0.csgs'));
        $this->assertSame(
            ['P-ANULADO' => 'anulado', 'P-OBSERVADO' => 'observado'],
            collect($respuesta->json('por_resolver'))->sortBy('numero')->pluck('validacion.situacion', 'numero')->all(),
        );
        $this->assertSame('cantidad_cajas_incorrecta', collect($respuesta->json('por_resolver'))->firstWhere('numero', 'P-OBSERVADO')['validacion']['motivo']);
        $diferencias = collect($respuesta->json('diferencias'))->keyBy('numero');
        // Sin equivalencias en el catálogo, el artículo no se compara (salvo el calibre, tal cual).
        $this->assertSame([
            ['campo' => 'cajas', 'etiqueta' => 'Cajas', 'origen' => 120, 'catalogo' => null, 'folios' => 112],
            ['campo' => 'csg', 'etiqueta' => 'Cajas por CSG', 'origen' => '100001: 120', 'catalogo' => null, 'folios' => '100001: 112'],
        ], $diferencias['P-CAJAS']['diferencias']);
        $this->assertSame(['Cliente', 'Marca', 'Especie', 'Variedad', 'Calibre', 'Embalaje', 'CSG'], $respuesta->json('pendientes.0.sin_catalogo'));
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

    public function test_lo_que_trae_el_origen_se_vincula_o_se_agrega_al_catalogo_y_se_compara(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = app(ServicioCliente::class)->guardarMaestro(['codigo' => 'EXP', 'nombre' => 'Exportadora Prueba'], $this->administrador);
        $clienteValidacion = ClienteValidacion::query()->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)->sole();
        $catalogo = app(ServicioCatalogoJerarquicoValidacion::class);
        $cereza = $catalogo->guardarEspecie(['temporada_id' => $temporada->id, 'nombre' => 'Cereza']);
        $santina = $catalogo->guardarVariedad(['especie_validacion_id' => $cereza->id, 'nombre' => 'Santina']);
        $catalogo->guardarCalibre(['especie_validacion_id' => $cereza->id, 'nombre' => 'JD']);
        $categoria = $catalogo->guardarCategoria(['temporada_id' => $temporada->id, 'nombre' => 'CAT1']);
        $catalogo->guardarCsg(['temporada_id' => $temporada->id, 'codigo' => '100001', 'variedad_ids' => [$santina->id]]);
        $catalogo->guardarEnvase([
            'especie_validacion_id' => $cereza->id, 'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'Caja granel 5 kg', 'codigo_externo' => 'CJ5KG', 'kilos_netos_por_caja' => 5,
        ]);

        $this->perfil();
        $this->respuesta = [
            $this->fila('P-SANTINA', ['cantidad' => 120]),
            // Pallet con dos variedades: Santina del CSG 100001 y Lapins de un CSG que el catálogo no tiene.
            $this->fila('P-MEZCLA', ['cantidad' => 50, 'cod_calibre' => 'XL']),
            $this->fila('P-MEZCLA', ['cantidad' => 30, 'cod_calibre' => 'XL', 'variedad' => 'LAPINS', 'codigo_variedad' => 'LAP', 'codigo_csg_predio' => '100002', 'descripcion_predio' => 'PREDIO DOS']),
        ];
        $this->artisan('integraciones:leer-stock-origen')->assertSuccessful();

        $respuesta = $this->actingAs($this->administrador, 'sanctum')->getJson('/api/validacion/pendientes-origen')->assertOk()
            ->assertJsonPath('puede_administrar_catalogo', true)
            ->assertJsonPath('resumen.catalogo_pendiente', 7)
            ->assertJsonPath('resumen.pallets_sin_catalogo', 2);
        // «CEREZAS» del origen es la especie Cereza del catálogo; el resto falta o depende del cliente.
        $this->assertSame(
            ['cliente:EMP', 'marca:MARCA PRUEBA', 'variedad:LAPINS', 'calibre:XL', 'envase:CJ5KG', 'categoria:C1', 'csg:100002'],
            collect($respuesta->json('catalogo'))->map(fn (array $item): string => "{$item['dimension']}:{$item['valor']}")->all(),
        );
        $marca = $this->itemCatalogo($respuesta, 'marca', 'MARCA PRUEBA');
        $this->assertSame(['cliente', [], 2, 200], [$marca['requiere'], $marca['acciones'], $marca['pallets_total'], $marca['cajas']]);
        $this->assertSame(['incorporar'], $this->itemCatalogo($respuesta, 'csg', '100002')['acciones']);
        $this->assertSame(['vincular'], $this->itemCatalogo($respuesta, 'categoria', 'C1')['acciones']);
        $this->assertSame(['Cliente', 'Marca', 'Embalaje'], collect($respuesta->json('pendientes'))->firstWhere('numero', 'P-SANTINA')['sin_catalogo']);
        $this->assertNotNull($respuesta->json('opciones_catalogo.marcas.'.$clienteValidacion->id));

        $this->postJson('/api/validacion/pendientes-origen/catalogo', ['clave' => $marca['clave'], 'accion' => 'incorporar'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Primero vincula el cliente EMP.');
        $this->postJson('/api/validacion/pendientes-origen/catalogo', ['clave' => $this->itemCatalogo($respuesta, 'cliente', 'EMP')['clave'], 'accion' => 'vincular'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entidad_id' => 'Elige el elemento del catálogo al que corresponde.']);

        // cliente = cod_empresa: queda como alias del cliente y el embalaje se reconoce por cod_articulo.
        $respuesta = $this->aplicarCatalogo($respuesta, 'cliente', 'EMP', 'vincular', $cliente->id)
            ->assertJsonPath('message', '«EMP» ahora se reconoce como «Exportadora Prueba».');
        $this->assertTrue(AliasCliente::query()->where(['cliente_id' => $cliente->id, 'origen' => 'stock_origen', 'codigo' => 'EMP'])->exists());
        $this->assertSame(
            ['marca:MARCA PRUEBA', 'variedad:LAPINS', 'calibre:XL', 'categoria:C1', 'csg:100002'],
            collect($respuesta->json('catalogo'))->map(fn (array $item): string => "{$item['dimension']}:{$item['valor']}")->all(),
        );
        $this->assertSame(['vincular', 'incorporar'], $this->itemCatalogo($respuesta, 'marca', 'MARCA PRUEBA')['acciones']);

        $respuesta = $this->aplicarCatalogo($respuesta, 'marca', 'MARCA PRUEBA', 'incorporar')
            ->assertJsonPath('message', '«MARCA PRUEBA» se agregó al catálogo de la temporada.');
        // La categoría solo se vincula: C1 del origen corresponde a CAT1 del catálogo.
        $respuesta = $this->aplicarCatalogo($respuesta, 'categoria', 'C1', 'vincular', $categoria->id)
            ->assertJsonPath('message', '«C1» ahora se reconoce como «CAT1».');
        $respuesta = $this->aplicarCatalogo($respuesta, 'variedad', 'LAPINS', 'incorporar');
        $respuesta = $this->aplicarCatalogo($respuesta, 'calibre', 'XL', 'incorporar');
        $respuesta = $this->aplicarCatalogo($respuesta, 'csg', '100002', 'incorporar')
            ->assertJsonPath('catalogo', [])
            ->assertJsonPath('resumen.catalogo_pendiente', 0)
            ->assertJsonPath('resumen.pallets_sin_catalogo', 0);

        $this->assertSame('MARCA PRUEBA', MarcaValidacion::query()->where('cliente_validacion_id', $clienteValidacion->id)->sole()->nombre);
        $lapins = VariedadValidacion::query()->where('especie_validacion_id', $cereza->id)->where('nombre', 'Lapins')->sole();
        $this->assertTrue(CalibreValidacion::query()->where('especie_validacion_id', $cereza->id)->where('nombre', 'XL')->exists());
        $csg = CsgValidacion::query()->where('temporada_id', $temporada->id)->where('codigo', '100002')->with('variedades')->sole();
        $this->assertSame(['PREDIO DOS', [$lapins->id]], [$csg->predio, $csg->variedades->pluck('id')->all()]);
        $this->assertSame(
            ['calibre' => 'incorporado', 'categoria' => 'vinculado', 'cliente' => 'vinculado', 'csg' => 'incorporado', 'marca' => 'incorporado', 'variedad' => 'incorporado'],
            HomologacionStockOrigen::query()->orderBy('dimension')->pluck('accion', 'dimension')->all(),
        );
        $pendiente = collect($respuesta->json('pendientes'))->firstWhere('numero', 'P-SANTINA');
        $this->assertSame(['Exportadora Prueba', 'CAT1', []], [$pendiente['cliente'], $pendiente['categoria_sugerida'], $pendiente['sin_catalogo']]);

        // Validado con otra variedad y otra categoría: la variedad es diferencia; la categoría, sugerencia.
        $this->folio('P-SANTINA', 120, [['csg' => '100001', 'cantidad_cajas' => 120]], [
            'exportadora' => 'Exportadora Prueba', 'marca' => 'MARCA PRUEBA', 'variedad' => 'Lapins', 'calibre' => 'JD',
            'datos_externos' => [
                'cantidad_cajas' => 120, 'especie' => 'Cereza', 'envase' => 'Caja granel 5 kg', 'categoria' => 'CAT2',
                'composicion' => [['csg' => '100001', 'cantidad_cajas' => 120]],
            ],
        ]);
        $respuesta = $this->getJson('/api/validacion/pendientes-origen')->assertOk()->assertJsonPath('resumen.con_diferencias', 1);
        $this->assertSame([
            ['campo' => 'variedad', 'etiqueta' => 'Variedad', 'origen' => 'SANTINA', 'catalogo' => 'Santina', 'folios' => 'Lapins'],
        ], $respuesta->json('diferencias.0.diferencias'));
        $this->assertSame([
            ['campo' => 'categoria', 'etiqueta' => 'Categoría', 'origen' => 'C1', 'catalogo' => 'CAT1', 'folios' => 'CAT2'],
        ], $respuesta->json('diferencias.0.sugerencias'));

        $this->postJson('/api/validacion/pendientes-origen/catalogo', ['clave' => $marca['clave'], 'accion' => 'incorporar'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ese valor ya no está pendiente. Actualiza la lista.');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Validador]), 'sanctum')
            ->postJson('/api/validacion/pendientes-origen/catalogo', ['clave' => $marca['clave'], 'accion' => 'incorporar'])
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

    /** @return array<string, mixed> */
    private function itemCatalogo(TestResponse $respuesta, string $dimension, string $valor): array
    {
        return collect($respuesta->json('catalogo'))->first(fn (array $item): bool => $item['dimension'] === $dimension && $item['valor'] === $valor)
            ?? $this->fail("No está pendiente en el catálogo: {$dimension} {$valor}.");
    }

    private function aplicarCatalogo(TestResponse $respuesta, string $dimension, string $valor, string $accion, ?string $entidad = null): TestResponse
    {
        return $this->postJson('/api/validacion/pendientes-origen/catalogo', [
            'clave' => $this->itemCatalogo($respuesta, $dimension, $valor)['clave'],
            'accion' => $accion,
            'entidad_id' => $entidad,
        ])->assertOk();
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

    /** Una fila con la forma de odbc_stock (los nombres de columna respetan sus mayúsculas). */
    private function fila(string $pallet, array $cambios = []): array
    {
        return [
            'cod_empresa' => 'EMP', 'cod_temporada' => '00', 'temporada' => 'TEMPORADA CAPACITACION',
            'cod_articulo' => 'CJ5KG', 'descripcion_articulo' => 'CAJA GRANEL 5KG', 'peso_neto_articulo' => '5.000',
            'cod_especie' => 'CER', 'descripcion_especie' => 'CEREZAS',
            'codigo_entidad' => '11111111-1P', 'razon_social_entidad' => 'PRODUCTOR DE PRUEBA SPA', 'variedad' => 'SANTINA', 'codigo_variedad' => 'SAN',
            'lote_recepcion' => '11462', 'cod_calibre' => 'JD', 'cod_categoria' => 'C1', 'codigo_csg_predio' => '100001', 'descripcion_predio' => 'PREDIO DE PRUEBA',
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
