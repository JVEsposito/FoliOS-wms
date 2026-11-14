<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\RolUsuario;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Envases\ServicioDevolucionEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

class ProductorSalidaDestareTest extends TestCase
{
    use PreparaInspeccionesEnvases, PreparaRecepcionValidadaCorregible, RefreshDatabase;

    public function test_con_varios_csg_el_destare_exige_el_productor_que_recibe_los_envases(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(cerrar: false, dosCsg: true);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->getJson("/api/romana/recepciones/{$recepcion->id}")->assertOk()
            ->assertJsonPath('data.productores_csg', [['codigo' => 'CSG-CORR', 'nombre' => 'Agrícola Uno'], ['codigo' => 'CSG-CORR-B', 'nombre' => 'Agrícola Dos']]);
        $ruta = "/api/romana/recepciones/{$recepcion->id}/cerrar";
        $cierre = fn (array $extra = []): array => $this->payloadConInspeccionRc02($ruta, ['operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'mismos', 'numero_guia_salida' => 'GS-CORR-1', 'peso_tara' => 10000, 'tipo_envase_calculo_neto' => 'bins',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]], ...$extra]);

        $this->postJson($ruta, $cierre())->assertUnprocessable()->assertJsonValidationErrors('salida_productor_csg')
            ->assertJsonPath('errors.salida_productor_csg.0', 'El camión trae 2 CSG (CSG-CORR, CSG-CORR-B): indica a qué productor se entregan los envases que salen.');
        $this->postJson($ruta, $cierre(['salida_productor_csg' => 'OTRO']))->assertUnprocessable()->assertJsonValidationErrors('salida_productor_csg');
        $this->postJson($ruta, $cierre(['salida_productor_csg' => 'CSG-CORR-B']))->assertOk()
            ->assertJsonPath('data.salida_productor.codigo', 'CSG-CORR-B')->assertJsonPath('data.salida_productor.nombre', 'Agrícola Dos');
        $evento = EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'recepcion_cerrada')->sole();
        $this->assertSame('CSG-CORR-B', $evento->datos['salida_productor']['codigo']);

        // Estado de cuenta: la entrega va al CSG elegido, en el detalle, en el resumen y en la línea.
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $grupo = collect($cuenta['detalle_csg'])->firstWhere('codigo', 'CSG-CORR-B');
        $salida = collect($grupo['documentos'])->firstWhere('titulo', 'Guía de salida N° GS-CORR-1');
        $this->assertStringContainsString('Entregados a CSG CSG-CORR-B · Agrícola Dos (camión con 2 CSG)', $salida['referencia']);
        $resumen = collect(collect($cuenta['resumen_csg'])->firstWhere('codigo', 'CSG-CORR-B')['tipos'])->mapWithKeys(fn ($t) => [$t['tipo_envase'] => [$t['entradas'], $t['salidas']]])->all();
        $this->assertSame(['bins' => [2, 4], 'totes' => [49, 97]], $resumen);
        $this->assertNull(collect($cuenta['resumen_csg'])->firstWhere('codigo', null));
        $this->assertSame(['CSG CSG-CORR-B · Agrícola Dos'], collect($cuenta['movimientos'])->whereNotNull('salida')->pluck('productor')->unique()->values()->all());

        // Buscar la salida para una devolución precarga ese productor.
        $encontrada = app(ServicioDevolucionEnvases::class)->salida($recepcion->cliente, $recepcion->temporada, 'GS-CORR-1');
        $this->assertSame('CSG-CORR-B', $encontrada['salidas'][0]['productor']['codigo']);
    }

    public function test_la_correccion_completa_asigna_el_productor_de_una_salida_antigua_aunque_el_lote_tenga_entregas(): void
    {
        [$recepcion, $segmentos] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        // Recepción cerrada antes de que Romana pidiera el productor de la salida.
        RecepcionRomana::whereKey($recepcion->id)->update(['salida_productor_snapshot' => null]);
        $lote = $this->lote($segmentos[0]);
        $lote->update(['estado' => EstadoLoteMateriaPrima::EntregaParcialProceso]);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->assertOk()
            ->assertJsonPath('data.destare.salida_productor_csg', null)->assertJsonCount(2, 'data.productores_csg')->json('data');
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $this->assertNotNull(collect($cuenta['resumen_csg'])->firstWhere('codigo', null), 'Sin elegir, la salida queda sin productor.');

        $ruta = "/api/romana/recepciones/{$recepcion->id}/correccion-integral";
        $this->putJson($ruta, $this->payloadCompleto($estado))->assertUnprocessable()->assertJsonValidationErrors('destare.salida_productor_csg');
        $payload = $this->payloadCompleto($estado);
        $payload['destare']['salida_productor_csg'] = 'CSG-CORR';
        $this->putJson($ruta, $payload)->assertOk()->assertJsonPath('data.correccion_integral.destare.salida_productor_csg', 'CSG-CORR')
            ->assertJsonPath('data.salida_productor.codigo', 'CSG-CORR');

        // Solo cambia a quién va la salida: movimientos, lote y kilos quedan iguales.
        $this->assertSame(18000.0, (float) $recepcion->refresh()->peso_neto);
        $this->assertSame(2, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'salida_recepcion_fruta')->count());
        $this->assertSame(0, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'reversion_salida_fruta')->count());
        $this->assertSame(EstadoLoteMateriaPrima::EntregaParcialProceso, $lote->refresh()->estado);
        $evento = EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'correccion_integral')->sole();
        $this->assertSame([null, 'CSG-CORR'], [$evento->datos['anterior']['destare']['salida_productor_csg'], $evento->datos['posterior']['destare']['salida_productor_csg']]);
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $this->assertNull(collect($cuenta['resumen_csg'])->firstWhere('codigo', null));
        $this->assertSame([4, 97], array_values(collect(collect($cuenta['resumen_csg'])->firstWhere('codigo', 'CSG-CORR')['tipos'])->pluck('salidas')->all()));

        // El ajuste solo de cuenta de los entregados sigue a ese productor.
        $estado = $this->getJson($ruta)->json('data');
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $estado['version'], 'motivo' => 'Salieron 90 totes, no 97: revisado con la guía GS-CORR-1.',
            'envases' => [['tipo_envase' => 'totes', 'recibidos' => 97, 'entregados' => 90]]])->assertOk();
        $ajuste = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'ajuste_cuenta')->sole();
        $this->assertSame('CSG-CORR', $ajuste->datos['ajuste_recepcion']['productor']['codigo']);
    }

    public function test_corregir_salida_cambia_el_productor_y_el_camion_vacio_no_lo_pide(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $ruta = "/api/romana/recepciones/{$recepcion->id}/corregir-salida-envases";
        $correccion = fn (array $extra): array => $this->payloadConInspeccionRc02($ruta, ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $recepcion->refresh()->version, 'motivo_correccion' => 'La guía GS-CORR-1 se emitió al otro productor.',
            'modo_salida_envases' => 'mismos', 'numero_guia_salida' => 'GS-CORR-1', 'peso_tara' => 10000, 'tipo_envase_calculo_neto' => 'bins',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]], ...$extra]);
        $this->comoAdministrador();

        $this->postJson($ruta, $correccion(['salida_productor_csg' => 'CSG-CORR']))->assertConflict()->assertJsonPath('message', 'La salida corregida no presenta cambios.');
        $this->postJson($ruta, $correccion(['salida_productor_csg' => 'CSG-CORR-B']))->assertOk()->assertJsonPath('data.salida_productor.codigo', 'CSG-CORR-B');
        $this->postJson($ruta, $correccion(['modo_salida_envases' => 'vacio', 'numero_guia_salida' => null]))->assertOk()
            ->assertJsonPath('data.salida_productor', null);
    }

    private function payloadCompleto(array $estado): array
    {
        return [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $estado['version'], 'motivo_correccion' => 'Se indica a qué productor salieron los envases.',
            'guias' => array_map(fn ($g) => ['id' => $g['id'], 'numero_guia_despacho' => $g['numero_guia_despacho'], 'envases' => $g['envases']], $estado['guias']),
            'segmentos' => array_map(fn ($s) => ['id' => $s['id'], 'envases' => $s['envases']], $estado['segmentos']),
            'propiedades' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'propiedad' => $e['propiedad']], $estado['envases']),
            'destare' => [
                'peso_tara' => $estado['destare']['peso_tara'], 'modo_salida_envases' => $estado['destare']['modo_salida_envases'],
                'numero_guia_salida' => $estado['destare']['numero_guia_salida'], 'salida_envases' => $estado['destare']['salida_envases'],
                'salida_productor_csg' => $estado['destare']['salida_productor_csg'],
                'taras_envases' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'tara_unitaria' => $e['tara_unitaria_salida']], $estado['envases']),
            ],
        ];
    }
}
