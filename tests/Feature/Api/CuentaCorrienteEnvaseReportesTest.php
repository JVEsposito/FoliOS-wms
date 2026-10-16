<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\MovimientoEnvase;
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
        $this->assertStringContainsString('Entregados: 100 propios de la planta, 50 del cliente.', $pdf);

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
            'tipo_envase' => 'Bins plástico',
            'propiedad' => 'Propia',
            'entrada' => null,
            'salida' => 10,
            'saldo' => 150,
        ], [
            'fecha' => '2026-10-14 10:00:00',
            'documento' => '5501',
            'movimiento' => 'Despacho',
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
        $this->assertStringContainsString('(5501) Tj', $pdf);
        $this->assertStringNotContainsString('(5502) Tj', $pdf);
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

    /** @return list<list<string>> valores de la hoja Movimientos, fila por fila */
    private function filasExcel(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $hoja = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
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
