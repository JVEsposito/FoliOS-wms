<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Services\Jornadas\CalendarioOperativo;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarioOperativoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_administrador_programa_la_vigencia_y_los_operadores_consultan_la_jornada(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13T06:00:00-03:00'));
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $ruta = '/api/administracion/jornada-operativa';
        $horario = ['vigente_desde' => '2026-11-13', 'inicio_jornada' => '07:00', 'inicio_turno_b' => '19:00', 'version_conocida' => 0];

        $this->actingAs($operador, 'sanctum')->postJson($ruta, $horario)->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson($ruta, [...$horario, 'inicio_turno_b' => '07:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('inicio_turno_b');
        $this->postJson($ruta, $horario)->assertOk()
            ->assertJsonPath('data.turno_operativo', null)
            ->assertJsonPath('data.proxima.vigente_desde', '2026-11-13');
        $this->postJson($ruta, $horario)->assertUnprocessable()->assertJsonValidationErrors('version_conocida');

        foreach ([
            ['2026-11-13T07:00:00-03:00', '2026-11-13', 'A'],
            ['2026-11-13T19:00:00-03:00', '2026-11-13', 'B'],
            ['2026-11-14T06:59:59-03:00', '2026-11-13', 'B'],
            ['2026-11-14T07:00:00-03:00', '2026-11-14', 'A'],
        ] as [$hora, $fecha, $turno]) {
            $this->travelTo(CarbonImmutable::parse($hora));
            $this->actingAs($operador, 'sanctum')->getJson('/api/jornada-operativa')->assertOk()
                ->assertJsonPath('data.fecha_jornada', $fecha)
                ->assertJsonPath('data.turno_operativo', $turno);
        }
        $this->actingAs($admin, 'sanctum')->postJson($ruta, $horario)
            ->assertUnprocessable()->assertJsonValidationErrors('vigente_desde');
    }

    public function test_el_rango_comun_preserva_la_historia_y_conserva_las_siete_en_el_cambio_de_hora(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02T07:00:00-04:00'));
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $calendario = app(CalendarioOperativo::class);
        $calendario->publicar([
            'vigente_desde' => '2026-09-04', 'inicio_jornada' => '07:00', 'inicio_turno_b' => '19:00', 'version_conocida' => 0,
        ], $admin->id);

        [$inicio, $fin] = $calendario->rango('2026-09-05', '2026-09-05');
        $this->assertSame('2026-09-05T11:00:00+00:00', $inicio->toAtomString());
        $this->assertSame('2026-09-06T10:00:00+00:00', $fin->toAtomString());
        $this->assertSame('2026-09-05', $calendario->fecha($fin->subSecond()));
        $this->assertSame('2026-09-06', $calendario->fecha($fin));

        [$inicioHistorico, $finHistorico] = $calendario->rango('2026-09-03', '2026-09-03');
        $this->assertSame('2026-09-03T04:00:00+00:00', $inicioHistorico->toAtomString());
        $this->assertSame('2026-09-04T04:00:00+00:00', $finHistorico->toAtomString());
        $previo = CarbonImmutable::parse('2026-09-04T05:00:00-04:00');
        $this->assertSame(['fecha_jornada' => '2026-09-04', 'turno_operativo' => null], $calendario->clasificar($previo));

        $calendario->publicar([
            'vigente_desde' => '2026-09-07', 'inicio_jornada' => '08:00', 'inicio_turno_b' => '20:00',
            'version_conocida' => $calendario->reglas()->first()->id,
        ], $admin->id);
        $corte = CarbonImmutable::parse('2026-09-07T08:00:00-03:00');
        $this->assertSame(['fecha_jornada' => '2026-09-06', 'turno_operativo' => 'B'], $calendario->clasificar($corte->subSecond()));
        $this->assertSame(['fecha_jornada' => '2026-09-07', 'turno_operativo' => 'A'], $calendario->clasificar($corte));
    }
}
