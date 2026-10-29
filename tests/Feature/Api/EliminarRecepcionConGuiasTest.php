<?php

namespace Tests\Feature\Api;

use App\Models\Cliente;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionesEnvases;
use Tests\TestCase;

class EliminarRecepcionConGuiasTest extends TestCase
{
    use PreparaCorreccionesEnvases, RefreshDatabase;

    public function test_una_guia_cancelada_con_origenes_mezclados_no_impide_eliminar_la_recepcion(): void
    {
        [$r, $origen, $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $otro = $this->otroOrigen($temporada->id, $admin->id, 'totes', 864);
        $guia = $this->crearGuia($cliente->id, [[$origen, 36], [$otro, 864]]);
        $this->postJson('/api/envases/guias-despacho/'.$guia.'/cancelar', ['motivo' => 'Carga cancelada antes de salir'])->assertOk();

        $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertOk();

        $this->assertSame('cancelada', GuiaDespachoEnvase::findOrFail($guia)->estado->value);
        $this->assertSame(2, DetalleGuiaDespachoEnvase::where('guia_despacho_envase_id', $guia)->count());
        $this->assertSame(138, $origen->refresh()->cantidad);
        $this->assertSame(864, $otro->refresh()->cantidad);
        $this->assertSame(0, MovimientoEnvase::where('documento_id', $guia)->count());
        $this->assertSame(0, $this->saldo('signo_existencia', $cliente));
        $inventario = collect(app(ServicioGuiaDespachoEnvases::class)->inventario($temporada)['origenes'])->keyBy('id');
        $this->assertFalse($inventario->has($origen->id));
        $this->assertSame(864, $inventario[$otro->id]['disponible']);
    }

    public function test_no_confunde_las_salidas_de_otro_origen_con_la_existencia_de_esta_recepcion(): void
    {
        [$r, $origen, $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $otro = $this->otroOrigen($temporada->id, $admin->id, 'bins', 138);
        $salidaAjena = $this->crearGuia($cliente->id, [[$otro, 100]]);
        $this->confirmarGuia($salidaAjena);
        $cancelada = $this->crearGuia($cliente->id, [[$origen, 36]]);
        $this->postJson('/api/envases/guias-despacho/'.$cancelada.'/cancelar', ['motivo' => 'La carga fue cancelada'])->assertOk();
        // El destinatario de otra salida no identifica qué recepción fue consumida.
        $this->assertSame(38, $this->saldo('signo_existencia', $cliente));

        $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertOk();

        $this->assertSame('confirmada', GuiaDespachoEnvase::findOrFail($salidaAjena)->estado->value);
        $inventario = collect(app(ServicioGuiaDespachoEnvases::class)->inventario($temporada)['origenes'])->keyBy('id');
        $this->assertFalse($inventario->has($origen->id));
        $this->assertSame(38, $inventario[$otro->id]['fisico']);
        $this->assertSame(1, MovimientoEnvase::where('documento_id', $salidaAjena)->count());
    }

    public function test_bloquea_la_reserva_y_el_despacho_vigentes_pero_admite_su_anulacion_compensada(): void
    {
        [$r, $origen, $cliente] = $this->ingresoEnvases();
        $guia = $this->crearGuia($cliente->id, [[$origen, 36]]);
        $ruta = '/api/romana/recepciones/'.$r->id;
        $mensaje = $this->deleteJson($ruta, $this->datos($r->version))->assertConflict()->json('message');
        $this->assertStringContainsString(GuiaDespachoEnvase::findOrFail($guia)->numero, $mensaje);
        $this->assertStringContainsString('Borrador', $mensaje);
        $this->assertStringContainsString('36 Bins plástico (arrendados)', $mensaje);
        $this->confirmarGuia($guia);
        $mensaje = $this->deleteJson($ruta, $this->datos($r->version))->assertConflict()->json('message');
        $this->assertStringContainsString('Confirmada', $mensaje);
        $this->assertStringContainsString('guía emitida 991001', $mensaje);
        $this->postJson('/api/envases/guias-despacho/'.$guia.'/anular', ['motivo' => 'Despacho revertido completamente'])->assertOk();
        $datos = $this->datos($r->version);

        $this->deleteJson($ruta, $datos)->assertOk();
        $this->deleteJson($ruta, $datos)->assertOk();

        $this->assertSame('anulada', GuiaDespachoEnvase::findOrFail($guia)->estado->value);
        $this->assertSame(2, MovimientoEnvase::where('documento_id', $guia)->count());
        $this->assertSame(1, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
        $this->assertSame(0, $this->saldo('signo_cuenta', $cliente));
        $this->assertSame(0, $this->saldo('signo_existencia', $cliente));
    }

    public function test_no_ignora_una_guia_anulada_cuya_reversa_no_compensa_el_despacho(): void
    {
        [$r, $origen, $cliente] = $this->ingresoEnvases();
        $guia = $this->crearGuia($cliente->id, [[$origen, 36]]);
        $this->confirmarGuia($guia);
        $this->postJson('/api/envases/guias-despacho/'.$guia.'/anular', ['motivo' => 'Anular el despacho'])->assertOk();
        // Simula un historial antiguo incoherente: el estado no basta para autorizar el retiro.
        DB::table('movimientos_envases')->where('documento_id', $guia)
            ->where('tipo_movimiento', 'reversion_despacho')->update(['cantidad' => 35]);

        $mensaje = $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict()->json('message');
        $this->assertStringContainsString(GuiaDespachoEnvase::findOrFail($guia)->numero, $mensaje);
        $this->assertStringContainsString('Anulada', $mensaje);
        $this->assertStringContainsString('36 Bins plástico', $mensaje);
        $this->assertStringContainsString('35 Bins plástico', $mensaje);

        $this->assertNotSame('anulada', $r->refresh()->estado->value);
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    private function crearGuia(string $clienteId, array $origenes): string
    {
        return $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(), 'cliente_id' => $clienteId, 'salida_at' => now()->toAtomString(),
            'detalles' => array_map(fn ($item) => ['tipo_envase' => $item[0]->tipo_envase->value,
                'propiedad' => $item[0]->propiedad->value, 'movimiento_origen_id' => $item[0]->id,
                'cantidad' => $item[1]], $origenes),
        ])->assertCreated()->json('data.id');
    }

    public function test_no_deja_existencia_negativa_por_una_salida_historica_sin_origen(): void
    {
        [$r, , $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $cancelada = $this->crearGuia($cliente->id, [[$r->movimientosEnvases()->first(), 36]]);
        $this->postJson('/api/envases/guias-despacho/'.$cancelada.'/cancelar', ['motivo' => 'Cancelar reserva'])->assertOk();
        $salida = MovimientoEnvase::create(['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id, 'documento_tipo' => 'salida_recepcion_fruta', 'documento_id' => (string) Str::uuid(),
            'numero_documento' => 'SALIDA-ANTERIOR', 'tipo_movimiento' => 'salida_recepcion_fruta',
            'tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => 100,
            'signo_cuenta' => -1, 'signo_existencia' => -1, 'ocurrido_at' => now(), 'creado_por_user_id' => $admin->id]);

        $mensaje = $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict()->json('message');
        $this->assertStringContainsString($r->numero_recepcion, $mensaje);
        $this->assertStringContainsString('SALIDA-ANTERIOR', $mensaje);
        $this->assertStringContainsString($salida->id, $mensaje);
        $this->assertStringContainsString('Se deben retirar 138', $mensaje);
        $this->assertStringContainsString('existencia total de planta: 38', $mensaje);
        $this->assertStringContainsString('Faltan 100', $mensaje);
        $this->assertStringContainsString('sin origen individual', $mensaje);
        $this->assertStringNotContainsString(GuiaDespachoEnvase::findOrFail($cancelada)->numero, $mensaje);

        $this->assertSame(38, $this->saldo('signo_existencia', $cliente));
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    public function test_identifica_el_despacho_historico_que_impide_calcular_el_inventario(): void
    {
        [$r, , $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $salida = $this->salidaHistorica($cliente->id, $temporada->id, $admin->id, 20, 'GUIA-HISTORICA-721');
        $salida->update(['tipo_movimiento' => 'despacho_cliente']);

        $mensaje = $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict()->json('message');

        $this->assertStringContainsString('GUIA-HISTORICA-721', $mensaje);
        $this->assertStringContainsString($salida->id, $mensaje);
        $this->assertStringContainsString('20 Bins plástico (arrendados)', $mensaje);
        $this->assertStringContainsString('no pueden atribuirse a esta recepción', $mensaje);
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    public function test_identifica_correcciones_vinculadas_aunque_no_tengan_numero_de_documento(): void
    {
        [$r, $origen, $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $correccion = $this->salidaHistorica($cliente->id, $temporada->id, $admin->id, 12, '');
        $correccion->update(['tipo_movimiento' => 'correccion_cuenta', 'signo_existencia' => 0,
            'movimiento_origen_id' => $origen->id]);

        $mensaje = $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict()->json('message');

        $this->assertStringContainsString($correccion->documento_id, $mensaje);
        $this->assertStringContainsString($correccion->id, $mensaje);
        $this->assertStringContainsString($origen->id, $mensaje);
        $this->assertStringContainsString($cliente->nombre, $mensaje);
        $this->assertStringContainsString('12 Bins plástico', $mensaje);
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    public function test_detalla_el_saldo_pendiente_sin_acusar_las_salidas_completamente_compensadas(): void
    {
        [$r, , $cliente, $temporada, $admin] = $this->ingresoEnvases();
        $vigente = $this->salidaHistorica($cliente->id, $temporada->id, $admin->id, 60, 'SALIDA-VIGENTE');
        $parcial = $this->salidaHistorica($cliente->id, $temporada->id, $admin->id, 60, 'SALIDA-PARCIAL');
        $compensada = $this->salidaHistorica($cliente->id, $temporada->id, $admin->id, 100, 'SALIDA-COMPENSADA');
        foreach ([[$parcial, 20], [$compensada, 100]] as [$salida, $cantidad]) {
            $reversa = $salida->replicate(['operacion_id'])->fill(['operacion_id' => (string) Str::uuid(),
                'tipo_movimiento' => 'reversion_salida_fruta', 'cantidad' => $cantidad,
                'signo_cuenta' => 1, 'signo_existencia' => 1, 'movimiento_origen_id' => $salida->id]);
            $reversa->save();
        }

        $mensaje = $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict()->json('message');

        $this->assertStringContainsString($vigente->numero_documento, $mensaje);
        $this->assertStringContainsString($parcial->numero_documento, $mensaje);
        $this->assertStringContainsString('saldo sin compensar: 40', $mensaje);
        $this->assertStringNotContainsString($compensada->numero_documento, $mensaje);
        $this->assertStringContainsString('existencia total de planta: 38', $mensaje);
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    public function test_muestra_la_recepcion_y_documento_de_una_salida_que_afecta_el_saldo_global(): void
    {
        [$r, , , $temporada, $admin] = $this->ingresoEnvases();
        [$otra, , $cliente] = $this->ingresoEnvases();
        $salida = $this->salidaHistorica($cliente->id, $temporada->id, $admin->id, 200, 'SALIDA-OTRA-RECEPCION');
        $salida->update(['recepcion_romana_id' => $otra->id, 'documento_tipo' => 'recepcion_romana', 'documento_id' => $otra->id]);

        $mensaje = $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict()->json('message');

        $this->assertStringContainsString('Recepción '.$otra->numero_recepcion, $mensaje);
        $this->assertStringContainsString($salida->numero_documento, $mensaje);
        $this->assertStringContainsString('pueden pertenecer a otras recepciones', $mensaje);
        $this->assertStringContainsString('disponibles de sus orígenes: 138', $mensaje);
        $this->assertStringContainsString('existencia total de planta: 76', $mensaje);
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    private function salidaHistorica(string $clienteId, string $temporadaId, int $usuarioId, int $cantidad, string $documento): MovimientoEnvase
    {
        return MovimientoEnvase::create(['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporadaId,
            'cliente_id' => $clienteId, 'documento_tipo' => 'salida_recepcion_fruta', 'documento_id' => (string) Str::uuid(),
            'numero_documento' => $documento, 'tipo_movimiento' => 'salida_recepcion_fruta',
            'tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => $cantidad,
            'signo_cuenta' => -1, 'signo_existencia' => -1, 'ocurrido_at' => now(), 'creado_por_user_id' => $usuarioId]);
    }

    private function confirmarGuia(string $id): void
    {
        $this->postJson('/api/envases/guias-despacho/'.$id.'/confirmar', ['numero_guia_despacho' => '991001'])->assertOk();
    }

    private function otroOrigen(string $temporadaId, int $usuarioId, string $tipo, int $cantidad): MovimientoEnvase
    {
        $cliente = Cliente::create(['codigo' => 'PROBINS', 'nombre' => 'Probins', 'activo' => true]);

        return MovimientoEnvase::create(['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporadaId,
            'cliente_id' => $cliente->id, 'documento_tipo' => 'recepcion_legacy', 'documento_id' => (string) Str::uuid(),
            'numero_documento' => '13817', 'tipo_movimiento' => 'recepcion_arriendo', 'tipo_envase' => $tipo,
            'propiedad' => 'arrendada', 'cantidad' => $cantidad, 'signo_cuenta' => 1, 'signo_existencia' => 1,
            'ocurrido_at' => now(), 'creado_por_user_id' => $usuarioId]);
    }

    private function datos(int $version): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $version,
            'motivo' => 'Eliminar recepción de prueba cuya carga fue cancelada.'];
    }
}
