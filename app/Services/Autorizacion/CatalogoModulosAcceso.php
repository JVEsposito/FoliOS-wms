<?php

namespace App\Services\Autorizacion;

use App\Enums\RolUsuario;
use App\Models\User;

class CatalogoModulosAcceso
{
    public const OFICINA_INTEGRIDAD_OPERACIONAL = 'administracion.integridad-operacional';

    public const OFICINA_MAESTROS_TEMPORADA = 'administracion.maestros-temporada';

    public const OFICINA_CATALOGOS_VALIDACION_LEGADO = 'frigorifico.catalogos';

    public const TABLET_OPERACION_FRIGORIFICO = 'operacion';

    public const TABLET_RECEPCION_MATERIALES = 'recepcion_materiales';

    public const TABLET_VALIDACION_PT = 'validacion';

    public const OFICINA_RECEPCION_FRUTA_EMBALADA = 'frigorifico.recepcion-fruta-embalada';

    public const TABLET_RECEPCION_FRUTA_EMBALADA = 'recepcion_fruta_embalada';

    public const TABLET_REPALETIZAJE = 'repaletizaje';

    public const OFICINA_REPALETIZAJE = 'frigorifico.repaletizaje';

    public const TABLET_VALIDACION_MP = 'validacion_mp';

    public const TABLET_FRUTA_PROCESO = 'fruta_proceso';

    public const TABLET_HIDROCOOLER_MP = 'hidrocooler_mp';

    public const TABLET_PREFRIO = 'prefrio';

    public const TABLET_OPERACION_MATERIALES = 'operacion_materiales';

    /**
     * @return array<int, array{
     *     clave: string,
     *     nombre: string,
     *     descripcion: string,
     *     modulos: array<int, array{clave: string, nombre: string, descripcion: string}>
     * }>
     */
    public function macromodulos(): array
    {
        return [
            [
                'clave' => 'materia-prima',
                'nombre' => 'Materia Prima',
                'descripcion' => 'Recepción, lotización, envases y validación de materia prima.',
                'modulos' => [
                    $this->modulo('materia-prima.romana', 'Romana', 'Ingreso, pesaje y seguimiento de recepciones.'),
                    $this->modulo('materia-prima.digitacion', 'Digitación de lotes', 'Creación y gestión de lotes posteriores a validación.'),
                    $this->modulo('materia-prima.hidrocooler', 'Hidrocooler', 'Ciclos individuales por lote, temperaturas y destino de salida.'),
                    $this->modulo('materia-prima.fruta-proceso', 'Fruta a proceso', 'Entregas parciales de bins desde cámara hacia Packing.'),
                    $this->modulo('materia-prima.despacho-comercial', 'Despacho comercial', 'Salidas de bins retornados por Packing con guía SII externa.'),
                    $this->modulo('materia-prima.validacion-mp', 'Validación MP', 'Flujo PDA/tablet para validar y segregar recepciones.'),
                    $this->modulo('materia-prima.cuenta-envases', 'Cuenta de envases', 'Saldos y movimientos de envases por cliente.'),
                    $this->modulo('materia-prima.despacho-envases', 'Despacho de envases', 'Guías, reservas y confirmación de salidas.'),
                ],
            ],
            [
                'clave' => 'frigorifico',
                'nombre' => 'Frigorífico (PT)',
                'descripcion' => 'Validación, prefrío, cámaras y despacho de producto terminado.',
                'modulos' => [
                    $this->modulo('frigorifico.validacion', 'Validación PT', 'Validación y observación de pallets.'),
                    $this->modulo(self::OFICINA_RECEPCION_FRUTA_EMBALADA, 'Recepción de fruta embalada', 'Captura de recepciones externas y sus pallets en borrador.'),
                    $this->modulo(self::OFICINA_REPALETIZAJE, 'Repaletizaje', 'Repas desde cámaras y registro RRPL-01.'),
                    $this->modulo('frigorifico.inspeccion-sag', 'Inspección SAG', 'Muestreos, inspecciones, fumigaciones y cambios de mercado.'),
                    $this->modulo('frigorifico.prefrio', 'Prefrío', 'Túneles, procesos y verificaciones de prefrío.'),
                    $this->modulo('frigorifico.camaras', 'Cámaras PT', 'Plano, movimientos y sesiones de producto terminado.'),
                    $this->modulo('frigorifico.cargas', 'Despacho', 'Cargas, estatus operativo y calendario de embarques.'),
                ],
            ],
            [
                'clave' => 'materiales',
                'nombre' => 'Materiales',
                'descripcion' => 'Catálogos, existencias, recepciones, despachos y transformación.',
                'modulos' => [
                    $this->modulo('materiales.resumen', 'Resumen', 'Estado general del inventario de materiales.'),
                    $this->modulo('materiales.catalogos', 'Catálogos', 'Clientes, proveedores, ítems y destinos.'),
                    $this->modulo('materiales.etiquetas', 'Recepción y etiquetas', 'Recepciones, folios e impresión de etiquetas.'),
                    $this->modulo('materiales.inventario', 'Inventario', 'Existencias, posiciones, kardex y correcciones.'),
                    $this->modulo('materiales.despachos', 'Despachos', 'Reservas, retiros y despachos internos.'),
                    $this->modulo('materiales.recetas', 'Recetas', 'Configuración de transformaciones internas.'),
                    $this->modulo('materiales.ordenes', 'Órdenes', 'Planificación y ejecución de transformaciones.'),
                    $this->modulo('materiales.exportaciones', 'Exportaciones', 'Descarga de inventarios autorizados.'),
                ],
            ],
            [
                'clave' => 'consultas',
                'nombre' => 'Consultas',
                'descripcion' => 'Búsqueda transversal y productores SAG/CSG.',
                'modulos' => [
                    $this->modulo('consultas.busqueda', 'Búsqueda operacional', 'Consulta transversal de folios, lotes y recepciones.'),
                    $this->modulo('consultas.sag', 'Consulta SAG / CSG', 'Consulta del registro externo del SAG.'),
                    $this->modulo('consultas.productores', 'Productores verificados', 'Expedientes y asociaciones de productores.'),
                ],
            ],
            [
                'clave' => 'administracion',
                'nombre' => 'Gerencia y Administración',
                'descripcion' => 'Indicadores transversales y configuración reservada.',
                'modulos' => [
                    $this->modulo('gerencia.panel', 'Panel gerencial', 'Indicadores, ocupación y disponibilidad operacional.'),
                    $this->modulo('administracion.accesos', 'Accesos y temporadas', 'Usuarios, perfiles, tablets y maestros globales.'),
                    $this->modulo(self::OFICINA_MAESTROS_TEMPORADA, 'Maestros de temporada', 'Base transversal consumida por los módulos operacionales.'),
                    $this->modulo('administracion.camaras', 'Configuración de cámaras', 'Creación y estructura física de cámaras.'),
                    $this->modulo(self::OFICINA_INTEGRIDAD_OPERACIONAL, 'Salud operacional', 'Auditoría de contradicciones entre folios, procesos, ubicaciones y reservas.'),
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function claves(): array
    {
        return collect($this->macromodulos())
            ->flatMap(fn (array $macromodulo): array => array_column($macromodulo['modulos'], 'clave'))
            ->push(self::OFICINA_CATALOGOS_VALIDACION_LEGADO)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{
     *     clave: string,
     *     nombre: string,
     *     descripcion: string,
     *     modulos: array<int, array{
     *         clave: string,
     *         nombre: string,
     *         descripcion: string,
     *         modulos_oficina_relacionados: array<int, string>
     *     }>
     * }>
     */
    public function macromodulosTablet(): array
    {
        return [
            [
                'clave' => 'materia-prima',
                'nombre' => 'Materia Prima',
                'descripcion' => 'Espacios de trabajo móviles para la recepción de fruta.',
                'modulos' => [
                    $this->moduloTablet(
                        self::TABLET_VALIDACION_MP,
                        'Validación MP',
                        'Validar cantidades, revisar tarjas y preparar segregaciones.',
                        ['materia-prima.validacion-mp'],
                    ),
                    $this->moduloTablet(
                        self::TABLET_HIDROCOOLER_MP,
                        'Hidrocooler MP',
                        'Iniciar, finalizar y supervisar ciclos de enfriamiento por lote.',
                        ['materia-prima.hidrocooler'],
                    ),
                    $this->moduloTablet(
                        self::TABLET_FRUTA_PROCESO,
                        'Fruta a proceso',
                        'Registrar viajes parciales de bins desde cámara hacia Packing.',
                        ['materia-prima.fruta-proceso'],
                    ),
                ],
            ],
            [
                'clave' => 'frigorifico',
                'nombre' => 'Frigorífico (PT)',
                'descripcion' => 'Operación móvil de producto terminado.',
                'modulos' => [
                    $this->moduloTablet(
                        self::TABLET_OPERACION_FRIGORIFICO,
                        'Operación frigorífico',
                        'Cámaras, movimientos, cargas y despachos de producto terminado.',
                        ['frigorifico.camaras', 'frigorifico.cargas'],
                    ),
                    $this->moduloTablet(
                        self::TABLET_VALIDACION_PT,
                        'Validación PT',
                        'Validación y observación de pallets.',
                        ['frigorifico.validacion'],
                    ),
                    $this->moduloTablet(self::TABLET_RECEPCION_FRUTA_EMBALADA, 'Recepción de fruta embalada', 'Captura de camiones y pallets externos en borrador.', [self::OFICINA_RECEPCION_FRUTA_EMBALADA]),
                    $this->moduloTablet(
                        self::TABLET_REPALETIZAJE,
                        'Repaletizaje',
                        'Consolidar saldos desde las cámaras; llena el registro RRPL-01.',
                        [self::OFICINA_REPALETIZAJE],
                    ),
                    $this->moduloTablet(
                        self::TABLET_PREFRIO,
                        'Prefrío',
                        'Ingreso, seguimiento y cierre de procesos de prefrío.',
                        ['frigorifico.prefrio'],
                    ),
                ],
            ],
            [
                'clave' => 'materiales',
                'nombre' => 'Materiales',
                'descripcion' => 'Recepción y operación móvil de las cámaras de Materiales.',
                'modulos' => [
                    $this->moduloTablet(
                        self::TABLET_OPERACION_MATERIALES,
                        'Cámara y operación de materiales',
                        'Cámaras, posiciones y operaciones habilitadas de inventario de materiales.',
                        ['materiales.inventario'],
                    ),
                    $this->moduloTablet(
                        self::TABLET_RECEPCION_MATERIALES,
                        'Recepción de materiales',
                        'Recepciones, generación de folios e impresión de etiquetas.',
                        ['materiales.etiquetas'],
                    ),
                ],
            ],
        ];
    }

    /**
     * Relación de compatibilidad entre cada espacio móvil implementado y las
     * oficinas que contienen sus permisos operacionales.
     *
     * @return array<string, array<int, string>>
     */
    public function modulosTablet(): array
    {
        return collect($this->macromodulosTablet())
            ->flatMap(fn (array $macromodulo): array => $macromodulo['modulos'])
            ->mapWithKeys(fn (array $modulo): array => [
                $modulo['clave'] => $modulo['modulos_oficina_relacionados'],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function clavesTablet(): array
    {
        return array_keys($this->modulosTablet());
    }

    /**
     * @param  array<int, string>  $modulosOficina
     * @return array<int, string>
     */
    public function modulosTabletCompatiblesCon(array $modulosOficina): array
    {
        return collect($this->modulosTablet())
            ->filter(
                fn (array $requeridos): bool => array_intersect($requeridos, $modulosOficina) !== [],
            )
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function modulosTabletPredeterminados(RolUsuario $rol): array
    {
        return $this->modulosTabletCompatiblesCon($this->modulosPredeterminados($rol));
    }

    /**
     * @return array<int, string>
     */
    public function modulosTabletUsuario(User $usuario): array
    {
        if (! $usuario->activo) {
            return [];
        }

        if (! $usuario->perfil_acceso_id) {
            return $this->modulosTabletPredeterminados($usuario->rol);
        }

        $usuario->loadMissing('perfilAcceso');
        $perfil = $usuario->perfilAcceso;

        if (! $perfil || ! $perfil->activo || $perfil->rol_base === RolUsuario::Consulta) {
            return [];
        }

        return array_values(array_intersect(
            $perfil->modulos_tablet ?? [],
            $this->modulosTabletCompatiblesCon($perfil->modulos ?? []),
        ));
    }

    public static function habilidadTablet(string $modulo): string
    {
        return "tablet:{$modulo}";
    }

    /**
     * @return array<int, string>
     */
    public function modulosPredeterminados(RolUsuario $rol): array
    {
        if ($rol === RolUsuario::Administrador) {
            return $this->claves();
        }

        return match ($rol) {
            RolUsuario::SupervisorFrio => [
                'gerencia.panel',
                'materia-prima.romana',
                'materia-prima.digitacion',
                'materia-prima.hidrocooler',
                'materia-prima.fruta-proceso',
                'materia-prima.despacho-comercial',
                'frigorifico.validacion',
                self::OFICINA_RECEPCION_FRUTA_EMBALADA,
                self::OFICINA_REPALETIZAJE,
                'frigorifico.inspeccion-sag',
                'frigorifico.prefrio',
                'frigorifico.camaras',
                'frigorifico.cargas',
                'consultas.busqueda',
                'consultas.sag',
                'consultas.productores',
            ],
            RolUsuario::SupervisorMateriales => [
                'gerencia.panel',
                'materia-prima.cuenta-envases',
                'materia-prima.despacho-envases',
                'materiales.resumen',
                'materiales.etiquetas',
                'materiales.inventario',
                'materiales.despachos',
                'materiales.recetas',
                'materiales.ordenes',
                'materiales.exportaciones',
            ],
            RolUsuario::Despachador => [
                'consultas.busqueda',
                'materia-prima.romana',
                'materia-prima.cuenta-envases',
                'materia-prima.despacho-envases',
                'frigorifico.camaras',
                'frigorifico.cargas',
                'materiales.resumen',
                'materiales.inventario',
                'materiales.despachos',
                'materiales.recetas',
                'materiales.ordenes',
                'materiales.exportaciones',
            ],
            RolUsuario::OperadorPrefrio => [
                'frigorifico.prefrio',
            ],
            RolUsuario::OperadorRomana => [
                'materia-prima.romana',
                'materia-prima.digitacion',
                'materia-prima.hidrocooler',
                'materia-prima.cuenta-envases',
                'materia-prima.despacho-envases',
            ],
            RolUsuario::DigitadorMateriaPrima => [
                'materia-prima.romana',
                'materia-prima.digitacion',
                'materia-prima.hidrocooler',
                'materia-prima.despacho-comercial',
                'materia-prima.cuenta-envases',
                'consultas.busqueda',
                'consultas.sag',
                'consultas.productores',
            ],
            RolUsuario::CamareroFrio => [
                'materia-prima.fruta-proceso',
                'frigorifico.camaras',
                'frigorifico.cargas',
            ],
            RolUsuario::CamareroMateriales => [
                'materiales.resumen',
                'materiales.etiquetas',
                'materiales.inventario',
                'materiales.despachos',
                'materiales.recetas',
                'materiales.ordenes',
                'materiales.exportaciones',
            ],
            RolUsuario::Validador => [
                'frigorifico.validacion',
                self::OFICINA_RECEPCION_FRUTA_EMBALADA,
            ],
            RolUsuario::ValidadorMp => [
                'materia-prima.validacion-mp',
            ],
            RolUsuario::Tarjador => [
                self::OFICINA_REPALETIZAJE,
            ],
            RolUsuario::Consulta => [
                self::OFICINA_RECEPCION_FRUTA_EMBALADA,
                'gerencia.panel',
                'materia-prima.romana',
                'materia-prima.digitacion',
                'materia-prima.hidrocooler',
                'materia-prima.fruta-proceso',
                'materia-prima.despacho-comercial',
                'materia-prima.cuenta-envases',
                'frigorifico.prefrio',
                'frigorifico.inspeccion-sag',
                'frigorifico.camaras',
                'frigorifico.cargas',
                'materiales.resumen',
                'materiales.inventario',
                'materiales.despachos',
                'materiales.recetas',
                'materiales.ordenes',
                'materiales.exportaciones',
            ],
            RolUsuario::Administrador => [],
        };
    }

    /**
     * @return array<int, string>
     */
    public function modulosUsuario(User $usuario): array
    {
        if (! $usuario->activo) {
            return [];
        }

        if (! $usuario->perfil_acceso_id) {
            return $this->modulosPredeterminados($usuario->rol);
        }

        $usuario->loadMissing('perfilAcceso');
        $perfil = $usuario->perfilAcceso;

        if (! $perfil || ! $perfil->activo) {
            return [];
        }

        $modulos = array_values(array_intersect($perfil->modulos, $this->claves()));

        if (in_array(self::OFICINA_CATALOGOS_VALIDACION_LEGADO, $modulos, true)) {
            $modulos[] = self::OFICINA_MAESTROS_TEMPORADA;
        }

        return array_values(array_unique($modulos));
    }

    public function usuarioTieneModulo(User $usuario, string|array $modulos): bool
    {
        $requeridos = is_array($modulos) ? $modulos : [$modulos];

        return array_intersect($requeridos, $this->modulosUsuario($usuario)) !== [];
    }

    /**
     * @return array{clave: string, nombre: string, descripcion: string}
     */
    private function modulo(string $clave, string $nombre, string $descripcion): array
    {
        return compact('clave', 'nombre', 'descripcion');
    }

    /**
     * @param  array<int, string>  $modulosOficinaRelacionados
     * @return array{
     *     clave: string,
     *     nombre: string,
     *     descripcion: string,
     *     modulos_oficina_relacionados: array<int, string>
     * }
     */
    private function moduloTablet(
        string $clave,
        string $nombre,
        string $descripcion,
        array $modulosOficinaRelacionados,
    ): array {
        return [
            'clave' => $clave,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'modulos_oficina_relacionados' => $modulosOficinaRelacionados,
        ];
    }
}
