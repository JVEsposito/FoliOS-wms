<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\MovimientoEnvase;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

/**
 * El campo manda 31 totes sobre 1 bin; con 24 totes por bin la planta agrega 1 bin propio.
 * Ese bin se presta a la cuenta del cliente y se devuelve internamente en el destare: el
 * camión sale con su bin y sus 31 totes, y la existencia de la planta no cambia.
 */
class PrestamoInternoBinsPlantaTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    private ?string $operacionCierre = null;

    public function test_el_bin_de_planta_se_presta_en_validacion_mp_y_vuelve_en_el_destare(): void
    {
        [$recepcion, $operador, $validador, $origen] = $this->recepcion('fruta_con_envases', ['bins' => 1, 'totes' => 31]);
        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');
        $this->getJson("/api/validacion-mp/recepciones/{$recepcion['id']}/catalogos")
            ->assertOk()->assertJsonPath('totes_por_bin', 24);

        $this->confirmar($validacion, ['bins' => 1, 'totes' => 31], [...$origen, 'bins_prestados_planta' => 1])
            ->assertOk()
            ->assertJsonPath('data.bins_prestados_planta', 1)
            ->assertJsonPath('data.segmentos.0.bins_prestados_planta', 1);

        $prestamo = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])
            ->where('tipo_movimiento', 'prestamo_interno')->sole();
        $this->assertSame(['bins', 1, -1, 0, 'propia'], [
            $prestamo->tipo_envase->value, $prestamo->cantidad, $prestamo->signo_cuenta, $prestamo->signo_existencia, $prestamo->propiedad->value,
        ]);
        // Mientras el camión sigue en planta, el bin prestado descuenta lo que la planta le debe.
        $this->assertSame(0, $this->saldo($recepcion['cliente']['id'], 'bins'));
        $this->actingAs($operador, 'sanctum')->getJson("/api/romana/recepciones/{$recepcion['id']}")
            ->assertOk()->assertJsonPath('data.bins_prestados_planta', ['cantidad' => 1, 'devuelto' => false]);

        $this->cerrar($recepcion['id'])->assertOk()->assertJsonPath('data.bins_prestados_planta.devuelto', true);
        $this->cerrar($recepcion['id'])->assertOk();

        $devolucion = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])
            ->where('tipo_movimiento', 'devolucion_prestamo_interno')->sole();
        $this->assertSame([$prestamo->id, 1, 1, 0, 'GS-PRESTAMO'], [
            $devolucion->movimiento_origen_id, $devolucion->cantidad, $devolucion->signo_cuenta, $devolucion->signo_existencia, $devolucion->numero_documento,
        ]);
        $this->assertSame(0, $this->saldo($recepcion['cliente']['id'], 'bins'));
        $this->assertSame(0, $this->saldo($recepcion['cliente']['id'], 'totes'));
        $this->assertSame(0, (int) MovimientoEnvase::query()->where('propiedad', 'propia')->get()->sum(fn ($m) => $m->cantidad * $m->signo_existencia));

        $cuenta = app(ServicioCuentaCorrienteEnvases::class);
        $temporadaId = $recepcion['temporada']['id'];
        $kardex = collect($cuenta->kardex(['temporada_id' => $temporadaId, 'cliente_id' => $recepcion['cliente']['id'], 'tipo_envase' => 'bins']));
        // La devolución interna va antes de la salida: el saldo nunca queda negativo.
        $this->assertSame([
            ['Recepción con fruta', 1], ['Préstamo interno de bins de planta', 0],
            ['Devolución interna de bins de planta', 1], ['Devolución en recepción', 0],
        ], $kardex->map(fn (array $fila): array => [$fila['movimiento'], $fila['saldo_cuenta']])->all());

        $estado = $cuenta->estadoCuenta(Cliente::findOrFail($recepcion['cliente']['id']), Temporada::findOrFail($temporadaId), null, null);
        $this->assertFalse(collect($estado['movimientos'])->contains(fn (array $linea): bool => str_contains($linea['movimiento'], 'bins de planta')));
        $bins = collect($estado['tipos'])->firstWhere('tipo_envase', 'bins');
        $this->assertSame([1, 1, 0], [$bins['entradas'], $bins['salidas'], $bins['saldo_final']]);

        // El lote se mueve en 2 bins; los kilos y el detalle siguen siendo los envases recibidos.
        $lote = $this->lotizar($recepcion['id']);
        $this->assertSame(['bins', 2, 'totes', 31, 1], [
            $lote->envase_primario->value, $lote->cantidad_envases_primarios,
            $lote->envase_secundario->value, $lote->cantidad_envases_secundarios, $lote->bins_prestados_planta,
        ]);
        $this->assertSame(['bins' => 1, 'totes' => 31], $lote->envasesDetalle->mapWithKeys(fn ($e) => [$e->tipo_envase->value => $e->cantidad])->sortKeys()->all());
        $this->assertEqualsWithDelta(9000, (float) $lote->kilos_netos_calculados, 0.001);
    }

    public function test_en_pesaje_acumulativo_el_prestamo_se_devuelve_al_validar(): void
    {
        [$recepcion, $operador, $validador, $origen] = $this->recepcion('fruta_pesaje_envases', ['bins' => 2]);
        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');
        $this->actingAs($operador, 'sanctum')
            ->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'cantidad_envases' => 2, 'peso_bruto' => 900])
            ->assertOk();
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", ['operacion_id' => (string) Str::uuid()])->assertOk();

        $this->actingAs($validador, 'sanctum');
        $this->confirmar($validacion, ['bins' => 2], [...$origen, 'bins_prestados_planta' => 1])->assertOk();

        $this->assertSame(['prestamo_interno', 'devolucion_prestamo_interno'], MovimientoEnvase::query()
            ->where('recepcion_romana_id', $recepcion['id'])->whereIn('tipo_movimiento', ['prestamo_interno', 'devolucion_prestamo_interno'])
            ->orderBy('id')->pluck('tipo_movimiento')->map->value->all());
        $this->assertSame(2, $this->saldo($recepcion['cliente']['id'], 'bins'));
    }

    public function test_la_segregacion_reparte_los_bins_de_planta_y_deben_sumar_el_total(): void
    {
        [$recepcion, , $validador, $origen] = $this->recepcion('fruta_con_envases', ['bins' => 2, 'totes' => 60]);
        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');
        $segmentos = fn (int $primero, int $segundo): array => [
            ['motivos' => ['cuartel'], 'cuartel' => 'C1', 'csg_validacion_id' => $origen['csg_validacion_id'], 'variedad_validacion_id' => $origen['variedad_validacion_id'],
                'bins_prestados_planta' => $primero, 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 30]]],
            ['motivos' => ['cuartel'], 'cuartel' => 'C2', 'csg_validacion_id' => $origen['csg_validacion_id'], 'variedad_validacion_id' => $origen['variedad_validacion_id'],
                'bins_prestados_planta' => $segundo, 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 1], ['tipo_envase' => 'totes', 'cantidad' => 30]]],
        ];
        $segregada = ['tarjas_verificadas' => true, 'requiere_segregacion' => true, 'bins_prestados_planta' => 2];

        $this->confirmar($validacion, ['bins' => 2, 'totes' => 60], [...$segregada, 'segmentos' => $segmentos(1, 0)])
            ->assertUnprocessable()->assertJsonValidationErrors('segmentos');
        $this->assertFalse(MovimientoEnvase::query()->where('tipo_movimiento', 'prestamo_interno')->exists());

        $this->confirmar($validacion, ['bins' => 2, 'totes' => 60], [...$segregada, 'segmentos' => $segmentos(1, 1)])
            ->assertOk()
            ->assertJsonPath('data.segmentos.0.bins_prestados_planta', 1)
            ->assertJsonPath('data.segmentos.1.bins_prestados_planta', 1);
        $this->assertSame(2, MovimientoEnvase::query()->where('tipo_movimiento', 'prestamo_interno')->sole()->cantidad);
    }

    public function test_la_referencia_de_totes_por_bin_se_administra_por_especie(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Arándano', 'activo' => true]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $ruta = '/api/administracion/reparto-envases/'.$especie->id;
        $referencias = [['tipo_envase' => 'bins', 'peso_referencia' => 200]];

        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['sugerido' => 'bins', 'totes_por_bin' => 0, 'referencias' => $referencias])
            ->assertUnprocessable()->assertJsonValidationErrors('totes_por_bin');
        $this->putJson($ruta, ['sugerido' => 'bins', 'totes_por_bin' => 20, 'referencias' => $referencias])
            ->assertOk()->assertJsonPath('data.totes_por_bin', 20);
        // Una pantalla que no envía la referencia no la borra; enviarla vacía sí.
        $this->putJson($ruta, ['sugerido' => 'bins', 'referencias' => $referencias])->assertOk()->assertJsonPath('data.totes_por_bin', 20);
        $this->putJson($ruta, ['sugerido' => 'bins', 'totes_por_bin' => null, 'referencias' => $referencias])->assertOk()->assertJsonPath('data.totes_por_bin', null);
    }

    public function test_una_recepcion_solo_de_envases_no_admite_bins_de_planta(): void
    {
        [$recepcion, , $validador] = $this->recepcion('solo_envases', ['bins' => 10]);
        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');

        $this->confirmar($validacion, ['bins' => 10], ['bins_prestados_planta' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('bins_prestados_planta');
    }

    /** @return array{0: array<string, mixed>, 1: User, 2: User, 3: array<string, mixed>} */
    private function recepcion(string $tipo, array $envases): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'PRE-'.Str::random(6), 'nombre' => 'Exportadora Préstamo', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $datos = [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'tipo_recepcion' => $tipo, 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => collect($envases)->map(fn (int $cantidad, string $tipoEnvase): array => ['tipo_envase' => $tipoEnvase, 'cantidad' => $cantidad])->values()->all(),
            'numero_guia_despacho' => 'GD-'.Str::random(8), 'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor', 'peso_bruto' => 10000,
        ];
        if ($tipo === 'fruta_pesaje_envases') {
            $datos = [...$datos, 'tipo_envase_pesaje' => 'bins', 'tara_unitaria_envase' => 50];
            unset($datos['peso_bruto']);
        }
        if ($tipo === 'solo_envases') {
            $datos = [...$datos, 'fecha_ingreso' => now(config('app.operational_timezone'))->toDateString(), 'concepto_envases' => 'compra', 'tipo_servicio' => null];
            unset($datos['peso_bruto']);
        }
        $recepcion = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        if ($tipo === 'fruta_con_envases') {
            $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        }
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-'.Str::random(8), 'activo' => true]);
        $variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina'], ['activo' => true]);

        return [$recepcion, $operador, $validador, [
            'tarjas_verificadas' => true, 'requiere_segregacion' => false,
            'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
        ]];
    }

    private function confirmar(string $validacion, array $cantidades, array $datos)
    {
        $ruta = "/api/validacion-mp/validaciones/{$validacion}/confirmar";

        return $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(),
            'envases' => collect($cantidades)->map(fn (int $cantidad, string $tipo): array => ['tipo_envase' => $tipo, 'cantidad_validada' => $cantidad])->values()->all(),
            ...$datos,
        ]));
    }

    private function cerrar(string $recepcionId)
    {
        $ruta = "/api/romana/recepciones/{$recepcionId}/cerrar";
        // El mismo identificador: repetir el cierre no duplica la devolución.
        $this->operacionCierre ??= (string) Str::uuid();

        return $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => $this->operacionCierre, 'peso_tara' => 1000, 'modo_salida_envases' => 'mismos',
            'numero_guia_salida' => 'GS-PRESTAMO', 'tipo_envase_calculo_neto' => 'totes',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]],
        ]));
    }

    private function saldo(string $clienteId, string $tipo): int
    {
        return (int) MovimientoEnvase::query()->where('cliente_id', $clienteId)->where('tipo_envase', $tipo)
            ->get()->sum(fn (MovimientoEnvase $m): int => $m->cantidad * $m->signo_cuenta);
    }

    private function lotizar(string $recepcionId): LoteMateriaPrima
    {
        $segmentoId = SegmentoValidacionMp::query()
            ->whereHas('validacion', fn ($q) => $q->where('recepcion_romana_id', $recepcionId))->value('id');
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $id = $this->actingAs($digitador, 'sanctum')->postJson('/api/materia-prima/lotes', [
            'operacion_id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $segmentoId, 'numero_lote' => 'L-'.Str::random(6),
            'sdp' => '123', 'fecha_cosecha' => now()->toDateString(), 'predio' => 'Fundo', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false,
        ])->assertCreated()->json('data.id');

        return LoteMateriaPrima::with('envasesDetalle')->findOrFail($id);
    }
}
