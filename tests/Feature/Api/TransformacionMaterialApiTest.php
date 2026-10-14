<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoLoteTransformacionMaterial;
use App\Enums\EstadoOrdenTransformacionMaterial;
use App\Enums\EstadoReservaMaterial;
use App\Enums\RolUsuario;
use App\Enums\TipoMovimientoInventarioMaterial;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\FolioSalidaReservadoTransformacion;
use App\Models\FolioTrabajoImpresionMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\PerfilImpresionEtiqueta;
use App\Models\Posicion;
use App\Models\ProveedorMaterial;
use App\Models\ReservaTransformacionMaterial;
use App\Models\Temporada;
use App\Models\TrabajoImpresionMaterial;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransformacionMaterialApiTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\PreparaFotoDocumentoRecepcionMaterial;

    public function test_transforma_material_mp_a_pt_conservando_item_codigo_y_trazabilidad(): void
    {
        [, $tokenOficina, $cliente, $proveedor, $item, $auxiliar] =
            $this->prepararCatalogo();
        [, , $tokenTablet] = $this->crearOperador();
        [$camara, $posicion] = $this->crearCamaraMateriales();
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                $auxiliar,
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folioEntrada = $confirmada['detalles'][0]['bultos'][0]['folio'];
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                $folioEntrada['numero_folio'],
                $posicion,
                $sesion,
                $item,
                0,
            ))
            ->assertOk();

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $item->id,
                'nombre' => 'Mismo código sin componente principal',
                'cantidad_base_salida' => 80,
                'componentes' => [[
                    'item_entrada_id' => $auxiliar->id,
                    'cantidad_estandar' => 1,
                    'es_componente_principal' => true,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $receta = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $item->id,
                'nombre' => 'Preparar conservando código',
                'cantidad_base_salida' => 80,
                'unidades_por_folio_salida' => 80,
                'componentes' => [[
                    'item_entrada_id' => $item->id,
                    'cantidad_estandar' => 80,
                    'es_componente_principal' => true,
                    'factor_conversion' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.item_salida.id', $item->id)
            ->assertJsonPath('data.item_salida.codigo', $item->codigo)
            ->assertJsonPath('data.item_salida.categoria_operacional', 'material_mp')
            ->assertJsonPath('data.versiones.0.componentes.0.item.id', $item->id)
            ->json('data');
        $receta = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/recetas/{$receta['id']}/versiones", [
                'cantidad_base_salida' => 80,
                'unidades_por_folio_salida' => 40,
                'componentes' => [[
                    'item_entrada_id' => $item->id,
                    'cantidad_estandar' => 80,
                    'es_componente_principal' => true,
                    'factor_conversion' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.versiones.0.numero_version', 2)
            ->assertJsonPath('data.versiones.0.componentes.0.item.id', $item->id)
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', [
                'operacion_id' => (string) Str::uuid(),
                'version_receta_material_id' => $receta['versiones'][0]['id'],
                'cantidad_planificada_salida' => 40,
                'fecha_operacional' => '2026-09-10',
            ])
            ->assertCreated()
            ->assertJsonPath('data.receta_snapshot.salida.item_id', $item->id)
            ->assertJsonPath('data.receta_snapshot.salida.categoria_operacional', 'material_pt')
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.reservas')
            ->assertJsonPath('data.reservas.0.item_material_id', $item->id)
            ->assertJsonPath('data.reservas.0.cantidad', '40.000')
            ->json('data');
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
            ])
            ->assertOk()
            ->json('data');
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 40,
            ])
            ->assertOk()
            ->json('data');
        $lote = $orden['lotes'][0];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 40,
                'consumos' => [[
                    'folio_id' => $folioEntrada['id'],
                    'cantidad' => 40,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.0.salidas.0.item.id', $item->id)
            ->assertJsonPath('data.lotes.0.salidas.0.item.codigo', $item->codigo)
            ->json('data');
        $folioSalidaId = $orden['lotes'][0]['salidas'][0]['folio_id'];

        $this->assertDatabaseHas('folios_materiales', [
            'folio_id' => $folioEntrada['id'],
            'item_material_id' => $item->id,
            'categoria_operacional' => 'material_mp',
            'cantidad_actual' => 40,
        ]);
        $this->assertDatabaseHas('folios_materiales', [
            'folio_id' => $folioSalidaId,
            'item_material_id' => $item->id,
            'categoria_operacional' => 'material_pt',
            'cantidad_actual' => 40,
        ]);
        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/inventario?vista=resumen')
            ->assertOk()
            ->assertJsonFragment([
                'categoria_operacional' => 'material_mp',
                'cantidad_actual' => '40.000',
            ])
            ->assertJsonFragment([
                'categoria_operacional' => 'material_pt',
                'cantidad_actual' => '40.000',
            ]);

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'Prueba de reversa del cambio de condición.',
            ])
            ->assertOk();
        $this->assertDatabaseHas('folios_materiales', [
            'folio_id' => $folioEntrada['id'],
            'item_material_id' => $item->id,
            'categoria_operacional' => 'material_mp',
            'cantidad_actual' => 80,
        ]);
        $this->assertDatabaseHas('folios_materiales', [
            'folio_id' => $folioSalidaId,
            'item_material_id' => $item->id,
            'categoria_operacional' => 'material_pt',
            'cantidad_actual' => 0,
        ]);
    }

    public function test_crea_receta_planifica_fifo_y_cancela_liberando_reservas(): void
    {
        [$administrador, $tokenOficina, $cliente, $proveedor, $entradaPrincipal, $entradaAuxiliar, $salida] =
            $this->prepararCatalogo();
        [, , $tokenTablet] = $this->crearOperador();
        [$camara, $posicionUno, $posicionDos] = $this->crearCamaraMateriales();
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $entradaPrincipal,
                $entradaAuxiliar,
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folioPrincipal = $confirmada['detalles'][0]['bultos'][0]['folio']['numero_folio'];
        $folioAuxiliar = $confirmada['detalles'][1]['bultos'][0]['folio']['numero_folio'];
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                $folioPrincipal,
                $posicionUno,
                $sesion,
                $entradaPrincipal,
                0,
            ))
            ->assertOk();
        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                $folioAuxiliar,
                $posicionDos,
                $sesion,
                $entradaAuxiliar,
                1,
            ))
            ->assertOk();

        $receta = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => 'Caja preparada 10 kg',
                'cantidad_base_salida' => 100,
                'componentes' => [
                    [
                        'item_entrada_id' => $entradaPrincipal->id,
                        'cantidad_estandar' => 100,
                        'es_componente_principal' => true,
                        'factor_conversion' => 1,
                        'merma_estandar_porcentaje' => 1,
                        'tolerancia_porcentaje' => 0.5,
                    ],
                    [
                        'item_entrada_id' => $entradaAuxiliar->id,
                        'cantidad_estandar' => 10,
                        'es_componente_principal' => false,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.versiones.0.numero_version', 1)
            ->assertJsonPath('data.versiones.0.estado', 'activa')
            ->assertJsonCount(2, 'data.versiones.0.componentes')
            ->json('data');

        $operacionOrden = (string) Str::uuid();
        $payloadOrden = [
            'operacion_id' => $operacionOrden,
            'version_receta_material_id' => $receta['versiones'][0]['id'],
            'cantidad_planificada_salida' => 50,
            'linea' => 'Armado 1',
            'turno' => 'Día',
            'fecha_operacional' => '2026-07-24',
        ];
        $orden = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', $payloadOrden)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.version', 1)
            ->json('data');
        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', $payloadOrden)
            ->assertCreated()
            ->assertJsonPath('data.id', $orden['id']);

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/recetas/{$receta['id']}/versiones", [
                'cantidad_base_salida' => 100,
                'componentes' => [
                    [
                        'item_entrada_id' => $entradaPrincipal->id,
                        'cantidad_estandar' => 120,
                        'es_componente_principal' => true,
                    ],
                    [
                        'item_entrada_id' => $entradaAuxiliar->id,
                        'cantidad_estandar' => 20,
                        'es_componente_principal' => false,
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.versiones.0.numero_version', 2)
            ->assertJsonPath('data.versiones.1.estado', 'retirada');
        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/transformaciones/recetas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.versiones_count', 2)
            ->assertJsonCount(1, 'data.0.versiones')
            ->assertJsonPath('data.0.versiones.0.numero_version', 2);

        $operacionPlanificacion = (string) Str::uuid();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $planificada = $this->conToken($tokenOficina)
                ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                    'operacion_id' => $operacionPlanificacion,
                    'version_conocida' => 1,
                ])
                ->assertOk()
                ->assertJsonPath('data.estado', 'planificada')
                ->assertJsonPath('data.version', 2)
                ->assertJsonCount(2, 'data.reservas')
                ->assertJsonPath('data.eventos.1.tipo', 'planificada')
                ->json('data');
            $consultasFifo = collect(DB::getQueryLog())
                ->filter(fn (array $consulta): bool => str_contains($consulta['query'], 'folios_materiales')
                    && str_contains($consulta['query'], 'cantidad_actual')
                    && str_contains($consulta['query'], 'cantidad_reservada')
                    && str_contains($consulta['query'], 'fecha_ingreso'))
                ->values();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(2, $consultasFifo);
        $this->assertTrue($consultasFifo->every(
            fn (array $consulta): bool => str_contains(strtolower($consulta['query']), 'limit 1'),
        ));

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                'operacion_id' => $operacionPlanificacion,
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $orden['id']);
        $this->assertSame(2, ReservaTransformacionMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->count());
        $this->assertDatabaseHas('reservas_transformacion_materiales', [
            'orden_transformacion_material_id' => $orden['id'],
            'item_material_id' => $entradaPrincipal->id,
            'cantidad' => 50,
            'estado' => 'activa',
            'orden_fifo' => 1,
        ]);
        $this->assertDatabaseHas('reservas_transformacion_materiales', [
            'orden_transformacion_material_id' => $orden['id'],
            'item_material_id' => $entradaAuxiliar->id,
            'cantidad' => 5,
            'estado' => 'activa',
            'orden_fifo' => 1,
        ]);
        $this->assertSame('50.000', FolioMaterial::query()
            ->findOrFail(Folio::query()->where('numero_folio', $folioPrincipal)->value('id'))
            ->cantidad_reservada);
        $this->assertSame('5.000', FolioMaterial::query()
            ->findOrFail(Folio::query()->where('numero_folio', $folioAuxiliar)->value('id'))
            ->cantidad_reservada);
        $this->assertSame($administrador->id, $planificada['creado_por']['id']);

        $itemAlternativo = $this->crearItem(
            ClienteMaterial::findOrFail($entradaPrincipal->cliente_material_id),
            $administrador,
            'CAJA-DES-CORR',
            'Caja desarmada alternativa',
            CategoriaOperacionalMaterial::MaterialMp,
        );
        $folioPrincipalId = Folio::query()
            ->where('numero_folio', $folioPrincipal)
            ->value('id');
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/inventario/{$folioPrincipalId}/corregir-item", [
                'operacion_id' => (string) Str::uuid(),
                'item_material_id' => $itemAlternativo->id,
                'motivo' => 'Intento de corrección con reserva de transformación activa.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertDatabaseHas('folios_materiales', [
            'folio_id' => $folioPrincipalId,
            'item_material_id' => $entradaPrincipal->id,
        ]);

        $operacionCancelacion = (string) Str::uuid();
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => $operacionCancelacion,
                'motivo' => 'Cambio de programación de la línea.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.eventos.2.tipo', 'cancelada');
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => $operacionCancelacion,
                'motivo' => 'Cambio de programación de la línea.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada');
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => $operacionCancelacion,
                'motivo' => 'Motivo diferente con el mismo UUID.',
            ])
            ->assertConflict();

        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail(Folio::query()->where('numero_folio', $folioPrincipal)->value('id'))
            ->cantidad_reservada);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail(Folio::query()->where('numero_folio', $folioAuxiliar)->value('id'))
            ->cantidad_reservada);
        $this->assertSame(2, ReservaTransformacionMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('estado', 'liberada')
            ->count());

        $recetaSinSaldo = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => 'Receta con auxiliar insuficiente',
                'cantidad_base_salida' => 100,
                'componentes' => [
                    [
                        'item_entrada_id' => $entradaPrincipal->id,
                        'cantidad_estandar' => 100,
                        'es_componente_principal' => true,
                    ],
                    [
                        'item_entrada_id' => $entradaAuxiliar->id,
                        'cantidad_estandar' => 40,
                        'es_componente_principal' => false,
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data');
        $ordenSinSaldo = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', [
                'operacion_id' => (string) Str::uuid(),
                'version_receta_material_id' => $recetaSinSaldo['versiones'][0]['id'],
                'cantidad_planificada_salida' => 50,
                'fecha_operacional' => '2026-07-24',
            ])
            ->assertCreated()
            ->json('data');
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$ordenSinSaldo['id']}/planificar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertSame(0, ReservaTransformacionMaterial::query()
            ->where('orden_transformacion_material_id', $ordenSinSaldo['id'])
            ->count());
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail(Folio::query()->where('numero_folio', $folioPrincipal)->value('id'))
            ->cantidad_reservada);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail(Folio::query()->where('numero_folio', $folioAuxiliar)->value('id'))
            ->cantidad_reservada);
    }

    public function test_rechaza_receta_sin_unico_componente_principal_y_restringe_permisos(): void
    {
        [$administrador, $tokenOficina, $cliente, , $entradaPrincipal, $entradaAuxiliar, $salida] =
            $this->prepararCatalogo();
        $camarero = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $tokenCamarero = $camarero->createToken('oficina-consulta', ['oficina'])->plainTextToken;
        $alcance = app(AlcanceOperacionalUsuario::class);
        $capacidadesAdministrador = $alcance->capacidadesApi($administrador);
        $capacidadesCamarero = $alcance->capacidadesApi($camarero);
        $this->assertTrue($capacidadesAdministrador['puede_consultar_transformaciones_materiales']);
        $this->assertTrue($capacidadesAdministrador['puede_gestionar_transformaciones_materiales']);
        $this->assertTrue($capacidadesAdministrador['puede_administrar_recetas_materiales']);
        $this->assertTrue($capacidadesCamarero['puede_consultar_transformaciones_materiales']);
        $this->assertFalse($capacidadesCamarero['puede_gestionar_transformaciones_materiales']);
        $this->assertTrue($capacidadesCamarero['puede_operar_transformaciones_materiales']);
        $this->assertFalse($capacidadesCamarero['puede_revertir_transformaciones_materiales']);
        $this->assertFalse($capacidadesCamarero['puede_administrar_recetas_materiales']);
        $this->assertTrue($capacidadesAdministrador['puede_revertir_transformaciones_materiales']);
        $payload = [
            'cliente_id' => $cliente->id,
            'item_salida_id' => $salida->id,
            'nombre' => 'Receta inválida',
            'cantidad_base_salida' => 100,
            'componentes' => [
                [
                    'item_entrada_id' => $entradaPrincipal->id,
                    'cantidad_estandar' => 100,
                    'es_componente_principal' => true,
                ],
                [
                    'item_entrada_id' => $entradaAuxiliar->id,
                    'cantidad_estandar' => 10,
                    'es_componente_principal' => true,
                ],
            ],
        ];

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertDatabaseCount('recetas_materiales', 0);

        $payload['componentes'][1]['es_componente_principal'] = false;
        $this->conToken($tokenCamarero)
            ->postJson('/api/materiales/transformaciones/recetas', $payload)
            ->assertForbidden();
        $this->conToken($tokenCamarero)
            ->getJson('/api/materiales/transformaciones/recetas')
            ->assertOk();
    }

    public function test_no_planifica_una_orden_de_una_temporada_historica(): void
    {
        [, $tokenOficina, $cliente, , $entradaPrincipal, , $salida] =
            $this->prepararCatalogo();
        $receta = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => 'Receta de temporada anterior',
                'cantidad_base_salida' => 100,
                'componentes' => [[
                    'item_entrada_id' => $entradaPrincipal->id,
                    'cantidad_estandar' => 100,
                    'es_componente_principal' => true,
                ]],
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', [
                'operacion_id' => (string) Str::uuid(),
                'version_receta_material_id' => $receta['versiones'][0]['id'],
                'cantidad_planificada_salida' => 20,
                'fecha_operacional' => '2026-07-26',
            ])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->json('data');
        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/transformaciones/ordenes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orden['id'])
            ->assertJsonPath('data.0.reservas_count', 0)
            ->assertJsonPath('data.0.lotes_count', 0)
            ->assertJsonPath('data.0.tiene_salidas', false)
            ->assertJsonMissingPath('data.0.receta_snapshot')
            ->assertJsonMissingPath('data.0.reservas')
            ->assertJsonMissingPath('data.0.lotes')
            ->assertJsonMissingPath('data.0.eventos');
        $this->conToken($tokenOficina)
            ->getJson("/api/materiales/transformaciones/ordenes/{$orden['id']}")
            ->assertOk()
            ->assertJsonCount(0, 'data.reservas')
            ->assertJsonCount(0, 'data.lotes')
            ->assertJsonCount(1, 'data.eventos');

        Temporada::query()->where('activa', true)->update(['activa' => false]);
        Temporada::create([
            'codigo' => 'TEMP-SIGUIENTE',
            'nombre' => 'Temporada siguiente',
            'activa' => true,
        ]);
        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/transformaciones/recetas')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->conToken($tokenOficina)
            ->getJson('/api/materiales/transformaciones/ordenes')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath(
                'message',
                'La orden pertenece a una temporada histórica y no puede planificarse.',
            );

        $this->assertDatabaseHas('ordenes_transformacion_materiales', [
            'id' => $orden['id'],
            'estado' => 'borrador',
            'version' => 1,
        ]);
        $this->assertSame(0, ReservaTransformacionMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->count());
    }

    public function test_ejecuta_lotes_parciales_revierte_el_ultimo_y_cierra_liberando_saldos(): void
    {
        [, $tokenOficina, $cliente, $proveedor, $entradaPrincipal, $entradaAuxiliar, $salida] =
            $this->prepararCatalogo();
        [$operador, $dispositivo, $tokenTablet] = $this->crearOperador();
        [$camara, $posicionUno, $posicionDos] = $this->crearCamaraMateriales();
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $entradaPrincipal,
                $entradaAuxiliar,
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folioPrincipal = $confirmada['detalles'][0]['bultos'][0]['folio'];
        $folioAuxiliar = $confirmada['detalles'][1]['bultos'][0]['folio'];
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');
        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                $folioPrincipal['numero_folio'],
                $posicionUno,
                $sesion,
                $entradaPrincipal,
                0,
            ))
            ->assertOk();
        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                $folioAuxiliar['numero_folio'],
                $posicionDos,
                $sesion,
                $entradaAuxiliar,
                1,
            ))
            ->assertOk();

        $receta = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => 'Caja preparada operativa',
                'cantidad_base_salida' => 100,
                'componentes' => [
                    [
                        'item_entrada_id' => $entradaPrincipal->id,
                        'cantidad_estandar' => 100,
                        'es_componente_principal' => true,
                        'factor_conversion' => 1,
                        'merma_estandar_porcentaje' => 1,
                    ],
                    [
                        'item_entrada_id' => $entradaAuxiliar->id,
                        'cantidad_estandar' => 10,
                        'es_componente_principal' => false,
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', [
                'operacion_id' => (string) Str::uuid(),
                'version_receta_material_id' => $receta['versiones'][0]['id'],
                'cantidad_planificada_salida' => 50,
                'fecha_operacional' => '2026-07-25',
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'planificada')
            ->json('data');

        $operacionInicio = (string) Str::uuid();
        $inicio = [
            'operacion_id' => $operacionInicio,
            'version_conocida' => 2,
        ];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar", $inicio)
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.version', 3)
            ->json('data');
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar", $inicio)
            ->assertOk()
            ->assertJsonPath('data.version', 3);

        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.lotes.0.estado', 'abierto')
            ->json('data');
        $loteUno = $orden['lotes'][0];
        $payloadCierreUno = [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 4,
            'cantidad_real_salida' => 29,
            'consumos' => [
                ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
                ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
            ],
        ];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteUno['id']}/cerrar", $payloadCierreUno)
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.version', 5)
            ->assertJsonPath('data.cantidad_real_salida', '29.000')
            ->assertJsonPath('data.lotes.0.salida_teorica', '30.000')
            ->assertJsonPath('data.lotes.0.merma_estandar', '0.300')
            ->assertJsonPath('data.lotes.0.merma_real', '1.000')
            ->assertJsonPath('data.lotes.0.desviacion_merma', '0.700')
            ->assertJsonPath('data.lotes.0.salidas.0.numero_folio', 'FGE0000003')
            ->json('data');
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteUno['id']}/cerrar", $payloadCierreUno)
            ->assertOk()
            ->assertJsonCount(1, 'data.lotes.0.salidas');

        $perfil = PerfilImpresionEtiqueta::query()->where('predeterminado', true)->firstOrFail();
        $folioSalidaUno = $orden['lotes'][0]['salidas'][0];
        $descarga = $this->conToken($tokenOficina)
            ->post(
                "/api/materiales/transformaciones/ordenes/{$orden['id']}/etiquetas",
                [
                    'operacion_id' => (string) Str::uuid(),
                    'perfil_id' => $perfil->id,
                    'formato' => 'pdf',
                    'canal' => 'oficina_descarga',
                    'folio_ids' => [$folioSalidaUno['folio_id']],
                    'copias' => 1,
                ],
                ['Accept' => 'application/pdf'],
            )
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $descarga->getContent());
        $this->assertDatabaseHas('trabajos_impresion_materiales', [
            'id' => $descarga->headers->get('X-Estiba-Print-Job'),
            'origen' => 'transformacion',
            'recepcion_material_id' => null,
            'orden_transformacion_material_id' => $orden['id'],
            'lote_transformacion_material_id' => $loteUno['id'],
        ]);
        $this->conToken($tokenOficina)
            ->getJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/impresiones")
            ->assertOk()
            ->assertJsonPath('data.0.origen', 'transformacion')
            ->assertJsonPath('data.0.folios.0.numero_folio', 'FGE0000003');
        $this->conToken($tokenTablet)
            ->post(
                "/api/materiales/transformaciones/ordenes/{$orden['id']}/etiquetas",
                [
                    'operacion_id' => (string) Str::uuid(),
                    'perfil_id' => $perfil->id,
                    'formato' => 'zpl',
                    'canal' => 'pda_directa',
                    'folio_ids' => [$folioSalidaUno['folio_id']],
                    'copias' => 1,
                    'motivo_reimpresion' => 'Etiqueta dañada durante la manipulación.',
                ],
                ['Accept' => 'application/zpl'],
            )
            ->assertOk()
            ->assertSee('FGE0000003', escape: false)
            ->assertSee('Transformaci', escape: false);

        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 6)
            ->json('data');
        $loteDos = $orden['lotes'][1];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteDos['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 6,
                'cantidad_real_salida' => 20,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 20],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.version', 7)
            ->assertJsonPath('data.cantidad_real_salida', '49.000')
            ->assertJsonPath('data.lotes.1.salidas.0.numero_folio', 'FGE0000004')
            ->json('data');

        $operacionReversa = (string) Str::uuid();
        $payloadReversa = [
            'operacion_id' => $operacionReversa,
            'version_conocida' => 7,
            'motivo' => 'Cantidad real digitada en el lote equivocado.',
        ];
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteUno['id']}/revertir", [
                ...$payloadReversa,
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->conToken($tokenTablet)
            ->postJson(
                "/api/materiales/transformaciones/lotes/{$loteDos['id']}/revertir",
                $payloadReversa,
            )
            ->assertForbidden();
        $orden = $this->conToken($tokenOficina)
            ->postJson(
                "/api/materiales/transformaciones/lotes/{$loteDos['id']}/revertir",
                $payloadReversa,
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.version', 8)
            ->assertJsonPath('data.cantidad_real_salida', '29.000')
            ->assertJsonPath('data.lotes.1.estado', 'anulado')
            ->assertJsonPath(
                'data.lotes.1.motivo_reversa',
                'Cantidad real digitada en el lote equivocado.',
            )
            ->assertJsonPath('data.lotes.1.salidas.0.numero_folio', 'FGE0000004')
            ->assertJsonPath('data.eventos.7.tipo', 'lote_reversado')
            ->json('data');
        $this->conToken($tokenOficina)
            ->postJson(
                "/api/materiales/transformaciones/lotes/{$loteDos['id']}/revertir",
                $payloadReversa,
            )
            ->assertOk()
            ->assertJsonPath('data.version', 8);
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteDos['id']}/revertir", [
                ...$payloadReversa,
                'motivo' => 'Otro motivo con el mismo identificador.',
            ])
            ->assertConflict();

        $folioSalidaRevertida = Folio::query()
            ->where('numero_folio', 'FGE0000004')
            ->firstOrFail();
        $this->assertFalse($folioSalidaRevertida->activo);
        $this->assertSame('anulado', $folioSalidaRevertida->estado_operacional->value);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail($folioSalidaRevertida->id)
            ->cantidad_actual);
        $this->assertSame('50.000', FolioMaterial::query()
            ->findOrFail($folioPrincipal['id'])
            ->cantidad_actual);
        $this->assertSame('20.000', FolioMaterial::query()
            ->findOrFail($folioPrincipal['id'])
            ->cantidad_reservada);
        $this->assertSame('7.000', FolioMaterial::query()
            ->findOrFail($folioAuxiliar['id'])
            ->cantidad_actual);
        $this->assertSame('2.000', FolioMaterial::query()
            ->findOrFail($folioAuxiliar['id'])
            ->cantidad_reservada);
        $this->assertSame(3, MovimientoInventarioMaterial::query()
            ->where('lote_transformacion_material_id', $loteDos['id'])
            ->where('tipo', 'reversa_transformacion')
            ->count());

        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 8,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 9)
            ->json('data');
        $loteReemplazo = $orden['lotes'][2];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteReemplazo['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 9,
                'cantidad_real_salida' => 20,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 20],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 10)
            ->assertJsonPath('data.cantidad_real_salida', '49.000')
            ->assertJsonPath('data.lotes.2.salidas.0.numero_folio', 'FGE0000005')
            ->json('data');

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $cerrada = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 10,
                'motivo_desviacion' => 'Producción real inferior por ajuste de línea.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->assertJsonPath('data.version', 11)
            ->assertJsonPath('data.cantidad_real_salida', '49.000')
            ->json('data');

        $this->assertCount(3, $cerrada['lotes']);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail($folioPrincipal['id'])
            ->cantidad_reservada);
        $this->assertSame('30.000', FolioMaterial::query()
            ->findOrFail($folioPrincipal['id'])
            ->cantidad_actual);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail($folioAuxiliar['id'])
            ->cantidad_reservada);
        $this->assertSame('5.000', FolioMaterial::query()
            ->findOrFail($folioAuxiliar['id'])
            ->cantidad_actual);
        $this->assertSame(2, Folio::query()
            ->where('origen_sistema', 'transformacion_materiales')
            ->where('estado_operacional', 'pendiente_ubicacion')
            ->where('activo', true)
            ->count());
        $this->assertSame(6, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', 'consumo_transformacion')
            ->count());
        $this->assertSame(3, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', 'produccion_transformacion')
            ->count());
        $this->assertDatabaseHas('eventos_transformacion_materiales', [
            'orden_transformacion_material_id' => $orden['id'],
            'tipo' => 'iniciada',
            'user_id' => $operador->id,
            'dispositivo_id' => $dispositivo->id,
        ]);
    }

    public function test_exige_motivo_fuera_de_fifo_y_revierte_el_cierre_incompleto(): void
    {
        [, $tokenOficina, $cliente, $proveedor, $entradaPrincipal, $entradaAuxiliar, $salida] =
            $this->prepararCatalogo();
        [, , $tokenTablet] = $this->crearOperador();
        [$camara, $posicionUno, $posicionDos, $posicionTres] = $this->crearCamaraMateriales();
        $payloadRecepcion = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $entradaPrincipal,
            $entradaAuxiliar,
        );
        $payloadRecepcion['numero_guia_despacho'] = 'GD-TRA-FIFO';
        $payloadRecepcion['detalles'][0]['bultos'] = [
            ['cantidad' => 40, 'lote_proveedor' => 'MP-FIFO-01'],
            ['cantidad' => 40, 'lote_proveedor' => 'MP-FIFO-02'],
        ];
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $payloadRecepcion)
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folioPrincipalUno = $confirmada['detalles'][0]['bultos'][0]['folio'];
        $folioPrincipalDos = $confirmada['detalles'][0]['bultos'][1]['folio'];
        $folioAuxiliar = $confirmada['detalles'][1]['bultos'][0]['folio'];
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        foreach ([
            [$folioPrincipalUno['numero_folio'], $posicionUno, $entradaPrincipal, 0],
            [$folioPrincipalDos['numero_folio'], $posicionDos, $entradaPrincipal, 1],
            [$folioAuxiliar['numero_folio'], $posicionTres, $entradaAuxiliar, 2],
        ] as [$folio, $posicion, $item, $version]) {
            $this->conToken($tokenTablet)
                ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                    $folio,
                    $posicion,
                    $sesion,
                    $item,
                    $version,
                ))
                ->assertOk();
        }

        $receta = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => 'Control FIFO operacional',
                'cantidad_base_salida' => 100,
                'componentes' => [
                    [
                        'item_entrada_id' => $entradaPrincipal->id,
                        'cantidad_estandar' => 100,
                        'es_componente_principal' => true,
                    ],
                    [
                        'item_entrada_id' => $entradaAuxiliar->id,
                        'cantidad_estandar' => 10,
                        'es_componente_principal' => false,
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', [
                'operacion_id' => (string) Str::uuid(),
                'version_receta_material_id' => $receta['versiones'][0]['id'],
                'cantidad_planificada_salida' => 50,
                'fecha_operacional' => '2026-07-25',
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
            ])
            ->assertOk()
            ->json('data');
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 10,
            ])
            ->assertOk()
            ->json('data');
        $lote = $orden['lotes'][0];

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 10,
                'consumos' => [
                    ['folio_id' => $folioPrincipalDos['id'], 'cantidad' => 10],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 1],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertDatabaseCount('consumos_transformacion_materiales', 0);
        $this->assertSame(0, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->count());
        $this->assertSame('40.000', FolioMaterial::query()
            ->findOrFail($folioPrincipalDos['id'])
            ->cantidad_actual);

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 10,
                'consumos' => [
                    [
                        'folio_id' => $folioPrincipalDos['id'],
                        'cantidad' => 10,
                        'motivo_desviacion_fifo' => 'Bulto delantero inaccesible durante el turno.',
                    ],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 1],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.0.consumos.0.siguio_fifo', false)
            ->assertJsonPath(
                'data.lotes.0.consumos.0.motivo_desviacion_fifo',
                'Bulto delantero inaccesible durante el turno.',
            )
            ->assertJsonPath('data.lotes.0.salidas.0.numero_folio', 'FGE0000004');
    }

    public function test_reversa_reactiva_un_folio_agotado_como_pendiente_de_ubicacion(): void
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80);
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 80,
            ])
            ->assertOk()
            ->json('data');
        $lote = $orden['lotes'][0];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 79,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 80],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 8],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 5)
            ->assertJsonPath('data.lotes.0.salidas.0.numero_folio', 'FGE0000003')
            ->json('data');

        $folioPrincipalAgotado = Folio::query()->findOrFail($folioPrincipal['id']);
        $this->assertFalse($folioPrincipalAgotado->activo);
        $this->assertSame('retirado_definitivo', $folioPrincipalAgotado->estado_operacional->value);
        $this->assertNull($folioPrincipalAgotado->ubicacionActual);

        $revertida = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'El consumo correspondía a otra orden de trabajo.',
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 6)
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.cantidad_real_salida', '0.000')
            ->assertJsonPath('data.lotes.0.estado', 'anulado')
            ->assertJsonPath(
                'data.eventos.5.datos.folios_entrada_requieren_ubicacion.0',
                $folioPrincipal['numero_folio'],
            )
            ->json('data');

        $folioPrincipalRestaurado = Folio::query()->findOrFail($folioPrincipal['id']);
        $materialPrincipal = FolioMaterial::query()->findOrFail($folioPrincipal['id']);
        $this->assertTrue($folioPrincipalRestaurado->activo);
        $this->assertSame('pendiente_ubicacion', $folioPrincipalRestaurado->estado_operacional->value);
        $this->assertNull($folioPrincipalRestaurado->ubicacionActual);
        $this->assertSame('80.000', $materialPrincipal->cantidad_actual);
        $this->assertSame('80.000', $materialPrincipal->cantidad_reservada);
        $reservaPrincipal = collect($revertida['reservas'])->first(
            fn (array $reserva): bool => data_get($reserva, 'folio.id') === $folioPrincipal['id'],
        );
        $this->assertNotNull($reservaPrincipal);
        $this->assertNull(data_get($reservaPrincipal, 'folio.ubicacion'));

        $folioSalida = Folio::query()->where('numero_folio', 'FGE0000003')->firstOrFail();
        $this->assertFalse($folioSalida->activo);
        $this->assertSame('anulado', $folioSalida->estado_operacional->value);
    }

    public function test_rechaza_reversa_si_el_folio_de_salida_ya_fue_ubicado(): void
    {
        [
            $tokenOficina,
            $tokenTablet,
            $folioPrincipal,
            $folioAuxiliar,
            $orden,
            $posicionSalida,
            $sesion,
            $itemSalida,
        ] = $this->prepararOrdenOperacional(20);
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->json('data');
        $lote = $orden['lotes'][0];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 20,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 20],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 2],
                ],
            ])
            ->assertOk()
            ->json('data');
        $folioSalida = $orden['lotes'][0]['salidas'][0];
        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                $folioSalida['numero_folio'],
                $posicionSalida,
                $sesion,
                $itemSalida,
                2,
            ))
            ->assertOk();

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'Se detectó una diferencia después de ubicar la salida.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->assertDatabaseHas('lotes_transformacion_materiales', [
            'id' => $lote['id'],
            'estado' => 'cerrado',
        ]);
        $this->assertDatabaseHas('folios', [
            'id' => $folioSalida['folio_id'],
            'estado_operacional' => 'disponible',
            'activo' => true,
        ]);
        $this->assertSame(0, MovimientoInventarioMaterial::query()
            ->where('lote_transformacion_material_id', $lote['id'])
            ->where('tipo', 'reversa_transformacion')
            ->count());
    }

    public function test_rechaza_reversa_si_la_etiqueta_de_salida_ya_fue_enviada_a_impresion(): void
    {
        [
            $tokenOficina,
            $tokenTablet,
            $folioPrincipal,
            $folioAuxiliar,
            $orden,
            $lote,
            $folioSalida,
        ] = $this->prepararLoteCerradoParaImpresion();
        $trabajoId = $this->generarTrabajoImpresionDirecta(
            $tokenTablet,
            $orden,
            $folioSalida,
        );

        $this->conToken($tokenTablet)
            ->postJson(
                "/api/materiales/transformaciones/trabajos-impresion/{$trabajoId}/resultado",
                [
                    'operacion_id' => (string) Str::uuid(),
                    'estado' => 'enviado',
                    'bytes_enviados' => 128,
                    'error' => null,
                    'impresora' => [
                        'nombre' => 'Zebra transformación',
                        'host' => '192.168.10.25',
                        'puerto' => 9100,
                    ],
                ],
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'enviado');

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'La cantidad real del lote fue digitada incorrectamente.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio')
            ->assertJsonPath(
                'message',
                'El lote posee etiquetas directas pendientes, enviadas o indeterminadas y no puede revertirse.',
            );

        $this->assertDatabaseHas('lotes_transformacion_materiales', [
            'id' => $lote['id'],
            'estado' => 'cerrado',
        ]);
        $this->assertDatabaseHas('folios', [
            'id' => $folioSalida['folio_id'],
            'estado_operacional' => 'pendiente_ubicacion',
            'activo' => true,
        ]);
        $this->assertSame('60.000', FolioMaterial::query()
            ->findOrFail($folioPrincipal['id'])
            ->cantidad_actual);
        $this->assertSame('8.000', FolioMaterial::query()
            ->findOrFail($folioAuxiliar['id'])
            ->cantidad_actual);
        $this->assertSame(0, MovimientoInventarioMaterial::query()
            ->where('lote_transformacion_material_id', $lote['id'])
            ->where('tipo', 'reversa_transformacion')
            ->count());
    }

    public function test_rechaza_reversa_con_impresion_directa_pendiente_o_indeterminada(): void
    {
        [
            $tokenOficina,
            $tokenTablet,
            ,
            ,
            $orden,
            $lote,
            $folioSalida,
        ] = $this->prepararLoteCerradoParaImpresion();
        $trabajoId = $this->generarTrabajoImpresionDirecta(
            $tokenTablet,
            $orden,
            $folioSalida,
        );

        $this->assertDatabaseHas('trabajos_impresion_materiales', [
            'id' => $trabajoId,
            'canal' => 'pda_directa',
            'estado' => 'generado',
        ]);
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'Intento mientras la impresión directa continúa pendiente.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El lote posee etiquetas directas pendientes, enviadas o indeterminadas y no puede revertirse.',
            );

        TrabajoImpresionMaterial::query()->findOrFail($trabajoId)->update([
            'estado' => 'indeterminado',
            'bytes_enviados' => 64,
            'ultimo_error' => 'La conexión se perdió después de iniciar el envío.',
        ]);
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'Intento con resultado de impresión indeterminado.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El lote posee etiquetas directas pendientes, enviadas o indeterminadas y no puede revertirse.',
            );

        $this->assertDatabaseHas('lotes_transformacion_materiales', [
            'id' => $lote['id'],
            'estado' => 'cerrado',
        ]);
        $this->assertDatabaseHas('folios', [
            'id' => $folioSalida['folio_id'],
            'estado_operacional' => 'pendiente_ubicacion',
            'activo' => true,
        ]);
        $this->assertSame(0, MovimientoInventarioMaterial::query()
            ->where('lote_transformacion_material_id', $lote['id'])
            ->where('tipo', 'reversa_transformacion')
            ->count());
    }

    public function test_configura_un_folio_por_lote_y_calcula_el_remanente_final(): void
    {
        [, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30);

        $this->assertSame('30.000', $orden['unidades_por_folio_salida']);
        $this->assertSame(3, $orden['folios_planificados']);
        $this->assertSame(0, $orden['folios_generados']);
        $this->assertSame(3, $orden['folios_pendientes']);

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 80,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El siguiente folio debe planificarse con 30.000 unidades de salida.',
            );

        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.0.cantidad_planificada_salida', '30.000')
            ->json('data');
        $loteUno = $orden['lotes'][0];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteUno['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 30,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.folios_generados', 1)
            ->assertJsonPath('data.folios_pendientes', 2)
            ->assertJsonCount(1, 'data.lotes.0.salidas')
            ->json('data');

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El siguiente folio debe planificarse con 30.000 unidades de salida.',
            );

        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'cantidad_planificada_salida' => 30,
            ])
            ->assertOk()
            ->json('data');
        $loteDos = $orden['lotes'][1];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteDos['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 6,
                'cantidad_real_salida' => 30,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.folios_generados', 2)
            ->json('data');

        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 7,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.2.cantidad_planificada_salida', '20.000')
            ->json('data');
        $loteTres = $orden['lotes'][2];
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$loteTres['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 8,
                'cantidad_real_salida' => 20,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 20],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente_cierre')
            ->assertJsonPath('data.folios_generados', 3)
            ->assertJsonPath('data.folios_pendientes', 0)
            ->assertJsonCount(1, 'data.lotes.0.salidas')
            ->assertJsonCount(1, 'data.lotes.1.salidas')
            ->assertJsonCount(1, 'data.lotes.2.salidas');
    }

    public function test_planificar_reserva_los_folios_de_salida_y_cada_lote_usa_el_siguiente(): void
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30);

        $this->assertSame([1, 2, 3], array_column($orden['folios_salida'], 'secuencia'));
        $this->assertSame(['30.000', '30.000', '20.000'], array_column($orden['folios_salida'], 'cantidad_teorica'));
        $this->assertSame(['reservado', 'reservado', 'reservado'], array_column($orden['folios_salida'], 'estado'));
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $this->assertCount(3, array_unique($numeros));
        foreach ($numeros as $numero) {
            $this->assertMatchesRegularExpression('/^F[A-Z]{2}\d{7}$/', $numero);
        }
        $this->assertSame([
            'reservados' => 3,
            'sin_imprimir' => 3,
            'por_reimprimir' => 0,
            'usados' => 0,
            'anulados' => 0,
        ], $orden['folios_salida_resumen']);
        $planificada = collect($orden['eventos'])->firstWhere('tipo', 'planificada');
        $this->assertSame($numeros, $planificada['datos']['folios_salida_reservados']);
        // Los folios reservados todavía no existen en inventario.
        $this->assertSame(0, Folio::query()->whereIn('numero_folio', $numeros)->count());

        $listado = collect($this->conToken($tokenOficina)
            ->getJson('/api/materiales/transformaciones/ordenes')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $orden['id']);
        $this->assertSame(3, $listado['folios_salida_resumen']['reservados']);
        $this->assertSame(3, $listado['folios_salida_resumen']['sin_imprimir']);
        $this->assertArrayNotHasKey('folios_salida', $listado);

        $orden = $this->abrirYCerrarLote($tokenTablet, $orden['id'], 3, 30, [
            'cantidad_real_salida' => 30,
            'consumos' => [
                ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
                ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
            ],
        ]);

        $salida = $orden['lotes'][0]['salidas'][0];
        $this->assertSame($numeros[0], $salida['numero_folio']);
        $this->assertSame('usado', $orden['folios_salida'][0]['estado']);
        $this->assertSame($salida['folio_id'], $orden['folios_salida'][0]['folio_id']);
        $this->assertSame($orden['lotes'][0]['id'], $orden['folios_salida'][0]['lote_id']);
        $this->assertSame('30.000', $orden['folios_salida'][0]['cantidad_real']);
        $this->assertFalse($orden['folios_salida'][0]['requiere_reimpresion']);
        $this->assertSame(2, $orden['folios_salida_resumen']['reservados']);
        $this->assertSame(1, $orden['folios_salida_resumen']['usados']);
        $this->assertSame('TR-260726-001', FolioMaterial::query()->findOrFail($salida['folio_id'])->lote);
        $cerrado = collect($orden['eventos'])->firstWhere('tipo', 'lote_cerrado');
        $this->assertSame($numeros[0], $cerrado['datos']['folio_salida']);
        $this->assertTrue($cerrado['datos']['folio_salida_reservado']);
    }

    public function test_imprime_los_folios_reservados_con_la_cantidad_teorica_y_pide_reimprimir_si_cambia(): void
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30);
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $reservaIds = array_column($orden['folios_salida'], 'id');
        $url = "/api/materiales/transformaciones/ordenes/{$orden['id']}/etiquetas";

        $respuesta = $this->conToken($tokenOficina)
            ->post($url, $this->payloadEtiquetas(['reserva_ids' => $reservaIds]), ['Accept' => 'application/zpl'])
            ->assertOk();
        $trabajo = TrabajoImpresionMaterial::query()->findOrFail($respuesta->headers->get('X-Estiba-Print-Job'));
        $etiquetas = $trabajo->contenido_snapshot;
        $this->assertSame($numeros, array_column($etiquetas, 'numero_folio'));
        $this->assertSame(['30,000', '30,000', '20,000'], array_column($etiquetas, 'cantidad'));
        $this->assertSame([null, null, null], array_column($etiquetas, 'folio_id'));
        $this->assertSame([1, 2, 3], array_column($etiquetas, 'numero_lote_transformacion'));
        $this->assertSame('TR-260726-002', $etiquetas[1]['lote_proveedor']);
        $this->assertNull($trabajo->lote_transformacion_material_id);
        $this->assertStringContainsString($numeros[2], $respuesta->getContent());
        $this->assertEqualsCanonicalizing($reservaIds, FolioTrabajoImpresionMaterial::query()
            ->where('trabajo_impresion_material_id', $trabajo->id)
            ->pluck('folio_salida_reservado_id')
            ->all());
        $this->assertSame(3, FolioSalidaReservadoTransformacion::query()
            ->whereIn('id', $reservaIds)
            ->where('impresiones', 1)
            ->count());

        $this->conToken($tokenOficina)
            ->post($url, $this->payloadEtiquetas(['reserva_ids' => [$reservaIds[0]]]), ['Accept' => 'application/zpl'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Debes indicar el motivo para volver a generar una etiqueta.');
        $this->conToken($tokenOficina)
            ->post($url, $this->payloadEtiquetas([
                'reserva_ids' => [$reservaIds[0]],
                'motivo_reimpresion' => 'Etiqueta dañada en la línea.',
            ]), ['Accept' => 'application/zpl'])
            ->assertOk();
        $this->assertSame(2, FolioSalidaReservadoTransformacion::query()->findOrFail($reservaIds[0])->impresiones);

        // La línea produce 28 en vez de los 30 impresos.
        $orden = $this->abrirYCerrarLote($tokenTablet, $orden['id'], 3, 30, [
            'cantidad_real_salida' => 28,
            'consumos' => [
                ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
                ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
            ],
        ]);
        $salida = $orden['lotes'][0]['salidas'][0];
        $this->assertSame($numeros[0], $salida['numero_folio']);
        $this->assertTrue($orden['folios_salida'][0]['requiere_reimpresion']);
        $this->assertSame(1, $orden['folios_salida_resumen']['por_reimprimir']);
        $this->assertTrue(collect($orden['eventos'])->firstWhere('tipo', 'lote_cerrado')['datos']['requiere_reimpresion']);

        $this->conToken($tokenOficina)
            ->post($url, $this->payloadEtiquetas(['reserva_ids' => [$reservaIds[0]]]), ['Accept' => 'application/zpl'])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                "El folio {$numeros[0]} ya fue producido: imprime su etiqueta desde los folios generados.",
            );

        // La reimpresión por cambio de cantidad no exige motivo: el sistema lo registra.
        $respuesta = $this->conToken($tokenOficina)
            ->post($url, $this->payloadEtiquetas(['folio_ids' => [$salida['folio_id']]]), ['Accept' => 'application/zpl'])
            ->assertOk();
        $trabajo = TrabajoImpresionMaterial::query()->findOrFail($respuesta->headers->get('X-Estiba-Print-Job'));
        $this->assertSame('La cantidad real difiere de la cantidad teórica impresa.', $trabajo->motivo_reimpresion);
        $this->assertSame('28,000', $trabajo->contenido_snapshot[0]['cantidad']);
        $this->assertSame(1, $trabajo->contenido_snapshot[0]['numero_lote_transformacion']);
        $this->assertDatabaseHas('folios_trabajos_impresion_materiales', [
            'trabajo_impresion_material_id' => $trabajo->id,
            'folio_id' => $salida['folio_id'],
            'folio_salida_reservado_id' => $reservaIds[0],
            'es_reimpresion' => true,
        ]);
        $reserva = FolioSalidaReservadoTransformacion::query()->findOrFail($reservaIds[0]);
        $this->assertFalse($reserva->requiere_reimpresion);
        $this->assertSame(3, $reserva->impresiones);

        // Ya con la cantidad real impresa, otra copia vuelve a exigir motivo.
        $this->conToken($tokenOficina)
            ->post($url, $this->payloadEtiquetas(['folio_ids' => [$salida['folio_id']]]), ['Accept' => 'application/zpl'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Debes indicar el motivo para volver a generar una etiqueta.');

        $historial = $this->conToken($tokenOficina)
            ->getJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/impresiones")
            ->assertOk()
            ->json('data');
        $this->assertCount(3, $historial);
        $this->assertSame($reservaIds[0], $historial[0]['folios'][0]['folio_salida_reservado_id']);
    }

    public function test_valida_el_folio_reservado_pistoleado_al_cerrar_el_lote(): void
    {
        [, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30);
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $consumos = [
            ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
            ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
        ];
        $lote = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 30,
            ])
            ->assertOk()
            ->json('data.lotes.0');

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 30,
                'folio_salida' => 'FZZ0000999',
                'consumos' => $consumos,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El folio FZZ0000999 no está reservado para esta orden.');

        // El camarero pistolea la segunda etiqueta: el lote toma ese número.
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 30,
                'folio_salida' => mb_strtolower($numeros[1]),
                'consumos' => $consumos,
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.0.salidas.0.numero_folio', $numeros[1])
            ->assertJsonPath('data.folios_salida.0.estado', 'reservado')
            ->assertJsonPath('data.folios_salida.1.estado', 'usado')
            ->json('data');

        $lote = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'cantidad_planificada_salida' => 30,
            ])
            ->assertOk()
            ->json('data.lotes.1');
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 6,
                'cantidad_real_salida' => 30,
                'folio_salida' => $numeros[1],
                'consumos' => $consumos,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', "El folio {$numeros[1]} ya fue usado en otro lote.");
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 6,
                'cantidad_real_salida' => 30,
                'consumos' => $consumos,
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.1.salidas.0.numero_folio', $numeros[0]);
    }

    public function test_revertir_anula_el_folio_reservado_y_cerrar_la_orden_anula_los_no_usados(): void
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30);
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $consumos = [
            ['folio_id' => $folioPrincipal['id'], 'cantidad' => 30],
            ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 3],
        ];
        $orden = $this->abrirYCerrarLote($tokenTablet, $orden['id'], 3, 30, [
            'cantidad_real_salida' => 30,
            'consumos' => $consumos,
        ]);

        $orden = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$orden['lotes'][0]['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'motivo' => 'La cantidad real del lote fue digitada incorrectamente.',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame([1, 2, 3, 4], array_column($orden['folios_salida'], 'secuencia'));
        $this->assertSame('anulado', $orden['folios_salida'][0]['estado']);
        $this->assertSame('Lote 1 revertido.', $orden['folios_salida'][0]['motivo_anulacion']);
        $reemplazo = $orden['folios_salida'][3];
        $this->assertSame('reservado', $reemplazo['estado']);
        $this->assertSame('30.000', $reemplazo['cantidad_teorica']);
        $this->assertNotContains($reemplazo['numero_folio'], $numeros);
        $this->assertSame(
            $reemplazo['numero_folio'],
            collect($orden['eventos'])->firstWhere('tipo', 'lote_reversado')['datos']['folio_salida_reservado_reemplazo'],
        );
        $this->assertSame(3, $orden['folios_salida_resumen']['reservados']);
        $this->assertSame(1, $orden['folios_salida_resumen']['anulados']);

        // El siguiente lote de 30 toma el primer reservado de 30 en secuencia.
        $orden = $this->abrirYCerrarLote($tokenTablet, $orden['id'], 6, 30, [
            'cantidad_real_salida' => 30,
            'consumos' => $consumos,
        ]);
        $this->assertSame($numeros[1], $orden['lotes'][1]['salidas'][0]['numero_folio']);

        $cerrada = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 8,
                'motivo_desviacion' => 'Se suspende la producción por cambio de programa.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->json('data');

        $this->assertSame(
            ['anulado', 'usado', 'anulado', 'anulado'],
            array_column($cerrada['folios_salida'], 'estado'),
        );
        $this->assertSame('No utilizado al cerrar la orden.', $cerrada['folios_salida'][2]['motivo_anulacion']);
        $this->assertSame(
            [$numeros[2], $reemplazo['numero_folio']],
            collect($cerrada['eventos'])->firstWhere('tipo', 'cerrada')['datos']['folios_salida_anulados'],
        );
    }

    public function test_cancelar_anula_los_folios_reservados_y_una_receta_sin_unidades_por_folio_no_reserva(): void
    {
        [$tokenOficina, , , , $orden] = $this->prepararOrdenOperacional(80, 30);

        $cancelada = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Cambio de programación de la línea.',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(['anulado', 'anulado', 'anulado'], array_column($cancelada['folios_salida'], 'estado'));
        $this->assertSame(['Orden cancelada.'], array_values(array_unique(array_column($cancelada['folios_salida'], 'motivo_anulacion'))));
        $this->assertSame(0, $cancelada['folios_salida_resumen']['reservados']);
        $this->assertSame(
            array_column($cancelada['folios_salida'], 'numero_folio'),
            collect($cancelada['eventos'])->firstWhere('tipo', 'cancelada')['datos']['folios_salida_anulados'],
        );

        $this->conToken($tokenOficina)
            ->post(
                "/api/materiales/transformaciones/ordenes/{$orden['id']}/etiquetas",
                $this->payloadEtiquetas(['reserva_ids' => [$cancelada['folios_salida'][0]['id']]]),
                ['Accept' => 'application/zpl'],
            )
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                "El folio {$cancelada['folios_salida'][0]['numero_folio']} fue anulado y no puede etiquetarse.",
            );
    }

    public function test_modo_cuadratura_retira_al_iniciar_confirma_folios_y_cuadra_al_cierre(): void
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30, iniciar: false);
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $iniciar = "/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar";

        $this->conToken($tokenTablet)
            ->postJson($iniciar, [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
                'modo_operacion' => 'cuadratura_cierre',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folios_retirados');
        $this->conToken($tokenTablet)
            ->postJson($iniciar, [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
                'modo_operacion' => 'cuadratura_cierre',
                'folios_retirados' => [$folioPrincipal['id']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Confirma el retiro de cada folio reservado de la orden antes de iniciar.');

        $orden = $this->conToken($tokenTablet)
            ->postJson($iniciar, [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
                'modo_operacion' => 'cuadratura_cierre',
                'folios_retirados' => [mb_strtoupper($folioPrincipal['id']), $folioAuxiliar['id']],
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->assertJsonPath('data.modo_operacion', 'cuadratura_cierre')
            ->json('data');

        // Lo reservado sale del inventario: el principal queda vacío y el auxiliar conserva 2.
        $principal = FolioMaterial::query()->with('folio')->findOrFail($folioPrincipal['id']);
        $this->assertSame('0.000', $principal->cantidad_actual);
        $this->assertSame('retirado_definitivo', $principal->folio->estado_operacional->value);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folioPrincipal['id']]);
        $auxiliar = FolioMaterial::query()->with('folio')->findOrFail($folioAuxiliar['id']);
        $this->assertSame('2.000', $auxiliar->cantidad_actual);
        $this->assertSame('0.000', $auxiliar->cantidad_reservada);
        $this->assertSame('disponible', $auxiliar->folio->estado_operacional->value);
        $this->assertSame(['consumida', 'consumida'], array_column($orden['reservas'], 'estado'));
        $this->assertSame(2, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', 'consumo_transformacion')
            ->where('metadatos->sentido', 'retiro_linea')
            ->count());
        $iniciada = collect($orden['eventos'])->firstWhere('tipo', 'iniciada');
        $this->assertSame('cuadratura_cierre', $iniciada['datos']['modo_operacion']);
        $this->assertCount(2, $iniciada['datos']['componentes_retirados']);

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Esta orden se cuadra al cierre: confirma cada folio de salida pistoleando su etiqueta.',
            );

        $orden = $this->confirmarFolioSalida($tokenTablet, $orden['id'], 3, $numeros[0], 30);
        $this->assertSame('cerrado', $orden['lotes'][0]['estado']);
        $this->assertSame([], $orden['lotes'][0]['consumos']);
        $this->assertSame($numeros[0], $orden['lotes'][0]['salidas'][0]['numero_folio']);
        $this->assertSame('usado', $orden['folios_salida'][0]['estado']);
        $orden = $this->confirmarFolioSalida($tokenTablet, $orden['id'], 4, mb_strtolower($numeros[1]), 28);
        $this->assertSame('58.000', $orden['cantidad_real_salida']);
        $this->assertSame('en_proceso', $orden['estado']);
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/folios-salida/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 5,
                'folio_salida' => $numeros[2],
                'cantidad_real_salida' => 31,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Un folio de salida no puede superar 30.000 unidades.');

        $cerrar = "/api/materiales/transformaciones/ordenes/{$orden['id']}/cerrar";
        $componentes = collect($orden['receta_snapshot']['componentes'])->keyBy('es_componente_principal');
        $cuadratura = [
            ['item_id' => $componentes[true]['item_id'], 'cantidad_merma' => 2, 'cantidad_sin_armar' => 20],
            ['item_id' => $componentes[false]['item_id'], 'cantidad_merma' => 0, 'cantidad_sin_armar' => 2],
        ];
        $base = [
            'version_conocida' => 5,
            'motivo_desviacion' => 'La línea se detuvo antes de terminar el último pallet.',
        ];
        $this->conToken($tokenTablet)
            ->postJson($cerrar, [...$base, 'operacion_id' => (string) Str::uuid(), 'folios_creados' => 1, 'cuadratura' => $cuadratura])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La orden registra 2 folios creados. Actualiza la orden y vuelve a confirmar el cierre.');
        $this->conToken($tokenTablet)
            ->postJson($cerrar, [...$base, 'operacion_id' => (string) Str::uuid(), 'folios_creados' => 2, 'cuadratura' => [$cuadratura[0]]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Declara la merma y lo sin armar de cada componente de la receta.');

        $cerrada = $this->conToken($tokenTablet)
            ->postJson($cerrar, [...$base, 'operacion_id' => (string) Str::uuid(), 'folios_creados' => 2, 'cuadratura' => $cuadratura])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->json('data');

        $filas = collect($cerrada['cuadratura'])->keyBy('es_componente_principal');
        $this->assertSame(
            ['80.000', '58.000', '2.000', '20.000', '58.000', '0.000'],
            [$filas[true]['cantidad_retirada'], $filas[true]['cantidad_teorica'], $filas[true]['cantidad_merma'], $filas[true]['cantidad_sin_armar'], $filas[true]['cantidad_utilizada'], $filas[true]['desviacion']],
        );
        $this->assertSame(
            ['8.000', '5.800', '0.000', '2.000', '6.000', '0.200'],
            [$filas[false]['cantidad_retirada'], $filas[false]['cantidad_teorica'], $filas[false]['cantidad_merma'], $filas[false]['cantidad_sin_armar'], $filas[false]['cantidad_utilizada'], $filas[false]['desviacion']],
        );

        // Lo sin armar vuelve a su folio: el principal, vacío, queda para ubicar.
        $principal = FolioMaterial::query()->with('folio')->findOrFail($folioPrincipal['id']);
        $this->assertSame('20.000', $principal->cantidad_actual);
        $this->assertSame('pendiente_ubicacion', $principal->folio->estado_operacional->value);
        $this->assertTrue($principal->folio->activo);
        $auxiliar = FolioMaterial::query()->with('folio')->findOrFail($folioAuxiliar['id']);
        $this->assertSame('4.000', $auxiliar->cantidad_actual);
        $this->assertSame('disponible', $auxiliar->folio->estado_operacional->value);
        $reservas = collect($cerrada['reservas'])->keyBy('folio.id');
        $this->assertSame(['60.000', '20.000'], [
            $reservas[$folioPrincipal['id']]['cantidad_consumida'],
            $reservas[$folioPrincipal['id']]['cantidad_devuelta'],
        ]);
        $this->assertSame(2, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', 'devolucion_transformacion')
            ->where('metadatos->sentido', 'sin_armar')
            ->count());
        $this->assertSame(['usado', 'usado', 'anulado'], array_column($cerrada['folios_salida'], 'estado'));
        $evento = collect($cerrada['eventos'])->firstWhere('tipo', 'cerrada');
        $this->assertSame(2, $evento['datos']['folios_creados']);
        $this->assertCount(2, $evento['datos']['devoluciones_sin_armar']);
        $this->assertTrue($evento['datos']['devoluciones_sin_armar'][0]['requiere_ubicacion']);
    }

    public function test_modo_cuadratura_exige_motivo_si_lo_utilizado_no_cuadra_con_la_receta(): void
    {
        [, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30, iniciar: false);
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $this->iniciarEnCuadratura($tokenTablet, $orden['id'], [$folioPrincipal['id'], $folioAuxiliar['id']]);
        $orden = $this->confirmarFolioSalida($tokenTablet, $orden['id'], 3, $numeros[0], 30);
        $orden = $this->confirmarFolioSalida($tokenTablet, $orden['id'], 4, $numeros[1], 30);
        $orden = $this->confirmarFolioSalida($tokenTablet, $orden['id'], 5, $numeros[2], 20);
        $this->assertSame('pendiente_cierre', $orden['estado']);

        $componentes = collect($orden['receta_snapshot']['componentes'])->keyBy('es_componente_principal');
        $principal = $componentes[true];
        $cerrar = fn (array $principalDeclarado, ?string $motivo = null) => $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 6,
                'folios_creados' => 3,
                ...($motivo ? ['motivo_desviacion' => $motivo] : []),
                'cuadratura' => [
                    ['item_id' => $principal['item_id'], ...$principalDeclarado],
                    ['item_id' => $componentes[false]['item_id'], 'cantidad_merma' => 0, 'cantidad_sin_armar' => 0],
                ],
            ]);

        $cerrar(['cantidad_merma' => 50, 'cantidad_sin_armar' => 40])
            ->assertUnprocessable()
            ->assertJsonPath('message', "La merma y lo sin armar de {$principal['codigo']} superan lo retirado (80,000 {$principal['unidad_medida']}).");
        $cerrar(['cantidad_merma' => 0, 'cantidad_sin_armar' => 5])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Lo utilizado de {$principal['codigo']} no cuadra con la receta: indica el motivo de la diferencia.");
        $this->assertSame('0.000', FolioMaterial::query()->findOrFail($folioPrincipal['id'])->cantidad_actual);

        $cerrada = $cerrar(['cantidad_merma' => 0, 'cantidad_sin_armar' => 5], 'Se encontraron cinco cajas sin armar en la línea.')
            ->assertOk()
            ->json('data');
        $this->assertSame('-5.000', collect($cerrada['cuadratura'])->firstWhere('es_componente_principal', true)['desviacion']);
        $this->assertSame('5.000', FolioMaterial::query()->findOrFail($folioPrincipal['id'])->cantidad_actual);
        $this->assertSame([], collect($cerrada['folios_salida'])->where('estado', 'anulado')->values()->all());
    }

    public function test_modo_cuadratura_revierte_folios_y_cancelar_devuelve_lo_retirado(): void
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(80, 30, iniciar: false);
        $numeros = array_column($orden['folios_salida'], 'numero_folio');
        $this->iniciarEnCuadratura($tokenTablet, $orden['id'], [$folioPrincipal['id'], $folioAuxiliar['id']]);
        $orden = $this->confirmarFolioSalida($tokenTablet, $orden['id'], 3, $numeros[0], 30);
        $cancelar = fn () => $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Se reprograma la orden para el turno siguiente.',
            ]);

        $cancelar()->assertUnprocessable();

        $orden = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/lotes/{$orden['lotes'][0]['id']}/revertir", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'motivo' => 'Se pistoleó la etiqueta equivocada.',
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame('anulado', $orden['lotes'][0]['estado']);
        $this->assertSame('anulado', $orden['folios_salida'][0]['estado']);
        $this->assertSame('reservado', $orden['folios_salida'][3]['estado']);
        $this->assertSame('anulado', Folio::query()
            ->where('numero_folio', $numeros[0])
            ->firstOrFail()
            ->estado_operacional
            ->value);

        $cancelada = $cancelar()->assertOk()->json('data');

        $principal = FolioMaterial::query()->with('folio')->findOrFail($folioPrincipal['id']);
        $this->assertSame('80.000', $principal->cantidad_actual);
        $this->assertSame('pendiente_ubicacion', $principal->folio->estado_operacional->value);
        $this->assertSame('10.000', FolioMaterial::query()->findOrFail($folioAuxiliar['id'])->cantidad_actual);
        $this->assertSame(['liberada', 'liberada'], array_column($cancelada['reservas'], 'estado'));
        $this->assertEqualsCanonicalizing(['80.000', '8.000'], array_column($cancelada['reservas'], 'cantidad_devuelta'));
        $this->assertSame(2, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', 'devolucion_transformacion')
            ->where('metadatos->sentido', 'cancelacion')
            ->count());
        $this->assertCount(2, collect($cancelada['eventos'])->firstWhere('tipo', 'cancelada')['datos']['componentes_devueltos']);
    }

    public function test_cada_modo_rechaza_las_acciones_del_otro(): void
    {
        // Orden por lote: no acepta confirmar folios pistoleados.
        [, $tokenTablet, , , $orden] = $this->prepararOrdenOperacional(80, 30);
        $this->assertSame('por_lote', $orden['modo_operacion']);
        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/folios-salida/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'folio_salida' => $orden['folios_salida'][0]['numero_folio'],
                'cantidad_real_salida' => 30,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta orden se opera por lotes: abre un lote y pistolea sus consumos.');
    }

    public function test_sin_folios_de_salida_reservados_no_se_puede_iniciar_en_cuadratura(): void
    {
        [, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(20, iniciar: false);

        $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
                'modo_operacion' => 'cuadratura_cierre',
                'folios_retirados' => [$folioPrincipal['id'], $folioAuxiliar['id']],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La orden no tiene folios de salida reservados: iníciala con el flujo por lote.');
        $this->assertSame('80.000', FolioMaterial::query()->findOrFail($folioPrincipal['id'])->cantidad_actual);
    }

    /** @param list<string> $folios */
    private function iniciarEnCuadratura(string $tokenTablet, string $ordenId, array $folios): array
    {
        return $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$ordenId}/iniciar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 2,
                'modo_operacion' => 'cuadratura_cierre',
                'folios_retirados' => $folios,
            ])
            ->assertOk()
            ->json('data');
    }

    private function confirmarFolioSalida(
        string $tokenTablet,
        string $ordenId,
        int $version,
        string $numeroFolio,
        float $cantidad,
    ): array {
        return $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$ordenId}/folios-salida/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $version,
                'folio_salida' => $numeroFolio,
                'cantidad_real_salida' => $cantidad,
            ])
            ->assertOk()
            ->json('data');
    }

    /**
     * Abre un lote con la cantidad planificada y lo cierra con los datos indicados.
     *
     * @param  array<string, mixed>  $cierre
     * @return array<string, mixed>
     */
    private function abrirYCerrarLote(
        string $tokenTablet,
        string $ordenId,
        int $version,
        float $cantidadPlanificada,
        array $cierre,
    ): array {
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$ordenId}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $version,
                'cantidad_planificada_salida' => $cantidadPlanificada,
            ])
            ->assertOk()
            ->json('data');
        $lote = collect($orden['lotes'])->firstWhere('estado', 'abierto');

        return $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $version + 1,
                ...$cierre,
            ])
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function payloadEtiquetas(array $datos): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'perfil_id' => PerfilImpresionEtiqueta::query()->firstOrFail()->id,
            'formato' => 'zpl',
            'simbologia' => 'code128',
            'canal' => 'oficina_descarga',
            'copias' => 1,
            ...$datos,
        ];
    }

    private function prepararLoteCerradoParaImpresion(): array
    {
        [$tokenOficina, $tokenTablet, $folioPrincipal, $folioAuxiliar, $orden] =
            $this->prepararOrdenOperacional(20);
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->json('data');
        $lote = $orden['lotes'][0];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 20,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 20],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 2],
                ],
            ])
            ->assertOk()
            ->json('data');

        return [
            $tokenOficina,
            $tokenTablet,
            $folioPrincipal,
            $folioAuxiliar,
            $orden,
            $lote,
            $orden['lotes'][0]['salidas'][0],
        ];
    }

    private function generarTrabajoImpresionDirecta(
        string $tokenTablet,
        array $orden,
        array $folioSalida,
    ): string {
        $perfil = PerfilImpresionEtiqueta::query()->firstOrFail();
        $generado = $this->conToken($tokenTablet)
            ->post(
                "/api/materiales/transformaciones/ordenes/{$orden['id']}/etiquetas",
                [
                    'operacion_id' => (string) Str::uuid(),
                    'perfil_id' => $perfil->id,
                    'formato' => 'zpl',
                    'canal' => 'pda_directa',
                    'folio_ids' => [$folioSalida['folio_id']],
                    'copias' => 1,
                ],
                ['Accept' => 'application/zpl'],
            )
            ->assertOk();
        $trabajoId = $generado->headers->get('X-Estiba-Print-Job');
        $this->assertIsString($trabajoId);

        return $trabajoId;
    }

    public function test_cancela_orden_en_proceso_tomada_sin_movimientos(): void
    {
        [
            $tokenOficina,
            ,
            $folioPrincipal,
            $folioAuxiliar,
            $orden,
        ] = $this->prepararOrdenOperacional(20);

        $this->assertSame('en_proceso', $orden['estado']);
        // Sin unidades por folio en la receta, la orden sigue el flujo anterior.
        $this->assertSame([], $orden['folios_salida']);
        $this->assertArrayNotHasKey('folios_salida_resumen', $orden);
        $cancelada = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Orden tomada por error desde la operación.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada')
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.eventos.3.datos.estado_anterior', 'en_proceso')
            ->assertJsonPath('data.eventos.3.datos.lotes_abiertos_descartados', 0)
            ->json('data');

        $this->assertCount(0, $cancelada['lotes']);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail($folioPrincipal['id'])
            ->cantidad_reservada);
        $this->assertSame('0.000', FolioMaterial::query()
            ->findOrFail($folioAuxiliar['id'])
            ->cantidad_reservada);
        $this->assertSame(2, ReservaTransformacionMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('estado', EstadoReservaMaterial::Liberada->value)
            ->count());
    }

    public function test_cancela_orden_en_proceso_descartando_lote_abierto(): void
    {
        [
            $tokenOficina,
            $tokenTablet,
            ,
            ,
            $orden,
        ] = $this->prepararOrdenOperacional(20);
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->assertJsonPath('data.lotes.0.estado', 'abierto')
            ->json('data');

        $cancelada = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'El operador abrió una orden que no correspondía.',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada')
            ->assertJsonPath('data.version', 5)
            ->assertJsonPath('data.lotes.0.estado', 'anulado')
            ->assertJsonPath('data.eventos.4.datos.lotes_abiertos_descartados', 1)
            ->json('data');

        $this->assertStringContainsString(
            'Cancelación de orden:',
            $cancelada['lotes'][0]['motivo_reversa'],
        );
        $this->assertDatabaseCount('consumos_transformacion_materiales', 0);
        $this->assertDatabaseCount('salidas_transformacion_materiales', 0);
    }

    public function test_rechaza_cancelar_orden_en_proceso_con_lote_cerrado(): void
    {
        [
            $tokenOficina,
            $tokenTablet,
            $folioPrincipal,
            $folioAuxiliar,
            $orden,
        ] = $this->prepararOrdenOperacional(40);
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/lotes", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 3,
                'cantidad_planificada_salida' => 20,
            ])
            ->assertOk()
            ->json('data');
        $lote = $orden['lotes'][0];
        $orden = $this->conToken($tokenTablet)
            ->postJson("/api/materiales/transformaciones/lotes/{$lote['id']}/cerrar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 4,
                'cantidad_real_salida' => 20,
                'consumos' => [
                    ['folio_id' => $folioPrincipal['id'], 'cantidad' => 20],
                    ['folio_id' => $folioAuxiliar['id'], 'cantidad' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_proceso')
            ->json('data');

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/cancelar", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Intento de cancelar una orden con producción real.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseHas('ordenes_transformacion_materiales', [
            'id' => $orden['id'],
            'estado' => EstadoOrdenTransformacionMaterial::EnProceso->value,
        ]);
        $this->assertDatabaseHas('lotes_transformacion_materiales', [
            'id' => $lote['id'],
            'estado' => EstadoLoteTransformacionMaterial::Cerrado->value,
        ]);
        $this->assertSame(2, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', TipoMovimientoInventarioMaterial::ConsumoTransformacion->value)
            ->count());
        $this->assertSame(1, MovimientoInventarioMaterial::query()
            ->where('orden_transformacion_material_id', $orden['id'])
            ->where('tipo', TipoMovimientoInventarioMaterial::ProduccionTransformacion->value)
            ->count());
    }

    private function prepararOrdenOperacional(
        float $cantidadPlanificada,
        ?float $unidadesPorFolio = null,
        bool $iniciar = true,
    ): array {
        [, $tokenOficina, $cliente, $proveedor, $entradaPrincipal, $entradaAuxiliar, $salida] =
            $this->prepararCatalogo();
        [, , $tokenTablet] = $this->crearOperador();
        [$camara, $posicionUno, $posicionDos, $posicionTres] = $this->crearCamaraMateriales();
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $entradaPrincipal,
                $entradaAuxiliar,
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folioPrincipal = $confirmada['detalles'][0]['bultos'][0]['folio'];
        $folioAuxiliar = $confirmada['detalles'][1]['bultos'][0]['folio'];
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        foreach ([
            [$folioPrincipal['numero_folio'], $posicionUno, $entradaPrincipal, 0],
            [$folioAuxiliar['numero_folio'], $posicionDos, $entradaAuxiliar, 1],
        ] as [$folio, $posicion, $item, $version]) {
            $this->conToken($tokenTablet)
                ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                    $folio,
                    $posicion,
                    $sesion,
                    $item,
                    $version,
                ))
                ->assertOk();
        }

        $receta = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/recetas', [
                'cliente_id' => $cliente->id,
                'item_salida_id' => $salida->id,
                'nombre' => 'Reversa de consumo agotado',
                'cantidad_base_salida' => 100,
                ...($unidadesPorFolio !== null
                    ? ['unidades_por_folio_salida' => $unidadesPorFolio]
                    : []),
                'componentes' => [
                    [
                        'item_entrada_id' => $entradaPrincipal->id,
                        'cantidad_estandar' => 100,
                        'es_componente_principal' => true,
                    ],
                    [
                        'item_entrada_id' => $entradaAuxiliar->id,
                        'cantidad_estandar' => 10,
                        'es_componente_principal' => false,
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/transformaciones/ordenes', [
                'operacion_id' => (string) Str::uuid(),
                'version_receta_material_id' => $receta['versiones'][0]['id'],
                'cantidad_planificada_salida' => $cantidadPlanificada,
                'fecha_operacional' => '2026-07-26',
            ])
            ->assertCreated()
            ->json('data');
        $orden = $this->conToken($tokenOficina)
            ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        if ($iniciar) {
            $orden = $this->conToken($tokenTablet)
                ->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/iniciar", [
                    'operacion_id' => (string) Str::uuid(),
                    'version_conocida' => 2,
                ])
                ->assertOk()
                ->json('data');
        }

        return [
            $tokenOficina,
            $tokenTablet,
            $folioPrincipal,
            $folioAuxiliar,
            $orden,
            $posicionTres,
            $sesion,
            $salida,
        ];
    }

    private function prepararCatalogo(): array
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $token = $administrador->createToken('oficina-transformacion', ['oficina'])->plainTextToken;
        $catalogo = ClienteMaterial::query()
            ->with(['cliente', 'temporada'])
            ->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))
            ->firstOrFail();
        $cliente = $catalogo->cliente;
        $cliente->update(['codigo_folio_materiales' => 'GE']);
        $entradaPrincipal = $this->crearItem(
            $catalogo,
            $administrador,
            'CAJA-DES-10',
            'Caja desarmada 10 kg',
            CategoriaOperacionalMaterial::MaterialMp,
        );
        $entradaAuxiliar = $this->crearItem(
            $catalogo,
            $administrador,
            'ABS-10',
            'Absorbente para caja 10 kg',
            CategoriaOperacionalMaterial::Insumo,
        );
        $salida = $this->crearItem(
            $catalogo,
            $administrador,
            'CAJA-PREP-10',
            'Caja preparada 10 kg',
            CategoriaOperacionalMaterial::MaterialPt,
        );
        $proveedor = ProveedorMaterial::create([
            'codigo' => 'PROV-TRA',
            'nombre' => 'Proveedor transformación',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        DB::table('clientes_proveedores_materiales')->insert([
            'id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'activo' => true,
            'categorias' => json_encode(['Embalaje'], JSON_UNESCAPED_UNICODE),
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            $administrador,
            $token,
            $cliente,
            $proveedor,
            $entradaPrincipal,
            $entradaAuxiliar,
            $salida,
        ];
    }

    private function crearItem(
        ClienteMaterial $catalogo,
        User $usuario,
        string $codigo,
        string $nombre,
        CategoriaOperacionalMaterial $categoria,
    ): ItemMaterial {
        return ItemMaterial::create([
            'cliente_material_id' => $catalogo->id,
            'codigo' => $codigo,
            'nombre' => $nombre,
            'categoria' => 'Embalaje',
            'categoria_operacional' => $categoria,
            'unidad_medida' => 'unidades',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }

    private function crearOperador(): array
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-TRA-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet transformación',
            'activo' => true,
        ]);
        $token = $usuario
            ->crearTokenParaDispositivo($dispositivo, 'tablet-transformacion')
            ->plainTextToken;

        return [$usuario, $dispositivo, $token];
    }

    private function crearCamaraMateriales(): array
    {
        $camara = Camara::create([
            'codigo' => 'MAT-TRA-01',
            'nombre' => 'Cámara transformación',
            'contenido' => ContenidoCamara::Materiales,
        ]);
        $posiciones = collect([1, 2, 3])->map(fn (int $numero): Posicion => Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => $numero,
            'nivel' => 1,
            'etiqueta' => sprintf('B01-P%02d-N1', $numero),
        ]));

        return [$camara, ...$posiciones->all()];
    }

    private function payloadRecepcion(
        Cliente $cliente,
        ProveedorMaterial $proveedor,
        ItemMaterial $entradaPrincipal,
        ItemMaterial $entradaAuxiliar,
    ): array {
        return [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-TRA-001',
            'fecha_documento' => '2026-07-24',
            'detalles' => [
                [
                    'item_material_id' => $entradaPrincipal->id,
                    'cantidad_documental' => 80,
                    'cantidad_recibida' => 80,
                    'cantidad_rechazada' => 0,
                    'bultos' => [['cantidad' => 80, 'lote_proveedor' => 'MP-001']],
                ],
                [
                    'item_material_id' => $entradaAuxiliar->id,
                    'cantidad_documental' => 10,
                    'cantidad_recibida' => 10,
                    'cantidad_rechazada' => 0,
                    'bultos' => [['cantidad' => 10, 'lote_proveedor' => 'INS-001']],
                ],
            ],
        ];
    }

    private function payloadUbicacion(
        string $numeroFolio,
        Posicion $posicion,
        string $sesion,
        ItemMaterial $item,
        int $version,
    ): array {
        return [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $numeroFolio,
            'tipo_bulto' => 'material',
            'posicion_destino_id' => $posicion->id,
            'sesion_destino_id' => $sesion,
            'version_destino_conocida' => $version,
            'generado_dispositivo_at' => now()->toAtomString(),
            'datos_material' => [
                'item_material_id' => $item->id,
                'cantidad' => 1,
                'lote' => 'IGNORADO-FOLIO-EXISTENTE',
                'proveedor' => 'Proveedor transformación',
            ],
        ];
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
