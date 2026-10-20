<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\Dispositivo;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PorteriaMovilApiTest extends TestCase
{
    use RefreshDatabase;

    private function identidad(): array
    {
        $perfil = PerfilAcceso::query()->where('codigo', 'GUARDIA_PORTERIA')->firstOrFail();
        $usuario = User::factory()->create([
            'rol' => RolUsuario::GuardiaPorteria, 'perfil_acceso_id' => $perfil->id,
            'password' => Hash::make('clave-segura'), 'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'PORTERIA-01', 'nombre' => 'Equipo de portería', 'activo' => true,
        ]);

        return [$usuario, $dispositivo, $perfil];
    }

    private function datos(array $cambios = []): array
    {
        return [...[
            'operacion_id' => (string) Str::uuid(), 'cliente_otro' => 'Cliente externo',
            'motivo' => 'carga', 'patente' => 'ABCD12', 'tipo_camion' => 'Camión con carro',
            'nombre_conductor' => 'Conductor de prueba', 'guias' => ['000123', '000124'],
            'cantidad' => 24, 'unidad' => 'bins',
        ], ...$cambios];
    }

    private function token(User $usuario, Dispositivo $dispositivo): string
    {
        return $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email, 'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])->assertOk()
            ->assertJsonPath('usuario.modulos_tablet', ['porteria'])
            ->assertJsonPath('usuario.capacidades.puede_consultar_porteria', true)
            ->assertJsonPath('usuario.capacidades.puede_registrar_porteria', true)
            ->assertJsonPath('usuario.capacidades.puede_administrar_porteria', false)
            ->assertJsonPath('usuario.capacidades.puede_operar_productos', false)
            ->assertJsonPath('usuario.capacidades.puede_operar_materiales', false)
            ->json('token');
    }

    public function test_guardia_con_perfil_real_inicia_sesion_y_registra_sin_acceder_a_romana_o_administracion(): void
    {
        [$usuario, $dispositivo] = $this->identidad();
        $token = $this->token($usuario, $dispositivo);
        $this->assertSame(['tablet:porteria'], $usuario->tokens()->firstOrFail()->abilities);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/porteria/catalogos')->assertOk();
        $datos = $this->datos();
        $id = $this->withToken($token)->postJson('/api/porteria/ingresos', $datos)
            ->assertCreated()->assertJsonPath('data.guias', ['000123', '000124'])->json('data.id');
        $this->withToken($token)->getJson('/api/porteria/ingresos')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson('/api/porteria/ingresos/'.$id)->assertOk()->assertJsonCount(1, 'eventos');
        $this->withToken($token)->getJson('/api/romana/catalogos')->assertForbidden();
        $this->withToken($token)->postJson('/api/administracion/clientes', ['codigo' => 'NO', 'nombre' => 'No'])->assertForbidden();
        $this->withToken($token)->putJson('/api/porteria/ingresos/'.$id, [...$datos, 'version' => 1, 'motivo_correccion' => 'Cambiar camión'])->assertForbidden();
        $this->withToken($token)->postJson('/api/porteria/ingresos/'.$id.'/anular', ['version' => 1, 'motivo' => 'No llegó el camión'])->assertForbidden();
        $this->withToken($token)->postJson('/api/porteria/ingresos/'.$id.'/revisar', ['version' => 1])->assertForbidden();
        $this->assertDatabaseCount('recepciones_romana', 0);
    }

    public function test_reintentar_la_respuesta_perdida_conserva_un_solo_ingreso_y_su_auditoria(): void
    {
        [$usuario, $dispositivo] = $this->identidad();
        $token = $this->token($usuario, $dispositivo);
        $this->app['auth']->forgetGuards();
        $datos = $this->datos();
        $id = $this->withToken($token)->postJson('/api/porteria/ingresos', $datos)->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/porteria/ingresos', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->withToken($token)->postJson('/api/porteria/ingresos', [...$datos, 'patente' => 'EFGH34'])->assertConflict();
        $this->assertDatabaseCount('ingresos_porteria', 1);
        $this->assertDatabaseCount('eventos_ingreso_porteria', 1);
    }

    public function test_confirma_el_intento_original_aunque_el_cliente_se_haya_desactivado_despues(): void
    {
        [$usuario, $dispositivo] = $this->identidad();
        $token = $this->token($usuario, $dispositivo);
        $cliente = Cliente::create(['codigo' => 'POR-MOVIL', 'nombre' => 'Cliente móvil', 'activo' => true]);
        $datos = $this->datos(['cliente_id' => $cliente->id, 'cliente_otro' => null]);
        $this->app['auth']->forgetGuards();
        $id = $this->withToken($token)->postJson('/api/porteria/ingresos', $datos)->assertCreated()->json('data.id');
        $cliente->update(['activo' => false]);
        $this->withToken($token)->postJson('/api/porteria/ingresos', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->withToken($token)->postJson('/api/porteria/ingresos', [...$datos, 'patente' => 'EFGH34'])->assertConflict();
        $this->withToken($token)->postJson('/api/porteria/ingresos', [...$datos, 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('ingresos_porteria', 1);
        $this->assertDatabaseCount('eventos_ingreso_porteria', 1);
    }

    public function test_el_token_de_otro_modulo_no_permite_usar_porteria(): void
    {
        [$usuario, $dispositivo] = $this->identidad();
        $token = $usuario->crearTokenParaDispositivo($dispositivo, 'otro-modulo', ['tablet:validacion'])->plainTextToken;
        $this->withToken($token)->getJson('/api/porteria/catalogos')->assertForbidden();
        $this->withToken($token)->getJson('/api/porteria/ingresos')->assertForbidden();
        $this->withToken($token)->postJson('/api/porteria/ingresos', $this->datos())->assertForbidden();
        $this->assertDatabaseCount('ingresos_porteria', 0);
    }

    public function test_perfil_inactivo_sin_modulo_o_dispositivo_no_autorizado_no_inicia_sesion(): void
    {
        [$usuario, $dispositivo, $perfil] = $this->identidad();
        $payload = ['email' => $usuario->email, 'password' => 'clave-segura', 'codigo_dispositivo' => $dispositivo->codigo];
        $perfil->update(['activo' => false]);
        $this->postJson('/api/acceso-tablet', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $perfil->update(['activo' => true, 'modulos_tablet' => []]);
        $this->postJson('/api/acceso-tablet', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $perfil->update(['modulos_tablet' => ['porteria']]);
        $dispositivo->update(['activo' => false]);
        $this->postJson('/api/acceso-tablet', $payload)->assertUnprocessable()->assertJsonValidationErrors('codigo_dispositivo');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_migracion_habilita_solo_perfiles_iniciales_compatibles_y_conserva_sus_decisiones(): void
    {
        $personalizado = PerfilAcceso::create([
            'codigo' => 'PORTERIA_CUSTOM', 'nombre' => 'Guardia personalizado', 'rol_base' => RolUsuario::GuardiaPorteria,
            'modulos' => [CatalogoModulosAcceso::OFICINA_PORTERIA], 'modulos_tablet' => [],
            'activo' => true, 'predeterminado' => false,
        ]);
        $guardia = PerfilAcceso::where('codigo', 'GUARDIA_PORTERIA')->firstOrFail();
        $guardia->update(['activo' => false, 'modulos_tablet' => []]);
        $administrador = PerfilAcceso::where('predeterminado', true)->where('rol_base', 'administrador')->firstOrFail();
        $previos = $administrador->modulos_tablet;
        $migracion = require database_path('migrations/2026_10_20_180000_habilitar_porteria_movil.php');
        $migracion->up();
        $migracion->up();
        $this->assertSame(['porteria'], $guardia->refresh()->modulos_tablet);
        $this->assertFalse($guardia->activo);
        $this->assertSame([], $personalizado->refresh()->modulos_tablet);
        $this->assertSame($previos, $administrador->refresh()->modulos_tablet);
    }
}
