<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\PerfilAcceso;
use App\Models\ProveedorMaterial;
use App\Models\RecepcionMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermisosMaterialesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_configurar_acciones_revoca_sesiones_y_un_cliente_anterior_no_puede_borrar_la_restriccion(): void
    {
        [$admin, $token, $perfil, $operador] = $this->preparar();
        $sesion = $operador->createToken('operador', ['oficina'])->plainTextToken;
        $this->withToken($sesion)->getJson('/api/materiales/recepciones')->assertOk();
        $this->withToken($token)->putJson('/api/administracion/perfiles-acceso/'.$perfil->id,
            [...$this->datosPerfil(), 'permisos_materiales' => ['recepciones.gestionar']])
            ->assertOk()->assertJsonPath('sesiones_revocadas', true)
            ->assertJsonPath('data.permisos_materiales', ['recepciones.gestionar']);
        $this->assertSame(0, $operador->tokens()->count());
        $this->withToken($sesion)->getJson('/api/materiales/recepciones')->assertUnauthorized();
        $nueva = $operador->createToken('operador-nuevo', ['oficina'])->plainTextToken;
        $this->withToken($token)->putJson('/api/administracion/perfiles-acceso/'.$perfil->id, $this->datosPerfil())
            ->assertOk()->assertJsonPath('sesiones_revocadas', false)
            ->assertJsonPath('data.permisos_materiales', ['recepciones.gestionar']);
        $this->assertSame(1, $operador->tokens()->count());
        $this->withToken($token)->putJson('/api/administracion/perfiles-acceso/'.$perfil->id,
            [...$this->datosPerfil(), 'permisos_materiales' => null])->assertUnprocessable();
        $this->withToken($nueva)->getJson('/api/materiales/recepciones')->assertOk();
    }

    public function test_api_impide_confirmar_corregir_anular_eliminar_e_imprimir_a_quien_solo_crea_borradores(): void
    {
        [$admin, , $perfil, $operador] = $this->preparar();
        $perfil->update(['permisos_materiales' => ['recepciones.gestionar']]);
        $recepcion = $this->recepcion($admin);
        $sesion = $operador->createToken('solo-borrador', ['oficina'])->plainTextToken;
        $this->withToken($sesion)->getJson('/api/materiales/recepciones/'.$recepcion->id)->assertOk();
        foreach (['confirmar', 'anular', 'etiquetas'] as $accion) {
            $this->withToken($sesion)->postJson('/api/materiales/recepciones/'.$recepcion->id.'/'.$accion,
                ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1])->assertForbidden();
        }
        $this->withToken($sesion)->putJson('/api/materiales/recepciones/'.$recepcion->id.'/administrar', [])->assertForbidden();
        $this->withToken($sesion)->deleteJson('/api/materiales/recepciones/'.$recepcion->id, [])->assertForbidden();
        $this->assertSame(1, $recepcion->fresh()->version);
        $this->assertSame('borrador', $recepcion->fresh()->estado->value);
        $this->assertDatabaseCount('eventos_recepciones_materiales', 0);
    }

    public function test_confirmador_puede_leer_borradores_de_otro_usuario_sin_poder_crear_corregir_o_anular(): void
    {
        [$admin, , $perfil, $operador] = $this->preparar();
        $perfil->update(['permisos_materiales' => ['recepciones.confirmar']]);
        $recepcion = $this->recepcion($admin);
        $sesion = $operador->createToken('confirmador', ['oficina'])->plainTextToken;
        $this->withToken($sesion)->getJson('/api/materiales/recepciones')->assertOk()->assertJsonFragment(['id' => $recepcion->id]);
        $this->withToken($sesion)->getJson('/api/materiales/recepciones/'.$recepcion->id)->assertOk();
        // Supera la autorización y llega al control documental: no genera stock sin foto.
        $this->withToken($sesion)->postJson('/api/materiales/recepciones/'.$recepcion->id.'/confirmar',
            ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1])->assertUnprocessable()->assertJsonPath('codigo', 'recepcion_sin_foto_documento');
        $this->withToken($sesion)->postJson('/api/materiales/recepciones', [])->assertForbidden();
        $this->withToken($sesion)->postJson('/api/materiales/recepciones/'.$recepcion->id.'/anular', [])->assertForbidden();
    }

    public function test_corregir_y_eliminar_son_independientes_y_eliminar_permite_consultar_evidencias(): void
    {
        [$admin, , $perfil, $operador] = $this->preparar();
        $recepcion = $this->recepcion($admin);
        $sesion = $operador->createToken('correccion', ['oficina'])->plainTextToken;
        $perfil->update(['permisos_materiales' => ['recepciones.corregir']]);
        $this->withToken($sesion)->deleteJson('/api/materiales/recepciones/'.$recepcion->id, [])->assertForbidden();
        $perfil->update(['permisos_materiales' => ['recepciones.eliminar']]);
        $this->withToken($sesion)->putJson('/api/materiales/recepciones/'.$recepcion->id.'/administrar', [])->assertForbidden();
        $this->withToken($sesion)->getJson('/api/materiales/recepciones/eliminaciones')->assertOk();
        // Autorizado a eliminar, pero exige los antecedentes de la operación.
        $this->withToken($sesion)->deleteJson('/api/materiales/recepciones/'.$recepcion->id, [])->assertUnprocessable();
    }

    public function test_rechaza_acciones_desconocidas_sin_oficina_y_en_solo_consulta(): void
    {
        [, $token, $perfil] = $this->preparar();
        foreach ([
            [...$this->datosPerfil(), 'permisos_materiales' => ['no-existe']],
            [...$this->datosPerfil(), 'modulos' => ['materiales.etiquetas'], 'permisos_materiales' => ['inventario.aprobar_ajustes']],
            [...$this->datosPerfil(), 'rol_base' => 'consulta', 'permisos_materiales' => ['recepciones.confirmar']],
            [...$this->datosPerfil(), 'rol_base' => 'camarero_materiales', 'permisos_materiales' => ['inventario.aprobar_ajustes']],
        ] as $datos) {
            $this->withToken($token)->putJson('/api/administracion/perfiles-acceso/'.$perfil->id, $datos)->assertUnprocessable();
        }
        $this->assertNull($perfil->fresh()->permisos_materiales);
        $this->withToken($token)->getJson('/api/administracion/perfiles-acceso')->assertOk()
            ->assertJsonFragment(['clave' => 'recepciones.confirmar', 'nombre' => 'Confirmar y generar inventario']);
    }

    public function test_quitar_una_oficina_retira_sus_acciones_y_la_configuracion_de_camaras_exige_su_modulo(): void
    {
        [, $token, $perfil, $operador] = $this->preparar();
        $perfil->update(['permisos_materiales' => ['inventario.transferir_cliente', 'recepciones.confirmar']]);
        $this->withToken($token)->putJson('/api/administracion/perfiles-acceso/'.$perfil->id,
            [...$this->datosPerfil(), 'modulos' => ['materiales.etiquetas']])->assertOk()
            ->assertJsonPath('data.permisos_materiales', ['recepciones.confirmar']);
        $this->assertFalse($operador->fresh()->can('administrar-camaras'));
        $perfil->update(['modulos' => ['administracion.camaras']]);
        $this->assertTrue($operador->fresh()->can('administrar-camaras'));
        $this->assertFalse($operador->fresh()->can('administrar-accesos'));
    }

    private function datosPerfil(): array
    {
        return ['codigo' => 'MAT-ACCIONES', 'nombre' => 'Materiales por acciones', 'rol_base' => 'supervisor_materiales',
            'modulos' => ['materiales.etiquetas', 'materiales.inventario', 'materiales.despachos'], 'modulos_tablet' => [], 'activo' => true];
    }

    private function preparar(): array
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true, 'perfil_acceso_id' => null]);
        $token = $admin->createToken('admin-acciones', ['oficina'])->plainTextToken;
        $perfil = PerfilAcceso::create($this->datosPerfil());
        $operador = User::factory()->create(['rol' => RolUsuario::SupervisorMateriales, 'activo' => true, 'perfil_acceso_id' => $perfil->id]);

        return [$admin, $token, $perfil, $operador];
    }

    private function recepcion(User $autor): RecepcionMaterial
    {
        $catalogo = ClienteMaterial::with('temporada')->where('codigo', 'GENERAL')->firstOrFail();
        $proveedor = ProveedorMaterial::create(['codigo' => 'PERMISOS', 'nombre' => 'Proveedor permisos', 'activo' => true,
            'creado_por_user_id' => $autor->id, 'actualizado_por_user_id' => $autor->id]);

        return RecepcionMaterial::create(['operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'temporada_id' => $catalogo->temporada->temporada_id, 'cliente_id' => $catalogo->cliente_id,
            'proveedor_material_id' => $proveedor->id, 'numero_guia_despacho' => 'GD-ACCIONES', 'estado' => 'borrador',
            'version' => 1, 'creado_por_user_id' => $autor->id]);
    }
}
