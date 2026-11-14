<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GruposModeracionChatTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/comunicaciones';

    public function test_el_grupo_permite_gestionar_miembros_sin_exponer_el_historial_a_terceros(): void
    {
        $creador = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $miembro = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $cliente = User::factory()->create(['rol' => RolUsuario::Cliente]);
        $datos = ['nombre' => 'Turno noche', 'miembros' => [$miembro->id], 'operacion_id' => (string) Str::uuid()];
        $this->actingAs($creador, 'sanctum')->postJson(self::API.'/chat/grupos', [...$datos, 'miembros' => [$cliente->id]])
            ->assertUnprocessable();
        $id = $this->postJson(self::API.'/chat/grupos', $datos)->assertOk()->assertJsonPath('data.tipo', 'grupo')->json('data.id');
        $this->postJson(self::API.'/chat/grupos', $datos)->assertOk()->assertJsonPath('data.id', $id);
        $mensajes = self::API."/chat/conversaciones/{$id}/mensajes";
        $grupo = self::API."/chat/grupos/{$id}";

        $this->actingAs($miembro, 'sanctum')->getJson($grupo)->assertOk()->assertJsonCount(2, 'data.miembros');
        $this->postJson($mensajes, ['texto' => 'Solo el turno', 'operacion_id' => (string) Str::uuid()])->assertOk();
        $this->putJson($grupo, ['nombre' => 'Otro nombre', 'miembros' => []])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson($mensajes)->assertNotFound();
        $this->getJson(self::API.'/chat/conversaciones')->assertJsonCount(1, 'data');
        $this->deleteJson($mensajes.'/1')->assertNotFound();
        $this->actingAs($creador, 'sanctum')->putJson($grupo, ['nombre' => 'Turno B', 'miembros' => [$admin->id]])->assertOk();
        $this->actingAs($miembro, 'sanctum')->getJson($mensajes)->assertNotFound();
        $this->postJson($mensajes, ['texto' => 'Fuera del grupo', 'operacion_id' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($admin, 'sanctum')->getJson($mensajes)->assertOk()->assertJsonPath('data.0.texto', 'Solo el turno');
    }

    public function test_eliminar_omite_el_contenido_y_las_menciones_y_se_propaga_por_revision(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $persona = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $global = $this->actingAs($admin, 'sanctum')->getJson(self::API.'/chat/conversaciones')->json('data.0.id');
        $ruta = self::API."/chat/conversaciones/{$global}/mensajes";
        $operacion = (string) Str::uuid();
        $texto = 'Aviso [[usuario:'.$persona->id.']]';
        $id = $this->postJson($ruta, ['texto' => $texto, 'operacion_id' => $operacion])->assertOk()->json('data.id');
        $this->actingAs($persona, 'sanctum')->getJson(self::API.'/resumen')->assertJsonPath('data.menciones_sin_leer', 1);
        $this->deleteJson($ruta.'/'.$id)->assertForbidden();
        $this->actingAs($admin, 'sanctum')->deleteJson($ruta.'/'.$id)->assertOk()->assertJsonPath('data.texto', null)
            ->assertJsonPath('data.sticker', null)->assertJsonPath('data.referencias', []);
        $this->deleteJson($ruta.'/'.$id)->assertOk();
        $this->postJson($ruta, ['texto' => $texto, 'operacion_id' => $operacion])->assertOk()->assertJsonPath('data.eliminado', true);
        $this->actingAs($persona, 'sanctum')->getJson($ruta."?despues_id={$id}&revision_moderacion=0")->assertOk()
            ->assertJsonPath('revision_moderacion', 1)->assertJsonPath('mensajes_eliminados', [$id])->assertJsonCount(0, 'data');
        $this->getJson($ruta)->assertJsonPath('data.0.texto', null)->assertJsonPath('data.0.eliminado', true);
        $this->getJson(self::API.'/resumen')->assertJsonPath('data.menciones_sin_leer', 0)->assertJsonPath('data.chat_no_leido', false);
        $this->getJson(self::API.'/chat/conversaciones')->assertJsonPath('data.0.extracto', 'Mensaje eliminado');
        $this->assertDatabaseHas('com_mensajes', ['id' => $id, 'eliminado_por_user_id' => $admin->id, 'revision_eliminacion' => 1]);
    }

    public function test_el_silencio_tiene_alcance_por_chat_y_se_puede_revocar_o_esperar_su_vencimiento(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $persona = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $global = $this->actingAs($admin, 'sanctum')->getJson(self::API.'/chat/conversaciones')->json('data.0.id');
        $privado = $this->postJson(self::API.'/chat/privados', ['usuario_id' => $persona->id])->json('data.id');
        $base = self::API.'/chat/conversaciones/';
        $silencio = $base.$global.'/silencios/'.$persona->id;
        $enviar = fn ($id) => $this->postJson($base.$id.'/mensajes', ['texto' => 'Hola', 'operacion_id' => (string) Str::uuid()]);

        $this->putJson($base.$privado.'/silencios/'.$persona->id, ['minutos' => 10])->assertForbidden();
        $this->actingAs($persona, 'sanctum')->putJson($silencio, ['minutos' => 10])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson($silencio, ['minutos' => 10])->assertOk();
        $this->actingAs($persona, 'sanctum')->getJson($base.$global.'/mensajes')->assertJsonPath('conversacion.puede_enviar', false);
        $enviar($global)->assertForbidden();
        $enviar($privado)->assertOk();
        $this->actingAs($admin, 'sanctum')->deleteJson($silencio)->assertOk();
        $this->actingAs($persona, 'sanctum');
        $enviar($global)->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson($silencio, ['minutos' => 10])->assertOk();
        $this->travel(10)->minutes();
        $this->actingAs($persona, 'sanctum');
        $enviar($global)->assertOk();
        $this->assertDatabaseCount('com_silencios_chat', 2);
    }

    public function test_la_foto_del_chat_se_entrega_solo_a_personal_interno_y_sin_exponer_la_ruta_privada(): void
    {
        Storage::fake('local');
        $persona = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $espectador = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $version = (string) Str::uuid();
        $archivo = "perfiles-usuarios/{$persona->id}/{$version}.jpg";
        Storage::disk('local')->put($archivo, 'imagen de prueba');
        $persona->forceFill(['foto_perfil_ruta' => $archivo])->save();
        $foto = self::API."/usuarios/{$persona->id}/foto";
        $global = $this->actingAs($persona, 'sanctum')->getJson(self::API.'/chat/conversaciones')->json('data.0.id');
        $this->postJson(self::API."/chat/conversaciones/{$global}/mensajes", ['texto' => 'Hola', 'operacion_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.remitente.foto_version', $version)->assertJsonMissingPath('data.remitente.foto_perfil_ruta');
        $this->actingAs($espectador, 'sanctum')->getJson($foto)->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Cliente]), 'sanctum')->getJson($foto)->assertForbidden();
        $persona->update(['activo' => false]);
        $this->actingAs($espectador, 'sanctum')->getJson($foto)->assertNotFound();
    }
}
