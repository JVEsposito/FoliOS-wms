<?php

namespace App\Services\Autorizacion;

/**
 * Oficinas web en el orden del menú, con el módulo y los permisos que habilitan cada una.
 *
 * Es la referencia del servidor para la pantalla de inicio de cada usuario
 * (InicioOficina) y para las opciones de «Pantalla de inicio» de los perfiles de acceso.
 * Repite las rutas, módulos y permisos del menú lateral
 * (resources/views/components/office/navigation.blade.php);
 * tests/Feature/InicioFoliosTest.php verifica que ambos coincidan.
 */
class CatalogoOficinas
{
    /**
     * @var array<string, string>
     */
    private const AREAS = [
        'materia-prima' => 'Materia Prima',
        'frigorifico' => 'Frigorífico',
        'materiales' => 'Materiales',
        'gerencia' => 'Gerencia',
        'administracion' => 'Administración',
        'consultas' => 'Consultas',
    ];

    /**
     * Prefijos de módulo que habilitan las oficinas sin módulo propio (resúmenes y
     * pantallas transversales) de cada área.
     *
     * @var array<string, list<string>>
     */
    private const PREFIJOS_AREA = [
        'materia-prima' => ['materia-prima.'],
        'frigorifico' => ['frigorifico.'],
        'materiales' => ['materiales.'],
        'gerencia' => ['gerencia.'],
        'administracion' => ['administracion.', 'gerencia.', 'finanzas.'],
        'consultas' => ['consultas.'],
    ];

    /**
     * Área, nombre, ruta, módulo ('' = sin módulo) y permisos (basta uno).
     */
    private const OFICINAS = [
        ['materia-prima', 'Resumen', '/oficina/materia-prima', '', ['puede_consultar_romana', 'puede_consultar_materia_prima', 'puede_consultar_hidrocooler_materia_prima', 'puede_consultar_fruta_proceso', 'puede_consultar_despacho_comercial', 'puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases']],
        ['materia-prima', 'Ingresos Portería', '/oficina/porteria', '', ['puede_consultar_porteria']],
        ['materia-prima', 'Romana', '/oficina/romana', 'materia-prima.romana', ['puede_consultar_romana']],
        ['materia-prima', 'Defectos de recepción', '/oficina/materia-prima/defectos-recepcion', '', ['puede_auditar_defectos_recepcion_mp']],
        ['materia-prima', 'Digitación de Lotes', '/oficina/materia-prima/lotes', 'materia-prima.digitacion', ['puede_consultar_materia_prima']],
        ['materia-prima', 'Hidrocooler', '/oficina/materia-prima/hidrocooler', 'materia-prima.hidrocooler', ['puede_consultar_hidrocooler_materia_prima']],
        ['materia-prima', 'Fruta a Proceso', '/oficina/materia-prima/fruta-a-proceso', 'materia-prima.fruta-proceso', ['puede_consultar_fruta_proceso']],
        ['materia-prima', 'Despacho Comercial', '/oficina/materia-prima/despacho-comercial', 'materia-prima.despacho-comercial', ['puede_consultar_despacho_comercial']],
        ['materia-prima', 'Existencias MP', '/oficina/materia-prima/existencias', 'materia-prima.digitacion', ['puede_consultar_materia_prima']],
        ['materia-prima', 'Cuenta Envases', '/oficina/envases/cuenta-corriente', 'materia-prima.cuenta-envases', ['puede_consultar_cuenta_envases']],
        ['materia-prima', 'Despacho Envases', '/oficina/envases/despachos', 'materia-prima.despacho-envases', ['puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases']],
        ['frigorifico', 'Resumen', '/oficina/frigorifico', '', ['puede_consultar_validaciones_pallet', 'puede_consultar_inspeccion_sag', 'puede_consultar_prefrio', 'ambito_camaras_productos', 'puede_consultar_catalogo_cargas', 'puede_consultar_cargas']],
        ['frigorifico', 'Validación', '/oficina/validacion', 'frigorifico.validacion', ['puede_consultar_validaciones_pallet']],
        ['frigorifico', 'Pendientes por validar', '/oficina/validacion/pendientes', 'frigorifico.validacion', ['puede_consultar_validaciones_pallet']],
        ['frigorifico', 'Etiquetas PT', '/oficina/validacion/etiquetas', 'frigorifico.validacion', ['puede_imprimir_etiquetas_pt']],
        ['frigorifico', 'Recepción de fruta embalada', '/oficina/recepcion-fruta-embalada', 'frigorifico.recepcion-fruta-embalada', ['puede_consultar_recepciones_fruta_embalada']],
        ['frigorifico', 'Repaletizajes', '/oficina/validacion/repaletizajes', '', ['puede_consultar_repaletizajes']],
        ['frigorifico', 'Anulaciones', '/oficina/validacion/anulaciones', 'frigorifico.validacion', ['puede_consultar_validaciones_pallet']],
        ['frigorifico', 'Inspección SAG', '/oficina/frigorifico/inspeccion-sag', 'frigorifico.inspeccion-sag', ['puede_consultar_inspeccion_sag']],
        ['frigorifico', 'Prefrío', '/oficina/prefrio', 'frigorifico.prefrio', ['puede_consultar_prefrio']],
        ['frigorifico', 'Cámaras', '/oficina/frigorifico/camaras', 'frigorifico.camaras', ['ambito_camaras_productos']],
        ['frigorifico', 'Discrepancias', '/oficina/frigorifico/discrepancias', 'frigorifico.camaras', ['puede_supervisar']],
        ['frigorifico', 'Cargas', '/oficina/frigorifico/despacho/cargas', 'frigorifico.cargas', ['puede_consultar_catalogo_cargas']],
        ['frigorifico', 'Estatus operativo', '/oficina/frigorifico/despacho/estatus', 'frigorifico.cargas', ['puede_consultar_cargas']],
        ['frigorifico', 'Calendario de embarques', '/oficina/frigorifico/despacho/calendario', 'frigorifico.cargas', ['puede_consultar_catalogo_cargas']],
        ['frigorifico', 'Existencias PT', '/oficina/frigorifico/existencias', 'frigorifico.cargas', ['puede_consultar_cargas']],
        ['materiales', 'Resumen', '/oficina/materiales', 'materiales.resumen', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Catálogos', '/oficina/materiales/catalogos', 'materiales.catalogos', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Ítems · niveles de stock', '/oficina/materiales/items/niveles', 'materiales.catalogos', ['puede_editar_niveles_stock_materiales']],
        ['materiales', 'Ítems', '/oficina/materiales/items', '', ['puede_consultar_despachos_materiales', 'puede_administrar_catalogos_materiales', 'puede_consultar_recepciones_materiales']],
        ['materiales', 'Recepciones', '/oficina/materiales/recepciones', 'materiales.etiquetas', ['puede_consultar_recepciones_materiales']],
        ['materiales', 'Etiquetas', '/oficina/materiales/recepcion', 'materiales.etiquetas', ['puede_consultar_recepciones_materiales', 'puede_imprimir_etiquetas_materiales']],
        ['materiales', 'Inventario BC', '/oficina/materiales/inventario', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Vencimientos', '/oficina/materiales/vencimientos', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Reposición', '/oficina/materiales/reposicion', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Tomas de inventario', '/oficina/materiales/tomas', 'materiales.inventario', ['puede_gestionar_bloqueos_materiales']],
        ['materiales', 'Inventario CC', '/oficina/materiales/almacenes', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Despachos', '/oficina/materiales/despachos', 'materiales.despachos', ['puede_consultar_despachos_materiales']],
        ['materiales', 'Recetas', '/oficina/materiales/recetas', 'materiales.recetas', ['puede_consultar_transformaciones_materiales']],
        ['materiales', 'Órdenes', '/oficina/materiales/ordenes', 'materiales.ordenes', ['puede_consultar_transformaciones_materiales']],
        ['materiales', 'Existencias', '/oficina/materiales/exportaciones', 'materiales.exportaciones', ['puede_consultar_despachos_materiales']],
        ['administracion', 'Resumen', '/oficina/administracion', '', ['puede_consultar_panel_gerencial', 'puede_consultar_accesos', 'puede_administrar_catalogos_validacion', 'puede_consultar_configuracion_camaras', 'puede_consultar_integridad_operacional', 'puede_consultar_finanzas']],
        ['gerencia', 'Operación ahora', '/oficina/operacion-ahora', 'gerencia.panel', ['puede_consultar_panel_gerencial']],
        ['gerencia', 'Panel Gerencial', '/oficina/gerencia', 'gerencia.panel', ['puede_consultar_panel_gerencial']],
        ['administracion', 'Accesos & Temporadas', '/oficina/accesos', 'administracion.accesos', ['puede_consultar_accesos']],
        ['administracion', 'Plantas y umbrales de prefrío', '/oficina/administracion/fruta-embalada', 'administracion.accesos', ['puede_administrar_accesos']],
        ['administracion', 'Maestros de temporada', '/oficina/administracion/maestros-temporada', 'administracion.maestros-temporada', ['puede_administrar_catalogos_validacion']],
        ['administracion', 'Configuración de cámaras', '/oficina/administracion/camaras', 'administracion.camaras', ['puede_consultar_configuracion_camaras']],
        ['administracion', 'Salud operacional', '/oficina/administracion/integridad-operacional', 'administracion.integridad-operacional', ['puede_consultar_integridad_operacional']],
        ['administracion', 'Demo comercial', '/oficina/demo', '', ['puede_habilitar_demo']],
        ['administracion', 'Hechos valorizables', '/oficina/finanzas/hechos', 'finanzas.prefactura', ['puede_consultar_finanzas']],
        ['administracion', 'Tarifario', '/oficina/finanzas/tarifario', 'finanzas.prefactura', ['puede_consultar_finanzas']],
        ['consultas', 'Resumen', '/oficina/consultas', '', ['puede_consultar_oficina_consultas', 'puede_consultar_sag']],
        ['consultas', 'Búsqueda Operacional', '/oficina/consultas/busqueda', 'consultas.busqueda', ['puede_consultar_oficina_consultas']],
        ['consultas', 'Trazabilidad de lotes', '/oficina/consultas/trazabilidad', 'consultas.busqueda', ['puede_consultar_oficina_consultas']],
        ['consultas', 'Productores SAG / CSG', '/oficina/consultas/sag', 'consultas.sag', ['puede_consultar_sag']],
        ['consultas', 'Productores Verificados', '/oficina/consultas/productores', 'consultas.productores', ['puede_consultar_oficina_consultas']],
    ];

    /**
     * @return list<array{ruta: string, nombre: string, area: string, area_nombre: string, modulo: string, permisos: list<string>}>
     */
    public function oficinas(): array
    {
        return array_map(
            fn (array $oficina): array => [
                'ruta' => $oficina[2],
                'nombre' => $oficina[1],
                'area' => $oficina[0],
                'area_nombre' => self::AREAS[$oficina[0]],
                'modulo' => $oficina[3],
                'permisos' => $oficina[4],
            ],
            self::OFICINAS,
        );
    }

    /**
     * Oficinas que un perfil puede fijar como pantalla de inicio (todas menos la demo).
     *
     * @return list<array{ruta: string, nombre: string, area: string, area_nombre: string, modulo: string, permisos: list<string>}>
     */
    public function opcionesInicio(): array
    {
        return array_values(array_filter(
            $this->oficinas(),
            fn (array $oficina): bool => $oficina['ruta'] !== '/oficina/demo',
        ));
    }

    /**
     * Módulos de perfil que habilitan una oficina: el suyo o, si no tiene, los de su área.
     *
     * @param  array{area: string, modulo: string}  $oficina
     * @param  list<string>  $clavesModulos  Claves del catálogo de módulos de oficina.
     * @return list<string>
     */
    public function modulosRelacionados(array $oficina, array $clavesModulos): array
    {
        if ($oficina['modulo'] !== '') {
            return [$oficina['modulo']];
        }

        $prefijos = self::PREFIJOS_AREA[$oficina['area']] ?? [];

        return array_values(array_filter(
            $clavesModulos,
            function (string $clave) use ($prefijos): bool {
                foreach ($prefijos as $prefijo) {
                    if (str_starts_with($clave, $prefijo)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    /**
     * @return array{ruta: string, nombre: string, area: string, area_nombre: string, modulo: string, permisos: list<string>}|null
     */
    public function oficina(string $ruta): ?array
    {
        foreach ($this->oficinas() as $oficina) {
            if ($oficina['ruta'] === $ruta) {
                return $oficina;
            }
        }

        return null;
    }
}
