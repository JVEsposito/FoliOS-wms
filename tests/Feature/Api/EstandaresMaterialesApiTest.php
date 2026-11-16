<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\OrigenEstandarMaterial;
use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ClienteValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\EstandarMaterial;
use App\Models\ItemMaterial;
use App\Models\LineaEstandarMaterial;
use App\Models\MarcaValidacion;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Materiales\ServicioEstandarMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class EstandaresMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $administrador;

    private string $token;

    private ClienteMaterial $catalogo;

    private Cliente $cliente;

    private EnvaseValidacion $envase;

    private ItemMaterial $tapa;

    private ItemMaterial $pallet;

    private ItemMaterial $etiquetaPlanta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->token = $this->administrador->createToken('oficina-estandares', ['oficina'])->plainTextToken;
        $this->catalogo = ClienteMaterial::query()->with(['cliente', 'temporada'])->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))->firstOrFail();
        $this->cliente = $this->catalogo->cliente;
        $temporadaId = $this->catalogo->temporada->temporada_id;
        $clienteValidacion = ClienteValidacion::create(['temporada_id' => $temporadaId, 'cliente_id' => $this->cliente->id, 'nombre' => 'FCO', 'activo' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $temporadaId, 'nombre' => 'Cereza', 'activo' => true]);
        $this->envase = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $clienteValidacion->id,
            'nombre' => 'FALCO CARTON 5KG POUCH', 'codigo_externo' => 'FC50PM', 'activo' => true]);
        MarcaValidacion::create(['cliente_validacion_id' => $clienteValidacion->id, 'nombre' => 'Falco Fruit', 'activo' => true]);
        $this->tapa = $this->item($this->catalogo, '031026', 'TAPA CAJA BOLSITAS 5 KG');
        $this->pallet = $this->item($this->catalogo, '031000', 'PALLET 1000X1200 TACO');
        $planta = Cliente::create(['codigo' => 'AR', 'nombre' => 'Agrorosario', 'activo' => true]);
        $catalogoPlanta = ClienteMaterial::create(['temporada_material_id' => $this->catalogo->temporada_material_id, 'cliente_id' => $planta->id,
            'codigo' => 'AR', 'nombre' => 'Agrorosario', 'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
        $this->etiquetaPlanta = $this->item($catalogoPlanta, 'AR5048', 'ETIQUETA 100X200 TERMICA');
    }

    public function test_crea_un_estandar_propio_con_insumos_de_otro_dueno_y_rechaza_duplicados(): void
    {
        $estandar = $this->crear()->assertCreated()
            ->assertJsonPath('data.origen', 'propio')
            ->assertJsonPath('data.codigo', 'FC50PM')
            ->assertJsonPath('data.version_vigente', 1)
            ->assertJsonPath('data.versiones.0.lineas.0.codigo_insumo', '031026')
            ->assertJsonPath('data.versiones.0.lineas.1.cantidad_por_caja', 0.0062)
            ->assertJsonPath('data.versiones.0.lineas.2.dueno', 'Agrorosario')
            ->assertJsonPath('data.versiones.0.lineas.2.variacion_valor', 'FALCO FRUIT')
            ->json('data');
        $this->assertDatabaseHas('lineas_estandares_materiales', ['item_material_id' => $this->pallet->id, 'cantidad_por_caja' => '0.006200', 'redondeo' => 'entero']);

        $this->crear()->assertConflict();
        $this->withToken($this->token)->getJson('/api/materiales/estandares?q=FC50')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $estandar['id'])->assertJsonPath('puede_administrar', true);
    }

    public function test_valida_items_de_la_temporada_cantidades_variaciones_y_embalaje_de_otro_cliente(): void
    {
        $otraTemporada = Temporada::create(['codigo' => 'T-OTRA', 'nombre' => 'Otra', 'activa' => false]);
        $temporadaMaterial = $this->catalogo->temporada->replicate()->fill(['temporada_id' => $otraTemporada->id, 'codigo' => 'MAT-OTRA', 'activa' => false]);
        $temporadaMaterial->save();
        $catalogoAntiguo = ClienteMaterial::create(['temporada_material_id' => $temporadaMaterial->id, 'cliente_id' => $this->cliente->id, 'codigo' => 'GENERAL',
            'nombre' => 'General', 'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
        $antiguo = $this->item($catalogoAntiguo, 'VIEJO', 'Ítem de otra temporada');

        $this->crear([['item_material_id' => $antiguo->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero']])
            ->assertUnprocessable()->assertJsonPath('message', 'El insumo 1 no es un ítem activo de Materiales de la temporada '.Temporada::query()->whereKey($this->catalogo->temporada->temporada_id)->value('codigo').'.');
        $this->crear([['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => '0.0000001', 'redondeo' => 'entero']])
            ->assertUnprocessable()->assertJsonPath('message', 'La cantidad por caja del insumo 1 debe ser mayor que cero, con hasta 6 decimales.');
        $this->crear([['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero', 'variacion_tipo' => 'marca']])
            ->assertUnprocessable()->assertJsonPath('message', 'La variación del insumo 1 necesita tipo y valor (hasta 120 caracteres), o ninguno.');
        $this->crear([
            ['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero'],
            ['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => 2, 'redondeo' => 'entero'],
        ])->assertUnprocessable()->assertJsonPath('message', 'El ítem 031026 está repetido. Deja una sola línea por ítem y variación.');

        $ajeno = ClienteValidacion::create(['temporada_id' => $this->envase->especie->temporada_id, 'nombre' => 'OTRO', 'activo' => true,
            'cliente_id' => Cliente::create(['codigo' => 'OT', 'nombre' => 'Otro cliente', 'activo' => true])->id]);
        $envaseAjeno = EnvaseValidacion::create(['especie_validacion_id' => $this->envase->especie_validacion_id, 'cliente_validacion_id' => $ajeno->id, 'nombre' => 'OTRO 5KG', 'activo' => true]);
        $this->crear(null, ['envase_validacion_id' => $envaseAjeno->id])
            ->assertUnprocessable()->assertJsonPath('message', 'El embalaje OTRO 5KG es de otro cliente.');
        $this->assertSame(0, EstandarMaterial::query()->count());
    }

    public function test_versiona_sin_tocar_la_version_anterior_y_respeta_vigencias_y_concurrencia(): void
    {
        $estandar = $this->crear(null, ['vigente_desde' => '2026-11-01'])->assertCreated()->json('data');
        $lineas = [['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero'],
            ['item_material_id' => $this->pallet->id, 'cantidad_por_caja' => '0.0083', 'redondeo' => 'decimal']];

        $this->withToken($this->token)->postJson("/api/materiales/estandares/{$estandar['id']}/versiones", ['version' => 1, 'vigente_desde' => '2026-10-31', 'lineas' => $lineas])
            ->assertUnprocessable()->assertJsonPath('message', 'La nueva versión debe regir desde el 01-11-2026 o después, cuando empieza la versión 1.');
        $this->withToken($this->token)->postJson("/api/materiales/estandares/{$estandar['id']}/versiones", ['version' => 7, 'vigente_desde' => '2026-11-10', 'lineas' => $lineas])
            ->assertConflict();
        $nueva = $this->withToken($this->token)->postJson("/api/materiales/estandares/{$estandar['id']}/versiones", [
            'version' => 1, 'vigente_desde' => '2026-11-10', 'motivo' => 'Pallet de 120 cajas', 'lineas' => $lineas,
        ])->assertCreated()->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.versiones')
            ->assertJsonPath('data.versiones.0.numero', 2)->assertJsonPath('data.versiones.1.lineas.1.cantidad_por_caja', 0.0062)->json('data');
        $this->assertSame($estandar['versiones'][0]['lineas'], $nueva['versiones'][1]['lineas']);

        $servicio = app(ServicioEstandarMaterial::class);
        $modelo = EstandarMaterial::query()->findOrFail($estandar['id']);
        $this->assertSame(1, $servicio->versionVigente($modelo, '2026-11-09')->numero);
        $this->assertSame(2, $servicio->versionVigente($modelo, '2026-11-10')->numero);
        $this->assertNull($servicio->versionVigente($modelo, '2026-10-30'));
    }

    public function test_el_propio_manda_sobre_el_de_planta_y_la_copia_exige_insumos_homologados(): void
    {
        $servicio = app(ServicioEstandarMaterial::class);
        $planta = EstandarMaterial::create([
            'temporada_id' => $this->envase->especie->temporada_id, 'cliente_id' => $this->cliente->id, 'envase_validacion_id' => $this->envase->id,
            'origen' => OrigenEstandarMaterial::Planta, 'codigo' => 'FC50PM', 'descripcion' => 'FALCO CARTON 5KG', 'codigo_articulo' => 'FC50PM',
            'activo' => true, 'version' => 1, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);
        $servicio->crearVersion($planta, OrigenEstandarMaterial::Planta, '2026-11-01', 'Lectura de planta', str_repeat('a', 64), [
            ['item_material_id' => $this->tapa->id, 'codigo_insumo' => '031026', 'descripcion_insumo' => 'TAPA', 'unidad_medida' => 'UN', 'cantidad_por_caja' => '1.000000', 'redondeo' => 'entero', 'variacion_tipo' => null, 'variacion_valor' => null],
            ['item_material_id' => null, 'codigo_insumo' => '031099', 'descripcion_insumo' => 'SIN HOMOLOGAR', 'unidad_medida' => null, 'cantidad_por_caja' => '0.025000', 'redondeo' => 'decimal', 'variacion_tipo' => null, 'variacion_valor' => null],
        ], $this->administrador);

        $this->withToken($this->token)->getJson("/api/materiales/estandares/{$planta->id}")->assertOk()
            ->assertJsonPath('data.insumos_sin_item', 1)->assertJsonPath('data.versiones.0.lineas.1.item_material_id', null);
        $this->withToken($this->token)->postJson("/api/materiales/estandares/{$planta->id}/versiones", ['version' => 1, 'lineas' => [['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero']]])
            ->assertUnprocessable()->assertJsonPath('message', 'Un estándar de planta se actualiza desde planta. Cópialo como propio para ajustarlo en FoliOS.');
        $this->withToken($this->token)->postJson("/api/materiales/estandares/{$planta->id}/copiar", [])
            ->assertUnprocessable()->assertJsonPath('message', 'Homologa primero los insumos de planta sin ítem de Materiales: 031099.');

        $this->assertSame($planta->id, $servicio->aplicable($planta->temporada_id, $this->cliente->id, $this->envase->id, '2026-11-05')['estandar']->id);

        LineaEstandarMaterial::query()->where('codigo_insumo', '031099')->update(['item_material_id' => $this->pallet->id]);
        $propio = $this->withToken($this->token)->postJson("/api/materiales/estandares/{$planta->id}/copiar", ['vigente_desde' => '2026-11-03'])->assertCreated()
            ->assertJsonPath('data.origen', 'propio')->assertJsonPath('data.estandar_base.id', $planta->id)
            ->assertJsonPath('data.versiones.0.lineas.1.codigo_insumo', '031000')->json('data');

        $aplicable = $servicio->aplicable($planta->temporada_id, $this->cliente->id, $this->envase->id, '2026-11-05');
        $this->assertSame($propio['id'], $aplicable['estandar']->id);
        $this->assertSame($planta->id, $aplicable['referencia']->id);
        // Antes de que rija el propio sigue mandando el de planta.
        $this->assertSame($planta->id, $servicio->aplicable($planta->temporada_id, $this->cliente->id, $this->envase->id, '2026-11-02')['estandar']->id);

        $this->withToken($this->token)->putJson("/api/materiales/estandares/{$propio['id']}/estado", ['activo' => false, 'version' => 1])
            ->assertOk()->assertJsonPath('data.activo', false);
        $this->assertSame($planta->id, $servicio->aplicable($planta->temporada_id, $this->cliente->id, $this->envase->id, '2026-11-05')['estandar']->id);
    }

    public function test_una_variacion_reemplaza_la_linea_general_del_mismo_item_segun_precedencia(): void
    {
        $estandar = $this->crear([
            ['item_material_id' => $this->etiquetaPlanta->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero'],
            ['item_material_id' => $this->etiquetaPlanta->id, 'cantidad_por_caja' => 2, 'redondeo' => 'entero', 'variacion_tipo' => 'calibre', 'variacion_valor' => 'xl'],
            ['item_material_id' => $this->etiquetaPlanta->id, 'cantidad_por_caja' => 3, 'redondeo' => 'entero', 'variacion_tipo' => 'marca', 'variacion_valor' => 'Falco  Fruit'],
            ['item_material_id' => $this->pallet->id, 'cantidad_por_caja' => '0.0062', 'redondeo' => 'decimal', 'variacion_tipo' => 'categoria', 'variacion_valor' => 'CAT1'],
        ])->assertCreated()->json('data');
        $version = EstandarMaterial::query()->findOrFail($estandar['id'])->versiones()->firstOrFail();
        $lineas = fn (array $caja) => ServicioEstandarMaterial::lineasParaCaja($version->lineas, $caja)
            ->map(fn (LineaEstandarMaterial $linea): string => $linea->codigo_insumo.'='.(float) $linea->cantidad_por_caja)->all();

        $this->assertSame(['AR5048=1'], $lineas(['marca' => 'OTRA', 'calibre' => 'L', 'categoria' => 'CAT2']));
        $this->assertSame(['AR5048=2', '031000=0.0062'], $lineas(['marca' => 'OTRA', 'calibre' => 'XL', 'categoria' => 'cat1']));
        $this->assertSame(['AR5048=3'], $lineas(['marca' => 'falco fruit', 'calibre' => 'XL', 'categoria' => null]));
    }

    public function test_opciones_permisos_y_excel(): void
    {
        $this->crear()->assertCreated();
        $this->withToken($this->token)->getJson('/api/materiales/estandares/opciones?cliente_id='.$this->cliente->id)->assertOk()
            ->assertJsonPath('envases.0.codigo_externo', 'FC50PM')->assertJsonPath('valores.marca.0', 'FALCO FRUIT');
        $this->withToken($this->token)->getJson('/api/materiales/estandares/items?q=AR50&cliente_id='.$this->cliente->id)->assertOk()
            ->assertJsonPath('data.0.codigo', 'AR5048')->assertJsonPath('data.0.del_cliente', false)->assertJsonPath('data.0.dueno', 'Agrorosario');

        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta, 'activo' => true]);
        $tokenConsulta = $consulta->createToken('oficina-consulta', ['oficina'])->plainTextToken;
        $this->withToken($tokenConsulta)->getJson('/api/materiales/estandares')->assertOk()->assertJsonPath('puede_administrar', false);
        $this->withToken($tokenConsulta)->postJson('/api/materiales/estandares', [])->assertForbidden();
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroMateriales, 'activo' => true]);
        $this->withToken($camarero->createToken('oficina-camarero', ['oficina'])->plainTextToken)->getJson('/api/materiales/estandares')->assertForbidden();

        $respuesta = $this->withToken($this->token)->get('/api/materiales/estandares/excel')->assertOk();
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $compartidas = (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringContainsString('<v>0.0062</v>', $hoja);
        $this->assertStringContainsString('Cantidad por caja', $hoja.$compartidas);
        $this->assertStringContainsString('FALCO FRUIT', $hoja.$compartidas);
    }

    private function crear(?array $lineas = null, array $extra = [])
    {
        return $this->withToken($this->token)->postJson('/api/materiales/estandares', [
            'cliente_id' => $this->cliente->id,
            'envase_validacion_id' => $this->envase->id,
            'vigente_desde' => '2026-11-01',
            'lineas' => $lineas ?? [
                ['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero'],
                ['item_material_id' => $this->pallet->id, 'cantidad_por_caja' => '0.0062', 'redondeo' => 'entero'],
                ['item_material_id' => $this->etiquetaPlanta->id, 'cantidad_por_caja' => 1, 'redondeo' => 'entero', 'variacion_tipo' => 'marca', 'variacion_valor' => ' falco fruit '],
            ],
            ...$extra,
        ]);
    }

    private function item(ClienteMaterial $catalogo, string $codigo, string $nombre): ItemMaterial
    {
        return ItemMaterial::create([
            'cliente_material_id' => $catalogo->id, 'codigo' => $codigo, 'nombre' => $nombre, 'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo, 'unidad_medida' => 'unidades', 'origen_sistema' => 'manual',
            'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);
    }
}
