<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\Captura\ContextoCaptura;
use App\Finanzas\Models\Hecho;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CantidadesHistoricasTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    private string $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00', 'America/Santiago'));
        $this->cliente = $this->clienteGlobal('CLIENTE HISTORICO');
    }

    public function test_aporte_parcial_conserva_una_estadia_y_las_cantidades_del_prefrio(): void
    {
        $folio = $this->enCamara();
        $this->prefrio($folio, '2026-11-02 10:00');
        $this->capturar(['prefrio', 'estadia']);
        $this->travel(5)->minutes();
        $this->repaletizar($folio, 120, 80, '2026-11-05 10:00');
        $this->capturar(['prefrio', 'estadia'], conciliar: true);

        $prefrio = $this->hechos('prefrio')->sole();
        $this->assertSame([120, 1, 0, 1], [$prefrio->medidas['cajas'], $prefrio->medidas['pallets'], $prefrio->medidas['saldos'], $prefrio->version]);
        $estadia = $this->hechos('estadia')->sole();
        $this->assertNull($estadia->termina_at);
        $this->assertSame(120, $estadia->medidas['cajas']);
        $tramos = $estadia->datos['tramos'];
        $this->assertSame([120, 80], array_column(array_column($tramos, 'medidas'), 'cajas'));
        $this->assertSame([1, 0], array_column(array_column($tramos, 'medidas'), 'pallets'));
        $this->assertSame($this->iso('2026-11-05 10:00'), $tramos[0]['hasta_at']);
        $this->assertSame($tramos[0]['hasta_at'], $tramos[1]['desde_at']);
        $this->assertNull($tramos[1]['hasta_at']);
        $this->assertSame('RP-HIST-01', $tramos[1]['repaletizaje_inicio']);
    }

    public function test_primera_captura_despues_de_varios_repaletizajes_reconstruye_cada_servicio(): void
    {
        $folio = $this->enCamara();
        $primero = $this->prefrio($folio, '2026-11-02 10:00');
        $this->repaletizar($folio, 120, 80, '2026-11-05 10:00');
        $segundo = $this->prefrio($folio, '2026-11-07 10:00');
        $this->repaletizar($folio, 80, 60, '2026-11-10 10:00', codigo: 'RP-HIST-02', tipoAntes: 'saldo');
        $this->despachar($folio, '2026-11-15 10:00');
        $this->actualizarFolio($folio, ['estado_operacional' => 'despachado']);
        $this->quitarUbicacion($folio);
        $this->capturar(['prefrio', 'estadia']);

        $prefrios = $this->hechos('prefrio')->keyBy('origen_id');
        $this->assertSame(120, $prefrios[$primero]->medidas['cajas']);
        $this->assertSame(80, $prefrios[$segundo]->medidas['cajas']);
        $this->assertSame(1, $prefrios[$segundo]->medidas['saldos']);
        $estadia = $this->hechos('estadia')->sole();
        $tramos = $estadia->datos['tramos'];
        $this->assertSame([120, 80, 60], array_column(array_column($tramos, 'medidas'), 'cajas'));
        $cajasDia = 0;
        foreach ($tramos as $indice => $tramo) {
            if ($indice > 0) {
                $this->assertSame($tramos[$indice - 1]['hasta_at'], $tramo['desde_at']);
            }
            $dias = CarbonImmutable::parse($tramo['desde_at'])->diffInHours(CarbonImmutable::parse($tramo['hasta_at'])) / 24;
            $cajasDia += $dias * $tramo['medidas']['cajas'];
        }
        $this->assertSame(1180.0, $cajasDia);
    }

    public function test_consumo_completo_no_convierte_servicios_pasados_en_saldos_de_cero_cajas(): void
    {
        $folio = $this->enCamara();
        $this->prefrio($folio, '2026-11-02 10:00');
        $this->repaletizar($folio, 120, 0, '2026-11-05 10:00');
        $this->capturar(['prefrio', 'estadia']);
        foreach (['prefrio', 'estadia'] as $fuente) {
            $hecho = $this->hechos($fuente)->sole();
            $this->assertSame(120, $hecho->medidas['cajas']);
            $this->assertSame(1, $hecho->medidas['pallets']);
            $this->assertSame('pallet', $hecho->datos['tipo_bulto']);
        }
        $estadia = $this->hechos('estadia')->sole();
        $this->assertSame($this->utc('2026-11-05 10:00'), $estadia->termina_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, $estadia->datos['tramos']);
    }

    public function test_folio_conservado_aumenta_sus_cajas_sin_reescribir_el_tramo_anterior(): void
    {
        $folio = $this->enCamara(40, 'saldo');
        $this->prefrio($folio, '2026-11-02 10:00');
        $this->repaletizar($folio, 40, 120, '2026-11-05 10:00', conservado: true, tipoAntes: 'saldo', tipoDespues: 'pallet');
        $this->capturar(['prefrio', 'estadia']);
        $this->assertSame(40, $this->hechos('prefrio')->sole()->medidas['cajas']);
        $estadia = $this->hechos('estadia')->sole();
        $this->assertNull($estadia->termina_at);
        $this->assertSame([40, 120], array_column(array_column($estadia->datos['tramos'], 'medidas'), 'cajas'));
        $this->assertSame([1, 0], array_column(array_column($estadia->datos['tramos'], 'medidas'), 'saldos'));
    }

    public function test_anular_el_repaletizaje_reconstruye_la_custodia_original(): void
    {
        $folio = $this->enCamara();
        $repa = $this->repaletizar($folio, 120, 80, '2026-11-05 10:00');
        $this->capturar(['estadia']);
        DB::table('repaletizajes')->where('id', $repa)->update(['estado' => 'anulado', 'updated_at' => now()]);
        $this->actualizarFolio($folio, ['tipo_bulto' => 'pallet', 'datos_externos' => json_encode(['cantidad_cajas' => 120])]);
        $this->capturar(['estadia'], conciliar: true);
        $hecho = $this->hechos('estadia')->sole();
        $this->assertCount(1, $hecho->datos['tramos']);
        $this->assertSame(120, $hecho->medidas['cajas']);
        $this->assertNull($hecho->termina_at);
    }

    public function test_cambios_del_mismo_segundo_no_crean_tramos_de_duracion_cero(): void
    {
        $folio = $this->enCamara();
        $this->repaletizar($folio, 120, 80, '2026-11-05 10:00');
        $this->repaletizar($folio, 80, 60, '2026-11-05 10:00', codigo: 'RP-HIST-02', tipoAntes: 'saldo');
        $this->capturar(['estadia']);
        $tramos = $this->hechos('estadia')->sole()->datos['tramos'];
        $this->assertSame([120, 60], array_column(array_column($tramos, 'medidas'), 'cajas'));
        $this->assertSame($tramos[0]['hasta_at'], $tramos[1]['desde_at']);
    }

    public function test_conciliacion_repara_hechos_previos_anula_duplicados_y_es_idempotente(): void
    {
        $folio = $this->enCamara();
        $this->repaletizar($folio, 120, 80, '2026-11-05 10:00');
        $this->capturar(['estadia']);
        $anterior = $this->hechos('estadia')->sole();
        $anterior->update(['medidas' => ['bultos' => 1, 'pallets' => 0, 'saldos' => 1, 'cajas' => 80], 'datos' => [], 'huella' => str_repeat('a', 64)]);
        $duplicado = $anterior->replicate();
        $duplicado->origen_id = "{$folio}#2";
        $duplicado->save();
        (require database_path('migrations/2026_10_18_110000_reconciliar_cantidades_historicas_finanzas.php'))->up();
        $lectura = $this->capturar(['estadia']);
        $this->assertTrue($lectura->resumen['estadia']['conciliacion']);
        $vigentes = $this->hechos('estadia')->keyBy('origen_id');
        $this->assertSame(120, $vigentes["{$folio}#1"]->medidas['cajas']);
        $this->assertSame(Hecho::ESTADO_ANULADO, $vigentes["{$folio}#2"]->estado);
        $this->assertSame(80, $anterior->fresh()->medidas['cajas'], 'El historial anterior permanece intacto.');
        $cantidad = Hecho::query()->count();
        $this->capturar(['estadia'], conciliar: true);
        $this->assertSame($cantidad, Hecho::query()->count());
    }

    private function enCamara(int $cajas = 120, string $tipo = 'pallet'): string
    {
        $folio = $this->folio('PT-HIST-01', $this->cliente, cajas: $cajas, tipoBulto: $tipo);
        $camara = $this->camara('CH');
        $this->movimiento($folio, null, $camara, '2026-11-01 10:00');
        $this->ubicar($folio, $camara, '2026-11-01 10:00');

        return $folio;
    }

    private function repaletizar(string $folio, int $antes, int $despues, string $hora, bool $conservado = false, string $codigo = 'RP-HIST-01', string $tipoAntes = 'pallet', string $tipoDespues = 'saldo'): string
    {
        $id = (string) Str::uuid();
        $resultado = $conservado ? $folio : $this->folio('RESULT-'.$codigo, $this->cliente);
        $this->sinLlaves(function () use ($id, $folio, $antes, $despues, $hora, $conservado, $codigo, $tipoAntes, $tipoDespues, $resultado): void {
            DB::table('repaletizajes')->insert([
                'id' => $id, 'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64), 'codigo' => $codigo,
                'modalidad' => 'consolidacion', 'tipo_resultado' => 'pallet', 'estrategia_folio' => $conservado ? 'conservar' : 'nuevo',
                'folio_resultante_id' => $resultado, 'folio_conservado_id' => $conservado ? $folio : null,
                'cantidad_resultante' => $conservado ? $despues : $antes - $despues, 'condicion_termica' => 'prefrio_aprobado',
                'campos_mix' => '[]', 'snapshot' => '{}', 'estado' => 'confirmado', 'user_id' => $this->usuarioOperacion(),
                'confirmado_at' => $this->utc($hora), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('repaletizaje_detalles')->insert([
                'id' => (string) Str::uuid(), 'repaletizaje_id' => $id, 'folio_origen_id' => $folio, 'orden' => 1,
                'es_folio_conservado' => $conservado, 'cajas_antes' => $antes, 'cajas_aportadas' => max(0, $antes - $despues), 'cajas_despues' => $despues,
                'tipo_bulto_antes' => $tipoAntes, 'tipo_bulto_despues' => $tipoDespues, 'estado_antes' => 'disponible',
                'estado_despues' => $despues === 0 ? 'agotado' : 'disponible', 'snapshot_antes' => '{}', 'snapshot_despues' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $datos = json_decode(DB::table('folios')->where('id', $folio)->value('datos_externos'), true);
        $datos['cantidad_cajas'] = $despues;
        $datos['composicion'] = $despues === 0 ? [] : [['csg' => '100001', 'cantidad_cajas' => $despues]];
        $this->actualizarFolio($folio, ['tipo_bulto' => $tipoDespues, 'activo' => $despues > 0,
            'estado_operacional' => $despues === 0 ? 'agotado' : 'disponible', 'datos_externos' => json_encode($datos)]);
        if ($despues === 0) {
            $this->quitarUbicacion($folio);
        }

        return $id;
    }

    private function prefrio(string $folio, string $fin): string
    {
        $id = (string) Str::uuid();
        $proceso = (string) Str::uuid();
        $this->sinLlaves(function () use ($id, $proceso, $folio, $fin): void {
            DB::table('procesos_prefrio')->insert([
                'id' => $proceso, 'temporada_id' => $this->temporadaFinanzas(), 'codigo' => 'PF-'.Str::random(8),
                'operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'tunel_prefrio_id' => (string) Str::uuid(),
                'estado' => 'aprobado', 'setpoint' => -1.5, 'creado_por_user_id' => $this->usuarioOperacion(),
                'finalizado_at' => $this->utc($fin), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('procesos_prefrio_folios')->insert([
                'id' => $id, 'proceso_prefrio_id' => $proceso, 'folio_id' => $folio, 'posicion_tunel_prefrio_id' => (string) Str::uuid(),
                'estado' => 'aprobado', 'cargado_por_user_id' => $this->usuarioOperacion(), 'cargado_at' => $this->utc($fin),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $id;
    }

    private function iso(string $hora): string
    {
        return ContextoCaptura::fecha($this->utc($hora))->toIso8601String();
    }
}
