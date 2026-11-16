<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\GuiaRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\ReasignacionEnvases;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

/**
 * Pedido del encargado de cuentas corrientes (14-11-2026): mover una guía completa de envases a otro
 * productor CSG o a otra cuenta corriente sin tocar kilos, Validación MP ni cantidades.
 */
class ReasignacionEnvasesTest extends TestCase
{
    use PreparaInspeccionesEnvases, PreparaRecepcionValidadaCorregible, RefreshDatabase;

    private const MOTIVO = 'Los envases de esta guía son de otro productor.';

    public function test_una_guia_de_un_camion_con_varias_pasa_a_otro_csg_sin_tocar_kilos_ni_lotes(): void
    {
        [$recepcion, $segmentos] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $lote = $this->lote($segmentos[1]);
        $cliente = $recepcion->cliente;
        $tercero = $this->asociar($cliente, $this->productorCsg('CSG-CORR-C', 'Agrícola Tres'));
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $antes = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $existencia = $cuenta->existencia($recepcion->temporada_id);
        $this->comoAdministrador();

        // El ingreso con fruta se reasigna por guía: cada guía del camión es un documento.
        $opciones = $this->getJson("/api/envases/cuenta-corriente/movimientos/{$this->ingreso($recepcion)->id}/reasignacion")->assertOk()->json('data');
        $this->assertSame(['1511', '1512'], array_column($opciones, 'numero'));
        $this->assertSame([['CSG-CORR'], ['CSG-CORR-B']], array_map(fn ($o) => array_column($o['actual']['productores'], 'codigo'), $opciones));
        $this->assertSame([2, 49], array_column($opciones[1]['envases'], 'cantidad'));
        $this->assertTrue($opciones[1]['disponible']);

        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $this->payload($opciones[1]['clave'], $cliente, $tercero))
            ->assertCreated()->assertJsonPath('data.vigente', true)->assertJsonPath('data.destino.productor.codigo', 'CSG-CORR-C');

        $despues = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $grupos = collect($despues['detalle_csg'])->keyBy('codigo');
        $this->assertSame(['CSG-CORR', 'CSG-CORR-C'], $grupos->keys()->sort()->values()->all());
        $this->assertSame(['Guía de ingreso N° 1512'], array_column($grupos['CSG-CORR-C']['documentos'], 'titulo'));
        $this->assertSame('Ingreso con fruta', $grupos['CSG-CORR-C']['documentos'][0]['movimiento']);
        $this->assertSame([2, 49], array_column($grupos['CSG-CORR-C']['documentos'][0]['lineas'], 'entrada'));
        $this->assertStringContainsString('Chofer Conductor de prueba', $grupos['CSG-CORR-C']['documentos'][0]['chofer']);
        // CSG-CORR conserva su guía 1511 y la salida del destare; sin rastro de la reasignación para el cliente.
        $this->assertSame(['Guía de ingreso N° 1511', 'Guía de salida N° GS-CORR-1'], array_column($grupos['CSG-CORR']['documentos'], 'titulo'));
        $this->assertStringNotContainsString('Reasignaci', json_encode($despues['detalle_csg'], JSON_UNESCAPED_UNICODE));
        // La cuenta del cliente y la existencia física no cambian; los kilos y el lote tampoco.
        $this->assertSame(array_column($antes['tipos'], 'saldo_final'), array_column($despues['tipos'], 'saldo_final'));
        $this->assertEquals($existencia, $cuenta->existencia($recepcion->temporada_id));
        $this->assertSame(18000.0, (float) $recepcion->refresh()->peso_neto);
        $this->assertSame($lote->kilos_netos_confirmados, LoteMateriaPrima::findOrFail($lote->id)->kilos_netos_confirmados);

        $pdf = $this->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$cliente->id)->assertOk()->getContent();
        $win = fn (string $texto): string => preg_quote(iconv('UTF-8', 'Windows-1252', $texto), '/');
        $this->assertMatchesRegularExpression('/'.$win('(CSG CSG-CORR-C · Agrícola Tres)').'.*?'.$win('(Guía de ingreso N° 1512 · Chofer Conductor de prueba').'/s', $pdf);
        $this->assertStringNotContainsString('CSG CSG-CORR-B', $pdf);

        // La otra guía muestra ahora dónde está la reasignada, y el kardex guarda el motivo.
        $opciones = $this->getJson("/api/envases/cuenta-corriente/movimientos/{$this->ingreso($recepcion)->id}/reasignacion")->json('data');
        $this->assertTrue($opciones[1]['reasignada']);
        $this->assertSame('CSG-CORR-C', $opciones[1]['actual']['productores'][0]['codigo']);
        $this->assertSame(['CSG-CORR-B'], array_column($opciones[1]['origen']['productores'], 'codigo'));
        $kardex = collect(iterator_to_array($cuenta->kardex(['temporada_id' => $recepcion->temporada_id, 'cliente_id' => $cliente->id])));
        $this->assertSame([self::MOTIVO], $kardex->where('movimiento', 'Reasignación: entra la guía')->pluck('motivo_correccion')->unique()->values()->all());
    }

    public function test_una_guia_pasa_a_otra_cuenta_corriente_bloquea_correcciones_y_se_deshace(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias();
        $cliente = $recepcion->cliente;
        $otra = Cliente::create(['codigo' => 'CLI-OTRA', 'nombre' => 'Exportadora otra', 'activo' => true]);
        $productor = $this->asociar($otra, $this->productorCsg('CSG-OTRA', 'Agrícola Otra'));
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $this->comoAdministrador();
        $opciones = $this->getJson("/api/envases/cuenta-corriente/movimientos/{$this->ingreso($recepcion)->id}/reasignacion")->json('data');
        $guia = collect($opciones)->firstWhere('numero', '1511');
        $this->assertSame('CLI-CORR', $guia['actual']['cliente']['codigo']);

        // Una cuenta o productor que no corresponde se rechaza antes de mover nada.
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $this->payload($guia['clave'], $cliente, $productor))
            ->assertUnprocessable()->assertJsonValidationErrors('productor_csg_id');
        $registro = $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $payload = $this->payload($guia['clave'], $otra, $productor))
            ->assertCreated()->json('data');
        // Repetir la misma operación no duplica asientos.
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $payload)->assertCreated()->assertJsonPath('data.id', $registro['id']);
        $this->assertSame(2, MovimientoEnvase::where('tipo_movimiento', TipoMovimientoEnvase::ReasignacionEntrada->value)->count());
        // Con la lectura anterior (sin reasignación vigente) ya no se puede volver a reasignar.
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', [...$payload, 'operacion_id' => (string) Str::uuid()])->assertConflict();

        $origen = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $destino = $cuenta->estadoCuenta($otra, $recepcion->temporada, null, null);
        $this->assertNotContains('1511', array_column($origen['movimientos'], 'documento'));
        $this->assertSame(['1511', '1511'], array_column($destino['movimientos'], 'documento'));
        $this->assertSame(['Recepción con fruta', 'Recepción con fruta'], array_column($destino['movimientos'], 'movimiento'));
        $this->assertSame([2, 48], array_column($destino['tipos'], 'saldo_final'));
        $this->assertSame([-2, -48], array_column($origen['tipos'], 'saldo_final'));
        $this->assertSame('CSG-OTRA', $destino['detalle_csg'][0]['codigo']);
        $this->assertStringContainsString('Recepción '.$recepcion->numero_recepcion, $destino['detalle_csg'][0]['documentos'][0]['referencia']);
        $saldos = collect($this->getJson('/api/envases/cuenta-corriente/movimientos')->json('balances'))
            ->mapWithKeys(fn ($b) => [$b['cliente']['codigo'].'|'.$b['tipo_envase'] => $b['saldo']]);
        $this->assertSame([-2, -48, 2, 48], [$saldos['CLI-CORR|bins'], $saldos['CLI-CORR|totes'], $saldos['CLI-OTRA|bins'], $saldos['CLI-OTRA|totes']]);

        $origenExcel = $cuenta->operacionesPorProductor($cliente, $recepcion->temporada, []);
        $destinoExcel = $cuenta->operacionesPorProductor($otra, $recepcion->temporada, []);
        $this->assertSame(['1512', 'GS-CORR-1'], array_column($origenExcel[0]['movimientos'], 'guia'));
        $this->assertSame('CSG-OTRA', $destinoExcel[0]['codigo']);
        $entradaExcel = $destinoExcel[0]['movimientos'][0];
        $this->assertSame(['1511', 'Ingreso con fruta', 2, 48], [$entradaExcel['guia'], $entradaExcel['movimiento'], $entradaExcel['bins'], $entradaExcel['totes']]);

        // Mientras está reasignada, la recepción no se corrige, ajusta ni anula.
        $mensaje = 'La guía 1511 está reasignada a Exportadora otra · CSG CSG-OTRA.';
        $estado = $this->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $estado['version'], 'motivo' => 'Conteo real de bins: 3.',
            'envases' => [['tipo_envase' => 'bins', 'recibidos' => 3]]])->assertConflict()
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, $mensaje));
        // Cualquier asiento nuevo sobre la recepción (corrección, anulación, conciliación) se rechaza en el servidor.
        try {
            MovimientoEnvase::create([...$this->ingreso($recepcion)->only(['temporada_id', 'cliente_id', 'recepcion_romana_id', 'documento_tipo',
                'documento_id', 'numero_documento', 'tipo_envase', 'propiedad']), 'operacion_id' => (string) Str::uuid(),
                'tipo_movimiento' => 'correccion_cantidad', 'cantidad' => 1, 'signo_cuenta' => -1, 'signo_existencia' => -1,
                'movimiento_origen_id' => $this->ingreso($recepcion)->id, 'ocurrido_at' => now(), 'estado_revision' => 'pendiente',
                'creado_por_user_id' => User::factory()->create()->id]);
            $this->fail('La corrección debió rechazarse.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringStartsWith($mensaje, $error->getMessage());
        }

        // Deshacer deja todo como estaba: la guía vuelve a su cuenta y productor.
        $this->postJson("/api/envases/cuenta-corriente/reasignaciones/{$registro['id']}/deshacer", ['operacion_id' => (string) Str::uuid(),
            'motivo' => 'La guía sí era de esta cuenta.'])->assertOk()->assertJsonPath('data.deshace', true);
        $origen = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $this->assertSame([0, 0], array_column($origen['tipos'], 'saldo_final'));
        $this->assertContains('1511', array_column($origen['movimientos'], 'documento'));
        $this->assertSame([], $cuenta->estadoCuenta($otra, $recepcion->temporada, null, null)['movimientos']);
        $this->assertSame([], $cuenta->operacionesPorProductor($otra, $recepcion->temporada, []));
        $this->assertContains('1511', array_column($cuenta->operacionesPorProductor($cliente, $recepcion->temporada, [])[0]['movimientos'], 'guia'));
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $recepcion->refresh()->version, 'motivo' => 'Conteo real de bins: 3.',
            'envases' => [['tipo_envase' => 'bins', 'recibidos' => 3]]])->assertOk();
    }

    public function test_la_salida_del_destare_y_un_despacho_se_reasignan_y_el_numero_de_guia_corregido_los_sigue(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $cliente = $recepcion->cliente;
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $this->comoAdministrador();
        $salida = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'salida_recepcion_fruta')->firstOrFail();
        [$documento] = $this->getJson("/api/envases/cuenta-corriente/movimientos/{$salida->id}/reasignacion")->json('data');
        $this->assertSame('entregados', $documento['lado']);
        $this->assertSame([-4, -97], array_column($documento['envases'], 'cantidad'));
        $this->assertSame(['CSG-CORR'], array_column($documento['actual']['productores'], 'codigo'));
        $segundo = ProductorCsg::where('codigo', 'CSG-CORR-B')->firstOrFail();
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $this->payload($documento['clave'], $cliente, $segundo))->assertCreated();

        $grupos = collect($cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null)['detalle_csg'])->keyBy('codigo');
        $this->assertSame(['Guía de ingreso N° 1512', 'Guía de salida N° GS-CORR-1'], array_column($grupos['CSG-CORR-B']['documentos'], 'titulo'));
        $this->assertSame('Entregados en el destare', $grupos['CSG-CORR-B']['documentos'][1]['movimiento']);
        $this->assertSame([4, 97], array_column($grupos['CSG-CORR-B']['documentos'][1]['lineas'], 'salida'));
        $this->assertNotContains('Guía de salida N° GS-CORR-1', array_column($grupos['CSG-CORR']['documentos'], 'titulo'));

        // El N° de guía corregido llega también a una guía reasignada.
        $guia = GuiaRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('numero_guia_despacho', '1511')->sole();
        $otra = GuiaRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('numero_guia_despacho', '1512')->sole();
        [$ingreso1511] = $this->getJson("/api/envases/cuenta-corriente/movimientos/{$this->ingreso($recepcion)->id}/reasignacion")->json('data');
        $tercero = $this->asociar($cliente, $this->productorCsg('CSG-CORR-C', 'Agrícola Tres'));
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $this->payload($ingreso1511['clave'], $cliente, $tercero))->assertCreated();
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/numero-guia", ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $recepcion->refresh()->version, 'motivo' => 'Se digitó 1511; la guía física dice 1521.',
            'guias' => [['id' => $guia->id, 'numero_guia_despacho' => '1521'], ['id' => $otra->id, 'numero_guia_despacho' => '1512']]])->assertOk();
        $grupos = collect($cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null)['detalle_csg'])->keyBy('codigo');
        $this->assertSame(['Guía de ingreso N° 1521'], array_column($grupos['CSG-CORR-C']['documentos'], 'titulo'));

        // Despacho de envases a otra cuenta corriente.
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $otraCuenta = Cliente::create(['codigo' => 'CLI-DESP', 'nombre' => 'Exportadora despacho', 'activo' => true]);
        $destino = $this->asociar($otraCuenta, $this->productorCsg('CSG-DESP', 'Agrícola Despacho'));
        $despacho = $this->despacho($cliente, $temporada, $segundo);
        $movimiento = MovimientoEnvase::where('documento_id', $despacho->id)->firstOrFail();
        [$documento] = $this->getJson("/api/envases/cuenta-corriente/movimientos/{$movimiento->id}/reasignacion")->json('data');
        $this->assertSame('Guía de despacho N° 7001', $documento['titulo']);
        $this->assertSame(['CSG-CORR-B'], array_column($documento['actual']['productores'], 'codigo'));
        $registro = $this->postJson('/api/envases/cuenta-corriente/reasignaciones', $this->payload($documento['clave'], $otraCuenta, $destino))->assertCreated()->json('data');
        $estado = $cuenta->estadoCuenta($otraCuenta, $temporada, null, null);
        $this->assertSame(['7001'], array_column($estado['movimientos'], 'documento'));
        $this->assertSame('Guía de despacho N° 7001', $estado['detalle_csg'][0]['documentos'][0]['titulo']);
        $this->assertSame([-10], array_column($estado['tipos'], 'saldo_final'));
        // El despacho reasignado no cambia de productor ni se anula sin deshacer antes.
        $this->putJson("/api/envases/guias-despacho/{$despacho->id}/productor", ['productor_csg_id' => ProductorCsg::where('codigo', 'CSG-CORR')->value('id'),
            'motivo' => 'Otro productor.', 'version' => $despacho->version])->assertConflict();

        // Reasignar otra vez mueve la guía desde donde está hoy, sin dejar restos.
        $vuelta = $this->asociar($otraCuenta, $this->productorCsg('CSG-DESP-2', 'Agrícola Despacho Dos'));
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', [...$this->payload($documento['clave'], $otraCuenta, $vuelta),
            'reasignacion_vigente_id' => $registro['id']])->assertCreated();
        $estado = $cuenta->estadoCuenta($otraCuenta, $temporada, null, null);
        $this->assertSame(['CSG-DESP-2'], array_column($estado['detalle_csg'], 'codigo'));
        $this->assertSame([-10], array_column($estado['tipos'], 'saldo_final'));
        $this->assertNotContains('7001', array_column($cuenta->estadoCuenta($cliente, $temporada, null, null)['movimientos'], 'documento'));

        // El reinicio operacional de la temporada también limpia las reasignaciones, antes que despachos y recepciones.
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true, 'password' => 'password']);
        $this->actingAs($administrador, 'sanctum')->postJson("/api/administracion/temporadas/{$temporada->id}/reinicio-operacional", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Cierre controlado de la etapa de pruebas integrales.', 'password' => 'password',
            'confirmacion' => "REINICIAR {$temporada->codigo}", 'confirmar_exclusion_bodega' => true, 'confirmar_preservar_configuracion' => true,
        ])->assertCreated();
        $this->assertSame(0, ReasignacionEnvases::count());
        $this->assertDatabaseMissing('guias_despacho_envases', ['id' => $despacho->id]);
    }

    private function payload(string $clave, Cliente $cliente, ProductorCsg $productor, ?string $vigente = null): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'documento_clave' => $clave, 'reasignacion_vigente_id' => $vigente,
            'cliente_destino_id' => $cliente->id, 'productor_csg_id' => $productor->id, 'motivo' => self::MOTIVO];
    }

    private function asociar(Cliente $cliente, ProductorCsg $productor): ProductorCsg
    {
        $usuario = User::factory()->create();
        $cliente->productoresCsg()->attach($productor->id, ['id' => (string) Str::uuid(), 'activo' => true,
            'asociado_por_user_id' => $usuario->id, 'actualizado_por_user_id' => $usuario->id]);

        return $productor;
    }

    private function ingreso(RecepcionRomana $recepcion): MovimientoEnvase
    {
        return MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'recepcion_fruta')
            ->where('tipo_envase', 'bins')->firstOrFail();
    }

    /** Despacho confirmado de 10 bins propios, con su asiento en la cuenta. */
    private function despacho(Cliente $cliente, Temporada $temporada, ProductorCsg $productor): GuiaDespachoEnvase
    {
        $usuario = User::factory()->create();
        $guia = GuiaDespachoEnvase::create(['operacion_id' => (string) Str::uuid(), 'numero' => 'GDE-REASIG', 'numero_guia_despacho' => '7001',
            'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id, 'cliente_codigo_snapshot' => $cliente->codigo,
            'cliente_nombre_snapshot' => $cliente->nombre, 'estado' => 'confirmada', 'salida_at' => now(), 'confirmado_at' => now(),
            'productor_csg_id' => $productor->id, 'productor_snapshot' => ['codigo' => $productor->codigo, 'nombre' => $productor->razon_social],
            'patente_camion' => 'GDEX11', 'nombre_conductor' => 'Chofer despacho', 'version' => 1, 'creado_por_user_id' => $usuario->id]);
        MovimientoEnvase::create(['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'documento_tipo' => 'guia_despacho_envases', 'documento_id' => $guia->id, 'numero_documento' => 'GDE-REASIG',
            'tipo_movimiento' => 'despacho_cliente', 'tipo_envase' => 'bins', 'cantidad' => 10, 'signo_cuenta' => -1, 'signo_existencia' => -1,
            'propiedad' => 'propia', 'ocurrido_at' => now(), 'salida_at' => now(), 'estado_revision' => 'pendiente', 'creado_por_user_id' => $usuario->id]);

        return $guia;
    }
}
