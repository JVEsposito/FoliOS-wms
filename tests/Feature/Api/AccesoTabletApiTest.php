<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\CondicionSag;
use App\Models\Dispositivo;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccesoTabletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_operador_accede_con_credenciales_y_tablet_autorizada(): void
    {
        [$usuario, $dispositivo] = $this->crearIdentidad();

        $token = $this->postJson('/api/acceso-tablet', [
            'email' => 'operador@example.com',
            'password' => 'clave-segura',
            'codigo_dispositivo' => 'TABLET-01',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('usuario.id', $usuario->id)
            ->assertJsonPath('usuario.rol', 'camarero_frio')
            ->assertJsonPath('usuario.ambito_camaras', 'productos')
            ->assertJsonPath('usuario.capacidades.puede_operar_productos', true)
            ->assertJsonPath('usuario.capacidades.puede_operar_materiales', false)
            ->assertJsonPath('usuario.capacidades.puede_consultar_fruta_proceso', true)
            ->assertJsonPath('usuario.capacidades.puede_entregar_fruta_proceso', true)
            ->assertJsonPath(
                'usuario.modulos_tablet.0',
                CatalogoModulosAcceso::TABLET_FRUTA_PROCESO,
            )
            ->assertJsonPath('dispositivo.id', $dispositivo->id)
            ->json('token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $usuario->id);
        $this->assertNotNull($dispositivo->refresh()->ultimo_acceso_at);
    }

    public function test_credenciales_invalidas_no_generan_un_token(): void
    {
        [$usuario] = $this->crearIdentidad();

        $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'incorrecta',
            'codigo_dispositivo' => 'TABLET-01',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_clave_temporal_bloquea_operacion_hasta_cambiarla_y_revoca_otras_sesiones(): void
    {
        [$usuario, $dispositivo] = $this->crearIdentidad();
        $usuario->update(['debe_cambiar_password' => true]);
        $otroToken = $usuario->createToken('otra-tablet')->accessToken;

        $token = $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])->assertOk()->assertJsonPath('usuario.debe_cambiar_password', true)->json('token');

        $this->withToken($token)->getJson('/api/camaras')
            ->assertForbidden()->assertJsonPath('codigo', 'cambio_password_requerido');

        $this->withToken($token)->putJson('/api/usuario/password', [
            'password_actual' => 'incorrecta',
            'password_nueva' => 'NuevaClave2026',
            'password_nueva_confirmation' => 'NuevaClave2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('password_actual');

        $this->withToken($token)->putJson('/api/usuario/password', [
            'password_actual' => 'clave-segura',
            'password_nueva' => 'clave-segura',
            'password_nueva_confirmation' => 'clave-segura',
        ])->assertUnprocessable()->assertJsonValidationErrors('password_nueva');

        $this->withToken($token)->putJson('/api/usuario/password', [
            'password_actual' => 'clave-segura',
            'password_nueva' => 'NuevaClave2026',
            'password_nueva_confirmation' => 'NuevaClave2026',
        ])->assertOk()->assertJsonPath('debe_cambiar_password', false);

        $this->assertFalse($usuario->refresh()->debe_cambiar_password);
        $this->assertTrue(Hash::check('NuevaClave2026', $usuario->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otroToken->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        auth()->forgetGuards();
        $this->withToken($token)->getJson('/api/camaras')->assertOk();

        $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])->assertUnprocessable();

        $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'NuevaClave2026',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])->assertOk()->assertJsonPath('usuario.debe_cambiar_password', false);
    }

    public function test_una_tablet_inactiva_no_puede_iniciar_turno(): void
    {
        [$usuario, $dispositivo] = $this->crearIdentidad();
        $dispositivo->update(['activo' => false]);

        $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo_dispositivo');
    }

    public function test_un_nuevo_acceso_en_la_misma_tablet_revoca_el_token_anterior(): void
    {
        [$usuario] = $this->crearIdentidad();
        $payload = [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => 'TABLET-01',
        ];
        $primerToken = $this->postJson('/api/acceso-tablet', $payload)->json('token');
        $segundoToken = $this->postJson('/api/acceso-tablet', $payload)->json('token');

        auth()->forgetGuards();
        $this->withToken($primerToken)->getJson('/api/user')->assertUnauthorized();
        auth()->forgetGuards();
        $this->withToken($segundoToken)->getJson('/api/user')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_materiales_publica_camara_y_recepcion_como_modulos_tablet_independientes(): void
    {
        $usuario = User::factory()->create([
            'email' => 'materiales@example.com',
            'password' => Hash::make('clave-segura'),
            'rol' => RolUsuario::CamareroMateriales,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-MAT-01',
            'nombre' => 'Tablet materiales',
        ]);

        $respuesta = $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])
            ->assertOk()
            ->assertJsonCount(2, 'usuario.modulos_tablet')
            ->assertJsonPath(
                'usuario.modulos_tablet.0',
                CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
            )
            ->assertJsonPath(
                'usuario.modulos_tablet.1',
                CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
            )
            ->assertJsonPath('usuario.capacidades.puede_operar_materiales', true)
            ->assertJsonPath('usuario.capacidades.puede_consultar_recepciones_materiales', true)
            ->assertJsonPath('usuario.capacidades.puede_consultar_despachos_materiales', true)
            ->assertJsonPath('usuario.capacidades.puede_consultar_transformaciones_materiales', true)
            ->assertJsonPath('usuario.ambito_camaras', 'materiales');

        $token = $respuesta->json('token');

        $this->withToken($token)
            ->getJson('/api/camaras')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/materiales/recepciones')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/materiales/despachos')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/materiales/transformaciones/ordenes')
            ->assertOk();
    }

    public function test_hidrocooler_mp_se_puede_abrir_en_tablet_con_perfil_autorizado(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'HIDRO-TABLET',
            'nombre' => 'Hidrocooler en tablet',
            'rol_base' => RolUsuario::DigitadorMateriaPrima,
            'modulos' => ['materia-prima.hidrocooler'],
            'modulos_tablet' => [CatalogoModulosAcceso::TABLET_HIDROCOOLER_MP],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'email' => 'hidro@example.com',
            'password' => Hash::make('clave-segura'),
            'rol' => RolUsuario::DigitadorMateriaPrima,
            'perfil_acceso_id' => $perfil->id,
        ]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-HIDRO', 'nombre' => 'Tablet Hidrocooler']);

        $token = $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])
            ->assertOk()
            ->assertJsonPath('usuario.modulos_tablet.0', CatalogoModulosAcceso::TABLET_HIDROCOOLER_MP)
            ->assertJsonCount(1, 'usuario.modulos_tablet')
            ->assertJsonPath('usuario.capacidades.puede_consultar_hidrocooler_materia_prima', true)
            ->assertJsonPath('usuario.capacidades.puede_operar_hidrocooler_materia_prima', true)
            ->assertJsonPath('usuario.capacidades.puede_supervisar_lotes_materia_prima', false)
            ->json('token');

        $this->withToken($token)->getJson('/api/materia-prima/hidrocooler/resumen')->assertOk();
        $this->withToken($token)->getJson('/api/materia-prima/hidrocooler/lotes')->assertOk();
    }

    public function test_un_perfil_solo_de_oficina_no_puede_iniciar_turno_en_tablet(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'MAT-OFICINA',
            'nombre' => 'Materiales solo oficina',
            'rol_base' => RolUsuario::CamareroMateriales,
            'modulos' => ['materiales.inventario'],
            'modulos_tablet' => [],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'email' => 'materiales-oficina@example.com',
            'password' => Hash::make('clave-segura'),
            'rol' => RolUsuario::CamareroMateriales,
            'perfil_acceso_id' => $perfil->id,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-MAT-02',
            'nombre' => 'Tablet materiales 02',
        ]);

        $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonPath(
                'errors.email.0',
                'El perfil del usuario se encuentra inactivo o no posee módulos tablet habilitados.',
            );

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_un_perfil_publica_solo_los_modulos_tablet_seleccionados(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'MAT-RECEPCION-PDA',
            'nombre' => 'Recepción materiales PDA',
            'rol_base' => RolUsuario::CamareroMateriales,
            'modulos' => ['materiales.etiquetas', 'materiales.inventario'],
            'modulos_tablet' => [
                CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
            ],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'email' => 'recepcion-materiales@example.com',
            'password' => Hash::make('clave-segura'),
            'rol' => RolUsuario::CamareroMateriales,
            'perfil_acceso_id' => $perfil->id,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-MAT-03',
            'nombre' => 'Tablet recepción materiales',
        ]);

        $respuesta = $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])
            ->assertOk()
            ->assertJsonPath(
                'usuario.modulos_tablet.0',
                CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
            )
            ->assertJsonCount(1, 'usuario.modulos_tablet')
            ->assertJsonPath('usuario.capacidades.puede_operar_materiales', false)
            ->assertJsonPath('usuario.capacidades.puede_consultar_recepciones_materiales', true)
            ->assertJsonPath('usuario.ambito_camaras', 'ninguno');

        $this->withToken($respuesta->json('token'))
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_un_perfil_puede_operar_camara_materiales_sin_habilitar_recepcion(): void
    {
        $camara = Camara::create([
            'codigo' => 'CAM-MAT-PDA',
            'nombre' => 'Cámara materiales PDA',
            'contenido' => ContenidoCamara::Materiales,
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $perfil = PerfilAcceso::create([
            'codigo' => 'MAT-CAMARA-PDA',
            'nombre' => 'Cámara materiales PDA',
            'rol_base' => RolUsuario::CamareroMateriales,
            'modulos' => ['materiales.inventario'],
            'modulos_tablet' => [
                CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
            ],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'email' => 'camara-materiales@example.com',
            'password' => Hash::make('clave-segura'),
            'rol' => RolUsuario::CamareroMateriales,
            'perfil_acceso_id' => $perfil->id,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-MAT-04',
            'nombre' => 'Tablet cámara materiales',
        ]);

        $respuesta = $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => $dispositivo->codigo,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'usuario.modulos_tablet')
            ->assertJsonPath(
                'usuario.modulos_tablet.0',
                CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
            )
            ->assertJsonPath('usuario.capacidades.puede_operar_materiales', true)
            ->assertJsonPath('usuario.capacidades.puede_consultar_recepciones_materiales', false)
            ->assertJsonPath('usuario.ambito_camaras', 'materiales');

        $this->withToken($respuesta->json('token'))
            ->getJson('/api/camaras')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $camara->id)
            ->assertJsonPath('data.0.contenido', ContenidoCamara::Materiales->value);

        $this->withToken($respuesta->json('token'))
            ->getJson('/api/materiales/recepciones')
            ->assertForbidden();
    }

    public function test_el_perfil_inicial_de_materiales_recupera_la_operacion_de_camaras(): void
    {
        $perfil = PerfilAcceso::query()
            ->where('codigo', 'CAMARERO_MATERIALES')
            ->where('predeterminado', true)
            ->firstOrFail();

        $this->assertContains(
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
            $perfil->modulos_tablet,
        );
        $this->assertContains(
            CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
            $perfil->modulos_tablet,
        );
    }

    public function test_el_perfil_inicial_de_camarero_publica_fruta_a_proceso_en_tablet(): void
    {
        $perfil = PerfilAcceso::query()
            ->where('codigo', 'CAMARERO_FRIO')
            ->where('predeterminado', true)
            ->firstOrFail();

        $this->assertContains('materia-prima.fruta-proceso', $perfil->modulos);
        $this->assertContains(
            CatalogoModulosAcceso::TABLET_FRUTA_PROCESO,
            $perfil->modulos_tablet,
        );
    }

    public function test_cerrar_turno_revoca_el_token_actual(): void
    {
        [$usuario] = $this->crearIdentidad();
        $token = $this->postJson('/api/acceso-tablet', [
            'email' => $usuario->email,
            'password' => 'clave-segura',
            'codigo_dispositivo' => 'TABLET-01',
        ])->json('token');

        $this->withToken($token)
            ->deleteJson('/api/acceso-tablet')
            ->assertNoContent();

        auth()->forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_el_catalogo_sag_solo_incluye_condiciones_activas(): void
    {
        [$usuario, $dispositivo] = $this->crearIdentidad();
        $activa = CondicionSag::create([
            'codigo' => 'APTA',
            'nombre' => 'Apta para exportación',
        ]);
        CondicionSag::create([
            'codigo' => 'INACTIVA',
            'nombre' => 'Condición inactiva',
            'activo' => false,
        ]);
        $token = $usuario
            ->crearTokenParaDispositivo($dispositivo, 'tablet-prueba')
            ->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/condiciones-sag')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activa->id)
            ->assertJsonPath('data.0.codigo', 'APTA');
    }

    /**
     * @return array{User, Dispositivo}
     */
    private function crearIdentidad(): array
    {
        $usuario = User::factory()->create([
            'email' => 'operador@example.com',
            'password' => Hash::make('clave-segura'),
            'rol' => RolUsuario::CamareroFrio,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-01',
            'nombre' => 'Tablet de prueba',
        ]);

        return [$usuario, $dispositivo];
    }
}
