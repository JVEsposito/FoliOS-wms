<?php

namespace Tests\Feature\Api;

use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\CorreccionValidacionPallet;
use App\Models\Folio;
use App\Models\TrazabilidadFolioOrigen;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionValidacion;
use Tests\TestCase;

class CorreccionValidacionPalletApiTest extends TestCase
{
    use PreparaCorreccionValidacion;
    use RefreshDatabase;

    public function test_administrador_corrige_validacion_y_folio_antes_de_prefrio(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0001'),
            )
            ->assertCreated()
            ->json('data.id');
        $operacionId = (string) Str::uuid();
        $correccion = $this->payloadCorreccion($catalogo, $operacionId);

        $this->conToken($this->tokenOficina($usuario))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $correccion,
            )
            ->assertOk()
            ->assertJsonPath('data.catalogo.articulo.envase', 'Cartón 10 kg')
            ->assertJsonPath('data.cantidad_cajas', 118)
            ->assertJsonPath('data.linea_proceso', 2)
            ->assertJsonPath('data.turno', 'B')
            ->assertJsonPath(
                'data.correcciones.0.motivo',
                'El embalaje y la cantidad se ingresaron incorrectamente.',
            );

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0001')->firstOrFail();
        $this->assertSame('Cartón 10 kg', $folio->datos_externos['envase']);
        $this->assertSame(118, $folio->datos_externos['cantidad_cajas']);
        $this->assertSame('Santina', $folio->variedad);
        $this->assertDatabaseHas('validaciones_pallet', [
            'id' => $validacionId,
            'articulo_validacion_id' => $catalogo['articulo_corregido_id'],
            'cantidad_cajas' => 118,
            'linea_proceso' => 2,
            'turno' => 'B',
        ]);
        $this->assertDatabaseHas('correcciones_validacion_pallet', [
            'operacion_id' => $operacionId,
            'validacion_pallet_id' => $validacionId,
            'folio_id' => $folio->id,
            'corregido_por_user_id' => $usuario->id,
        ]);
    }

    public function test_corrige_solo_el_numero_del_folio_y_conserva_la_identidad_y_el_historial(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $captura = $this->payloadValidacion($catalogo, '0000000067');
        $registro = $this->conToken($tokenPda)->postJson('/api/validacion/pallets', $captura)
            ->assertCreated()->json('data');
        $id = $registro['id'];
        $folioId = $registro['folio']['id'];
        $tokenOficina = $this->tokenOficina($usuario);
        $this->conToken($tokenOficina)->getJson("/api/validacion/pallets/{$id}/correccion")
            ->assertOk()->assertJsonPath('puede_cambiar_numero_folio', true);

        $datos = [
            ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
            ...collect($captura)->only(['tipo_bulto', 'cantidad_cajas', 'linea_proceso', 'turno', 'articulo_validacion_id'])->all(),
            'numero_folio' => ' 0000000076 ',
            'motivo_correccion' => 'Se invirtieron los dígitos de la etiqueta.',
        ];
        $ruta = "/api/validacion/pallets/{$id}/corregir";
        $this->conToken($tokenOficina)->putJson($ruta, $datos)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.numero_folio', '0000000076')
            ->assertJsonPath('data.folio.id', $folioId)
            ->assertJsonPath('data.folio.numero_folio', '0000000076')
            ->assertJsonPath('data.folio.estado_operacional', 'pendiente_prefrio')
            ->assertJsonPath('data.cantidad_cajas', 120)
            ->assertJsonPath('data.correcciones.0.numero_folio_anterior', '0000000067')
            ->assertJsonPath('data.correcciones.0.numero_folio_nuevo', '0000000076');
        $this->conToken($tokenOficina)->putJson($ruta, $datos)->assertOk();
        $this->assertDatabaseCount('correcciones_validacion_pallet', 1);
        $this->assertDatabaseMissing('folios', ['numero_folio' => '0000000067']);

        // La PDA puede reenviar la captura original sin duplicar el bulto corregido.
        $this->conToken($tokenPda)->postJson('/api/validacion/pallets', $captura)
            ->assertOk()->assertJsonPath('data.folio.id', $folioId)->assertJsonPath('data.numero_folio', '0000000076');
        $this->conToken($tokenPda)->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, '0000000067'))
            ->assertCreated()->assertJsonPath('data.numero_intento', 2);
    }

    public function test_rechaza_un_numero_ocupado_sin_modificar_ningun_folio(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $id = $this->conToken($tokenPda)->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PT-ORIGINAL'))
            ->assertCreated()->json('data.id');
        $this->conToken($tokenPda)->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PT-OCUPADO'))->assertCreated();
        $datos = [...$this->payloadCorreccion($catalogo, (string) Str::uuid()), 'numero_folio' => 'pt-ocupado'];
        $ruta = "/api/validacion/pallets/{$id}/corregir";
        $this->conToken($this->tokenOficina($usuario))->putJson($ruta, $datos)
            ->assertConflict()->assertJsonPath('message', 'El número de folio indicado ya está registrado o tiene una validación definitiva.');
        $this->assertDatabaseHas('validaciones_pallet', ['id' => $id, 'numero_folio' => 'PT-ORIGINAL']);
        $this->assertDatabaseHas('secuencias_validacion_folio', ['numero_folio' => 'PT-OCUPADO', 'ultimo_intento' => 1]);
        $this->assertDatabaseCount('folios', 2);
        $this->assertDatabaseCount('correcciones_validacion_pallet', 0);
    }

    public function test_la_correccion_es_idempotente_ante_reintentos(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0002'),
            )
            ->assertCreated()
            ->json('data.id');
        $payload = $this->payloadCorreccion($catalogo, (string) Str::uuid());
        $tokenOficina = $this->tokenOficina($usuario);

        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", $payload)
            ->assertOk();
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", $payload)
            ->assertOk();

        $this->assertSame(1, CorreccionValidacionPallet::query()->count());
    }

    public function test_permite_corregir_en_camara_sin_alterar_el_estado_operativo(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0003'),
            )
            ->assertCreated()
            ->json('data.id');

        Folio::query()
            ->where('numero_folio', 'PAL-CORR-0003')
            ->update([
                'estado_operacional' => 'disponible',
                'condicion_termica' => 'prefrio_aprobado',
            ]);

        $tokenOficina = $this->tokenOficina($usuario);
        $this->conToken($tokenOficina)->getJson("/api/validacion/pallets/{$validacionId}/correccion")
            ->assertOk()->assertJsonPath('puede_cambiar_numero_folio', false);
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'numero_folio' => 'PAL-CORR-0003-NUEVO',
            ])->assertConflict();
        $this->assertDatabaseMissing('folios', ['numero_folio' => 'PAL-CORR-0003-NUEVO']);

        $this->conToken($tokenOficina)
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $this->payloadCorreccion($catalogo, (string) Str::uuid()),
            )
            ->assertOk()
            ->assertJsonPath('data.folio.estado_operacional', 'disponible')
            ->assertJsonPath('data.folio.condicion_termica', 'prefrio_aprobado')
            ->assertJsonPath('data.catalogo.articulo.envase', 'Cartón 10 kg');

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0003')->firstOrFail();
        $this->assertSame('disponible', $folio->estado_operacional->value);
        $this->assertSame('prefrio_aprobado', $folio->condicion_termica->value);

        $correccion = CorreccionValidacionPallet::query()->firstOrFail();
        $this->assertSame(
            'disponible',
            $correccion->datos_anteriores['folio']['estado_operacional'],
        );
        $this->assertSame(
            'disponible',
            $correccion->datos_nuevos['folio']['estado_operacional'],
        );
    }

    public function test_permite_corregir_un_folio_despachado_e_inactivo(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0005'),
            )
            ->assertCreated()
            ->json('data.id');

        Folio::query()
            ->where('numero_folio', 'PAL-CORR-0005')
            ->update([
                'estado_operacional' => 'despachado',
                'condicion_termica' => 'prefrio_aprobado',
                'activo' => false,
            ]);

        $this->conToken($this->tokenOficina($usuario))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $this->payloadCorreccion($catalogo, (string) Str::uuid()),
            )
            ->assertOk()
            ->assertJsonPath('data.folio.estado_operacional', 'despachado')
            ->assertJsonPath('data.folio.activo', false)
            ->assertJsonPath('data.puede_corregir', true);

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0005')->firstOrFail();
        $this->assertFalse($folio->activo);
        $this->assertSame('despachado', $folio->estado_operacional->value);
        $this->assertSame('prefrio_aprobado', $folio->condicion_termica->value);
    }

    public function test_la_correccion_reemplaza_la_composicion_por_csg_con_lote_y_proceso(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PAL-CORR-0101'))
            ->assertCreated()
            ->json('data.id');
        $tokenOficina = $this->tokenOficina($usuario);

        $contexto = $this->conToken($tokenOficina)
            ->getJson("/api/validacion/pallets/{$validacionId}/correccion")
            ->assertOk()
            ->assertJsonPath('validacion.numero_folio', 'PAL-CORR-0101')
            ->assertJsonPath('validacion.composicion.0.origen_validacion_id', $catalogo['origen_id'])
            ->assertJsonPath('validacion.composicion.0.cantidad_cajas', 120)
            ->assertJsonPath('catalogo.temporada.id', $catalogo['temporada_id'])
            ->assertJsonPath('exige_lote_proceso', false);
        // Orígenes de toda la temporada, pero artículos y combinaciones solo del cliente del bulto.
        $this->assertCount(3, $contexto->json('catalogo.origenes'));
        $this->assertSame('MACE', $contexto->json('catalogo.cliente'));
        $this->assertEqualsCanonicalizing(
            [$catalogo['articulo_original_id'], $catalogo['articulo_corregido_id']],
            $contexto->json('catalogo.articulos_por_origen.'.$catalogo['origen_id']),
        );
        $this->assertSame([$catalogo['articulo_corregido_id']], $contexto->json('catalogo.articulos_por_origen.'.$catalogo['origen_dos_id']));
        $this->assertArrayNotHasKey($catalogo['origen_otro_cliente_id'], $contexto->json('catalogo.articulos_por_origen'));
        $this->assertCount(2, $contexto->json('catalogo.articulos'));
        $otroCliente = $this->conToken($tokenOficina)
            ->getJson("/api/validacion/pallets/{$validacionId}/correccion?cliente=OTRO")
            ->assertOk()
            ->assertJsonPath('catalogo.cliente', 'OTRO');
        $this->assertSame([$catalogo['origen_otro_cliente_id']], array_keys($otroCliente->json('catalogo.articulos_por_origen')));

        // Se agrega un segundo CSG: 80 + 40 cajas, cada línea con su lote y proceso.
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'cantidad_cajas' => 120,
                'origen_validacion_id' => null,
                'composicion' => [
                    ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 80, 'lote_materia_prima' => ' l-100 ', 'proceso_packing' => 'p-1'],
                    ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 40, 'lote_materia_prima' => 'L-200', 'proceso_packing' => 'P-2'],
                ],
                'motivo_correccion' => 'El pallet trae fruta de dos CSG.',
            ])
            ->assertOk()
            ->assertJsonPath('data.cantidad_cajas', 120);

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0101')->firstOrFail();
        $this->assertSame('MIX', $folio->datos_externos['csg']);
        $this->assertSame(['123225', '123226'], $folio->datos_externos['csgs']);
        $this->assertSame(
            [['123225', 80, 'L-100', 'P-1'], ['123226', 40, 'L-200', 'P-2']],
            collect($folio->datos_externos['composicion'])->map(fn (array $linea): array => [
                $linea['csg'], $linea['cantidad_cajas'], $linea['lote_materia_prima'], $linea['proceso_packing'],
            ])->all(),
        );
        $this->assertSame('Cartón 10 kg', $folio->datos_externos['envase']);
        $this->assertSame(
            ['123225' => 80, '123226' => 40],
            TrazabilidadFolioOrigen::query()->where('folio_id', $folio->id)->orderBy('csg')->pluck('cantidad_cajas', 'csg')->all(),
        );

        // Un bulto con varios CSG ya se puede corregir: vuelve a un solo CSG con otro total.
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'composicion' => [
                    ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 118, 'lote_materia_prima' => 'L-200', 'proceso_packing' => 'P-2'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.cantidad_cajas', 118);
        $folio->refresh();
        $this->assertSame(['123226', 'Fundo Los Robles', 118], [$folio->datos_externos['csg'], $folio->datos_externos['predio'], $folio->datos_externos['cantidad_cajas']]);
        $this->assertDatabaseHas('validaciones_pallet', ['id' => $validacionId, 'origen_validacion_id' => $catalogo['origen_dos_id']]);
        $this->assertSame(1, TrazabilidadFolioOrigen::query()->where('folio_id', $folio->id)->count());
    }

    public function test_la_composicion_corregida_cumple_las_reglas_de_la_pda(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PAL-CORR-0102'))
            ->assertCreated()
            ->json('data.id');
        $tokenOficina = $this->tokenOficina($usuario);
        $corregir = fn (array $composicion, int $cajas, ?string $articulo = null) => $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'cantidad_cajas' => $cajas,
                'articulo_validacion_id' => $articulo ?? $catalogo['articulo_corregido_id'],
                'composicion' => $composicion,
            ]);

        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 80],
            ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 30],
        ], 120)->assertUnprocessable()->assertJsonPath('message', 'La composición por CSG suma 110 cajas y el bulto declara 120.');
        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60],
            ['origen_validacion_id' => $catalogo['origen_otro_cliente_id'], 'cantidad_cajas' => 60],
        ], 120)->assertUnprocessable()->assertJsonPath('message', 'Todos los CSG del bulto deben pertenecer al mismo cliente y marca.');
        // El embalaje original no está habilitado en el segundo CSG.
        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60],
            ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 60],
        ], 120, $catalogo['articulo_original_id'])->assertUnprocessable()->assertJsonPath('message', 'El artículo corregido no está habilitado para todos los CSG del bulto.');
        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60, 'lote_materia_prima' => 'L-1'],
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60, 'lote_materia_prima' => 'l-1'],
        ], 120)->assertUnprocessable()->assertJsonValidationErrors(['composicion' => 'No repitas la misma combinación de CSG, lote y proceso en el bulto.']);

        config(['validacion.exigir_lote_proceso' => true]);
        $corregir([['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 120]], 120)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['composicion.0.lote_materia_prima' => 'Ingresa el lote de materia prima de cada línea.']);

        $this->assertSame(0, DB::table('correcciones_validacion_pallet')->count());
        $this->assertSame(120, Folio::query()->where('numero_folio', 'PAL-CORR-0102')->firstOrFail()->datos_externos['cantidad_cajas']);
    }

    public function test_supervisor_no_puede_corregir_validacion_aprobada(): void
    {
        [$catalogo, , $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0004'),
            )
            ->assertCreated()
            ->json('data.id');
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
        ]);

        $this->conToken($this->tokenOficina($supervisor))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $this->payloadCorreccion($catalogo, (string) Str::uuid()),
            )
            ->assertForbidden();

        $this->assertDatabaseCount('correcciones_validacion_pallet', 0);
    }

    public function test_corrige_solo_las_fechas_de_una_validacion_con_el_reloj_adelantado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03T01:05:00Z'));
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $captura = $this->conToken($tokenPda)->postJson('/api/validacion/pallets', [
            ...$this->payloadValidacion($catalogo, 'SAL-FECHA-1'),
            'tipo_bulto' => 'saldo',
            'cantidad_cajas' => 60,
            'generado_dispositivo_at' => '2026-11-04T01:04:00Z',
            'fecha_embalaje' => '2026-11-03',
        ])->assertCreated()->json('data');
        $id = $captura['id'];
        $tokenOficina = $this->tokenOficina($usuario);
        $this->conToken($tokenOficina)->getJson("/api/validacion/pallets/{$id}/correccion")
            ->assertOk()->assertJsonPath('validacion.fecha_hora_validacion', '2026-11-03T22:04');

        $cambio = [
            'operacion_id' => (string) Str::uuid(),
            'solo_fechas' => true,
            'fecha_hora_validacion' => '2026-11-02T22:04',
            'fecha_embalaje' => '2026-11-02',
            'motivo_correccion' => 'La PDA tenía la fecha adelantada un día.',
        ];
        $ruta = "/api/validacion/pallets/{$id}/corregir";
        $this->conToken($tokenOficina)->putJson($ruta, [
            ...$cambio, 'operacion_id' => (string) Str::uuid(), 'fecha_hora_validacion' => '2026-11-03T22:04',
        ])->assertUnprocessable();
        $this->conToken($tokenOficina)->putJson($ruta, [
            ...$cambio, 'operacion_id' => (string) Str::uuid(), 'fecha_embalaje' => '2026-11-03',
        ])->assertUnprocessable();

        $this->conToken($tokenOficina)->putJson($ruta, $cambio)
            ->assertOk()
            ->assertJsonPath('data.folio.id', $captura['folio']['id'])
            ->assertJsonPath('data.cantidad_cajas', 60)
            ->assertJsonPath('data.catalogo.composicion.0.fecha_embalaje', '2026-11-02')
            ->assertJsonPath('data.generado_dispositivo_at', '2026-11-03T01:04:00+00:00')
            ->assertJsonPath('data.recibido_servidor_at', '2026-11-03T01:05:00+00:00');
        $this->conToken($tokenOficina)->putJson($ruta, $cambio)->assertOk();
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'folio_id' => $captura['folio']['id'], 'fecha_embalaje' => '2026-11-02', 'cantidad_cajas' => 60,
        ]);
        $this->assertDatabaseCount('correcciones_validacion_pallet', 1);
        $this->assertDatabaseHas('correcciones_validacion_pallet', [
            'validacion_pallet_id' => $id,
            'datos_anteriores->validacion->generado_dispositivo_at' => '2026-11-04T01:04:00+00:00',
            'datos_anteriores->validacion->snapshot->fecha_embalaje' => '2026-11-03',
            'datos_nuevos->validacion->snapshot->fecha_embalaje' => '2026-11-02',
        ]);
    }

    /**
     * @return array{array<string, string|int>, User, string}
     */
    public function test_corregir_a_saldo_suelta_la_salida_pendiente_del_planificador(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PAL-CORR-SALDO'))
            ->assertCreated()->json('data.id');
        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-SALDO')->firstOrFail();
        $plan = app(ServicioPlanesOperacionales::class)->crear(
            temporada: $folio->temporada, tipo: TipoPlanOperacional::RecepcionTunel, titulo: 'Recibir túnel de prueba',
            creadoPor: $usuario, tareas: [[
                'folio_id' => $folio->id, 'tipo_movimiento' => TipoMovimiento::UbicacionInicial,
                'prioridad' => PrioridadOperacional::Alta, 'instruccion' => 'Retirar PAL-CORR-SALDO de TUNEL 1.',
            ]], prioridad: PrioridadOperacional::Alta, motivo: 'Prueba', referenciaTipo: 'proceso_prefrio',
            referenciaId: (string) Str::uuid(), contexto: ['planner_horizon' => 'rolling'],
        );
        $tarea = $plan->tareas()->firstOrFail();

        $this->conToken($this->tokenOficina($usuario))
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'tipo_bulto' => 'saldo', 'cantidad_cajas' => 1, 'motivo_correccion' => 'Es una caja de muestra, no un pallet.',
            ])->assertOk();

        $tarea->refresh();
        $this->assertSame('cancelada', $tarea->estado->value);
        $this->assertSame('PAL-CORR-SALDO es saldo: el planificador no pide estibar saldos.', $tarea->motivo_cancelacion);
        $this->assertSame($usuario->id, $tarea->cancelada_por_user_id);
    }
}
