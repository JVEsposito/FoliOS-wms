<?php

namespace App\Services\Autorizacion;

use App\Enums\RolUsuario;
use App\Models\PerfilAcceso;
use App\Models\User;

class CatalogoPermisosMateriales
{
    public function catalogo(): array
    {
        return [
            $this->grupo('recepciones', 'Recepciones', ['materiales.etiquetas'], [
                'recepciones.gestionar' => 'Crear y editar borradores; adjuntar fotos',
                'recepciones.confirmar' => 'Confirmar y generar inventario',
                'recepciones.corregir' => 'Corregir administrativamente',
                'recepciones.anular' => 'Anular recepciones',
                'recepciones.eliminar' => 'Eliminar con conservación de evidencia',
                'recepciones.imprimir' => 'Imprimir etiquetas',
                'recepciones.valorizar' => 'Asignar y corregir valores de compra netos',
            ]),
            $this->grupo('despachos', 'Despachos', ['materiales.despachos'], [
                'despachos.gestionar' => 'Crear y gestionar despachos',
                'despachos.retirar' => 'Retirar materiales',
                'despachos.cancelar' => 'Cancelar despachos',
            ]),
            $this->grupo('inventario', 'Inventario', ['materiales.inventario'], [
                'inventario.aprobar_ajustes' => 'Aprobar tomas y aplicar ajustes de inventario',
                'inventario.corregir_item' => 'Corregir el ítem de un folio',
                'inventario.transferir_cliente' => 'Transferir folios entre clientes',
                'inventario.bloquear' => 'Bloquear y desbloquear materiales',
            ]),
            $this->grupo('catalogos', 'Catálogos', ['materiales.catalogos'], [
                'catalogos.administrar' => 'Administrar catálogos de Materiales',
            ]),
            $this->grupo('datos_items', 'Datos operativos de ítems', ['materiales.catalogos', 'materiales.inventario'], [
                'inventario.editar_datos_item' => 'Editar niveles de stock y fotos de ítems',
            ]),
            $this->grupo('capacidad', 'Capacidad de bodegas', ['materiales.inventario'], [
                'capacidad.configurar' => 'Configurar bodegas, zonas útiles y tipos de pallet',
                'capacidad.medir' => 'Registrar medidas reales de las cargas',
            ]),
            $this->grupo('recetas', 'Recetas', ['materiales.recetas'], [
                'recetas.administrar' => 'Crear y editar recetas',
            ]),
            $this->grupo('transformaciones', 'Transformaciones', ['materiales.ordenes'], [
                'transformaciones.planificar' => 'Planificar órdenes',
                'transformaciones.operar' => 'Operar órdenes y registrar consumos',
                'transformaciones.revertir' => 'Revertir transformaciones',
            ]),
            $this->grupo('regularizacion_recepciones', 'Autorizaciones de recepción', ['materiales.etiquetas'], [
                'recepciones.autorizar_tardios' => 'Autorizar recepciones retroactivas y provisionales',
            ]),
            $this->grupo('regularizacion_despachos', 'Autorizaciones de despacho', ['materiales.despachos'], [
                'despachos.autorizar_tardios' => 'Autorizar despachos retroactivos y provisionales',
            ]),
        ];
    }

    public function claves(): array
    {
        return collect($this->catalogo())->flatMap(fn ($grupo) => array_column($grupo['acciones'], 'clave'))->all();
    }

    public function compatibles(array $modulos, bool $consulta = false, bool $incluirPreparados = true): array
    {
        if ($consulta) {
            return [];
        }

        return collect($this->catalogo())
            ->filter(fn ($grupo) => ($incluirPreparados || ! $grupo['preparado']) && array_intersect($grupo['modulos'], $modulos) !== [])
            ->flatMap(fn ($grupo) => array_column($grupo['acciones'], 'clave'))->values()->all();
    }

    public function efectivosPerfil(PerfilAcceso $perfil): array
    {
        $compatibles = $this->compatibles($perfil->modulos ?? [], $perfil->rol_base === RolUsuario::Consulta);
        $seleccion = $perfil->permisos_materiales;
        if ($seleccion === null) {
            $seleccion = $this->compatibles($perfil->modulos ?? [], $perfil->rol_base === RolUsuario::Consulta, (bool) $perfil->protegido);
            if (! $perfil->protegido) {
                // Activar el circuito no concede autorizaciones a perfiles históricos sin selección explícita.
                $seleccion = array_values(array_filter($seleccion, fn (string $accion): bool => ! str_ends_with($accion, '.autorizar_tardios')));
            }
        }

        // La valorización es una autorización nueva y explícita para perfiles personalizados.
        if ($perfil->permisos_materiales === null && ! $perfil->protegido) {
            $seleccion = array_values(array_diff($seleccion, ['recepciones.valorizar']));
        }
        $efectivos = array_values(array_intersect($compatibles, $seleccion));
        if (! in_array($perfil->rol_base, [RolUsuario::Administrador, RolUsuario::SupervisorMateriales], true)) {
            $efectivos = array_values(array_diff($efectivos, ['inventario.aprobar_ajustes', 'recepciones.valorizar']));
        }
        // Las nuevas escrituras de capacidad requieren selección explícita
        // en perfiles existentes, salvo el Administrador protegido.
        if ($perfil->permisos_materiales === null && ! $perfil->protegido) {
            $efectivos = array_values(array_diff($efectivos, ['capacidad.configurar', 'capacidad.medir']));
        }

        return $efectivos;
    }

    /** Se aplica además del permiso histórico: no habilita módulos ni eleva roles. */
    public function permite(User $usuario, string $accion): bool
    {
        if (! $usuario->activo || $usuario->rol === RolUsuario::Consulta || ! in_array($accion, $this->claves(), true)) {
            return false;
        }
        if ($usuario->perfil_acceso_id === null) {
            if (str_starts_with($accion, 'capacidad.')) {
                return $usuario->rol === RolUsuario::Administrador
                    || ($accion === 'capacidad.medir' && $usuario->rol === RolUsuario::SupervisorMateriales);
            }

            return ! str_ends_with($accion, '.autorizar_tardios') || $usuario->rol === RolUsuario::Administrador;
        }
        $usuario->loadMissing('perfilAcceso');
        $perfil = $usuario->perfilAcceso;

        return $perfil && $perfil->activo && in_array($accion, $this->efectivosPerfil($perfil), true);
    }

    private function grupo(string $clave, string $nombre, array $modulos, array $acciones, bool $preparado = false): array
    {
        return compact('clave', 'nombre', 'modulos', 'preparado') + [
            'acciones' => collect($acciones)->map(fn ($nombre, $clave) => compact('clave', 'nombre') + [
                'roles_requeridos' => in_array($clave, ['inventario.aprobar_ajustes', 'recepciones.valorizar'], true) ? ['administrador', 'supervisor_materiales'] : [],
            ])->values()->all(),
        ];
    }
}
