<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Envases\ServicioDevolucionEnvases;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

/**
 * Caso real (Ribagorza, CSG 90624): la guía 2324 despachó 13 bins, 14 esponjas y 312 totes;
 * el camión reingresó con esos envases y se registró como ingreso en arriendo sin CSG, con
 * fecha retroactiva y la hora del registro. La cuenta del productor quedaba debiendo de más.
 */
class DevolucionEnvasesCsgTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    private Temporada $temporada;

    private Cliente $cliente;

    private User $administrador;

    private ProductorCsg $productor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-11 09:33:00', 'America/Santiago'));
        $this->temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->cliente = Cliente::create(['codigo' => 'FA-001', 'nombre' => 'FALCO FRUIT', 'activo' => true]);
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->productor = $this->productorCsg($this->cliente, '90624', 'AGRICOLA RIBAGORZA SPA');
        // Existencia de planta desde la que sale la guía 2324.
        $this->ingresoInicial('bins', 'arrendada', 100);
        $this->ingresoInicial('esponjas', 'propia', 100);
        $this->ingresoInicial('totes', 'arrendada', 2400);
        $this->despacho2324();
    }

    public function test_asocia_un_reingreso_de_solo_envases_a_la_devolucion_del_csg(): void
    {
        $recepcion = $this->reingresoEnArriendo();
        $ingresos = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)->get();
        $this->assertCount(3, $ingresos);
        $this->assertSame(['recepcion_arriendo'], $ingresos->pluck('tipo_movimiento')->map->value->unique()->values()->all());
        $saldoCliente = $this->saldoCuenta();
        $antes = $this->resumenCsg('90624');
        $this->assertSame([0, 0, 0], array_column($antes, 'entradas'));
        $this->assertSame([13, 14, 312], array_column($antes, 'salidas'));
        $existenciaAntes = $this->existencia();

        $salida = $this->getJson('/api/envases/devoluciones/salida?cliente_id='.$this->cliente->id.'&numero=2324')
            ->assertOk()->json('data');
        $this->assertCount(1, $salida['salidas']);
        $this->assertSame('despacho', $salida['salidas'][0]['origen']);
        $this->assertSame('90624', $salida['salidas'][0]['productor']['codigo']);
        $this->assertSame('2026-11-10 17:36', $salida['salidas'][0]['fecha']);
        $this->assertSame([
            ['tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => 13],
            ['tipo_envase' => 'esponjas', 'propiedad' => 'propia', 'cantidad' => 14],
            ['tipo_envase' => 'totes', 'propiedad' => 'arrendada', 'cantidad' => 312],
        ], $salida['salidas'][0]['envases']);
        $this->assertSame([], $salida['devoluciones']);

        $ruta = '/api/envases/cuenta-corriente/movimientos/'.$ingresos->first()->id.'/corregir-propiedad';
        $datos = [
            'concepto_envases' => 'devolucion',
            'devolucion_productor_csg_id' => $this->productor->id,
            'devolucion_guia_salida' => '2324',
            'propiedades' => ['bins' => 'arrendada', 'esponjas' => 'propia', 'totes' => 'arrendada'],
            'motivo' => 'El camión reingresó con los envases despachados por la guía 2324.',
        ];
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->postJson($ruta, [...$datos, 'ingreso_at' => '2026-11-10T17:50'])->assertForbidden();
        $this->actingAs($this->administrador, 'sanctum');
        // La hora del registro (09:33) deja el reingreso antes de la salida que devuelve.
        $this->postJson($ruta, $datos)->assertUnprocessable()
            ->assertJsonPath('errors.ingreso_at.0', 'La devolución no puede ser anterior a la salida de la guía 2324 (10-11-2026 17:36). Indica la fecha y hora reales del reingreso.');
        $otro = $this->productorCsg(Cliente::create(['codigo' => 'OT-001', 'nombre' => 'Otro cliente', 'activo' => true]), '99999', 'Ajeno');
        $this->postJson($ruta, [...$datos, 'devolucion_productor_csg_id' => $otro->id, 'ingreso_at' => '2026-11-10T17:50'])
            ->assertUnprocessable()->assertJsonValidationErrors('devolucion_productor_csg_id');
        $this->postJson($ruta, [...$datos, 'propiedades' => ['bins' => 'arrendada'], 'ingreso_at' => '2026-11-10T17:50'])
            ->assertUnprocessable()->assertJsonValidationErrors('propiedades.esponjas');
        $this->assertSame(3, MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)->count());

        $this->postJson($ruta, [...$datos, 'ingreso_at' => '2026-11-10T17:50'])->assertOk();

        $recepcion->refresh();
        $this->assertSame('devolucion', $recepcion->concepto_envases->value);
        $this->assertSame($this->productor->id, $recepcion->devolucion_productor_csg_id);
        $this->assertSame(['codigo' => '90624', 'nombre' => 'AGRICOLA RIBAGORZA SPA'], $recepcion->devolucion_productor_snapshot);
        $this->assertSame('2324', $recepcion->devolucion_guia_salida);
        $this->assertSame('2026-11-10 20:50:00', $recepcion->ingreso_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('propia', $recepcion->detallesEnvases()->where('tipo_envase', 'esponjas')->firstOrFail()->propiedad->value);

        $devoluciones = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
            ->where('tipo_movimiento', 'devolucion_envases')->orderBy('tipo_envase')->get();
        $this->assertSame(['arrendada', 'propia', 'arrendada'], $devoluciones->pluck('propiedad')->map->value->all());
        $this->assertSame([13, 14, 312], $devoluciones->pluck('cantidad')->all());
        foreach ($devoluciones as $devolucion) {
            $this->assertSame(1, $devolucion->signo_cuenta);
            $this->assertSame('2026-11-10 20:50:00', $devolucion->ocurrido_at->utc()->format('Y-m-d H:i:s'));
            $this->assertSame('90624', $devolucion->datos['devolucion']['productor']['codigo']);
            $this->assertSame('2324', $devolucion->datos['devolucion']['guia_salida']);
        }
        // Los ingresos originales quedan, compensados por su corrección.
        foreach ($ingresos as $ingreso) {
            $this->assertDatabaseHas('movimientos_envases', ['movimiento_origen_id' => $ingreso->id, 'tipo_movimiento' => 'correccion_propiedad', 'signo_existencia' => -1]);
        }

        // El total del cliente no cambia: solo pasa del saldo sin CSG al productor que devolvió.
        $this->assertSame($saldoCliente, $this->saldoCuenta());
        $despues = $this->resumenCsg('90624');
        $this->assertSame([13, 14, 312], array_column($despues, 'entradas'));
        $this->assertSame([13, 14, 312], array_column($despues, 'salidas'));
        $this->assertSame([0, 0, 0], array_column($despues, 'saldo_final'));

        // Las esponjas vuelven como propias, igual que salieron.
        $existencia = $this->existencia();
        $this->assertSame($existenciaAntes['esponjas|propia'] + 14, $existencia['esponjas|propia']);
        $this->assertSame($existenciaAntes['esponjas|arrendada'] - 14, $existencia['esponjas|arrendada']);
        $this->assertSame($existenciaAntes['bins|arrendada'], $existencia['bins|arrendada']);

        $evento = EventoRecepcionRomana::query()->where('recepcion_romana_id', $recepcion->id)
            ->where('tipo', 'correccion_administrativa')->latest('ocurrido_at')->firstOrFail();
        $this->assertSame('arriendo', $evento->datos['anterior']['concepto_envases']);
        $this->assertSame('2026-11-10T12:33:00+00:00', $evento->datos['anterior']['ingreso_at']);
        $this->assertSame('90624', $evento->datos['posterior']['devolucion']['productor']['codigo']);

        $movimiento = collect($this->getJson('/api/envases/cuenta-corriente/movimientos?cliente_id='.$this->cliente->id)->assertOk()->json('data'))
            ->firstWhere('id', $devoluciones->first()->id);
        $this->assertSame('Devolución de envases', $movimiento['movimiento']);
        $this->assertSame('90624', $movimiento['devolucion']['productor']['codigo']);
        $this->assertFalse($movimiento['puede_corregir_propiedad']);

        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->cliente, $this->temporada, null, null);
        $linea = collect($estado['movimientos'])->firstWhere('movimiento', 'Devolución de envases');
        $this->assertSame('CSG 90624 · AGRICOLA RIBAGORZA SPA · devuelve guía 2324', $linea['productor']);
        // En el detalle por CSG la devolución queda junto a la guía que devuelve y el saldo del productor vuelve a cero.
        $grupo = collect($estado['detalle_csg'])->firstWhere('codigo', '90624');
        $documento = collect($grupo['documentos'])->firstWhere('movimiento', 'Devolución de envases');
        $this->assertSame('Guía de ingreso N° 2324', $documento['titulo']);
        $this->assertSame('Chofer Luis Duarte (RUT 12.345.678-5)', $documento['chofer']);
        $this->assertSame('Recepción '.$recepcion->numero_recepcion.' · devuelve la guía 2324 · Camión JJLY15', $documento['referencia']);
        $this->assertSame([0, 0, 0], array_column($grupo['totales'], 'saldo_final'));
        $pdf = $this->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->cliente->id)->assertOk()->getContent();
        $this->assertStringContainsString(iconv('UTF-8', 'Windows-1252', 'devuelve la guía 2324'), $pdf);
        $this->get('/api/envases/cuenta-corriente/estado-cuenta?cliente_id='.$this->cliente->id.'&formato=xlsx')->assertOk();
        $sinCsg = collect($estado['detalle_csg'])->firstWhere('codigo', null);
        $this->assertNotContains('Devolución de envases', array_column($sinCsg['documentos'] ?? [], 'movimiento'));

        $this->postJson($ruta, [...$datos, 'ingreso_at' => '2026-11-10T17:55'])->assertConflict();
        $this->assertSame([['id' => $recepcion->id, 'numero_recepcion' => $recepcion->numero_recepcion, 'fecha' => '2026-11-10 17:50',
            'productor' => ['codigo' => '90624', 'nombre' => 'AGRICOLA RIBAGORZA SPA']]],
            $this->getJson('/api/envases/devoluciones/salida?cliente_id='.$this->cliente->id.'&numero=2324')->json('data.devoluciones'));
    }

    public function test_romana_registra_la_devolucion_con_csg_propiedad_y_hora_real(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $payload = fn (array $cambios = []): array => [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $this->temporada->id,
            'cliente_id' => $this->cliente->id,
            'tipo_recepcion' => 'solo_envases',
            'concepto_envases' => 'devolucion',
            'devolucion_productor_csg_id' => $this->productor->id,
            'devolucion_guia_salida' => ' 2324 ',
            'fecha_ingreso' => '2026-11-10',
            'hora_ingreso' => '17:50',
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 13, 'propiedad' => 'arrendada'],
                ['tipo_envase' => 'esponjas', 'cantidad' => 14, 'propiedad' => 'propia'],
                ['tipo_envase' => 'totes', 'cantidad' => 312, 'propiedad' => 'arrendada'],
            ],
            'numero_guia_despacho' => 'DEV-2324',
            'patente_camion' => 'JJLY15',
            'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Luis Duarte',
            ...$cambios,
        ];

        $this->postJson('/api/romana/recepciones', $payload(['devolucion_productor_csg_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('devolucion_productor_csg_id');
        $sinPropiedad = $payload();
        unset($sinPropiedad['envases'][1]['propiedad']);
        $this->postJson('/api/romana/recepciones', $sinPropiedad)
            ->assertUnprocessable()->assertJsonValidationErrors('envases.1.propiedad');
        $this->postJson('/api/romana/recepciones', $payload(['hora_ingreso' => null]))
            ->assertUnprocessable()->assertJsonPath('errors.hora_ingreso.0', 'La devolución no puede ser anterior a la salida de la guía 2324 (10-11-2026 17:36). Indica la fecha y hora reales del reingreso.');
        $this->postJson('/api/romana/recepciones', $payload(['fecha_ingreso' => '2026-11-11', 'hora_ingreso' => '23:59']))
            ->assertUnprocessable()->assertJsonPath('errors.hora_ingreso.0', 'La hora del reingreso no puede ser futura.');
        $this->assertSame(0, RecepcionRomana::query()->count());

        $datos = $this->postJson('/api/romana/recepciones', $payload())->assertCreated()->json('data');
        $this->assertSame('devolucion', $datos['concepto_envases']);
        $this->assertSame(['productor' => ['id' => $this->productor->id, 'codigo' => '90624', 'nombre' => 'AGRICOLA RIBAGORZA SPA'], 'guia_salida' => '2324'], $datos['devolucion']);
        $recepcion = RecepcionRomana::findOrFail($datos['id']);
        $this->assertSame('2026-11-10 20:50:00', $recepcion->ingreso_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('propia', $recepcion->detallesEnvases()->where('tipo_envase', 'esponjas')->firstOrFail()->propiedad->value);
        $this->assertContains('devolucion', array_column($this->getJson('/api/romana/catalogos')->assertOk()->json('conceptos_envases'), 'codigo'));

        $this->validar($recepcion, ['bins' => 13, 'esponjas' => 14, 'totes' => 312]);

        $movimientos = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)->orderBy('tipo_envase')->get();
        $this->assertSame(['devolucion_envases'], $movimientos->pluck('tipo_movimiento')->map->value->unique()->values()->all());
        $this->assertSame(['arrendada', 'propia', 'arrendada'], $movimientos->pluck('propiedad')->map->value->all());
        $this->assertSame([1, 1, 1], $movimientos->pluck('signo_cuenta')->all());
        $this->assertSame('90624', $movimientos->first()->datos['devolucion']['productor']['codigo']);
        $this->assertSame([0, 0, 0], array_column($this->resumenCsg('90624'), 'saldo_final'));

        // La devolución ingresa existencia como origen disponible para un despacho posterior.
        $origenes = collect(app(ServicioGuiaDespachoEnvases::class)->inventario($this->temporada)['origenes']);
        $this->assertTrue($origenes->contains(fn (array $origen): bool => $origen['id'] === $movimientos->first()->id));
    }

    public function test_la_salida_de_un_destare_entrega_el_csg_de_la_recepcion_y_limita_la_fecha(): void
    {
        // Como en planta: la guía 2324 salió en el destare de la recepción 961, validada con CSG 90624.
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $recepcion961 = RecepcionRomana::findOrFail($this->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
            'tipo_recepcion' => 'fruta_con_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 13, 'propiedad' => 'arrendada']],
            'numero_guia_despacho' => '961', 'patente_camion' => 'JJLY15', 'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Luis Duarte', 'peso_bruto' => 9000,
        ])->assertCreated()->json('data.id'));
        $validacion = $recepcion961->validacionesMp()->create([
            'temporada_id' => $this->temporada->id, 'operacion_toma_id' => (string) Str::uuid(), 'estado' => 'validada',
            'validador_user_id' => $this->administrador->id, 'tomada_at' => now(), 'validada_at' => now(),
        ]);
        $csg = CsgValidacion::create(['temporada_id' => $this->temporada->id, 'codigo' => '90624', 'productor_csg_id' => $this->productor->id, 'predio' => 'Predio 90624', 'activo' => true]);
        $validacion->segmentos()->create(['secuencia' => 1, 'motivos' => ['csg'], 'csg_validacion_id' => $csg->id, 'csg_snapshot' => '90624'])
            ->envases()->create(['tipo_envase' => 'bins', 'cantidad' => 13]);
        foreach (['bins' => ['arrendada', 13], 'esponjas' => ['propia', 14]] as $tipo => [$propiedad, $cantidad]) {
            MovimientoEnvase::create([
                'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
                'recepcion_romana_id' => $recepcion961->id, 'documento_tipo' => 'recepcion_romana', 'documento_id' => $recepcion961->id,
                'numero_documento' => '2425', 'tipo_movimiento' => 'salida_recepcion_fruta', 'tipo_envase' => $tipo, 'cantidad' => $cantidad,
                'signo_cuenta' => -1, 'signo_existencia' => -1, 'propiedad' => $propiedad,
                'ocurrido_at' => '2026-11-10 20:36:51', 'salida_at' => '2026-11-10 20:36:51',
                'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->administrador->id,
            ]);
        }

        $salida = $this->getJson('/api/envases/devoluciones/salida?cliente_id='.$this->cliente->id.'&numero=2425')->assertOk()->json('data.salidas');
        $this->assertCount(1, $salida);
        $this->assertSame('destare', $salida[0]['origen']);
        $this->assertSame($recepcion961->numero_recepcion.' · guía de ingreso 961', $salida[0]['documento']);
        $this->assertSame(['id' => $this->productor->id, 'codigo' => '90624', 'nombre' => 'AGRICOLA RIBAGORZA SPA'], $salida[0]['productor']);
        $this->assertSame([['tipo_envase' => 'bins', 'propiedad' => 'arrendada', 'cantidad' => 13], ['tipo_envase' => 'esponjas', 'propiedad' => 'propia', 'cantidad' => 14]], $salida[0]['envases']);

        $servicio = app(ServicioDevolucionEnvases::class);
        $this->assertSame('2026-11-10 20:36:51', $servicio->primeraSalida($this->cliente, $this->temporada, '2425')->format('Y-m-d H:i:s'));
        $this->expectException(ValidationException::class);
        $servicio->asegurarPosteriorASalida($this->cliente, $this->temporada, '2425', CarbonImmutable::parse('2026-11-10 17:30', 'America/Santiago'), 'ingreso_at');
    }

    private function productorCsg(Cliente $cliente, string $codigo, string $nombre): ProductorCsg
    {
        $productor = ProductorCsg::create([
            'codigo' => $codigo, 'razon_social' => $nombre, 'predio' => 'Predio '.$codigo,
            'estado_sag' => 'activo', 'fuente_url' => 'https://www.sag.gob.cl/',
            'primera_verificacion_at' => now(), 'ultima_verificacion_at' => now(),
            'ultima_consulta_user_id' => $this->administrador->id, 'respuesta_hash' => hash('sha256', $codigo),
        ]);
        $cliente->productoresCsg()->attach($productor, [
            'id' => (string) Str::uuid(), 'activo' => true,
            'asociado_por_user_id' => $this->administrador->id, 'actualizado_por_user_id' => $this->administrador->id,
        ]);

        return $productor;
    }

    private function ingresoInicial(string $tipo, string $propiedad, int $cantidad): void
    {
        MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
            'documento_tipo' => 'recepcion_romana', 'numero_documento' => 'INICIAL-'.$tipo,
            'tipo_movimiento' => $propiedad === 'arrendada' ? 'recepcion_arriendo' : 'recepcion_compra',
            'tipo_envase' => $tipo, 'cantidad' => $cantidad, 'signo_cuenta' => $propiedad === 'arrendada' ? 1 : 0,
            'signo_existencia' => 1, 'propiedad' => $propiedad,
            'ocurrido_at' => '2026-11-01 12:00:00', 'ingreso_at' => '2026-11-01 12:00:00',
            'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->administrador->id,
        ]);
    }

    private function despacho2324(): void
    {
        $this->actingAs($this->administrador, 'sanctum');
        $guia = $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(),
            'cliente_id' => $this->cliente->id,
            'productor_csg_id' => $this->productor->id,
            'salida_at' => '2026-11-10T17:36:00-03:00',
            'detalles' => [
                ['tipo_envase' => 'bins', 'cantidad' => 13, 'propiedad' => 'arrendada'],
                ['tipo_envase' => 'esponjas', 'cantidad' => 14, 'propiedad' => 'propia'],
                ['tipo_envase' => 'totes', 'cantidad' => 312, 'propiedad' => 'arrendada'],
            ],
        ])->assertCreated()->json('data');
        $this->postJson("/api/envases/guias-despacho/{$guia['id']}/confirmar", ['numero_guia_despacho' => '2324'])->assertOk();
    }

    /** Lo que ocurrió en planta: ingreso en arriendo con fecha del día anterior y la hora del registro. */
    private function reingresoEnArriendo(): RecepcionRomana
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $datos = $this->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $this->temporada->id,
            'cliente_id' => $this->cliente->id,
            'tipo_recepcion' => 'solo_envases',
            'concepto_envases' => 'arriendo',
            'fecha_ingreso' => '2026-11-10',
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 13],
                ['tipo_envase' => 'esponjas', 'cantidad' => 14],
                ['tipo_envase' => 'totes', 'cantidad' => 312],
            ],
            'numero_guia_despacho' => '2324',
            'patente_camion' => 'JJLY15',
            'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Luis Duarte',
            'observacion' => 'Camión reingresa con los envases despachados por la guía 2324.',
        ])->assertCreated()->json('data');
        $recepcion = RecepcionRomana::findOrFail($datos['id']);
        $this->assertSame('2026-11-10 12:33:00', $recepcion->ingreso_at->utc()->format('Y-m-d H:i:s'));
        $this->validar($recepcion, ['bins' => 13, 'esponjas' => 14, 'totes' => 312]);

        return $recepcion;
    }

    /** @param array<string, int> $cantidades */
    private function validar(RecepcionRomana $recepcion, array $cantidades): void
    {
        $validacion = $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum')->postJson(
            '/api/validacion-mp/recepciones/'.$recepcion->id.'/tomar',
            ['operacion_id' => (string) Str::uuid()],
        )->assertOk()->json('data');
        $ruta = '/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar';
        $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(),
            'envases' => collect($cantidades)->map(fn (int $cantidad, string $tipo): array => ['tipo_envase' => $tipo, 'cantidad_validada' => $cantidad])->values()->all(),
        ]))->assertOk();
        $this->actingAs($this->administrador, 'sanctum');
    }

    private function saldoCuenta(): int
    {
        return (int) MovimientoEnvase::query()->where('cliente_id', $this->cliente->id)
            ->get()->sum(fn (MovimientoEnvase $movimiento): int => $movimiento->cantidad * $movimiento->signo_cuenta);
    }

    /** @return list<array<string, mixed>> */
    private function resumenCsg(string $codigo): array
    {
        $resumen = collect(app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->cliente, $this->temporada, null, null)['resumen_csg'])
            ->firstWhere('codigo', $codigo);

        return collect($resumen['tipos'] ?? [])->sortBy('tipo_envase')->values()->all();
    }

    /** @return array<string, int> */
    private function existencia(): array
    {
        return MovimientoEnvase::query()->where('temporada_id', $this->temporada->id)->get()
            ->groupBy(fn (MovimientoEnvase $m): string => $m->tipo_envase->value.'|'.$m->propiedad->value)
            ->map(fn ($grupo): int => (int) $grupo->sum(fn (MovimientoEnvase $m): int => $m->cantidad * $m->signo_existencia))
            ->all() + ['esponjas|arrendada' => 0, 'esponjas|propia' => 0, 'bins|arrendada' => 0];
    }
}
