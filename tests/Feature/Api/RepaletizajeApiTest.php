<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\Repaletizaje;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Validacion\ServicioEtiquetasPt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepaletizajeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cambia_un_pallet_o_saldo_a_un_folio_nuevo_sin_alterar_su_composicion(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio($temporada, 'SAL-CAMBIO', 60);

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'modalidad' => 'cambio_folio',
            'origenes' => [[
                'folio_id' => $origen->id,
                'cantidad_aportada' => 60,
            ]],
            'resultados' => [[
                'numero_folio' => 'SAL-CAMBIO-NUEVO',
                'tipo_resultado' => 'saldo',
                'cantidad_objetivo' => 120,
                'cantidad_resultante' => 60,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.modalidad', 'cambio_folio')
            ->assertJsonPath('data.resultados.0.folio.numero_folio', 'SAL-CAMBIO-NUEVO')
            ->assertJsonPath('data.resultados.0.cantidad_resultante', 60);

        $this->assertDatabaseHas('folios', [
            'id' => $origen->id,
            'activo' => false,
            'estado_operacional' => 'agotado',
        ]);
        $nuevo = Folio::query()->where('numero_folio', 'SAL-CAMBIO-NUEVO')->firstOrFail();
        $this->assertSame(60, $nuevo->datos_externos['cantidad_cajas']);
        $this->assertSame('111', $nuevo->datos_externos['composicion'][0]['csg']);
    }

    public function test_cambio_folio_heredado_aprobado_queda_disponible_para_carga(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio(
            $temporada,
            'SAL-HEREDADO-PF',
            60,
            condicion: CondicionTermicaFolio::PrefrioAprobado,
            estado: EstadoOperacionalFolio::PendientePrefrio,
        );
        $camara = Camara::create([
            'codigo' => 'CAM-REPA-HEREDADO',
            'nombre' => 'Cámara repa heredado',
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        DB::table('ubicaciones_actuales')->insert([
            'id' => (string) Str::uuid(),
            'folio_id' => $origen->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
            'movimiento_id' => null,
            'ubicado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $respuesta = $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'modalidad' => 'cambio_folio',
            'origenes' => [[
                'folio_id' => $origen->id,
                'cantidad_aportada' => 60,
            ]],
            'resultados' => [[
                'numero_folio' => 'SAL-HEREDADO-PF-NUEVO',
                'tipo_resultado' => 'saldo',
                'cantidad_objetivo' => 120,
                'cantidad_resultante' => 60,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.folio_resultante.estado_operacional', 'disponible')
            ->assertJsonPath('data.folio_resultante.condicion_termica', 'prefrio_aprobado');

        $nuevo = Folio::query()
            ->where('numero_folio', 'SAL-HEREDADO-PF-NUEVO')
            ->firstOrFail();
        $this->assertSame(
            HabilitacionAlmacenamientoFolio::Habilitado,
            $nuevo->habilitacion_almacenamiento,
        );
        $this->assertDatabaseHas('ubicaciones_actuales', [
            'folio_id' => $nuevo->id,
            'posicion_id' => $posicion->id,
        ]);

        $despachador = User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);
        $cargaId = $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/cargas', [])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles?q=SAL-HEREDADO-PF-NUEVO')
            ->assertOk()
            ->assertJsonPath('data.0.numero_folio', $nuevo->numero_folio);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$cargaId}/folios", [
                'folios' => [$nuevo->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.total_folios', 1);
    }

    public function test_hotfix_corrige_la_secuencia_acumulada_de_migraciones_post_prefrio(): void
    {
        [, $temporada] = $this->contexto();
        $sinUbicacionValidacion = $this->folio(
            $temporada,
            'PAL-VALIDACION-PENDIENTE',
            40,
            condicion: CondicionTermicaFolio::PrefrioAprobado,
            estado: EstadoOperacionalFolio::PendientePrefrio,
            tipo: TipoBulto::Pallet,
        );
        $sinUbicacionRepa = $this->folio(
            $temporada,
            'SAL-REPA-PENDIENTE',
            40,
            condicion: CondicionTermicaFolio::PrefrioAprobado,
            estado: EstadoOperacionalFolio::PendientePrefrio,
        );
        $sinUbicacionRepa->update(['origen_sistema' => 'repaletizaje']);
        $ubicado = $this->folio(
            $temporada,
            'PAL-APROBADO-UBICADO',
            40,
            condicion: CondicionTermicaFolio::PrefrioAprobado,
            estado: EstadoOperacionalFolio::PendientePrefrio,
            tipo: TipoBulto::Pallet,
        );
        $pendienteReal = $this->folio(
            $temporada,
            'PAL-PREFRIO-PENDIENTE',
            40,
            condicion: CondicionTermicaFolio::PendientePrefrio,
            estado: EstadoOperacionalFolio::PendientePrefrio,
            tipo: TipoBulto::Pallet,
        );
        $camara = Camara::create([
            'codigo' => 'CAM-NORMALIZACION-PF',
            'nombre' => 'Cámara normalización post prefrío',
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        DB::table('ubicaciones_actuales')->insert([
            'id' => (string) Str::uuid(),
            'folio_id' => $ubicado->id,
            'camara_id' => $camara->id,
            'posicion_id' => $posicion->id,
            'movimiento_id' => null,
            'ubicado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migracionRepa = require database_path(
            'migrations/2026_08_18_120000_normalizar_estado_repas_aprobados.php',
        );
        $migracionRepa->up();
        $migracionPostPrefrio = require database_path(
            'migrations/2026_08_18_160000_normalizar_estado_post_prefrio.php',
        );
        $migracionPostPrefrio->up();

        $this->assertSame(
            EstadoOperacionalFolio::Disponible,
            $sinUbicacionRepa->refresh()->estado_operacional,
        );

        $hotfix = require database_path(
            'migrations/2026_08_20_120000_corregir_estados_post_prefrio_acumulados.php',
        );
        $hotfix->up();

        $this->assertSame(
            EstadoOperacionalFolio::PendienteUbicacion,
            $sinUbicacionValidacion->refresh()->estado_operacional,
        );
        $this->assertSame(
            EstadoOperacionalFolio::PendienteUbicacion,
            $sinUbicacionRepa->refresh()->estado_operacional,
        );
        $this->assertSame(
            EstadoOperacionalFolio::Disponible,
            $ubicado->refresh()->estado_operacional,
        );
        $this->assertSame(
            EstadoOperacionalFolio::PendientePrefrio,
            $pendienteReal->refresh()->estado_operacional,
        );
    }

    public function test_recupera_la_fecha_de_validacion_en_folios_historicos_y_la_conserva(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio($temporada, 'SAL-FECHA-HISTORICA', 83);
        $origen->update(['fecha_ingreso' => '2026-08-08 18:30:00']);

        $this->withToken($token)
            ->getJson('/api/validacion/repaletizajes/folios/SAL-FECHA-HISTORICA')
            ->assertOk()
            ->assertJsonPath('composicion.0.fecha_embalaje', '2026-08-08');

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'modalidad' => 'cambio_folio',
            'origenes' => [[
                'folio_id' => $origen->id,
                'cantidad_aportada' => 83,
            ]],
            'resultados' => [[
                'numero_folio' => 'SAL-FECHA-CONSERVADA',
                'tipo_resultado' => 'saldo',
                'cantidad_objetivo' => 120,
                'cantidad_resultante' => 83,
            ]],
        ])->assertOk()
            ->assertJsonPath(
                'data.resultados.0.folio.composicion.0.fecha_embalaje',
                '2026-08-08',
            );

        $resultado = Folio::query()
            ->where('numero_folio', 'SAL-FECHA-CONSERVADA')
            ->firstOrFail();
        $this->assertSame(
            '2026-08-08',
            $resultado->datos_externos['composicion'][0]['fecha_embalaje'],
        );
    }

    public function test_interfaz_division_balancea_salidas_y_envia_la_modalidad_definida(): void
    {
        $script = file_get_contents(resource_path('js/office-repalletizing.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString(
            'operacion_id: uuid(), modalidad: modality,',
            $script,
        );
        $this->assertStringNotContainsString(
            'operacion_id: uuid(), modalidad,',
            $script,
        );
        $this->assertStringContainsString(
            'line[`salida${otherOutput}`] = line.cantidad_cajas - value;',
            $script,
        );
        $this->assertStringContainsString(
            "...(modality === 'division' ? { composicion: composition } : {}),",
            $script,
        );
        $this->assertStringNotContainsString(
            'cantidad_resultante: quantity, composicion: composition,',
            $script,
        );
        $this->assertStringContainsString('class="division-line__outputs"', $script);
    }

    public function test_composicion_vacia_entrega_un_mensaje_de_validacion_legible(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio($temporada, 'SAL-CAMBIO-INVALIDO', 60);

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'modalidad' => 'cambio_folio',
            'origenes' => [[
                'folio_id' => $origen->id,
                'cantidad_aportada' => 60,
            ]],
            'resultados' => [[
                'numero_folio' => 'SAL-CAMBIO-INVALIDO-NUEVO',
                'tipo_resultado' => 'saldo',
                'cantidad_objetivo' => 120,
                'cantidad_resultante' => 60,
                'composicion' => [],
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('resultados.0.composicion')
            ->assertJson([
                'errors' => [
                    'resultados.0.composicion' => [
                        'La composición de cada resultado debe incluir al menos una línea.',
                    ],
                ],
            ]);
    }

    public function test_divide_un_folio_en_dos_y_anular_restaura_el_origen_completo(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio($temporada, 'PAL-DIVIDIR', 60, tipo: TipoBulto::Pallet);
        $datos = $origen->datos_externos;
        $datos['composicion'] = [
            ['csg' => '111', 'predio' => 'Predio', 'fecha_embalaje' => '2026-08-10', 'cantidad_cajas' => 30],
            ['csg' => '222', 'predio' => 'Predio', 'fecha_embalaje' => '2026-08-11', 'cantidad_cajas' => 30],
        ];
        $origen->update(['datos_externos' => $datos]);
        $lineas = collect($this->withToken($token)
            ->getJson('/api/validacion/repaletizajes/folios/PAL-DIVIDIR')
            ->assertOk()->json('composicion'));

        $respuesta = $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'modalidad' => 'division',
            'origenes' => [[
                'folio_id' => $origen->id,
                'cantidad_aportada' => 60,
            ]],
            'resultados' => [
                [
                    'numero_folio' => 'SAL-DIV-A',
                    'tipo_resultado' => 'saldo',
                    'cantidad_objetivo' => 120,
                    'cantidad_resultante' => 30,
                    'composicion' => $lineas->map(fn (array $linea): array => [
                        'clave' => $linea['clave'],
                        'cantidad_cajas' => $linea['csg'] === '111' ? 20 : 10,
                    ])->all(),
                ],
                [
                    'numero_folio' => 'SAL-DIV-B',
                    'tipo_resultado' => 'saldo',
                    'cantidad_objetivo' => 120,
                    'cantidad_resultante' => 30,
                    'composicion' => $lineas->map(fn (array $linea): array => [
                        'clave' => $linea['clave'],
                        'cantidad_cajas' => $linea['csg'] === '111' ? 10 : 20,
                    ])->all(),
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.modalidad', 'division')
            ->assertJsonCount(2, 'data.resultados');

        $repaId = $respuesta->json('data.id');
        $this->assertDatabaseCount('repaletizaje_resultados', 2);
        $this->assertSame(30, Folio::query()->where('numero_folio', 'SAL-DIV-A')->firstOrFail()->datos_externos['cantidad_cajas']);

        $supervisorToken = $this->token(RolUsuario::SupervisorFrio, 'SUP-DIVISION');
        $this->conToken($supervisorToken)->postJson("/api/validacion/repaletizajes/{$repaId}/anular", [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'División registrada por error.',
        ])->assertOk()->assertJsonPath('data.estado', 'anulado');

        $origen->refresh();
        $this->assertTrue($origen->activo);
        $this->assertSame(60, $origen->datos_externos['cantidad_cajas']);
        $this->assertFalse(Folio::query()->where('numero_folio', 'SAL-DIV-A')->firstOrFail()->activo);
        $this->assertFalse(Folio::query()->where('numero_folio', 'SAL-DIV-B')->firstOrFail()->activo);
    }

    public function test_la_division_conserva_lote_y_proceso_de_packing_en_los_resultados(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio($temporada, 'PAL-DIV-LOTES', 60, tipo: TipoBulto::Pallet);
        $datos = $origen->datos_externos;
        // Mismo CSG y fecha, dos lotes: no deben fusionarse en una sola línea.
        $datos['composicion'] = [
            ['csg' => '111', 'predio' => 'Predio', 'fecha_embalaje' => '2026-08-10', 'cantidad_cajas' => 40, 'lote_materia_prima' => 'L-10', 'proceso_packing' => 'P-5'],
            ['csg' => '111', 'predio' => 'Predio', 'fecha_embalaje' => '2026-08-10', 'cantidad_cajas' => 20, 'lote_materia_prima' => 'L-11', 'proceso_packing' => 'P-5'],
        ];
        $origen->update(['datos_externos' => $datos]);
        $lineas = collect($this->withToken($token)
            ->getJson('/api/validacion/repaletizajes/folios/PAL-DIV-LOTES')
            ->assertOk()->json('composicion'));
        $this->assertCount(2, $lineas);

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'modalidad' => 'division',
            'origenes' => [['folio_id' => $origen->id, 'cantidad_aportada' => 60]],
            'resultados' => [
                [
                    'numero_folio' => 'SAL-LOTE-A',
                    'tipo_resultado' => 'saldo',
                    'cantidad_objetivo' => 120,
                    'cantidad_resultante' => 30,
                    'composicion' => $lineas->map(fn (array $linea): array => [
                        'clave' => $linea['clave'],
                        'cantidad_cajas' => $linea['lote_materia_prima'] === 'L-10' ? 20 : 10,
                    ])->all(),
                ],
                [
                    'numero_folio' => 'SAL-LOTE-B',
                    'tipo_resultado' => 'saldo',
                    'cantidad_objetivo' => 120,
                    'cantidad_resultante' => 30,
                    'composicion' => $lineas->map(fn (array $linea): array => [
                        'clave' => $linea['clave'],
                        'cantidad_cajas' => $linea['lote_materia_prima'] === 'L-10' ? 20 : 10,
                    ])->all(),
                ],
            ],
        ])->assertOk();

        $resultado = Folio::query()->where('numero_folio', 'SAL-LOTE-A')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['L-10' => 20, 'L-11' => 10],
            collect($resultado->datos_externos['composicion'])->pluck('cantidad_cajas', 'lote_materia_prima')->all(),
        );
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'folio_id' => $resultado->id,
            'numero_lote_materia_prima' => 'L-11',
            'numero_proceso_packing' => 'P-5',
            'cantidad_cajas' => 10,
        ]);
    }

    public function test_distribuye_un_saldo_mixto_por_csg_y_fecha_sin_perder_el_residual(): void
    {
        [$token, $temporada] = $this->contexto();
        $mixto = $this->folio($temporada, 'SAL-MIXTO', 60, csg: 'MIX');
        $datos = $mixto->datos_externos;
        $datos['composicion'] = [
            ['csg' => '111', 'predio' => 'Predio A', 'fecha_embalaje' => '2026-08-10', 'cantidad_cajas' => 30],
            ['csg' => '111', 'predio' => 'Predio A', 'fecha_embalaje' => '2026-08-11', 'cantidad_cajas' => 30],
        ];
        $mixto->update(['datos_externos' => $datos]);
        $simple = $this->folio($temporada, 'SAL-SIMPLE', 40, csg: '222');

        $composicion = $this->withToken($token)
            ->getJson('/api/validacion/repaletizajes/folios/SAL-MIXTO')
            ->assertOk()
            ->json('composicion');

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'nuevo',
            'numero_folio_resultante' => 'SAL-SIN-DISTRIBUCION',
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $mixto->id, 'cantidad_aportada' => 40],
                ['folio_id' => $simple->id, 'cantidad_aportada' => 40],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'El folio SAL-MIXTO posee más de un CSG o fecha. Indica cuántas cajas aporta cada composición.',
            );

        $respuesta = $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'nuevo',
            'numero_folio_resultante' => 'SAL-RESULTADO-MIX',
            'cantidad_objetivo' => 120,
            'origenes' => [
                [
                    'folio_id' => $mixto->id,
                    'cantidad_aportada' => 40,
                    'composicion' => collect($composicion)->map(fn (array $linea): array => [
                        'clave' => $linea['clave'],
                        'cantidad_aportada' => 20,
                    ])->all(),
                ],
                ['folio_id' => $simple->id, 'cantidad_aportada' => 40],
            ],
        ])->assertOk()
            ->assertJsonPath('data.folio_resultante.csg', 'MIX')
            ->assertJsonCount(3, 'data.folio_resultante.composicion');

        $residual = Folio::query()->findOrFail($mixto->id)->datos_externos;
        $this->assertSame(20, $residual['cantidad_cajas']);
        $this->assertCount(2, $residual['composicion']);
        $this->assertSame([10, 10], collect($residual['composicion'])->pluck('cantidad_cajas')->all());
        $this->assertContains('fecha_embalaje', $respuesta->json('data.campos_mix'));
    }

    public function test_crea_pallet_nuevo_con_mix_y_conserva_saldo_residual(): void
    {
        [$token, $temporada] = $this->contexto();
        $primero = $this->folio($temporada, 'SAL-001', 90, calibre: '2J', csg: '111');
        $segundo = $this->folio($temporada, 'SAL-002', 40, calibre: '3J', csg: '222');

        $respuesta = $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => 'pallet',
            'estrategia_folio' => 'nuevo',
            'numero_folio_resultante' => 'PAL-900',
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $primero->id, 'cantidad_aportada' => 90],
                ['folio_id' => $segundo->id, 'cantidad_aportada' => 30],
            ],
        ])->assertOk()
            ->assertJsonPath('data.folio_resultante.numero_folio', 'PAL-900')
            ->assertJsonPath('data.folio_resultante.cantidad_cajas', 120)
            ->assertJsonPath('data.folio_resultante.tipo_bulto', 'pallet')
            ->assertJsonPath('data.folio_resultante.calibre', 'MIX')
            ->assertJsonPath('data.folio_resultante.csg', 'MIX');

        $this->assertDatabaseHas('folios', [
            'id' => $primero->id,
            'activo' => false,
            'estado_operacional' => 'agotado',
        ]);
        $this->assertSame(
            10,
            (int) Folio::query()->findOrFail($segundo->id)->datos_externos['cantidad_cajas'],
        );
        $this->assertDatabaseCount('repaletizaje_detalles', 2);
        $this->assertContains('calibre', $respuesta->json('data.campos_mix'));
        $this->assertContains('csg', $respuesta->json('data.campos_mix'));
    }

    public function test_consolida_saldo_post_prefrio_sin_ubicacion_como_pendiente_ubicacion(): void
    {
        [$token, $temporada] = $this->contexto();
        $primero = $this->folio(
            $temporada,
            'SAL-FRIO-1',
            30,
            condicion: CondicionTermicaFolio::PrefrioAprobado,
            estado: EstadoOperacionalFolio::Disponible,
        );
        $segundo = $this->folio(
            $temporada,
            'SAL-FRIO-2',
            25,
            condicion: CondicionTermicaFolio::PrefrioAprobado,
            estado: EstadoOperacionalFolio::Disponible,
        );

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'conservar',
            'numero_folio_resultante' => 'SAL-FRIO-1',
            'folio_conservado_id' => $primero->id,
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $primero->id, 'cantidad_aportada' => 30],
                ['folio_id' => $segundo->id, 'cantidad_aportada' => 25],
            ],
        ])->assertOk()
            ->assertJsonPath('data.folio_resultante.numero_folio', 'SAL-FRIO-1')
            ->assertJsonPath('data.folio_resultante.cantidad_cajas', 55)
            ->assertJsonPath('data.folio_resultante.tipo_bulto', 'saldo')
            ->assertJsonPath('data.folio_resultante.estado_operacional', 'pendiente_ubicacion')
            ->assertJsonPath('data.folio_resultante.condicion_termica', 'prefrio_aprobado');
    }

    public function test_bloquea_clientes_diferentes(): void
    {
        $this->assertIncompatibilidad('cliente', ['cliente' => 'OTRO CLIENTE']);
    }

    public function test_bloquea_especies_diferentes(): void
    {
        $this->assertIncompatibilidad('especie', ['especie' => 'Kiwi']);
    }

    public function test_bloquea_marcas_diferentes(): void
    {
        $this->assertIncompatibilidad('marca', ['marca' => 'OTRA MARCA']);
    }

    public function test_bloquea_estados_termicos_diferentes(): void
    {
        $this->assertIncompatibilidad('estado térmico', [
            'condicion' => CondicionTermicaFolio::PrefrioAprobado,
            'estado' => EstadoOperacionalFolio::Disponible,
        ]);
    }

    public function test_es_idempotente_y_anulacion_restaura_los_folios(): void
    {
        [$token, $temporada] = $this->contexto();
        $primero = $this->folio($temporada, 'SAL-R1', 60);
        $segundo = $this->folio($temporada, 'SAL-R2', 70);
        $operacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacion,
            'turno' => 'A',
            'tipo_resultado' => 'pallet',
            'estrategia_folio' => 'conservar',
            'numero_folio_resultante' => 'SAL-R1',
            'folio_conservado_id' => $primero->id,
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $primero->id, 'cantidad_aportada' => 60],
                ['folio_id' => $segundo->id, 'cantidad_aportada' => 60],
            ],
        ];

        $id = $this->withToken($token)
            ->postJson('/api/validacion/repaletizajes', $payload)
            ->assertOk()
            ->json('data.id');
        $this->withToken($token)
            ->postJson('/api/validacion/repaletizajes', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('repaletizajes', 1);

        $supervisorToken = $this->token(RolUsuario::SupervisorFrio, 'SUP-REPA');
        $this->conToken($supervisorToken)->postJson(
            "/api/validacion/repaletizajes/{$id}/anular",
            [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Error operacional confirmado.',
            ],
        )->assertOk()->assertJsonPath('data.estado', 'anulado');

        $this->assertSame(
            60,
            (int) Folio::query()->findOrFail($primero->id)->datos_externos['cantidad_cajas'],
        );
        $this->assertSame(
            70,
            (int) Folio::query()->findOrFail($segundo->id)->datos_externos['cantidad_cajas'],
        );
        $this->assertSame(
            'saldo',
            Folio::query()->findOrFail($primero->id)->tipo_bulto->value,
        );
    }

    public function test_historial_y_busqueda_de_repaletizajes_solo_exponen_la_temporada_activa(): void
    {
        [$token, $temporada] = $this->contexto();
        $temporadaAnterior = Temporada::create([
            'codigo' => '2025-2026-HIST-REPA',
            'nombre' => 'Temporada histórica repaletizaje',
            'activa' => false,
            'version_catalogo' => 1,
        ]);
        $usuario = User::factory()->create(['rol' => RolUsuario::Validador]);
        $vigente = $this->folio(
            $temporada,
            'PAL-REPA-VIGENTE',
            120,
            tipo: TipoBulto::Pallet,
        );
        $historico = $this->folio(
            $temporadaAnterior,
            'PAL-REPA-HISTORICO',
            120,
            tipo: TipoBulto::Pallet,
        );

        Repaletizaje::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'repa-vigente'),
            'codigo' => 'REPA-2026-900001',
            'modalidad' => 'consolidacion',
            'tipo_resultado' => 'pallet',
            'estrategia_folio' => 'nuevo',
            'folio_resultante_id' => $vigente->id,
            'folio_conservado_id' => null,
            'cantidad_objetivo' => 120,
            'cantidad_resultante' => 120,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio->value,
            'campos_mix' => [],
            'snapshot' => [],
            'estado' => 'confirmado',
            'observacion' => null,
            'user_id' => $usuario->id,
            'dispositivo_id' => null,
            'confirmado_at' => now(),
        ]);
        Repaletizaje::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'repa-historico'),
            'codigo' => 'REPA-2025-900001',
            'modalidad' => 'consolidacion',
            'tipo_resultado' => 'pallet',
            'estrategia_folio' => 'nuevo',
            'folio_resultante_id' => $historico->id,
            'folio_conservado_id' => null,
            'cantidad_objetivo' => 120,
            'cantidad_resultante' => 120,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio->value,
            'campos_mix' => [],
            'snapshot' => [],
            'estado' => 'confirmado',
            'observacion' => null,
            'user_id' => $usuario->id,
            'dispositivo_id' => null,
            'confirmado_at' => now()->subDay(),
        ]);

        $this->withToken($token)
            ->getJson('/api/validacion/repaletizajes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.codigo', 'REPA-2026-900001')
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->getJson('/api/validacion/repaletizajes?folio=PAL-REPA-HISTORICO')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_rechaza_folios_que_no_pertenecen_a_la_temporada_activa(): void
    {
        [$token, $temporada] = $this->contexto();
        $temporadaAnterior = Temporada::create([
            'codigo' => '2025-2026',
            'nombre' => 'Temporada anterior',
            'activa' => false,
            'version_catalogo' => 1,
        ]);
        $vigente = $this->folio($temporada, 'SAL-VIGENTE', 30);
        $historico = $this->folio($temporadaAnterior, 'SAL-HISTORICO', 30);

        $this->withToken($token)
            ->getJson("/api/validacion/repaletizajes/folios/{$historico->numero_folio}")
            ->assertOk()
            ->assertJsonPath('existe', false);

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'nuevo',
            'numero_folio_resultante' => 'SAL-CRUZADO',
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $vigente->id, 'cantidad_aportada' => 30],
                ['folio_id' => $historico->id, 'cantidad_aportada' => 30],
            ],
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'El folio SAL-HISTORICO no pertenece a la temporada activa.',
            );

        $this->assertDatabaseMissing('folios', [
            'numero_folio' => 'SAL-CRUZADO',
        ]);
    }

    public function test_impide_anular_si_un_folio_participa_en_un_repaletizaje_posterior(): void
    {
        [$token, $temporada] = $this->contexto();
        $primero = $this->folio($temporada, 'SAL-CADENA-1', 20);
        $segundo = $this->folio($temporada, 'SAL-CADENA-2', 20);
        $tercero = $this->folio($temporada, 'SAL-CADENA-3', 20);

        $primeraRespuesta = $this->withToken($token)
            ->postJson('/api/validacion/repaletizajes', [
                'turno' => 'A',
                'operacion_id' => (string) Str::uuid(),
                'tipo_resultado' => 'saldo',
                'estrategia_folio' => 'nuevo',
                'numero_folio_resultante' => 'SAL-CADENA-A',
                'cantidad_objetivo' => 120,
                'origenes' => [
                    ['folio_id' => $primero->id, 'cantidad_aportada' => 20],
                    ['folio_id' => $segundo->id, 'cantidad_aportada' => 20],
                ],
            ])->assertOk();
        $primeraRepaId = $primeraRespuesta->json('data.id');
        $resultadoPrimeroId = $primeraRespuesta->json('data.folio_resultante.id');

        $this->withToken($token)
            ->postJson('/api/validacion/repaletizajes', [
                'turno' => 'A',
                'operacion_id' => (string) Str::uuid(),
                'tipo_resultado' => 'saldo',
                'estrategia_folio' => 'nuevo',
                'numero_folio_resultante' => 'SAL-CADENA-B',
                'cantidad_objetivo' => 120,
                'origenes' => [
                    ['folio_id' => $resultadoPrimeroId, 'cantidad_aportada' => 40],
                    ['folio_id' => $tercero->id, 'cantidad_aportada' => 20],
                ],
            ])->assertOk();

        $supervisorToken = $this->token(RolUsuario::SupervisorFrio, 'SUP-CADENA');
        $this->conToken($supervisorToken)
            ->postJson("/api/validacion/repaletizajes/{$primeraRepaId}/anular", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Intento de revertir una genealogía ya utilizada.',
            ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'No se puede anular porque uno de sus folios ya participa en un repaletizaje posterior.',
            );

        $this->assertDatabaseHas('repaletizajes', [
            'id' => $primeraRepaId,
            'estado' => 'confirmado',
        ]);
        $this->assertDatabaseHas('folios', [
            'id' => $primero->id,
            'activo' => false,
            'estado_operacional' => 'agotado',
        ]);
    }

    public function test_etiqueta_conservada_usa_composicion_actual_y_fecha_mas_antigua_en_toda_la_cadena(): void
    {
        [$token, $temporada] = $this->contexto();
        $primero = $this->folio($temporada, 'FECHA-06', 20);
        $segundo = $this->folio($temporada, 'FECHA-09', 30);
        $tercero = $this->folio($temporada, 'FECHA-04', 10);
        foreach ([[$primero, '2026-02-06'], [$segundo, '2026-02-09'], [$tercero, '2026-02-04']] as [$folio, $fecha]) {
            $folio->update(['datos_externos' => [...$folio->datos_externos, 'fecha_embalaje' => $fecha]]);
        }
        $segundo->update(['variedad' => 'Lapins']);
        $primeraRepa = $this->conToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A', 'operacion_id' => (string) Str::uuid(), 'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'conservar', 'numero_folio_resultante' => $primero->numero_folio,
            'folio_conservado_id' => $primero->id, 'cantidad_objetivo' => 120,
            'origenes' => [['folio_id' => $primero->id, 'cantidad_aportada' => 20], ['folio_id' => $segundo->id, 'cantidad_aportada' => 30]],
        ])->assertOk()->json('data.id');
        $primero->refresh();
        $this->assertSame('2026-02-06', $primero->fecha_proceso_pt->toDateString());
        $etiqueta = app(ServicioEtiquetasPt::class)->etiqueta($primero);
        $this->assertSame('repaletizaje', $etiqueta['origen']);
        $this->assertSame(50, $etiqueta['cantidad_cajas']);
        $this->assertSame('MIXTA', $etiqueta['variedad']);
        $this->assertSame('2026-02-06', $etiqueta['fecha_proceso']);
        $this->travel(1)->seconds();
        $this->conToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A', 'operacion_id' => (string) Str::uuid(), 'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'nuevo', 'numero_folio_resultante' => 'CADENA-04', 'cantidad_objetivo' => 120,
            'origenes' => [['folio_id' => $primero->id, 'cantidad_aportada' => 50], ['folio_id' => $tercero->id, 'cantidad_aportada' => 10]],
        ])->assertOk();
        $nuevo = Folio::where('numero_folio', 'CADENA-04')->firstOrFail();
        $this->assertSame('2026-02-04', $nuevo->fecha_proceso_pt->toDateString());
        $this->assertSame('2026-02-04', app(ServicioEtiquetasPt::class)->etiqueta($nuevo)['fecha_proceso']);

        // Simula snapshots del esquema anterior: la primera repa no tenía fecha persistida.
        DB::table('repaletizaje_detalles')->where('repaletizaje_id', '!=', $primeraRepa)->get()->each(function ($detalle): void {
            $snapshot = json_decode($detalle->snapshot_antes, true);
            unset($snapshot['atributos']['fecha_proceso_pt']);
            DB::table('repaletizaje_detalles')->where('id', $detalle->id)->update(['snapshot_antes' => json_encode($snapshot)]);
        });
        DB::table('folios')->where('origen_sistema', 'repaletizaje')->update(['fecha_proceso_pt' => null]);
        $sinHistorial = $this->folio($temporada, 'REPA-SIN-HISTORIAL', 10);
        $sinHistorial->update(['origen_sistema' => 'repaletizaje']);
        $migracion = require database_path('migrations/2026_10_06_120000_etiquetas_pt_planta.php');
        $migracion->completarFechasHistoricas();
        $this->assertSame('2026-02-06', $primero->refresh()->fecha_proceso_pt->toDateString());
        $this->assertSame('2026-02-04', $nuevo->refresh()->fecha_proceso_pt->toDateString());
        $this->assertNull($sinHistorial->refresh()->fecha_proceso_pt);
    }

    public function test_cambio_folio_conserva_fecha_original_y_anulacion_restaura_fecha_anterior(): void
    {
        [$token, $temporada] = $this->contexto();
        $origen = $this->folio($temporada, 'UNICO-06', 60);
        $origen->update(['datos_externos' => [...$origen->datos_externos, 'fecha_embalaje' => '2026-02-06']]);
        $respuesta = $this->conToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A', 'operacion_id' => (string) Str::uuid(), 'modalidad' => 'cambio_folio',
            'origenes' => [['folio_id' => $origen->id, 'cantidad_aportada' => 60]],
            'resultados' => [['numero_folio' => 'UNICO-REPA', 'tipo_resultado' => 'saldo', 'cantidad_objetivo' => 120, 'cantidad_resultante' => 60]],
        ])->assertOk();
        $nuevo = Folio::where('numero_folio', 'UNICO-REPA')->firstOrFail();
        $this->assertSame('2026-02-06', $nuevo->fecha_proceso_pt->toDateString());
        $supervisor = $this->token(RolUsuario::SupervisorFrio, 'FECHA-ANULACION');
        $this->conToken($supervisor)->postJson('/api/validacion/repaletizajes/'.$respuesta->json('data.id').'/anular', [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Restaurar folio de origen y sus datos.',
        ])->assertOk();
        $this->assertNull($origen->refresh()->fecha_proceso_pt);
        $this->assertSame('2026-02-06', app(ServicioEtiquetasPt::class)->etiqueta($origen)['fecha_proceso']);
    }

    /** @param array<string, mixed> $cambio */
    private function assertIncompatibilidad(string $campo, array $cambio): void
    {
        [$token, $temporada] = $this->contexto();
        $primero = $this->folio($temporada, 'SAL-A-'.$campo, 20);
        $segundo = $this->folio(
            $temporada,
            'SAL-B-'.$campo,
            20,
            cliente: $cambio['cliente'] ?? 'CLIENTE',
            especie: $cambio['especie'] ?? 'Cereza',
            marca: $cambio['marca'] ?? 'MARCA',
            condicion: $cambio['condicion'] ?? CondicionTermicaFolio::PendientePrefrio,
            estado: $cambio['estado'] ?? EstadoOperacionalFolio::PendientePrefrio,
        );

        $this->withToken($token)->postJson('/api/validacion/repaletizajes', [
            'turno' => 'A',
            'operacion_id' => (string) Str::uuid(),
            'tipo_resultado' => 'saldo',
            'estrategia_folio' => 'nuevo',
            'numero_folio_resultante' => 'SAL-MIX-'.$campo,
            'cantidad_objetivo' => 120,
            'origenes' => [
                ['folio_id' => $primero->id, 'cantidad_aportada' => 20],
                ['folio_id' => $segundo->id, 'cantidad_aportada' => 20],
            ],
        ])->assertUnprocessable()
            ->assertJsonPath(
                'message',
                "No se puede mezclar diferente {$campo} en un repaletizaje.",
            );
    }

    /** @return array{string, Temporada} */
    private function contexto(): array
    {
        $temporada = Temporada::query()
            ->where('activa', true)
            ->firstOrFail();

        return [$this->token(RolUsuario::Validador, 'VAL-'.Str::random(6)), $temporada];
    }

    private function token(RolUsuario $rol, string $codigo): string
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $dispositivo = Dispositivo::create([
            'codigo' => $codigo,
            'nombre' => "PDA {$codigo}",
            'plataforma' => 'android',
            'activo' => true,
        ]);

        return $usuario
            ->crearTokenParaDispositivo($dispositivo, "test-{$codigo}")
            ->plainTextToken;
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function folio(
        Temporada $temporada,
        string $numero,
        int $cantidad,
        string $cliente = 'CLIENTE',
        string $especie = 'Cereza',
        string $marca = 'MARCA',
        string $calibre = '2J',
        string $csg = '111',
        CondicionTermicaFolio $condicion = CondicionTermicaFolio::PendientePrefrio,
        EstadoOperacionalFolio $estado = EstadoOperacionalFolio::PendientePrefrio,
        TipoBulto $tipo = TipoBulto::Saldo,
    ): Folio {
        return Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => mb_strtoupper($numero),
            'tipo_bulto' => $tipo,
            'estado_operacional' => $estado,
            'condicion_termica' => $condicion,
            'habilitacion_almacenamiento' => $condicion === CondicionTermicaFolio::PrefrioAprobado
                ? HabilitacionAlmacenamientoFolio::Habilitado
                : HabilitacionAlmacenamientoFolio::NoHabilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'variedad' => 'Santina',
            'calibre' => $calibre,
            'marca' => $marca,
            'exportadora' => $cliente,
            'origen_sistema' => 'validacion',
            'identificador_externo' => (string) Str::uuid(),
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
            'datos_externos' => [
                'especie' => $especie,
                'categoria' => 'Exportación',
                'envase' => 'Caja 5 kg',
                'csg' => $csg,
                'predio' => 'Predio',
                'cuartel' => 'Cuartel',
                'cantidad_cajas' => $cantidad,
            ],
        ]);
    }
}
