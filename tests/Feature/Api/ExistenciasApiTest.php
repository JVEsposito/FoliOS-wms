<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\ConexionExistencia;
use App\Models\Embarque;
use App\Models\Folio;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Existencias\ServicioExistencias;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ExistenciasApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_ve_las_tres_existencias_y_descarga_un_corte_xlsx(): void
    {
        [, $token] = $this->acceso(RolUsuario::Administrador);

        $this->withToken($token)
            ->getJson('/api/existencias')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonFragment(['tipo' => 'producto-terminado'])
            ->assertJsonFragment(['tipo' => 'despachos-producto-terminado'])
            ->assertJsonFragment(['tipo' => 'materiales'])
            ->assertJsonFragment(['tipo' => 'materia-prima']);

        $respuesta = $this->withToken($token)
            ->get('/api/existencias/producto-terminado/corte');

        $respuesta
            ->assertOk()
            ->assertDownload()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $this->assertStringContainsString(
            'Existencia_Producto_Terminado_',
            (string) $respuesta->headers->get('content-disposition'),
        );
    }

    public function test_consulta_dedicada_filtra_definicion_y_conexiones_por_area(): void
    {
        [, $token] = $this->acceso(RolUsuario::Administrador);

        $this->withToken($token)
            ->getJson('/api/existencias?tipo=materiales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'materiales')
            ->assertJsonMissing(['tipo' => 'producto-terminado'])
            ->assertJsonMissing(['tipo' => 'materia-prima'])
            ->assertJsonCount(0, 'conexiones');
    }

    public function test_producto_aprobado_en_prefrio_queda_pendiente_de_ubicacion_en_existencias(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporada = app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'TEMP-EX-PF',
            'nombre' => 'Temporada existencias Prefrío',
            'activa' => true,
        ], usuarioId: $administrador->id);
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-EX-01',
            'nombre' => 'Túnel existencias',
            'capacidad_posiciones' => 2,
            'setpoint_habitual' => -1.5,
            'creado_por_user_id' => $administrador->id,
        ]);
        $posicion = PosicionTunelPrefrio::create([
            'tunel_prefrio_id' => $tunel->id,
            'numero' => 1,
            'etiqueta' => 'TUN-EX-01-P01',
            'activa' => true,
        ]);
        $folio = Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'PAL-EX-PF-001',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => CondicionTermicaFolio::EnProceso,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'PF-EX-000001',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'proceso-existencias'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => EstadoProcesoPrefrio::EnProceso,
            'setpoint' => -1.5,
            'version' => 3,
            'creado_por_user_id' => $administrador->id,
        ]);
        $asignacion = ProcesoPrefrioFolio::create([
            'proceso_prefrio_id' => $proceso->id,
            'folio_id' => $folio->id,
            'posicion_tunel_prefrio_id' => $posicion->id,
            'estado' => EstadoFolioProcesoPrefrio::EnProceso,
            'cargado_at' => now(),
            'cargado_por_user_id' => $administrador->id,
        ]);

        $filas = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::PRODUCTO_TERMINADO);
        $this->assertInstanceOf(LazyCollection::class, $filas);
        $enPrefrio = $filas->firstWhere('folio', $folio->numero_folio);

        $this->assertSame('En Prefrío', $enPrefrio['etapa_actual']);
        $this->assertSame('TUN-EX-01 · PF-EX-000001', $enPrefrio['tunel_prefrio']);

        $proceso->update([
            'estado' => EstadoProcesoPrefrio::Aprobado,
            'finalizado_por_user_id' => $administrador->id,
            'finalizado_at' => now(),
        ]);
        $asignacion->update(['estado' => EstadoFolioProcesoPrefrio::Aprobado]);
        $folio->update([
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
        ]);

        $pendiente = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::PRODUCTO_TERMINADO)
            ->firstWhere('folio', $folio->numero_folio);

        $this->assertSame('Pendiente de ubicación', $pendiente['estado_operacional']);
        $this->assertSame('Pendiente de ubicación', $pendiente['etapa_actual']);
        $this->assertNull($pendiente['tunel_prefrio']);
        $this->assertNull($pendiente['camara']);
        $this->assertNull($pendiente['posicion']);
    }

    public function test_conexion_excel_es_revocable_y_deja_de_actualizarse(): void
    {
        [, $tokenOficina] = $this->acceso(RolUsuario::Administrador);

        $respuesta = $this->withToken($tokenOficina)
            ->post('/api/existencias/materiales/conexion-excel');

        $respuesta
            ->assertCreated()
            ->assertHeader('content-type', 'application/x-msquery; charset=UTF-8');
        $this->assertStringContainsString("WEB\r\n1\r\n", $respuesta->getContent());
        $this->assertMatchesRegularExpression('/token=([A-Za-z0-9]+)/', $respuesta->getContent());
        preg_match('/token=([A-Za-z0-9]+)/', $respuesta->getContent(), $coincidencias);
        $tokenConsulta = $coincidencias[1];
        $conexion = ConexionExistencia::query()->firstOrFail();

        $respuestaConsulta = $this->get('/api/existencias/materiales/consulta?token='.$tokenConsulta)
            ->assertOk()
            ->assertStreamed();
        $contenidoConsulta = $respuestaConsulta->streamedContent();
        $this->assertStringContainsString('Existencia de materiales', $contenidoConsulta);
        $this->assertStringContainsString('Cantidad disponible en almacén', $contenidoConsulta);
        $this->assertStringContainsString('Centro de costo', $contenidoConsulta);

        $this->withToken($tokenOficina)
            ->postJson("/api/existencias/conexiones/{$conexion->id}/revocar")
            ->assertOk()
            ->assertJsonPath('data.vigente', false);

        $this->get('/api/existencias/materiales/consulta?token='.$tokenConsulta)
            ->assertGone();
    }

    public function test_limita_los_cortes_xlsx_por_usuario(): void
    {
        [, $token] = $this->acceso(RolUsuario::Administrador);

        for ($intento = 1; $intento <= 3; $intento++) {
            $this->withToken($token)
                ->get('/api/existencias/materiales/corte')
                ->assertOk()
                ->assertDownload();
        }

        $this->withToken($token)
            ->get('/api/existencias/materiales/corte')
            ->assertTooManyRequests();
    }

    public function test_limita_la_actualizacion_excel_por_token_de_conexion(): void
    {
        [, $tokenOficina] = $this->acceso(RolUsuario::Administrador);
        $respuesta = $this->withToken($tokenOficina)
            ->post('/api/existencias/materiales/conexion-excel')
            ->assertCreated();
        preg_match('/token=([A-Za-z0-9]+)/', $respuesta->getContent(), $coincidencias);
        $tokenConsulta = $coincidencias[1];
        $url = '/api/existencias/materiales/consulta?token='.$tokenConsulta;

        for ($intento = 1; $intento <= 6; $intento++) {
            $this->get($url)
                ->assertOk()
                ->assertStreamed()
                ->streamedContent();
        }

        $this->get($url)
            ->assertTooManyRequests();
    }

    public function test_supervisor_materiales_solo_recibe_existencia_de_materiales(): void
    {
        [, $token] = $this->acceso(RolUsuario::SupervisorMateriales);

        $this->withToken($token)
            ->get('/api/existencias/materia-prima/corte')
            ->assertForbidden();
        $this->withToken($token)
            ->get('/api/existencias/producto-terminado/corte')
            ->assertForbidden();
        $this->withToken($token)
            ->getJson('/api/existencias?tipo=producto-terminado')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/existencias')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipo', 'materiales');
    }

    public function test_usuario_de_consulta_accede_a_custodia_y_su_historial(): void
    {
        [, $token] = $this->acceso(RolUsuario::Consulta);

        $this->withToken($token)
            ->getJson('/api/materiales/almacenes')
            ->assertOk();
        $this->withToken($token)
            ->getJson('/api/materiales/almacenes/movimientos')
            ->assertOk();
    }

    public function test_cada_area_posee_su_propia_oficina_de_existencias(): void
    {
        $this->get('/oficina/existencias')
            ->assertRedirect('/oficina/materiales/exportaciones');

        $this->get('/oficina/frigorifico/existencias')
            ->assertOk()
            ->assertSee('Existencia y despachos de producto terminado')
            ->assertSee('data-inventory-type="producto-terminado,despachos-producto-terminado"', false)
            ->assertSee('id="inventoryFilters"', false)
            ->assertSee('data-office-key="existencias-pt"', false)
            ->assertDontSee('Tres inventarios. Una fuente oficial.');

        $this->get('/oficina/materiales/exportaciones')
            ->assertOk()
            ->assertSee('Existencia de materiales')
            ->assertSee('data-inventory-type="materiales"', false)
            ->assertSee('data-office-key="exportaciones"', false);

        $this->get('/oficina/materia-prima/existencias')
            ->assertOk()
            ->assertSee('Existencia de materia prima')
            ->assertSee('data-inventory-type="materia-prima"', false)
            ->assertSee('data-office-key="existencias-mp"', false);

        $this->get('/oficina/materiales/almacenes')
            ->assertOk()
            ->assertSee('data-office-key="custodia"', false)
            ->assertSee('data-navigation-module="materiales.inventario"', false)
            ->assertDontSee('data-navigation-module="materiales.custodia"', false)
            ->assertSee('Existencia en centros de costo');

        $script = file_get_contents(resource_path('js/office-material-warehouses.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("can('puede_consultar_kardex_materiales')", $script);
        $this->assertStringContainsString('const movementForm = event.currentTarget;', $script);
        $this->assertStringContainsString('submitButton.disabled = true;', $script);
        $this->assertStringNotContainsString('event.currentTarget.elements', $script);
    }

    public function test_despachos_pt_se_exportan_por_cliente_y_periodo(): void
    {
        [$administrador, $token] = $this->acceso(RolUsuario::Administrador);
        $temporada = app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'TEMP-EX-DESP',
            'nombre' => 'Temporada despachos',
            'activa' => true,
        ], usuarioId: $administrador->id);
        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-EX-000001',
            'estado' => EstadoCarga::Cerrada,
            'prioridad' => PrioridadCarga::Normal,
            'version' => 5,
            'patente' => 'ABCD12',
            'conductor' => 'Conductor Prueba',
            'creada_por_user_id' => $administrador->id,
            'actualizada_por_user_id' => $administrador->id,
            'cerrada_por_user_id' => $administrador->id,
            'cerrada_at' => '2026-09-20 15:00:00',
        ]);
        foreach ([['PAL-DESP-A', 'Exportadora Norte', '2026-09-20 14:00:00'], ['PAL-DESP-B', 'Exportadora Sur', '2026-09-20 14:30:00'], ['PAL-DESP-C', 'Exportadora Norte', '2026-09-10 10:00:00']] as [$numero, $cliente, $salida]) {
            $folio = Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => $numero,
                'tipo_bulto' => TipoBulto::Pallet,
                'estado_operacional' => EstadoOperacionalFolio::Despachado,
                'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
                'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
                'fecha_ingreso' => now(),
                'activo' => true,
                'exportadora' => $cliente,
                'variedad' => 'Santina',
                'datos_externos' => [
                    'cantidad_cajas' => 120,
                    'composicion' => [
                        ['csg' => '111', 'cantidad_cajas' => 80, 'lote_materia_prima' => 'L-1', 'proceso_packing' => 'P-9'],
                        ['csg' => '111', 'cantidad_cajas' => 40, 'lote_materia_prima' => 'L-2', 'proceso_packing' => 'P-9'],
                    ],
                ],
            ]);
            CargaFolio::create([
                'carga_id' => $carga->id,
                'folio_id' => $folio->id,
                'estado' => EstadoCargaFolio::EnAnden,
                'asignado_por_user_id' => $administrador->id,
                'asignado_at' => '2026-09-01 08:00:00',
                'finalizado_por_user_id' => $administrador->id,
                'finalizado_at' => $salida,
            ]);
        }

        $servicio = app(ServicioExistencias::class);
        $filas = $servicio->filas(ServicioExistencias::DESPACHOS_PRODUCTO_TERMINADO, [
            'cliente' => 'exportadora norte',
            'desde' => '2026-09-15',
        ])->values()->all();

        $this->assertCount(1, $filas);
        $this->assertSame('PAL-DESP-A', $filas[0]['folio']);
        $this->assertSame('CAR-EX-000001', $filas[0]['carga']);
        $this->assertSame('ABCD12', $filas[0]['patente']);
        $this->assertSame('L-1 | L-2', $filas[0]['lotes_materia_prima']);
        $this->assertSame('P-9', $filas[0]['procesos_packing']);

        $this->withToken($token)
            ->getJson('/api/existencias?tipo=producto-terminado,despachos-producto-terminado')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('clientes', ['Exportadora Norte', 'Exportadora Sur']);

        $respuesta = $this->withToken($token)
            ->get('/api/existencias/despachos-producto-terminado/corte?cliente=Exportadora%20Sur&desde=2026-09-01&hasta=2026-09-30')
            ->assertOk()
            ->assertDownload();
        $this->assertStringContainsString(
            'Despachos_Producto_Terminado_exportadora_sur_',
            (string) $respuesta->headers->get('content-disposition'),
        );

        $this->withToken($token)
            ->getJson('/api/existencias/despachos-producto-terminado/corte?desde=2026-09-30&hasta=2026-09-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hasta']);
    }

    public function test_excel_despachos_muestra_el_numero_externo_del_instructivo_de_cada_pallet_como_texto(): void
    {
        [$administrador, $token] = $this->acceso(RolUsuario::Administrador);
        $temporadaId = DB::table('temporadas')->where('activa', true)->value('id');
        $cliente = Cliente::create(['codigo' => 'CLI-ORD-EXT', 'nombre' => 'Cliente orden externa', 'activo' => true]);
        $carga = Carga::create([
            'temporada_id' => $temporadaId, 'codigo' => 'CAR-ORD-EXT', 'numero_orden_externa' => '000LEGACY',
            'estado' => EstadoCarga::Cerrada, 'prioridad' => PrioridadCarga::Normal,
            'creada_por_user_id' => $administrador->id, 'actualizada_por_user_id' => $administrador->id,
            'cerrada_por_user_id' => $administrador->id, 'cerrada_at' => now(),
        ]);
        $embarque = Embarque::create([
            'temporada_id' => $temporadaId, 'cliente_id' => $cliente->id, 'carga_id' => $carga->id,
            'codigo' => 'EMB-EXT', 'numero_correlativo' => 1,
            'fecha_programada' => now()->toDateString(), 'hora_programada' => '10:00:00',
            'intervalo_minutos' => 45, 'modalidad' => 'maritimo', 'estado' => 'confirmado',
            'creado_por_user_id' => $administrador->id, 'actualizado_por_user_id' => $administrador->id,
        ]);
        $esperado = ['PAL-EXT-1' => '000123', 'PAL-EXT-2' => '000456', 'PAL-EXT-3' => '0', 'PAL-ANTIGUO' => '000LEGACY'];
        foreach ($esperado as $numeroFolio => $numeroExterno) {
            $instructivo = $numeroFolio === 'PAL-ANTIGUO' ? null : $embarque->instructivos()->create([
                'orden' => (int) substr($numeroFolio, -1), 'numero_externo' => $numeroExterno,
            ]);
            $folio = Folio::create([
                'temporada_id' => $temporadaId, 'numero_folio' => $numeroFolio,
                'tipo_bulto' => TipoBulto::Pallet, 'estado_operacional' => EstadoOperacionalFolio::Despachado,
                'fecha_ingreso' => now(), 'activo' => true, 'exportadora' => $cliente->nombre,
                'datos_externos' => ['cantidad_cajas' => 104],
            ]);
            CargaFolio::create([
                'carga_id' => $carga->id, 'folio_id' => $folio->id, 'instructivo_embarque_id' => $instructivo?->id,
                'estado' => EstadoCargaFolio::Despachado,
                'asignado_por_user_id' => $administrador->id, 'asignado_at' => now(),
                'finalizado_por_user_id' => $administrador->id, 'finalizado_at' => now(),
            ]);
        }

        $filas = app(ServicioExistencias::class)->filas(ServicioExistencias::DESPACHOS_PRODUCTO_TERMINADO)->collect()->keyBy('folio');
        $this->assertCount(4, $filas);
        foreach ($esperado as $folio => $externo) {
            $this->assertSame($externo, $filas[$folio]['orden_embarque']);
        }

        $respuesta = $this->withToken($token)->get('/api/existencias/despachos-producto-terminado/corte')->assertOk()->assertDownload();
        $ruta = $respuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($ruta);
        $documento = new DOMDocument;
        $this->assertTrue($documento->loadXML($xml));
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $this->assertSame('Orden de embarque', $xpath->evaluate('string(//s:c[@r="F1"])'));
        $registros = $xpath->query('//s:sheetData/s:row[position() > 1]');
        $this->assertCount(4, $registros);
        foreach ($registros as $registro) {
            $numeroFila = $registro->getAttribute('r');
            $folio = $xpath->evaluate('string(s:c[@r="J'.$numeroFila.'"])', $registro);
            $celdaOrden = $xpath->query('s:c[@r="F'.$numeroFila.'"]', $registro)->item(0);
            $this->assertSame($esperado[$folio], $celdaOrden->textContent);
            $this->assertSame('inlineStr', $celdaOrden->getAttribute('t'));
        }

        // Una asignación histórica sin instructivo ni orden externa no toma la referencia de otro pallet.
        $carga->update(['numero_orden_externa' => null]);
        $antiguo = app(ServicioExistencias::class)->filas(ServicioExistencias::DESPACHOS_PRODUCTO_TERMINADO)->firstWhere('folio', 'PAL-ANTIGUO');
        $this->assertNull($antiguo['orden_embarque']);
    }

    /** @return array{User, string} */
    public function test_existencia_pt_tolera_folios_sin_condicion_termica_ni_habilitacion(): void
    {
        [$administrador] = $this->acceso(RolUsuario::Administrador);
        $temporada = app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'TEMP-EX-NULOS',
            'nombre' => 'Temporada con folios sin condición',
            'activa' => true,
        ], usuarioId: $administrador->id);
        Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => 'PAL-SIN-CONDICION',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);

        $fila = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::PRODUCTO_TERMINADO)
            ->firstWhere('folio', 'PAL-SIN-CONDICION');

        $this->assertNotNull($fila);
        $this->assertNull($fila['condicion_termica']);
        $this->assertNull($fila['habilitacion_almacenamiento']);
    }

    public function test_despachos_pt_se_recorren_completos_por_bloques_aun_con_salidas_simultaneas(): void
    {
        [$administrador] = $this->acceso(RolUsuario::Administrador);
        $temporada = app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'TEMP-EX-BLOQUES',
            'nombre' => 'Temporada bloques',
            'activa' => true,
        ], usuarioId: $administrador->id);
        $carga = Carga::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'CAR-EX-BLOQUES',
            'estado' => EstadoCarga::Cerrada,
            'prioridad' => PrioridadCarga::Normal,
            'creada_por_user_id' => $administrador->id,
            'actualizada_por_user_id' => $administrador->id,
        ]);
        // 1.003 despachos con solo dos horas de salida: el corte entre bloques de 500 cae
        // dentro de filas con la misma fecha y no debe repetir ni omitir ninguna.
        $folios = [];
        $asignaciones = [];
        foreach (range(1, 1003) as $numero) {
            $folioId = (string) Str::uuid();
            $folios[] = [
                'id' => $folioId,
                'temporada_id' => $temporada->id,
                'numero_folio' => sprintf('PAL-BLQ-%04d', $numero),
                'tipo_bulto' => TipoBulto::Pallet->value,
                'estado_operacional' => EstadoOperacionalFolio::Despachado->value,
                'fecha_ingreso' => now(),
                'activo' => false,
                'exportadora' => 'Exportadora Norte',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $asignaciones[] = [
                'id' => (string) Str::uuid(),
                'carga_id' => $carga->id,
                'folio_id' => $folioId,
                'estado' => EstadoCargaFolio::EnAnden->value,
                'asignado_por_user_id' => $administrador->id,
                'asignado_at' => '2026-09-01 08:00:00',
                'finalizado_at' => $numero % 2 === 0 ? '2026-09-20 14:00:00' : '2026-09-20 15:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($folios, 250) as $bloque) {
            DB::table('folios')->insert($bloque);
        }
        foreach (array_chunk($asignaciones, 250) as $bloque) {
            DB::table('carga_folios')->insert($bloque);
        }

        $filas = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::DESPACHOS_PRODUCTO_TERMINADO, ['cliente' => 'EXPORTADORA NORTE'])
            ->pluck('folio')
            ->all();

        $this->assertCount(1003, $filas);
        $this->assertCount(1003, array_unique($filas));
    }

    private function acceso(RolUsuario $rol): array
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $token = $usuario->createToken('prueba-existencias', ['oficina'])->plainTextToken;

        return [$usuario, $token];
    }
}
