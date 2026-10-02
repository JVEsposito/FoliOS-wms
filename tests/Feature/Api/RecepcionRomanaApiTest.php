<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\EventoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecepcionRomanaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_exige_especie_para_fruta_y_permite_leer_recepciones_historicas_sin_ella(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        unset($datos['especie_validacion_id']);
        $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()->assertJsonValidationErrors('especie_validacion_id');

        $datos['especie_validacion_id'] = EspecieValidacion::query()->firstOrFail()->id;
        $recepcion = $this->postJson('/api/romana/recepciones', $datos)->assertCreated()->json('data');
        $actualizacion = $datos;
        $actualizacion['operacion_id'] = (string) Str::uuid();
        $actualizacion['version_conocida'] = $recepcion['version'];
        unset($actualizacion['especie_validacion_id']);
        $this->putJson('/api/romana/recepciones/'.$recepcion['id'], $actualizacion)
            ->assertUnprocessable()->assertJsonValidationErrors('especie_validacion_id');

        RecepcionRomana::query()->whereKey($recepcion['id'])->update(['especie_validacion_id' => null]);
        $this->getJson('/api/romana/recepciones/'.$recepcion['id'])
            ->assertOk()->assertJsonPath('data.especie_validacion_id', null);
    }

    public function test_exige_elegir_un_tipo_de_camion_valido_al_registrar_el_ingreso(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        $this->actingAs($operador, 'sanctum');

        unset($datos['tipo_camion']);
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo_camion');

        $datos['tipo_camion'] = 'otro';
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo_camion');

        $datos['tipo_camion'] = 'plano';
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->assertJsonPath('data.tipo_camion', 'plano');
    }

    public function test_completa_el_pesaje_en_dos_tiempos_y_emite_el_aviso_de_recibo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-21 10:45:00'));
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $cliente = $this->cliente();
        $datos = $this->datosIngreso($cliente);

        $creada = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::EnBasculaIngreso->value)
            ->assertJsonPath('data.numero_recepcion', 'REC-2607-0001')
            ->assertJsonPath('data.temporada.id', $datos['temporada_id'])
            ->assertJsonPath('data.cliente.nombre', 'Exportadora Los Andes')
            ->assertJsonPath('data.peso_bruto', 28540)
            ->assertJsonPath('data.tipo_camion', 'termo')
            ->assertJsonPath('data.envases.0.tipo_envase', 'bins')
            ->assertJsonPath('data.envases.0.cantidad_declarada', 48)
            ->json('data');

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->assertJsonPath('data.id', $creada['id']);

        $operacionConfirmacion = (string) Str::uuid();
        $this->postJson('/api/romana/recepciones/'.$creada['id'].'/confirmar-ingreso', [
            'operacion_id' => $operacionConfirmacion,
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::EnBasculaSalida->value)
            ->assertJsonPath('data.puede_cerrar', false)
            ->assertJsonPath('data.destare_pendiente_validacion', true);

        $this->postJson('/api/romana/recepciones/'.$creada['id'].'/confirmar-ingreso', [
            'operacion_id' => $operacionConfirmacion,
        ])->assertOk();

        $this->marcarEnvasesValidadosParaDestare($creada['id']);
        $this->travelTo(CarbonImmutable::parse('2026-07-21 14:10:00'));
        $cerrada = $this->postJson('/api/romana/recepciones/'.$creada['id'].'/cerrar', [
            'operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'mismos',
            'numero_guia_salida' => 'GS-PRUEBA',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40]],
            'peso_tara' => 10540,
            'tipo_envase_calculo_neto' => 'bins',
            'observacion' => 'Sellos y guía verificados.',
        ])
            ->assertOk()
            ->assertJsonPath('data.numero_recepcion', 'REC-2607-0001')
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::Cerrado->value)
            ->assertJsonPath('data.peso_tara', 10540)
            ->assertJsonPath('data.peso_neto', 18000)
            ->assertJsonPath('data.tipo_envase_calculo_neto', 'bins')
            ->assertJsonPath('data.cantidad_envase_calculo_neto', 48)
            ->assertJsonPath('data.peso_neto_por_envase', 375)
            ->assertJsonPath('data.aviso_recibo_disponible', true)
            ->json('data');

        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $creada['id'],
            'numero_recepcion' => 'REC-2607-0001',
            'tipo_camion' => 'termo',
            'peso_neto' => 18000,
            'estado' => EstadoRecepcionRomana::Cerrado->value,
        ]);
        $this->assertSame(3, EventoRecepcionRomana::query()->count());
        $this->assertDatabaseCount('folios', 0);
        $this->assertDatabaseCount('validaciones_pallet', 0);
        $this->assertDatabaseCount('procesos_prefrio', 0);

        $cliente->update(['nombre' => 'Nombre modificado posteriormente']);
        $this->getJson('/api/romana/recepciones/'.$creada['id'])
            ->assertOk()
            ->assertJsonPath('data.cliente.nombre', 'Exportadora Los Andes')
            ->assertJsonCount(3, 'data.eventos');

        $pdf = $this->get('/api/romana/recepciones/'.$cerrada['id'].'/aviso-recibo')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="aviso-recibo-rec-2607-0001.pdf"');
        $contenidoPdf = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $contenidoPdf);
        $this->assertStringContainsString('(REGISTRO DE PESAJE)', $contenidoPdf);
        $this->assertStringContainsString('(ROMANA)', $contenidoPdf);
        $this->assertStringContainsString('(RPR-01)', $contenidoPdf);
        $this->assertStringContainsString('(VERSION)', $contenidoPdf);
        $this->assertStringContainsString('(1)', $contenidoPdf);
        $this->assertStringContainsString('(31-08-2026)', $contenidoPdf);
        $this->assertStringNotContainsString('(Tipo de cami', $contenidoPdf);
        $this->assertStringNotContainsString('(Temporada)', $contenidoPdf);

        $gerencia = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $this->actingAs($gerencia, 'sanctum')
            ->getJson('/api/gerencia/resumen')
            ->assertOk()
            ->assertJsonPath('data.romana.cerradas_hoy', 1)
            ->assertJsonPath('data.romana.peso_neto_hoy', 18000)
            ->assertJsonPath('data.romana.envases_hoy', 48)
            ->assertJsonPath('data.romana.clientes_hoy', 1)
            ->assertJsonPath('data.romana.tendencia_diaria.6.recepciones', 1)
            ->assertJsonPath('data.romana.tendencia_diaria.6.peso_neto', 18000);
    }

    public function test_descarga_planilla_de_pesaje_en_blanco_sin_recepcion(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);

        $respuesta = $this->actingAs($operador, 'sanctum')
            ->get('/api/romana/registro-pesaje/en-blanco')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="registro-pesaje-romana-en-blanco.pdf"',
            );

        $contenidoPdf = (string) $respuesta->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $contenidoPdf);
        $this->assertStringContainsString('(REGISTRO DE PESAJE)', $contenidoPdf);
        $this->assertStringContainsString('(ROMANA)', $contenidoPdf);
        $this->assertStringContainsString('(Peso bruto)', $contenidoPdf);
        $this->assertStringNotContainsString('(Tipo de cam', $contenidoPdf);
        $this->assertStringContainsString('(Tara cam', $contenidoPdf);
        $this->assertStringContainsString('(PESO NETO)', $contenidoPdf);
        $this->assertStringContainsString('(RPR-01)', $contenidoPdf);
        $this->assertStringContainsString('/DCTDecode', $contenidoPdf);
        $this->assertStringNotContainsString('(REC-', $contenidoPdf);
    }

    public function test_bloquea_destare_invalido_duplicados_y_edicion_despues_de_confirmar(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $cliente = $this->cliente();
        $datos = $this->datosIngreso($cliente);
        $id = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data.id');

        $edicionValida = $datos;
        $edicionValida['operacion_id'] = (string) Str::uuid();
        $edicionValida['peso_bruto'] = 29000;
        $edicionValida['tipo_camion'] = 'plano';
        $this->putJson('/api/romana/recepciones/'.$id, $edicionValida)
            ->assertOk()
            ->assertJsonPath('data.peso_bruto', 29000)
            ->assertJsonPath('data.tipo_camion', 'plano')
            ->assertJsonPath('data.version', 2);

        $this->postJson('/api/romana/recepciones/'.$id.'/cerrar', [
            'operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'mismos',
            'numero_guia_salida' => 'GS-INVALIDO',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40]],
            'peso_tara' => 10000,
        ])->assertConflict()->assertJsonPath('codigo', 'conflicto_operacional');

        $duplicado = $this->datosIngreso($cliente);
        $duplicado['operacion_id'] = (string) Str::uuid();
        $this->postJson('/api/romana/recepciones', $duplicado)
            ->assertConflict()
            ->assertJsonPath('message', 'La guía de despacho ya fue registrada para este cliente.');

        $this->postJson('/api/romana/recepciones/'.$id.'/confirmar-ingreso', [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();

        $edicion = $edicionValida;
        $edicion['operacion_id'] = (string) Str::uuid();
        $edicion['peso_bruto'] = 30000;
        $this->putJson('/api/romana/recepciones/'.$id, $edicion)
            ->assertConflict()
            ->assertJsonPath('message', 'La recepción ya confirmó su ingreso y sus antecedentes no pueden editarse.');

        $this->marcarEnvasesValidadosParaDestare($id);
        $this->postJson('/api/romana/recepciones/'.$id.'/cerrar', [
            'operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'mismos',
            'numero_guia_salida' => 'GS-INVALIDO-2',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40]],
            'peso_tara' => 29000,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'El peso neto de fruta debe ser mayor que cero.');

        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $id,
            'estado' => EstadoRecepcionRomana::EnBasculaSalida->value,
            'peso_tara' => null,
        ]);
    }

    public function test_descontar_tara_de_envases_si_el_camion_sale_sin_ellos(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        $datos['peso_bruto'] = 30000;
        $datos['envases'] = [
            ['tipo_envase' => 'bins', 'cantidad' => 10],
            ['tipo_envase' => 'totes', 'cantidad' => 20],
            ['tipo_envase' => 'esponjas', 'cantidad' => 5],
        ];

        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->marcarEnvasesValidadosParaDestare($recepcion['id']);
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
            'peso_tara' => 10000,
            'tipo_envase_calculo_neto' => 'bins',
            'modo_salida_envases' => 'vacio',
            'taras_envases' => [
                ['tipo_envase' => 'bins', 'tara_unitaria' => 40],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['taras_envases']);

        $cerrada = $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
            'peso_tara' => 10000,
            'tipo_envase_calculo_neto' => 'bins',
            'modo_salida_envases' => 'vacio',
            'taras_envases' => [
                ['tipo_envase' => 'bins', 'tara_unitaria' => 40],
                ['tipo_envase' => 'totes', 'tara_unitaria' => 2],
                ['tipo_envase' => 'esponjas', 'tara_unitaria' => 1],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::Cerrado->value)
            ->assertJsonPath('data.modo_salida_envases', 'vacio')
            ->assertJsonPath('data.peso_tara', 10000)
            ->assertJsonPath('data.peso_tara_envases', 445)
            ->assertJsonPath('data.peso_tara_total', 10445)
            ->assertJsonPath('data.peso_neto', 19555)
            ->assertJsonPath('data.peso_neto_por_envase', 1955.5)
            ->json('data');

        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $cerrada['id'],
            'tipo_envase' => 'bins',
            'tara_unitaria_salida' => 40,
        ]);
        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $cerrada['id'],
            'tipo_envase' => 'totes',
            'tara_unitaria_salida' => 2,
        ]);
        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $cerrada['id'],
            'tipo_envase' => 'esponjas',
            'tara_unitaria_salida' => 1,
        ]);
        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $cerrada['id'],
            'salida_sin_envases' => false,
            'modo_salida_envases' => 'vacio',
            'peso_tara_envases' => 445,
            'peso_neto' => 19555,
        ]);
    }

    public function test_administrador_corrige_recepcion_cerrada_y_recalcula_pesos_con_trazabilidad(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $datos = $this->datosIngreso($this->cliente());
        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data');

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", [
            'operacion_id' => (string) Str::uuid(),
        ])->assertOk();
        // Expediente histórico cerrado antes de la validación MP: conserva
        // la corrección administrativa existente y sus pesos.
        DB::table('recepciones_romana')->where('id', $recepcion['id'])->update([
            'estado' => 'cerrado', 'peso_tara' => 10540, 'peso_neto' => 18000,
            'tipo_envase_calculo_neto' => 'bins', 'cantidad_envase_calculo_neto' => 48,
            'peso_neto_por_envase' => 375, 'version' => 3,
        ]);
        DB::table('detalles_envases_recepcion_romana')->where('recepcion_romana_id', $recepcion['id'])
            ->update(['tara_unitaria_salida' => 40]);
        $cerrada = $this->getJson("/api/romana/recepciones/{$recepcion['id']}")
            ->assertOk()->assertJsonPath('data.correccion_administrativa_disponible', true)->json('data');

        $correccion = $datos;
        $correccion['operacion_id'] = (string) Str::uuid();
        $correccion['version_conocida'] = $cerrada['version'];
        $correccion['motivo_correccion'] = 'Peso bruto y cantidad de bins digitados incorrectamente.';
        $correccion['peso_bruto'] = 30000;
        $correccion['tipo_camion'] = 'plano';
        $correccion['peso_tara'] = 10000;
        $correccion['tipo_envase_calculo_neto'] = 'bins';
        $correccion['envases'] = [
            ['tipo_envase' => 'bins', 'cantidad' => 60],
        ];

        $this->actingAs($operador, 'sanctum')
            ->putJson("/api/romana/recepciones/{$recepcion['id']}/corregir", $correccion)
            ->assertForbidden();

        $corregida = $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/romana/recepciones/{$recepcion['id']}/corregir", $correccion)
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::Cerrado->value)
            ->assertJsonPath('data.peso_bruto', 30000)
            ->assertJsonPath('data.tipo_camion', 'plano')
            ->assertJsonPath('data.peso_tara', 10000)
            ->assertJsonPath('data.peso_neto', 20000)
            ->assertJsonPath('data.cantidad_envase_calculo_neto', 60)
            ->assertJsonPath('data.peso_neto_por_envase', 333.333)
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.puede_editar', false)
            ->assertJsonPath('data.correccion_administrativa_disponible', true)
            ->assertJsonFragment(['tipo' => 'correccion_administrativa'])
            ->assertJsonFragment(['motivo' => $correccion['motivo_correccion']])
            ->json('data');

        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/corregir", $correccion)
            ->assertOk()
            ->assertJsonPath('data.version', 4);

        $this->assertSame(3, EventoRecepcionRomana::query()->count());
        $evento = EventoRecepcionRomana::query()
            ->where('recepcion_romana_id', $recepcion['id'])
            ->where('tipo', 'correccion_administrativa')
            ->firstOrFail();
        $this->assertSame(28540.0, (float) $evento->datos['anterior']['peso_bruto']);
        $this->assertSame('termo', $evento->datos['anterior']['tipo_camion']);
        $this->assertSame('plano', $evento->datos['posterior']['tipo_camion']);
        $this->assertSame(30000.0, (float) $evento->datos['posterior']['peso_bruto']);
        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $corregida['id'],
            'tipo_camion' => 'plano',
            'peso_bruto' => 30000,
            'peso_tara' => 10000,
            'peso_neto' => 20000,
            'peso_neto_por_envase' => 333.333,
            'version' => 4,
        ]);

        $sinEnvaseCalculado = $correccion;
        $sinEnvaseCalculado['operacion_id'] = (string) Str::uuid();
        $sinEnvaseCalculado['version_conocida'] = 4;
        $sinEnvaseCalculado['envases'] = [
            ['tipo_envase' => 'totes', 'cantidad' => 60],
        ];
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/corregir", $sinEnvaseCalculado)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'No puedes retirar el envase utilizado para calcular el neto individual.',
            );

        $correccion['operacion_id'] = (string) Str::uuid();
        $this->putJson("/api/romana/recepciones/{$recepcion['id']}/corregir", $correccion)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'La recepción cambió desde que abriste el expediente. Actualiza antes de corregir.',
            );
    }

    public function test_bloquea_edicion_en_romana_cuando_validacion_mp_ya_tomo_la_recepcion(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $datos = $this->datosIngreso($this->cliente());
        $recepcionId = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $recepcionId,
            'estado_validacion_mp' => EstadoValidacionMp::Pendiente->value,
        ]);

        RecepcionRomana::query()->findOrFail($recepcionId)->update([
            'estado_validacion_mp' => EstadoValidacionMp::EnCurso,
        ]);
        $edicion = $datos;
        $edicion['operacion_id'] = (string) Str::uuid();
        $edicion['peso_bruto'] = 30000;

        $this->putJson('/api/romana/recepciones/'.$recepcionId, $edicion)
            ->assertConflict()
            ->assertJsonPath('message', 'La recepción ya fue tomada por Validación MP y sus antecedentes no pueden editarse.');

        $edicion['operacion_id'] = (string) Str::uuid();
        $edicion['version_conocida'] = 1;
        $edicion['motivo_correccion'] = 'Corrección solicitada fuera de plazo.';
        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/romana/recepciones/{$recepcionId}/corregir", $edicion)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'La recepción ya fue tomada por Validación MP y no admite correcciones administrativas.',
            );
    }

    public function test_separa_consulta_de_operacion_y_expone_capacidades_en_el_acceso(): void
    {
        $cliente = $this->cliente();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $operador = User::factory()->create([
            'rol' => RolUsuario::OperadorRomana,
            'email' => 'romana@estiba.local',
            'password' => 'password123',
        ]);
        $camarero = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);

        $this->actingAs($consulta, 'sanctum')->getJson('/api/romana/recepciones')->assertOk();
        $this->postJson('/api/romana/recepciones', $this->datosIngreso($cliente))->assertForbidden();
        $this->actingAs($camarero, 'sanctum')->getJson('/api/romana/recepciones')->assertForbidden();

        $this->postJson('/api/acceso-oficina', [
            'email' => $operador->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_consultar_romana', true)
            ->assertJsonPath('usuario.puede_operar_romana', true)
            ->assertJsonPath('usuario.puede_corregir_recepciones_romana', false)
            ->assertJsonPath('usuario.ambito_camaras', 'ninguno');

        $administrador = User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'email' => 'admin-romana@estiba.local',
            'password' => 'password123',
        ]);
        $this->postJson('/api/acceso-oficina', [
            'email' => $administrador->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.puede_corregir_recepciones_romana', true);
    }

    public function test_requiere_un_cliente_operacional_activo_y_un_rut_valido(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $cliente = $this->cliente(false);
        $datos = $this->datosIngreso($cliente);

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cliente_id');

        $cliente->update(['activo' => true]);
        $datos['rut_conductor'] = '12.345.678-9';
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rut_conductor');
    }

    public function test_comparte_temporada_y_cliente_sin_unir_los_flujos_operacionales(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $temporadaId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                ...$this->vigenciaProductiva(),
                'codigo' => '2026-2027',
                'nombre' => 'Temporada 2026-2027',
                'activa' => true,
            ])
            ->assertCreated()
            ->json('data.id');
        $clienteId = $this->postJson('/api/administracion/clientes', [
            'codigo' => 'TRANSVERSAL',
            'nombre' => 'Exportadora Transversal',
            'codigo_externo' => 'CLI-TRANS-01',
            'activo' => true,
        ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(1, Cliente::query()->where('codigo_externo', 'CLI-TRANS-01')->count());
        $this->assertSame(1, Temporada::query()->where('codigo', '2026-2027')->count());
        $this->assertSame(1, Temporada::query()->where('activa', true)->count());
        $this->getJson('/api/romana/catalogos')
            ->assertOk()
            ->assertJsonPath('temporadas.0.id', $temporadaId)
            ->assertJsonPath('clientes.0.id', $clienteId)
            ->assertJsonPath('clientes.0.presente_en_validacion', true)
            ->assertJsonPath('clientes.0.presente_en_materiales', true);
    }

    public function test_romana_muestra_la_temporada_activa_por_defecto_y_conserva_consulta_historica_explicita(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $cliente = $this->cliente();
        $temporadaAnterior = Temporada::query()->where('activa', true)->firstOrFail();

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->datosIngreso($cliente))
            ->assertCreated();

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'ROM-NUEVA',
            'nombre' => 'Temporada nueva de romana',
            'activa' => true,
        ], usuarioId: $operador->id);

        $this->getJson('/api/romana/recepciones')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/romana/recepciones?temporada_id={$temporadaAnterior->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.temporada.id', $temporadaAnterior->id);
    }

    public function test_numera_y_notifica_una_recepcion_solo_de_envases_con_detalle_separado(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-21 16:32:15'));
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $datos = $this->datosIngreso($this->cliente());
        $datos['tipo_recepcion'] = 'solo_envases';
        $datos['fecha_ingreso'] = '2026-07-18';
        $datos['concepto_envases'] = 'arriendo';
        $datos['tipo_servicio'] = null;
        $datos['peso_bruto'] = 28540; // Un cliente antiguo podría seguir enviando el campo.
        $datos['envases'] = [
            ['tipo_envase' => 'bins', 'cantidad' => 120],
            ['tipo_envase' => 'esponjas', 'cantidad' => 800],
        ];

        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->assertJsonPath('data.numero_recepcion', 'REC-2607-0001')
            ->assertJsonPath('data.tipo_recepcion', 'solo_envases')
            ->assertJsonPath('data.fecha_ingreso', '2026-07-18')
            ->assertJsonPath('data.ingreso_at', '2026-07-18T16:32:15+00:00')
            ->assertJsonPath('data.concepto_envases', 'arriendo')
            ->assertJsonPath('data.peso_bruto', null)
            ->assertJsonPath('data.peso_tara', null)
            ->assertJsonPath('data.peso_neto', null)
            ->assertJsonPath('data.estado_validacion_mp', 'pendiente')
            ->assertJsonCount(2, 'data.envases')
            ->json('data');

        $this->assertDatabaseHas('detalles_envases_recepcion_romana', [
            'recepcion_romana_id' => $recepcion['id'],
            'tipo_envase' => 'esponjas',
            'cantidad_declarada' => 800,
            'cantidad_validada' => null,
        ]);
        $eventoIngreso = EventoRecepcionRomana::query()
            ->where('recepcion_romana_id', $recepcion['id'])
            ->firstOrFail();
        $this->assertSame('2026-07-18', $eventoIngreso->datos['fecha_ingreso']);
        $this->assertSame('2026-07-18T16:32:15+00:00', $eventoIngreso->datos['ingreso_at']);
        $this->assertSame('2026-07-21 16:32:15', $eventoIngreso->ocurrido_at->toDateTimeString());
        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/notificaciones-operacionales')
            ->assertOk()
            ->assertJsonPath('data.0.tipo', 'recepcion_romana_creada')
            ->assertJsonPath('data.0.recepcion_romana.numero_recepcion', 'REC-2607-0001')
            ->assertJsonPath('data.0.datos.ingreso_at', '2026-07-18T16:32:15+00:00');

        $this->actingAs($operador, 'sanctum')
            ->getJson('/api/envases/cuenta-corriente/movimientos')
            ->assertOk()
            ->assertJsonPath('resumen.lineas_pendientes_validacion', 2)
            ->assertJsonPath('pendientes.0.numero_recepcion', 'REC-2607-0001');

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/confirmar-ingreso", [
            'operacion_id' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::EnBasculaSalida->value);

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::Cerrado->value)
            ->assertJsonPath('data.peso_bruto', null)
            ->assertJsonPath('data.peso_tara', null)
            ->assertJsonPath('data.peso_neto', null)
            ->assertJsonPath('data.peso_tara_total', null)
            ->assertJsonPath('data.aviso_recibo_disponible', true);

        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $recepcion['id'],
            'peso_bruto' => null,
            'peso_tara' => null,
            'peso_neto' => null,
            'estado' => EstadoRecepcionRomana::Cerrado->value,
            'ingreso_at' => '2026-07-18 16:32:15',
        ]);
    }

    public function test_fecha_manual_se_limita_a_recepciones_solo_envases_y_no_admite_futuro(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-21 16:32:15'));
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        $datos['tipo_recepcion'] = 'solo_envases';
        $datos['concepto_envases'] = 'compra';
        $datos['tipo_servicio'] = null;
        $datos['peso_bruto'] = null;

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_ingreso');

        $datos['fecha_ingreso'] = '2026-07-22';
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fecha_ingreso');

        $datos['tipo_recepcion'] = 'fruta_con_envases';
        $datos['concepto_envases'] = null;
        $datos['tipo_servicio'] = 'prefrio';
        $datos['peso_bruto'] = 28540;
        $datos['fecha_ingreso'] = '2026-07-18';
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->assertJsonPath('data.fecha_ingreso', null)
            ->assertJsonPath('data.ingreso_at', '2026-07-21T16:32:15+00:00');
    }

    public function test_oculta_notificaciones_de_recepciones_de_temporadas_anteriores(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $this->datosIngreso($this->cliente()))
            ->assertCreated();
        $this->actingAs($validador, 'sanctum')
            ->getJson('/api/notificaciones-operacionales')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'NOT-NUEVA',
            'nombre' => 'Temporada nueva de notificaciones',
            'activa' => true,
        ], usuarioId: $operador->id);

        $this->getJson('/api/notificaciones-operacionales')
            ->assertOk()
            ->assertJsonPath('resumen.no_leidas', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_pesa_todos_los_envases_en_tandas_y_cierra_con_neto_acumulado(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        $datos['tipo_recepcion'] = 'fruta_pesaje_envases';
        $datos['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 5]];
        $datos['tipo_envase_pesaje'] = 'bins';
        $datos['tara_unitaria_envase'] = 40;
        unset($datos['peso_bruto']);

        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::EnPesajeEnvases->value)
            ->assertJsonPath('data.peso_bruto', 0)
            ->assertJsonPath('data.pesaje_envases.tipo_envase', 'bins')
            ->assertJsonPath('data.pesaje_envases.tara_unitaria', 40)
            ->assertJsonPath('data.pesaje_envases.cantidad_declarada', 5)
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 0)
            ->assertJsonPath('data.pesaje_envases.cantidad_pendiente', 5)
            ->assertJsonPath('data.puede_confirmar_ingreso', false)
            ->assertJsonPath('data.puede_registrar_pesaje', true)
            ->assertJsonPath('data.puede_cerrar', false)
            ->json('data');

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Faltan 5 envases por pesar antes de cerrar la recepción.');

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 1,
            'peso_bruto' => 40,
        ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'El peso bruto del grupo debe ser mayor que la tara total de sus envases.',
            );

        $primeraOperacion = (string) Str::uuid();
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => $primeraOperacion,
            'cantidad_envases' => 1,
            'peso_bruto' => 440,
        ])
            ->assertOk()
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 1)
            ->assertJsonPath('data.peso_bruto', 440)
            ->assertJsonPath('data.peso_tara', 40)
            ->assertJsonPath('data.peso_neto', 400)
            ->assertJsonPath('data.peso_neto_por_envase', 400)
            ->assertJsonPath('data.lecturas_pesaje_envases.0.cantidad_envases', 1);

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => $primeraOperacion,
            'cantidad_envases' => 1,
            'peso_bruto' => 440,
        ])
            ->assertOk()
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 1);

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 3,
            'peso_bruto' => 1260,
            'observacion' => 'Tanda de tres bins.',
        ])
            ->assertOk()
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 4)
            ->assertJsonPath('data.pesaje_envases.cantidad_pendiente', 1)
            ->assertJsonPath('data.peso_bruto', 1700)
            ->assertJsonPath('data.peso_tara', 160)
            ->assertJsonPath('data.peso_neto', 1540)
            ->assertJsonPath('data.peso_neto_por_envase', 385);

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 2,
            'peso_bruto' => 820,
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'La lectura supera los 1 envases pendientes de pesaje.');

        $completa = $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 1,
            'peso_bruto' => 410,
        ])
            ->assertOk()
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 5)
            ->assertJsonPath('data.pesaje_envases.cantidad_pendiente', 0)
            ->assertJsonPath('data.pesaje_envases.completo', true)
            ->assertJsonPath('data.peso_bruto', 2110)
            ->assertJsonPath('data.peso_tara', 200)
            ->assertJsonPath('data.peso_neto', 1910)
            ->assertJsonPath('data.peso_neto_por_envase', 382)
            ->assertJsonPath('data.puede_cerrar', true)
            ->json('data');

        $cerrada = $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
            'observacion' => 'Pesaje completo de los cinco bins.',
        ])
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoRecepcionRomana::Cerrado->value)
            ->assertJsonPath('data.peso_neto', 1910)
            ->assertJsonPath('data.puede_registrar_pesaje', false)
            ->assertJsonPath('data.aviso_recibo_disponible', true)
            ->json('data');

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 1,
            'peso_bruto' => 400,
        ])->assertConflict();
        $this->assertDatabaseCount('pesajes_envases_recepcion_romana', 3);
        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $cerrada['id'],
            'cantidad_envases_pesados' => 5,
            'peso_neto' => 1910,
            'estado' => EstadoRecepcionRomana::Cerrado->value,
        ]);
    }

    public function test_anula_una_tanda_y_recalcula_el_avance_antes_del_cierre(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        $datos['tipo_recepcion'] = 'fruta_pesaje_envases';
        $datos['envases'] = [['tipo_envase' => 'totes', 'cantidad' => 3]];
        $datos['tipo_envase_pesaje'] = 'totes';
        $datos['tara_unitaria_envase'] = 2.5;
        unset($datos['peso_bruto']);

        $recepcion = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data');
        $pesada = $this->postJson("/api/romana/recepciones/{$recepcion['id']}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(),
            'cantidad_envases' => 3,
            'peso_bruto' => 307.5,
        ])
            ->assertOk()
            ->assertJsonPath('data.peso_tara', 7.5)
            ->assertJsonPath('data.peso_neto', 300)
            ->json('data');
        $lecturaId = $pesada['lecturas_pesaje_envases'][0]['id'];
        $operacionAnulacion = (string) Str::uuid();
        $payloadAnulacion = [
            'operacion_id' => $operacionAnulacion,
            'motivo' => 'Lectura digitada con un decimal incorrecto.',
        ];

        $this->postJson(
            "/api/romana/recepciones/{$recepcion['id']}/pesajes-envases/{$lecturaId}/anular",
            $payloadAnulacion,
        )
            ->assertOk()
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 0)
            ->assertJsonPath('data.pesaje_envases.cantidad_pendiente', 3)
            ->assertJsonPath('data.peso_bruto', 0)
            ->assertJsonPath('data.peso_tara', 0)
            ->assertJsonPath('data.peso_neto', 0)
            ->assertJsonPath('data.lecturas_pesaje_envases.0.anulado', true);

        $this->postJson(
            "/api/romana/recepciones/{$recepcion['id']}/pesajes-envases/{$lecturaId}/anular",
            $payloadAnulacion,
        )
            ->assertOk()
            ->assertJsonPath('data.pesaje_envases.cantidad_pesada', 0);
    }

    public function test_pesaje_acumulativo_exige_un_solo_envase_coincidente_y_su_tara(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $datos = $this->datosIngreso($this->cliente());
        $datos['tipo_recepcion'] = 'fruta_pesaje_envases';
        $datos['envases'] = [
            ['tipo_envase' => 'bins', 'cantidad' => 5],
            ['tipo_envase' => 'totes', 'cantidad' => 2],
        ];
        $datos['tipo_envase_pesaje'] = 'bins';
        unset($datos['peso_bruto']);

        $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['envases', 'tara_unitaria_envase']);

        $datos['envases'] = [['tipo_envase' => 'bins', 'cantidad' => 5]];
        $datos['tipo_envase_pesaje'] = 'totes';
        $datos['tara_unitaria_envase'] = 3;
        $this->postJson('/api/romana/recepciones', $datos)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo_envase_pesaje');
    }

    public function test_no_edita_una_recepcion_de_temporada_inactiva_ni_la_traslada_a_la_activa(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $cliente = $this->cliente();
        $datos = $this->datosIngreso($cliente);
        $temporadaOriginal = $datos['temporada_id'];
        $id = $this->actingAs($operador, 'sanctum')
            ->postJson('/api/romana/recepciones', $datos)
            ->assertCreated()
            ->json('data.id');

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'ROM-NUEVA',
            'nombre' => 'Temporada nueva de Romana',
            'activa' => true,
        ], usuarioId: $operador->id);

        // El formulario envía la temporada activa: antes, eso trasladaba la recepción.
        $edicion = $datos;
        $edicion['operacion_id'] = (string) Str::uuid();
        $edicion['temporada_id'] = Temporada::query()->where('activa', true)->value('id');
        $edicion['peso_bruto'] = 29500;
        $this->actingAs($operador, 'sanctum')
            ->putJson('/api/romana/recepciones/'.$id, $edicion)
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa');

        $this->assertDatabaseHas('recepciones_romana', [
            'id' => $id,
            'temporada_id' => $temporadaOriginal,
            'version' => 1,
        ]);
    }

    private function cliente(bool $activo = true): Cliente
    {
        return Cliente::create([
            'codigo' => 'ELA-01',
            'nombre' => 'Exportadora Los Andes',
            'codigo_externo' => 'ELA-01',
            'activo' => $activo,
        ]);
    }

    /** @return array<string, mixed> */
    private function datosIngreso(Cliente $cliente): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'temporada_id' => Temporada::query()->where('activa', true)->firstOrFail()->id,
            'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'fruta_con_envases',
            'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => Temporada::query()->where('activa', true)->firstOrFail()->id, 'nombre' => 'Cereza'], ['activo' => true])->id,
            'concepto_envases' => null,
            'tipo_servicio' => 'prefrio',
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 48],
            ],
            'numero_guia_despacho' => 'GD-77881',
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'termo',
            'patente_carro' => 'WXYZ34',
            'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'María González',
            'peso_bruto' => 28540,
            'observacion' => 'Carga sellada en origen.',
        ];
    }

    private function marcarEnvasesValidadosParaDestare(string $recepcionId): void
    {
        // Estos casos prueban Romana; el flujo real de validación se cubre por API
        // en ValidacionMpApiTest. La fixture conserva los datos MP inmutables.
        DB::table('detalles_envases_recepcion_romana')->where('recepcion_romana_id', $recepcionId)
            ->update(['cantidad_validada' => DB::raw('cantidad_declarada')]);
        DB::table('recepciones_romana')->where('id', $recepcionId)
            ->update(['estado_validacion_mp' => EstadoValidacionMp::Validada->value]);
    }
}
