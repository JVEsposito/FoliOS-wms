<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\BinRetornoPacking;
use App\Models\BinRetornoPackingOrigen;
use App\Models\CalibreValidacion;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EntregaFrutaProceso;
use App\Models\EspecieValidacion;
use App\Models\ModificacionBinRetornoPacking;
use App\Models\RegularizacionRetornoPackingLegacy;
use App\Models\RetornoPacking;
use App\Models\Temporada;
use App\Models\TipoResultadoPacking;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Existencias\ServicioExistencias;
use App\Services\MateriaPrima\ServicioDespachoComercialRetorno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class BinRetornoPackingApiTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    public function test_registra_rutas_y_esquema_del_nuevo_modelo_por_bin(): void
    {
        $this->assertTrue(Schema::hasTable('bins_retorno_packing'));
        $this->assertTrue(Schema::hasTable('bin_retorno_packing_origenes'));
        $this->assertTrue(Schema::hasTable('regularizaciones_retorno_packing_legacy'));
        $this->assertTrue(Schema::hasTable('modificaciones_bin_retorno_packing'));
        $this->assertTrue(Schema::hasColumn('bins_retorno_packing', 'folio_definitivo_vigente'));
        $this->assertTrue(Schema::hasColumns('bins_retorno_packing', [
            'temporada_id',
            'folio_provisional',
            'folio_definitivo',
            'kilos_totales',
            'kilos_totales_definitivos',
            'estado',
            'payload_regularizacion_hash',
            'operacion_anulacion_id',
            'payload_anulacion_hash',
            'anulado_por_user_id',
            'anulado_at',
            'motivo_anulacion',
            'retorno_packing_legacy_id',
        ]));
        $this->assertTrue(Schema::hasColumn(
            'bin_retorno_packing_origenes',
            'kilos_aportados_definitivos',
        ));

        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/materia-prima/fruta-proceso/retornos-bin/resumen')
            ->assertOk()
            ->assertJsonPath('bins_registrados', 0)
            ->assertJsonPath('pendientes_regularizacion', 0)
            ->assertJsonPath('regularizados', 0);

        $this->getJson('/api/materia-prima/fruta-proceso/retornos-bin/procesos')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_rechaza_bin_si_no_existe_un_origen_operacional_valido(): void
    {
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        $this->actingAs($camarero, 'sanctum')
            ->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [
                'operacion_id' => (string) Str::uuid(),
                'kilos_totales' => 412,
                'origenes' => [[
                    'lote_materia_prima_id' => (string) Str::uuid(),
                    'numero_orden' => '0608A',
                    'linea_proceso' => '600',
                    'turno' => 'A',
                    'kilos_aportados' => 412,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('origenes.0.lote_materia_prima_id');
    }

    public function test_el_nombre_largo_de_linea_se_conserva_desde_el_viaje_hasta_el_origen_del_bin(): void
    {
        $nombre = str_repeat('L', 60);
        $contexto = $this->prepararEntrega('LINEA60', 'OP-LINEA60', $nombre);
        $payload = $this->payloadBin($contexto, (string) Str::uuid(), 400);
        $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $payload)
            ->assertCreated()->assertJsonPath('data.origenes.0.linea_proceso', $nombre);
        $this->assertDatabaseHas('bin_retorno_packing_origenes', ['linea_proceso' => $nombre]);
        $payload['operacion_id'] = (string) Str::uuid();
        $payload['origenes'][0]['linea_proceso'] = $nombre.'X';
        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('origenes.0.linea_proceso');
    }

    public function test_registra_bin_real_exige_cuadratura_y_rechaza_reintento_con_payload_distinto(): void
    {
        $contexto = $this->prepararEntrega('REGISTRO', 'OP-BIN-001');
        $operacion = (string) Str::uuid();
        $payload = $this->payloadBin($contexto, $operacion, 412.125);

        $respuesta = $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $payload)
            ->assertCreated()
            ->assertJsonPath('data.kilos_totales', 412.125)
            ->assertJsonPath('data.estado', 'pendiente_regularizacion')
            ->assertJsonPath('data.origenes.0.numero_orden', 'OP-BIN-001')
            ->assertJsonPath('data.origenes.0.kilos_aportados', 412.125)
            ->json('data');

        $this->assertMatchesRegularExpression('/^PR-\d{6}$/', $respuesta['folio_provisional']);
        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $respuesta['id'],
            'temporada_id' => $contexto['temporada']->id,
            'operacion_id' => $operacion,
            'kilos_totales' => 412.125,
        ]);
        $this->assertDatabaseHas('bin_retorno_packing_origenes', [
            'bin_retorno_packing_id' => $respuesta['id'],
            'lote_materia_prima_id' => $contexto['lote']['id'],
            'numero_orden' => 'OP-BIN-001',
            'kilos_aportados' => 412.125,
        ]);

        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $respuesta['id']);
        $this->assertDatabaseCount('bins_retorno_packing', 1);
        $this->assertDatabaseCount('bin_retorno_packing_origenes', 1);

        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [
            ...$payload,
            'kilos_totales' => 413.125,
            'origenes' => [[
                ...$payload['origenes'][0],
                'kilos_aportados' => 413.125,
            ]],
        ])->assertStatus(409);

        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [
            ...$payload,
            'operacion_id' => (string) Str::uuid(),
            'origenes' => [[
                ...$payload['origenes'][0],
                'kilos_aportados' => 400,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('origenes');
    }

    public function test_aisla_bins_por_temporada_y_valida_idempotencia_de_regularizacion(): void
    {
        $temporadaActiva = Temporada::query()->where('activa', true)->firstOrFail();
        $temporadaAnterior = Temporada::create([
            'codigo' => '2025-2026',
            'nombre' => 'Temporada anterior',
            'activa' => false,
            'version_catalogo' => 1,
        ]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $this->bin($temporadaActiva, $camarero, 'PR-ACTIVA');
        $this->bin($temporadaAnterior, $camarero, 'PR-HISTORICA');

        $this->actingAs($camarero, 'sanctum')
            ->getJson('/api/materia-prima/fruta-proceso/retornos-bin/bins')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio_provisional', 'PR-ACTIVA');
        $this->getJson('/api/materia-prima/fruta-proceso/retornos-bin/resumen')
            ->assertOk()
            ->assertJsonPath('bins_registrados', 1)
            ->assertJsonPath('pendientes_regularizacion', 1);

        $contexto = $this->prepararEntrega('CUADRATURA', 'OP-CUAD-001');
        $vigente = $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson(
                '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                $this->payloadBin($contexto, (string) Str::uuid(), 412.125),
            )
            ->assertCreated()
            ->assertJsonPath('data.kilos_totales_definitivos', null)
            ->assertJsonPath('data.origenes.0.kilos_aportados_definitivos', null)
            ->json('data');

        $tipo = TipoResultadoPacking::query()->firstOrCreate(
            ['codigo' => 'TEST-RETORNO'],
            [
                'nombre' => 'Retorno de prueba',
                'prefijo_sublote' => 'TR',
                'activo' => true,
                'orden' => 999,
            ],
        );
        $operacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacion,
            'folio_definitivo' => 'MP-RET-0001',
            'tipo_resultado_packing_id' => $tipo->id,
            'nombre_resultado' => 'Retorno comercial',
            'kilos_totales_definitivos' => 410.375,
            'origenes' => [[
                'origen_id' => $vigente['origenes'][0]['id'],
                'kilos_aportados_definitivos' => 410.375,
            ]],
        ];
        $ruta = "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$vigente['id']}/regularizar";

        $this->postJson($ruta, [
            ...$payload,
            'kilos_totales_definitivos' => 410,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('origenes');

        $this->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.folio_definitivo', 'MP-RET-0001')
            ->assertJsonPath('data.kilos_totales_verdes', 412.125)
            ->assertJsonPath('data.kilos_totales_definitivos', 410.375)
            ->assertJsonPath('data.origenes.0.kilos_aportados_verdes', 412.125)
            ->assertJsonPath('data.origenes.0.kilos_aportados_definitivos', 410.375)
            ->assertJsonPath('data.estado', 'regularizado');
        $this->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.folio_definitivo', 'MP-RET-0001');
        $this->postJson($ruta, [
            ...$payload,
            'folio_definitivo' => 'MP-RET-0002',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'El bin ya fue regularizado con otra operación o datos diferentes.');
        $otroTipo = TipoResultadoPacking::query()
            ->whereKeyNot($tipo->id)
            ->where('activo', true)
            ->firstOrFail();
        $this->postJson($ruta, [
            ...$payload,
            'tipo_resultado_packing_id' => $otroTipo->id,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'El bin ya fue regularizado con otra operación o datos diferentes.');
        $this->postJson($ruta, [
            ...$payload,
            'kilos_totales_definitivos' => 409.500,
            'origenes' => [[
                ...$payload['origenes'][0],
                'kilos_aportados_definitivos' => 409.500,
            ]],
        ])->assertStatus(409)
            ->assertJsonPath('message', 'El bin ya fue regularizado con otra operación o datos diferentes.');

        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $vigente['id'],
            'temporada_id' => $temporadaActiva->id,
            'folio_definitivo' => 'MP-RET-0001',
            'kilos_totales' => 412.125,
            'kilos_totales_definitivos' => 410.375,
        ]);
        $this->assertDatabaseHas('bin_retorno_packing_origenes', [
            'id' => $vigente['origenes'][0]['id'],
            'kilos_aportados' => 412.125,
            'kilos_aportados_definitivos' => 410.375,
        ]);
        $this->assertNotNull(
            BinRetornoPacking::query()->findOrFail($vigente['id'])->payload_regularizacion_hash,
        );
    }

    public function test_listado_incluye_todos_los_retornos_de_la_temporada_sin_recorte(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        foreach (range(1, 305) as $numero) {
            $this->bin($temporada, $usuario, sprintf('PR-LISTA-%03d', $numero));
        }

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/materia-prima/fruta-proceso/retornos-bin/bins')
            ->assertOk()
            ->assertJsonCount(305, 'data');
    }

    public function test_modifica_retorno_pendiente_y_regularizado_con_historial_auditable(): void
    {
        $contexto = $this->prepararEntrega('MODIFICAR', 'OP-BIN-MOD');
        $bin = $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson(
                '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                $this->payloadBin($contexto, (string) Str::uuid(), 412.125),
            )
            ->assertCreated()
            ->json('data');
        $ruta = "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$bin['id']}";
        $operacionPendiente = (string) Str::uuid();
        $payloadPendiente = [
            'operacion_id' => $operacionPendiente,
            'motivo' => 'Se corrigió el peso verde y el folio informado en la observación.',
            'kilos_totales' => 410.125,
            'observacion' => 'Folio del bin: BIN-77',
            'origenes' => [[
                'origen_id' => $bin['origenes'][0]['id'],
                'kilos_aportados' => 410.125,
            ]],
        ];

        $this->actingAs($contexto['supervisor'], 'sanctum')
            ->putJson($ruta, $payloadPendiente)
            ->assertOk()
            ->assertJsonPath('data.kilos_totales', 410.125)
            ->assertJsonPath('data.observacion', 'Folio del bin: BIN-77')
            ->assertJsonPath('data.modificado_por', $contexto['supervisor']->name)
            ->assertJsonPath(
                'data.motivo_ultima_modificacion',
                $payloadPendiente['motivo'],
            );

        $this->putJson($ruta, $payloadPendiente)
            ->assertOk()
            ->assertJsonPath('data.kilos_totales', 410.125);
        $this->assertDatabaseCount('modificaciones_bin_retorno_packing', 1);

        $this->putJson($ruta, [
            ...$payloadPendiente,
            'kilos_totales' => 411.125,
            'origenes' => [[
                ...$payloadPendiente['origenes'][0],
                'kilos_aportados' => 411.125,
            ]],
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'El identificador de modificación ya fue utilizado con datos diferentes.',
            );

        $primeraModificacion = ModificacionBinRetornoPacking::query()
            ->where('operacion_id', $operacionPendiente)
            ->firstOrFail();
        $this->assertSame('412.125', $primeraModificacion->datos_anteriores['kilos_totales']);
        $this->assertSame('410.125', $primeraModificacion->datos_nuevos['kilos_totales']);

        $tipo = TipoResultadoPacking::query()
            ->where('activo', true)
            ->firstOrFail();
        $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson("{$ruta}/regularizar", [
                'operacion_id' => (string) Str::uuid(),
                'folio_definitivo' => 'MP-MOD-0001',
                'tipo_resultado_packing_id' => $tipo->id,
                'nombre_resultado' => 'Resultado antes de corregir',
                'kilos_totales_definitivos' => 409.750,
                'origenes' => [[
                    'origen_id' => $bin['origenes'][0]['id'],
                    'kilos_aportados_definitivos' => 409.750,
                ]],
            ])->assertOk();

        $operacionRegularizado = (string) Str::uuid();
        $payloadRegularizado = [
            'operacion_id' => $operacionRegularizado,
            'motivo' => 'Se corrigieron el folio y los pesos definitivos digitados.',
            'kilos_totales' => 409.500,
            'observacion' => 'Folio del bin: BIN-77 corregido',
            'folio_definitivo' => 'MP-MOD-0002',
            'tipo_resultado_packing_id' => $tipo->id,
            'nombre_resultado' => 'Resultado corregido',
            'kilos_totales_definitivos' => 408.500,
            'origenes' => [[
                'origen_id' => $bin['origenes'][0]['id'],
                'kilos_aportados' => 409.500,
                'kilos_aportados_definitivos' => 408.500,
            ]],
        ];

        $this->actingAs($contexto['supervisor'], 'sanctum')
            ->putJson($ruta, $payloadRegularizado)
            ->assertOk()
            ->assertJsonPath('data.folio_provisional', $bin['folio_provisional'])
            ->assertJsonPath('data.folio_definitivo', 'MP-MOD-0002')
            ->assertJsonPath('data.kilos_totales_verdes', 409.5)
            ->assertJsonPath('data.kilos_totales_definitivos', 408.5)
            ->assertJsonPath('data.origenes.0.kilos_aportados_verdes', 409.5)
            ->assertJsonPath('data.origenes.0.kilos_aportados_definitivos', 408.5);

        $this->assertDatabaseCount('modificaciones_bin_retorno_packing', 2);
        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $bin['id'],
            'folio_provisional' => $bin['folio_provisional'],
            'folio_definitivo' => 'MP-MOD-0002',
            'kilos_totales' => 409.500,
            'kilos_totales_definitivos' => 408.500,
        ]);
        $this->assertDatabaseHas('modificaciones_bin_retorno_packing', [
            'bin_retorno_packing_id' => $bin['id'],
            'operacion_id' => $operacionRegularizado,
            'modificado_por_user_id' => $contexto['supervisor']->id,
            'motivo' => $payloadRegularizado['motivo'],
        ]);
    }

    public function test_modifica_los_procesos_de_origen_y_conserva_ambas_versiones_en_auditoria(): void
    {
        $principal = $this->prepararEntrega('ORIGEN-PRINCIPAL', 'OP-ORIGEN-001');
        $adicional = $this->prepararEntrega('ORIGEN-ADICIONAL', 'OP-ORIGEN-002');
        $bin = $this->actingAs($principal['camarero'], 'sanctum')
            ->postJson(
                '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                $this->payloadBin($principal, (string) Str::uuid(), 508),
            )
            ->assertCreated()
            ->json('data');

        $payload = [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'El retorno provenía de dos procesos y faltaba registrar el segundo.',
            'kilos_totales' => 508,
            'observacion' => 'Folio del bin: 31804',
            'origenes' => [
                [
                    'origen_id' => $bin['origenes'][0]['id'],
                    ...$this->origen($principal, 408),
                ],
                $this->origen($adicional, 100),
            ],
        ];

        $this->actingAs($principal['supervisor'], 'sanctum')
            ->putJson(
                "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$bin['id']}",
                $payload,
            )
            ->assertOk()
            ->assertJsonCount(2, 'data.origenes')
            ->assertJsonPath('data.kilos_totales', 508);

        $this->assertSame(
            2,
            BinRetornoPackingOrigen::query()
                ->where('bin_retorno_packing_id', $bin['id'])
                ->count(),
        );
        $this->assertDatabaseHas('bin_retorno_packing_origenes', [
            'bin_retorno_packing_id' => $bin['id'],
            'numero_orden' => $principal['numero_orden'],
            'kilos_aportados' => 408,
        ]);
        $this->assertDatabaseHas('bin_retorno_packing_origenes', [
            'bin_retorno_packing_id' => $bin['id'],
            'numero_orden' => $adicional['numero_orden'],
            'kilos_aportados' => 100,
        ]);

        $modificacion = ModificacionBinRetornoPacking::query()
            ->where('operacion_id', $payload['operacion_id'])
            ->firstOrFail();
        $this->assertCount(1, $modificacion->datos_anteriores['origenes']);
        $this->assertCount(2, $modificacion->datos_nuevos['origenes']);
    }

    public function test_anula_retorno_sin_borrarlo_y_exige_idempotencia_estricta(): void
    {
        $contexto = $this->prepararEntrega('ANULAR', 'OP-BIN-ANULAR');
        $bin = $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson(
                '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                $this->payloadBin($contexto, (string) Str::uuid(), 412.125),
            )
            ->assertCreated()
            ->json('data');
        $operacion = (string) Str::uuid();
        $ruta = "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$bin['id']}/anular";
        $payload = [
            'operacion_id' => $operacion,
            'motivo' => 'Peso verde ingresado en el proceso equivocado.',
        ];

        $this->actingAs($contexto['supervisor'], 'sanctum')
            ->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulado')
            ->assertJsonPath('data.motivo_anulacion', $payload['motivo']);

        $this->postJson($ruta, $payload)
            ->assertOk()
            ->assertJsonPath('data.estado', 'anulado');

        $this->postJson($ruta, [
            ...$payload,
            'motivo' => 'Se intenta cambiar el motivo después de anular.',
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'El bin ya fue anulado con otra operación o un motivo diferente.',
            );

        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $bin['id'],
            'estado' => 'anulado',
            'operacion_anulacion_id' => $operacion,
            'anulado_por_user_id' => $contexto['supervisor']->id,
            'motivo_anulacion' => $payload['motivo'],
        ]);
        $this->assertDatabaseCount('bins_retorno_packing', 1);
        $this->getJson('/api/materia-prima/fruta-proceso/retornos-bin/resumen')
            ->assertOk()
            ->assertJsonPath('bins_registrados', 0)
            ->assertJsonPath('kilos_registrados', 0);
    }

    public function test_anular_libera_el_folio_definitivo_sin_borrar_el_historial(): void
    {
        $original = $this->prepararEntrega('FOLIO-ORIGINAL', 'OP-FOLIO-001');
        $reingreso = $this->prepararEntrega('FOLIO-REINGRESO', 'OP-FOLIO-002');
        $tipo = TipoResultadoPacking::query()->where('activo', true)->firstOrFail();
        $primerBin = $this->actingAs($original['camarero'], 'sanctum')
            ->postJson(
                '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                $this->payloadBin($original, (string) Str::uuid(), 508),
            )
            ->assertCreated()
            ->json('data');
        $rutaPrimerBin = "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$primerBin['id']}";

        $this->postJson("{$rutaPrimerBin}/regularizar", [
            'operacion_id' => (string) Str::uuid(),
            'folio_definitivo' => '31804',
            'tipo_resultado_packing_id' => $tipo->id,
            'kilos_totales_definitivos' => 508,
            'origenes' => [[
                'origen_id' => $primerBin['origenes'][0]['id'],
                'kilos_aportados_definitivos' => 508,
            ]],
        ])->assertOk();
        $this->actingAs($original['supervisor'], 'sanctum')
            ->postJson("{$rutaPrimerBin}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'El retorno estaba asociado a un solo proceso por error.',
            ])
            ->assertOk();

        $segundoBin = $this->actingAs($reingreso['camarero'], 'sanctum')
            ->postJson(
                '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                $this->payloadBin($reingreso, (string) Str::uuid(), 508),
            )
            ->assertCreated()
            ->json('data');
        $this->postJson(
            "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$segundoBin['id']}/regularizar",
            [
                'operacion_id' => (string) Str::uuid(),
                'folio_definitivo' => '31804',
                'tipo_resultado_packing_id' => $tipo->id,
                'kilos_totales_definitivos' => 508,
                'origenes' => [[
                    'origen_id' => $segundoBin['origenes'][0]['id'],
                    'kilos_aportados_definitivos' => 508,
                ]],
            ],
        )->assertOk()
            ->assertJsonPath('data.folio_definitivo', '31804');

        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $primerBin['id'],
            'estado' => 'anulado',
            'folio_definitivo' => '31804',
            'folio_definitivo_vigente' => null,
        ]);
        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $segundoBin['id'],
            'estado' => 'regularizado',
            'folio_definitivo' => '31804',
            'folio_definitivo_vigente' => '31804',
        ]);
        $this->assertSame(
            2,
            BinRetornoPacking::query()->where('folio_definitivo', '31804')->count(),
        );
    }

    public function test_migra_y_descarta_legado_con_auditoria_cuadratura_e_idempotencia(): void
    {
        $contexto = $this->prepararEntrega('LEGADO', 'OP-LEG-001');
        $tipo = TipoResultadoPacking::query()
            ->where('codigo', 'precalibre')
            ->firstOrFail();
        $retornoMigrable = $this->registrarRetornoLegacy(
            $contexto,
            $tipo,
            1,
            400,
        );

        $operacionMigracion = (string) Str::uuid();
        $payloadMigracion = [
            'operacion_id' => $operacionMigracion,
            'kilos_totales' => 400,
            'motivo' => 'Conversión controlada al modelo por bin.',
            'origenes' => [$this->origen($contexto, 400)],
        ];
        $rutaMigracion = "/api/materia-prima/fruta-proceso/retornos-bin/legacy/{$retornoMigrable->id}/migrar";
        $migrado = $this->actingAs($contexto['supervisor'], 'sanctum')
            ->postJson($rutaMigracion, $payloadMigracion)
            ->assertCreated()
            ->assertJsonPath('data.kilos_totales', 400)
            ->assertJsonPath('data.retorno_legacy', $retornoMigrable->numero)
            ->json('data');

        $this->assertDatabaseHas('bins_retorno_packing', [
            'id' => $migrado['id'],
            'temporada_id' => $contexto['temporada']->id,
            'retorno_packing_legacy_id' => $retornoMigrable->id,
        ]);
        $this->assertDatabaseHas('regularizaciones_retorno_packing_legacy', [
            'operacion_id' => $operacionMigracion,
            'retorno_packing_id' => $retornoMigrable->id,
            'bin_retorno_packing_id' => $migrado['id'],
            'accion' => 'migrado',
        ]);

        $this->postJson($rutaMigracion, $payloadMigracion)
            ->assertCreated()
            ->assertJsonPath('data.id', $migrado['id']);
        $this->postJson($rutaMigracion, [
            ...$payloadMigracion,
            'kilos_totales' => 401,
            'origenes' => [$this->origen($contexto, 401)],
        ])->assertStatus(409);

        $retornoDescartable = $this->registrarRetornoLegacy(
            $contexto,
            $tipo,
            2,
            780,
        );
        $operacionDescarte = (string) Str::uuid();
        $rutaDescarte = "/api/materia-prima/fruta-proceso/retornos-bin/legacy/{$retornoDescartable->id}/descartar";
        $payloadDescarte = [
            'operacion_id' => $operacionDescarte,
            'motivo' => 'El retorno agrupó dos bins y debe reingresarse individualmente.',
        ];

        $this->postJson($rutaDescarte, $payloadDescarte)
            ->assertOk()
            ->assertJsonPath('message', 'Retorno anterior descartado. Reingresa sus bins individualmente.');
        $this->postJson($rutaDescarte, $payloadDescarte)->assertOk();
        $this->postJson($rutaDescarte, [
            ...$payloadDescarte,
            'motivo' => 'Motivo contradictorio para el mismo UUID.',
        ])->assertStatus(409);

        $this->assertDatabaseHas('regularizaciones_retorno_packing_legacy', [
            'operacion_id' => $operacionDescarte,
            'retorno_packing_id' => $retornoDescartable->id,
            'accion' => 'descartado',
        ]);
        $this->assertNotNull($retornoDescartable->fresh()->anulado_at);
        $this->assertDatabaseMissing('sublotes_retorno_packing', [
            'retorno_packing_id' => $retornoDescartable->id,
            'estado' => 'pendiente_ubicacion',
        ]);
        $this->assertSame(2, RegularizacionRetornoPackingLegacy::query()->count());
    }

    public function test_oficina_presenta_recepcion_regularizacion_y_legado(): void
    {
        $this->get('/oficina/materia-prima/retornos-packing')
            ->assertOk()
            ->assertSee('Registrar retorno de fruta')
            ->assertSee('Todos los retornos registrados')
            ->assertSee('Folio, observación, lote u orden')
            ->assertSee('Guardar modificación')
            ->assertSee('Motivo de la corrección')
            ->assertSee('Agregar proceso')
            ->assertSee('Puedes agregar, quitar o reemplazar procesos de origen')
            ->assertSee('Pendientes de regularizar')
            ->assertSee('Registros anteriores')
            ->assertSee('Kilos totales definitivos')
            ->assertSee('Observación del retorno físico')
            ->assertSee('id="regularizeObservation"', false)
            ->assertSee('Cuadraturas debe confirmar folio, clasificación, kilos totales y kilos definitivos por proceso.')
            ->assertSee('folio provisional', false);

        $script = file_get_contents(resource_path('js/office-raw-material-returns.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('kilos_totales_definitivos', $script);
        $this->assertStringContainsString('kilos_aportados_definitivos', $script);
        $this->assertStringContainsString('Regularizar folio y kilos', $script);
        $this->assertStringContainsString('regularizeObservation', $script);
        $this->assertStringContainsString('bin.observacion', $script);
        $this->assertStringContainsString('Anular retorno', $script);
        $this->assertStringContainsString('data-annul-bin', $script);
        $this->assertStringContainsString('data-edit-bin', $script);
        $this->assertStringContainsString('data-remove-edit-origin', $script);
        $this->assertStringContainsString('lote_materia_prima_id: origin.lote_materia_prima_id', $script);
        $this->assertStringContainsString("method: 'PUT'", $script);
        $this->assertStringNotContainsString('state.bins.slice(0, 8)', $script);
    }

    public function test_existencia_materia_prima_descuenta_entregas_e_incluye_retornos_clasificados(): void
    {
        $contexto = $this->prepararEntrega('EXISTENCIAS', 'OP-EXIST-001');

        foreach ([
            'comercial' => [412.125, 410.375],
            'precalibre' => [401.500, 399.750],
            'descarte' => [388.250, 386.000],
        ] as $codigo => [$kilosVerdes, $kilosDefinitivos]) {
            $tipo = TipoResultadoPacking::query()->where('codigo', $codigo)->firstOrFail();
            $bin = $this->actingAs($contexto['camarero'], 'sanctum')
                ->postJson(
                    '/api/materia-prima/fruta-proceso/retornos-bin/bins',
                    $this->payloadBin($contexto, (string) Str::uuid(), $kilosVerdes),
                )
                ->assertCreated()
                ->json('data');

            $this->postJson("/api/materia-prima/fruta-proceso/retornos-bin/bins/{$bin['id']}/regularizar", [
                'operacion_id' => (string) Str::uuid(),
                'folio_definitivo' => 'RET-EX-'.strtoupper($codigo),
                'tipo_resultado_packing_id' => $tipo->id,
                'nombre_resultado' => $tipo->nombre,
                'kilos_totales_definitivos' => $kilosDefinitivos,
                'origenes' => [[
                    'origen_id' => $bin['origenes'][0]['id'],
                    'kilos_aportados_definitivos' => $kilosDefinitivos,
                ]],
            ])->assertOk();
        }

        $filas = app(ServicioExistencias::class)
            ->filas(ServicioExistencias::MATERIA_PRIMA)
            ->collect();
        $lote = $filas->firstWhere('folio_existencia', 'LOTE-EXISTENCIAS');

        $this->assertNotNull($lote);
        $this->assertSame('Lote recibido', $lote['tipo_existencia']);
        $this->assertSame('Entrega parcial', $lote['estado_entrega']);
        $this->assertSame('20/48', $lote['avance_entrega']);
        $this->assertSame(48, $lote['cantidad_primarios_inicial']);
        $this->assertSame(20, $lote['cantidad_primarios_entregada']);
        $this->assertSame(28, $lote['cantidad_primarios']);
        $this->assertSame(7500.0, $lote['kilos_enviados_packing']);
        $this->assertSame(10500.0, $lote['kilos_existencia_actual']);

        $retornos = $filas->where('tipo_existencia', 'Retorno de Packing')->values();

        $this->assertCount(3, $retornos);
        $this->assertEqualsCanonicalizing(
            TipoResultadoPacking::query()
                ->whereIn('codigo', ['comercial', 'precalibre', 'descarte'])
                ->pluck('nombre')
                ->all(),
            $retornos->pluck('clasificacion_retorno')->all(),
        );
        $this->assertSame([1], $retornos->pluck('cantidad_primarios')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            [410.375, 399.75, 386.0],
            $retornos->pluck('kilos_existencia_actual')->all(),
        );
        $this->assertTrue($retornos->every(
            fn (array $fila): bool => $fila['estado_kilos_existencia'] === 'Kilos definitivos confirmados por Cuadraturas'
                && $fila['lotes_origen'] === 'LOTE-EXISTENCIAS'
                && str_contains($fila['procesos_origen'], 'OP-EXIST-001'),
        ));
    }

    public function test_registra_cajas_tres_cuartos_con_existencia_y_contadores_en_su_unidad(): void
    {
        $c = $this->prepararEntrega('CAJAS', 'OP-CAJAS');
        $payload = [...$this->payloadBin($c, (string) Str::uuid(), 240), 'tipo_envase' => 'caja_3_4', 'cantidad_envases' => 24];
        $data = $this->actingAs($c['camarero'], 'sanctum')->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $payload)
            ->assertCreated()->assertJsonPath('data.tipo_envase', 'caja_3_4')->assertJsonPath('data.cantidad_envases', 24)
            ->assertJsonPath('data.envase', 'CAJAS 3/4')->json('data');
        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $payload)->assertCreated()->assertJsonPath('data.id', $data['id']);
        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [...$payload, 'cantidad_envases' => 25])->assertConflict();
        $this->getJson('/api/materia-prima/fruta-proceso/retornos-bin/resumen')->assertOk()
            ->assertJsonPath('bins_registrados', 0)->assertJsonPath('cajas_3_4_registradas', 24)->assertJsonPath('retornos_registrados', 1);
        $fila = app(ServicioExistencias::class)->filas(ServicioExistencias::MATERIA_PRIMA)->collect()->firstWhere('folio_existencia', $data['folio_provisional']);
        $this->assertSame('CAJAS 3/4', $fila['envase_primario']);
        $this->assertSame(24, $fila['cantidad_primarios']);
        $this->assertSame(240.0, $fila['kilos_existencia_actual']);
        $this->actingAs($c['supervisor'], 'sanctum')->postJson("/api/materia-prima/fruta-proceso/retornos-bin/bins/{$data['id']}/anular", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Retorno ingresado por error en las pruebas.',
        ])->assertOk()->assertJsonPath('data.cantidad_envases', 24);
        $this->getJson('/api/materia-prima/fruta-proceso/retornos-bin/resumen')->assertOk()->assertJsonPath('cajas_3_4_registradas', 0);
        $this->assertDatabaseCount('bins_retorno_packing', 1);
    }

    public function test_reenvio_de_bin_anterior_conserva_su_hash_y_las_cajas_exigen_cantidad_valida(): void
    {
        $c = $this->prepararEntrega('ENVASE', 'OP-ENVASE');
        $base = $this->payloadBin($c, (string) Str::uuid(), 100);
        $this->actingAs($c['camarero'], 'sanctum');
        foreach ([['tipo_envase' => 'caja_3_4'], ['tipo_envase' => 'caja_3_4', 'cantidad_envases' => 0],
            ['tipo_envase' => 'caja_3_4', 'cantidad_envases' => 1.5], ['tipo_envase' => 'bins', 'cantidad_envases' => 2]] as $invalid) {
            $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [...$base, ...$invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('cantidad_envases');
        }
        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [...$base, 'tipo_envase' => 'otro'])
            ->assertUnprocessable()->assertJsonValidationErrors('tipo_envase');
        $id = $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', $base)->assertCreated()->json('data.id');
        $this->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins', [...$base, 'tipo_envase' => 'bins', 'cantidad_envases' => 1])
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('bins_retorno_packing', 1);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $this->actingAs($validador, 'sanctum')->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins',
            [...$base, 'operacion_id' => (string) Str::uuid(), 'tipo_envase' => 'caja_3_4', 'cantidad_envases' => 12])->assertForbidden();
    }

    public function test_corrige_envase_con_motivo_y_lo_conserva_al_regularizar_y_despachar(): void
    {
        $c = $this->prepararEntrega('CORRCAJA', 'OP-CORRCAJA');
        $data = $this->actingAs($c['camarero'], 'sanctum')->postJson('/api/materia-prima/fruta-proceso/retornos-bin/bins',
            $this->payloadBin($c, (string) Str::uuid(), 240))->assertCreated()->json('data');
        $ruta = "/api/materia-prima/fruta-proceso/retornos-bin/bins/{$data['id']}";
        $cambio = ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Packing devolvió cajas 3/4 y se digitó un bin.',
            'tipo_envase' => 'caja_3_4', 'cantidad_envases' => 24, 'kilos_totales' => 240,
            'origenes' => [['origen_id' => $data['origenes'][0]['id'], 'kilos_aportados' => 240]]];
        $this->actingAs($c['supervisor'], 'sanctum')->putJson($ruta, $cambio)->assertOk()->assertJsonPath('data.cantidad_envases', 24);
        $this->putJson($ruta, $cambio)->assertOk();
        $audit = ModificacionBinRetornoPacking::query()->sole();
        $this->assertSame('bins', $audit->datos_anteriores['tipo_envase']);
        $this->assertSame('caja_3_4', $audit->datos_nuevos['tipo_envase']);
        $this->assertSame(24, $audit->datos_nuevos['cantidad_envases']);
        unset($cambio['tipo_envase'], $cambio['cantidad_envases']);
        $cambio['operacion_id'] = (string) Str::uuid();
        $this->putJson($ruta, $cambio)->assertOk()->assertJsonPath('data.tipo_envase', 'caja_3_4')->assertJsonPath('data.cantidad_envases', 24);
        $tipo = TipoResultadoPacking::where('activo', true)->firstOrFail();
        $this->actingAs($c['camarero'], 'sanctum')->postJson($ruta.'/regularizar', [
            'operacion_id' => (string) Str::uuid(), 'folio_definitivo' => 'RET-CAJA-24', 'tipo_resultado_packing_id' => $tipo->id,
            'kilos_totales_definitivos' => 235, 'origenes' => [['origen_id' => $data['origenes'][0]['id'], 'kilos_aportados_definitivos' => 235]],
        ])->assertOk()->assertJsonPath('data.cantidad_envases', 24)->assertJsonPath('data.kilos_totales_definitivos', 235);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $despacho = app(ServicioDespachoComercialRetorno::class)->crear([
            'destinatario' => 'Comprador', 'bins' => [$data['id']],
        ], $admin);
        $this->assertSame('caja_3_4', $despacho->bins->first()->tipo_envase);
        $this->assertSame(24, $despacho->bins->first()->cantidad_envases);
        $this->actingAs($admin, 'sanctum')->putJson($ruta, [...$cambio, 'operacion_id' => (string) Str::uuid(),
            'folio_definitivo' => 'RET-CAJA-24', 'tipo_resultado_packing_id' => $tipo->id, 'kilos_totales_definitivos' => 235,
            'origenes' => [['origen_id' => $data['origenes'][0]['id'], 'kilos_aportados' => 240, 'kilos_aportados_definitivos' => 235]],
        ])->assertConflict();
    }

    public function test_retorno_por_viaje_separa_bins_y_cajas_y_conserva_la_unidad_al_ubicar(): void
    {
        $c = $this->prepararEntrega('MIXCAJA', 'OP-MIXCAJA');
        $tipo = TipoResultadoPacking::where('activo', true)->firstOrFail();
        $payload = ['operacion_id' => (string) Str::uuid(), 'cierra_entrega' => false, 'resultados' => [
            ['tipo_resultado_packing_id' => $tipo->id, 'cantidad_bins' => 2, 'kilos_netos' => 400],
            ['tipo_resultado_packing_id' => $tipo->id, 'tipo_envase' => 'caja_3_4', 'cantidad_envases' => 6, 'cantidad_bins' => 0, 'kilos_netos' => 60],
        ]];
        $ruta = "/api/materia-prima/fruta-proceso/entregas/{$c['entrega']['id']}/retornos";
        $lote = $this->actingAs($c['camarero'], 'sanctum')->postJson($ruta, $payload)->assertOk()
            ->assertJsonPath('data.entregas.0.retorno.bins_retornados', 2)
            ->assertJsonPath('data.entregas.0.retorno.cajas_3_4_retornadas', 6)->json('data');
        $this->postJson($ruta, $payload)->assertOk();
        $payload['resultados'][0]['tipo_envase'] = 'bins';
        $payload['resultados'][0]['cantidad_envases'] = 2;
        $this->postJson($ruta, $payload)->assertOk();
        $invalido = $payload;
        $invalido['operacion_id'] = (string) Str::uuid();
        $invalido['resultados'][1]['cantidad_bins'] = 6;
        $this->postJson($ruta, $invalido)->assertUnprocessable()->assertJsonValidationErrors('resultados.1.cantidad_envases');
        $this->assertDatabaseCount('sublotes_retorno_packing', 2);
        $this->getJson('/api/materia-prima/fruta-proceso/resumen')->assertOk()->assertJsonPath('bins_retornados', 2)
            ->assertJsonPath('cajas_3_4_retornadas', 6)->assertJsonPath('kilos_recuperados', 460);
        $cajas = collect($lote['entregas'][0]['retorno']['movimientos'][0]['resultados'])->firstWhere('tipo_envase', 'caja_3_4');
        $camara = Camara::where('codigo', 'MP-BIN-MIXCAJA')->firstOrFail();
        $this->postJson("/api/materia-prima/fruta-proceso/sublotes/{$cajas['id']}/ubicar", [
            'operacion_id' => (string) Str::uuid(), 'camara_id' => $camara->id,
        ])->assertOk();
        $this->assertDatabaseHas('eventos_lote_materia_prima', ['tipo' => 'sublote_packing_ubicado', 'datos->tipo_envase' => 'caja_3_4', 'datos->cantidad_envases' => 6]);
        $this->assertSame(20, EntregaFrutaProceso::findOrFail($c['entrega']['id'])->cantidad_envases);
    }

    public function test_cajas_por_viaje_pueden_migrarse_a_un_folio_con_su_cantidad(): void
    {
        $c = $this->prepararEntrega('MIGCAJA', 'OP-MIGCAJA');
        $tipo = TipoResultadoPacking::where('activo', true)->firstOrFail();
        $op = (string) Str::uuid();
        $this->actingAs($c['camarero'], 'sanctum')->postJson("/api/materia-prima/fruta-proceso/entregas/{$c['entrega']['id']}/retornos", [
            'operacion_id' => $op, 'cierra_entrega' => false, 'resultados' => [[
                'tipo_resultado_packing_id' => $tipo->id, 'tipo_envase' => 'caja_3_4', 'cantidad_envases' => 12, 'kilos_netos' => 120,
            ]],
        ])->assertOk();
        $retorno = RetornoPacking::where('operacion_id', $op)->firstOrFail();
        $this->getJson('/api/materia-prima/fruta-proceso/retornos-bin/legacy')->assertOk()->assertJsonPath('data.0.migrable', true);
        $this->actingAs($c['supervisor'], 'sanctum')->postJson("/api/materia-prima/fruta-proceso/retornos-bin/legacy/{$retorno->id}/migrar", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Identificar el retorno de doce cajas con su folio.',
            'kilos_totales' => 120, 'origenes' => [$this->origen($c, 120)],
        ])->assertCreated()->assertJsonPath('data.tipo_envase', 'caja_3_4')->assertJsonPath('data.cantidad_envases', 12);
    }

    private function bin(
        Temporada $temporada,
        User $usuario,
        string $folioProvisional,
    ): BinRetornoPacking {
        return BinRetornoPacking::create([
            'temporada_id' => $temporada->id,
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $folioProvisional),
            'folio_provisional' => $folioProvisional,
            'kilos_totales' => 400,
            'estado' => 'pendiente_regularizacion',
            'registrado_por_user_id' => $usuario->id,
            'registrado_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function prepararEntrega(string $sufijo, string $numeroOrden, string $linea = 'Línea 2'): array
    {
        $contexto = $this->prepararRecepcionValidada($sufijo);
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $camara = Camara::create([
            'codigo' => "MP-BIN-{$sufijo}",
            'nombre' => "Cámara retorno {$sufijo}",
            'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);

        $lote = $this->actingAs($digitador, 'sanctum')
            ->postJson('/api/materia-prima/lotes', $this->payloadLote($contexto, [
                'numero_lote' => "LOTE-{$sufijo}",
                'requiere_hidrocooler' => false,
            ]))
            ->assertCreated()
            ->json('data');
        $lote = $this->postJson("/api/materia-prima/lotes/{$lote['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $lote['version'],
        ])->assertOk()->json('data');
        $this->postJson("/api/materia-prima/lotes/{$lote['id']}/asignar-camara", [
            'operacion_id' => (string) Str::uuid(),
            'camara_id' => $camara->id,
        ])->assertOk();

        $entrega = $this->actingAs($camarero, 'sanctum')
            ->postJson("/api/materia-prima/fruta-proceso/lotes/{$lote['id']}/entregas", [
                'operacion_id' => (string) Str::uuid(),
                'cantidad_envases' => 20,
                'kilos_enviados' => 7500,
                'linea_proceso' => $linea,
                'turno' => 'A',
                'numero_orden' => $numeroOrden,
            ])
            ->assertOk()
            ->json('data.entregas.0');

        return [
            ...$contexto,
            'lote' => $lote,
            'entrega' => $entrega,
            'camarero' => $camarero,
            'supervisor' => $supervisor,
            'numero_orden' => $numeroOrden,
            'linea_proceso' => $linea,
            'turno' => 'A',
        ];
    }

    /** @return array<string, mixed> */
    private function prepararRecepcionValidada(string $sufijo): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::create([
            'codigo' => "EXP-{$sufijo}",
            'nombre' => "Exportadora {$sufijo}",
            'activo' => true,
        ]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', [
                'operacion_id' => (string) Str::uuid(),
                'temporada_id' => $temporada->id,
                'cliente_id' => $cliente->id,
                'tipo_recepcion' => 'fruta_con_envases',
                'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => "Cereza {$sufijo}"], ['activo' => true])->id,
                'tipo_servicio' => 'proceso',
                'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad' => 48],
                    ['tipo_envase' => 'totes', 'cantidad' => 10],
                ],
                'numero_guia_despacho' => "GD-{$sufijo}",
                'patente_camion' => 'ABCD12',
                'tipo_camion' => 'plano',
                'rut_conductor' => '12.345.678-5',
                'nombre_conductor' => 'Transportista MP',
                'peso_bruto' => 28000,
            ])
            ->assertCreated()
            ->json('data');
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();

        $especie = EspecieValidacion::firstOrCreate([
            'temporada_id' => $temporada->id,
            'nombre' => "Cereza {$sufijo}",
            'activo' => true,
        ]);
        $variedad = VariedadValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => "Santina {$sufijo}",
            'activo' => true,
        ]);
        $calibre = CalibreValidacion::create([
            'especie_validacion_id' => $especie->id,
            'nombre' => '28 mm',
            'activo' => true,
        ]);
        $csg = CsgValidacion::create([
            'temporada_id' => $temporada->id,
            'codigo' => "CSG-{$sufijo}",
            'predio' => "Fundo {$sufijo}",
            'activo' => true,
        ]);

        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", [
                'operacion_id' => (string) Str::uuid(),
            ])->assertOk()->json('data');
        $segmentoId = $this->postJson(
            "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar", $this->payloadConInspeccionRc02("/api/validacion-mp/validaciones/{$validacion['id']}/confirmar", [
                'operacion_id' => (string) Str::uuid(),
                'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad_validada' => 48],
                    ['tipo_envase' => 'totes', 'cantidad_validada' => 10],
                ],
                'tarjas_verificadas' => true,
                'requiere_segregacion' => false,
                'csg_validacion_id' => $csg->id,
                'variedad_validacion_id' => $variedad->id,
            ]),
        )->assertOk()->json('data.segmentos.0.id');

        $this->actingAs($operador, 'sanctum')->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", $this->payloadConInspeccionRc02("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'mismos',
            'numero_guia_salida' => "GS-{$sufijo}",
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]],
            'peso_tara' => 10000,
            'tipo_envase_calculo_neto' => 'bins',
        ]))->assertOk();

        return [
            'temporada' => $temporada,
            'cliente' => $cliente,
            'segmento_id' => $segmentoId,
            'csg_id' => $csg->id,
            'especie_id' => $especie->id,
            'variedad_id' => $variedad->id,
            'calibre_id' => $calibre->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $reemplazos
     * @return array<string, mixed>
     */
    private function payloadLote(array $contexto, array $reemplazos = []): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'segmento_validacion_mp_id' => $contexto['segmento_id'],
            'numero_lote' => 'LOTE-BIN-RETORNO',
            'csg_validacion_id' => $contexto['csg_id'],
            'sdp' => '987654321',
            'ggn' => '1234567890123',
            'fecha_cosecha' => '2026-08-09',
            'predio' => 'Fundo de prueba',
            'especie_validacion_id' => $contexto['especie_id'],
            'variedad_validacion_id' => $contexto['variedad_id'],
            'calibre_validacion_id' => $contexto['calibre_id'],
            'cuartel' => 'C-12',
            'tipo_producto' => 'materia_prima',
            'envase_primario' => 'bins',
            'envase_secundario' => 'totes',
            'cantidad_envases_primarios' => 48,
            'cantidad_envases_secundarios' => 10,
            'kilos_brutos' => 19000,
            'kilos_netos_confirmados' => 18000,
            'requiere_hidrocooler' => false,
            ...$reemplazos,
        ];
    }

    /** @param array<string, mixed> $contexto */
    private function payloadBin(array $contexto, string $operacion, float $kilos): array
    {
        return [
            'operacion_id' => $operacion,
            'kilos_totales' => $kilos,
            'observacion' => 'Retorno físico individual.',
            'origenes' => [$this->origen($contexto, $kilos)],
        ];
    }

    /** @param array<string, mixed> $contexto */
    private function origen(array $contexto, float $kilos): array
    {
        return [
            'lote_materia_prima_id' => $contexto['lote']['id'],
            'numero_orden' => $contexto['numero_orden'],
            'linea_proceso' => $contexto['linea_proceso'],
            'turno' => $contexto['turno'],
            'kilos_aportados' => $kilos,
        ];
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function registrarRetornoLegacy(
        array $contexto,
        TipoResultadoPacking $tipo,
        int $bins,
        float $kilos,
    ): RetornoPacking {
        $operacion = (string) Str::uuid();
        $this->actingAs($contexto['camarero'], 'sanctum')
            ->postJson(
                "/api/materia-prima/fruta-proceso/entregas/{$contexto['entrega']['id']}/retornos",
                [
                    'operacion_id' => $operacion,
                    'cierra_entrega' => false,
                    'resultados' => [[
                        'tipo_resultado_packing_id' => $tipo->id,
                        'cantidad_bins' => $bins,
                        'kilos_netos' => $kilos,
                    ]],
                ],
            )->assertOk();

        return RetornoPacking::query()
            ->where('operacion_id', $operacion)
            ->firstOrFail();
    }
}
