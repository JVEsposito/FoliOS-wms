<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoAlmacenMaterial;
use App\Models\AlmacenMaterial;
use App\Models\ArticuloValidacion;
use App\Models\CategoriaValidacion;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\ClienteValidacion;
use App\Models\Dispositivo;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MarcaValidacion;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\OrigenValidacion;
use App\Models\SaldoMaterialAlmacen;
use App\Models\SaldoRebajaEstandar;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Services\Materiales\ServicioEstandarMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RebajaEstandarApiTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/materiales/rebajas-estandar';

    private User $administrador;

    private string $temporadaId;

    private AlmacenMaterial $packing;

    private ItemMaterial $tapa;

    private ItemMaterial $pallet;

    private ItemMaterial $etiqueta;

    private ClienteValidacion $clienteValidacion;

    private EnvaseValidacion $envase;

    private EnvaseValidacion $envaseSinEstandar;

    private Dispositivo $dispositivo;

    private int $folios = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($this->administrador, 'sanctum');
        $general = ClienteMaterial::query()->with('temporada')->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))->firstOrFail();
        $this->temporadaId = $general->temporada->temporada_id;
        $cliente = Cliente::create(['codigo' => 'FCO', 'nombre' => 'Falco Fruit', 'activo' => true]);
        $catalogo = ClienteMaterial::create(['temporada_material_id' => $general->temporada_material_id, 'cliente_id' => $cliente->id, 'codigo' => 'FCO',
            'nombre' => 'Falco Fruit', 'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
        $this->tapa = $this->item($catalogo, '031026', 'TAPA CAJA 5 KG');
        $this->pallet = $this->item($catalogo, '031000', 'PALLET 1000X1200');
        $this->etiqueta = $this->item($general, 'AR5048', 'ETIQUETA 100X200');
        $this->clienteValidacion = ClienteValidacion::create(['temporada_id' => $this->temporadaId, 'cliente_id' => $cliente->id, 'nombre' => 'Falco Fruit', 'activo' => true]);
        $especie = EspecieValidacion::create(['temporada_id' => $this->temporadaId, 'nombre' => 'Cereza', 'activo' => true]);
        $this->envase = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $this->clienteValidacion->id, 'nombre' => 'FALCO 5KG', 'codigo_externo' => 'FC50PM', 'activo' => true]);
        $this->envaseSinEstandar = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $this->clienteValidacion->id, 'nombre' => 'FALCO 2,5KG', 'activo' => true]);
        app(ServicioEstandarMaterial::class)->crear([
            'cliente_id' => $cliente->id, 'envase_validacion_id' => $this->envase->id, 'vigente_desde' => '2026-11-01',
            'lineas' => [
                ['item_material_id' => $this->tapa->id, 'cantidad_por_caja' => '1', 'redondeo' => 'entero'],
                ['item_material_id' => $this->pallet->id, 'cantidad_por_caja' => '0.0062', 'redondeo' => 'entero'],
                ['item_material_id' => $this->etiqueta->id, 'cantidad_por_caja' => '1', 'redondeo' => 'entero', 'variacion_tipo' => 'marca', 'variacion_valor' => 'Falco Fruit'],
            ],
        ], $this->administrador);
        $this->packing = AlmacenMaterial::create(['codigo' => 'PACK-01', 'nombre' => 'Packing línea 1', 'tipo' => TipoAlmacenMaterial::Virtual,
            'centro_costo' => 'PACK-01', 'requiere_ubicacion_fisica' => false, 'origen_sistema' => 'manual', 'activo' => true,
            'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id]);
        $this->dispositivo = Dispositivo::create(['codigo' => 'PDA-REB', 'nombre' => 'PDA rebaja', 'activo' => true]);

        $this->saldoPacking($this->tapa, 100, 'FMT0000001', now()->subDays(3));
        $this->saldoPacking($this->tapa, 50, 'FMT0000002', now()->subDay());
        $this->saldoPacking($this->pallet, 1, 'FMT0000003', now()->subDay());

        $this->validar('2026-11-10', 'Falco Fruit', 'AR01STD20016', [98]);
        $this->validar('2026-11-10', 'Falco Fruit', 'ar01std20016', [40, 20]);
        $this->validar('2026-11-11', 'Otra Marca', 'AR01STD20016', [30]);
        $this->validar('2026-11-11', 'Falco Fruit', 'AR01STD20016', [12], $this->envaseSinEstandar);
        $this->validar('2026-11-12', 'Falco Fruit', 'AR01STD20016', [99]);
    }

    public function test_agrupa_lo_validado_descuenta_fifo_arrastra_fraccion_y_deja_pendiente_lo_que_falta(): void
    {
        $vista = $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->assertOk()->json();
        $this->assertSame(3, $vista['totales']['grupos']);
        $this->assertSame(188, $vista['totales']['cajas_seleccionadas']);
        $this->assertSame(12, $vista['totales']['cajas_sin_estandar']);
        $marca = collect($vista['grupos'])->first(fn ($grupo) => $grupo['marca'] === 'FALCO FRUIT' && $grupo['fecha'] === '2026-11-10');
        $this->assertSame([158, 2, 'AR01STD20016', 'propio'], [$marca['cajas'], $marca['pallets'], $marca['proceso'], $marca['estandar']['origen']]);
        $this->assertSame('sin_estandar', collect($vista['grupos'])->firstWhere('envase.id', $this->envaseSinEstandar->id)['problema']);
        $materiales = collect($vista['materiales'])->keyBy('codigo');
        $this->assertSame([188.0, 188, 150, 38], [(float) $materiales['031026']['teorico'], (int) $materiales['031026']['a_descontar'], (int) $materiales['031026']['a_descontar_ahora'], (int) $materiales['031026']['faltante']]);
        $this->assertEqualsWithDelta(1.1656, $materiales['031000']['teorico'], 0.000001);
        $this->assertSame([1, 0], [(int) $materiales['031000']['a_descontar_ahora'], (int) $materiales['031000']['faltante']]);
        $this->assertSame([158, 0, 158], [(int) $materiales['AR5048']['a_descontar'], (int) $materiales['AR5048']['a_descontar_ahora'], (int) $materiales['AR5048']['faltante']]);

        $listos = collect($vista['grupos'])->whereNull('problema')->map(fn ($grupo) => ['clave' => $grupo['clave'], 'cajas' => $grupo['cajas']])->values()->all();
        $operacion = (string) Str::uuid();
        $rebaja = $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => $operacion, 'grupos' => $listos])->assertCreated()
            ->assertJsonPath('data.codigo', 'REB-000001')->assertJsonPath('data.cajas', 188)->assertJsonPath('data.pallets', 3)
            ->assertJsonPath('data.resumen.con_faltante', 2)->json('data');
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => $operacion, 'grupos' => $listos])->assertOk()->assertJsonPath('data.id', $rebaja['id']);
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => $operacion, 'grupos' => array_slice($listos, 0, 1)])->assertConflict();

        $consumos = MovimientoAlmacenMaterial::query()->where('documento_relacionado', 'REB-000001')->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame([1.0, 100.0, 50.0], $consumos->map(fn ($movimiento) => (float) $movimiento->cantidad)->all());
        $this->assertSame(0.0, $this->saldo('FMT0000001') + $this->saldo('FMT0000002'));
        $saldos = SaldoRebajaEstandar::query()->where('almacen_material_id', $this->packing->id)->get()->keyBy('item_material_id');
        $this->assertSame(['38.000000', '0.000000'], [(string) $saldos[$this->tapa->id]->pendiente, (string) $saldos[$this->tapa->id]->fraccion]);
        $this->assertSame(['0.000000', '0.165600'], [(string) $saldos[$this->pallet->id]->pendiente, (string) $saldos[$this->pallet->id]->fraccion]);
        $this->assertSame('158.000000', (string) $saldos[$this->etiqueta->id]->pendiente);

        // Lo ya rebajado no vuelve a aparecer; los pendientes se reintentan en la rebaja siguiente.
        $otra = $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->assertOk()->json();
        $this->assertSame(['sin_estandar'], collect($otra['grupos'])->pluck('problema')->all());
        $this->saldoPacking($this->tapa, 40, 'FMT0000004', now());
        $pendientes = $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => (string) Str::uuid(), 'grupos' => []])->assertCreated()
            ->assertJsonPath('data.codigo', 'REB-000002')->assertJsonPath('data.cajas', 0)->json('data');
        $this->assertSame(38.0, (float) collect($pendientes['consumos'])->firstWhere('item.codigo', '031026')['descontado']);
        $this->assertSame(2.0, $this->saldo('FMT0000004'));
        $this->assertSame('0.000000', (string) SaldoRebajaEstandar::query()->where('item_material_id', $this->tapa->id)->value('pendiente'));

        // La fracción del pallet se suma a la producción siguiente: 0,1656 + 99 × 0,0062 = 0,7794 → 0 pallets.
        $siguiente = $this->postJson(self::RUTA.'/previsualizar', ['desde' => '2026-11-12', 'hasta' => '2026-11-12', 'almacen_id' => $this->packing->id])->assertOk()->json();
        $pallet = collect($siguiente['materiales'])->firstWhere('codigo', '031000');
        $this->assertSame([0.1656, 0, 0.7794], [round($pallet['fraccion_anterior'], 4), (int) $pallet['a_descontar'], round($pallet['fraccion_resultante'], 4)]);
    }

    public function test_rechaza_vistas_desactualizadas_grupos_sin_estandar_y_usuarios_sin_permiso(): void
    {
        $vista = $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->assertOk()->json();
        $listo = collect($vista['grupos'])->whereNull('problema')->first();
        $sinEstandar = collect($vista['grupos'])->whereNotNull('problema')->first();
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => (string) Str::uuid(), 'grupos' => [['clave' => $listo['clave'], 'cajas' => $listo['cajas'] + 1]]])
            ->assertConflict();
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => (string) Str::uuid(), 'grupos' => [['clave' => $sinEstandar['clave'], 'cajas' => $sinEstandar['cajas']]]])
            ->assertUnprocessable();
        $this->postJson(self::RUTA.'/previsualizar', [...$this->filtros(), 'desde' => '2026-09-01'])->assertUnprocessable();
        $this->postJson(self::RUTA.'/previsualizar', [...$this->filtros(), 'almacen_id' => AlmacenMaterial::query()->where('codigo', AlmacenMaterial::CODIGO_BODEGA_CENTRAL)->value('id')])
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta, 'activo' => true]), 'sanctum');
        $this->getJson(self::RUTA.'/opciones')->assertOk()->assertJsonPath('puede_rebajar', false);
        $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->assertOk();
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => (string) Str::uuid(), 'grupos' => []])->assertForbidden();
        $this->assertSame(0, MovimientoAlmacenMaterial::query()->where('documento_relacionado', 'like', 'REB-%')->count());
    }

    public function test_correcciones_y_anulaciones_posteriores_generan_ajustes_que_compensan_pendientes_y_quedan_a_favor(): void
    {
        $vista = $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->json();
        $listos = collect($vista['grupos'])->whereNull('problema')->map(fn ($grupo) => ['clave' => $grupo['clave'], 'cajas' => $grupo['cajas']])->values()->all();
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => (string) Str::uuid(), 'grupos' => $listos])->assertCreated();

        // El pallet de 98 cajas se corrige a 90 y el de la otra marca se anula después de rebajar.
        $corregido = ValidacionPallet::query()->where('cantidad_cajas', 98)->firstOrFail();
        $snapshot = $corregido->snapshot;
        $snapshot['composicion'][0]['cantidad_cajas'] = 90;
        $corregido->forceFill(['cantidad_cajas' => 90, 'snapshot' => $snapshot])->save();
        ValidacionPallet::query()->where('cantidad_cajas', 30)->firstOrFail()->forceFill(['estado' => 'anulada'])->save();

        $ajustes = $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->assertOk()->assertJsonPath('totales.ajustes', 2)->json();
        $porMarca = collect($ajustes['grupos'])->where('tipo', 'ajuste')->keyBy('marca');
        $this->assertSame([-8, 'Corregidos'], [$porMarca['FALCO FRUIT']['cajas'], $porMarca['FALCO FRUIT']['motivo']]);
        $this->assertSame([-30, 'Anulados'], [$porMarca['OTRA MARCA']['cajas'], $porMarca['OTRA MARCA']['motivo']]);
        $materiales = collect($ajustes['materiales'])->keyBy('codigo');
        // 38 cajas de menos compensan las 38 tapas que faltaban; el pallet queda a favor.
        $this->assertSame([-38.0, 0.0, 0.0], [(float) $materiales['031026']['teorico'], (float) $materiales['031026']['a_descontar'], (float) $materiales['031026']['faltante']]);
        $this->assertSame([-1.0, 0.0], [(float) $materiales['031000']['faltante'], (float) $materiales['031000']['a_descontar_ahora']]);

        $seleccion = collect($ajustes['grupos'])->where('tipo', 'ajuste')->map(fn ($grupo) => ['clave' => $grupo['clave'], 'cajas' => $grupo['cajas']])->values()->all();
        $this->postJson(self::RUTA, [...$this->filtros(), 'operacion_id' => (string) Str::uuid(), 'grupos' => $seleccion])->assertCreated()
            ->assertJsonPath('data.codigo', 'REB-000002')->assertJsonPath('data.cajas', -38)->assertJsonPath('data.movimientos', 0);
        $saldos = SaldoRebajaEstandar::query()->where('almacen_material_id', $this->packing->id)->get()->keyBy('item_material_id');
        $this->assertSame('0.000000', (string) $saldos[$this->tapa->id]->pendiente);
        $this->assertSame(['-1.000000', '0.930000'], [(string) $saldos[$this->pallet->id]->pendiente, (string) $saldos[$this->pallet->id]->fraccion]);
        $this->assertSame('150.000000', (string) $saldos[$this->etiqueta->id]->pendiente);
        $this->postJson(self::RUTA.'/previsualizar', $this->filtros())->assertOk()->assertJsonPath('totales.ajustes', 0);

        $respuesta = $this->get(self::RUTA.'/excel?desde='.now(config('app.operational_timezone'))->toDateString().'&hasta='.now(config('app.operational_timezone'))->toDateString())->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $contenido = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringContainsString('REB-000002', $contenido);
        $this->get(self::RUTA.'/excel?detalle=produccion&desde=2026-01-01&hasta=2026-12-31')->assertOk();
    }

    private function filtros(): array
    {
        return ['desde' => '2026-11-10', 'hasta' => '2026-11-11', 'almacen_id' => $this->packing->id];
    }

    private function saldo(string $folio): float
    {
        return (float) SaldoMaterialAlmacen::query()->where('almacen_material_id', $this->packing->id)
            ->where('folio_id', Folio::query()->where('numero_folio', $folio)->value('id'))->value('cantidad_actual');
    }

    private function item(ClienteMaterial $catalogo, string $codigo, string $nombre): ItemMaterial
    {
        return ItemMaterial::create([
            'cliente_material_id' => $catalogo->id, 'codigo' => $codigo, 'nombre' => $nombre, 'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo, 'unidad_medida' => 'UN', 'origen_sistema' => 'manual',
            'activo' => true, 'creado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);
    }

    /** Folio de material cuyo saldo ya fue entregado a Packing. */
    private function saldoPacking(ItemMaterial $item, float $cantidad, string $numero, $ingreso): void
    {
        $folio = Folio::create([
            'temporada_id' => $this->temporadaId, 'numero_folio' => $numero, 'tipo_bulto' => 'material',
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion, 'fecha_ingreso' => $ingreso, 'activo' => true,
            'origen_sistema' => 'recepcion_materiales', 'estado_integracion' => 'no_vinculado',
        ]);
        FolioMaterial::create([
            'folio_id' => $folio->id, 'item_material_id' => $item->id, 'categoria_operacional' => 'insumo', 'cantidad_inicial' => $cantidad,
            'cantidad_actual' => $cantidad, 'cantidad_reservada' => 0, 'unidad_medida' => 'UN', 'lote' => 'L1', 'proveedor' => 'Proveedor',
        ]);
        SaldoMaterialAlmacen::query()->where('folio_id', $folio->id)->update(['cantidad_actual' => 0, 'camara_id' => null, 'posicion_id' => null]);
        SaldoMaterialAlmacen::create(['folio_id' => $folio->id, 'almacen_material_id' => $this->packing->id, 'cantidad_actual' => $cantidad, 'cantidad_reservada' => 0]);
    }

    /** @param list<int> $cajasPorLinea */
    private function validar(string $fecha, string $marca, string $proceso, array $cajasPorLinea, ?EnvaseValidacion $envase = null): void
    {
        $envase ??= $this->envase;
        $marcaCatalogo = MarcaValidacion::query()->firstOrCreate(['cliente_validacion_id' => $this->clienteValidacion->id, 'nombre' => $marca], ['activo' => true]);
        $articulo = ArticuloValidacion::query()->firstOrCreate(
            ['temporada_id' => $this->temporadaId, 'especie' => 'Cereza', 'variedad' => 'Lapins', 'calibre' => 'XL', 'envase' => $envase->nombre],
            ['envase_validacion_id' => $envase->id, 'activo' => true],
        );
        $categoria = CategoriaValidacion::query()->firstOrCreate(['temporada_id' => $this->temporadaId, 'nombre' => 'CAT1'], ['activo' => true]);
        $numero = sprintf('%07d', ++$this->folios);
        $folio = Folio::create([
            'temporada_id' => $this->temporadaId, 'numero_folio' => $numero, 'tipo_bulto' => 'pallet',
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio, 'fecha_ingreso' => now(), 'activo' => true,
            'origen_sistema' => 'validacion', 'estado_integracion' => 'no_vinculado',
        ]);
        $composicion = [];
        foreach ($cajasPorLinea as $indice => $cajas) {
            $origen = OrigenValidacion::query()->firstOrCreate(
                ['temporada_id' => $this->temporadaId, 'cliente' => 'Falco Fruit', 'marca' => $marca, 'csg' => '10'.$indice],
                ['cliente_validacion_id' => $this->clienteValidacion->id, 'marca_validacion_id' => $marcaCatalogo->id, 'predio' => 'Predio', 'activo' => true],
            );
            $composicion[] = ['articulo_validacion_id' => $articulo->id, 'envase_validacion_id' => $envase->id, 'especie' => 'Cereza', 'variedad' => 'Lapins',
                'envase' => $envase->nombre, 'cliente' => 'Falco Fruit', 'origen_validacion_id' => $origen->id, 'csg' => $origen->csg, 'predio' => 'Predio',
                'fecha_embalaje' => $fecha, 'cantidad_cajas' => $cajas, 'proceso_packing' => $proceso];
        }
        ValidacionPallet::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'numero_folio' => $numero, 'numero_intento' => 1,
            'tipo_bulto' => 'pallet', 'cantidad_cajas' => array_sum($cajasPorLinea), 'linea_proceso' => 1, 'turno' => 'A',
            'temporada_id' => $this->temporadaId, 'articulo_validacion_id' => $articulo->id, 'origen_validacion_id' => $composicion[0]['origen_validacion_id'],
            'categoria_validacion_id' => $categoria->id, 'resultado' => 'aprobado', 'estado' => 'aceptada', 'catalogo_version_dispositivo' => 1,
            'catalogo_version_servidor' => 1, 'user_id' => $this->administrador->id, 'dispositivo_id' => $this->dispositivo->id, 'folio_id' => $folio->id,
            'generado_dispositivo_at' => now(), 'recibido_servidor_at' => now(),
            'snapshot' => ['articulo' => ['especie' => 'Cereza', 'variedad' => 'Lapins', 'calibre' => 'XL', 'envase' => $envase->nombre],
                'origen' => ['cliente' => 'Falco Fruit', 'marca' => $marca], 'fecha_embalaje' => $fecha, 'composicion' => $composicion,
                'categoria' => ['id' => $categoria->id, 'nombre' => 'CAT1']],
        ]);
    }
}
