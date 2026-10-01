<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class ValidacionPalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumen_cuenta_observados_de_toda_la_temporada_y_aplica_los_filtros_del_historial(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Administrador, 'VAL-RESUMEN');
        $usuarioId = User::query()->where('rol', RolUsuario::Administrador->value)->latest('id')->value('id');
        $dispositivoId = Dispositivo::query()->where('codigo', 'VAL-RESUMEN')->value('id');
        for ($numero = 1; $numero <= 27; $numero++) {
            DB::table('validaciones_pallet')->insert([
                'id' => (string) Str::uuid(),
                'operacion_id' => (string) Str::uuid(),
                'payload_hash' => str_repeat('a', 64),
                'numero_folio' => sprintf('PAL-RES-%02d', $numero),
                'numero_intento' => 1,
                'tipo_bulto' => 'pallet',
                'cantidad_cajas' => 100,
                'temporada_id' => $catalogo['temporada_id'],
                'articulo_validacion_id' => $catalogo['articulo_validacion_id'],
                'origen_validacion_id' => $catalogo['origen_validacion_id'],
                'resultado' => $numero === 27 ? 'aprobado' : 'observado',
                'estado' => 'aceptada',
                'catalogo_version_dispositivo' => 1,
                'catalogo_version_servidor' => 1,
                'snapshot' => '{}',
                'user_id' => $usuarioId,
                'dispositivo_id' => $dispositivoId,
                'generado_dispositivo_at' => now(),
                'recibido_servidor_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->conToken($token)->getJson('/api/validacion/pallets?per_page=25')
            ->assertOk()->assertJsonCount(25, 'data');
        $this->conToken($token)->getJson('/api/validacion/registro/resumen')
            ->assertOk()
            ->assertJsonPath('temporada.id', $catalogo['temporada_id'])
            ->assertJsonPath('temporada.version_catalogo', 1)
            ->assertJsonPath('articulos_activos', 1)
            ->assertJsonPath('origenes_activos', 1)
            ->assertJsonPath('combinaciones_activas', 1)
            ->assertJsonPath('observados', 26)
            ->assertJsonMissingPath('articulos');
        $this->conToken($token)->getJson('/api/validacion/registro/resumen?folio=PAL-RES-01')
            ->assertOk()->assertJsonPath('observados', 1);
        $this->conToken($token)->getJson('/api/validacion/registro/resumen?resultado=aprobado')
            ->assertOk()->assertJsonPath('observados', 0);
    }

    public function test_aprueba_y_crea_folio_pendiente_de_prefrio_de_forma_idempotente(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-01');
        $payload = $this->payload($catalogo, 'PAL-0001');

        $validacion = $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.numero_folio', 'PAL-0001')
            ->assertJsonPath('data.resultado', 'aprobado')
            ->assertJsonPath('data.estado', 'aceptada')
            ->assertJsonPath('data.linea_proceso', 1)
            ->assertJsonPath('data.turno', 'A')
            ->assertJsonPath('data.catalogo.categoria.nombre', 'Exportación')
            ->assertJsonPath('data.folio.estado_operacional', 'pendiente_prefrio')
            ->json('data.id');

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $validacion);

        $this->assertSame(1, Folio::query()->where('numero_folio', 'PAL-0001')->count());
        $this->assertDatabaseHas('folios', [
            'numero_folio' => 'PAL-0001',
            'temporada_id' => $catalogo['temporada_id'],
        ]);
        $this->assertDatabaseCount('validaciones_pallet', 1);
    }

    public function test_usa_la_fecha_de_validacion_si_el_folio_no_informa_fecha_de_embalaje(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-FECHA');
        $payload = $this->payload($catalogo, 'PAL-FECHA-VALIDACION');
        $payload['generado_dispositivo_at'] = '2026-08-09T02:30:00Z';

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.catalogo.fecha_embalaje', '2026-08-08')
            ->assertJsonPath('data.catalogo.composicion.0.fecha_embalaje', '2026-08-08');

        $folio = Folio::query()->where('numero_folio', 'PAL-FECHA-VALIDACION')->firstOrFail();
        $this->assertSame('2026-08-08', $folio->datos_externos['fecha_embalaje']);
        $this->assertSame(
            '2026-08-08',
            $folio->datos_externos['composicion'][0]['fecha_embalaje'],
        );
    }

    public function test_aprueba_un_bulto_con_varios_csg_y_una_sola_fecha(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-MIX-CSG');
        $segundoOrigen = (string) Str::uuid();
        DB::table('origenes_validacion')->insert([
            'id' => $segundoOrigen,
            'temporada_id' => $catalogo['temporada_id'],
            'cliente' => 'DIS',
            'marca' => 'ATLAS',
            'csg' => '105411',
            'predio' => 'OLM 2',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(),
            'temporada_id' => $catalogo['temporada_id'],
            'articulo_validacion_id' => $catalogo['articulo_validacion_id'],
            'origen_validacion_id' => $segundoOrigen,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = [
            ...$this->payload($catalogo, 'PAL-MIX-CSG'),
            'fecha_embalaje' => '2026-08-12',
            'composicion' => [
                ['origen_validacion_id' => $catalogo['origen_validacion_id'], 'cantidad_cajas' => 70],
                ['origen_validacion_id' => $segundoOrigen, 'cantidad_cajas' => 50],
            ],
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.catalogo.origen.csg', 'MIX')
            ->assertJsonPath('data.catalogo.fecha_embalaje', '2026-08-12')
            ->assertJsonCount(2, 'data.catalogo.composicion');

        $folio = Folio::query()->where('numero_folio', 'PAL-MIX-CSG')->firstOrFail();
        $this->assertSame('MIX', $folio->datos_externos['csg']);
        $this->assertSame('2026-08-12', $folio->datos_externos['fecha_embalaje']);
        $this->assertSame([70, 50], collect($folio->datos_externos['composicion'])->pluck('cantidad_cajas')->all());
    }

    public function test_registra_varios_lotes_y_procesos_por_pallet_y_los_hace_trazables(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-LOTES');
        $payload = [
            ...$this->payload($catalogo, 'PAL-LOTES-01'),
            'composicion' => [
                [
                    'origen_validacion_id' => $catalogo['origen_validacion_id'],
                    'cantidad_cajas' => 80,
                    'lote_materia_prima' => ' l-2401 ',
                    'proceso_packing' => 'p-77',
                ],
                [
                    'origen_validacion_id' => $catalogo['origen_validacion_id'],
                    'cantidad_cajas' => 40,
                    'lote_materia_prima' => 'L-2402',
                    'proceso_packing' => 'P-77',
                ],
            ],
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.catalogo.composicion.0.lote_materia_prima', 'L-2401')
            ->assertJsonPath('data.catalogo.composicion.1.proceso_packing', 'P-77');

        // Reintento idéntico: sigue siendo idempotente con los campos nuevos.
        $this->conToken($token)->postJson('/api/validacion/pallets', $payload)->assertOk();

        $folio = Folio::query()->where('numero_folio', 'PAL-LOTES-01')->firstOrFail();
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'folio_id' => $folio->id,
            'numero_lote_materia_prima' => 'L-2401',
            'numero_proceso_packing' => 'P-77',
            'cantidad_cajas' => 80,
        ]);
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'folio_id' => $folio->id,
            'numero_lote_materia_prima' => 'L-2402',
            'cantidad_cajas' => 40,
        ]);

        $consulta = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/consultas/trazabilidad?q=l-2402&temporada_id='.$folio->temporada_id)
            ->assertOk()
            ->assertJsonPath('data.termino', 'L-2402')
            ->assertJsonPath('data.resumen.folios', 1)
            ->assertJsonPath('data.resumen.cajas_coincidentes', 40)
            ->assertJsonPath('data.folios.0.numero', 'PAL-LOTES-01')
            ->assertJsonCount(2, 'data.folios.0.lineas');

        $this->actingAs($consulta, 'sanctum')
            ->getJson('/api/consultas/trazabilidad?q=P-77&temporada_id='.$folio->temporada_id)
            ->assertOk()
            ->assertJsonPath('data.resumen.cajas_coincidentes', 120);
    }

    public function test_con_la_exigencia_activa_lote_y_proceso_son_obligatorios_salvo_en_un_rechazo(): void
    {
        config(['validacion.exigir_lote_proceso' => true]);
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-EXIGE');

        // PDA anterior: sin composición.
        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $this->payload($catalogo, 'PAL-EXIGE-01'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('composicion');

        $linea = ['origen_validacion_id' => $catalogo['origen_validacion_id'], 'cantidad_cajas' => 120];
        $this->conToken($token)
            ->postJson('/api/validacion/pallets', [
                ...$this->payload($catalogo, 'PAL-EXIGE-01'),
                'composicion' => [[...$linea, 'lote_materia_prima' => 'L-1']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('composicion.0.proceso_packing');

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', [
                ...$this->payload($catalogo, 'PAL-EXIGE-01'),
                'composicion' => [[...$linea, 'lote_materia_prima' => 'L-1', 'proceso_packing' => 'P-1']],
            ])
            ->assertCreated();
    }

    public function test_rechaza_repetir_la_misma_combinacion_de_csg_lote_y_proceso(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-LOTES-DUP');
        $linea = [
            'origen_validacion_id' => $catalogo['origen_validacion_id'],
            'cantidad_cajas' => 60,
            'lote_materia_prima' => 'L-1',
            'proceso_packing' => 'P-1',
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', [
                ...$this->payload($catalogo, 'PAL-LOTES-DUP'),
                'composicion' => [$linea, [...$linea, 'lote_materia_prima' => ' l-1 ']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['composicion']);
    }

    public function test_observa_sin_crear_folio_y_la_aprobacion_posterior_es_otro_intento(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-02');
        $observado = [
            ...$this->payload($catalogo, 'PAL-0002'),
            'resultado' => 'observado',
            'motivo' => 'csg_no_coincide',
            'observacion' => 'La etiqueta física informa otro CSG.',
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $observado)
            ->assertCreated()
            ->assertJsonPath('data.numero_intento', 1)
            ->assertJsonPath('data.folio', null);

        $this->assertDatabaseMissing('folios', ['numero_folio' => 'PAL-0002']);

        $aprobado = $this->payload($catalogo, 'PAL-0002');
        $aprobado['operacion_id'] = (string) Str::uuid();

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $aprobado)
            ->assertCreated()
            ->assertJsonPath('data.numero_intento', 2)
            ->assertJsonPath('data.folio.numero_folio', 'PAL-0002');
    }

    public function test_mi_sesion_resume_folios_intentos_y_no_mezcla_validadores_dispositivos_ni_accesos_previos(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-SES-01');
        DB::table('temporadas')
            ->where('id', '!=', $catalogo['temporada_id'])
            ->update(['activa' => false]);

        $anterior = $this->payload($catalogo, 'PAL-SES-ANTERIOR');
        $anterior['generado_dispositivo_at'] = now()->subMinute()->toAtomString();
        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $anterior)
            ->assertCreated();

        $observado = [
            ...$this->payload($catalogo, 'PAL-SES-0001'),
            'resultado' => 'observado',
            'motivo' => 'csg_no_coincide',
            'observacion' => 'Primer intento de la sesión.',
        ];
        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $observado)
            ->assertCreated();

        $aprobado = $this->payload($catalogo, 'PAL-SES-0001');
        $aprobado['operacion_id'] = (string) Str::uuid();
        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $aprobado)
            ->assertCreated()
            ->assertJsonPath('data.numero_intento', 2);

        $segundoObservado = [
            ...$this->payload($catalogo, 'PAL-SES-0002'),
            'resultado' => 'observado',
            'motivo' => 'etiqueta_no_coincide',
            'observacion' => 'Segundo folio de la sesión.',
        ];
        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $segundoObservado)
            ->assertCreated();

        [, $otroToken] = $this->acceso(RolUsuario::Validador, 'VAL-SES-02');
        $this->conToken($otroToken)
            ->postJson('/api/validacion/pallets', $this->payload($catalogo, 'PAL-SES-OTRO'))
            ->assertCreated();

        $this->conToken($token)
            ->getJson('/api/validacion/pallets/mi-sesion?per_page=10')
            ->assertOk()
            ->assertJsonPath('sesion.dispositivo.codigo', 'VAL-SES-01')
            ->assertJsonPath('sesion.temporada.id', $catalogo['temporada_id'])
            ->assertJsonPath('resumen.folios_trabajados', 2)
            ->assertJsonPath('resumen.registros_realizados', 3)
            ->assertJsonPath('resumen.aprobados', 1)
            ->assertJsonPath('resumen.observados', 1)
            ->assertJsonPath('resumen.rechazados', 0)
            ->assertJsonPath('resumen.conflictos', 0)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data')
            ->assertJsonMissing(['numero_folio' => 'PAL-SES-ANTERIOR'])
            ->assertJsonMissing(['numero_folio' => 'PAL-SES-OTRO']);
    }

    public function test_aprobacion_es_terminal_y_un_intento_posterior_queda_en_conflicto(): void
    {
        [$catalogo, $tokenA] = $this->contexto(RolUsuario::Validador, 'VAL-03');
        [, $tokenB] = $this->acceso(RolUsuario::Validador, 'VAL-04');

        $primeraId = $this->conToken($tokenA)
            ->postJson('/api/validacion/pallets', $this->payload($catalogo, 'PAL-0003'))
            ->assertCreated()
            ->json('data.id');

        $segundo = [
            ...$this->payload($catalogo, 'PAL-0003'),
            'resultado' => 'observado',
            'motivo' => 'etiqueta_no_coincide',
            'observacion' => 'Segundo dispositivo informa otra etiqueta.',
        ];

        $this->conToken($tokenB)
            ->postJson('/api/validacion/pallets', $segundo)
            ->assertStatus(409)
            ->assertJsonPath('data.estado', 'conflicto')
            ->assertJsonPath('data.numero_intento', 2)
            ->assertJsonPath('data.conflicto_con.id', $primeraId);

        $this->assertSame(1, Folio::query()->where('numero_folio', 'PAL-0003')->count());
        $this->assertDatabaseCount('validaciones_pallet', 2);
    }

    public function test_supervisor_puede_rechazar_y_el_rechazo_es_terminal(): void
    {
        // Un rechazo no exige lote ni proceso: la etiqueta puede ser ilegible o faltar.
        config(['validacion.exigir_lote_proceso' => true]);
        [$catalogo, $token] = $this->contexto(RolUsuario::SupervisorFrio, 'SUP-01');
        $rechazo = [
            ...$this->payload($catalogo, 'PAL-0004'),
            'resultado' => 'rechazado',
            'motivo' => 'condicion_fruta',
            'observacion' => 'Condición no aceptable.',
        ];

        $rechazoId = $this->conToken($token)
            ->postJson('/api/validacion/pallets', $rechazo)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'aceptada')
            ->assertJsonPath('data.folio', null)
            ->json('data.id');
        config(['validacion.exigir_lote_proceso' => false]);

        $aprobacion = $this->payload($catalogo, 'PAL-0004');

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $aprobacion)
            ->assertStatus(409)
            ->assertJsonPath('data.estado', 'conflicto')
            ->assertJsonPath('data.conflicto_con.id', $rechazoId);

        $this->assertDatabaseMissing('folios', ['numero_folio' => 'PAL-0004']);
    }

    public function test_validador_no_puede_confirmar_rechazo_definitivo(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-05');
        $payload = [
            ...$this->payload($catalogo, 'PAL-0005'),
            'resultado' => 'rechazado',
            'motivo' => 'condicion_fruta',
            'observacion' => 'Condición no aceptable.',
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertForbidden();

        $this->assertDatabaseMissing('validaciones_pallet', ['numero_folio' => 'PAL-0005']);
    }

    public function test_reutilizar_uuid_con_payload_distinto_genera_conflicto(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-06');
        $payload = [
            ...$this->payload($catalogo, 'PAL-0006'),
            'resultado' => 'observado',
            'motivo' => 'cantidad_cajas_incorrecta',
            'observacion' => 'Cantidad pendiente de confirmación.',
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated();

        $payload['cantidad_cajas'] = 121;

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertStatus(409)
            ->assertJsonPath('codigo', 'conflicto_operacional');

        $this->assertDatabaseCount('validaciones_pallet', 1);
    }

    public function test_acepta_catalogo_desactualizado_y_lo_informa_en_la_respuesta(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-07');
        $payload = $this->payload($catalogo, 'PAL-0007');
        $payload['catalogo_version'] = 2;

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('catalogo_desactualizado', true)
            ->assertJsonPath('data.catalogo.desactualizado', true)
            ->assertJsonPath('data.catalogo.version_dispositivo', 2)
            ->assertJsonPath('data.catalogo.version_servidor', 1);
    }

    public function test_rechaza_una_temporada_inactiva(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-08');
        DB::table('temporadas')
            ->where('id', $catalogo['temporada_id'])
            ->update(['activa' => false]);

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $this->payload($catalogo, 'PAL-0008'))
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseMissing('folios', ['numero_folio' => 'PAL-0008']);
    }

    public function test_indice_valida_paginacion_filtra_y_no_expone_el_hash_interno(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-09');

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $this->payload($catalogo, 'PAL-0009'))
            ->assertCreated();

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?per_page=1000')
            ->assertUnprocessable();

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?folio=pal-0009&resultado=aprobado&estado=aceptada&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('data.0.numero_folio', 'PAL-0009')
            ->assertJsonMissingPath('data.0.payload_hash');
    }

    public function test_exige_contexto_de_jornada_para_registrar_la_validacion(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-JORNADA-01');
        $payload = $this->payload($catalogo, 'PAL-SIN-JORNADA');
        unset($payload['linea_proceso'], $payload['turno']);

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['linea_proceso', 'turno']);
    }

    public function test_filtra_por_jornada_y_exporta_el_registro_rrpp_01_en_hora_local(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-RRPP-01');
        $payload = [
            ...$this->payload($catalogo, 'PAL-RRPP-0001'),
            'linea_proceso' => 2,
            'turno' => 'b',
            'generado_dispositivo_at' => '2026-07-29T23:30:00-04:00',
        ];

        $encargado = $this->conToken($token)
            ->postJson('/api/validacion/pallets', $payload)
            ->assertCreated()
            ->assertJsonPath('data.linea_proceso', 2)
            ->assertJsonPath('data.turno', 'B')
            ->json('data.usuario.nombre');

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?fecha=2026-07-29&linea_proceso=2&turno=B&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', 'PAL-RRPP-0001');

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?fecha=2026-07-30&per_page=10')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->conToken($token)
            ->getJson("/api/validacion/registro/opciones?temporada_id={$catalogo['temporada_id']}")
            ->assertOk()
            ->assertJsonPath('temporada.id', $catalogo['temporada_id'])
            ->assertJsonCount(1, 'validadores')
            ->assertJsonPath('validadores.0.nombre', $encargado);

        $respuesta = $this->conToken($token)->get(
            '/api/validacion/registro/rrpp-01?'.http_build_query([
                'temporada_id' => $catalogo['temporada_id'],
                'fecha' => '2026-07-29',
                'linea_proceso' => 2,
                'turno' => 'B',
            ]),
        );

        $respuesta
            ->assertOk()
            ->assertDownload('RRPP-01_2026-07-29.xlsx')
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertIsString($hoja);
        $this->assertSame('29-07-2026', $this->valorCelda($hoja, 'C5'));
        $this->assertSame($encargado, $this->valorCelda($hoja, 'C6'));
        $this->assertSame('B', $this->valorCelda($hoja, 'C7'));
        $this->assertSame('2', $this->valorCelda($hoja, 'C8'));
        $this->assertSame('PAL-RRPP-0001', $this->valorCelda($hoja, 'B11'));
        $this->assertSame('ATLAS', $this->valorCelda($hoja, 'C11'));
        $this->assertSame('5 kg', $this->valorCelda($hoja, 'D11'));
        $this->assertSame('Cereza', $this->valorCelda($hoja, 'E11'));
        $this->assertSame('Santina', $this->valorCelda($hoja, 'F11'));
        $this->assertSame('105410', $this->valorCelda($hoja, 'G11'));
        $this->assertSame('2J', $this->valorCelda($hoja, 'H11'));
        $this->assertSame('120', $this->valorCelda($hoja, 'I11'));
        $this->assertSame('X', $this->valorCelda($hoja, 'J11'));
        $this->assertSame('', $this->valorCelda($hoja, 'K11'));
        $this->assertSame('SUM(I11:I30)', $this->formulaCelda($hoja, 'I31'));
        $this->assertSame('120', $this->valorCelda($hoja, 'I31'));
        $zip->close();
    }

    public function test_descarga_rrpp_01_en_blanco_sin_validaciones_previas(): void
    {
        [, $token] = $this->contexto(RolUsuario::Validador, 'VAL-RRPP-BLANCO');

        $respuesta = $this->conToken($token)
            ->get('/api/validacion/registro/rrpp-01/en-blanco')
            ->assertOk()
            ->assertDownload('RRPP-01_EN_BLANCO.xlsx')
            ->assertHeader(
                'Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()) === true);
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $libro = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $this->assertIsString($hoja);
        $this->assertIsString($libro);
        $this->assertSame('', $this->valorCelda($hoja, 'C5'));
        $this->assertSame('', $this->valorCelda($hoja, 'C6'));
        $this->assertSame('', $this->valorCelda($hoja, 'C7'));
        $this->assertSame('', $this->valorCelda($hoja, 'C8'));
        $this->assertSame('', $this->valorCelda($hoja, 'B11'));
        $this->assertSame('Categoría', $this->valorCelda($hoja, 'M10'));
        $this->assertSame('SUM(I11:I30)', $this->formulaCelda($hoja, 'I31'));
        $this->assertSame('0', $this->valorCelda($hoja, 'I31'));
        $this->assertStringContainsString('RRPP-01 en blanco', $libro);
    }

    public function test_historial_separa_temporadas_y_por_defecto_muestra_solo_la_activa(): void
    {
        [$catalogo, $token] = $this->contexto(RolUsuario::Validador, 'VAL-TEMP-01');

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $this->payload($catalogo, 'PAL-TEMP-ANTERIOR'))
            ->assertCreated();

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'TEMP-NUEVA',
            'nombre' => 'Temporada nueva',
            'activa' => true,
        ]);

        $this->assertFalse(Temporada::query()->findOrFail($catalogo['temporada_id'])->activa);

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?per_page=10')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->conToken($token)
            ->getJson("/api/validacion/pallets?temporada_id={$catalogo['temporada_id']}&per_page=10")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', 'PAL-TEMP-ANTERIOR');
    }

    public function test_validador_no_puede_consultar_cargas_materiales_ni_camaras(): void
    {
        [, $token] = $this->acceso(RolUsuario::Validador, 'VAL-10');

        $this->conToken($token)
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->conToken($token)
            ->getJson('/api/cargas')
            ->assertForbidden();

        $this->conToken($token)
            ->getJson('/api/materiales/inventario')
            ->assertForbidden();
    }

    /**
     * @return array{array<string, string|int>, string}
     */
    private function contexto(RolUsuario $rol, string $codigo): array
    {
        $temporada = (string) Str::uuid();
        $articulo = (string) Str::uuid();
        $origen = (string) Str::uuid();
        $categoria = (string) Str::uuid();

        $this->desactivarTemporadasDePrueba();
        DB::table('temporadas')->insert([
            'id' => $temporada,
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('articulos_validacion')->insert([
            'id' => $articulo,
            'temporada_id' => $temporada,
            'especie' => 'Cereza',
            'variedad' => 'Santina',
            'calibre' => '2J',
            'envase' => '5 kg',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('categorias_validacion')->insert([
            'id' => $categoria,
            'temporada_id' => $temporada,
            'nombre' => 'Exportación',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen,
            'temporada_id' => $temporada,
            'cliente' => 'DIS',
            'marca' => 'ATLAS',
            'csg' => '105410',
            'predio' => 'OLM',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(),
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [, $token] = $this->acceso($rol, $codigo);

        return [[
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'categoria_validacion_id' => $categoria,
            'catalogo_version' => 1,
        ], $token];
    }

    /**
     * @return array{User, string}
     */
    private function acceso(RolUsuario $rol, string $codigo): array
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $dispositivo = Dispositivo::create([
            'codigo' => $codigo,
            'nombre' => "PDA {$codigo}",
            'plataforma' => 'android',
            'activo' => true,
        ]);
        $token = $usuario->crearTokenParaDispositivo($dispositivo, "test-{$codigo}")->plainTextToken;

        return [$usuario, $token];
    }

    /**
     * @param  array<string, string|int>  $catalogo
     * @return array<string, mixed>
     */
    private function payload(array $catalogo, string $folio): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $folio,
            'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 120,
            'linea_proceso' => 1,
            'turno' => 'A',
            ...$catalogo,
            'resultado' => 'aprobado',
            'motivo' => null,
            'observacion' => null,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function valorCelda(string $xml, string $referencia): string
    {
        $documento = new \DOMDocument;
        $this->assertTrue($documento->loadXML($xml));
        $xpath = new \DOMXPath($documento);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $celda = $xpath->query("//m:c[@r='{$referencia}']")->item(0);
        $this->assertNotNull($celda);
        $inline = $xpath->query('m:is/m:t', $celda)->item(0);

        return $inline?->textContent ?? $xpath->query('m:v', $celda)->item(0)?->textContent ?? '';
    }

    private function formulaCelda(string $xml, string $referencia): string
    {
        $documento = new \DOMDocument;
        $this->assertTrue($documento->loadXML($xml));
        $xpath = new \DOMXPath($documento);
        $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $celda = $xpath->query("//m:c[@r='{$referencia}']")->item(0);
        $this->assertNotNull($celda);

        return $xpath->query('m:f', $celda)->item(0)?->textContent ?? '';
    }
}
