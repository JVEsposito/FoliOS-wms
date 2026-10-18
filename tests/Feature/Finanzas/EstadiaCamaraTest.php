<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\Models\Hecho;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstadiaCamaraTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
    }

    public function test_la_estadia_va_del_ingreso_a_camara_al_despacho_aunque_salga_un_rato(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $camara = $this->camara('C1');
        $anden = $this->camara('AN1', tipo: 'despacho');
        $folio = $this->folio('PT-0001', $cliente);

        $this->movimiento($folio, null, $camara, '2026-11-01 10:00');
        $this->movimiento($folio, $camara, null, '2026-11-02 08:00');
        $this->movimiento($folio, null, $camara, '2026-11-02 08:30');
        $this->movimiento($folio, $camara, $anden, '2026-11-05 15:40');
        $this->movimiento($folio, $anden, null, '2026-11-05 15:50');
        $this->despachar($folio, '2026-11-05 16:00');

        $this->capturar(['estadia']);

        $estadias = $this->hechos('estadia');
        $this->assertCount(1, $estadias);
        $estadia = $estadias->first();
        $this->assertSame("{$folio}#1", $estadia->origen_id);
        $this->assertSame($this->utc('2026-11-01 10:00'), $estadia->ocurrido_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->utc('2026-11-05 16:00'), $estadia->termina_at->format('Y-m-d H:i:s'));
        $this->assertSame('despacho', $estadia->datos['motivo_salida']);
        $this->assertSame('CG-0001', $estadia->datos['carga']);
        $this->assertSame('C1', $estadia->datos['camara_ingreso']);
        $this->assertSame(1, $estadia->datos['interrupciones']);
        $this->assertFalse($estadia->datos['salida_estimada']);
        $this->assertSame($cliente, $estadia->cliente_id);
        $this->assertSame(['bultos' => 1, 'cajas' => 120, 'pallets' => 1, 'saldos' => 0], collect($estadia->medidas)->sortKeys()->all());
    }

    public function test_una_estadia_abierta_se_cierra_en_una_version_nueva_al_despachar(): void
    {
        $camara = $this->camara('C1');
        $folio = $this->folio('PT-0002', $this->clienteGlobal('EXPORTADORA DEMO'));
        $this->movimiento($folio, null, $camara, '2026-11-03 09:00');
        $this->ubicar($folio, $camara, '2026-11-03 09:00');

        $this->capturar(['estadia']);
        $abierta = $this->hechos('estadia')->sole();
        $this->assertNull($abierta->termina_at);

        $this->travel(5)->minutes();
        $this->movimiento($folio, $camara, null, '2026-11-04 11:55');
        $this->despachar($folio, '2026-11-04 12:00');
        $this->capturar(['estadia']);

        $versiones = $this->hechos('estadia', soloVigentes: false);
        $this->assertSame([1, 2], $versiones->pluck('version')->all());
        $this->assertSame($this->utc('2026-11-04 12:00'), $versiones->last()->termina_at->format('Y-m-d H:i:s'));
        $this->assertSame(Hecho::MOTIVO_CAMBIO, $versiones->last()->motivo_version);
    }

    public function test_el_repaletizaje_cierra_los_origenes_y_abre_la_estadia_del_folio_nuevo(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $camara = $this->camara('C1');
        $primero = $this->folio('PT-0010', $cliente, cajas: 60, tipoBulto: 'saldo');
        $segundo = $this->folio('PT-0011', $cliente, cajas: 60, tipoBulto: 'saldo');
        foreach ([$primero, $segundo] as $folio) {
            $this->movimiento($folio, null, $camara, '2026-11-01 10:00');
            $this->ubicar($folio, $camara, '2026-11-01 10:00');
        }
        $this->capturar(['estadia']);

        // Repaletizaje sin folio conservado: el nuevo hereda la ubicación del primero.
        $this->travel(5)->minutes();
        $nuevo = $this->folio('PT-0012', $cliente, cajas: 120);
        $repaletizaje = $this->repaletizar([$primero, $segundo], $nuevo, '2026-11-06 12:00');
        DB::table('ubicaciones_actuales')->where('folio_id', $primero)->update(['folio_id' => $nuevo, 'updated_at' => now()]);
        $this->quitarUbicacion($segundo);
        $this->actualizarFolio($primero, ['estado_operacional' => 'agotado']);
        $this->actualizarFolio($segundo, ['estado_operacional' => 'agotado']);
        $this->capturar(['estadia']);

        $estadias = $this->hechos('estadia')->keyBy('referencia');
        $this->assertSame($this->utc('2026-11-06 12:00'), $estadias['PT-0010']->termina_at->format('Y-m-d H:i:s'));
        $this->assertSame('repaletizaje', $estadias['PT-0010']->datos['motivo_salida']);
        $this->assertSame('RP-0001', $estadias['PT-0011']->datos['repaletizaje_salida']);
        $this->assertSame(60, $estadias['PT-0011']->medidas['cajas']);
        $this->assertSame($this->utc('2026-11-06 12:00'), $estadias['PT-0012']->ocurrido_at->format('Y-m-d H:i:s'));
        $this->assertNull($estadias['PT-0012']->termina_at);
        $this->assertSame('RP-0001', $estadias['PT-0012']->datos['repaletizaje_ingreso']);

        // Si el repaletizaje se anula, el folio nuevo nunca existió y los orígenes siguen en cámara.
        $this->travel(5)->minutes();
        DB::table('repaletizajes')->where('id', $repaletizaje)->update(['estado' => 'anulado', 'updated_at' => now()]);
        DB::table('ubicaciones_actuales')->where('folio_id', $nuevo)->update(['folio_id' => $primero, 'updated_at' => now()]);
        $this->ubicar($segundo, $camara, '2026-11-01 10:00');
        $this->actualizarFolio($primero, ['estado_operacional' => 'disponible']);
        $this->actualizarFolio($segundo, ['estado_operacional' => 'disponible']);
        $this->actualizarFolio($nuevo, ['estado_operacional' => 'anulado', 'activo' => false]);
        $this->capturar(['estadia']);

        $estadias = $this->hechos('estadia')->keyBy('referencia');
        $this->assertSame(Hecho::ESTADO_ANULADO, $estadias['PT-0012']->estado);
        $this->assertNull($estadias['PT-0010']->termina_at);
        $this->assertNull($estadias['PT-0011']->termina_at);
        $this->assertSame(Hecho::ESTADO_VIGENTE, $estadias['PT-0010']->estado);
    }

    public function test_un_folio_anulado_cierra_su_estadia_cuando_se_anulo(): void
    {
        $camara = $this->camara('C1');
        $folio = $this->folio('PT-0020', $this->clienteGlobal('EXPORTADORA DEMO'));
        $this->movimiento($folio, null, $camara, '2026-11-01 10:00');
        $this->sinLlaves(fn () => DB::table('anulaciones_validacion_pallet')->insert([
            'id' => (string) Str::uuid(), 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64),
            'validacion_pallet_id' => (string) Str::uuid(), 'folio_id' => $folio, 'numero_folio' => 'PT-0020',
            'motivo_categoria' => 'folio_incorrecto', 'motivo' => 'Prueba', 'anulado_por_user_id' => $this->usuarioOperacion(),
            'anulado_at' => $this->utc('2026-11-02 07:30'), 'snapshot' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->actualizarFolio($folio, ['estado_operacional' => 'anulado', 'activo' => false]);

        $this->capturar(['estadia']);

        $estadia = $this->hechos('estadia')->sole();
        $this->assertSame($this->utc('2026-11-02 07:30'), $estadia->termina_at->format('Y-m-d H:i:s'));
        $this->assertSame('anulacion', $estadia->datos['motivo_salida']);
    }

    public function test_un_termino_sin_evento_usa_la_ultima_salida_de_camara(): void
    {
        $camara = $this->camara('C1');
        $folio = $this->folio('PT-0030', $this->clienteGlobal('EXPORTADORA DEMO'));
        $this->movimiento($folio, null, $camara, '2026-11-01 10:00');
        $this->movimiento($folio, $camara, null, '2026-11-03 18:00');
        $this->actualizarFolio($folio, ['estado_operacional' => 'retirado_definitivo']);

        $this->capturar(['estadia']);

        $estadia = $this->hechos('estadia')->sole();
        $this->assertSame($this->utc('2026-11-03 18:00'), $estadia->termina_at->format('Y-m-d H:i:s'));
        $this->assertSame('retiro_definitivo', $estadia->datos['motivo_salida']);
        $this->assertTrue($estadia->datos['salida_estimada']);
    }

    public function test_solo_cuentan_las_camaras_de_producto_y_un_retiro_largo_queda_a_la_vista(): void
    {
        $productos = $this->camara('C1');
        $materiales = $this->camara('M1', contenido: 'materiales');
        $enMateriales = $this->folio('PT-0040', $this->clienteGlobal('EXPORTADORA DEMO'));
        $this->movimiento($enMateriales, null, $materiales, '2026-11-01 10:00');

        $fuera = $this->folio('PT-0041', null, 'EXPORTADORA SIN VINCULAR');
        $this->movimiento($fuera, null, $productos, '2026-11-01 10:00');
        $this->movimiento($fuera, $productos, null, '2026-11-02 10:00');

        $this->capturar(['estadia']);

        $estadia = $this->hechos('estadia')->sole();
        $this->assertSame('PT-0041', $estadia->referencia);
        $this->assertNull($estadia->termina_at, 'Mientras el folio siga vigente, la planta lo custodia.');
        $this->assertSame(
            CarbonImmutable::parse($this->utc('2026-11-02 10:00'), 'UTC')->toIso8601String(),
            $estadia->datos['fuera_de_camara_desde'],
        );
        $this->assertNull($estadia->cliente_id, 'Sin composición vinculada ni un cliente con ese nombre.');
        $this->assertSame('EXPORTADORA SIN VINCULAR', $estadia->cliente_nombre);
    }

    public function test_movimientos_del_mismo_segundo_se_ordenan_por_continuidad(): void
    {
        $origen = $this->camara('C1');
        $destino = $this->camara('C2');
        $folio = $this->folio('PT-0050', $this->clienteGlobal('EXPORTADORA DEMO'));
        $this->movimiento($folio, null, $origen, '2026-11-01 10:00');
        // Un plan retira y vuelve a ubicar el folio en el mismo segundo, en cualquier orden de id.
        $this->movimiento($folio, null, $destino, '2026-11-02 09:00:00');
        $this->movimiento($folio, $origen, null, '2026-11-02 09:00:00');
        $this->ubicar($folio, $destino, '2026-11-02 09:00');

        $this->capturar(['estadia']);

        $estadia = $this->hechos('estadia')->sole();
        $this->assertNull($estadia->termina_at);
        $this->assertNull($estadia->datos['fuera_de_camara_desde']);
        $this->assertSame(1, $estadia->datos['interrupciones']);
    }

    /** @param  list<string>  $origenes */
    private function repaletizar(array $origenes, string $resultado, string $hora): string
    {
        $id = (string) Str::uuid();
        $ubicaciones = DB::table('ubicaciones_actuales')->whereIn('folio_id', $origenes)->pluck('camara_id', 'folio_id');
        $this->sinLlaves(function () use ($id, $origenes, $resultado, $hora, $ubicaciones): void {
            DB::table('repaletizajes')->insert([
                'id' => $id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'codigo' => 'RP-0001',
                'modalidad' => 'consolidacion', 'tipo_resultado' => 'pallet', 'estrategia_folio' => 'nuevo',
                'folio_resultante_id' => $resultado, 'cantidad_resultante' => 120, 'condicion_termica' => 'prefrio_aprobado',
                'campos_mix' => '[]', 'snapshot' => '{}', 'estado' => 'confirmado', 'user_id' => $this->usuarioOperacion(),
                'confirmado_at' => $this->utc($hora), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($origenes as $orden => $origen) {
                DB::table('repaletizaje_detalles')->insert([
                    'id' => (string) Str::uuid(), 'repaletizaje_id' => $id, 'folio_origen_id' => $origen, 'orden' => $orden + 1,
                    'es_folio_conservado' => false, 'cajas_antes' => 60, 'cajas_aportadas' => 60, 'cajas_despues' => 0,
                    'tipo_bulto_antes' => 'saldo', 'estado_antes' => 'disponible', 'estado_despues' => 'agotado',
                    'snapshot_antes' => json_encode(['ubicacion' => isset($ubicaciones[$origen]) ? ['camara_id' => $ubicaciones[$origen]] : null]),
                    'snapshot_despues' => '{}', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('repaletizaje_resultados')->insert([
                'id' => (string) Str::uuid(), 'repaletizaje_id' => $id, 'folio_id' => $resultado, 'orden' => 1,
                'tipo_resultado' => 'pallet', 'cantidad_resultante' => 120, 'hereda_ubicacion' => true, 'snapshot' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $id;
    }
}
