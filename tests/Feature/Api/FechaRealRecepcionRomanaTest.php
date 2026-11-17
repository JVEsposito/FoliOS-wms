<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Enums\TipoMovimientoEnvase;
use App\Models\Camara;
use App\Models\EventoRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Jornadas\CalendarioOperativo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

class FechaRealRecepcionRomanaTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaRecepcionValidadaCorregible;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.operational_timezone' => 'America/Santiago']);
        // Jornada de planta 07:00–06:59, turno B desde las 19:00, vigente desde antes de los casos.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00', 'America/Santiago'));
        app(CalendarioOperativo::class)->publicar([
            'vigente_desde' => '2026-11-02', 'inicio_jornada' => '07:00', 'inicio_turno_b' => '19:00', 'version_conocida' => 0,
        ], User::factory()->create(['rol' => RolUsuario::Administrador])->id);
    }

    /** El camión de la noche del 16-11 se digitó la mañana del 17-11 (hora de planta, UTC-3). */
    private function recepcionDigitadaAlDiaSiguiente(): RecepcionRomana
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:15', 'America/Santiago'));
        [$recepcion] = $this->recepcionCerradaConDosGuias();

        return $recepcion->refresh();
    }

    private function digitadora(): User
    {
        return User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
    }

    private function datos(RecepcionRomana $recepcion, array $cambios = []): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $recepcion->version,
            'ingreso_fecha' => '2026-11-16', 'ingreso_hora' => '23:30',
            'salida_fecha' => '2026-11-17', 'salida_hora' => '01:10',
            'motivo' => 'Camión de la noche digitado al día siguiente.',
            ...$cambios,
        ];
    }

    public function test_la_recepcion_digitada_al_dia_siguiente_vuelve_a_su_jornada_con_sus_envases(): void
    {
        $recepcion = $this->recepcionDigitadaAlDiaSiguiente();
        $registrada = $recepcion->created_at->toAtomString();
        $ingresos = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)
            ->where('ocurrido_at', $recepcion->ingreso_at)->where('signo_existencia', 1)->count();
        $salidas = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::SalidaRecepcionFruta->value)->count();
        $this->assertGreaterThan(0, $ingresos);
        $this->assertGreaterThan(0, $salidas);
        $this->assertSame('2026-11-17', $this->getJson("/api/romana/recepciones/{$recepcion->id}")->json('data.fecha_jornada'));

        // Un ajuste de cuenta se fecha un segundo después del ingreso; una corrección, cuando se hizo.
        $base = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('signo_existencia', 1)->firstOrFail();
        $ajuste = $base->replicate(['operacion_id']);
        $ajuste->fill(['operacion_id' => (string) Str::uuid(), 'tipo_movimiento' => TipoMovimientoEnvase::AjusteCuenta,
            'signo_existencia' => 0, 'ingreso_at' => null, 'ocurrido_at' => $recepcion->ingreso_at->copy()->addSecond(),
            'datos' => ['ajuste_recepcion' => ['lado' => 'recibidos']]])->save();
        $correccion = $base->replicate(['operacion_id']);
        $correccion->fill(['operacion_id' => (string) Str::uuid(), 'tipo_movimiento' => TipoMovimientoEnvase::CorreccionCantidad,
            'ingreso_at' => null, 'ocurrido_at' => $recepcion->ingreso_at->copy()->addHour()])->save();

        $this->actingAs($this->digitadora(), 'sanctum');
        $detalle = $this->getJson("/api/romana/recepciones/{$recepcion->id}")->assertOk()
            ->assertJsonPath('data.puede_corregir_fecha_real', true)->json('data');
        $this->assertSame($registrada, $detalle['registrado_at']);

        $datos = $this->datos($recepcion);
        $corregida = $this->putJson("/api/romana/recepciones/{$recepcion->id}/fecha-real", $datos)->assertOk()
            ->assertJsonPath('data.ingreso_at', '2026-11-17T02:30:00+00:00')
            ->assertJsonPath('data.salida_at', '2026-11-17T04:10:00+00:00')
            ->assertJsonPath('data.fecha_jornada', '2026-11-16')
            ->assertJsonPath('data.turno_operativo', 'B')
            ->assertJsonPath('data.registrado_at', $registrada)
            ->assertJsonPath('data.version', $recepcion->version + 1)
            ->json('data');

        // Los envases recibidos y devueltos quedan con la hora del camión; nada queda en el 17 a las 09:15.
        $this->assertSame($ingresos, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)
            ->where('ocurrido_at', '2026-11-17 02:30:00')->where('ingreso_at', '2026-11-17 02:30:00')->count());
        $this->assertSame($salidas, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::SalidaRecepcionFruta->value)
            ->where('ocurrido_at', '2026-11-17 04:10:00')->where('salida_at', '2026-11-17 04:10:00')->count());
        $this->assertSame(0, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)
            ->where('ocurrido_at', $recepcion->ingreso_at)->count());
        $this->assertSame('2026-11-17 02:30:01', $ajuste->refresh()->ocurrido_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-17 13:15:00', $correccion->refresh()->ocurrido_at->format('Y-m-d H:i:s'));

        $evento = EventoRecepcionRomana::where('operacion_id', $datos['operacion_id'])->sole();
        $this->assertSame('fecha_real_corregida', $evento->tipo->value);
        $this->assertSame('Camión de la noche digitado al día siguiente.', $evento->datos['motivo']);
        $this->assertSame('2026-11-17T12:15:00+00:00', $evento->datos['anterior']['ingreso_at']);
        $this->assertSame('2026-11-17T02:30:00+00:00', $evento->datos['posterior']['ingreso_at']);
        $this->assertEquals(['ingreso' => $ingresos + 1, 'salida' => $salidas], $evento->datos['movimientos_envases']);

        // Reintento idéntico: misma respuesta; otro contenido con el mismo identificador: conflicto.
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/fecha-real", $datos)->assertOk()
            ->assertJsonPath('data.version', $corregida['version']);
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/fecha-real", [...$datos, 'ingreso_hora' => '23:00'])->assertConflict();
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'fecha_real_corregida')->count());

        // Los informes por fecha de Romana la muestran el día del camión.
        $this->getJson('/api/romana/recepciones?desde=2026-11-16&hasta=2026-11-16')->assertOk()
            ->assertJsonFragment(['numero_recepcion' => $recepcion->numero_recepcion]);
        $this->assertNotContains($recepcion->numero_recepcion, collect(
            $this->getJson('/api/romana/recepciones?desde=2026-11-17&hasta=2026-11-17')->assertOk()->json('data'),
        )->pluck('numero_recepcion')->all());
    }

    public function test_la_digitacion_respeta_la_cronologia_y_sus_dias_permitidos(): void
    {
        $recepcion = $this->recepcionDigitadaAlDiaSiguiente();
        $ruta = "/api/romana/recepciones/{$recepcion->id}/fecha-real";
        $this->actingAs($this->digitadora(), 'sanctum');
        $casos = [
            ['ingreso_fecha' => '2026-11-17', 'ingreso_hora' => '09:30'],
            ['ingreso_fecha' => '2026-11-13', 'ingreso_hora' => '23:30'],
            ['salida_fecha' => '2026-11-16', 'salida_hora' => '22:00'],
            ['salida_fecha' => '2026-11-17', 'salida_hora' => '10:00'],
            ['salida_fecha' => null, 'salida_hora' => null],
            ['ingreso_fecha' => '2026-11-17', 'ingreso_hora' => '09:15', 'salida_fecha' => '2026-11-17', 'salida_hora' => '09:15'],
            ['motivo' => 'corto'],
        ];
        foreach ($casos as $cambios) {
            $this->putJson($ruta, $this->datos($recepcion, $cambios))->assertUnprocessable();
        }
        $this->putJson($ruta, $this->datos($recepcion, ['version_conocida' => $recepcion->version - 1]))->assertConflict();
        foreach ([RolUsuario::Validador, RolUsuario::CamareroFrio, RolUsuario::Consulta, RolUsuario::ValidadorMp] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')
                ->putJson($ruta, $this->datos($recepcion))->assertForbidden();
        }
        $this->assertSame(0, EventoRecepcionRomana::where('tipo', 'fecha_real_corregida')->count());
        $this->assertSame($recepcion->ingreso_at->toAtomString(), $recepcion->refresh()->ingreso_at->toAtomString());

        // Pasados los días permitidos, solo administración corrige, y sin ese límite hacia atrás.
        $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00', 'America/Santiago'));
        $this->actingAs($this->digitadora(), 'sanctum');
        $this->getJson("/api/romana/recepciones/{$recepcion->id}")->assertJsonPath('data.puede_corregir_fecha_real', false);
        $this->putJson($ruta, $this->datos($recepcion))->assertConflict();
        $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}")->assertJsonPath('data.puede_corregir_fecha_real', true);
        $this->comoAdministrador()->putJson($ruta, $this->datos($recepcion, ['ingreso_fecha' => '2026-11-12', 'ingreso_hora' => '22:00']))
            ->assertOk()->assertJsonPath('data.fecha_jornada', '2026-11-12');
    }

    public function test_el_ingreso_a_camara_se_registra_con_su_hora_real_sin_anteceder_al_camion(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:15', 'America/Santiago'));
        [$recepcion, $segmentos] = $this->recepcionCerradaConDosGuias();
        $this->actingAs($this->digitadora(), 'sanctum')
            ->putJson("/api/romana/recepciones/{$recepcion->id}/fecha-real", $this->datos($recepcion->refresh()))->assertOk();
        $lote = $this->lote($segmentos[0]);
        $camara = Camara::create(['codigo' => 'MP-NOCHE', 'nombre' => 'Cámara MP noche', 'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima, 'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        $this->actingAs($this->digitadora(), 'sanctum');
        $version = $this->postJson("/api/materia-prima/lotes/{$lote->id}/confirmar", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $lote->version,
        ])->assertOk()->json('data.version');
        $this->assertNotNull($version);
        $ruta = "/api/materia-prima/lotes/{$lote->id}/asignar-camara";
        $asignar = fn (?string $hora) => $this->postJson($ruta, ['operacion_id' => (string) Str::uuid(), 'camara_id' => $camara->id,
            ...($hora ? ['ingresado_at' => $hora] : [])]);

        $asignar('2026-11-16T23:00')->assertUnprocessable()->assertJsonValidationErrors('ingresado_at');
        $asignar('2026-11-17T10:00')->assertUnprocessable()->assertJsonValidationErrors('ingresado_at');
        $asignar('2026-11-17 00:40')->assertUnprocessable()->assertJsonValidationErrors('ingresado_at');
        $asignar('2026-11-17T00:40')->assertOk()
            ->assertJsonPath('data.asignacion_camara.asignado_at', '2026-11-17T03:40:00+00:00');

        $fila = $this->getJson('/api/materia-prima/movimientos?lote_id='.$lote->id)->assertOk()
            ->collect('data')->firstWhere('tipo', 'camara_asignada');
        $this->assertSame('2026-11-17 00:40:00', $fila['fecha']);
        $this->assertSame('Hora real declarada; se digitó el 17-11-2026 09:15.', $fila['detalle']);
        $this->getJson('/api/materia-prima/movimientos?lote_id='.$lote->id.'&desde=2026-11-16&hasta=2026-11-16')
            ->assertOk()->assertJsonPath('total', 1);

        // Con un lote ya en cámara, la recepción no puede quedar después de ese ingreso.
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/fecha-real", $this->datos($recepcion->refresh(), [
            'ingreso_fecha' => '2026-11-17', 'ingreso_hora' => '01:00', 'salida_fecha' => '2026-11-17', 'salida_hora' => '02:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('ingreso_hora');
        $this->assertDatabaseHas('recepciones_romana', ['id' => $recepcion->id, 'ingreso_at' => '2026-11-17 02:30:00']);
        $this->assertSame(1, DB::table('asignaciones_camara_lote_materia_prima')->where('lote_materia_prima_id', $lote->id)->count());
    }

    public function test_sin_hora_real_el_ingreso_a_camara_conserva_la_hora_del_registro(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-17 09:15', 'America/Santiago'));
        [, $segmentos] = $this->recepcionCerradaConDosGuias();
        $lote = $this->lote($segmentos[1]);
        $camara = Camara::create(['codigo' => 'MP-DIA', 'nombre' => 'Cámara MP día', 'tipo' => 'almacenaje',
            'contenido' => ContenidoCamara::MateriaPrima, 'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        $this->actingAs($this->digitadora(), 'sanctum');
        $this->postJson("/api/materia-prima/lotes/{$lote->id}/confirmar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $lote->version])->assertOk();
        $this->postJson("/api/materia-prima/lotes/{$lote->id}/asignar-camara", ['operacion_id' => (string) Str::uuid(), 'camara_id' => $camara->id])
            ->assertOk()->assertJsonPath('data.asignacion_camara.asignado_at', '2026-11-17T12:15:00+00:00');
        $fila = $this->getJson('/api/materia-prima/movimientos?lote_id='.$lote->id)->collect('data')->firstWhere('tipo', 'camara_asignada');
        $this->assertNull($fila['detalle']);
    }
}
