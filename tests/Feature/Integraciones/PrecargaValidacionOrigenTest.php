<?php

namespace Tests\Feature\Integraciones;

use App\Enums\RolUsuario;
use App\Models\ArticuloValidacion;
use App\Models\ClienteValidacion;
use App\Models\CombinacionValidacion;
use App\Models\ConexionExterna;
use App\Models\CsgValidacion;
use App\Models\Dispositivo;
use App\Models\MarcaValidacion;
use App\Models\OrigenValidacion;
use App\Models\PalletStockOrigen;
use App\Models\Temporada;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Clientes\ServicioCliente;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Validacion\ServicioCatalogoJerarquicoValidacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La PDA parte la validación con lo que la planta informa del pallet: el validador confirma o
 * corrige solo lo que no coincide. Queda constancia de la copia mostrada y de lo corregido.
 */
class PrecargaValidacionOrigenTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    public array $respuesta = [];

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 12:00:00', 'America/Santiago'));
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $prueba = $this;
        $this->app->instance(ConectorMySqlLectura::class, new class($prueba) extends ConectorMySqlLectura
        {
            public function __construct(private readonly PrecargaValidacionOrigenTest $prueba) {}

            public function consultar(array $credenciales, string $sql, array $parametros = [], int $segundos = 60): array
            {
                return $this->prueba->respuesta;
            }
        });
    }

    public function test_la_pda_recibe_los_datos_de_planta_traducidos_al_catalogo(): void
    {
        $catalogo = $this->catalogo();
        $this->leer([
            // Dos filas del mismo CSG, lote y proceso se suman en una línea, como en la PDA.
            $this->fila('P-PRECARGA', ['cantidad' => 70, 'codigo_csg_predio' => '100001', 'lote_recepcion' => '11462', 'Proceso' => 'AR01STD10000']),
            $this->fila('P-PRECARGA', ['cantidad' => 30, 'codigo_csg_predio' => '100001', 'lote_recepcion' => '11462', 'Proceso' => 'AR01STD10000', 'codigo_entidad' => '22222222-2P']),
            $this->fila('P-PRECARGA', ['cantidad' => 20, 'codigo_csg_predio' => '100002', 'lote_recepcion' => '11463', 'Proceso' => 'ar01std10001']),
            // Un CSG que el catálogo no tiene: la línea llega sin origen y queda para completar.
            $this->fila('P-PRECARGA', ['cantidad' => 10, 'codigo_csg_predio' => '999999', 'lote_recepcion' => '11464', 'Proceso' => 'AR01STD10002']),
        ]);
        [, $token] = $this->validador('PDA-PRECARGA-1');

        $datos = $this->conToken($token)->getJson('/api/validacion/precarga-origen?numero_folio=p-precarga')
            ->assertOk()->json('data');

        $this->assertTrue($datos['disponible']);
        $this->assertSame(PalletStockOrigen::query()->where('numero_pallet', 'P-PRECARGA')->value('huella'), $datos['huella']);
        $this->assertSame(['pallet', 130, '2026-10-07', false], [$datos['tipo_bulto'], $datos['cantidad_cajas'], $datos['fecha_embalaje'], $datos['desactualizada']]);
        $this->assertSame($catalogo['articulo']->id, $datos['articulo_validacion_id']);
        $this->assertSame($catalogo['categoria'], $datos['categoria_validacion_id']);
        $this->assertSame([
            ['csg' => '100001', 'predio' => 'PREDIO DE PRUEBA', 'origen_validacion_id' => $catalogo['origenes']['100001'], 'cantidad_cajas' => 100, 'lote_materia_prima' => '11462', 'proceso_packing' => 'AR01STD10000'],
            ['csg' => '100002', 'predio' => 'PREDIO DE PRUEBA', 'origen_validacion_id' => $catalogo['origenes']['100002'], 'cantidad_cajas' => 20, 'lote_materia_prima' => '11463', 'proceso_packing' => 'AR01STD10001'],
            ['csg' => '999999', 'predio' => 'PREDIO DE PRUEBA', 'origen_validacion_id' => null, 'cantidad_cajas' => 10, 'lote_materia_prima' => '11464', 'proceso_packing' => 'AR01STD10002'],
        ], $datos['composicion']);
        $this->assertSame(['CSG sin equivalencia en el catálogo de validación.'], $datos['pendientes']);
        $this->assertSame(['Exportadora Prueba', 'MARCA PRUEBA', 'SANTINA', 'JD'], [$datos['origen']['cliente'], $datos['origen']['marca'], $datos['origen']['variedad'], $datos['origen']['calibre']]);

        $this->conToken($token)->getJson('/api/validacion/precarga-origen?numero_folio=OTRO')
            ->assertOk()
            ->assertJsonPath('data', ['disponible' => false, 'motivo' => 'El sistema de planta no tiene este pallet en bodega.']);
        // La precarga es parte de validar: quien no valida pallets no la consulta.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum')
            ->getJson('/api/validacion/precarga-origen?numero_folio=P-PRECARGA')
            ->assertForbidden();
    }

    public function test_la_validacion_deja_constancia_de_la_copia_mostrada_y_de_lo_corregido(): void
    {
        $catalogo = $this->catalogo();
        $this->leer([
            $this->fila('P-CORRIGE', ['cantidad' => 100, 'codigo_csg_predio' => '100001']),
            $this->fila('P-CORRIGE', ['cantidad' => 20, 'codigo_csg_predio' => '100002', 'lote_recepcion' => '11463']),
        ]);
        $huella = PalletStockOrigen::query()->where('numero_pallet', 'P-CORRIGE')->value('huella');
        [, $token] = $this->validador('PDA-PRECARGA-2');
        // El validador contó 118 cajas en el CSG 100001: corrige cajas y composición.
        $payload = [
            'operacion_id' => (string) Str::uuid(), 'numero_folio' => 'P-CORRIGE', 'tipo_bulto' => 'pallet', 'cantidad_cajas' => 118,
            'linea_proceso' => 1, 'turno' => 'A', 'temporada_id' => $catalogo['temporada']->id, 'catalogo_version' => $catalogo['temporada']->refresh()->version_catalogo,
            'articulo_validacion_id' => $catalogo['articulo']->id, 'origen_validacion_id' => $catalogo['origenes']['100001'], 'fecha_embalaje' => '2026-10-07',
            'composicion' => [
                ['origen_validacion_id' => $catalogo['origenes']['100001'], 'cantidad_cajas' => 98, 'lote_materia_prima' => '11462', 'proceso_packing' => 'AR01STD10000'],
                ['origen_validacion_id' => $catalogo['origenes']['100002'], 'cantidad_cajas' => 20, 'lote_materia_prima' => '11463', 'proceso_packing' => 'AR01STD10000'],
            ],
            'categoria_validacion_id' => $catalogo['categoria'], 'resultado' => 'aprobado', 'generado_dispositivo_at' => now()->toAtomString(),
            'precarga_origen' => ['huella' => $huella, 'modificados' => ['composicion', 'cantidad_cajas', 'composicion']],
        ];

        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.precarga_origen', ['huella' => $huella, 'modificados' => ['cantidad_cajas', 'composicion'], 'coincide_con_copia_vigente' => true]);
        // El mismo envío repetido es idempotente: la precarga es parte del contenido.
        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)->assertOk();
        $this->assertSame(1, ValidacionPallet::query()->where('numero_folio', 'P-CORRIGE')->count());

        $this->conToken($token)->postJson('/api/validacion/pallets', [...$payload, 'operacion_id' => (string) Str::uuid(), 'precarga_origen' => ['huella' => 'corta', 'modificados' => ['otra_cosa']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['precarga_origen.huella', 'precarga_origen.modificados.0']);
    }

    /** @return array{temporada: Temporada, articulo: ArticuloValidacion, categoria: string, origenes: array<string, string>} */
    private function catalogo(): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = app(ServicioCliente::class)->guardarMaestro(['codigo' => 'EMP', 'nombre' => 'Exportadora Prueba'], $this->administrador);
        $clienteValidacion = ClienteValidacion::query()->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)->sole();
        $servicio = app(ServicioCatalogoJerarquicoValidacion::class);
        $marca = $servicio->guardarMarca(['cliente_validacion_id' => $clienteValidacion->id, 'nombre' => 'MARCA PRUEBA']);
        $cereza = $servicio->guardarEspecie(['temporada_id' => $temporada->id, 'nombre' => 'Cereza', 'codigo_externo' => 'CER']);
        $santina = $servicio->guardarVariedad(['especie_validacion_id' => $cereza->id, 'nombre' => 'Santina']);
        $servicio->guardarCalibre(['especie_validacion_id' => $cereza->id, 'nombre' => 'JD']);
        $categoria = $servicio->guardarCategoria(['temporada_id' => $temporada->id, 'nombre' => 'CAT1', 'codigo_externo' => 'C1']);
        $servicio->guardarEnvase([
            'especie_validacion_id' => $cereza->id, 'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'Caja granel 5 kg', 'codigo_externo' => 'CJ5KG', 'kilos_netos_por_caja' => 5,
        ]);
        $origenes = [];
        foreach (['100001', '100002'] as $codigo) {
            $csg = $servicio->guardarCsg(['temporada_id' => $temporada->id, 'codigo' => $codigo, 'predio' => 'PREDIO DE PRUEBA', 'variedad_ids' => [$santina->id]]);
            // Un CSG sin productor queda disponible para el cliente: el catálogo crea su origen.
            $origenes[$codigo] = OrigenValidacion::query()->firstOrCreate(
                ['temporada_id' => $temporada->id, 'cliente' => $clienteValidacion->nombre, 'marca' => $marca->nombre, 'csg' => $codigo],
                ['cliente_validacion_id' => $clienteValidacion->id, 'marca_validacion_id' => $marca->id, 'csg_validacion_id' => $csg->id, 'predio' => 'PREDIO DE PRUEBA', 'activo' => true],
            )->id;
        }
        $articulo = ArticuloValidacion::query()->where('temporada_id', $temporada->id)->where('variedad_validacion_id', $santina->id)
            ->where('cliente_validacion_id', $clienteValidacion->id)->where('activo', true)->sole();
        foreach ($origenes as $origen) {
            CombinacionValidacion::query()->firstOrCreate(
                ['temporada_id' => $temporada->id, 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $origen],
                ['activo' => true],
            );
        }
        $this->assertNotNull(CsgValidacion::query()->where('codigo', '100001')->first());
        $this->assertSame(1, MarcaValidacion::query()->count());

        return ['temporada' => $temporada, 'articulo' => $articulo, 'categoria' => $categoria->id, 'origenes' => $origenes];
    }

    /** @param  list<array<string, mixed>>  $filas */
    private function leer(array $filas): void
    {
        ConexionExterna::query()->create([
            'nombre' => 'SE',
            'credenciales' => ['host' => '192.0.2.10', 'puerto' => 3306, 'usuario' => 'lector', 'password' => 'clave-prueba', 'base_datos' => 'origen'],
            'activa' => true, 'version' => 1,
            'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);
        $this->respuesta = $filas;
        $this->artisan('integraciones:leer-stock-origen --sin-cola')->assertSuccessful();
    }

    /** @return array{User, string} */
    private function validador(string $codigo): array
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Validador]);
        $dispositivo = Dispositivo::create(['codigo' => $codigo, 'nombre' => "PDA {$codigo}", 'plataforma' => 'android', 'activo' => true]);

        return [$usuario, $usuario->crearTokenParaDispositivo($dispositivo, "test-{$codigo}")->plainTextToken];
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** Una fila con la forma de odbc_stock. */
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
}
