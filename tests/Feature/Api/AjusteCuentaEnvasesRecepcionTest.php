<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\RolUsuario;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\EventoRecepcionRomana;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Romana\ServicioAjusteCuentaEnvasesRecepcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

class AjusteCuentaEnvasesRecepcionTest extends TestCase
{
    use PreparaInspeccionesEnvases, PreparaRecepcionValidadaCorregible, RefreshDatabase;

    private const MOTIVO = 'Conteo real de bins en patio: 3, no 4. Revisado con el chofer.';

    public function test_ajusta_solo_la_cuenta_aunque_el_lote_ya_tenga_entregas_a_proceso(): void
    {
        [$recepcion, $segmentos] = $this->recepcionCerradaConDosGuias();
        $lote = $this->lote($segmentos[0]);
        $lote->update(['estado' => EstadoLoteMateriaPrima::EntregaParcialProceso]);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->assertOk()
            ->assertJsonPath('data.cuenta_envases.disponible', true)->assertJsonPath('data.cuenta_envases.exige_productor', false)
            ->assertJsonPath('data.cuenta_envases.entregados_editables', true)->json('data');
        $bins = collect($estado['cuenta_envases']['tipos'])->firstWhere('tipo_envase', 'bins');
        $this->assertSame([4, 4, 4, 4], [$bins['validados'], $bins['recibidos_cuenta'], $bins['recibidos_registro'], $bins['entregados_cuenta']]);

        // La corrección completa sigue bloqueada por el lote; la de solo cuenta no lo toca.
        $completa = $this->payloadCompleto($estado);
        $completa['segmentos'][0]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 48]];
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $completa)->assertConflict()
            ->assertJsonPath('message', 'El lote LOTE-CORR-1 ya tiene entregas a proceso: su conteo no se puede cambiar sin anular esas entregas.');

        $payload = $this->payload($estado, ['bins' => ['recibidos' => 3]]);
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", $payload)->assertOk()
            ->assertJsonPath('data.correccion_integral.cuenta_envases.tipos.0.recibidos_cuenta', 3)
            ->assertJsonPath('data.correccion_integral.cuenta_envases.tipos.0.recibidos_registro', 4)
            ->assertJsonPath('data.correccion_integral.cuenta_envases.ajustes.0.diferencia', -1);

        // Guías, validación, lotes y kilos quedan como estaban.
        $recepcion->refresh();
        $this->assertSame(18000.0, (float) $recepcion->peso_neto);
        $this->assertSame(4, DetalleEnvaseRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')->value('cantidad_validada'));
        $this->assertSame(4, DetalleEnvaseRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')->value('cantidad_declarada'));
        $this->assertEquals([['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 48]],
            GuiaRecepcionRomana::where('numero_guia_despacho', '1511')->sole()->envases);
        $this->assertSame(2, $lote->refresh()->load('envasesDetalle')->envasesDetalle->firstWhere('tipo_envase.value', 'bins')->cantidad);

        // Un asiento de cuenta sin efecto físico, fechado junto al ingreso.
        $ajuste = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'ajuste_cuenta')->sole();
        $ingreso = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'recepcion_fruta')->where('tipo_envase', 'bins')->sole();
        $this->assertSame([1, -1, 0, 'cliente'], [$ajuste->cantidad, $ajuste->signo_cuenta, $ajuste->signo_existencia, $ajuste->propiedad->value]);
        $this->assertSame($ingreso->ocurrido_at->addSecond()->toAtomString(), $ajuste->ocurrido_at->toAtomString());
        $this->assertSame(['recibidos', 4, 3, -1, 'CSG-CORR'], [$ajuste->datos['ajuste_recepcion']['lado'], $ajuste->datos['ajuste_recepcion']['registro'],
            $ajuste->datos['ajuste_recepcion']['correcto'], $ajuste->datos['ajuste_recepcion']['diferencia'], $ajuste->datos['ajuste_recepcion']['productor']['codigo']]);
        $this->assertSame(0, (int) MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_envase', 'bins')
            ->get()->sum(fn ($m) => $m->cantidad * $m->signo_existencia));
        $evento = EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'ajuste_cuenta_envases')->sole();
        $this->assertSame([self::MOTIVO, 'Bins plástico recibidos 4 → 3'], [$evento->datos['motivo'], $evento->datos['resumen']]);

        // Estado de cuenta: el ajuste va en su propia línea, con signo, motivo y el CSG de la recepción.
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $linea = collect($cuenta['movimientos'])->firstWhere('movimiento', "Ajuste de recibidos de {$recepcion->numero_recepcion} (administración)");
        $this->assertSame([-1, null, 'CSG CSG-CORR', '1511 / 1512'], [$linea['entrada'], $linea['salida'], $linea['productor'], $linea['documento']]);
        $this->assertSame(-1, collect($cuenta['tipos'])->firstWhere('tipo_envase', 'bins')['saldo_final']);
        $grupo = collect($cuenta['detalle_csg'])->firstWhere('codigo', 'CSG-CORR');
        $documento = collect($grupo['documentos'])->firstWhere('titulo', 'Ajuste a la guía de ingreso N° 1511 / 1512');
        $this->assertSame('Ajuste de recibidos por administración', $documento['movimiento']);
        $this->assertStringContainsString('Motivo: '.self::MOTIVO, $documento['referencia']);
        $this->assertSame([['tipo_envase' => 'Bins plástico', 'entrada' => -1, 'salida' => null, 'saldo' => -1]], $documento['lineas']);
        $this->assertSame(3, collect($grupo['totales'])->firstWhere('tipo_envase', 'Bins plástico')['entradas']);
        $resumen = collect($cuenta['resumen_csg'])->firstWhere('codigo', 'CSG-CORR');
        $this->assertSame([3, 4], [collect($resumen['tipos'])->firstWhere('tipo_envase', 'bins')['entradas'], collect($resumen['tipos'])->firstWhere('tipo_envase', 'bins')['salidas']]);

        // RPR-01 y Cuenta Envases lo explican.
        $this->assertStringContainsString('Bins plástico recibidos 4 → 3. Motivo: '.self::MOTIVO,
            app(ServicioAjusteCuentaEnvasesRecepcion::class)->resumen($recepcion));
        $this->getJson('/api/envases/cuenta-corriente/movimientos?temporada_id='.$recepcion->temporada_id)->assertOk()
            ->assertJsonFragment(['tipo_movimiento' => 'ajuste_cuenta', 'impacto_cuenta' => -1, 'impacto_existencia' => 0, 'puede_corregir_saldo_historico' => false]);

        // Repetir la operación no duplica el ajuste.
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", $payload)->assertOk();
        $this->assertSame(1, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'ajuste_cuenta')->count());
    }

    public function test_un_nuevo_ajuste_reemplaza_al_anterior_y_ajusta_los_entregados_del_destare(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias();
        $ruta = "/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases";
        $estado = fn (): array => $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');
        $this->putJson($ruta, $this->payload($estado(), ['bins' => ['recibidos' => 3]]))->assertOk();
        // El camión salió vacío: los 97 totes no salieron. Y los bins eran 2, no 3.
        $this->putJson($ruta, $this->payload($estado(), ['bins' => ['recibidos' => 2], 'totes' => ['entregados' => 0]]))->assertOk();

        $ajustes = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->whereIn('tipo_movimiento', ['ajuste_cuenta', 'reversion_ajuste_cuenta'])->get();
        $asientos = $ajustes->map(fn ($m) => $m->tipo_movimiento->value.' '.$m->tipo_envase->value.' '.($m->cantidad * $m->signo_cuenta))->sort()->values()->all();
        $this->assertSame(['ajuste_cuenta bins -1', 'ajuste_cuenta bins -2', 'ajuste_cuenta totes 97', 'reversion_ajuste_cuenta bins 1'], $asientos);
        $totes = $ajustes->firstWhere('tipo_envase.value', 'totes');
        $salida = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'salida_recepcion_fruta')->where('tipo_envase', 'totes')->sole();
        $this->assertSame(['entregados', 97, 0], [$totes->datos['ajuste_recepcion']['lado'], $totes->datos['ajuste_recepcion']['registro'], $totes->datos['ajuste_recepcion']['correcto']]);
        $this->assertSame([$salida->ocurrido_at->addSecond()->toAtomString(), 'GS-CORR-1'], [$totes->ocurrido_at->toAtomString(), $totes->numero_documento]);

        // El estado de cuenta muestra solo el ajuste vigente de cada lado.
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $lineas = collect($cuenta['movimientos'])->filter(fn ($l) => str_starts_with($l['movimiento'], 'Ajuste de'))
            ->map(fn ($l) => [$l['movimiento'], $l['tipo_envase'], $l['entrada'], $l['salida']])->values()->all();
        $this->assertSame([
            ["Ajuste de recibidos de {$recepcion->numero_recepcion} (administración)", 'Bins plástico', -2, null],
            ["Ajuste de entregados de {$recepcion->numero_recepcion} (administración)", 'Tote', null, -97],
        ], $lineas);
        $saldos = collect($cuenta['tipos'])->mapWithKeys(fn ($t) => [$t['tipo_envase'] => [$t['entradas'], $t['salidas'], $t['saldo_final']]])->all();
        $this->assertSame(['bins' => [2, 4, -2], 'totes' => [97, 0, 97]], $saldos);
        $this->assertSame('Ajuste a la guía de salida N° GS-CORR-1', collect(collect($cuenta['detalle_csg'])->firstWhere('codigo', 'CSG-CORR')['documentos'])
            ->firstWhere('movimiento', 'Ajuste de entregados por administración')['titulo']);

        // Volver a lo registrado deja la cuenta como estaba y sin líneas de ajuste.
        $this->putJson($ruta, $this->payload($estado(), ['bins' => ['recibidos' => 4], 'totes' => ['entregados' => 97]]))->assertOk()
            ->assertJsonCount(3, 'data.correccion_integral.cuenta_envases.ajustes')
            ->assertJsonPath('data.correccion_integral.cuenta_envases.ajustes.0.vigente', false);
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $this->assertEmpty(collect($cuenta['movimientos'])->filter(fn ($l) => str_starts_with($l['movimiento'], 'Ajuste de')));
        $this->assertSame(0, collect($cuenta['tipos'])->sum('saldo_final'));
        $this->assertNull(app(ServicioAjusteCuentaEnvasesRecepcion::class)->resumen($recepcion));
    }

    public function test_la_correccion_completa_no_vuelve_a_corregir_un_envase_ajustado_en_cuenta(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias();
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", $this->payload($estado, ['bins' => ['recibidos' => 3]]))->assertOk();
        $estado = $this->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');

        $completa = $this->payloadCompleto($estado);
        $completa['segmentos'][0]['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 48]];
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $completa)->assertConflict()
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'Bins plástico tiene un ajuste de cuenta de administración en los recibidos'))->etc());
        // Otros cambios sí pasan.
        $completa = $this->payloadCompleto($estado);
        $completa['guias'][1]['numero_guia_despacho'] = '1522';
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral", $completa)->assertOk();
        $this->assertSame(1, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'ajuste_cuenta')->count());
    }

    public function test_con_varios_csg_pide_el_productor_de_la_diferencia(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")
            ->assertJsonPath('data.cuenta_envases.exige_productor', true)
            ->assertJsonPath('data.cuenta_envases.productores.1.codigo', 'CSG-CORR-B')->json('data');
        $ruta = "/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases";
        $this->putJson($ruta, $this->payload($estado, ['totes' => ['recibidos' => 96]]))->assertUnprocessable()->assertJsonValidationErrors('productor_csg');
        $this->putJson($ruta, [...$this->payload($estado, ['totes' => ['recibidos' => 96]]), 'productor_csg' => 'CSG-CORR-B'])->assertOk();

        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $grupo = collect($cuenta['detalle_csg'])->firstWhere('codigo', 'CSG-CORR-B');
        $documento = collect($grupo['documentos'])->firstWhere('movimiento', 'Ajuste de recibidos por administración');
        $this->assertSame(-1, $documento['lineas'][0]['entrada']);
        $this->assertSame(48, collect($grupo['totales'])->firstWhere('tipo_envase', 'Tote')['entradas']);
    }

    public function test_solo_administracion_ajusta_y_respeta_version_cambios_y_destare(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(cerrar: false);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")
            ->assertJsonPath('data.cuenta_envases.entregados_editables', false)->json('data');
        $ruta = "/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases";
        $payload = $this->payload($estado, ['bins' => ['recibidos' => 3]]);

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]), 'sanctum')
            ->putJson($ruta, $payload)->assertForbidden();
        $this->comoAdministrador()->putJson($ruta, [...$payload, 'version_conocida' => 1])->assertConflict();
        $this->putJson($ruta, $this->payload($estado, []))->assertConflict()->assertJsonPath('message', 'El ajuste no presenta cambios respecto de la cuenta.');
        $this->putJson($ruta, $this->payload($estado, ['bins' => ['entregados' => 1]]))->assertUnprocessable()->assertJsonValidationErrors('envases.0.entregados');
        $this->putJson($ruta, [...$payload, 'motivo' => 'corto'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'ajuste_cuenta')->count());
        $this->assertSame(0, EventoRecepcionRomana::where('tipo', 'ajuste_cuenta_envases')->count());
        $this->assertSame($estado['version'], RecepcionRomana::findOrFail($recepcion->id)->version);
    }

    /** @param array<string, array{recibidos?: int, entregados?: int}> $cambios */
    private function payload(array $estado, array $cambios): array
    {
        $cuenta = $estado['cuenta_envases'];

        return [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $estado['version'], 'motivo' => self::MOTIVO,
            'envases' => array_map(fn (array $t): array => [
                'tipo_envase' => $t['tipo_envase'],
                'recibidos' => $cambios[$t['tipo_envase']]['recibidos'] ?? $t['recibidos_cuenta'],
                'entregados' => $cuenta['entregados_editables'] ? ($cambios[$t['tipo_envase']]['entregados'] ?? $t['entregados_cuenta'])
                    : ($cambios[$t['tipo_envase']]['entregados'] ?? null),
            ], $cuenta['tipos']),
        ];
    }

    private function payloadCompleto(array $estado): array
    {
        return [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $estado['version'], 'motivo_correccion' => 'Corrección completa de prueba.',
            'guias' => array_map(fn ($g) => ['id' => $g['id'], 'numero_guia_despacho' => $g['numero_guia_despacho'], 'envases' => $g['envases']], $estado['guias']),
            'segmentos' => array_map(fn ($s) => ['id' => $s['id'], 'envases' => $s['envases']], $estado['segmentos']),
            'propiedades' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'propiedad' => $e['propiedad']], $estado['envases']),
            'destare' => $estado['destare'] === null ? null : [
                'peso_tara' => $estado['destare']['peso_tara'], 'modo_salida_envases' => $estado['destare']['modo_salida_envases'],
                'numero_guia_salida' => $estado['destare']['numero_guia_salida'], 'salida_envases' => $estado['destare']['salida_envases'],
                'taras_envases' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'tara_unitaria' => $e['tara_unitaria_salida']], $estado['envases']),
            ],
        ];
    }
}
