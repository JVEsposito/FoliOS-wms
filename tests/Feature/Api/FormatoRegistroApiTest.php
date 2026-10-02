<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\FormatoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormatoRegistroApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administracion_crea_publica_y_audita_formatos_con_codigo_unico(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/administracion/formatos-registro')
            ->assertOk()->assertJsonFragment(['codigo' => 'RPR-01', 'version' => '1'])->assertJsonFragment(['codigo' => 'RC-02', 'version' => '1']);
        $datos = ['codigo' => 'OTRO-01', 'nombre' => 'Otro registro', 'version' => '1',
            'fecha_vigencia' => '2026-10-02', 'localidad' => 'Rengo', 'activo' => true];
        $creado = $this->postJson('/api/administracion/formatos-registro', $datos)->assertCreated()->json('data');
        $this->postJson('/api/administracion/formatos-registro', $datos)->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->travel(2)->seconds();
        $this->putJson('/api/administracion/formatos-registro/'.$creado['id'], [
            ...$datos, 'version' => '2', 'activo' => false, 'actualizado_at_conocido' => $creado['actualizado_at'],
        ])->assertOk()->assertJsonPath('data.version', '2')->assertJsonPath('data.actualizado_por', $admin->name);
        $this->assertDatabaseHas('formatos_registro', ['id' => $creado['id'], 'creado_por_user_id' => $admin->id, 'actualizado_por_user_id' => $admin->id]);
        $this->assertDatabaseCount('eventos_formato_registro', 2);
        $this->getJson('/api/administracion/formatos-registro/'.$creado['id'].'/eventos')
            ->assertOk()->assertJsonPath('data.data.0.antes.version', '1')
            ->assertJsonPath('data.data.0.despues.version', '2')
            ->assertJsonPath('data.data.0.usuario.id', $admin->id);
        $this->putJson('/api/administracion/formatos-registro/'.$creado['id'], [
            ...$datos, 'version' => '3', 'actualizado_at_conocido' => $creado['actualizado_at'],
        ])->assertConflict();
    }

    public function test_operador_de_romana_no_puede_administrar_ni_leer_historial_de_formatos(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $formato = FormatoRegistro::firstOrFail();
        $this->actingAs($operador, 'sanctum')->getJson('/api/administracion/formatos-registro')->assertForbidden();
        $this->postJson('/api/administracion/formatos-registro', [])->assertForbidden();
        $this->putJson('/api/administracion/formatos-registro/'.$formato->id, [])->assertForbidden();
        $this->getJson('/api/administracion/formatos-registro/'.$formato->id.'/eventos')->assertForbidden();
    }
}
