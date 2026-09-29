<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValidacionMpApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_recepcion_historica_sin_especie_se_completa_con_auditoria_y_filtra_variedades(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $otra = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Uva', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina', 'activo' => true]);
        VariedadValidacion::create(['especie_validacion_id' => $otra->id, 'nombre' => 'Red Globe', 'activo' => true]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->recepcion($temporada, $cliente))
            ->assertCreated()->json('data');
        DB::table('recepciones_romana')->where('id', $recepcion['id'])->update(['especie_validacion_id' => null]);
        $this->actingAs($validador, 'sanctum');
        $this->getJson("/api/validacion-mp/recepciones/{$recepcion['id']}/catalogos")
            ->assertOk()->assertJsonCount(0, 'variedades')->assertJsonCount(2, 'especies');
        $validacion = $this->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk()->json('data');
        $ruta = "/api/validacion-mp/recepciones/{$recepcion['id']}/especie";
        $payload = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion['version'],
            'especie_validacion_id' => $especie->id];
        $this->postJson($ruta, [...$payload, 'especie_validacion_id' => $otra->id,
            'version_conocida' => $recepcion['version'] - 1])->assertConflict();
        $this->postJson($ruta, $payload)->assertOk()->assertJsonPath('data.especie_validacion_id', $especie->id);
        $this->postJson($ruta, $payload)->assertOk();
        $this->getJson("/api/validacion-mp/recepciones/{$recepcion['id']}/catalogos")
            ->assertOk()->assertJsonCount(1, 'variedades')->assertJsonPath('variedades.0.id', $variedad->id);
        $this->assertDatabaseHas('eventos_recepcion_romana', [
            'recepcion_romana_id' => $recepcion['id'], 'tipo' => 'especie_completada', 'user_id' => $validador->id,
        ]);
        $this->assertDatabaseHas('validaciones_mp', ['id' => $validacion['id'], 'estado' => 'en_curso']);
    }

    public function test_segregacion_rechaza_un_segmento_sin_envase_contenedor(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->recepcion($temporada, $cliente))
            ->assertCreated()->json('data');
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-CONTENEDOR', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $recepcion['especie_validacion_id'], 'nombre' => 'Santina', 'activo' => true]);
        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", [
                'operacion_id' => (string) Str::uuid(),
            ])->assertOk()->json('data');
        $this->postJson("/api/validacion-mp/validaciones/{$validacion['id']}/confirmar", [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 48], ['tipo_envase' => 'totes', 'cantidad_validada' => 10]],
            'tarjas_verificadas' => true, 'requiere_segregacion' => true,
            'segmentos' => [
                ['motivos' => ['cuartel'], 'cuartel' => 'A', 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                    'envases' => [['tipo_envase' => 'bins', 'cantidad' => 48], ['tipo_envase' => 'totes', 'cantidad' => 0]]],
                ['motivos' => ['cuartel'], 'cuartel' => 'B', 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
                    'envases' => [['tipo_envase' => 'bins', 'cantidad' => 0], ['tipo_envase' => 'totes', 'cantidad' => 10]]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('segmentos.1.envases');
        $this->assertDatabaseCount('segmentos_validacion_mp', 0);
    }

    public function test_solo_ofrece_y_acepta_csg_habilitados_para_el_cliente_de_romana(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $clienteAutorizado = $this->cliente();
        $clienteRecepcion = Cliente::create([
            'codigo' => 'CLI-OTRO',
            'nombre' => 'Otro cliente',
            'activo' => true,
        ]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $productor = ProductorCsg::create([
            'codigo' => 'CSG-CLIENTE-01',
            'razon_social' => 'Productor cliente autorizado',
            'predio' => 'Predio autorizado',
            'estado_sag' => 'activo',
            'tipo_codigo' => 'CSG',
            'fuente_url' => 'https://sag.example.test',
            'primera_verificacion_at' => now(),
            'ultima_verificacion_at' => now(),
            'ultima_consulta_user_id' => $operador->id,
            'respuesta_hash' => hash('sha256', 'csg-cliente-01'),
        ]);
        $csg = CsgValidacion::create([
            'productor_csg_id' => $productor->id,
            'temporada_id' => $temporada->id,
            'codigo' => $productor->codigo,
            'predio' => $productor->predio,
            'activo' => true,
        ]);
        DB::table('clientes_productores_csg')->insert([
            'id' => (string) Str::uuid(),
            'cliente_id' => $clienteAutorizado->id,
            'productor_csg_id' => $productor->id,
            'activo' => true,
            'asociado_por_user_id' => $operador->id,
            'actualizado_por_user_id' => $operador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->recepcion($temporada, $clienteRecepcion))
            ->assertCreated()
            ->json('data');

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/catalogos')
            ->assertOk()
            ->assertJsonCount(0, 'csg');
        $validacion = $this->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk()->json('data');

        $variedad = VariedadValidacion::create([
            'especie_validacion_id' => $recepcion['especie_validacion_id'],
            'nombre' => 'Santina', 'activo' => true,
        ]);

        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad_validada' => 48],
                ['tipo_envase' => 'totes', 'cantidad_validada' => 10],
            ],
            'tarjas_verificadas' => true,
            'requiere_segregacion' => false,
            'csg_validacion_id' => $csg->id,
            'variedad_validacion_id' => $variedad->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['segmentos']);
    }

    public function test_toma_recepcion_por_correlativo_y_confirma_diferencias_y_segregacion_sin_crear_folios(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-21 10:10:00'));
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $otroValidador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->recepcion($temporada, $cliente))
            ->assertCreated()
            ->json('data');

        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina', 'activo' => true]);
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-001', 'activo' => true]);

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion-mp/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.numero_recepcion', 'REC-2607-0001')
            ->assertJsonPath('data.0.numero_guia_despacho', 'GD-MP-001')
            ->assertJsonPath('data.0.envases.1.tipo_envase', 'totes');

        $validacion = $this->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.estado', 'en_curso')->json('data');

        $this->actingAs($otroValidador, 'sanctum')
            ->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'La recepción ya fue tomada por otro validador MP.');

        $this->actingAs($validador, 'sanctum');
        $operacionConfirmacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionConfirmacion,
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad_validada' => 45],
                ['tipo_envase' => 'totes', 'cantidad_validada' => 10],
            ],
            'tarjas_verificadas' => true,
            'requiere_segregacion' => true,
            'segmentos' => [
                [
                    'motivos' => ['csg'],
                    'csg_validacion_id' => $csg->id,
                    'variedad_validacion_id' => $variedad->id,
                    'envases' => [
                        ['tipo_envase' => 'bins', 'cantidad' => 20],
                        ['tipo_envase' => 'totes', 'cantidad' => 4],
                    ],
                ],
                [
                    'motivos' => ['cuartel', 'variedad'],
                    'csg_validacion_id' => $csg->id,
                    'cuartel' => 'C-12',
                    'variedad_validacion_id' => $variedad->id,
                    'envases' => [
                        ['tipo_envase' => 'bins', 'cantidad' => 25],
                        ['tipo_envase' => 'totes', 'cantidad' => 6],
                    ],
                ],
            ],
        ];
        $otraEspecie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Uva', 'activo' => true]);
        $variedadAjena = VariedadValidacion::create(['especie_validacion_id' => $otraEspecie->id, 'nombre' => 'Red Globe', 'activo' => true]);
        $invalido = $payload;
        $invalido['operacion_id'] = (string) Str::uuid();
        $invalido['segmentos'][0]['variedad_validacion_id'] = $variedadAjena->id;
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', $invalido)
            ->assertUnprocessable()->assertJsonValidationErrors('segmentos');
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', $payload)
            ->assertOk()
            ->assertJsonPath('data.estado', 'validada')
            ->assertJsonPath('data.requiere_segregacion', true)
            ->assertJsonPath('data.segmentos.0.estado', 'pendiente_lote')
            ->assertJsonPath('data.segmentos.1.cuartel', 'C-12')
            ->assertJsonCount(2, 'data.segmentos');
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', $payload)->assertOk();
        $this->getJson('/api/validacion-mp/historico?fecha=2026-07-21')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cliente', $cliente->nombre)
            ->assertJsonPath('data.0.segmentos.0.csg', $csg->codigo)
            ->assertJsonPath('data.0.segmentos.0.variedad', $variedad->nombre);
        $this->getJson('/api/validacion-mp/historico?fecha=2026-07-20')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $recepcion['id'],
            'tipo_envase' => 'bins',
            'cantidad_declarada' => 48,
            'cantidad_validada' => 45,
        ]);
        $this->assertDatabaseHas('movimientos_envases', [
            'recepcion_romana_id' => $recepcion['id'],
            'tipo_envase' => 'bins',
            'cantidad' => 45,
            'signo_cuenta' => 1,
            'signo_existencia' => 1,
            'propiedad' => 'cliente',
        ]);
        $this->assertDatabaseCount('movimientos_envases', 2);
        $this->assertDatabaseCount('segmentos_validacion_mp', 2);
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('validaciones_pallet', 0);

        $this->getJson('/api/envases/cuenta-corriente/movimientos')
            ->assertForbidden();
        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/envases/cuenta-corriente/movimientos')
            ->assertOk()
            ->assertJsonPath('resumen.lineas_pendientes_validacion', 0)
            ->assertJsonPath('data.0.ingreso_at', '2026-07-21T10:10:00+00:00');
    }

    public function test_recepcion_de_compra_solo_envases_no_exige_tarjas_y_no_afecta_cuenta_del_cliente(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $datos = $this->recepcion($temporada, $cliente);
        $datos['tipo_recepcion'] = 'solo_envases';
        $datos['fecha_ingreso'] = now(config('app.operational_timezone'))->toDateString();
        $datos['concepto_envases'] = 'compra';
        $datos['tipo_servicio'] = null;
        $datos['envases'] = [['tipo_envase' => 'esponjas', 'cantidad' => 500]];
        $recepcion = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');

        $this->actingAs($validador, 'sanctum');
        $validacion = $this->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk()->json('data');
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'esponjas', 'cantidad_validada' => 498]],
        ])->assertOk()->assertJsonPath('data.tarjas_verificadas', null)->assertJsonCount(0, 'data.segmentos');

        $this->assertDatabaseHas('movimientos_envases', [
            'recepcion_romana_id' => $recepcion['id'],
            'tipo_movimiento' => 'recepcion_compra',
            'cantidad' => 498,
            'signo_cuenta' => 0,
            'signo_existencia' => 1,
            'propiedad' => 'propia',
        ]);
    }

    public function test_rechaza_envases_ajenos_o_duplicados_dentro_de_una_segregacion(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->recepcion($temporada, $cliente))
            ->assertCreated()
            ->json('data');
        $this->actingAs($validador, 'sanctum');
        $validacion = $this->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk()->json('data');

        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-ENV', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $recepcion['especie_validacion_id'], 'nombre' => 'Santina', 'activo' => true]);
        $base = [
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad_validada' => 48],
                ['tipo_envase' => 'totes', 'cantidad_validada' => 10],
            ],
            'tarjas_verificadas' => true,
            'requiere_segregacion' => true,
        ];
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [
            ...$base,
            'operacion_id' => (string) Str::uuid(),
            'segmentos' => [
                ['motivos' => ['cuartel'], 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id, 'cuartel' => 'A', 'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad' => 20],
                    ['tipo_envase' => 'esponjas', 'cantidad' => 1],
                ]],
                ['motivos' => ['cuartel'], 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id, 'cuartel' => 'B', 'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad' => 28],
                    ['tipo_envase' => 'totes', 'cantidad' => 10],
                ]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['segmentos.0.envases']);

        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [
            ...$base,
            'operacion_id' => (string) Str::uuid(),
            'segmentos' => [
                ['motivos' => ['cuartel'], 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id, 'cuartel' => 'A', 'envases' => [
                    ['tipo_envase' => 'bins', 'cantidad' => 20],
                    ['tipo_envase' => 'bins', 'cantidad' => 28],
                    ['tipo_envase' => 'totes', 'cantidad' => 5],
                ]],
                ['motivos' => ['cuartel'], 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id, 'cuartel' => 'B', 'envases' => [
                    ['tipo_envase' => 'totes', 'cantidad' => 5],
                ]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['segmentos.0.envases']);
        $this->assertDatabaseCount('movimientos_envases', 0);
        $this->assertDatabaseCount('segmentos_validacion_mp', 0);
    }

    public function test_cuenta_corriente_muestra_la_temporada_activa_y_permite_historial_explicito(): void
    {
        $temporadaAnterior = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $movimientoAnterior = MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporadaAnterior->id,
            'cliente_id' => $cliente->id,
            'documento_tipo' => 'recepcion_romana',
            'numero_documento' => 'REC-HIST-01',
            'tipo_movimiento' => 'recepcion_fruta',
            'tipo_envase' => 'bins',
            'cantidad' => 15,
            'signo_cuenta' => 1,
            'signo_existencia' => 1,
            'propiedad' => 'cliente',
            'ocurrido_at' => now(),
            'ingreso_at' => now(),
            'estado_revision' => 'pendiente',
            'creado_por_user_id' => $operador->id,
        ]);
        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'CTA-NUEVA',
            'nombre' => 'Temporada nueva de cuenta corriente',
            'activa' => true,
        ], usuarioId: $operador->id);

        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/envases/cuenta-corriente/movimientos')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'balances');
        $this->getJson("/api/envases/cuenta-corriente/movimientos?temporada_id={$temporadaAnterior->id}")
            ->assertOk()
            ->assertJsonPath('data.0.numero_documento', 'REC-HIST-01')
            ->assertJsonPath('balances.0.saldo', 15);
        $this->postJson('/api/envases/cuenta-corriente/movimientos/'.$movimientoAnterior->id.'/revisar', [
            'estado' => 'revisado',
        ])->assertConflict()->assertJsonPath('codigo', 'temporada_no_activa');
    }

    public function test_validacion_mp_oculta_y_rechaza_recepciones_de_temporadas_anteriores(): void
    {
        $temporadaAnterior = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->recepcion($temporadaAnterior, $cliente))
            ->assertCreated()
            ->json('data');
        $validacion = $this->actingAs($validador, 'sanctum')
            ->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
                'operacion_id' => (string) Str::uuid(),
            ])
            ->assertOk()
            ->json('data');

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'VAL-MP-NUEVA',
            'nombre' => 'Temporada nueva de Validación MP',
            'activa' => true,
        ], usuarioId: $operador->id);

        $this->getJson('/api/validacion-mp/pendientes')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/validacion-mp/recepciones/buscar/'.$recepcion['numero_recepcion'])->assertNotFound();
        $this->getJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/catalogos')->assertNotFound();
        // Las consultas siguen respondiendo 404; las escrituras, 409 con el control central.
        $this->postJson('/api/validacion-mp/recepciones/'.$recepcion['id'].'/tomar', [
            'operacion_id' => (string) Str::uuid(),
        ])->assertConflict()->assertJsonPath('codigo', 'temporada_no_activa');
        $this->postJson('/api/validacion-mp/validaciones/'.$validacion['id'].'/confirmar', [])
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa');
    }

    public function test_pesaje_acumulativo_mantiene_la_recepcion_visible_y_bloquea_confirmacion_hasta_cerrar_romana(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = $this->cliente();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $datos = $this->recepcion($temporada, $cliente);
        $datos['tipo_recepcion'] = 'fruta_pesaje_envases';
        $datos['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 2]];
        $datos['tipo_envase_pesaje'] = 'bins';
        $datos['tara_unitaria_envase'] = 50;
        unset($datos['peso_bruto']);

        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data');

        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/validacion-mp/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.numero_recepcion', $recepcion['numero_recepcion'])
            ->assertJsonPath('data.0.estado_romana', 'en_pesaje_envases')
            ->assertJsonPath('data.0.pesaje_envases.cantidad_pesada', 0);
        $validacion = $this->postJson(
            "/api/validacion-mp/recepciones/{$recepcion['id']}/tomar",
            ['operacion_id' => (string) Str::uuid()],
        )->assertOk()->json('data');
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-PESAJE', 'activo' => true]);
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $recepcion['especie_validacion_id'], 'nombre' => 'Santina', 'activo' => true]);
        $confirmacion = [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 2]],
            'tarjas_verificadas' => true,
            'requiere_segregacion' => false,
            'csg_validacion_id' => $csg->id,
            'variedad_validacion_id' => $variedad->id,
        ];
        $this->postJson(
            "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar",
            $confirmacion,
        )
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'Romana debe completar y cerrar el pesaje acumulativo antes de confirmar Validación MP.',
            );

        $this->actingAs($operador, 'sanctum')
            ->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
                'operacion_id' => (string) Str::uuid(),
                'cantidad_envases' => 2,
                'peso_bruto' => 900,
            ])
            ->assertOk()
            ->assertJsonPath('data.peso_tara', 100)
            ->assertJsonPath('data.peso_neto', 800);
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();

        $confirmacion['operacion_id'] = (string) Str::uuid();
        $this->actingAs($validador, 'sanctum')
            ->postJson(
                "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar",
                $confirmacion,
            )
            ->assertOk()
            ->assertJsonPath('data.estado', 'validada')
            ->assertJsonPath('data.tarjas_verificadas', true);
        $this->assertDatabaseHas('movimientos_envases', [
            'recepcion_romana_id' => $recepcion['id'],
            'tipo_movimiento' => 'recepcion_fruta',
            'tipo_envase' => 'bins',
            'cantidad' => 2,
            'signo_cuenta' => 1,
            'propiedad' => 'cliente',
        ]);
    }

    private function cliente(): Cliente
    {
        return Cliente::create(['codigo' => 'CLI-MP', 'nombre' => 'Cliente MP', 'activo' => true]);
    }

    /** @return array<string, mixed> */
    private function recepcion(Temporada $temporada, Cliente $cliente): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'fruta_con_envases',
            'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true])->id,
            'tipo_servicio' => 'proceso',
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 48],
                ['tipo_envase' => 'totes', 'cantidad' => 10],
            ],
            'numero_guia_despacho' => 'GD-MP-001',
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor MP',
            'peso_bruto' => 28000,
        ];
    }
}
