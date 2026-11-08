<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\PersonaFrigorifico;
use App\Models\User;
use App\Services\Frigorifico\ControlPersonal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ControlPersonalFrigorificoTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private string $ruta = '/api/frigorifico/control-personal';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06T12:00:00Z'));
        $this->supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $this->actingAs($this->supervisor, 'sanctum');
    }

    private function persona(array $cambios = []): array
    {
        return $this->postJson($this->ruta.'/personas', [...[
            'nombre' => 'Ana de prueba', 'identificador' => 'INT-001', 'sector' => 'Cámaras', 'funcion' => 'Camarera',
            'jornada_base_minutos' => 480, 'activo' => true,
        ], ...$cambios])->assertCreated()->json('data');
    }

    private function jornada(string $personaId, array $cambios = []): array
    {
        return [...['persona_id' => $personaId, 'ingreso' => '2026-11-04T20:00', 'salida' => '2026-11-05T06:17', 'colacion_minutos' => 60], ...$cambios];
    }

    public function test_turno_nocturno_se_resume_y_exporta_con_minutos_exactos(): void
    {
        $p = $this->persona();
        $this->postJson($this->ruta.'/jornadas', $this->jornada($p['id']))->assertCreated();
        $r = $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $r->assertJsonPath('data.resumen.0.minutos_trabajados', 557)->assertJsonPath('data.resumen.0.minutos_extra', 77)
            ->assertJsonPath('data.jornadas.0.ingreso', '2026-11-04T20:00')->assertJsonPath('data.resumen.0.jornadas_cerradas', 1);
        foreach (['resumen', 'detalle'] as $vista) {
            $response = $this->get($this->ruta.'/exportar?mes=2026-11&vista='.$vista)->assertOk();
            $zip = new \ZipArchive;
            $zip->open($response->baseResponse->getFile()->getPathname());
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $this->assertStringContainsString('01:17', $xml);
            $this->assertStringContainsString('Ana de prueba', $xml);
            $zip->close();
        }
    }

    public function test_no_expone_personal_ni_exportaciones_a_operadores_o_perfiles_de_consulta(): void
    {
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk();
        foreach (RolUsuario::cases() as $rol) {
            $usuario = User::factory()->create(['rol' => $rol, 'activo' => true]);
            $this->actingAs($usuario, 'sanctum');
            $esperado = in_array($rol, [RolUsuario::Administrador, RolUsuario::SupervisorFrio], true) ? 200 : 403;
            $this->getJson($this->ruta.'?mes=2026-11')->assertStatus($esperado);
            if ($esperado === 403) {
                $this->getJson($this->ruta.'/exportar?mes=2026-11')->assertForbidden();
                $this->postJson($this->ruta.'/personas', [])->assertForbidden();
                $this->postJson($this->ruta.'/jornadas', [])->assertForbidden();
                $this->putJson($this->ruta.'/jornadas/inexistente', [])->assertForbidden();
                $this->postJson($this->ruta.'/jornadas/inexistente/anular', [])->assertForbidden();
                $this->getJson($this->ruta.'/jornadas/inexistente/historial')->assertForbidden();
                $this->get($this->ruta.'/ficha?mes=2026-11')->assertForbidden();
                $this->postJson($this->ruta.'/ausencias', [])->assertForbidden();
                $this->putJson($this->ruta.'/ausencias/inexistente', [])->assertForbidden();
                $this->postJson($this->ruta.'/ausencias/inexistente/anular', [])->assertForbidden();
                $this->getJson($this->ruta.'/ausencias/inexistente/historial')->assertForbidden();
            }
        }
        $this->supervisor->update(['activo' => false]);
        $this->actingAs($this->supervisor->fresh(), 'sanctum');
        $this->getJson($this->ruta.'?mes=2026-11')->assertForbidden();
        $this->supervisor->update(['activo' => true]);
        // Ni asignar el módulo a un perfil operativo convierte al operador en supervisor.
        $perfil = PerfilAcceso::create(['codigo' => 'CONTROL-TEST', 'nombre' => 'Operador con módulo', 'rol_base' => RolUsuario::CamareroFrio,
            'modulos' => ['frigorifico.control-personal'], 'modulos_tablet' => [], 'activo' => true]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'perfil_acceso_id' => $perfil->id]), 'sanctum');
        $this->getJson($this->ruta.'?mes=2026-11')->assertForbidden();
        $perfil->update(['rol_base' => RolUsuario::Consulta]);
        $this->supervisor->update(['perfil_acceso_id' => $perfil->id]);
        $this->actingAs($this->supervisor->fresh(), 'sanctum');
        $this->getJson($this->ruta.'?mes=2026-11')->assertForbidden();
        $perfil->update(['rol_base' => RolUsuario::SupervisorFrio, 'modulos' => []]);
        $this->actingAs($this->supervisor->fresh(), 'sanctum');
        $this->getJson($this->ruta.'?mes=2026-11')->assertForbidden();
        $perfil->update(['modulos' => ['frigorifico.control-personal'], 'activo' => false]);
        $this->actingAs($this->supervisor->fresh(), 'sanctum');
        $this->getJson($this->ruta.'?mes=2026-11')->assertForbidden();
    }

    public function test_pendientes_cierre_correccion_anulacion_y_conflictos_conservan_auditoria(): void
    {
        $p = $this->persona();
        $datos = $this->jornada($p['id'], ['salida' => null]);
        $j = $this->postJson($this->ruta.'/jornadas', $datos)->assertCreated()->json('data');
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonPath('data.resumen.0.pendientes', 1)
            ->assertJsonPath('data.resumen.0.minutos_extra', 0)->assertJsonPath('data.jornadas.0.minutos_trabajados', null);
        $cerrar = [...$datos, 'salida' => '2026-11-05T06:17', 'version' => 1, 'motivo' => 'Salida informada por supervisor'];
        $this->putJson($this->ruta.'/jornadas/'.$j['id'], $cerrar)->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->ruta.'/jornadas/'.$j['id'], $cerrar)->assertStatus(409);
        $this->putJson($this->ruta.'/jornadas/'.$j['id'], [...$cerrar, 'version' => 2, 'jornada_base_minutos' => 0, 'motivo' => 'Jornada extraordinaria completa'])
            ->assertOk()->assertJsonPath('data.version', 3);
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonPath('data.resumen.0.minutos_extra', 557);
        $this->postJson($this->ruta.'/jornadas/'.$j['id'].'/anular', ['version' => 2, 'motivo' => 'Registro equivocado'])->assertStatus(409);
        $this->postJson($this->ruta.'/jornadas/'.$j['id'].'/anular', ['version' => 3, 'motivo' => 'Registro equivocado'])->assertOk();
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonCount(0, 'data.resumen')->assertJsonPath('data.jornadas.0.estado', 'anulada');
        $this->getJson($this->ruta.'/jornadas/'.$j['id'].'/historial')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.motivo', 'Registro equivocado')->assertJsonPath('data.1.anterior.jornada_base_minutos', 480)
            ->assertJsonPath('data.1.nuevo.jornada_base_minutos', 0)->assertJsonPath('data.0.user_id', $this->supervisor->id);
        $this->putJson($this->ruta.'/jornadas/'.$j['id'], [...$cerrar, 'version' => 4])->assertUnprocessable();
    }

    public function test_rechaza_superposiciones_y_conserva_base_historica_al_cambiar_o_desactivar_persona(): void
    {
        $p = $this->persona();
        $datos = $this->jornada($p['id'], ['salida' => null]);
        $j = $this->postJson($this->ruta.'/jornadas', $datos)->assertCreated()->json('data');
        $this->postJson($this->ruta.'/jornadas', [...$datos, 'ingreso' => '2026-11-05T08:00'])->assertUnprocessable()->assertJsonValidationErrors('ingreso');
        $this->putJson($this->ruta.'/personas/'.$p['id'], [...$p, 'version' => 1, 'jornada_base_minutos' => 420, 'activo' => false])->assertOk();
        $this->putJson($this->ruta.'/personas/'.$p['id'], [...$p, 'version' => 1])->assertStatus(409);
        $this->putJson($this->ruta.'/jornadas/'.$j['id'], [...$datos, 'salida' => '2026-11-05T06:17', 'version' => 1, 'motivo' => 'Cierre pendiente'])
            ->assertOk()->assertJsonPath('data.jornada_base_minutos', 480);
        $this->postJson($this->ruta.'/jornadas', $this->jornada($p['id'], ['ingreso' => '2026-11-05T20:00', 'salida' => null]))->assertUnprocessable();
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonPath('data.resumen.0.minutos_extra', 77);
        $this->assertSame(2, DB::table('frigorifico_control_personal_eventos')->whereNull('jornada_id')->count());
    }

    public function test_imputa_cruce_de_mes_al_ingreso_y_filtra_por_sector_historico_y_persona(): void
    {
        $p = $this->persona();
        $otra = $this->persona(['identificador' => 'INT-002', 'nombre' => 'Otra persona', 'sector' => 'Prefrío']);
        $this->postJson($this->ruta.'/jornadas', $this->jornada($p['id'], ['ingreso' => '2026-10-31T20:00', 'salida' => '2026-11-01T06:00']))->assertCreated();
        $this->postJson($this->ruta.'/jornadas', $this->jornada($otra['id']))->assertCreated();
        $this->getJson($this->ruta.'?mes=2026-10&persona_id='.$p['id'])->assertOk()->assertJsonPath('data.resumen.0.minutos_extra', 60);
        $this->getJson($this->ruta.'?mes=2026-11&persona_id='.$p['id'])->assertOk()->assertJsonCount(0, 'data.jornadas');
        $this->getJson($this->ruta.'?mes=2026-11&sector=Prefr%C3%ADo')->assertOk()->assertJsonCount(1, 'data.jornadas')->assertJsonPath('data.resumen.0.persona_id', $otra['id']);
        $this->getJson($this->ruta.'?mes=2026-13')->assertUnprocessable();
    }

    private function ausencia(string $personaId, array $cambios = []): array
    {
        return [...['persona_id' => $personaId, 'tipo' => 'libre', 'desde' => '2026-11-02', 'hasta' => '2026-11-02', 'observacion' => null], ...$cambios];
    }

    private function hojaExcel(string $url): string
    {
        $response = $this->get($url)->assertOk();
        $zip = new \ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        return (string) $xml;
    }

    public function test_ausencias_por_rango_suman_dias_del_mes_sin_chocar_con_jornadas_ni_entre_si(): void
    {
        $p = $this->persona();
        $this->postJson($this->ruta.'/jornadas', $this->jornada($p['id']))->assertCreated();
        // El día de ingreso de una jornada vigente no admite ausencia.
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['desde' => '2026-11-04', 'hasta' => '2026-11-04']))
            ->assertUnprocessable()->assertJsonValidationErrors('desde');
        $a = $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'vacaciones', 'desde' => '2026-10-28', 'hasta' => '2026-11-02']))
            ->assertCreated()->json('data');
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonPath('data.resumen.0.ausencias.vacaciones', 2)
            ->assertJsonPath('data.resumen.0.dias_ausencia', 2)->assertJsonPath('data.ausencias.0.dias_mes', 2)
            ->assertJsonPath('data.ausencias.0.dias_total', 6)->assertJsonPath('data.ausencias.0.tipo_nombre', 'Vacaciones');
        // Una persona con solo ausencias en el mes también aparece en el resumen.
        $this->getJson($this->ruta.'?mes=2026-10')->assertOk()->assertJsonPath('data.resumen.0.ausencias.vacaciones', 4)
            ->assertJsonPath('data.resumen.0.minutos_trabajados', 0);
        // Ni jornadas dentro de la ausencia ni ausencias superpuestas.
        $this->postJson($this->ruta.'/jornadas', $this->jornada($p['id'], ['ingreso' => '2026-11-01T08:00', 'salida' => '2026-11-01T17:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('ingreso');
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['desde' => '2026-11-02', 'hasta' => '2026-11-03']))
            ->assertUnprocessable()->assertJsonValidationErrors('desde');
        // Validaciones de fechas: orden, largo máximo y faltas sin anticipar.
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['desde' => '2026-11-10', 'hasta' => '2026-11-09']))
            ->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'licencia', 'desde' => '2026-11-10', 'hasta' => '2027-02-10']))
            ->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'falta', 'desde' => '2026-11-07', 'hasta' => '2026-11-07']))
            ->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'falta', 'desde' => '2026-11-06', 'hasta' => '2026-11-06']))->assertCreated();
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'turno']))->assertUnprocessable()->assertJsonValidationErrors('tipo');
        // Corrección y anulación con versión, motivo e historial propio.
        $corregir = [...$this->ausencia($p['id'], ['tipo' => 'vacaciones', 'desde' => '2026-10-28', 'hasta' => '2026-11-01']), 'version' => 1];
        $this->putJson($this->ruta.'/ausencias/'.$a['id'], $corregir)->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->putJson($this->ruta.'/ausencias/'.$a['id'], [...$corregir, 'motivo' => 'Volvió un día antes'])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson($this->ruta.'/ausencias/'.$a['id'], [...$corregir, 'motivo' => 'Reintento'])->assertStatus(409);
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonPath('data.resumen.0.ausencias.vacaciones', 1)
            ->assertJsonPath('data.resumen.0.ausencias.falta', 1)->assertJsonPath('data.resumen.0.dias_ausencia', 2);
        $resumen = $this->hojaExcel($this->ruta.'/exportar?mes=2026-11&vista=resumen');
        $this->assertStringContainsString('Vacaciones (días)', $resumen);
        $this->postJson($this->ruta.'/ausencias/'.$a['id'].'/anular', ['version' => 2, 'motivo' => 'Se registró en la persona equivocada'])->assertOk();
        $this->postJson($this->ruta.'/ausencias/'.$a['id'].'/anular', ['version' => 3, 'motivo' => 'Otra vez'])->assertStatus(409);
        $this->putJson($this->ruta.'/ausencias/'.$a['id'], [...$corregir, 'version' => 3, 'motivo' => 'Revivir'])->assertUnprocessable();
        $this->getJson($this->ruta.'?mes=2026-11')->assertOk()->assertJsonPath('data.resumen.0.ausencias.vacaciones', 0)
            ->assertJsonPath('data.ausencias.1.estado', 'anulada');
        $this->getJson($this->ruta.'/ausencias/'.$a['id'].'/historial')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.accion', 'ausencia_anulada')->assertJsonPath('data.1.anterior.hasta', '2026-11-02')
            ->assertJsonPath('data.1.nuevo.hasta', '2026-11-01')->assertJsonPath('data.0.user_id', $this->supervisor->id);
        // Anulada, el día vuelve a admitir jornada.
        $this->postJson($this->ruta.'/jornadas', $this->jornada($p['id'], ['ingreso' => '2026-11-01T08:00', 'salida' => '2026-11-01T17:00']))->assertCreated();
        $ausencias = $this->hojaExcel($this->ruta.'/exportar?mes=2026-11&vista=ausencias');
        $this->assertStringContainsString('Vacaciones', $ausencias);
        $this->assertStringContainsString('28-10-2026', $ausencias);
    }

    public function test_cuenta_dias_de_ausencia_sin_saltar_dias_con_el_cambio_de_horario(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-01T12:00:00Z'));
        $p = $this->persona();
        // Chile adelanta la hora el primer domingo de septiembre y la atrasa el primer domingo de abril.
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'licencia', 'desde' => '2026-09-05', 'hasta' => '2026-09-07']))->assertCreated();
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($p['id'], ['tipo' => 'libre', 'desde' => '2026-04-04', 'hasta' => '2026-04-06']))->assertCreated();
        $this->getJson($this->ruta.'?mes=2026-09')->assertOk()->assertJsonPath('data.resumen.0.ausencias.licencia', 3);
        $this->getJson($this->ruta.'?mes=2026-04')->assertOk()->assertJsonPath('data.resumen.0.ausencias.libre', 3);
        $persona = PersonaFrigorifico::query()->findOrFail($p['id']);
        $ficha = app(ControlPersonal::class)->ficha('2026-09', $persona);
        $this->assertCount(30, $ficha['dias']);
        $this->assertSame(['2026-09-05', '2026-09-06', '2026-09-07'], array_column(array_filter($ficha['dias'], fn ($d) => $d['ausencia'] !== null), 'fecha'));
        $this->assertSame(2, $ficha['dias'][0]['dia_semana']); // 01-09-2026 es martes
    }

    public function test_ficha_pdf_dia_a_dia_por_persona_y_de_todas_las_personas_con_registros(): void
    {
        $ana = $this->persona();
        $beto = $this->persona(['identificador' => 'INT-002', 'nombre' => 'Beto de prueba', 'sector' => 'Prefrío']);
        $this->persona(['identificador' => 'INT-003', 'nombre' => 'Carla sin registros']);
        $this->postJson($this->ruta.'/jornadas', $this->jornada($ana['id'], ['observacion' => 'Cubrió turno de despacho']))->assertCreated();
        $this->postJson($this->ruta.'/jornadas', $this->jornada($ana['id'], ['ingreso' => '2026-11-05T20:00', 'salida' => null]))->assertCreated();
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($ana['id']))->assertCreated();
        $this->postJson($this->ruta.'/ausencias', $this->ausencia($beto['id'], ['tipo' => 'permiso', 'observacion' => 'Trámite personal']))->assertCreated();

        $pdf = $this->get($this->ruta.'/ficha?mes=2026-11&persona_id='.$ana['id'])->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('ficha-personal-2026-11-ana-de-prueba.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $contenido = $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $contenido);
        foreach (['FICHA MENSUAL DE JORNADAS', 'Noviembre 2026', 'Ana de prueba', 'mi'.chr(0xE9).' 04-11', '06:17 +1', '01:17', 'Sin salida',
            'Cubri'.chr(0xF3).' turno de despacho', 'Libre', 'Sin registro', 'Revisi'.chr(0xF3).'n con el trabajador', 'Hoja 1 de'] as $texto) {
            $this->assertStringContainsString($texto, $contenido, $texto);
        }
        $this->assertStringNotContainsString('Beto de prueba', $contenido);

        $todas = $this->get($this->ruta.'/ficha?mes=2026-11')->assertOk()->getContent();
        $this->assertStringContainsString('Ana de prueba', $todas);
        $this->assertStringContainsString('Beto de prueba', $todas);
        $this->assertStringContainsString('Tr'.chr(0xE1).'mite personal', $todas);
        $this->assertStringNotContainsString('Carla sin registros', $todas);
        $this->assertGreaterThan(substr_count($contenido, '/Type /Page '), substr_count($todas, '/Type /Page '));
        $soloPrefrio = $this->get($this->ruta.'/ficha?mes=2026-11&sector=Prefr%C3%ADo')->assertOk()->getContent();
        $this->assertStringNotContainsString('Ana de prueba', $soloPrefrio);
        $this->getJson($this->ruta.'/ficha?mes=2026-09')->assertUnprocessable()->assertJsonValidationErrors('mes');
    }
}
