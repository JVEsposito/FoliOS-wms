<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoPerfilUsuarioApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_el_perfil_y_la_foto_requieren_sesion(): void
    {
        $this->getJson('/api/usuario/perfil')->assertUnauthorized();
        $this->getJson('/api/usuario/foto-perfil')->assertUnauthorized();
        $this->postJson('/api/usuario/foto-perfil')->assertUnauthorized();
        $this->deleteJson('/api/usuario/foto-perfil')->assertUnauthorized();
    }

    public function test_guarda_una_foto_pequena_privada_y_el_perfil_persiste_entre_sesiones(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $this->actingAs($usuario, 'sanctum')->getJson('/api/usuario/perfil')->assertOk()
            ->assertJsonPath('data.foto_perfil_version', null);
        $this->getJson('/api/usuario/foto-perfil')->assertNotFound();
        $respuesta = $this->postJson('/api/usuario/foto-perfil', [
            'foto' => UploadedFile::fake()->image('perfil.png', 1024, 512),
        ])->assertOk()->assertJsonPath('data.id', $usuario->id);
        $version = $respuesta->json('data.foto_perfil_version');
        $this->assertNotEmpty($version);
        $ruta = $usuario->fresh()->foto_perfil_ruta;
        Storage::disk('local')->assertExists($ruta);
        $this->assertArrayNotHasKey('foto_perfil_ruta', $usuario->fresh()->toArray());
        $this->assertStringNotContainsString($ruta, $respuesta->getContent());
        $dimensiones = getimagesizefromstring(Storage::disk('local')->get($ruta));
        $this->assertSame([384, 192, IMAGETYPE_JPEG], array_slice($dimensiones, 0, 3));
        $this->assertLessThan(100_000, Storage::disk('local')->size($ruta));

        $this->actingAs($usuario->fresh(), 'sanctum')->getJson('/api/usuario/perfil')->assertOk()
            ->assertJsonPath('data.foto_perfil_version', $version);
        $this->getJson('/api/usuario/foto-perfil')->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_cambiar_y_quitar_solo_afecta_a_la_foto_del_usuario_autenticado(): void
    {
        $otro = User::factory()->create();
        $this->actingAs($otro, 'sanctum')->postJson('/api/usuario/foto-perfil', [
            'foto' => UploadedFile::fake()->image('otro.jpg'),
        ])->assertOk();
        $rutaOtro = $otro->fresh()->foto_perfil_ruta;
        $usuario = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $this->actingAs($usuario, 'sanctum')->postJson('/api/usuario/foto-perfil', [
            'user_id' => $otro->id, 'foto_perfil_ruta' => $rutaOtro,
            'foto' => UploadedFile::fake()->image('primera.jpg'),
        ])->assertOk()->assertJsonPath('data.id', $usuario->id);
        $anterior = $usuario->fresh()->foto_perfil_ruta;
        $this->postJson('/api/usuario/foto-perfil', ['foto' => UploadedFile::fake()->image('nueva.png')])->assertOk();
        $actual = $usuario->fresh()->foto_perfil_ruta;
        $this->assertNotSame($anterior, $actual);
        Storage::disk('local')->assertMissing($anterior);
        Storage::disk('local')->assertExists($actual);
        $this->deleteJson('/api/usuario/foto-perfil', ['user_id' => $otro->id])->assertOk()
            ->assertJsonPath('data.foto_perfil_version', null);
        $this->assertNull($usuario->fresh()->foto_perfil_ruta);
        Storage::disk('local')->assertMissing($actual);
        Storage::disk('local')->assertExists($rutaOtro);
        $this->assertSame($rutaOtro, $otro->fresh()->foto_perfil_ruta);
        $this->getJson('/api/usuario/foto-perfil')->assertNotFound();
        $this->deleteJson('/api/usuario/foto-perfil')->assertOk();
        $this->getJson('/api/usuario/foto-perfil/'.$otro->id)->assertNotFound();
    }

    public function test_rechaza_archivos_invalidos_o_grandes_sin_cambiar_la_foto_actual(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario, 'sanctum')->postJson('/api/usuario/foto-perfil', [
            'foto' => UploadedFile::fake()->image('actual.jpg'),
        ])->assertOk();
        $ruta = $usuario->fresh()->foto_perfil_ruta;
        foreach ([
            UploadedFile::fake()->createWithContent('foto.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('animada.gif'),
            UploadedFile::fake()->createWithContent('falsa.jpg', 'Esto no es una imagen'),
            UploadedFile::fake()->image('grande.jpg')->size(5121),
        ] as $archivo) {
            $this->postJson('/api/usuario/foto-perfil', ['foto' => $archivo])->assertUnprocessable()->assertJsonValidationErrors('foto');
            $this->assertSame($ruta, $usuario->fresh()->foto_perfil_ruta);
        }
        Storage::disk('local')->assertExists($ruta);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_cliente_solo_accede_a_su_perfil_sin_abrir_rutas_internas(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Cliente]);
        $token = $usuario->createToken('perfil', ['oficina'])->plainTextToken;
        $this->withToken($token)->getJson('/api/usuario/perfil')->assertOk()->assertJsonPath('data.id', $usuario->id);
        $this->postJson('/api/usuario/foto-perfil', ['foto' => UploadedFile::fake()->image('cliente.jpg')])->assertOk();
        $this->getJson('/api/usuario/foto-perfil')->assertOk();
        $this->deleteJson('/api/usuario/foto-perfil')->assertOk();
        $this->getJson('/api/camaras')->assertForbidden()->assertJsonPath('codigo', 'solo_portal_clientes');
    }

    public function test_un_usuario_inactivo_no_puede_leer_o_cambiar_fotos(): void
    {
        $usuario = User::factory()->create();
        $token = $usuario->createToken('perfil')->plainTextToken;
        $usuario->update(['activo' => false]);
        $this->withToken($token)->getJson('/api/usuario/perfil')->assertUnauthorized();
        $this->getJson('/api/usuario/foto-perfil')->assertUnauthorized();
        $this->postJson('/api/usuario/foto-perfil', ['foto' => UploadedFile::fake()->image('inactivo.jpg')])->assertUnauthorized();
        $this->deleteJson('/api/usuario/foto-perfil')->assertUnauthorized();
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }
}
