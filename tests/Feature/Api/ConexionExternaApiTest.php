<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ConexionExterna;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Integraciones\ConectorMySqlLectura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PDOException;
use Tests\TestCase;

class ConexionExternaApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/administracion/conexiones-externas';

    private function datos(): array
    {
        return ['nombre' => 'Origen de prueba', 'host' => '127.0.0.2', 'puerto' => 13306, 'usuario' => 'lector_fixture', 'password' => 'clave_solo_fixture', 'base_datos' => 'origen_fixture', 'activa' => true];
    }

    private function administrador(): void
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
    }

    private function crear(): array
    {
        return $this->postJson(self::URL, $this->datos())->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    public function test_las_credenciales_se_cifran_y_la_clave_no_se_devuelve_ni_se_audita(): void
    {
        $this->administrador();
        $datos = $this->crear();
        $this->assertArrayNotHasKey('password', $datos);
        $this->assertTrue($datos['password_configurada']);
        $modelo = ConexionExterna::findOrFail($datos['id']);
        $this->assertSame($this->datos()['password'], $modelo->credenciales['password']);
        $this->assertArrayNotHasKey('credenciales', $modelo->toArray());
        $cifrado = DB::table('conexiones_externas')->value('credenciales');
        foreach (['host', 'usuario', 'base_datos', 'password'] as $campo) {
            $this->assertStringNotContainsString($this->datos()[$campo], $cifrado);
            $this->assertStringNotContainsString($this->datos()[$campo], $modelo->eventos->toJson());
        }
        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.host', '127.0.0.2')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.credenciales')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_solo_el_administrador_habilitado_puede_acceder_a_todas_las_acciones(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->datos())->assertUnauthorized();
        $this->administrador();
        $datos = $this->crear();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::SupervisorFrio]), 'sanctum');
        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, $this->datos())->assertForbidden();
        $this->putJson(self::URL.'/'.$datos['id'], [...$this->datos(), 'version_conocida' => 1])->assertForbidden();
        $this->postJson(self::URL.'/'.$datos['id'].'/probar', ['version_conocida' => 1])->assertForbidden();
    }

    public function test_editar_sin_clave_conserva_la_guardada_y_rechaza_versiones_antiguas(): void
    {
        $this->administrador();
        $datos = $this->crear();
        $payload = [...$this->datos(), 'nombre' => 'Origen editado', 'password' => '', 'version_conocida' => 1];
        $this->putJson(self::URL.'/'.$datos['id'], $payload)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame('clave_solo_fixture', ConexionExterna::find($datos['id'])->credenciales['password']);
        $this->putJson(self::URL.'/'.$datos['id'], [...$payload, 'password' => 'clave_otro_editor'])->assertConflict();
        $this->assertSame('clave_solo_fixture', ConexionExterna::find($datos['id'])->credenciales['password']);
        $this->putJson(self::URL.'/'.$datos['id'], [...$payload, 'password' => 'clave_fixture_nueva', 'version_conocida' => 2])
            ->assertOk()->assertJsonMissingPath('data.password');
        $modelo = ConexionExterna::find($datos['id']);
        $this->assertSame('clave_fixture_nueva', $modelo->credenciales['password']);
        $this->assertStringNotContainsString('clave_fixture_nueva', $modelo->eventos->toJson());
    }

    public function test_valida_el_destino_sin_permitir_inyectar_componentes_del_dsn(): void
    {
        $this->administrador();
        foreach ([['host', 'localhost;unix_socket=/tmp/otra.sock'], ['base_datos', 'origen;charset=latin1'], ['puerto', 65536], ['password', '']] as [$campo, $valor]) {
            $this->postJson(self::URL, [...$this->datos(), $campo => $valor])->assertUnprocessable()->assertJsonValidationErrors($campo);
        }
        $this->assertDatabaseCount('conexiones_externas', 0);
    }

    public function test_prueba_el_perfil_guardado_y_una_edicion_invalida_el_resultado(): void
    {
        $this->administrador();
        $datos = $this->crear();
        $this->mock(ConectorMySqlLectura::class, function (MockInterface $mock): void {
            $mock->shouldReceive('probar')->once()->with(collect($this->datos())->except('nombre', 'activa')->all());
        });
        $this->postJson(self::URL.'/'.$datos['id'].'/probar', ['version_conocida' => 1])
            ->assertOk()->assertJsonPath('data.ultima_prueba_estado', 'correcta')->assertJsonMissingPath('data.password');
        $this->putJson(self::URL.'/'.$datos['id'], [...$this->datos(), 'version_conocida' => 1])
            ->assertOk()->assertJsonPath('data.ultima_prueba_estado', null)->assertJsonPath('data.ultima_prueba_at', null);
    }

    public function test_una_falla_no_expone_ni_registra_los_detalles_del_controlador(): void
    {
        $this->administrador();
        $datos = $this->crear();
        Log::spy();
        $this->mock(ConectorMySqlLectura::class, function (MockInterface $mock): void {
            $mock->shouldReceive('probar')->once()->andThrow(new PDOException('dsn_privado_fixture clave_solo_fixture lector_fixture'));
        });
        $respuesta = $this->postJson(self::URL.'/'.$datos['id'].'/probar', ['version_conocida' => 1]);
        $respuesta->assertUnprocessable()
            ->assertJsonPath('data.ultima_prueba_estado', 'fallida')
            ->assertJsonPath('causa', 'error_desconocido');
        foreach (['dsn_privado_fixture', 'clave_solo_fixture', 'PDOException'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $respuesta->getContent());
        }
        Log::shouldNotHaveReceived('error');
        $this->assertStringNotContainsString('dsn_privado_fixture', ConexionExterna::find($datos['id'])->eventos->toJson());
    }

    public function test_no_prueba_perfiles_inactivos_ni_una_version_reemplazada(): void
    {
        $this->administrador();
        $datos = $this->crear();
        $this->mock(ConectorMySqlLectura::class, fn (MockInterface $mock) => $mock->shouldNotReceive('probar'));
        $this->postJson(self::URL.'/'.$datos['id'].'/probar', ['version_conocida' => 2])->assertConflict();
        ConexionExterna::find($datos['id'])->update(['activa' => false]);
        $this->postJson(self::URL.'/'.$datos['id'].'/probar', ['version_conocida' => 1])->assertUnprocessable();
    }

    public function test_una_prueba_tardia_no_valida_una_configuracion_que_cambio(): void
    {
        $this->administrador();
        $datos = $this->crear();
        $this->mock(ConectorMySqlLectura::class, function (MockInterface $mock) use ($datos): void {
            $mock->shouldReceive('probar')->once()->andReturnUsing(function () use ($datos): void {
                DB::table('conexiones_externas')->where('id', $datos['id'])->update(['version' => 2]);
            });
        });
        $this->postJson(self::URL.'/'.$datos['id'].'/probar', ['version_conocida' => 1])->assertConflict();
        $this->assertNull(ConexionExterna::find($datos['id'])->ultima_prueba_estado);
    }

    public function test_el_reinicio_de_temporada_conserva_el_perfil_y_su_auditoria(): void
    {
        $this->administrador();
        $datos = $this->crear();
        $antes = DB::table('conexiones_externas')->where('id', $datos['id'])->value('credenciales');
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->postJson('/api/administracion/temporadas/'.$temporada->id.'/reinicio-operacional', [
            'operacion_id' => (string) Str::uuid(),
            'motivo' => 'Verificar que se conserva la configuración global.',
            'password' => 'password',
            'confirmacion' => 'REINICIAR '.$temporada->codigo,
            'confirmar_exclusion_bodega' => true,
            'confirmar_preservar_configuracion' => true,
        ])->assertCreated();
        $this->assertSame($antes, DB::table('conexiones_externas')->where('id', $datos['id'])->value('credenciales'));
        $this->assertDatabaseCount('eventos_conexion_externa', 1);
    }
}
