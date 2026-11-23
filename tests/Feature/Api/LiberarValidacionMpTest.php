<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\EventoRecepcionRomana;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionesEnvases;
use Tests\Concerns\PreparaRecepcionEnvases;
use Tests\TestCase;

/**
 * Una Validación MP tomada que nadie confirma deja la recepción sin segmentos ni lotes.
 * Un administrador puede liberarla con motivo para que otro validador la tome; el
 * validador anterior ya no puede confirmarla y queda el evento en la recepción.
 */
class LiberarValidacionMpTest extends TestCase
{
    use PreparaCorreccionesEnvases, PreparaRecepcionEnvases, RefreshDatabase;

    private const MOTIVO = 'El validador no está en turno y la recepción quedó tomada.';

    public function test_el_administrador_libera_y_otro_validador_la_toma_y_confirma(): void
    {
        $c = $this->preparar(['bins' => 4], confirmar: false);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $ruta = '/api/romana/recepciones/'.$c['recepcion']->id;

        $this->actingAs($admin, 'sanctum')->getJson($ruta)->assertOk()
            ->assertJsonPath('data.estado_validacion_mp', 'en_curso')
            ->assertJsonPath('data.validacion_tomada_por.nombre', $c['validador']->name)
            ->assertJsonPath('data.puede_liberar_validacion_mp', true);
        $this->postJson($ruta.'/liberar-validacion-mp', $this->datos())->assertOk()
            ->assertJsonPath('data.estado_validacion_mp', 'pendiente')
            ->assertJsonPath('data.validacion_tomada_por', null)
            ->assertJsonPath('data.puede_liberar_validacion_mp', false);
        $this->assertDatabaseHas('eventos_recepcion_romana', ['recepcion_romana_id' => $c['recepcion']->id, 'tipo' => 'validacion_mp_liberada', 'user_id' => $admin->id]);

        $otro = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $validacion = $this->actingAs($otro, 'sanctum')->postJson('/api/validacion-mp/recepciones/'.$c['recepcion']->id.'/tomar', ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');
        $this->assertSame($c['validacion'], $validacion);
        $this->actingAs($c['validador'], 'sanctum')->postJson('/api/validacion-mp/validaciones/'.$validacion.'/confirmar', $this->confirmacion($validacion, $c['mp']))
            ->assertConflict();
        $this->actingAs($otro, 'sanctum')->postJson('/api/validacion-mp/validaciones/'.$validacion.'/confirmar', $this->confirmacion($validacion, $c['mp']))
            ->assertOk();
        $this->assertSame('validada', $c['recepcion']->refresh()->estado_validacion_mp->value);
    }

    public function test_el_validador_anterior_no_confirma_una_validacion_liberada(): void
    {
        $c = $this->preparar(['bins' => 4], confirmar: false);
        $this->liberar($c);

        $this->actingAs($c['validador'], 'sanctum')->postJson('/api/validacion-mp/validaciones/'.$c['validacion'].'/confirmar', $this->confirmacion($c['validacion'], $c['mp']))
            ->assertConflict()->assertJsonPath('message', 'La validación fue liberada por administración. Vuelve a tomarla desde los pendientes.');
    }

    public function test_el_mismo_validador_puede_volver_a_tomarla(): void
    {
        $c = $this->preparar(['bins' => 4], confirmar: false);
        $this->liberar($c);

        $this->actingAs($c['validador'], 'sanctum')->postJson('/api/validacion-mp/recepciones/'.$c['recepcion']->id.'/tomar', ['operacion_id' => (string) Str::uuid()])->assertOk();

        $this->assertSame('en_curso', $c['recepcion']->refresh()->estado_validacion_mp->value);
        $this->assertSame($c['validador']->id, $c['recepcion']->validacion_tomada_por_user_id);
    }

    public function test_solo_administrador_con_motivo_y_solo_en_curso(): void
    {
        $c = $this->preparar(['bins' => 4], confirmar: false);
        $ruta = '/api/romana/recepciones/'.$c['recepcion']->id.'/liberar-validacion-mp';

        $this->actingAs($c['validador'], 'sanctum')->postJson($ruta, $this->datos())->assertForbidden();
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->postJson($ruta, [...$this->datos(), 'motivo' => 'corto'])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->postJson($ruta, $this->datos())->assertOk();
        $this->postJson($ruta, $this->datos())->assertConflict()->assertJsonPath('message', 'Solo se libera una Validación MP en curso.');
    }

    public function test_es_idempotente(): void
    {
        $c = $this->preparar(['bins' => 4], confirmar: false);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $datos = $this->datos();
        $ruta = '/api/romana/recepciones/'.$c['recepcion']->id.'/liberar-validacion-mp';

        $this->actingAs($admin, 'sanctum')->postJson($ruta, $datos)->assertOk();
        $this->postJson($ruta, $datos)->assertOk()->assertJsonPath('data.estado_validacion_mp', 'pendiente');
        $this->postJson($ruta, [...$datos, 'motivo' => 'Otro motivo distinto para la misma operación.'])->assertConflict();

        $this->assertSame(1, EventoRecepcionRomana::query()->where('recepcion_romana_id', $c['recepcion']->id)->where('tipo', 'validacion_mp_liberada')->count());
    }

    private function liberar(array $c): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/romana/recepciones/'.$c['recepcion']->id.'/liberar-validacion-mp', $this->datos())->assertOk();
    }

    /** @return array{operacion_id: string, motivo: string} */
    private function datos(): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'motivo' => self::MOTIVO];
    }

    /** @param  array<string, mixed>  $mp */
    private function confirmacion(string $validacion, array $mp): array
    {
        $ruta = '/api/validacion-mp/validaciones/'.$validacion.'/confirmar';

        return $this->payloadConInspeccionRc02($ruta, [...$mp, 'operacion_id' => (string) Str::uuid()]);
    }
}
