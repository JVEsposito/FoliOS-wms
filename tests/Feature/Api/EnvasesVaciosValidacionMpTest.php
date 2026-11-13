<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\EspecieValidacion;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\MateriaPrima\ServicioLoteMateriaPrima;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

/**
 * Envases que llegan vacíos y conteos distintos de la guía (12-11-2026).
 *
 * La guía declara 35 bins y 840 totes; Validación MP cuenta 36 bins y 864 totes, de los que
 * 24 totes vienen vacíos. La cuenta corriente y la tara del destare usan lo contado; el peso
 * promedio y los lotes, solo los 840 totes con fruta. El RPR-01 lo deja escrito.
 */
class EnvasesVaciosValidacionMpTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    private Temporada $temporada;

    private User $operador;

    private User $validador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
    }

    public function test_los_vacios_no_bajan_el_peso_promedio_y_lo_contado_de_mas_entra_en_la_tara_y_en_el_rpr01(): void
    {
        [$recepcion, $validacion] = $this->recepcionTomada();
        [$csg, $variedad] = $this->origen();

        $this->confirmar($validacion, [
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 36], ['tipo_envase' => 'totes', 'cantidad_validada' => 864, 'cantidad_vacia' => 24]],
            'requiere_segregacion' => false, 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
        ])->assertOk()
            ->assertJsonPath('data.segmentos.0.envases.1.tipo_envase', 'totes')
            ->assertJsonPath('data.segmentos.0.envases.1.cantidad', 840)
            ->assertJsonPath('data.segmentos.0.envases.1.cantidad_vacia', 24);

        $totes = DetalleEnvaseRecepcionRomana::query()->where('recepcion_romana_id', $recepcion['id'])->where('tipo_envase', 'totes')->sole();
        $this->assertSame([864, 24, 840], [$totes->cantidad_validada, $totes->cantidad_vacia, $totes->cantidadConFruta()]);
        // La cuenta corriente recibe todo lo contado, vacíos incluidos.
        $ingreso = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])->where('tipo_envase', 'totes')->sole();
        $this->assertSame(864, (int) $ingreso->cantidad);
        $this->assertEquals(['cantidad_declarada' => 840, 'cantidad_validada' => 864, 'diferencia' => 24, 'cantidad_vacia' => 24],
            collect($ingreso->datos)->only(['cantidad_declarada', 'cantidad_validada', 'diferencia', 'cantidad_vacia'])->all());
        $this->assertEquals([['tipo_envase' => 'bins', 'cantidad' => 36], ['tipo_envase' => 'totes', 'cantidad' => 864]],
            GuiaRecepcionRomana::query()->where('recepcion_romana_id', $recepcion['id'])->sole()->envases_validados);

        // El camión sale sin envases: la tara descuenta los 36 bins y los 864 totes contados.
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/cerrar";
        $cerrada = $this->actingAs($this->operador, 'sanctum')->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(), 'modo_salida_envases' => 'vacio', 'numero_guia_salida' => null, 'salida_envases' => [],
            'peso_tara' => 10000, 'tipo_envase_calculo_neto' => 'totes',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]],
        ]))->assertOk()
            ->assertJsonPath('data.peso_tara_envases', 3168)
            ->assertJsonPath('data.peso_neto', 7350)
            ->assertJsonPath('data.cantidad_envase_calculo_neto', 840)
            ->assertJsonPath('data.envases.1.cantidad_vacia', 24)
            ->assertJsonPath('data.envases.1.cantidad_con_fruta', 840)
            ->json('data');
        // 7.350 kg en 840 totes con fruta: 8,75 kg. Con los 24 vacíos habría dado 8,507 kg.
        $this->assertEqualsWithDelta(8.75, $cerrada['peso_neto_por_envase'], 0.0005);
        $this->assertSame([
            'Bins plástico: Validación MP contó 36 y la guía declaraba 35 (1 más).',
            'Tote: Validación MP contó 864 y la guía declaraba 840 (24 más).',
            'Tote: 24 de 864 llegaron vacíos. Cuentan para la cuenta corriente y la tara, no para el peso promedio.',
            'La tara de envases, el neto y el peso promedio se calculan con lo contado en Validación MP.',
            'Peso promedio por envase con fruta (Tote): 8,750 kg sobre 840.',
        ], $cerrada['observacion_conteo']);

        // La hoja que firma el chofer lo lleva en la observación de cierre.
        $pdf = (string) $this->get("/api/romana/recepciones/{$recepcion['id']}/aviso-recibo")->assertOk()->getContent();
        foreach (['Validación MP contó: Bins plástico 36', 'guía 35', 'Tote 864', 'guía 840; 24 vacíos', 'Tara y neto con lo contado. Promedio sin vacíos: 8,750 kg por Tote con fruta'] as $texto) {
            $this->assertStringContainsString(iconv('UTF-8', 'Windows-1252', $texto), $pdf);
        }
        // Breve para que las firmas del chofer y de Romana sigan en la misma hoja.
        $this->assertSame(1, substr_count($pdf, '/Type /Page /Parent'));

        // El lote recibe todo el neto con los 840 totes con fruta.
        $segmento = SegmentoValidacionMp::query()->whereHas('validacion', fn ($q) => $q->where('recepcion_romana_id', $recepcion['id']))->sole();
        $this->assertEqualsWithDelta(7350, app(ServicioLoteMateriaPrima::class)->netoEstimado($segmento, RecepcionRomana::query()->findOrFail($recepcion['id'])), 0.0005);
    }

    /**
     * REC-2611-0022 (08-11-2026): las guías 1524-1525 declaran 23 bins y 547 totes; Validación MP
     * cuenta 25 bins y 596 totes. El camión entra a las 14:26 y sale vacío a las 15:19, hora de planta.
     */
    public function test_rec_2611_0022_cuenta_corriente_destare_y_rpr01_usan_lo_validado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08 17:26:00', 'UTC'));
        $recepcion = $this->actingAs($this->operador, 'sanctum')->postJson('/api/romana/recepciones', [
            ...$this->datosRecepcion(), 'numero_guia_despacho' => '1524-1525', 'peso_bruto' => 22680,
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 23], ['tipo_envase' => 'totes', 'cantidad' => 547]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        $validacion = $this->actingAs($this->validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data');
        [$csg, $variedad] = $this->origen();
        $this->confirmar($validacion, [
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 25], ['tipo_envase' => 'totes', 'cantidad_validada' => 596]],
            'requiere_segregacion' => false, 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
        ])->assertOk();

        // Cuenta corriente: entra lo contado, 25 bins y 596 totes, a la hora de ingreso.
        $movimientos = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])->get()->keyBy(fn ($m) => $m->tipo_envase->value);
        $this->assertSame([25, 596], [(int) $movimientos['bins']->cantidad, (int) $movimientos['totes']->cantidad]);
        $this->assertSame([2, 49], [$movimientos['bins']->datos['diferencia'], $movimientos['totes']->datos['diferencia']]);
        $this->assertTrue($movimientos->every(fn ($m) => $m->signo_cuenta === 1 && $m->ocurrido_at->equalTo(CarbonImmutable::parse('2026-11-08 17:26:00', 'UTC'))));

        // Destare: el camión sale vacío; la tara descuenta los 25 bins y los 596 totes contados.
        $this->travelTo(CarbonImmutable::parse('2026-11-08 18:19:00', 'UTC'));
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/cerrar";
        $this->actingAs($this->operador, 'sanctum')->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(), 'modo_salida_envases' => 'vacio', 'numero_guia_salida' => null, 'salida_envases' => [],
            'peso_tara' => 15500, 'tipo_envase_calculo_neto' => 'totes',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 43], ['tipo_envase' => 'totes', 'tara_unitaria' => 0.89]],
        ]))->assertOk()
            ->assertJsonPath('data.peso_tara_envases', 1605.44)
            ->assertJsonPath('data.peso_neto', 5574.56)
            ->assertJsonPath('data.cantidad_envase_calculo_neto', 596)
            ->assertJsonPath('data.peso_neto_por_envase', 9.353);
        $this->assertSame(2, MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])->count(), 'sale vacío: no hay salida de envases');

        // Estado de cuenta del cliente: la recepción suma lo validado.
        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta(Cliente::query()->findOrFail($recepcion['cliente']['id'] ?? $recepcion['cliente_id']), $this->temporada, null, null);
        $entradas = collect($estado['movimientos'])->mapWithKeys(fn (array $linea) => [$linea['tipo_envase'] => $linea['entrada']]);
        $this->assertSame(['Bins plástico' => 25, 'Tote' => 596], $entradas->all());

        // RPR-01: lo recibido es lo validado, la tara con su desglose y las horas en hora de planta.
        preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\) Tj/', (string) $this->get("/api/romana/recepciones/{$recepcion['id']}/aviso-recibo")->assertOk()->getContent(), $c);
        $textos = array_map(fn (string $v): string => iconv('Windows-1252', 'UTF-8', strtr($v, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\'])), $c[1]);
        $valor = fn (string $campo): string => $textos[array_search($campo, $textos, true) + 1];
        $this->assertSame('25 Bins plástico · 596 Tote (guía: 23 Bins plástico · 547 Tote)', $valor('Envases recibidos'));
        $this->assertSame(['08-11-2026 14:26', '08-11-2026 15:19'], [$valor('Ingreso'), $valor('Salida / destare')]);
        $this->assertSame('22.680,000 kg', $valor('Peso bruto'));
        $this->assertSame('17.105,440 kg (camión 15.500,000 + envases 1.605,440)', $valor('Tara camión / envases'));
        $this->assertSame('5.574,560 kg', $valor('PESO NETO'));
        $this->assertNotContains('Envases declarados', $textos);
    }

    public function test_los_vacios_se_validan_y_en_una_segregacion_se_indica_su_segmento(): void
    {
        [$recepcion, $validacion] = $this->recepcionTomada();
        [$csg, $variedad] = $this->origen();
        $base = ['requiere_segregacion' => false, 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id];

        $this->confirmar($validacion, [...$base, 'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 35],
            ['tipo_envase' => 'totes', 'cantidad_validada' => 840, 'cantidad_vacia' => 841]]])
            ->assertUnprocessable()->assertJsonPath('errors.envases.0', 'Tote: los vacíos no pueden superar los contados.');

        $segmentos = fn (int $vaciosPrimero, int $vaciosSegundo): array => [
            ['motivos' => ['csg'], 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 20], ['tipo_envase' => 'totes', 'cantidad' => 470, 'cantidad_vacia' => $vaciosPrimero]]],
            ['motivos' => ['csg'], 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 15], ['tipo_envase' => 'totes', 'cantidad' => 350, 'cantidad_vacia' => $vaciosSegundo]]],
        ];
        $conteo = [['tipo_envase' => 'bins', 'cantidad_validada' => 35], ['tipo_envase' => 'totes', 'cantidad_validada' => 840, 'cantidad_vacia' => 20]];
        $this->confirmar($validacion, ['envases' => $conteo, 'requiere_segregacion' => true, 'segmentos' => $segmentos(0, 0)])
            ->assertUnprocessable()->assertJsonPath('errors.segmentos.0', 'La distribución de totes entre segmentos no coincide con lo validado.');
        $sinVacios = $segmentos(0, 15);
        $sinVacios[0]['envases'][1]['cantidad'] = 475;
        $this->confirmar($validacion, ['envases' => $conteo, 'requiere_segregacion' => true, 'segmentos' => $sinVacios])
            ->assertUnprocessable()->assertJsonPath('errors.segmentos.0', 'Tote: indica en qué segmento llegaron los vacíos; deben sumar 20.');
        $this->confirmar($validacion, ['envases' => $conteo, 'requiere_segregacion' => true, 'segmentos' => $segmentos(5, 15)])
            ->assertOk()
            ->assertJsonPath('data.segmentos.0.envases.1.cantidad_vacia', 5)
            ->assertJsonPath('data.segmentos.1.envases.1.cantidad', 350)
            ->assertJsonPath('data.segmentos.1.envases.1.cantidad_vacia', 15);
        $this->assertSame(20, DetalleEnvaseRecepcionRomana::query()->where('recepcion_romana_id', $recepcion['id'])->where('tipo_envase', 'totes')->value('cantidad_vacia'));
    }

    public function test_en_pesaje_acumulativo_el_neto_por_envase_se_rehace_sin_los_vacios(): void
    {
        $datos = [...$this->datosRecepcion(), 'tipo_recepcion' => 'fruta_pesaje_envases', 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 4]],
            'tipo_envase_pesaje' => 'bins', 'tara_unitaria_envase' => 50];
        unset($datos['peso_bruto']);
        $recepcion = $this->actingAs($this->operador, 'sanctum')->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", ['operacion_id' => (string) Str::uuid(), 'cantidad_envases' => 4, 'peso_bruto' => 1000])
            ->assertOk()->assertJsonPath('data.peso_neto', 800);
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", $this->payloadConInspeccionRc02("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
        ]))->assertOk()->assertJsonPath('data.peso_neto_por_envase', 200);
        $validacion = $this->actingAs($this->validador, 'sanctum')->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data');
        [$csg, $variedad] = $this->origen();
        $base = ['requiere_segregacion' => false, 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id];

        $this->confirmar($validacion, [...$base, 'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 4, 'cantidad_vacia' => 4]]])
            ->assertUnprocessable()->assertJsonPath('errors.envases.0', 'Con todos los envases vacíos no queda fruta que validar. Revisa el conteo de vacíos.');
        $this->confirmar($validacion, [...$base, 'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 4, 'cantidad_vacia' => 1]]])->assertOk();

        $cerrada = RecepcionRomana::query()->findOrFail($recepcion['id']);
        // El neto pesado no cambia; se reparte entre los 3 bins con fruta.
        $this->assertSame([800.0, 3, 266.667], [(float) $cerrada->peso_neto, (int) $cerrada->cantidad_envase_calculo_neto, (float) $cerrada->peso_neto_por_envase]);
        $this->assertDatabaseHas('eventos_recepcion_romana', ['recepcion_romana_id' => $recepcion['id'], 'tipo' => 'neto_por_envase_ajustado']);
        $this->actingAs($this->operador, 'sanctum')->getJson("/api/romana/recepciones/{$recepcion['id']}")->assertOk()
            ->assertJsonPath('data.observacion_conteo.0', 'Bins plástico: 1 de 4 llegaron vacíos. Cuentan para la cuenta corriente y la tara, no para el peso promedio.')
            ->assertJsonPath('data.observacion_conteo.1', 'El neto por envase se calculó con lo contado en Validación MP.');
    }

    public function test_la_correccion_integral_conserva_los_vacios_si_no_los_envia_y_los_cambia_si_los_envia(): void
    {
        [$recepcion, $validacion] = $this->recepcionTomada();
        [$csg, $variedad] = $this->origen();
        $this->confirmar($validacion, [
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 35], ['tipo_envase' => 'totes', 'cantidad_validada' => 840, 'cantidad_vacia' => 24]],
            'requiere_segregacion' => false, 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
        ])->assertOk();
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/correccion-integral";
        $estado = $this->actingAs($administrador, 'sanctum')->getJson($ruta)->assertOk()->json('data');
        $this->assertSame(['tipo_envase' => 'totes', 'cantidad' => 816, 'cantidad_vacia' => 24], $estado['segmentos'][0]['envases'][1]);
        $corregir = fn (array $totes, string $motivo): array => [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => RecepcionRomana::query()->findOrFail($recepcion['id'])->version,
            'motivo_correccion' => $motivo,
            'guias' => array_map(fn ($g) => ['id' => $g['id'], 'numero_guia_despacho' => $g['numero_guia_despacho'], 'envases' => $g['envases']], $estado['guias']),
            'segmentos' => [['id' => $estado['segmentos'][0]['id'], 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 36], ['tipo_envase' => 'totes', ...$totes]]]],
        ];

        // Una pantalla que no conoce los vacíos no los borra: corrige solo los bins.
        $this->putJson($ruta, $corregir(['cantidad' => 816], 'Se contó un bin más al revisar el andén.'))->assertOk();
        $totes = DetalleEnvaseRecepcionRomana::query()->where('recepcion_romana_id', $recepcion['id'])->where('tipo_envase', 'totes')->sole();
        $this->assertSame([840, 24], [$totes->cantidad_validada, $totes->cantidad_vacia]);

        $this->putJson($ruta, $corregir(['cantidad' => 830, 'cantidad_vacia' => 10], 'Solo diez totes venían realmente vacíos.'))->assertOk();
        $this->assertSame([840, 10], [$totes->refresh()->cantidad_validada, $totes->cantidad_vacia]);
        // La cuenta corriente no cambia: los envases recibidos son los mismos.
        $this->assertSame(0, MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion['id'])->where('tipo_envase', 'totes')
            ->where('tipo_movimiento', '!=', 'recepcion_fruta')->count());
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function recepcionTomada(): array
    {
        $recepcion = $this->actingAs($this->operador, 'sanctum')->postJson('/api/romana/recepciones', $this->datosRecepcion())->assertCreated()->json('data');
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        $validacion = $this->actingAs($this->validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data');

        return [$recepcion, $validacion];
    }

    /** @return array{0: CsgValidacion, 1: VariedadValidacion} */
    private function origen(): array
    {
        $especie = EspecieValidacion::query()->where('temporada_id', $this->temporada->id)->where('nombre', 'Cereza')->firstOrFail();

        return [
            CsgValidacion::create(['temporada_id' => $this->temporada->id, 'codigo' => 'CSG-'.Str::random(8), 'activo' => true]),
            VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Lapins-'.Str::random(5), 'activo' => true]),
        ];
    }

    private function confirmar(array $validacion, array $datos): TestResponse
    {
        $ruta = "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar";

        return $this->actingAs($this->validador, 'sanctum')->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(), 'tarjas_verificadas' => true, ...$datos,
        ]));
    }

    /** @return array<string, mixed> */
    private function datosRecepcion(): array
    {
        return [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id,
            'cliente_id' => Cliente::firstOrCreate(['codigo' => 'CLI-VAC'], ['nombre' => 'Cliente vacíos', 'activo' => true])->id,
            'tipo_recepcion' => 'fruta_con_envases',
            'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza'], ['activo' => true])->id,
            'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 35], ['tipo_envase' => 'totes', 'cantidad' => 840]],
            'numero_guia_despacho' => 'GD-VAC-'.Str::random(5), 'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor MP', 'peso_bruto' => 20518,
        ];
    }
}
