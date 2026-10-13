<?php

namespace App\Services\Autorizacion;

/**
 * Oficinas web en el orden del menú, con el módulo y los permisos que habilitan cada una.
 *
 * Es la referencia del servidor para decidir la pantalla de inicio de cada usuario
 * (ver InicioOficina). Repite las rutas, módulos y permisos del menú lateral
 * (resources/views/components/office/navigation.blade.php);
 * tests/Feature/InicioFoliosTest.php verifica que ambos coincidan.
 */
class CatalogoOficinas
{
    /**
     * Ruta, módulo ('' = sin módulo) y permisos (basta uno).
     */
    private const OFICINAS = [
        // materia-prima
        ['/oficina/materia-prima', '', ['puede_consultar_romana', 'puede_consultar_materia_prima', 'puede_consultar_hidrocooler_materia_prima', 'puede_consultar_fruta_proceso', 'puede_consultar_despacho_comercial', 'puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases']],
        ['/oficina/romana', 'materia-prima.romana', ['puede_consultar_romana']],
        ['/oficina/materia-prima/defectos-recepcion', '', ['puede_auditar_defectos_recepcion_mp']],
        ['/oficina/materia-prima/lotes', 'materia-prima.digitacion', ['puede_consultar_materia_prima']],
        ['/oficina/materia-prima/hidrocooler', 'materia-prima.hidrocooler', ['puede_consultar_hidrocooler_materia_prima']],
        ['/oficina/materia-prima/fruta-a-proceso', 'materia-prima.fruta-proceso', ['puede_consultar_fruta_proceso']],
        ['/oficina/materia-prima/despacho-comercial', 'materia-prima.despacho-comercial', ['puede_consultar_despacho_comercial']],
        ['/oficina/materia-prima/existencias', 'materia-prima.digitacion', ['puede_consultar_materia_prima']],
        ['/oficina/envases/cuenta-corriente', 'materia-prima.cuenta-envases', ['puede_consultar_cuenta_envases']],
        ['/oficina/envases/despachos', 'materia-prima.despacho-envases', ['puede_consultar_cuenta_envases', 'puede_gestionar_despacho_envases', 'puede_anular_despacho_envases']],
        // frigorifico
        ['/oficina/frigorifico', '', ['puede_consultar_validaciones_pallet', 'puede_consultar_inspeccion_sag', 'puede_consultar_prefrio', 'ambito_camaras_productos', 'puede_consultar_catalogo_cargas', 'puede_consultar_cargas']],
        ['/oficina/validacion', 'frigorifico.validacion', ['puede_consultar_validaciones_pallet']],
        ['/oficina/validacion/etiquetas', 'frigorifico.validacion', ['puede_imprimir_etiquetas_pt']],
        ['/oficina/recepcion-fruta-embalada', 'frigorifico.recepcion-fruta-embalada', ['puede_consultar_recepciones_fruta_embalada']],
        ['/oficina/validacion/repaletizajes', '', ['puede_consultar_repaletizajes']],
        ['/oficina/validacion/anulaciones', 'frigorifico.validacion', ['puede_consultar_validaciones_pallet']],
        ['/oficina/frigorifico/inspeccion-sag', 'frigorifico.inspeccion-sag', ['puede_consultar_inspeccion_sag']],
        ['/oficina/prefrio', 'frigorifico.prefrio', ['puede_consultar_prefrio']],
        ['/oficina/frigorifico/camaras', 'frigorifico.camaras', ['ambito_camaras_productos']],
        ['/oficina/frigorifico/discrepancias', 'frigorifico.camaras', ['puede_supervisar']],
        ['/oficina/frigorifico/despacho/cargas', 'frigorifico.cargas', ['puede_consultar_catalogo_cargas']],
        ['/oficina/frigorifico/despacho/estatus', 'frigorifico.cargas', ['puede_consultar_cargas']],
        ['/oficina/frigorifico/despacho/calendario', 'frigorifico.cargas', ['puede_consultar_catalogo_cargas']],
        ['/oficina/frigorifico/existencias', 'frigorifico.cargas', ['puede_consultar_cargas']],
        // materiales
        ['/oficina/materiales', 'materiales.resumen', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/catalogos', 'materiales.catalogos', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/items/niveles', 'materiales.catalogos', ['puede_editar_niveles_stock_materiales']],
        ['/oficina/materiales/items', '', ['puede_consultar_despachos_materiales', 'puede_administrar_catalogos_materiales', 'puede_consultar_recepciones_materiales']],
        ['/oficina/materiales/recepciones', 'materiales.etiquetas', ['puede_consultar_recepciones_materiales']],
        ['/oficina/materiales/recepcion', 'materiales.etiquetas', ['puede_consultar_recepciones_materiales', 'puede_imprimir_etiquetas_materiales']],
        ['/oficina/materiales/inventario', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/vencimientos', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/reposicion', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/tomas', 'materiales.inventario', ['puede_gestionar_bloqueos_materiales']],
        ['/oficina/materiales/almacenes', 'materiales.inventario', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/despachos', 'materiales.despachos', ['puede_consultar_despachos_materiales']],
        ['/oficina/materiales/recetas', 'materiales.recetas', ['puede_consultar_transformaciones_materiales']],
        ['/oficina/materiales/ordenes', 'materiales.ordenes', ['puede_consultar_transformaciones_materiales']],
        ['/oficina/materiales/exportaciones', 'materiales.exportaciones', ['puede_consultar_despachos_materiales']],
        // administracion
        ['/oficina/administracion', '', ['puede_consultar_panel_gerencial', 'puede_consultar_accesos', 'puede_administrar_catalogos_validacion', 'puede_consultar_configuracion_camaras', 'puede_consultar_integridad_operacional']],
        ['/oficina/operacion-ahora', 'gerencia.panel', ['puede_consultar_panel_gerencial']],
        ['/oficina/gerencia', 'gerencia.panel', ['puede_consultar_panel_gerencial']],
        ['/oficina/accesos', 'administracion.accesos', ['puede_consultar_accesos']],
        ['/oficina/administracion/fruta-embalada', 'administracion.accesos', ['puede_administrar_accesos']],
        ['/oficina/administracion/maestros-temporada', 'administracion.maestros-temporada', ['puede_administrar_catalogos_validacion']],
        ['/oficina/administracion/camaras', 'administracion.camaras', ['puede_consultar_configuracion_camaras']],
        ['/oficina/administracion/integridad-operacional', 'administracion.integridad-operacional', ['puede_consultar_integridad_operacional']],
        ['/oficina/demo', '', ['puede_habilitar_demo']],
        // consultas
        ['/oficina/consultas', '', ['puede_consultar_oficina_consultas', 'puede_consultar_sag']],
        ['/oficina/consultas/busqueda', 'consultas.busqueda', ['puede_consultar_oficina_consultas']],
        ['/oficina/consultas/trazabilidad', 'consultas.busqueda', ['puede_consultar_oficina_consultas']],
        ['/oficina/consultas/sag', 'consultas.sag', ['puede_consultar_sag']],
        ['/oficina/consultas/productores', 'consultas.productores', ['puede_consultar_oficina_consultas']],
    ];

    /**
     * @return list<array{ruta: string, modulo: string, permisos: list<string>}>
     */
    public function oficinas(): array
    {
        return array_map(
            fn (array $oficina): array => [
                'ruta' => $oficina[0],
                'modulo' => $oficina[1],
                'permisos' => $oficina[2],
            ],
            self::OFICINAS,
        );
    }
}
