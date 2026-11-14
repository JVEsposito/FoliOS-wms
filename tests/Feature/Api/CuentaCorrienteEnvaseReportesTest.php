<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoEnvaseRomana;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class CuentaCorrienteEnvaseReportesTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private Cliente $planta;

    private Cliente $productor;

    private User $administrador;

    private MovimientoEnvase $compra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Santiago'));
        $this->temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->planta = Cliente::create(['codigo' => 'AGR', 'nombre' => 'Agro Rosario', 'activo' => true]);
        $this->productor = Cliente::create(['codigo' => 'PRB', 'nombre' => 'Probins', 'activo' => true]);
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);

        // Compra de envases propios: no mueve la cuenta de nadie.
        $this->compra = $this->movimiento($this->planta, 'recepcion_compra', 500, 0, 1, 'propia', '2026-10-01 10:00', 'COMPRA-1');
        $recepcion = $this->movimiento($this->productor, 'recepcion_fruta', 300, 1, 1, 'cliente', '2026-10-02 09:00', 'REC-1');
        $this->movimiento($this->productor, 'despacho_cliente', 120, -1, -1, 'propia', '2026-10-05 11:00', 'GDE-1', $this->compra);
        $this->movimiento($this->productor, 'despacho_cliente', 50, -1, -1, 'cliente', '2026-10-06 11:00', 'GDE-2', $recepcion);
        // 23:30 en planta es el día 8 en UTC: pertenece al día 7.
        $this->movimiento($this->productor, 'recepcion_fruta', 10, 1, 1, 'cliente', '2026-10-07 23:30', 'REC-NOCHE');
        $this->movimiento($this->productor, 'reversion_despacho', 20, 1, 1, 'propia', '2026-10-08 08:00', 'GDE-1', $this->compra);
    }

    public function test_los_envases_propios_se_ven_en_existencia_y_en_lo_entregado_a_cada_cliente(): void
    {
        $this->actingAs($this->administrador, 'sanctum');
        $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $this->productor->id,
            'salida_at' => '2026-10-14T10:00:00-03:00',
            'detalles' => [['tipo_envase' => 'bins', 'cantidad' => 30, 'propiedad' => 'propia']],
        ])->assertCreated();

        $respuesta = $this->getJson('/api/envases/cuenta-corriente/movimientos')->assertOk();

        // La compra (signo 0) ya no aparece como un saldo 0 de la planta.
        $this->assertSame([[
            'cliente' => ['id' => $this->productor->id, 'codigo' => 'PRB', 'nombre' => 'Probins'],
            'tipo_envase' => 'bins',
            'saldo' => 160,
            'propios_entregados' => 100,
        ]], $respuesta->json('balances'));
        $bins = $respuesta->json('existencia.tipos.0');
        $this->assertSame('bins', $bins['tipo_envase']);
        $this->assertSame(['cliente' => 260, 'propia' => 400, 'arrendada' => 0, 'total' => 660], $bins['en_planta']);
        $this->assertSame(30, $bins['reservado']['propia']);
        $this->assertSame(630, $bins['disponible']);
        $this->assertSame(['ingresados' => 500, 'despachados' => 100, 'en_planta' => 400], $bins['propios']);
        $this->assertSame([[
            'cliente_id' => $this->productor->id,
            'cliente' => ['codigo' => 'PRB', 'nombre' => 'Probins'],
            'tipo_envase' => 'bins',
            'cantidad' => 100,
        ]], $respuesta->json('existencia.propios_entregados'));
    }

    public function test_los_filtros_de_fecha_usan_el_dia_de_planta(): void
    {
        $this->actingAs($this->administrador, 'sanctum');

        $dia7 = $this->getJson('/api/envases/cuenta-corriente/movimientos?desde=2026-10-07&hasta=2026-10-07')
            ->assertOk()->json('data');
        $this->assertSame(['REC-NOCHE'], array_column($dia7, 'numero_documento'));
        $dia8 = $this->getJson('/api/envases/cuenta-corriente/movimientos?desde=2026-10-08&hasta=2026-10-08')
            ->assertOk()->json('data');
        $this->assertSame(['GDE-1'], array_column($dia8, 'numero_documento'));
    }

    public function test_el_kardex_en_excel_lleva_el_saldo_acumulado_de_la_cuenta(): void
    {
        $this->actingAs($this->administrador, 'sanctum');

        $completo = $this->get('/api/envases/cuenta-corriente/movimientos/excel?cliente_id='.$this->productor->id)
            ->assertOk()
            ->assertDownload('cuenta-envases-'.$this->temporada->codigo.'.xlsx');
        $filas = $this->filasExcel($completo->baseResponse->getFile()->getPathname());
        $this->assertSame(['Fecha y hora', 'Código cliente', 'Cliente', 'Documento', 'Guía de despacho emitida', 'Movimiento', 'Envase', 'Propiedad', 'Cantidad', 'Efecto en cuenta', 'Saldo de cuenta'], array_slice($filas[0], 0, 11));
        $this->assertSame(['300', '180', '130', '140', '160'], array_column(array_slice($filas, 1), 10));
        $this->assertSame(['REC-1', 'GDE-1', 'GDE-2', 'REC-NOCHE', 'GDE-1'], array_column(array_slice($filas, 1), 3));
        $this->assertSame('Despacho', $filas[2][5]);
        $this->assertSame('Propia', $filas[2][7]);

        // La compra propia sale en el kardex general, sin efecto ni saldo de cuenta.
        $general = $this->get('/api/envases/cuenta-corriente/movimientos/excel')->assertOk();
        $compra = collect($this->filasExcel($general->baseResponse->getFile()->getPathname()))
            ->first(fn (array $fila): bool => ($fila[3] ?? null) === 'COMPRA-1');
        $this->assertSame(['Compra de envases propios', 'Bins plástico', 'Propia', '500', '', '', '500'], array_slice($compra, 5, 7));

        // Con período, el saldo sigue siendo el de la cuenta, no el de las filas visibles.
        $periodo = $this->get('/api/envases/cuenta-corriente/movimientos/excel?cliente_id='.$this->productor->id.'&desde=2026-10-05&hasta=2026-10-06')
            ->assertOk();
        $filas = $this->filasExcel($periodo->baseResponse->getFile()->getPathname());
        $this->assertSame(['180', '130'], array_column(array_slice($filas, 1), 10));
    }

    public function test_el_estado_de_cuenta_resume_el_periodo_y_se_descarga_en_pdf_o_excel(): void
    {
        $estado = app(ServicioCuentaCorrienteEnvases::class)
            ->estadoCuenta($this->productor, $this->temporada, '2026-10-05', null);
        $this->assertSame([[
            'tipo_envase' => 'bins',
            'nombre' => 'Bins plástico',
            'orden' => 1,
            'saldo_inicial' => 300,
            'entradas' => 10,
            'salidas' => 150,
            'salidas_por_propiedad' => ['cliente' => 50, 'propia' => 100, 'arrendada' => 0],
            'saldo_final' => 160,
        ]], $estado['tipos']);
        $this->assertSame([180, 130, 140, 160], array_column($estado['movimientos'], 'saldo'));
        // El desglose CSG resume el período, sin repetir las 300 unidades iniciales.
        $this->assertSame(10, array_sum(array_map(fn ($g) => array_sum(array_column($g['tipos'], 'entradas')), $estado['resumen_csg'])));
        $this->assertSame(150, array_sum(array_map(fn ($g) => array_sum(array_column($g['tipos'], 'salidas')), $estado['resumen_csg'])));
        // Una anulación histórica sin referencia al despacho descuenta de lo entregado, en la misma columna.
        $this->assertSame([null, null, 10, null], array_column($estado['movimientos'], 'entrada'));
        $this->assertSame([120, 50, null, -20], array_column($estado['movimientos'], 'salida'));

        $this->actingAs($this->administrador, 'sanctum');
        $pdf = $this->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->productor->id.'&desde=2026-10-05')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('ESTADO DE CUENTA DE ENVASES', $pdf);
        $this->assertStringContainsString('PRB', $pdf);
        $this->assertStringContainsString('RESUMEN POR PRODUCTOR', $pdf);
        $this->assertStringNotContainsString('Entregados: 100 propios de la planta, 50 del cliente.', $pdf);
        $this->assertStringNotContainsString('(Propiedad)', $pdf);

        $this->get('/api/envases/cuenta-corriente/estado-cuenta?formato=xlsx&cliente_id='.$this->productor->id)
            ->assertOk()
            ->assertDownload('estado-cuenta-envases-prb-'.$this->temporada->codigo.'.xlsx');
        $this->getJson('/api/envases/cuenta-corriente/estado-cuenta')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliente_id');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum')
            ->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->productor->id)
            ->assertForbidden();
    }

    public function test_el_estado_de_cuenta_muestra_la_guia_emitida_y_omite_las_guias_anuladas(): void
    {
        $this->actingAs($this->administrador, 'sanctum');
        $compra2 = $this->movimiento($this->planta, 'recepcion_compra', 10, 0, 1, 'propia', '2026-10-03 10:00', 'COMPRA-2');
        $this->movimiento($this->planta, 'recepcion_compra', 20, 0, 1, 'propia', '2026-10-03 11:00', 'COMPRA-TOTES', null, 'totes');
        // Bins desde dos orígenes internos y totes: en la guía son dos líneas, una por envase.
        $entregada = $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $this->productor->id,
            'salida_at' => '2026-10-14T10:00:00-03:00',
            'detalles' => [
                ['tipo_envase' => 'totes', 'cantidad' => 5, 'propiedad' => 'propia'],
                ['tipo_envase' => 'bins', 'cantidad' => 6, 'propiedad' => 'propia', 'movimiento_origen_id' => $this->compra->id],
                ['tipo_envase' => 'bins', 'cantidad' => 4, 'propiedad' => 'propia', 'movimiento_origen_id' => $compra2->id],
            ],
        ])->assertCreated()->json('data');
        $this->postJson("/api/envases/guias-despacho/{$entregada['id']}/confirmar", ['numero_guia_despacho' => '5501'])->assertOk();
        // Internamente son tres movimientos (uno por origen); el cliente verá dos líneas.
        $this->assertSame(3, MovimientoEnvase::query()->where('documento_id', $entregada['id'])->count());
        $anulada = $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $this->productor->id,
            'salida_at' => '2026-10-14T10:30:00-03:00',
            'detalles' => [['tipo_envase' => 'bins', 'cantidad' => 16, 'propiedad' => 'propia']],
        ])->assertCreated()->json('data');
        $this->postJson("/api/envases/guias-despacho/{$anulada['id']}/confirmar", ['numero_guia_despacho' => '5502'])->assertOk();
        $this->postJson("/api/envases/guias-despacho/{$anulada['id']}/anular", ['motivo' => 'Se emitió por error.'])->assertOk();

        $estado = app(ServicioCuentaCorrienteEnvases::class)
            ->estadoCuenta($this->productor, $this->temporada, '2026-10-14', null);
        $this->assertSame([[
            'fecha' => '2026-10-14 10:00:00',
            'documento' => '5501',
            'movimiento' => 'Despacho',
            'productor' => 'Sin productor asignado',
            'tipo_envase' => 'Bins plástico',
            'propiedad' => 'Propia',
            'entrada' => null,
            'salida' => 10,
            'saldo' => 150,
        ], [
            'fecha' => '2026-10-14 10:00:00',
            'documento' => '5501',
            'movimiento' => 'Despacho',
            'productor' => 'Sin productor asignado',
            'tipo_envase' => 'Tote',
            'propiedad' => 'Propia',
            'entrada' => null,
            'salida' => 5,
            'saldo' => -5,
        ]], $estado['movimientos']);
        $this->assertSame(160, $estado['tipos'][0]['saldo_inicial']);
        $this->assertSame(['cliente' => 0, 'propia' => 10, 'arrendada' => 0], $estado['tipos'][0]['salidas_por_propiedad']);
        $this->assertSame(150, $estado['tipos'][0]['saldo_final']);
        $this->assertSame(-5, $estado['tipos'][1]['saldo_final']);
        $this->assertSame(150, collect($this->getJson('/api/envases/cuenta-corriente/movimientos')->json('balances'))->firstWhere('tipo_envase', 'bins')['saldo']);

        $pdf = $this->get('/api/envases/cuenta-corriente/estado-cuenta?desde=2026-10-14&cliente_id='.$this->productor->id)->assertOk()->getContent();
        $this->assertStringContainsString('5501) Tj', $pdf);
        $this->assertStringNotContainsString('5502) Tj', $pdf);
        $this->assertCount(1, $estado['grupos_documentos']);
        $this->assertCount(2, $estado['grupos_documentos'][0]['lineas']);
        $this->assertStringNotContainsString('Anulaci', $pdf);

        // La trazabilidad interna conserva todo: despacho, anulación y número emitido.
        $kardex = $this->get('/api/envases/cuenta-corriente/movimientos/excel?cliente_id='.$this->productor->id.'&desde=2026-10-14')->assertOk();
        $filas = array_slice($this->filasExcel($kardex->baseResponse->getFile()->getPathname()), 1);
        $this->assertSame(['5501', '5501', '5501', '5502', '5502'], array_column($filas, 4));
        $this->assertSame(['Despacho', 'Despacho', 'Despacho', 'Despacho', 'Anulación de despacho'], array_column($filas, 5));

        $movimientos = collect($this->getJson('/api/envases/cuenta-corriente/movimientos?desde=2026-10-14')->json('data'));
        $this->assertSame(
            ['numero' => '5501', 'archivo' => false],
            $movimientos->firstWhere('documento_id', $entregada['id'])['guia_emitida'],
        );
    }

    public function test_el_estado_de_cuenta_identifica_los_productores_de_una_recepcion_historica_en_pdf_y_excel(): void
    {
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $this->actingAs($this->administrador, 'sanctum');
        $recepcionId = $this->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $this->temporada->id,
            'cliente_id' => $this->productor->id,
            'tipo_recepcion' => 'fruta_con_envases',
            'especie_validacion_id' => $especie->id,
            'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 300]],
            'numero_guia_despacho' => 'REC-1',
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor MP',
            'peso_bruto' => 28000,
        ])->assertCreated()->json('data.id');
        $recepcion = RecepcionRomana::findOrFail($recepcionId);
        $validacion = $recepcion->validacionesMp()->create([
            'temporada_id' => $this->temporada->id,
            'operacion_toma_id' => (string) Str::uuid(),
            'estado' => 'validada',
            'validador_user_id' => $this->administrador->id,
            'tomada_at' => now(),
            'validada_at' => now(),
        ]);
        $productor = ProductorCsg::create([
            'codigo' => '122104', 'razon_social' => 'Agricola Los Robles', 'predio' => 'Predio Los Robles',
            'estado_sag' => 'activo', 'fuente_url' => 'https://sag.example.test',
            'primera_verificacion_at' => now(), 'ultima_verificacion_at' => now(),
            'ultima_consulta_user_id' => $this->administrador->id, 'respuesta_hash' => hash('sha256', '122104'),
        ]);
        $csg = CsgValidacion::create([
            'temporada_id' => $this->temporada->id, 'codigo' => '122104', 'productor_csg_id' => $productor->id,
            'predio' => $productor->predio, 'activo' => true,
        ]);
        foreach ([1, 2] as $secuencia) {
            $segmento = $validacion->segmentos()->create([
                'secuencia' => $secuencia, 'motivos' => ['cuartel'], 'csg_validacion_id' => $csg->id, 'csg_snapshot' => '122104',
            ]);
            $segmento->envases()->create(['tipo_envase' => 'bins', 'cantidad' => 100]);
        }
        $validacion->segmentos()->create(['secuencia' => 3, 'motivos' => ['csg'], 'csg_snapshot' => '122105'])
            ->envases()->create(['tipo_envase' => 'bins', 'cantidad' => 100]);
        MovimientoEnvase::query()->where('numero_documento', 'REC-1')->update(['recepcion_romana_id' => $recepcionId]);

        $ruta = '/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->productor->id;
        $productores = 'CSG 122104 · Agricola Los Robles / CSG 122105';
        $pdf = $this->get($ruta)->assertOk()->getContent();
        // El detalle por productor reparte la guía según lo validado y lo dice en cada parte.
        $win = fn (string $texto): string => preg_quote(iconv('UTF-8', 'Windows-1252', $texto), '/');
        $this->assertMatchesRegularExpression('/'.$win('(CSG 122104 · Agricola Los Robles)').'.*?'.$win('(Guía de ingreso N° REC-1 · Chofer Conductor MP').'.*?'.$win('Camión ABCD12)')
            .'.*?'.$win('(Guía compartida con CSG 122105: aquí solo la parte de este productor.)').'.*?\(\+200\).*?'
            .$win('(CSG 122105 · Productor sin nombre)').'.*?'.$win('(Guía de ingreso N° REC-1').'.*?\(\+100\).*?\(Sin productor asociado\)/s', $pdf);
        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->productor, $this->temporada, null, null);
        $resumen = collect($estado['resumen_csg'])->keyBy('codigo');
        $this->assertSame(200, $resumen['122104']['tipos'][0]['entradas']);
        $this->assertSame(100, $resumen['122105']['tipos'][0]['entradas']);
        // Cada grupo del detalle termina en lo mismo que su fila del resumen.
        foreach ($estado['detalle_csg'] as $grupo) {
            $this->assertSame(array_column($resumen[$grupo['codigo']]['tipos'], 'saldo_final'), array_column($grupo['totales'], 'saldo_final'));
        }

        $excel = $this->get($ruta.'&formato=xlsx')->assertOk();
        $filas = $this->filasExcel($excel->baseResponse->getFile()->getPathname());
        $this->assertSame('Productor / CSG', $filas[0][8]);
        $this->assertSame(['REC-1', 'Recepción con fruta', 'Bins plástico', 'Del cliente', '300', '', '300', $productores], array_slice($filas[1], 1));
        $this->assertSame(['300', '180', '130', '140', '160'], array_column(array_slice($filas, 1), 7));

        $this->movimiento($this->productor, 'salida_recepcion_fruta', 35, -1, -1, 'cliente', '2026-10-14 10:00', 'DEV-PERIODO')
            ->update(['recepcion_romana_id' => $recepcionId]);
        $this->movimiento($this->productor, 'recepcion_fruta', 800, 1, 1, 'cliente', '2026-10-15 10:00', 'REC-POSTERIOR');
        $periodo = app(ServicioCuentaCorrienteEnvases::class)
            ->estadoCuenta($this->productor, $this->temporada, '2026-10-14', '2026-10-14');

        // Los dos CSG conservan su saldo inicial. La devolución global queda sin asignar.
        $this->assertSame(['122104', '122105', null], array_column($periodo['resumen_csg'], 'codigo'));
        $bins = array_column(array_column($periodo['resumen_csg'], 'tipos'), 0);
        $this->assertSame([0, 0, 0], array_column($bins, 'entradas'));
        $this->assertSame([0, 0, 35], array_column($bins, 'salidas'));
        $this->assertSame([200, 100, -175], array_column($bins, 'saldo_final'));
        $this->assertSame(125, $periodo['tipos'][0]['saldo_final']);

        $pdfPeriodo = $this->get($ruta.'&desde=2026-10-14&hasta=2026-10-14')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/\(RESUMEN POR PRODUCTOR\).*?\(Saldo final\).*?CSG 122104\).*?\(\+200\).*?CSG 122105\).*?\(\+100\).*?\(Sin productor asociado\).*?\(-175\)/s', $pdfPeriodo);
        $this->assertStringNotContainsString('REC-POSTERIOR', $pdfPeriodo);
    }

    /**
     * Estado de cuenta de FALCO FRUIT (13-11-2026): la entrega con guía de salida 2300 aparecía
     * justo después de la guía 12520 y parecía su salida, aunque era del destare de otro camión.
     * El detalle va agrupado por CSG, en orden cronológico dentro de cada uno, y cada guía lleva
     * su chofer, su recepción y su camión.
     */
    public function test_el_detalle_va_por_csg_con_chofer_recepcion_y_camion_junto_a_cada_guia(): void
    {
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $this->actingAs($this->administrador, 'sanctum');
        $recepcion = function (string $guia, string $patente, string $carro, string $chofer, string $rut, string $csg, string $productor) use ($especie): RecepcionRomana {
            $id = $this->postJson('/api/romana/recepciones', [
                'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->productor->id,
                'tipo_recepcion' => 'fruta_con_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 66]], 'numero_guia_despacho' => $guia,
                'patente_camion' => $patente, 'patente_carro' => $carro, 'tipo_camion' => 'termo', 'rut_conductor' => $rut, 'nombre_conductor' => $chofer, 'peso_bruto' => 28000,
            ])->assertCreated()->json('data.id');
            $modelo = RecepcionRomana::findOrFail($id);
            $validacion = $modelo->validacionesMp()->create(['temporada_id' => $this->temporada->id, 'operacion_toma_id' => (string) Str::uuid(),
                'estado' => 'validada', 'validador_user_id' => $this->administrador->id, 'tomada_at' => now(), 'validada_at' => now()]);
            $productorCsg = ProductorCsg::create(['codigo' => $csg, 'razon_social' => $productor, 'predio' => $productor, 'estado_sag' => 'activo',
                'fuente_url' => 'https://sag.example.test', 'primera_verificacion_at' => now(), 'ultima_verificacion_at' => now(),
                'ultima_consulta_user_id' => $this->administrador->id, 'respuesta_hash' => hash('sha256', $csg)]);
            $csgValidacion = CsgValidacion::create(['temporada_id' => $this->temporada->id, 'codigo' => $csg, 'productor_csg_id' => $productorCsg->id, 'predio' => $productor, 'activo' => true]);
            $validacion->segmentos()->create(['secuencia' => 1, 'motivos' => ['csg'], 'csg_validacion_id' => $csgValidacion->id, 'csg_snapshot' => $csg])
                ->envases()->create(['tipo_envase' => 'bins', 'cantidad' => 66]);

            return $modelo;
        };
        $guia12520 = $recepcion('12520', 'RY3495', 'GRJB65', 'FABIAN URDILES', '21.267.505-9', '3130606', 'AGRO COMERCIAL ESMERALDA LIMITADA');
        $guia1526 = $recepcion('1526', 'KSBB99', 'JP2999', 'IGNACIO DURAN', '12.345.678-5', '3115673', 'AGRICOLA JG LIMITADA');
        $this->movimiento($this->productor, 'recepcion_fruta', 66, 1, 1, 'cliente', '2026-10-09 14:57', '1526')->update(['recepcion_romana_id' => $guia1526->id]);
        $this->movimiento($this->productor, 'recepcion_fruta', 66, 1, 1, 'cliente', '2026-10-09 15:19', '12520')->update(['recepcion_romana_id' => $guia12520->id]);
        $this->movimiento($this->productor, 'salida_recepcion_fruta', 70, -1, -1, 'cliente', '2026-10-09 15:54', '2300')->update(['recepcion_romana_id' => $guia1526->id]);

        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->productor, $this->temporada, '2026-10-09', '2026-10-09');
        $this->assertSame(['3115673', '3130606', null], array_column($estado['detalle_csg'], 'codigo'));
        [$jg, $esmeralda] = $estado['detalle_csg'];
        $this->assertSame(['Guía de ingreso N° 1526', 'Guía de salida N° 2300'], array_column($jg['documentos'], 'titulo'));
        $this->assertSame('Chofer IGNACIO DURAN (RUT 12.345.678-5)', $jg['documentos'][1]['chofer']);
        $this->assertSame('Destare de '.$guia1526->numero_recepcion.' (guía de ingreso 1526) · Camión KSBB99 · carro JP2999', $jg['documentos'][1]['referencia']);
        $this->assertSame('Entregados en el destare', $jg['documentos'][1]['movimiento']);
        // Saldo acumulado del CSG: 66 recibidos y 70 entregados.
        $this->assertSame([66, -4], [$jg['documentos'][0]['lineas'][0]['saldo'], $jg['documentos'][1]['lineas'][0]['saldo']]);
        $this->assertSame([['tipo_envase' => 'Bins plástico', 'saldo_inicial' => 0, 'entradas' => 66, 'salidas' => 70, 'saldo_final' => -4]], $jg['totales']);
        $this->assertSame('Chofer FABIAN URDILES (RUT 21.267.505-9)', $esmeralda['documentos'][0]['chofer']);
        $this->assertSame(66, $esmeralda['totales'][0]['saldo_final']);
        // El resumen por productor suma lo mismo que el detalle.
        $resumen = collect($estado['resumen_csg'])->keyBy('codigo');
        $this->assertSame([-4, 66], [$resumen['3115673']['tipos'][0]['saldo_final'], $resumen['3130606']['tipos'][0]['saldo_final']]);

        $pdf = $this->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->productor->id.'&desde=2026-10-09&hasta=2026-10-09')->assertOk()->getContent();
        $w = fn (string $texto): string => preg_quote(iconv('UTF-8', 'Windows-1252', $texto), '/');
        $this->assertMatchesRegularExpression('/'.$w('(CSG 3115673 · AGRICOLA JG LIMITADA)').'.*?'.$w('(Guía de ingreso N° 1526 · Chofer IGNACIO DURAN')
            .'.*?'.$w('(Guía de salida N° 2300 · Chofer IGNACIO DURAN').'.*?'.$w('(Entregados en el destare · Destare de '.$guia1526->numero_recepcion)
            .'.*?\(-4\).*?'.$w('(CSG 3130606 · AGRO COMERCIAL ESMERALDA LIMITADA)').'.*?'.$w('(Guía de ingreso N° 12520 · Chofer FABIAN URDILES').'/s', $pdf);
        $this->assertStringContainsString('(DETALLE POR PRODUCTOR \(CSG\))', $pdf);
        $this->assertStringNotContainsString('DETALLE POR DOCUMENTO', $pdf);
    }

    public function test_dos_documentos_con_misma_guia_y_fecha_no_se_fusionan_en_el_pdf(): void
    {
        foreach ([10, 20] as $cantidad) {
            $this->movimiento($this->productor, 'recepcion_fruta', $cantidad, 1, 1, 'cliente', '2026-10-14 09:00', 'MISMA-GUIA')
                ->update(['documento_id' => (string) Str::uuid()]);
        }
        $estado = app(ServicioCuentaCorrienteEnvases::class)
            ->estadoCuenta($this->productor, $this->temporada, '2026-10-14', '2026-10-14');
        $this->assertCount(2, $estado['grupos_documentos']);
        $this->assertSame($estado['movimientos'], array_merge(...array_column($estado['grupos_documentos'], 'lineas')));
        $this->assertSame(190, $estado['tipos'][0]['saldo_final']);
    }

    /**
     * El encargado de cuentas corrientes envía al cliente solo algunos envases (solo esponjas, o bins y
     * totes): el documento trae los movimientos, saldos y el detalle por CSG solo de esos envases.
     */
    public function test_el_estado_de_cuenta_se_emite_solo_con_los_envases_elegidos(): void
    {
        $this->movimiento($this->productor, 'recepcion_fruta', 40, 1, 1, 'cliente', '2026-10-09 09:00', 'REC-ESP', envase: 'esponjas');
        $this->movimiento($this->productor, 'despacho_cliente', 15, -1, -1, 'cliente', '2026-10-10 09:00', 'GDE-ESP', envase: 'esponjas');
        $this->movimiento($this->productor, 'recepcion_fruta', 12, 1, 1, 'cliente', '2026-10-11 09:00', 'REC-TOT', envase: 'totes');
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);

        $esponjas = $cuenta->estadoCuenta($this->productor, $this->temporada, null, null, ['esponjas']);
        $this->assertSame(['esponjas'], array_column($esponjas['tipos'], 'tipo_envase'));
        $this->assertSame(25, $esponjas['tipos'][0]['saldo_final']);
        $this->assertSame(['REC-ESP', 'GDE-ESP'], array_column($esponjas['movimientos'], 'documento'));
        $this->assertSame([40, 25], array_column($esponjas['movimientos'], 'saldo'));
        $this->assertSame(['Esponja de bins'], $esponjas['envases_incluidos']);
        $this->assertNotEmpty($esponjas['resumen_csg']);
        foreach ([...$esponjas['resumen_csg'], ...$esponjas['detalle_csg']] as $grupo) {
            $this->assertCount(1, $grupo['tipos'] ?? $grupo['totales']);
            $this->assertStringNotContainsString('Bins plástico', json_encode($grupo, JSON_UNESCAPED_UNICODE));
        }

        // Bins y totes, en el orden del catálogo aunque se pidan al revés; cada envase conserva su propia cuenta.
        $binsTotes = $cuenta->estadoCuenta($this->productor, $this->temporada, null, null, ['totes', 'bins']);
        $this->assertSame(['bins', 'totes'], array_column($binsTotes['tipos'], 'tipo_envase'));
        $this->assertSame([160, 12], array_column($binsTotes['tipos'], 'saldo_final'));
        $this->assertSame(['Bins plástico', 'Tote'], $binsTotes['envases_incluidos']);
        $this->assertNotContains('REC-ESP', array_column($binsTotes['movimientos'], 'documento'));
        $todos = $cuenta->estadoCuenta($this->productor, $this->temporada, null, null);
        $this->assertSame(['bins', 'esponjas', 'totes'], array_column($todos['tipos'], 'tipo_envase'));
        $this->assertNull($todos['envases_incluidos']);

        $this->actingAs($this->administrador, 'sanctum');
        $ruta = '/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->productor->id;
        $win = fn (string $texto): string => iconv('UTF-8', 'Windows-1252', $texto);
        $respuesta = $this->get($ruta.'&tipos_envase[]=totes&tipos_envase[]=bins')->assertOk()
            ->assertDownload('estado-cuenta-envases-prb-'.$this->temporada->codigo.'-bins-totes.pdf');
        $pdf = $respuesta->getContent();
        $this->assertStringContainsString($win('Envases: Bins plástico, Tote'), $pdf);
        $this->assertStringContainsString('REC-TOT', $pdf);
        $this->assertStringNotContainsString('REC-ESP', $pdf);

        $excel = $this->get($ruta.'&formato=xlsx&tipos_envase[]=esponjas')->assertOk()
            ->assertDownload('estado-cuenta-envases-prb-'.$this->temporada->codigo.'-esponjas.xlsx');
        $archivo = $excel->baseResponse->getFile()->getPathname();
        $this->assertSame(['Esponja de bins', 'Esponja de bins'], array_column(array_slice($this->filasExcel($archivo), 1), 3));
        $this->assertContains(['Envases', 'Esponja de bins'], $this->filasExcel($archivo, 'sheet2'));

        // Marcar todos es el estado de cuenta completo, con el nombre de siempre.
        $completo = $this->get($ruta.'&formato=xlsx&'.http_build_query(['tipos_envase' => array_column(TipoEnvaseRomana::catalogo(), 'codigo')]))
            ->assertOk()->assertDownload('estado-cuenta-envases-prb-'.$this->temporada->codigo.'.xlsx');
        $this->assertContains(['Envases', 'Todos'], $this->filasExcel($completo->baseResponse->getFile()->getPathname(), 'sheet2'));

        $this->getJson($ruta.'&tipos_envase[]=cajones')->assertUnprocessable()->assertJsonValidationErrors('tipos_envase.0');
        $this->getJson($ruta.'&tipos_envase[]=bins&tipos_envase[]=bins')->assertUnprocessable()->assertJsonValidationErrors('tipos_envase.0');
    }

    private function movimiento(
        Cliente $cliente,
        string $tipo,
        int $cantidad,
        int $signoCuenta,
        int $signoExistencia,
        string $propiedad,
        string $fechaLocal,
        string $documento,
        ?MovimientoEnvase $origen = null,
        string $envase = 'bins',
    ): MovimientoEnvase {
        return MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $this->temporada->id,
            'cliente_id' => $cliente->id,
            'documento_tipo' => 'prueba',
            'numero_documento' => $documento,
            'tipo_movimiento' => $tipo,
            'tipo_envase' => $envase,
            'cantidad' => $cantidad,
            'signo_cuenta' => $signoCuenta,
            'signo_existencia' => $signoExistencia,
            'propiedad' => $propiedad,
            'movimiento_origen_id' => $origen?->id,
            'ocurrido_at' => CarbonImmutable::parse($fechaLocal, 'America/Santiago')->utc(),
            'estado_revision' => 'pendiente',
            'creado_por_user_id' => $this->administrador->id,
        ]);
    }

    /** @return list<list<string>> valores de una hoja (por defecto, la de movimientos), fila por fila */
    private function filasExcel(string $ruta, string $hoja = 'sheet1'): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $hoja = simplexml_load_string((string) $zip->getFromName("xl/worksheets/{$hoja}.xml"));
        $zip->close();
        $filas = [];
        foreach ($hoja->sheetData->row as $fila) {
            $valores = [];
            foreach ($fila->c as $celda) {
                $valores[] = isset($celda->is) ? (string) $celda->is->t : (string) $celda->v;
            }
            $filas[] = $valores;
        }

        return $filas;
    }
}
