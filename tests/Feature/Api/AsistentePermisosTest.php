<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AsistentePermisosTest extends TestCase
{
    use RefreshDatabase;

    public function test_perfil_con_modulo_asistente_puede_usarlo(): void
    {
        $usuario = $this->usuarioConPerfil(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->assertTrue(Gate::forUser($usuario)->allows('usar-asistente'));
        $this->assertTrue($this->capacidades($usuario)['puede_usar_asistente']);
        $this->assertFalse(Gate::forUser($usuario)->allows('administrar-asistente'));
    }

    public function test_perfil_sin_modulo_no_puede(): void
    {
        $usuario = $this->usuarioConPerfil(RolUsuario::SupervisorFrio, ['materia-prima.romana']);

        $this->assertFalse(Gate::forUser($usuario)->allows('usar-asistente'));
        $this->assertFalse($this->capacidades($usuario)['puede_usar_asistente']);
    }

    public function test_rol_cliente_nunca_puede_aunque_el_perfil_tenga_el_modulo(): void
    {
        $usuario = $this->usuarioConPerfil(RolUsuario::Cliente, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->assertFalse(Gate::forUser($usuario)->allows('usar-asistente'));
    }

    public function test_solo_administrador_con_modulo_de_configuracion_administra(): void
    {
        $modulos = [CatalogoModulosAcceso::OFICINA_ASISTENTE, CatalogoModulosAcceso::OFICINA_ASISTENTE_CONFIGURACION];
        $administrador = $this->usuarioConPerfil(RolUsuario::Administrador, $modulos);
        $supervisor = $this->usuarioConPerfil(RolUsuario::SupervisorFrio, $modulos);
        $administradorSinModulo = $this->usuarioConPerfil(RolUsuario::Administrador, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->assertTrue(Gate::forUser($administrador)->allows('administrar-asistente'));
        $this->assertTrue($this->capacidades($administrador)['puede_administrar_asistente']);
        $this->assertFalse(Gate::forUser($supervisor)->allows('administrar-asistente'));
        $this->assertFalse(Gate::forUser($administradorSinModulo)->allows('administrar-asistente'));
    }

    /** @param  array<int, string>  $modulos */
    private function usuarioConPerfil(RolUsuario $rol, array $modulos): User
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'ASIST-'.$rol->value.'-'.count($modulos),
            'nombre' => 'Perfil '.$rol->value,
            'rol_base' => $rol,
            'modulos' => $modulos,
            'activo' => true,
        ]);

        return User::factory()->create([
            'rol' => $rol,
            'activo' => true,
            'perfil_acceso_id' => $perfil->id,
        ]);
    }

    /** @return array<string, bool> */
    private function capacidades(User $usuario): array
    {
        return app(AlcanceOperacionalUsuario::class)->capacidadesApi($usuario);
    }
}
