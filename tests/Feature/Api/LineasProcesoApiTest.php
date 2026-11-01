<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TurnoProceso;
use App\Models\LineaProceso;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LineasProcesoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_catalogo_trae_las_cinco_lineas_y_los_turnos_con_sus_codigos_de_planilla(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum');

        $respuesta = $this->getJson('/api/materia-prima/ordenes-proceso/catalogos')->assertOk();

        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], array_column($respuesta->json('lineas'), 'codigo'));
        $this->assertEquals([6000], array_values(array_unique(array_column($respuesta->json('lineas'), 'rendimiento_kg_hora'))));
        $respuesta
            ->assertJsonPath('turnos.0.codigo', 'A')
            ->assertJsonPath('turnos.0.codigo_pre_sif', 'T1')
            ->assertJsonPath('turnos.0.codigo_instructivo', 'AM')
            ->assertJsonPath('turnos.1.codigo', 'B')
            ->assertJsonPath('turnos.1.codigo_pre_sif', 'T2')
            ->assertJsonPath('turnos.1.codigo_instructivo', 'PM');
    }

    public function test_el_turno_se_reconoce_desde_cualquier_codigo_de_planilla(): void
    {
        $this->assertSame(TurnoProceso::A, TurnoProceso::desdeCodigo('t1'));
        $this->assertSame(TurnoProceso::A, TurnoProceso::desdeCodigo(' AM '));
        $this->assertSame(TurnoProceso::B, TurnoProceso::desdeCodigo('T2'));
        $this->assertSame(TurnoProceso::B, TurnoProceso::desdeCodigo('b'));
        $this->assertNull(TurnoProceso::desdeCodigo('T3'));
        $this->assertNull(TurnoProceso::desdeCodigo(null));
    }

    public function test_solo_roles_de_ordenes_de_proceso_consultan_el_catalogo(): void
    {
        foreach ([RolUsuario::Administrador, RolUsuario::SupervisorFrio, RolUsuario::DigitadorMateriaPrima, RolUsuario::Consulta] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')
                ->getJson('/api/materia-prima/ordenes-proceso/catalogos')
                ->assertOk();
        }

        foreach ([RolUsuario::CamareroFrio, RolUsuario::OperadorRomana, RolUsuario::ValidadorMp] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')
                ->getJson('/api/materia-prima/ordenes-proceso/catalogos')
                ->assertForbidden();
        }
    }

    public function test_el_modulo_de_ordenes_llega_a_los_perfiles_que_programan_o_consultan_proceso(): void
    {
        $catalogo = app(CatalogoModulosAcceso::class);

        foreach ([RolUsuario::SupervisorFrio, RolUsuario::DigitadorMateriaPrima, RolUsuario::Consulta, RolUsuario::Administrador] as $rol) {
            $this->assertContains(CatalogoModulosAcceso::OFICINA_ORDENES_PROCESO, $catalogo->modulosPredeterminados($rol), $rol->value);
        }
        $this->assertNotContains(CatalogoModulosAcceso::OFICINA_ORDENES_PROCESO, $catalogo->modulosPredeterminados(RolUsuario::CamareroFrio));

        foreach (['administrador', 'supervisor_frio', 'digitador_materia_prima', 'consulta'] as $rolBase) {
            $perfil = PerfilAcceso::query()->where('predeterminado', true)->where('rol_base', $rolBase)->first();
            if ($perfil) {
                $this->assertContains(CatalogoModulosAcceso::OFICINA_ORDENES_PROCESO, $perfil->modulos, $rolBase);
            }
        }
    }

    public function test_el_supervisor_ajusta_el_rendimiento_con_control_de_version(): void
    {
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $linea = LineaProceso::query()->where('codigo', 'L3')->firstOrFail();

        $this->actingAs($supervisor, 'sanctum')
            ->putJson("/api/materia-prima/lineas-proceso/{$linea->id}", [
                'rendimiento_kg_hora' => 7500,
                'version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.rendimiento_kg_hora', 7500)
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('lineas_proceso', [
            'id' => $linea->id,
            'rendimiento_kg_hora' => 7500,
            'actualizado_por_user_id' => $supervisor->id,
        ]);

        $this->putJson("/api/materia-prima/lineas-proceso/{$linea->id}", [
            'activa' => false,
            'version' => 1,
        ])->assertStatus(409);
        $this->assertTrue($linea->refresh()->activa);
    }

    public function test_el_digitador_no_configura_lineas_y_el_rendimiento_tiene_limites(): void
    {
        $linea = LineaProceso::query()->where('codigo', 'L1')->firstOrFail();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]), 'sanctum')
            ->putJson("/api/materia-prima/lineas-proceso/{$linea->id}", ['rendimiento_kg_hora' => 5000, 'version' => 1])
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->putJson("/api/materia-prima/lineas-proceso/{$linea->id}", ['rendimiento_kg_hora' => 50, 'version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rendimiento_kg_hora');
    }
}
