<?php

namespace Tests\Feature;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\Autorizacion\CatalogoOficinas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InicioFoliosTest extends TestCase
{
    use RefreshDatabase;

    public function test_folios_es_la_entrada_unica_de_la_web(): void
    {
        $this->get('/FoliOS')
            ->assertOk()
            ->assertSee('id="foliosInicio"', false)
            ->assertSee('id="foliosLoginForm"', false)
            ->assertSee('data-inicio-panel="sin-oficinas"', false)
            ->assertSee('Un solo acceso para toda la planta.')
            ->assertDontSee('data-office-shell', false);
    }

    public function test_otras_formas_de_escribir_la_entrada_y_la_antigua_oficina_llevan_a_folios(): void
    {
        foreach (['/folios', '/FOLIOS', '/Folios'] as $ruta) {
            $this->get($ruta)->assertStatus(301)->assertRedirect('/FoliOS');
        }

        $this->get('/oficina')->assertRedirect('/FoliOS');
    }

    /**
     * @return array<string, array{RolUsuario, string}>
     */
    public static function inicioPorRol(): array
    {
        return [
            'administrador' => [RolUsuario::Administrador, '/oficina/operacion-ahora'],
            'supervisor de frío' => [RolUsuario::SupervisorFrio, '/oficina/operacion-ahora'],
            'despachador' => [RolUsuario::Despachador, '/oficina/frigorifico/despacho/cargas'],
            'operador de romana' => [RolUsuario::OperadorRomana, '/oficina/romana'],
            'digitador de materia prima' => [RolUsuario::DigitadorMateriaPrima, '/oficina/materia-prima/lotes'],
            'supervisor de materiales' => [RolUsuario::SupervisorMateriales, '/oficina/materiales'],
            'camarero de frío' => [RolUsuario::CamareroFrio, '/oficina/frigorifico/camaras'],
            // Sin perfil, Consulta no trae los módulos de Consultas (matriz heredada del rol):
            // su primera oficina habilitada es el resumen de Materia Prima.
            'consulta sin perfil' => [RolUsuario::Consulta, '/oficina/materia-prima'],
        ];
    }

    #[DataProvider('inicioPorRol')]
    public function test_el_inicio_de_sesion_entrega_la_pantalla_de_inicio_del_rol(RolUsuario $rol, string $inicio): void
    {
        $usuario = User::factory()->create(['rol' => $rol]);

        $this->postJson('/api/acceso-oficina', [
            'email' => $usuario->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.inicio', $inicio);
    }

    public function test_un_perfil_sin_la_pantalla_del_rol_parte_en_su_primera_oficina(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'DESPACHO_SOLO_MATERIALES',
            'nombre' => 'Despacho de materiales',
            'rol_base' => RolUsuario::Despachador,
            'modulos' => ['materiales.resumen'],
            'modulos_tablet' => [],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'perfil_acceso_id' => $perfil->id,
        ]);

        $this->postJson('/api/acceso-oficina', [
            'email' => $usuario->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.inicio', '/oficina/materiales');
    }

    public function test_un_perfil_de_consulta_con_consultas_parte_en_consultas(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'CONSULTA_TRAZABILIDAD',
            'nombre' => 'Consulta de trazabilidad',
            'rol_base' => RolUsuario::Consulta,
            'modulos' => ['consultas.busqueda', 'materia-prima.romana'],
            'modulos_tablet' => [],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::Consulta,
            'perfil_acceso_id' => $perfil->id,
        ]);

        $this->postJson('/api/acceso-oficina', [
            'email' => $usuario->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('usuario.inicio', '/oficina/consultas');
    }

    public function test_la_sesion_vigente_entrega_la_identidad_actualizada_y_su_inicio(): void
    {
        $perfil = PerfilAcceso::create([
            'codigo' => 'ROMANA_Y_LOTES',
            'nombre' => 'Romana y lotes',
            'rol_base' => RolUsuario::OperadorRomana,
            'modulos' => ['materia-prima.romana', 'materia-prima.digitacion'],
            'modulos_tablet' => [],
            'activo' => true,
        ]);
        $usuario = User::factory()->create([
            'rol' => RolUsuario::OperadorRomana,
            'perfil_acceso_id' => $perfil->id,
        ]);
        $token = $this->postJson('/api/acceso-oficina', [
            'email' => $usuario->email,
            'password' => 'password',
        ])->assertOk()->json('token');

        $this->withToken($token)
            ->getJson('/api/acceso-oficina')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('usuario.id', $usuario->id)
            ->assertJsonPath('usuario.inicio', '/oficina/romana');

        // El perfil cambió después de iniciar sesión: el inicio se recalcula.
        $perfil->update(['modulos' => ['materia-prima.digitacion']]);
        $this->withToken($token)
            ->getJson('/api/acceso-oficina')
            ->assertOk()
            ->assertJsonPath('usuario.inicio', '/oficina/materia-prima');

        // Sin módulos de oficina, la sesión deja de servir para la web.
        $perfil->update(['modulos' => []]);
        $this->withToken($token)->getJson('/api/acceso-oficina')->assertForbidden();
    }

    public function test_la_sesion_vigente_exige_token(): void
    {
        $this->getJson('/api/acceso-oficina')->assertUnauthorized();
    }

    public function test_el_catalogo_del_servidor_coincide_con_el_menu_de_oficina(): void
    {
        $html = $this->get('/oficina/consultas')->assertOk()->getContent();
        preg_match_all('/data-navigation-targets="([^"]*)"/', $html, $coincidencias);
        $this->assertNotEmpty($coincidencias[1]);

        $menu = collect($coincidencias[1])
            ->flatMap(fn (string $json): array => json_decode(html_entity_decode($json, ENT_QUOTES), true))
            ->map(fn (array $oficina): string => json_encode([$oficina['href'], $oficina['module'], $oficina['permissions']]))
            ->sort()
            ->values()
            ->all();

        $catalogo = collect(app(CatalogoOficinas::class)->oficinas())
            ->map(fn (array $oficina): string => json_encode([$oficina['ruta'], $oficina['modulo'], $oficina['permisos']]))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($menu, $catalogo, 'Si cambia el menú de Oficina, actualiza App\\Services\\Autorizacion\\CatalogoOficinas.');
    }
}
