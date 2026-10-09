<?php

namespace Tests\Feature\Api;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\Dispositivo;
use App\Models\EliminacionRecepcionMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\FolioMaterialLiberado;
use App\Models\ItemMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\PerfilImpresionEtiqueta;
use App\Models\Posicion;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\Temporada;
use App\Models\TrabajoImpresionMaterial;
use App\Models\UbicacionActual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class RecepcionMaterialApiTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\PreparaFotoDocumentoRecepcionMaterial;

    public function test_busqueda_de_folios_para_camat_respeta_prefijo_temporada_y_ubicacion(): void
    {
        [, , , , $item] = $this->prepararCatalogo();
        [, , $token] = $this->crearOperador();
        [$camara, $posicion] = $this->crearCamaraMateriales();
        $otraCamara = Camara::create([
            'codigo' => 'MAT-REC-02',
            'nombre' => 'Otra cámara de materiales',
            'contenido' => ContenidoCamara::Materiales,
        ]);

        $crear = function (string $numero, ?string $temporadaId = null) use ($item): Folio {
            $folio = Folio::create([
                'numero_folio' => $numero,
                'temporada_id' => $temporadaId,
                'tipo_bulto' => 'material',
                'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
                'fecha_ingreso' => now(),
                'activo' => true,
            ]);
            FolioMaterial::create([
                'folio_id' => $folio->id,
                'item_material_id' => $item->id,
                'cantidad_inicial' => 10,
                'cantidad_actual' => 10,
                'cantidad_reservada' => 0,
                'unidad_medida' => 'rollos',
            ]);

            return $folio;
        };

        $disponible = $crear('FGE0000092');
        $sinPosicion = $crear('FGE0000093');
        UbicacionActual::create(['folio_id' => $sinPosicion->id, 'camara_id' => $camara->id, 'ubicado_at' => now()]);
        $ocupado = $crear('FGE0000094');
        UbicacionActual::create(['folio_id' => $ocupado->id, 'camara_id' => $camara->id, 'posicion_id' => $posicion->id, 'ubicado_at' => now()]);
        $otraUbicacion = $crear('FGE0000095');
        UbicacionActual::create(['folio_id' => $otraUbicacion->id, 'camara_id' => $otraCamara->id, 'ubicado_at' => now()]);
        $pasada = Temporada::create(['codigo' => 'TEMP-PASADA-BUSQUEDA', 'nombre' => 'Pasada', 'activa' => false]);
        $crear('FGE0000096', $pasada->id);
        $agotado = $crear('FGE0000097');
        $agotado->material->update(['cantidad_actual' => 0]);

        $url = '/api/movimientos/folios-materiales-disponibles?'.http_build_query([
            'prefijo' => 'fge000', 'camara_id' => $camara->id,
        ]);
        $this->withToken($token)->getJson($url)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.numero_folio', $disponible->numero_folio)
            ->assertJsonPath('data.1.numero_folio', $sinPosicion->numero_folio);

        $this->withToken($token)->getJson($url.'&solo_camara=1')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', $disponible->numero_folio);
        $this->withToken($token)->getJson('/api/movimientos/folios-materiales-disponibles?'.http_build_query([
            'prefijo' => 'FGE%', 'camara_id' => $camara->id,
        ]))->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/movimientos/folios-materiales-disponibles?prefijo=F&camara_id='.$camara->id)
            ->assertUnprocessable();
        $otroUsuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->actingAs($otroUsuario)->getJson($url)->assertForbidden();
    }

    public function test_bandeja_muestra_solo_temporada_activa_y_permite_consultar_historial_explicito(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $temporadaAnterior = Temporada::query()->where('activa', true)->firstOrFail();
        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente, $proveedor, $item, [['cantidad' => 1, 'lote_proveedor' => 'ANT-01']],
            ))
            ->assertCreated()->json('data');

        $this->conToken($token)->getJson('/api/materiales/recepciones')
            ->assertOk()->assertJsonPath('data.0.id', $recepcion['id']);

        $temporadaAnterior->update(['activa' => false]);
        $temporadaNueva = Temporada::create([
            'codigo' => 'TEMP-TRAZA-MAT',
            'nombre' => 'Temporada siguiente',
            'activa' => true,
        ]);
        $this->conToken($token)->getJson('/api/materiales/recepciones')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->conToken($token)->getJson('/api/materiales/recepciones?temporada_id='.$temporadaAnterior->id)
            ->assertOk()->assertJsonPath('data.0.id', $recepcion['id']);

        $temporadaNueva->update(['activa' => false]);
        $this->conToken($token)->getJson('/api/materiales/recepciones')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_confirma_bultos_y_permite_ubicar_pendientes_y_bloqueados(): void
    {
        [$administrador, $tokenOficina, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        [, , $tokenTablet] = $this->crearOperador();
        $payload = $this->payloadRecepcion($cliente, $proveedor, $item, [
            [
                'cantidad' => 6,
                'lote_proveedor' => 'L-REC-01',
            ],
            [
                'cantidad' => 4,
                'lote_proveedor' => 'L-REC-02',
                'bloqueado' => true,
                'motivo_bloqueo' => 'Pendiente de control de calidad.',
            ],
        ]);
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonCount(2, 'data.detalles.0.bultos')
            ->assertJsonPath('data.eventos.0.tipo', 'creada')
            ->json('data');

        $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $recepcion['id']);
        $this->assertSame(1, RecepcionMaterial::query()
            ->where('operacion_id', $payload['operacion_id'])
            ->count());

        $this->conToken($tokenTablet)
            ->getJson("/api/materiales/recepciones/{$recepcion['id']}")
            ->assertNotFound();

        $operacionConfirmacion = (string) Str::uuid();
        $confirmada = $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => $operacionConfirmacion,
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001')
            ->assertJsonPath('data.detalles.0.bultos.0.folio.estado_operacional', 'pendiente_ubicacion')
            ->assertJsonPath('data.detalles.0.bultos.1.folio.numero_folio', 'FGE0000002')
            ->assertJsonPath('data.detalles.0.bultos.1.folio.estado_operacional', 'bloqueado')
            ->assertJsonPath('data.eventos.1.tipo', 'confirmada')
            ->json('data');

        $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => $operacionConfirmacion,
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $recepcion['id']);
        $this->assertSame(2, Folio::query()
            ->whereIn('numero_folio', ['FGE0000001', 'FGE0000002'])
            ->count());

        $this->conToken($tokenTablet)
            ->getJson("/api/materiales/recepciones/{$recepcion['id']}")
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada');
        $this->conToken($tokenTablet)
            ->getJson('/api/materiales/recepciones/folios-pendientes')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        foreach (['FGE0000001', 'FGE0000002'] as $numeroFolio) {
            $this->conToken($tokenTablet)
                ->getJson('/api/movimientos/consultar-folio?numero_folio='.$numeroFolio)
                ->assertOk()
                ->assertJsonPath('data.disponible_ubicacion', true);
        }

        [$camara, $posicionUno, $posicionDos] = $this->crearCamaraMateriales();
        $sesion = $this->conToken($tokenTablet)
            ->postJson("/api/camaras/{$camara->id}/sesiones")
            ->assertCreated()
            ->json('data.id');

        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                'FGE0000001',
                $posicionUno,
                $sesion,
                $item,
                0,
            ))
            ->assertOk();
        $this->conToken($tokenTablet)
            ->postJson('/api/movimientos/ubicar', $this->payloadUbicacion(
                'FGE0000002',
                $posicionDos,
                $sesion,
                $item,
                1,
            ))
            ->assertOk();

        $this->assertSame(
            EstadoOperacionalFolio::Disponible,
            Folio::query()->where('numero_folio', 'FGE0000001')->firstOrFail()->estado_operacional,
        );
        $this->assertSame(
            EstadoOperacionalFolio::Bloqueado,
            Folio::query()->where('numero_folio', 'FGE0000002')->firstOrFail()->estado_operacional,
        );
        $this->assertDatabaseHas('folios_materiales', [
            'folio_id' => $confirmada['detalles'][0]['bultos'][0]['folio']['id'],
            'cantidad_actual' => 6,
        ]);

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/recepciones/{$recepcion['id']}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Intento posterior a la ubicación.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertSame($administrador->id, RecepcionMaterial::findOrFail($recepcion['id'])->creado_por_user_id);
    }

    public function test_borrador_puede_reabrirse_actualizarse_y_confirmarse(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $creada = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [
                    ['cantidad' => 6, 'lote_proveedor' => 'LOTE-ORIGINAL-01'],
                    ['cantidad' => 4, 'lote_proveedor' => 'LOTE-ORIGINAL-02'],
                ],
            ))
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.version', 1)
            ->json('data');
        $detalleAnterior = $creada['detalles'][0]['id'];
        $bultosAnteriores = collect($creada['detalles'][0]['bultos'])->pluck('id')->all();
        $actualizacion = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $item,
            [['cantidad' => 7, 'lote_proveedor' => 'LOTE-CORREGIDO-01']],
        );
        $actualizacion['version_conocida'] = 1;
        $actualizacion['numero_guia_despacho'] = 'GD-REC-EDITADA';
        $actualizacion['observacion'] = 'Borrador corregido antes de crear inventario.';
        $actualizacion['detalles'][0]['cantidad_documental'] = 8;
        $actualizacion['detalles'][0]['cantidad_contada'] = 8;
        $actualizacion['detalles'][0]['cantidad_aceptada'] = 7;
        $actualizacion['detalles'][0]['cantidad_recibida'] = 7;
        $actualizacion['detalles'][0]['cantidad_rechazada'] = 1;

        $editada = $this->conToken($token)
            ->putJson("/api/materiales/recepciones/{$creada['id']}", $actualizacion)
            ->assertOk()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.numero_guia_despacho', 'GD-REC-EDITADA')
            ->assertJsonPath('data.observacion', 'Borrador corregido antes de crear inventario.')
            ->assertJsonPath('data.detalles.0.cantidad_documental', '8.000')
            ->assertJsonPath('data.detalles.0.cantidad_contada', '8.000')
            ->assertJsonPath('data.detalles.0.cantidad_aceptada', '7.000')
            ->assertJsonPath('data.detalles.0.cantidad_rechazada', '1.000')
            ->assertJsonCount(1, 'data.detalles.0.bultos')
            ->assertJsonPath('data.detalles.0.bultos.0.lote_proveedor', 'LOTE-CORREGIDO-01')
            ->assertJsonPath('data.eventos.1.tipo', 'actualizada')
            ->json('data');

        $this->conToken($token)
            ->putJson("/api/materiales/recepciones/{$creada['id']}", $actualizacion)
            ->assertOk()
            ->assertJsonPath('data.version', 2);
        $this->assertNotNull(DB::table('detalles_recepciones_materiales')
            ->where('id', $detalleAnterior)
            ->value('deleted_at'));
        foreach ($bultosAnteriores as $bultoId) {
            $this->assertNotNull(DB::table('bultos_recepciones_materiales')
                ->where('id', $bultoId)
                ->value('deleted_at'));
        }
        $this->assertSame(1, DB::table('detalles_recepciones_materiales')
            ->where('recepcion_material_id', $creada['id'])
            ->whereNull('deleted_at')
            ->count());
        $this->assertSame(1, DB::table('bultos_recepciones_materiales')
            ->whereNull('deleted_at')
            ->count());

        $actualizacionObsoleta = $actualizacion;
        $actualizacionObsoleta['operacion_id'] = (string) Str::uuid();
        $actualizacionObsoleta['numero_guia_despacho'] = 'GD-REC-OBSOLETA';
        $this->conToken($token)
            ->putJson("/api/materiales/recepciones/{$creada['id']}", $actualizacionObsoleta)
            ->assertConflict();

        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$creada['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $editada['version'],
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.version', 3)
            ->assertJsonCount(1, 'data.detalles.0.bultos')
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001');
        $this->assertSame(1, Folio::query()
            ->where('origen_sistema', 'recepcion_materiales')
            ->count());
        $this->assertDatabaseHas('folios_materiales', [
            'cantidad_inicial' => 7,
            'lote' => 'LOTE-CORREGIDO-01',
        ]);
        $this->assertDatabaseCount('eventos_recepciones_materiales', 3);
    }

    public function test_permite_el_mismo_item_en_lineas_separadas_y_genera_folios_independientes(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $payload = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $item,
            [['cantidad' => 60, 'lote_proveedor' => 'BULTO-ALTO']],
        );
        $payload['numero_guia_despacho'] = 'GD-ITEM-REPETIDO';
        $payload['detalles'][] = [
            'item_material_id' => $item->id,
            'cantidad_documental' => 40,
            'cantidad_contada' => 40,
            'cantidad_aceptada' => 40,
            'cantidad_recibida' => 40,
            'cantidad_rechazada' => 0,
            'observacion' => 'Bulto de menor altura.',
            'bultos' => [[
                'cantidad' => 40,
                'lote_proveedor' => 'BULTO-BAJO',
            ]],
        ];

        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->assertJsonCount(2, 'data.detalles')
            ->assertJsonPath('data.detalles.0.item.id', $item->id)
            ->assertJsonPath('data.detalles.1.item.id', $item->id)
            ->assertJsonPath('data.detalles.0.bultos.0.cantidad', '60.000')
            ->assertJsonPath('data.detalles.1.bultos.0.cantidad', '40.000')
            ->json('data');

        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001')
            ->assertJsonPath('data.detalles.1.bultos.0.folio.numero_folio', 'FGE0000002');

        $this->assertSame(2, DB::table('detalles_recepciones_materiales')
            ->where('recepcion_material_id', $recepcion['id'])
            ->where('item_material_id', $item->id)
            ->whereNull('deleted_at')
            ->count());
        $this->assertSame(2, FolioMaterial::query()
            ->where('item_material_id', $item->id)
            ->whereNotNull('bulto_recepcion_material_id')
            ->count());
    }

    public function test_anulacion_intacta_compensa_saldos_y_es_idempotente(): void
    {
        [, $tokenOficina, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $payload = $this->payloadRecepcion($cliente, $proveedor, $item, [
            ['cantidad' => 5, 'lote_proveedor' => 'L-ANU-01'],
        ]);
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->json('data');
        $this->conToken($tokenOficina)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk();

        $folio = Folio::query()->where('numero_folio', 'FGE0000001')->firstOrFail();
        $operacionAnulacion = (string) Str::uuid();
        $anulada = [
            'operacion_id' => $operacionAnulacion,
            'motivo' => 'Guía rechazada por el proveedor.',
        ];
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/recepciones/{$recepcion['id']}/anular", $anulada)
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulada')
            ->assertJsonPath('data.eventos.2.tipo', 'anulada');
        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/recepciones/{$recepcion['id']}/anular", $anulada)
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulada');

        $material = FolioMaterial::query()->findOrFail($folio->id);
        $this->assertSame('0.000', $material->cantidad_actual);
        $this->assertFalse($folio->refresh()->activo);
        $this->assertSame(2, MovimientoInventarioMaterial::query()
            ->where('folio_id', $folio->id)
            ->count());
        $this->assertDatabaseHas('movimientos_inventario_materiales', [
            'folio_id' => $folio->id,
            'tipo' => 'anulacion_recepcion',
            'cantidad' => -5,
            'cantidad_resultante' => 0,
        ]);
        $this->assertDatabaseCount('eventos_recepciones_materiales', 3);

        $this->conToken($tokenOficina)
            ->postJson("/api/materiales/recepciones/{$recepcion['id']}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => $anulada['motivo'],
            ])
            ->assertConflict();
    }

    public function test_camarero_crea_y_confirma_su_recepcion_pero_no_puede_anularla(): void
    {
        [, , $cliente, $proveedor, $item] = $this->prepararCatalogo();
        [, , $tokenCamarero] = $this->crearOperador();
        $payload = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $item,
            [['cantidad' => 4, 'lote_proveedor' => 'L-CAM-01']],
        );

        $recepcion = $this->conToken($tokenCamarero)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'borrador')
            ->json('data');
        $this->conToken($tokenCamarero)
            ->getJson("/api/materiales/recepciones/{$recepcion['id']}")
            ->assertOk();
        $this->conToken($tokenCamarero)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001');
        $this->conToken($tokenCamarero)
            ->postJson("/api/materiales/recepciones/{$recepcion['id']}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Intento sin atribución de supervisión.',
            ])
            ->assertForbidden();
    }

    public function test_concilia_contado_aceptado_y_rechazado_y_rechaza_inconsistencias(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-CONCILIADA-001',
            'detalles' => [[
                'item_material_id' => $item->id,
                'cantidad_documental' => 12,
                'cantidad_contada' => 10,
                'cantidad_aceptada' => 7,
                'cantidad_rechazada' => 3,
                'bultos' => [['cantidad' => 7]],
            ]],
        ];
        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->assertJsonPath('data.detalles.0.cantidad_documental', '12.000')
            ->assertJsonPath('data.detalles.0.cantidad_contada', '10.000')
            ->assertJsonPath('data.detalles.0.cantidad_aceptada', '7.000')
            ->assertJsonPath('data.detalles.0.cantidad_recibida', '7.000')
            ->assertJsonPath('data.detalles.0.cantidad_rechazada', '3.000')
            ->json('data');

        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.detalles.0.bultos');
        $this->assertDatabaseHas('folios_materiales', [
            'cantidad_inicial' => 7,
            'cantidad_actual' => 7,
        ]);

        $inconsistente = $payload;
        $inconsistente['operacion_id'] = (string) Str::uuid();
        $inconsistente['numero_guia_despacho'] = 'GD-INCONSISTENTE-001';
        $inconsistente['detalles'][0]['cantidad_contada'] = 11;
        $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $inconsistente)
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
    }

    /**
     * @param  array<string, int|string>  $cantidadAceptada
     */
    #[DataProvider('cantidadesCeroAceptadas')]
    public function test_confirma_rechazo_total_sin_generar_folios(
        array $cantidadAceptada,
    ): void {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-RECHAZO-TOTAL-001',
            'detalles' => [[
                'item_material_id' => $item->id,
                'cantidad_documental' => 5,
                'cantidad_contada' => 5,
                ...$cantidadAceptada,
                'cantidad_rechazada' => 5,
                'bultos' => [],
            ]],
        ];
        $rechazada = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $payload)
            ->assertCreated()
            ->assertJsonPath('data.detalles.0.cantidad_contada', '5.000')
            ->assertJsonPath('data.detalles.0.cantidad_aceptada', '0.000')
            ->assertJsonPath('data.detalles.0.cantidad_recibida', '0.000')
            ->assertJsonPath('data.detalles.0.cantidad_rechazada', '5.000')
            ->assertJsonCount(0, 'data.detalles.0.bultos')
            ->json('data');
        $foliosAntes = Folio::query()->where('tipo_bulto', 'material')->count();
        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$rechazada['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonCount(0, 'data.snapshot_confirmacion.folios');
        $this->assertSame(
            $foliosAntes,
            Folio::query()->where('tipo_bulto', 'material')->count(),
        );
    }

    /**
     * @return array<string, array{array<string, int|string>}>
     */
    public static function cantidadesCeroAceptadas(): array
    {
        return [
            'cantidad aceptada numérica' => [['cantidad_aceptada' => 0]],
            'cantidad aceptada textual' => [['cantidad_aceptada' => '0']],
            'alias legado cantidad recibida' => [['cantidad_recibida' => '0']],
        ];
    }

    public function test_proveedor_solo_puede_recibir_items_de_categorias_habilitadas(): void
    {
        [$administrador, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $noAutorizado = ItemMaterial::create([
            'cliente_material_id' => $item->cliente_material_id,
            'codigo' => 'QUIM-REC',
            'nombre' => 'Químico no autorizado',
            'categoria' => 'Químicos',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'litros',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);

        $this->conToken($token)
            ->getJson('/api/materiales/recepciones/catalogos')
            ->assertOk()
            ->assertJsonPath('proveedores.0.categorias.0.categoria', 'Embalaje');

        $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $noAutorizado,
                [['cantidad' => 1]],
            ))
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
    }

    public function test_confirmacion_revalida_categorias_revocadas_despues_del_borrador(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 1]],
            ))
            ->assertCreated()
            ->json('data');

        DB::table('clientes_proveedores_materiales')
            ->where('cliente_id', $cliente->id)
            ->where('proveedor_material_id', $proveedor->id)
            ->update([
                'categorias' => json_encode(['Categoría revocada'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseHas('recepciones_materiales', [
            'id' => $recepcion['id'],
            'estado' => 'borrador',
            'version' => 1,
        ]);
        $this->assertDatabaseMissing('eventos_recepciones_materiales', [
            'recepcion_material_id' => $recepcion['id'],
            'tipo' => 'confirmada',
        ]);
    }

    public function test_genera_etiquetas_zpl_y_pdf_con_idempotencia_y_auditoria_de_reimpresion(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 5, 'lote_proveedor' => 'LOTE-ETIQUETA-01']],
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folio = $confirmada['detalles'][0]['bultos'][0]['folio'];
        $perfil = PerfilImpresionEtiqueta::query()
            ->where('predeterminado', true)
            ->firstOrFail();
        $perfil->update(['orientacion' => 'vertical']);
        $operacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacion,
            'perfil_id' => $perfil->id,
            'formato' => 'zpl',
            'canal' => 'oficina_descarga',
            'folio_ids' => [$folio['id']],
            'copias' => 1,
        ];

        $zpl = $this->conToken($token)
            ->post(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                $payload,
                ['Accept' => 'application/zpl'],
            )
            ->assertOk()
            ->assertHeader('content-type', 'application/zpl')
            ->assertSee('^XA', escape: false)
            ->assertSee('^PW400', escape: false)
            ->assertSee('^LL799', escape: false)
            ->assertSee('FGE0000001', escape: false);
        $trabajoId = $zpl->headers->get('X-Estiba-Print-Job');

        $this->conToken($token)
            ->post(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                $payload,
                ['Accept' => 'application/zpl'],
            )
            ->assertOk()
            ->assertHeader('X-Estiba-Print-Job', $trabajoId);
        $this->assertDatabaseCount('trabajos_impresion_materiales', 1);
        $this->assertDatabaseHas('folios_trabajos_impresion_materiales', [
            'trabajo_impresion_material_id' => $trabajoId,
            'folio_id' => $folio['id'],
            'numero_folio_snapshot' => 'FGE0000001',
            'es_reimpresion' => false,
        ]);
        $this->assertDatabaseHas('trabajos_impresion_materiales', [
            'id' => $trabajoId,
            'formato' => 'zpl',
            'simbologia' => 'code128',
        ]);

        $reimpresion = [
            ...$payload,
            'operacion_id' => (string) Str::uuid(),
            'formato' => 'pdf',
        ];
        $this->conToken($token)
            ->post(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                $reimpresion,
                ['Accept' => 'application/pdf'],
            )
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $reimpresion['operacion_id'] = (string) Str::uuid();
        $reimpresion['motivo_reimpresion'] = 'Etiqueta dañada durante la instalación.';
        $pdf = $this->conToken($token)
            ->post(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                $reimpresion,
                ['Accept' => 'application/pdf'],
            )
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf->getContent());
        $this->assertDatabaseHas('folios_trabajos_impresion_materiales', [
            'trabajo_impresion_material_id' => $pdf->headers->get('X-Estiba-Print-Job'),
            'folio_id' => $folio['id'],
            'es_reimpresion' => true,
        ]);
        $this->assertSame(2, TrabajoImpresionMaterial::query()->count());

        $this->conToken($token)
            ->getJson("/api/materiales/recepciones/{$recepcion['id']}/impresiones")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.motivo_reimpresion', 'Etiqueta dañada durante la instalación.');
    }

    public function test_genera_nlbl_nativo_con_qr_para_zebra_y_nicelabel(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $recepcion = $this->travelTo(
            Carbon::parse('2026-07-28 08:15:00'),
            fn () => $this->conToken($token)
                ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                    $cliente,
                    $proveedor,
                    $item,
                    [['cantidad' => 5, 'lote_proveedor' => 'LOTE-NLBL-01']],
                ))
                ->assertCreated()
                ->json('data'),
        );
        $fechaBorrador = RecepcionMaterial::findOrFail($recepcion['id'])
            ->created_at
            ->format('d/m/Y H:i');
        $confirmada = $this->travelTo(
            Carbon::parse('2026-07-29 13:14:00'),
            fn () => $this->conToken($token)
                ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                    'operacion_id' => (string) Str::uuid(),
                    'version_conocida' => 1,
                ])
                ->assertOk()
                ->json('data'),
        );
        $folio = $confirmada['detalles'][0]['bultos'][0]['folio'];
        $perfil = PerfilImpresionEtiqueta::query()
            ->where('codigo', 'ZEB-ZT231-203')
            ->firstOrFail();

        $respuesta = $this->travelTo(
            Carbon::parse('2026-07-30 16:45:00'),
            fn () => $this->conToken($token)
                ->post(
                    "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                    [
                        'operacion_id' => (string) Str::uuid(),
                        'perfil_id' => $perfil->id,
                        'formato' => 'nlbl',
                        'simbologia' => 'qr',
                        'canal' => 'oficina_descarga',
                        'folio_ids' => [$folio['id']],
                        'copias' => 1,
                    ],
                    ['Accept' => 'application/octet-stream'],
                )
                ->assertOk()
                ->assertHeader('content-type', 'application/octet-stream')
                ->assertHeader(
                    'content-disposition',
                    'attachment; filename="etiquetas-GD-REC-001.nlbl"',
                ),
        );

        $this->assertStringStartsWith("PK\x03\x04", $respuesta->getContent());
        $this->assertStringContainsString('Formats/FGE0000001', $respuesta->getContent());
        $this->assertStringContainsString('Etiquetas FoliOS.slnx', $respuesta->getContent());
        $trabajoId = $respuesta->headers->get('X-Estiba-Print-Job');
        $this->assertDatabaseHas('trabajos_impresion_materiales', [
            'id' => $trabajoId,
            'formato' => 'nlbl',
            'simbologia' => 'qr',
        ]);
        $trabajo = TrabajoImpresionMaterial::findOrFail($trabajoId);
        $fechaEtiqueta = $trabajo->contenido_snapshot[0]['fecha_recepcion'];
        $this->assertSame($fechaBorrador, $fechaEtiqueta);
        $this->assertNotSame(
            Folio::findOrFail($folio['id'])->fecha_ingreso->format('d/m/Y H:i'),
            $fechaEtiqueta,
        );
        $this->assertNotSame(
            $trabajo->solicitado_at->format('d/m/Y H:i'),
            $fechaEtiqueta,
        );

        $this->conToken($token)
            ->post(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                [
                    'operacion_id' => (string) Str::uuid(),
                    'perfil_id' => $perfil->id,
                    'formato' => 'zpl',
                    'simbologia' => 'qr',
                    'canal' => 'oficina_descarga',
                    'folio_ids' => [$folio['id']],
                    'copias' => 1,
                    'motivo_reimpresion' => 'Validación del flujo directo con código QR.',
                ],
                ['Accept' => 'application/zpl'],
            )
            ->assertOk()
            ->assertSee('^BQN,2,', escape: false)
            ->assertSee('FGE0000001', escape: false);
    }

    public function test_administra_perfiles_globales_y_un_usuario_de_consulta_no_puede_imprimir(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $perfil = $this->conToken($token)
            ->postJson('/api/administracion/etiquetas/materiales/perfiles', [
                'codigo' => 'BIX-SLP-TX400-203-100X200',
                'nombre' => 'Bixolon SLP-TX400 · 100 × 200 mm',
                'fabricante' => 'Bixolon',
                'modelo' => 'SLP-TX400',
                'lenguaje' => 'bpl-z',
                'dpi' => 203,
                'ancho_mm' => 100,
                'alto_mm' => 200,
                'orientacion' => 'vertical',
                'predeterminado' => true,
                'activo' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.predeterminado', true)
            ->json('data');
        $this->assertDatabaseHas('perfiles_impresion_etiquetas', [
            'id' => $perfil['id'],
            'fabricante' => 'Bixolon',
            'lenguaje' => 'bpl-z',
            'dpi' => 203,
            'ancho_mm' => 100,
            'alto_mm' => 200,
            'orientacion' => 'vertical',
        ]);
        $this->assertSame(1, PerfilImpresionEtiqueta::query()
            ->where('predeterminado', true)
            ->count());

        $this->conToken($token)
            ->postJson('/api/administracion/etiquetas/materiales/perfiles', [
                'codigo' => 'BIX-SLP-TX400-203-100X201',
                'nombre' => 'Bixolon SLP-TX400 · 100 × 201 mm',
                'fabricante' => 'Bixolon',
                'modelo' => 'SLP-TX400',
                'lenguaje' => 'bpl-z',
                'dpi' => 203,
                'ancho_mm' => 100,
                'alto_mm' => 201,
                'orientacion' => 'vertical',
                'predeterminado' => false,
                'activo' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.alto_mm.0', 'El alto debe estar entre 20 y 200 mm.');

        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 1]],
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $consulta = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'activo' => true,
        ])->createToken('consulta', ['oficina'])->plainTextToken;

        $this->conToken($consulta)
            ->getJson('/api/materiales/recepciones/perfiles-impresion')
            ->assertOk();
        $this->conToken($consulta)
            ->postJson("/api/materiales/recepciones/{$recepcion['id']}/etiquetas", [
                'operacion_id' => (string) Str::uuid(),
                'perfil_id' => $perfil['id'],
                'formato' => 'zpl',
                'canal' => 'oficina_descarga',
                'folio_ids' => [$confirmada['detalles'][0]['bultos'][0]['folio']['id']],
                'copias' => 1,
            ])
            ->assertForbidden();
    }

    public function test_tablet_informa_resultado_directo_sin_reintentar_un_estado_indeterminado(): void
    {
        [, $tokenOficina, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        [, $dispositivo, $tokenTablet] = $this->crearOperador();
        $recepcion = $this->conToken($tokenOficina)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 2, 'lote_proveedor' => 'LOTE-IP-01']],
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
        $perfil = PerfilImpresionEtiqueta::query()->where('predeterminado', true)->firstOrFail();
        $folioId = $confirmada['detalles'][0]['bultos'][0]['folio']['id'];
        $payloadDirecto = [
            'operacion_id' => (string) Str::uuid(),
            'perfil_id' => $perfil->id,
            'formato' => 'zpl',
            'canal' => 'pda_directa',
            'folio_ids' => [$folioId],
            'copias' => 1,
        ];
        $this->conToken($tokenOficina)
            ->postJson(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                $payloadDirecto,
            )
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');
        $this->conToken($tokenTablet)
            ->postJson(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                [
                    ...$payloadDirecto,
                    'operacion_id' => (string) Str::uuid(),
                    'formato' => 'pdf',
                ],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('formato');
        $generado = $this->conToken($tokenTablet)
            ->post(
                "/api/materiales/recepciones/{$recepcion['id']}/etiquetas",
                [
                    ...$payloadDirecto,
                    'operacion_id' => (string) Str::uuid(),
                ],
                ['Accept' => 'application/zpl'],
            )
            ->assertOk();
        $trabajoId = $generado->headers->get('X-Estiba-Print-Job');
        $operacionResultado = (string) Str::uuid();
        $resultado = [
            'operacion_id' => $operacionResultado,
            'estado' => 'indeterminado',
            'bytes_enviados' => 0,
            'error' => 'La conexión se cerró después de iniciar la escritura.',
            'impresora' => [
                'nombre' => 'Zebra patio',
                'host' => '192.168.10.25',
                'puerto' => 9100,
            ],
        ];

        $this->conToken($tokenTablet)
            ->postJson(
                "/api/materiales/recepciones/trabajos-impresion/{$trabajoId}/resultado",
                $resultado,
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'indeterminado');
        $this->conToken($tokenTablet)
            ->postJson(
                "/api/materiales/recepciones/trabajos-impresion/{$trabajoId}/resultado",
                $resultado,
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'indeterminado');
        $this->assertDatabaseHas('trabajos_impresion_materiales', [
            'id' => $trabajoId,
            'estado' => 'indeterminado',
            'dispositivo_id' => $dispositivo->id,
            'resultado_operacion_id' => $operacionResultado,
            'bytes_enviados' => 0,
        ]);

        $this->conToken($tokenTablet)
            ->postJson(
                "/api/materiales/recepciones/trabajos-impresion/{$trabajoId}/resultado",
                [
                    ...$resultado,
                    'operacion_id' => (string) Str::uuid(),
                    'estado' => 'enviado',
                    'error' => null,
                ],
            )
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');
        $this->conToken($tokenOficina)
            ->postJson(
                "/api/materiales/recepciones/trabajos-impresion/{$trabajoId}/resultado",
                $resultado,
            )
            ->assertNotFound();
    }

    public function test_administrador_corrige_confirmada_y_reutiliza_sus_folios(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $creada = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [
                    ['cantidad' => 6, 'lote_proveedor' => 'LOTE-01'],
                    ['cantidad' => 4, 'lote_proveedor' => 'LOTE-02'],
                ],
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$creada['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001')
            ->assertJsonPath('data.detalles.0.bultos.1.folio.numero_folio', 'FGE0000002')
            ->json('data');
        $correccion = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $item,
            [['cantidad' => 10, 'lote_proveedor' => 'LOTE-CORREGIDO']],
        );
        $correccion['version_conocida'] = $confirmada['version'];
        $correccion['numero_guia_despacho'] = 'GD-REC-CORREGIDA';
        $correccion['motivo_correccion'] = 'Se corrige la distribución de bultos digitada por error.';
        $correccion['confirmacion_operacion_id'] = (string) Str::uuid();

        $corregida = $this->conToken($token)
            ->putJson(
                "/api/materiales/recepciones/{$creada['id']}/administrar",
                $correccion,
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.numero_guia_despacho', 'GD-REC-CORREGIDA')
            ->assertJsonCount(1, 'data.detalles.0.bultos')
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001')
            ->json('data');

        $this->assertDatabaseHas('folios_materiales_liberados', [
            'cliente_id' => $cliente->id,
            'numero_folio' => 'FGE0000002',
        ]);
        $this->assertDatabaseHas('eventos_recepciones_materiales', [
            'recepcion_material_id' => $creada['id'],
            'tipo' => 'corregida_administrativamente',
        ]);

        $siguiente = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $item,
            [['cantidad' => 2, 'lote_proveedor' => 'LOTE-SIGUIENTE']],
        );
        $siguiente['numero_guia_despacho'] = 'GD-REC-SIGUIENTE';
        $nueva = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $siguiente)
            ->assertCreated()
            ->json('data');
        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$nueva['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000002');

        $this->assertNull(FolioMaterialLiberado::query()
            ->where('numero_folio', 'FGE0000002')
            ->first());
        $this->assertSame('FGE0000001', $corregida['detalles'][0]['bultos'][0]['folio']['numero_folio']);
    }

    public function test_administrador_elimina_recepcion_y_siguiente_ingreso_reutiliza_folio(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $creada = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 10]],
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$creada['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001')
            ->json('data');

        $this->conToken($token)
            ->deleteJson("/api/materiales/recepciones/{$creada['id']}", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $confirmada['version'],
                'motivo' => 'La guía fue digitada con un número equivocado.',
            ])
            ->assertOk()
            ->assertJsonPath('data.recepcion_id', $creada['id'])
            ->assertJsonPath('data.folios_liberados.0', 'FGE0000001');

        $this->assertDatabaseMissing('recepciones_materiales', ['id' => $creada['id']]);
        $this->assertDatabaseMissing('folios', ['numero_folio' => 'FGE0000001']);
        $this->assertDatabaseHas('folios_materiales_liberados', [
            'cliente_id' => $cliente->id,
            'numero_folio' => 'FGE0000001',
        ]);
        $auditoria = EliminacionRecepcionMaterial::query()->firstOrFail();
        $this->assertSame($creada['id'], $auditoria->recepcion_material_id_original);
        $this->assertSame(['FGE0000001'], $auditoria->folios);
        $this->assertSame('GD-REC-001', data_get($auditoria->snapshot, 'cabecera.numero_guia_despacho'));

        $reemplazo = $this->payloadRecepcion(
            $cliente,
            $proveedor,
            $item,
            [['cantidad' => 10]],
        );
        $reemplazo['numero_guia_despacho'] = 'GD-REC-REEMPLAZO';
        $nueva = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $reemplazo)
            ->assertCreated()
            ->json('data');
        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$nueva['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.detalles.0.bultos.0.folio.numero_folio', 'FGE0000001');
    }

    public function test_solo_administrador_puede_corregir_o_eliminar_recepciones(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $creada = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 10]],
            ))
            ->assertCreated()
            ->json('data');
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorMateriales,
            'activo' => true,
        ]);
        $tokenSupervisor = $supervisor->createToken('oficina-supervisor', ['oficina'])->plainTextToken;

        $this->conToken($tokenSupervisor)
            ->putJson("/api/materiales/recepciones/{$creada['id']}/administrar", [])
            ->assertForbidden();
        $this->conToken($tokenSupervisor)
            ->deleteJson("/api/materiales/recepciones/{$creada['id']}", [])
            ->assertForbidden();
        $this->assertDatabaseHas('recepciones_materiales', ['id' => $creada['id']]);
    }

    public function test_no_elimina_recepcion_si_un_folio_tiene_actividad_posterior(): void
    {
        [$administrador, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $creada = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [['cantidad' => 10]],
            ))
            ->assertCreated()
            ->json('data');
        $confirmada = $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$creada['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk()
            ->json('data');
        $folioId = $confirmada['detalles'][0]['bultos'][0]['folio']['id'];
        DB::table('eventos_bloqueos_materiales')->insert([
            'id' => (string) Str::uuid(),
            'operacion_id' => (string) Str::uuid(),
            'folio_id' => $folioId,
            'tipo' => 'bloqueo',
            'estado_anterior' => 'pendiente_ubicacion',
            'estado_resultante' => 'bloqueado',
            'motivo' => 'Actividad posterior de control.',
            'user_id' => $administrador->id,
            'ocurrido_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->conToken($token)
            ->deleteJson("/api/materiales/recepciones/{$creada['id']}", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => $confirmada['version'],
                'motivo' => 'Intento administrativo que debe bloquearse.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseHas('recepciones_materiales', ['id' => $creada['id']]);
        $this->assertDatabaseHas('folios', ['id' => $folioId]);
        $this->assertDatabaseCount('folios_materiales_liberados', 0);
    }

    public function test_genera_registro_de_muestreo_excel_solo_para_recepcion_confirmada(): void
    {
        [, $token, $cliente, $proveedor, $item] = $this->prepararCatalogo();
        $recepcion = $this->conToken($token)
            ->postJson('/api/materiales/recepciones', $this->payloadRecepcion(
                $cliente,
                $proveedor,
                $item,
                [
                    ['cantidad' => 6, 'lote_proveedor' => 'LOTE-MUESTRA-01'],
                    ['cantidad' => 4, 'lote_proveedor' => 'LOTE-MUESTRA-02'],
                ],
            ))
            ->assertCreated()
            ->json('data');

        $this->conToken($token)
            ->get("/api/materiales/recepciones/{$recepcion['id']}/registro-muestreo")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El registro de muestreo solo está disponible para recepciones confirmadas.',
            );

        $this->conToken($token)
            ->postJsonConDocumento("/api/materiales/recepciones/{$recepcion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'version_conocida' => 1,
            ])
            ->assertOk();

        $respuesta = $this->conToken($token)
            ->get("/api/materiales/recepciones/{$recepcion['id']}/registro-muestreo")
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        $this->assertStringContainsString(
            'Registro_Muestreo_GD_REC_001.xlsx',
            (string) $respuesta->headers->get('content-disposition'),
        );

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertIsString($hoja);
        $this->assertStringContainsString('Registro de muestreo de recepción de materiales', $hoja);
        $this->assertStringContainsString('GD-REC-001', $hoja);
        $this->assertStringContainsString('PROV-REC', $hoja);
        $this->assertStringContainsString('FILM-REC', $hoja);
        $this->assertStringContainsString('☒ Proveedor nuevo', $hoja);
        $this->assertStringContainsString('<c r="H12" s="8"><v>1</v></c>', $hoja);
    }

    public function test_descarga_registro_de_muestreo_en_blanco_sin_recepcion(): void
    {
        [, $token] = $this->prepararCatalogo();

        $respuesta = $this->conToken($token)
            ->get('/api/materiales/recepciones/registro-muestreo/en-blanco')
            ->assertOk()
            ->assertDownload('Registro_Muestreo_EN_BLANCO.xlsx')
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $propiedades = $zip->getFromName('docProps/core.xml');
        $zip->close();

        $this->assertIsString($hoja);
        $this->assertIsString($propiedades);
        $this->assertStringContainsString('Registro de muestreo de recepción de materiales', $hoja);
        $this->assertStringContainsString('☐ Proveedor nuevo', $hoja);
        $this->assertStringContainsString('☐ Proveedor con desempeño satisfactorio', $hoja);
        $this->assertStringContainsString('☐ Proveedor con diferencias anteriores', $hoja);
        $this->assertStringNotContainsString('☒', $hoja);
        $this->assertStringContainsString('<c r="C21" s="7"', $hoja);
        $this->assertStringContainsString('en blanco', $propiedades);
        $this->assertStringNotContainsString('PROV-REC', $hoja);
        $this->assertStringNotContainsString('FILM-REC', $hoja);
    }

    private function prepararCatalogo(): array
    {
        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
        $token = $administrador->createToken('oficina-test', ['oficina'])->plainTextToken;
        $catalogo = ClienteMaterial::query()
            ->with(['cliente', 'temporada'])
            ->where('codigo', 'GENERAL')
            ->whereHas('temporada', fn ($consulta) => $consulta->where('activa', true))
            ->firstOrFail();
        $cliente = $catalogo->cliente;
        $cliente->update(['codigo_folio_materiales' => 'GE']);
        $item = ItemMaterial::create([
            'cliente_material_id' => $catalogo->id,
            'codigo' => 'FILM-REC',
            'nombre' => 'Film para recepción',
            'categoria' => 'Embalaje',
            'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'unidad_medida' => 'rollos',
            'origen_sistema' => 'manual',
            'activo' => true,
            'creado_por_user_id' => $administrador->id,
            'actualizado_por_user_id' => $administrador->id,
        ]);
        $proveedor = ProveedorMaterial::create([
            'codigo' => 'PROV-REC',
            'nombre' => 'Proveedor recepción',
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

        return [$administrador, $token, $cliente, $proveedor, $item];
    }

    private function crearOperador(): array
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::CamareroMateriales,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-REC-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet recepción',
            'activo' => true,
        ]);
        $token = $usuario
            ->crearTokenParaDispositivo($dispositivo, 'tablet-recepcion')
            ->plainTextToken;

        return [$usuario, $dispositivo, $token];
    }

    private function crearCamaraMateriales(): array
    {
        $camara = Camara::create([
            'codigo' => 'MAT-REC-01',
            'nombre' => 'Cámara recepción',
            'contenido' => ContenidoCamara::Materiales,
        ]);
        $posiciones = collect([1, 2])->map(fn (int $numero): Posicion => Posicion::create([
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
        ItemMaterial $item,
        array $bultos,
    ): array {
        return [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $cliente->id,
            'proveedor_material_id' => $proveedor->id,
            'numero_guia_despacho' => 'GD-REC-001',
            'fecha_documento' => '2026-07-24',
            'detalles' => [[
                'item_material_id' => $item->id,
                'cantidad_documental' => collect($bultos)->sum('cantidad'),
                'cantidad_recibida' => collect($bultos)->sum('cantidad'),
                'cantidad_rechazada' => 0,
                'bultos' => $bultos,
            ]],
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
                'lote' => 'IGNORADO-PARA-FOLIO-EXISTENTE',
                'proveedor' => 'Proveedor recepción',
            ],
        ];
    }

    private function conToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
