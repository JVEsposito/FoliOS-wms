<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ClienteValidacion;
use App\Models\ConexionExterna;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\EstandarMaterial;
use App\Models\EstandarOrigen;
use App\Models\ItemMaterial;
use App\Models\OperacionIntegracion;
use App\Models\User;
use App\Services\Integraciones\ConectorMySqlLectura;
use App\Services\Integraciones\ServicioLecturaEstandaresOrigen;
use App\Services\Integraciones\ServicioOperacionesIntegracion;
use App\Services\Materiales\ServicioEstandarMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use Tests\TestCase;

class EstandaresPlantaApiTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/materiales/estandares/planta';

    private User $administrador;

    private ConexionExterna $conexion;

    private ClienteMaterial $catalogo;

    private Cliente $cliente;

    private EnvaseValidacion $envase;

    private ItemMaterial $tapa;

    private ItemMaterial $pallet;

    private array $filas = [];

    private ?\Throwable $falla = null;

    private array $consultas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($this->administrador, 'sanctum');
        $this->conexion = ConexionExterna::create(['nombre' => 'Planta', 'activa' => true, 'version' => 1, 'credenciales' => ['password' => 'clave_fixture']]);
        $this->mock(ConectorMySqlLectura::class)->shouldReceive('consultar')->andReturnUsing(function ($credenciales, $sql, $parametros = []) {
            $this->consultas[] = [$sql, $parametros];
            if ($this->falla) {
                throw $this->falla;
            }

            return $this->filas;
        });

        $general = ClienteMaterial::query()->with('temporada')->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))->firstOrFail();
        $temporadaId = $general->temporada->temporada_id;
        $this->cliente = Cliente::create(['codigo' => 'FCO', 'nombre' => 'Falco Fruit', 'activo' => true]);
        $this->catalogo = $this->catalogo($this->cliente, 'FCO');
        $clienteValidacion = ClienteValidacion::create(['temporada_id' => $temporadaId, 'cliente_id' => $this->cliente->id, 'nombre' => 'Falco Fruit', 'activo' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $temporadaId, 'nombre' => 'Cereza', 'codigo_externo' => 'CE', 'activo' => true]);
        $this->envase = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'FALCO CARTON 5KG POUCH', 'codigo_externo' => 'FC50PM', 'activo' => true]);
        $this->tapa = $this->item($this->catalogo, '031026', 'TAPA CAJA BOLSITAS 5 KG');
        $this->pallet = $this->item($this->catalogo, 'PAL-5G', 'PALLET 1000X1200 TACO', '031000');
        // La misma etiqueta existe en el catálogo de dos dueños: queda pendiente de elegir.
        $this->item($general, 'AR5048', 'ETIQUETA 100X200 GENERAL');
        $this->item($this->catalogo(Cliente::create(['codigo' => 'AR', 'nombre' => 'Agrorosario', 'activo' => true]), 'AR'), 'AR5048', 'ETIQUETA 100X200 PLANTA');
        $this->filas = [
            $this->fila(['cod_insumo' => '031026', 'cantidad' => '1']),
            $this->fila(['cod_insumo' => '031000', 'descripcion_insumo' => 'PALLET', 'tipo_rebaja_insumo' => 'Decimal', 'cantidad' => '0.006200000178813934']),
            $this->fila(['cod_insumo' => 'AR5048', 'descripcion_insumo' => 'ETIQUETA', 'cantidad' => '1']),
            $this->fila(['cod_empresa' => 'OLM', 'cod_articulo' => 'CC50GM', 'descripcion_articulo' => 'CAJA CARTON GRANEL 5.0 KG', 'codigo_estandar' => 'CC50GM', 'cod_insumo' => 'EMB2001106', 'cantidad' => '1']),
        ];
    }

    public function test_configura_lee_en_cola_vincula_insumos_e_incorpora_versiones_de_planta(): void
    {
        $this->configurar()->assertOk()->assertJsonPath('version', 1);
        $this->getJson(self::RUTA)->assertOk()->assertJsonPath('configuracion.tabla', 'odbc_estandar_materiales')
            ->assertJsonPath('configuracion.conexion.nombre', 'Planta')->assertJsonMissingPath('configuracion.conexion.credenciales');

        $this->leer();
        [$sql, $parametros] = $this->consultas[0];
        $this->assertStringContainsString('FROM `odbc_estandar_materiales` WHERE `cod_temporada` = ?', $sql);
        $this->assertStringNotContainsString('creador', $sql);
        $this->assertSame(['T2627'], $parametros);

        $estado = $this->getJson(self::RUTA)->assertOk()
            ->assertJsonPath('ultima_lectura.estado', 'correcta')->assertJsonPath('ultima_lectura.estandares', 2)
            ->assertJsonPath('resumen.nuevo', 1)->assertJsonPath('resumen.falta_cliente', 1)->json();
        $fco = collect($estado['estandares'])->firstWhere('cod_empresa', 'FCO');
        $this->assertSame([$this->tapa->id, $this->pallet->id, null], array_column($fco['lineas'], 'item_material_id'));
        $this->assertCount(2, $fco['lineas'][2]['candidatos']);
        $this->assertSame('AR5048', $estado['insumos_pendientes'][0]['cod_insumo']);

        $this->postJson(self::RUTA.'/incorporar')->assertOk()->assertJsonPath('resumen.creados', 1)->assertJsonPath('resumen.omitidos', 1);
        $estandar = EstandarMaterial::query()->where('origen', 'planta')->firstOrFail();
        $version = $estandar->versiones()->with('lineas')->firstOrFail();
        $this->assertSame('2026-11-06', $version->vigente_desde->toDateString());
        $this->assertSame(['031026', '031000', 'AR5048'], $version->lineas->pluck('codigo_insumo')->all());
        $this->assertNull($version->lineas[2]->item_material_id);
        $this->assertSame('0.006200', (string) $version->lineas[1]->cantidad_por_caja);

        $etiqueta = collect($fco['lineas'][2]['candidatos'])->firstWhere('dueno', 'Agrorosario');
        $this->postJson(self::RUTA.'/vinculos/insumos', ['cod_empresa' => 'FCO', 'cod_insumo' => 'AR5048', 'item_material_id' => $etiqueta['id']])->assertOk();
        $this->getJson(self::RUTA)->assertJsonPath('resumen.cambio', 1)->assertJsonCount(0, 'insumos_pendientes');
        $this->postJson(self::RUTA.'/incorporar')->assertOk()->assertJsonPath('resumen.actualizados', 1);
        $this->postJson(self::RUTA.'/incorporar')->assertOk()->assertJsonPath('resumen.al_dia', 1);
        $this->assertSame(2, $estandar->versiones()->count());
        $this->assertSame($etiqueta['id'], $estandar->versiones()->where('numero', 2)->firstOrFail()->lineas()->where('codigo_insumo', 'AR5048')->value('item_material_id'));

        // Un cambio en planta con edición anterior a la última versión rige desde esa versión.
        $this->filas[1]['cantidad'] = '0.0083';
        $this->filas = array_map(fn (array $fila): array => [...$fila, 'fecha_edicion' => '2026-11-01 10:00:00'], $this->filas);
        $this->leer();
        $this->postJson(self::RUTA.'/incorporar')->assertOk()->assertJsonPath('resumen.actualizados', 1);
        $tercera = $estandar->versiones()->where('numero', 3)->firstOrFail();
        $this->assertSame('2026-11-06', $tercera->vigente_desde->toDateString());
        $aplicable = app(ServicioEstandarMaterial::class)->aplicable($estandar->temporada_id, $this->cliente->id, $this->envase->id, '2026-11-07');
        $this->assertSame(3, $aplicable['version']->numero);
    }

    public function test_vincula_cliente_y_embalaje_que_planta_trae_con_otros_codigos(): void
    {
        $this->configurar();
        $this->leer();
        $olm = Cliente::create(['codigo' => 'LOSOLMOS', 'nombre' => 'Los Olmos', 'activo' => true]);
        $this->postJson(self::RUTA.'/vinculos/clientes', ['cod_empresa' => 'OLM', 'cliente_id' => $olm->id])->assertOk();
        $estado = $this->getJson(self::RUTA)->assertOk()->json();
        $origen = collect($estado['estandares'])->firstWhere('cod_empresa', 'OLM');
        $this->assertSame('falta_embalaje', $origen['estado']);

        $clienteValidacion = ClienteValidacion::query()->where('cliente_id', $olm->id)->firstOrFail();
        $envase = EnvaseValidacion::create(['especie_validacion_id' => $this->envase->especie_validacion_id, 'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'CARTON GRANEL 5 KG LOS OLMOS', 'activo' => true]);
        $this->postJson(self::RUTA.'/vinculos/embalajes', ['estandar_origen_id' => $origen['id'], 'envase_validacion_id' => $this->envase->id])
            ->assertUnprocessable()->assertJsonPath('message', 'Elige un embalaje PT de ese cliente.');
        $this->postJson(self::RUTA.'/vinculos/embalajes', ['estandar_origen_id' => $origen['id'], 'envase_validacion_id' => $envase->id])->assertOk();
        $this->assertSame('nuevo', collect($this->getJson(self::RUTA)->json('estandares'))->firstWhere('cod_empresa', 'OLM')['estado']);
    }

    public function test_una_lectura_fallida_conserva_la_copia_y_los_permisos_separan_configurar_de_leer(): void
    {
        $this->configurar();
        $this->leer();
        $this->falla = new PDOException('Table missing');
        $this->falla->errorInfo = ['42S02', 1146, 'Table missing'];
        $lectura = app(ServicioLecturaEstandaresOrigen::class)->leer($this->administrador);
        $this->assertSame('fallida', $lectura->estado);
        $this->assertSame(2, EstandarOrigen::query()->count());
        $this->getJson(self::RUTA)->assertJsonPath('ultima_lectura.estado', 'fallida')->assertJsonCount(2, 'estandares');

        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorMateriales, 'activo' => true]);
        $this->actingAs($supervisor, 'sanctum');
        $this->configurar(1)->assertForbidden();
        $this->getJson(self::RUTA)->assertOk()->assertJsonPath('puede_configurar', false)->assertJsonCount(0, 'conexiones');
        $this->postJson(self::RUTA.'/incorporar')->assertOk();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta, 'activo' => true]), 'sanctum');
        $this->getJson(self::RUTA)->assertOk()->assertJsonPath('puede_administrar', false);
        $this->postJson(self::RUTA.'/lecturas')->assertForbidden();
    }

    private function configurar(int $version = 0)
    {
        return $this->putJson(self::RUTA.'/configuracion', [
            'conexion_id' => $this->conexion->id, 'tabla' => 'odbc_estandar_materiales', 'temporada_origen' => 't2627',
            'empresas' => [], 'habilitada' => true, 'version_conocida' => $version,
        ]);
    }

    private function leer(): void
    {
        $id = $this->postJson(self::RUTA.'/lecturas')->assertAccepted()->json('operacion.id');
        app(ServicioOperacionesIntegracion::class)->ejecutar($id);
        $this->assertSame('correcta', OperacionIntegracion::query()->findOrFail($id)->estado);
    }

    private function fila(array $cambios): array
    {
        return [
            'cod_empresa' => 'FCO', 'cod_temporada' => 'T2627', 'codigo_estandar' => 'FC50PM', 'descripcion_estandar' => 'FALCO CARTON 5KG POUCH',
            'cod_articulo' => 'FC50PM', 'descripcion_articulo' => 'FALCO CARTON 5KG POUCH', 'cod_insumo' => '031026', 'descripcion_insumo' => 'TAPA',
            'tipo_rebaja_insumo' => 'Entero', 'cantidad' => '1', 'cod_especie' => 'CE', 'desc_especie' => 'CEREZAS',
            'fecha_creacion' => '2026-11-06 09:59:57', 'fecha_edicion' => null, ...$cambios,
        ];
    }

    private function catalogo(Cliente $cliente, string $codigo): ClienteMaterial
    {
        $general = ClienteMaterial::query()->where('codigo', 'GENERAL')->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))->firstOrFail();

        return ClienteMaterial::create(['temporada_material_id' => $general->temporada_material_id, 'cliente_id' => $cliente->id, 'codigo' => $codigo,
            'nombre' => $cliente->nombre, 'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
    }

    private function item(ClienteMaterial $catalogo, string $codigo, string $nombre, ?string $externo = null): ItemMaterial
    {
        return ItemMaterial::create([
            'cliente_material_id' => $catalogo->id, 'codigo' => $codigo, 'nombre' => $nombre, 'categoria' => 'Embalaje', 'codigo_externo' => $externo,
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo, 'unidad_medida' => 'unidades', 'origen_sistema' => 'manual',
            'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);
    }
}
