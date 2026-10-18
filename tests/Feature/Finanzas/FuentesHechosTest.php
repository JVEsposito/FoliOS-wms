<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\Models\Hecho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FuentesHechosTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
    }

    public function test_validacion_pt_versiona_correcciones_y_anula_con_la_operacion(): void
    {
        $cliente = $this->clienteGlobal('Exportadora Demo SpA');
        $validacion = $this->validacion('PT-0200', $cliente, cajas: 120, hora: '2026-11-04 07:45');
        $this->capturar(['validacion_pt']);

        $hecho = $this->hechos('validacion_pt')->sole();
        $this->assertSame('PT-0200', $hecho->referencia);
        $this->assertSame($cliente, $hecho->cliente_id, 'El cliente de Validación está vinculado al cliente global.');
        $this->assertSame('Exportadora Demo SpA', $hecho->cliente_nombre);
        $this->assertSame(['bultos' => 1, 'cajas' => 120, 'pallets' => 1, 'saldos' => 0], collect($hecho->medidas)->sortKeys()->all());
        $this->assertSame('Santina', $hecho->datos['variedad']);
        $this->assertSame('2026-11-04', $hecho->fecha_operacional->toDateString());

        $this->travel(5)->minutes();
        DB::table('validaciones_pallet')->where('id', $validacion)->update(['cantidad_cajas' => 110, 'tipo_bulto' => 'saldo', 'updated_at' => now()]);
        $this->capturar(['validacion_pt']);
        $this->assertSame(['bultos' => 1, 'cajas' => 110, 'pallets' => 0, 'saldos' => 1], collect($this->hechos('validacion_pt')->sole()->medidas)->sortKeys()->all());

        $this->travel(5)->minutes();
        DB::table('validaciones_pallet')->where('id', $validacion)->update(['estado' => 'anulada', 'updated_at' => now()]);
        $this->capturar(['validacion_pt']);
        $ultima = $this->hechos('validacion_pt')->sole();
        $this->assertSame([3, Hecho::ESTADO_ANULADO], [$ultima->version, $ultima->estado]);
    }

    public function test_prefrio_aprobado_es_hecho_y_un_reproceso_lo_anula(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $folio = $this->folio('PT-0300', $cliente, cajas: 96, tipoBulto: 'saldo');
        $asignacion = $this->prefrio($folio, 'aprobado', '2026-11-05 06:00', '2026-11-05 18:30');
        $this->capturar(['prefrio']);

        $hecho = $this->hechos('prefrio')->sole();
        $this->assertSame($asignacion, $hecho->origen_id);
        $this->assertSame('PT-0300', $hecho->referencia);
        $this->assertSame('PF-0001', $hecho->datos['proceso']);
        $this->assertSame('T1', $hecho->datos['tunel']);
        $this->assertSame(750, $hecho->datos['minutos_enfriado']);
        $this->assertSame($this->utc('2026-11-05 18:30'), $hecho->ocurrido_at->format('Y-m-d H:i:s'));
        $this->assertSame(['bultos' => 1, 'cajas' => 96, 'pallets' => 0, 'saldos' => 1], collect($hecho->medidas)->sortKeys()->all());

        $this->travel(5)->minutes();
        DB::table('procesos_prefrio_folios')->where('id', $asignacion)->update(['estado' => 'requiere_reproceso', 'updated_at' => now()]);
        $this->capturar(['prefrio']);
        $this->assertSame(Hecho::ESTADO_ANULADO, $this->hechos('prefrio')->sole()->estado);
    }

    public function test_despacho_repaletizaje_y_recepcion_embalada(): void
    {
        $cliente = $this->clienteGlobal('EXPORTADORA DEMO');
        $folio = $this->folio('PT-0400', $cliente, cajas: 108);
        $cargaFolio = $this->despachar($folio, '2026-11-07 21:10', 'CG-0042');

        $origen = $this->folio('PT-0401', $cliente, cajas: 40, tipoBulto: 'saldo');
        $resultado = $this->folio('PT-0402', $cliente, cajas: 120);
        $repaletizaje = (string) Str::uuid();
        $this->sinLlaves(function () use ($repaletizaje, $origen, $resultado): void {
            DB::table('repaletizajes')->insert([
                'id' => $repaletizaje, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('e', 64), 'codigo' => 'RP-0042',
                'modalidad' => 'consolidacion', 'tipo_resultado' => 'pallet', 'estrategia_folio' => 'nuevo',
                'folio_resultante_id' => $resultado, 'cantidad_resultante' => 120, 'condicion_termica' => 'prefrio_aprobado',
                'campos_mix' => '[]', 'snapshot' => '{}', 'estado' => 'confirmado', 'user_id' => $this->usuarioOperacion(),
                'confirmado_at' => $this->utc('2026-11-06 11:00'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('repaletizaje_detalles')->insert([
                'id' => (string) Str::uuid(), 'repaletizaje_id' => $repaletizaje, 'folio_origen_id' => $origen, 'orden' => 1,
                'es_folio_conservado' => false, 'cajas_antes' => 40, 'cajas_aportadas' => 40, 'cajas_despues' => 0,
                'tipo_bulto_antes' => 'saldo', 'estado_antes' => 'disponible', 'estado_despues' => 'agotado',
                'snapshot_antes' => '{}', 'snapshot_despues' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $recepcion = $this->recepcionEmbalada($cliente);

        $this->capturar(['despacho', 'repaletizaje', 'recepcion_embalada']);

        $despacho = $this->hechos('despacho')->sole();
        $this->assertSame($cargaFolio, $despacho->origen_id);
        $this->assertSame('CG-0042', $despacho->datos['carga']);
        $this->assertSame(108, $despacho->medidas['cajas']);
        $this->assertSame('2026-11-07', $despacho->fecha_operacional->toDateString());

        $repa = $this->hechos('repaletizaje')->sole();
        $this->assertSame('RP-0042', $repa->referencia);
        $this->assertSame(['cajas' => 120, 'folios_origen' => 1, 'folios_resultantes' => 1, 'operaciones' => 1], collect($repa->medidas)->sortKeys()->all());
        $this->assertSame('PT-0402', $repa->datos['folio_resultante']);
        $this->assertSame($cliente, $repa->cliente_id);

        $embalada = $this->hechos('recepcion_embalada')->sole();
        $this->assertSame($recepcion, $embalada->origen_id);
        $this->assertSame(['bultos' => 3, 'cajas' => 300, 'pallets' => 2, 'recepciones' => 1, 'saldos' => 1], collect($embalada->medidas)->sortKeys()->all());
        $this->assertSame('PLANTA EXTERNA DE PRUEBA', $embalada->datos['planta_origen']);
        $this->assertTrue($embalada->datos['llega_con_prefrio']);
    }

    private function prefrio(string $folio, string $estado, string $inicio, string $fin): string
    {
        $tunel = (string) Str::uuid();
        $proceso = (string) Str::uuid();
        $asignacion = (string) Str::uuid();
        $this->sinLlaves(function () use ($tunel, $proceso, $asignacion, $folio, $estado, $inicio, $fin): void {
            DB::table('tuneles_prefrio')->insert([
                'id' => $tunel, 'codigo' => 'T1', 'nombre' => 'Túnel 1', 'capacidad_posiciones' => 20, 'estado_administrativo' => 'activo',
                'estado_tecnico' => 'operativo', 'creado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('procesos_prefrio')->insert([
                'id' => $proceso, 'temporada_id' => $this->temporadaFinanzas(), 'codigo' => 'PF-0001', 'operacion_id' => (string) Str::uuid(),
                'payload_hash' => str_repeat('f', 64), 'tunel_prefrio_id' => $tunel, 'estado' => 'aprobado', 'setpoint' => -1.5,
                'creado_por_user_id' => $this->usuarioOperacion(), 'iniciado_at' => $this->utc($inicio),
                'pendiente_verificacion_at' => $this->utc($fin), 'finalizado_at' => $this->utc($fin), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('procesos_prefrio_folios')->insert([
                'id' => $asignacion, 'proceso_prefrio_id' => $proceso, 'folio_id' => $folio, 'posicion_tunel_prefrio_id' => (string) Str::uuid(),
                'estado' => $estado, 'temperatura_inicial' => 9.5, 'temperatura_final' => 1.2, 'cargado_at' => $this->utc($inicio),
                'cargado_por_user_id' => $this->usuarioOperacion(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $asignacion;
    }

    private function recepcionEmbalada(string $cliente): string
    {
        $planta = (string) Str::uuid();
        $recepcion = (string) Str::uuid();
        $this->sinLlaves(function () use ($planta, $recepcion, $cliente): void {
            DB::table('plantas_origen')->insert([
                'id' => $planta, 'codigo' => 'PEXT', 'nombre' => 'PLANTA EXTERNA DE PRUEBA', 'activa' => true, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('recepciones_fruta_embalada')->insert([
                'id' => $recepcion, 'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporadaFinanzas(), 'cliente_id' => $cliente,
                'planta_origen_id' => $planta, 'numero_guia' => 'GD-7781', 'servicio' => 'almacenaje', 'turno' => 'dia',
                'validador_id' => $this->usuarioOperacion(), 'recepcion_at' => $this->utc('2026-11-08 09:00'), 'chofer' => 'Conductor de prueba',
                'patente_delantera' => 'CD5678', 'llega_con_prefrio' => true, 'estado' => 'aceptada', 'version' => 2,
                'creado_por_user_id' => $this->usuarioOperacion(), 'actualizado_por_user_id' => $this->usuarioOperacion(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([['pallet', 120], ['pallet', 120], ['saldo', 60]] as $orden => [$tipo, $cajas]) {
                DB::table('recepciones_fruta_embalada_pallets')->insert([
                    'id' => (string) Str::uuid(), 'recepcion_fruta_embalada_id' => $recepcion, 'orden' => $orden + 1,
                    'folio_origen' => 'EXT-'.($orden + 1), 'tipo_bulto' => $tipo, 'articulo_validacion_id' => (string) Str::uuid(),
                    'origen_validacion_id' => (string) Str::uuid(), 'embalaje' => 'Caja 5 kg', 'especie' => 'Cereza', 'variedad' => 'Santina',
                    'csg' => '100002', 'calibre' => '2J', 'cantidad_cajas' => $cajas, 'fecha_proceso_origen' => '2026-11-07',
                    'temperatura_pulpa_c' => 1.5, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $recepcion;
    }
}
