<?php

namespace Tests\Unit;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\PersonalAccessToken;
use App\Models\TomaInventarioMaterial;
use App\Models\User;
use App\Services\Autorizacion\CatalogoPermisosMateriales;
use App\Services\Materiales\ServicioTomaInventarioMaterial;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PermisosAccionesMaterialesTest extends TestCase
{
    private const ACCIONES = [
        'recepciones.gestionar' => 'gestionar-recepciones-materiales',
        'recepciones.confirmar' => 'confirmar-recepciones-materiales',
        'recepciones.corregir' => 'administrar-recepciones-materiales',
        'recepciones.anular' => 'anular-recepciones-materiales',
        'recepciones.eliminar' => 'eliminar-recepciones-materiales',
        'recepciones.imprimir' => 'imprimir-etiquetas-materiales',
        'despachos.gestionar' => 'gestionar-despachos-materiales',
        'despachos.retirar' => 'retirar-materiales',
        'despachos.cancelar' => 'cancelar-despachos-materiales',
        'inventario.aprobar_ajustes' => 'aprobar-ajustes-materiales',
        'inventario.corregir_item' => 'corregir-items-estibados-materiales',
        'inventario.transferir_cliente' => 'transferir-folios-materiales-clientes',
        'inventario.bloquear' => 'gestionar-bloqueos-materiales',
        'catalogos.administrar' => 'administrar-catalogos-materiales',
        'inventario.editar_datos_item' => 'editar-niveles-stock-materiales',
        'recetas.administrar' => 'administrar-recetas-materiales',
        'transformaciones.planificar' => 'gestionar-transformaciones-materiales',
        'transformaciones.operar' => 'operar-transformaciones-materiales',
        'transformaciones.revertir' => 'revertir-transformaciones-materiales',
        'recepciones.autorizar_tardios' => 'autorizar-recepciones-tardias-materiales',
        'despachos.autorizar_tardios' => 'autorizar-despachos-tardios-materiales',
    ];

    public function test_cada_accion_es_independiente_de_las_demas_y_las_alias_no_eluden_la_restriccion(): void
    {
        $catalogo = app(CatalogoPermisosMateriales::class);
        $this->assertEqualsCanonicalizing(array_keys(self::ACCIONES), $catalogo->claves());
        foreach (self::ACCIONES as $seleccion => $habilidad) {
            $usuario = $this->usuario([$seleccion]);
            foreach (self::ACCIONES as $otra => $gate) {
                $this->assertSame($seleccion === $otra, $usuario->can($gate), "$seleccion / $gate");
            }
        }
    }

    public function test_activar_despachos_tardios_no_otorga_autorizacion_a_perfiles_historicos(): void
    {
        $usuario = $this->usuario(null);
        $this->assertFalse($usuario->can('autorizar-despachos-tardios-materiales'));
        $usuario->perfilAcceso->protegido = true;
        $this->assertTrue($usuario->can('autorizar-despachos-tardios-materiales'));
    }

    public function test_quitar_escrituras_no_quita_lectura_ni_entrega_administracion_de_camaras_o_accesos(): void
    {
        $usuario = $this->usuario([]);
        $this->assertTrue($usuario->can('consultar-recepciones-materiales'));
        $this->assertTrue($usuario->can('supervisar-recepciones-materiales'));
        $this->assertTrue($usuario->can('consultar-despachos-materiales'));
        foreach (self::ACCIONES as $gate) {
            $this->assertFalse($usuario->can($gate));
        }
        $this->assertFalse($usuario->can('administrar-camaras'));
        $this->assertFalse($usuario->can('administrar-accesos'));
    }

    public function test_acciones_no_abren_oficinas_faltantes_ni_superan_solo_consulta_o_perfiles_inactivos(): void
    {
        $usuario = $this->usuario(array_keys(self::ACCIONES));
        $usuario->perfilAcceso->modulos = ['materiales.etiquetas'];
        $this->assertFalse($usuario->can('transferir-folios-materiales-clientes'));
        $this->assertTrue($usuario->can('confirmar-recepciones-materiales'));
        $usuario->perfilAcceso->rol_base = RolUsuario::Consulta;
        foreach (self::ACCIONES as $gate) {
            $this->assertFalse($usuario->can($gate));
        }
        $usuario->perfilAcceso->rol_base = RolUsuario::Administrador;
        $usuario->perfilAcceso->activo = false;
        $this->assertFalse($usuario->can('confirmar-recepciones-materiales'));
        $usuario->setRelation('perfilAcceso', null);
        $this->assertFalse($usuario->can('confirmar-recepciones-materiales'));
    }

    public function test_perfiles_anteriores_conservan_acciones_actuales_y_tardios_exigen_configuracion_explicita(): void
    {
        $usuario = $this->usuario(null);
        $this->assertTrue($usuario->can('eliminar-recepciones-materiales'));
        $this->assertTrue($usuario->can('aprobar-ajustes-materiales'));
        $this->assertFalse($usuario->can('autorizar-recepciones-tardias-materiales'));
        $usuario->perfilAcceso->protegido = true;
        $this->assertTrue($usuario->can('autorizar-recepciones-tardias-materiales'));
        $usuario->perfil_acceso_id = null;
        $usuario->setRelation('perfilAcceso', null);
        $this->assertTrue($usuario->can('confirmar-recepciones-materiales'));
    }

    public function test_permiso_explicito_no_supera_el_espacio_autorizado_de_la_tablet(): void
    {
        $usuario = $this->usuario(['recepciones.confirmar']);
        $token = (new PersonalAccessToken)->forceFill(['dispositivo_id' => (string) Str::uuid(), 'abilities' => ['tablet:operacion_materiales']]);
        $usuario->withAccessToken($token);
        $this->assertFalse($usuario->can('confirmar-recepciones-materiales'));
        $token->abilities = ['tablet:recepcion_materiales'];
        $this->assertTrue($usuario->can('confirmar-recepciones-materiales'));
    }

    public function test_servicio_de_inventario_rechaza_aplicar_ajustes_sin_el_permiso_antes_de_mutar(): void
    {
        try {
            app(ServicioTomaInventarioMaterial::class)->operar(new TomaInventarioMaterial, 'aprobar', [], $this->usuario([]));
            $this->fail('No debe intentar aplicar inventario.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_configuracion_de_camaras_se_delega_por_su_modulo_independiente(): void
    {
        $usuario = $this->usuario([]);
        $usuario->rol = RolUsuario::SupervisorMateriales;
        $usuario->perfilAcceso->rol_base = RolUsuario::SupervisorMateriales;
        $this->assertFalse($usuario->can('administrar-camaras'));
        $usuario->perfilAcceso->modulos = ['administracion.camaras'];
        $this->assertTrue($usuario->can('administrar-camaras'));
        $this->assertFalse($usuario->can('administrar-accesos'));
        $usuario->perfilAcceso->rol_base = RolUsuario::Consulta;
        $this->assertTrue($usuario->can('consultar-configuracion-camaras'));
        $this->assertFalse($usuario->can('administrar-camaras'));
    }

    private function usuario(?array $permisos): User
    {
        $perfil = (new PerfilAcceso)->forceFill(['id' => (string) Str::uuid(), 'rol_base' => RolUsuario::Administrador,
            'activo' => true, 'protegido' => false, 'modulos' => ['materiales.etiquetas', 'materiales.inventario', 'materiales.despachos', 'materiales.catalogos', 'materiales.recetas', 'materiales.ordenes'],
            'modulos_tablet' => ['recepcion_materiales', 'operacion_materiales'], 'permisos_materiales' => $permisos]);

        return (new User)->forceFill(['id' => 1, 'activo' => true, 'rol' => RolUsuario::Administrador, 'perfil_acceso_id' => $perfil->id])->setRelation('perfilAcceso', $perfil);
    }
}
