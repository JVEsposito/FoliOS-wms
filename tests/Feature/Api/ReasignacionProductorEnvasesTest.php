<?php

namespace Tests\Feature\Api;

use App\Models\Cliente;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\ReasignacionProductorEnvases;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

/**
 * Pedido del encargado de cuentas corrientes (17-11-2026): reasignar un movimiento de entrada o salida de
 * envases a otro productor CSG de la misma cuenta, completo o por cantidades, con el estado de cuenta y el
 * Excel mostrando solo la asignación definitiva.
 */
class ReasignacionProductorEnvasesTest extends TestCase
{
    use PreparaInspeccionesEnvases, PreparaRecepcionValidadaCorregible, RefreshDatabase;

    private const MOTIVO = 'Parte de los envases de la guía son de otro productor.';

    private const RUTA = '/api/envases/cuenta-corriente/reasignaciones-productor';

    public function test_reasigna_parte_de_una_guia_a_otro_csg_y_los_documentos_muestran_solo_la_asignacion_final(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $cliente = $recepcion->cliente;
        $tercero = $this->asociar($cliente, $this->productorCsg('CSG-CORR-C', 'Agrícola Tres'));
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $antes = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $asientos = MovimientoEnvase::count();
        $this->comoAdministrador();

        // El movimiento muestra cada guía del camión con su productor, su razón social y sus cantidades.
        $opciones = $this->getJson($this->opciones($this->ingreso($recepcion)))->assertOk()->json('data');
        $this->assertSame(['Guía de ingreso N° 1511', 'Guía de ingreso N° 1512'], array_column($opciones, 'titulo'));
        $guia = $opciones[1];
        $this->assertSame([['codigo' => 'CSG-CORR-B', 'nombre' => 'Agrícola Dos']], array_map(fn ($p) => ['codigo' => $p['codigo'], 'nombre' => $p['nombre']], $guia['productores']));
        $this->assertSame([['tipo_envase' => 'bins', 'nombre' => 'Bins plástico', 'cantidad' => 2], ['tipo_envase' => 'totes', 'nombre' => 'Tote', 'cantidad' => 49]],
            $guia['productores'][0]['envases']);
        $this->assertTrue($guia['disponible']);

        // Parcial: 1 bin y 9 totes de la guía 1512 pasan de CSG-CORR-B a CSG-CORR-C.
        $payload = $this->payload($guia['clave'], 'CSG-CORR-B', $tercero, 'parcial', ['bins' => 1, 'totes' => 9]);
        $registro = $this->postJson(self::RUTA, $payload)->assertCreated()
            ->assertJsonPath('data.modalidad', 'parcial')->assertJsonPath('data.destino.codigo', 'CSG-CORR-C')->json('data');
        // Reenviar la misma operación no la repite; el mismo identificador con otros datos se rechaza.
        $this->postJson(self::RUTA, $payload)->assertCreated()->assertJsonPath('data.id', $registro['id']);
        $this->postJson(self::RUTA, [...$payload, 'motivo' => 'Otro motivo distinto para la misma operación.'])->assertConflict();
        $this->assertSame(1, ReasignacionProductorEnvases::count());
        // No se crea ningún asiento: la cuenta del cliente y su saldo no cambian.
        $this->assertSame($asientos, MovimientoEnvase::count());

        $despues = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $this->assertSame(array_column($antes['tipos'], 'saldo_final'), array_column($despues['tipos'], 'saldo_final'));
        $grupos = collect($despues['detalle_csg'])->keyBy('codigo');
        $documento = fn (string $csg, string $titulo): array => collect($grupos[$csg]['documentos'])->firstWhere('titulo', $titulo);
        $this->assertSame([1, 40], array_column($documento('CSG-CORR-B', 'Guía de ingreso N° 1512')['lineas'], 'entrada'));
        $this->assertSame([1, 9], array_column($documento('CSG-CORR-C', 'Guía de ingreso N° 1512')['lineas'], 'entrada'));
        // La guía conserva fecha, chofer y recepción en los dos productores.
        foreach (['fecha', 'chofer', 'referencia', 'movimiento'] as $campo) {
            $this->assertSame($documento('CSG-CORR-B', 'Guía de ingreso N° 1512')[$campo], $documento('CSG-CORR-C', 'Guía de ingreso N° 1512')[$campo]);
        }
        $this->assertSame([2, 48], array_column($documento('CSG-CORR', 'Guía de ingreso N° 1511')['lineas'], 'entrada'));
        $this->assertStringNotContainsString('Reasign', json_encode([$despues['detalle_csg'], $despues['resumen_csg']], JSON_UNESCAPED_UNICODE));
        $resumen = collect($despues['resumen_csg'])->keyBy('codigo');
        $this->assertSame([1, 9], array_column($resumen['CSG-CORR-C']['tipos'], 'saldo_final'));
        $this->assertSame([1, 40], array_column($resumen['CSG-CORR-B']['tipos'], 'saldo_final'));

        // Excel por productor: una fila por guía y CSG, con las cantidades definitivas.
        $excel = collect($cuenta->operacionesPorProductor($cliente, $recepcion->temporada, []))->keyBy('codigo');
        $fila = fn (string $csg, string $guia): array => collect($excel[$csg]['movimientos'])->firstWhere('guia', $guia);
        $this->assertSame([1, 40], [$fila('CSG-CORR-B', '1512')['bins'], $fila('CSG-CORR-B', '1512')['totes']]);
        $this->assertSame([1, 9, 'Ingreso con fruta'], [$fila('CSG-CORR-C', '1512')['bins'], $fila('CSG-CORR-C', '1512')['totes'], $fila('CSG-CORR-C', '1512')['movimiento']]);
        $this->assertSame('Agrícola Tres', $excel['CSG-CORR-C']['nombre']);

        $pdf = $this->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$cliente->id)->assertOk()->getContent();
        $win = fn (string $texto): string => preg_quote(iconv('UTF-8', 'Windows-1252', $texto), '/');
        $this->assertMatchesRegularExpression('/'.$win('(CSG CSG-CORR-C · Agrícola Tres)').'.*?'.$win('(Guía de ingreso N° 1512 · Chofer Conductor de prueba').'/s', $pdf);
        $this->assertStringNotContainsString('Reasign', $pdf);

        // Lo que queda se puede seguir reasignando, nunca más de lo que tiene el productor de origen.
        $guia = $this->getJson($this->opciones($this->ingreso($recepcion)))->json('data.1');
        $this->assertSame(['CSG-CORR-B', 'CSG-CORR-C'], array_column($guia['productores'], 'codigo'));
        $this->assertSame('CSG-CORR-C', $guia['historial'][0]['destino']['codigo']);
        $this->postJson(self::RUTA, $this->payload($guia['clave'], 'CSG-CORR-B', $tercero, 'parcial', ['bins' => 2]))
            ->assertUnprocessable()->assertJsonPath('errors.envases.0', 'Puedes reasignar hasta 1 bins plástico: es lo que tiene el productor de origen en este movimiento.');
        // El productor indicado como origen debe tener el movimiento.
        $this->postJson(self::RUTA, $this->payload($guia['clave'], 'CSG-CORR', $tercero, 'completa'))
            ->assertUnprocessable()->assertJsonValidationErrors('productor_origen_codigo');
        // El destino debe ser un productor declarado en la cuenta, con su razón social.
        $ajeno = $this->productorCsg('CSG-AJENO', 'Agrícola Ajena');
        $this->postJson(self::RUTA, $this->payload($guia['clave'], 'CSG-CORR-B', $ajeno, 'completa'))->assertUnprocessable()->assertJsonValidationErrors('productor_csg_id');
        $this->postJson(self::RUTA, [...$this->payload($guia['clave'], 'CSG-CORR-B', $tercero, 'completa'), 'razon_social' => 'Agrícola Uno'])
            ->assertUnprocessable()->assertJsonValidationErrors('razon_social');
        $this->postJson(self::RUTA, $this->payload($guia['clave'], 'CSG-CORR-B', ProductorCsg::where('codigo', 'CSG-CORR-B')->sole(), 'completa'))
            ->assertUnprocessable()->assertJsonPath('errors.productor_csg_id.0', 'El movimiento ya está asignado a ese productor.');

        // Completa: lo que le quedaba a CSG-CORR-B en la guía 1512 pasa a CSG-CORR; la guía deja de estar en CSG-CORR-B.
        $uno = ProductorCsg::where('codigo', 'CSG-CORR')->sole();
        $this->postJson(self::RUTA, $this->payload($guia['clave'], 'CSG-CORR-B', $uno, 'completa'))->assertCreated()->assertJsonPath('data.modalidad', 'completa')
            ->assertJsonPath('data.envases', [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 40]]);
        $grupos = collect($cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null)['detalle_csg'])->keyBy('codigo');
        $this->assertNotContains('Guía de ingreso N° 1512', array_column($grupos['CSG-CORR-B']['documentos'] ?? [], 'titulo'));
        $this->assertSame([1, 40], array_column(collect($grupos['CSG-CORR']['documentos'])->firstWhere('titulo', 'Guía de ingreso N° 1512')['lineas'], 'entrada'));

        // El kardex interno guarda la trazabilidad de cada envase reasignado.
        $fila = collect($this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$cliente->id)->json('data'))->firstWhere('id', $this->ingreso($recepcion)->id);
        $this->assertTrue($fila['puede_reasignar_productor']);
        $this->assertSame([1, 1], array_column($fila['reasignaciones_productor'], 'cantidad'));
        $this->assertSame(self::MOTIVO, $fila['reasignaciones_productor'][0]['motivo']);
    }

    public function test_la_salida_del_destare_se_reasigna_completa_y_el_saldo_inicial_por_productor_la_considera(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $cliente = $recepcion->cliente;
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $this->comoAdministrador();
        $salida = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'salida_recepcion_fruta')->firstOrFail();
        [$documento] = $this->getJson($this->opciones($salida))->assertOk()->json('data');
        $this->assertSame(['entregados', 'Guía de salida N° GS-CORR-1'], [$documento['lado'], $documento['titulo']]);
        $this->assertSame([4, 97], array_column($documento['productores'][0]['envases'], 'cantidad'));

        $segundo = ProductorCsg::where('codigo', 'CSG-CORR-B')->sole();
        $this->postJson(self::RUTA, $this->payload($documento['clave'], 'CSG-CORR', $segundo, 'completa'))->assertCreated();

        $estado = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        $grupos = collect($estado['detalle_csg'])->keyBy('codigo');
        $this->assertNotContains('Guía de salida N° GS-CORR-1', array_column($grupos['CSG-CORR']['documentos'], 'titulo'));
        $salidaB = collect($grupos['CSG-CORR-B']['documentos'])->firstWhere('titulo', 'Guía de salida N° GS-CORR-1');
        $this->assertSame([4, 97], array_column($salidaB['lineas'], 'salida'));
        $excel = collect($cuenta->operacionesPorProductor($cliente, $recepcion->temporada, []))->keyBy('codigo');
        $this->assertNotContains('GS-CORR-1', array_column($excel['CSG-CORR']['movimientos'], 'guia'));
        $this->assertSame([-4, -97], [collect($excel['CSG-CORR-B']['movimientos'])->firstWhere('guia', 'GS-CORR-1')['bins'],
            collect($excel['CSG-CORR-B']['movimientos'])->firstWhere('guia', 'GS-CORR-1')['totes']]);

        // Con un período posterior a la guía, el saldo inicial de cada productor ya trae la reasignación.
        $manana = now(config('app.operational_timezone'))->addDay()->toDateString();
        $resumen = collect($cuenta->estadoCuenta($cliente, $recepcion->temporada, $manana, null)['resumen_csg'])->keyBy('codigo');
        $this->assertSame([2, 48], array_column($resumen['CSG-CORR']['tipos'], 'saldo_inicial'));
        $this->assertSame([-2, -48], array_column($resumen['CSG-CORR-B']['tipos'], 'saldo_inicial'));
        $completo = collect($estado['resumen_csg'])->keyBy('codigo');
        $this->assertSame(array_column($completo['CSG-CORR-B']['tipos'], 'saldo_final'), array_column($resumen['CSG-CORR-B']['tipos'], 'saldo_inicial'));
    }

    public function test_una_salida_sin_productor_declarado_pasa_a_un_csg_de_la_cuenta(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        // Camión con dos CSG destarado sin declarar a quién se entregaron los envases.
        $recepcion->forceFill(['salida_productor_snapshot' => null])->saveQuietly();
        $cliente = $recepcion->cliente;
        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $this->assertContains(null, array_column($cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null)['detalle_csg'], 'codigo'));
        $this->comoAdministrador();
        $salida = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'salida_recepcion_fruta')->firstOrFail();
        [$documento] = $this->getJson($this->opciones($salida))->json('data');
        $this->assertSame([null], array_column($documento['productores'], 'codigo'));

        $this->postJson(self::RUTA, $this->payload($documento['clave'], null, ProductorCsg::where('codigo', 'CSG-CORR-B')->sole(), 'completa'))->assertCreated();
        $estado = $cuenta->estadoCuenta($cliente, $recepcion->temporada, null, null);
        // Ya no queda nada «sin productor»: la salida está en el CSG declarado.
        $this->assertNotContains(null, array_column($estado['detalle_csg'], 'codigo'));
        $this->assertNotContains(null, array_column($estado['resumen_csg'], 'codigo'));
        $grupos = collect($estado['detalle_csg'])->keyBy('codigo');
        $this->assertSame([4, 97], array_column(collect($grupos['CSG-CORR-B']['documentos'])->firstWhere('titulo', 'Guía de salida N° GS-CORR-1')['lineas'], 'salida'));
        $this->assertNotContains(null, array_column($cuenta->operacionesPorProductor($cliente, $recepcion->temporada, []), 'codigo'));
    }

    public function test_no_reparte_una_guia_movida_con_reasignar_guia_ni_movimientos_que_no_son_de_una_guia(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(dosCsg: true);
        $cliente = $recepcion->cliente;
        $this->comoAdministrador();
        $salida = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'salida_recepcion_fruta')->firstOrFail();
        [$documento] = $this->getJson($this->opciones($salida))->json('data');
        $otra = Cliente::create(['codigo' => 'CLI-OTRA', 'nombre' => 'Exportadora otra', 'activo' => true]);
        $productorOtra = $this->asociar($otra, $this->productorCsg('CSG-OTRA', 'Agrícola Otra'));
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', ['operacion_id' => (string) Str::uuid(), 'documento_clave' => $documento['clave'],
            'reasignacion_vigente_id' => null, 'cliente_destino_id' => $otra->id, 'productor_csg_id' => $productorOtra->id,
            'motivo' => 'La salida completa fue de otra cuenta.'])->assertCreated();

        // La guía ya no está en esta cuenta: se reparte después de deshacer «Reasignar guía».
        $mensaje = 'La guía GS-CORR-1 está reasignada con «Reasignar guía» a Exportadora otra · CSG CSG-OTRA. Para repartirla entre productores, deshaz esa reasignación primero.';
        $this->getJson($this->opciones($salida))->assertConflict()->assertJsonPath('message', $mensaje);
        $segundo = ProductorCsg::where('codigo', 'CSG-CORR-B')->sole();
        $this->postJson(self::RUTA, $this->payload($documento['clave'], 'CSG-CORR', $segundo, 'completa'))->assertConflict()->assertJsonPath('message', $mensaje);
        // Las entradas que dejó «Reasignar guía» en la otra cuenta tampoco se reparten desde aquí.
        $entrada = MovimientoEnvase::where('tipo_movimiento', 'reasignacion_entrada')->firstOrFail();
        $fila = collect($this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$otra->id)->json('data'))->firstWhere('id', $entrada->id);
        $this->assertFalse($fila['puede_reasignar_productor']);
        $this->getJson($this->opciones($entrada))->assertUnprocessable();
        $this->assertSame(0, ReasignacionProductorEnvases::count());

        // Solo administración reasigna.
        $this->actingAs(User::factory()->create(['rol' => 'digitador_materia_prima', 'activo' => true]), 'sanctum')
            ->getJson($this->opciones($this->ingreso($recepcion)))->assertForbidden();
    }

    private function opciones(MovimientoEnvase $movimiento): string
    {
        return "/api/envases/cuenta-corriente/movimientos/{$movimiento->id}/reasignacion-productor";
    }

    /** @param array<string, int> $envases */
    private function payload(string $clave, ?string $origen, ProductorCsg $destino, string $modalidad, array $envases = []): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'documento_clave' => $clave, 'productor_origen_codigo' => $origen,
            'productor_csg_id' => $destino->id, 'razon_social' => $destino->razon_social, 'modalidad' => $modalidad,
            'envases' => array_map(fn (string $tipo, int $cantidad): array => ['tipo_envase' => $tipo, 'cantidad' => $cantidad], array_keys($envases), $envases),
            'motivo' => self::MOTIVO];
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
}
