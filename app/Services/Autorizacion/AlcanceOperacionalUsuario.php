<?php

namespace App\Services\Autorizacion;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\PersonalAccessToken;
use App\Models\User;

class AlcanceOperacionalUsuario
{
    public function __construct(
        private readonly CatalogoModulosAcceso $catalogoModulos,
    ) {}

    /**
     * @return array<int, ContenidoCamara>
     */
    public function contenidosVisibles(User $usuario): array
    {
        if (! $usuario->activo) {
            return [];
        }

        $contenidos = match ($this->rolEfectivo($usuario)) {
            RolUsuario::SupervisorFrio,
            RolUsuario::CamareroFrio => [ContenidoCamara::Productos],
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales => [ContenidoCamara::Materiales],
            RolUsuario::DigitadorMateriaPrima => [ContenidoCamara::MateriaPrima],
            RolUsuario::Administrador,
            RolUsuario::Despachador,
            RolUsuario::Consulta => ContenidoCamara::cases(),
            RolUsuario::OperadorPrefrio,
            RolUsuario::OperadorRomana,
            RolUsuario::Validador,
            RolUsuario::ValidadorMp,
            RolUsuario::Tarjador => [],
        };

        return array_values(array_filter(
            $contenidos,
            fn (ContenidoCamara $contenido): bool => (
                $contenido !== ContenidoCamara::Materiales
                || $this->permiteModuloTablet(
                    $usuario,
                    CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
                )
            ) && $this->catalogoModulos->usuarioTieneModulo(
                $usuario,
                match ($contenido) {
                    ContenidoCamara::Productos => 'frigorifico.camaras',
                    ContenidoCamara::Materiales => 'materiales.inventario',
                    ContenidoCamara::MateriaPrima => 'materia-prima.digitacion',
                },
            ),
        ));
    }

    public function ambitoCamaras(User $usuario): string
    {
        $contenidos = $this->contenidosVisibles($usuario);

        if (count($contenidos) > 1) {
            return 'ambos';
        }

        return $contenidos[0]->value ?? 'ninguno';
    }

    public function puedeVerCamara(User $usuario, Camara|ContenidoCamara $camara): bool
    {
        $contenido = $camara instanceof Camara ? $camara->contenido : $camara;

        return in_array($contenido, $this->contenidosVisibles($usuario), true);
    }

    public function puedeOperarCamara(User $usuario, Camara|ContenidoCamara $camara): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        $contenido = $camara instanceof Camara ? $camara->contenido : $camara;

        return match ($contenido) {
            ContenidoCamara::Productos => $this->rolActivoEnModulo(
                $usuario,
                [RolUsuario::Administrador, RolUsuario::SupervisorFrio, RolUsuario::CamareroFrio],
                'frigorifico.camaras',
            ),
            ContenidoCamara::Materiales => $this->rolActivoEnModulo(
                $usuario,
                [RolUsuario::Administrador, RolUsuario::SupervisorMateriales, RolUsuario::CamareroMateriales],
                'materiales.inventario',
            ) && $this->permiteModuloTablet(
                $usuario,
                CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
            ),
            ContenidoCamara::MateriaPrima => false,
        };
    }

    public function puedeOperarAlgunaCamara(User $usuario): bool
    {
        return $this->puedeOperarCamara($usuario, ContenidoCamara::Productos)
            || $this->puedeOperarCamara($usuario, ContenidoCamara::Materiales);
    }

    public function puedeSupervisarCamara(User $usuario, Camara|ContenidoCamara $camara): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        $contenido = $camara instanceof Camara ? $camara->contenido : $camara;

        return match ($contenido) {
            ContenidoCamara::Productos => $this->rolActivoEnModulo(
                $usuario,
                [RolUsuario::Administrador, RolUsuario::SupervisorFrio],
                'frigorifico.camaras',
            ),
            ContenidoCamara::Materiales => $this->rolActivoEnModulo(
                $usuario,
                [RolUsuario::Administrador, RolUsuario::SupervisorMateriales],
                'materiales.inventario',
            ) && $this->permiteModuloTablet(
                $usuario,
                CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
            ),
            ContenidoCamara::MateriaPrima => false,
        };
    }

    public function puedeCrearCamara(User $usuario, ContenidoCamara $contenido): bool
    {
        return $this->puedeAdministrarCamaras($usuario);
    }

    public function puedeAdministrarCamaras(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'administracion.camaras',
        );
    }

    public function puedeAdministrarAccesos(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'administracion.accesos',
        );
    }

    public function puedeAdministrarCatalogosMateriales(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'materiales.catalogos',
        );
    }

    public function puedeConsultarAccesos(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::Consulta],
            'administracion.accesos',
        );
    }

    public function puedeConsultarConfiguracionCamaras(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::Consulta],
            'administracion.camaras',
        );
    }

    public function puedeConsultarIntegridadOperacional(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::Consulta],
            CatalogoModulosAcceso::OFICINA_INTEGRIDAD_OPERACIONAL,
        );
    }

    public function puedeEjecutarIntegridadOperacional(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            CatalogoModulosAcceso::OFICINA_INTEGRIDAD_OPERACIONAL,
        );
    }

    public function contenidoForzadoCreacion(User $usuario): ?ContenidoCamara
    {
        return match ($usuario->rol) {
            RolUsuario::SupervisorFrio => ContenidoCamara::Productos,
            RolUsuario::SupervisorMateriales => ContenidoCamara::Materiales,
            default => null,
        };
    }

    public function puedeCerrarSesionForzosamente(User $usuario, Camara $camara): bool
    {
        return $this->puedeSupervisarCamara($usuario, $camara);
    }

    public function puedeConsultarControlAmbiental(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::CamareroFrio,
            RolUsuario::Consulta,
        ], 'frigorifico.camaras');
    }

    public function puedeRegistrarControlAmbiental(User $usuario): bool
    {
        return $this->puedeOperarCamara($usuario, ContenidoCamara::Productos)
            && $this->permiteModuloTablet(
                $usuario,
                CatalogoModulosAcceso::TABLET_OPERACION_FRIGORIFICO,
            );
    }

    public function puedeCorregirControlAmbiental(User $usuario): bool
    {
        return $this->puedeSupervisarCamara($usuario, ContenidoCamara::Productos);
    }

    public function puedeGestionarCargas(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Despachador,
        ], 'frigorifico.cargas');
    }

    public function puedeAutorizarSobrecupoEmbarques(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], 'frigorifico.cargas');
    }

    public function puedeConsultarCargas(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::CamareroFrio,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], 'frigorifico.cargas');
    }

    public function puedeConsultarCatalogoCargas(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], 'frigorifico.cargas');
    }

    public function puedeReportarIncidenciasCarga(User $usuario): bool
    {
        return $this->catalogoModulos->usuarioTieneModulo($usuario, 'frigorifico.cargas')
            && $this->puedeOperarCamara($usuario, ContenidoCamara::Productos);
    }

    public function puedeResolverComercialmenteCarga(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::Despachador],
            'frigorifico.cargas',
        );
    }

    public function puedeResolverReparacionCarga(User $usuario): bool
    {
        return $this->puedeResolverComercialmenteCarga($usuario)
            || $this->rolActivoEnModulo(
                $usuario,
                [RolUsuario::SupervisorFrio],
                'frigorifico.cargas',
            );
    }

    public function puedeEnviarFoliosAnden(User $usuario): bool
    {
        return $this->catalogoModulos->usuarioTieneModulo($usuario, 'frigorifico.cargas')
            && $this->puedeOperarCamara($usuario, ContenidoCamara::Productos);
    }

    public function puedeCerrarDespachoFrigorifico(User $usuario): bool
    {
        return $this->puedeResolverComercialmenteCarga($usuario);
    }

    public function puedeGestionarAndenes(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'frigorifico.cargas',
        );
    }

    public function puedeGestionarDespachosMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::Despachador,
        ], 'materiales.despachos');
    }

    public function puedeConsultarDespachosMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], [
            'materiales.resumen',
            'materiales.catalogos',
            'materiales.etiquetas',
            'materiales.inventario',
            'materiales.despachos',
            'materiales.recetas',
            'materiales.ordenes',
            'materiales.exportaciones',
        ]);
    }

    public function puedeConsultarRecepcionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], ['materiales.etiquetas', 'materiales.despachos']);
    }

    public function puedeGestionarRecepcionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales,
        ], 'materiales.etiquetas');
    }

    public function puedeAnularRecepcionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorMateriales],
            'materiales.etiquetas',
        );
    }

    public function puedeAdministrarRecepcionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'materiales.etiquetas',
        );
    }

    public function puedeImprimirEtiquetasMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_RECEPCION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorMateriales, RolUsuario::CamareroMateriales],
            'materiales.etiquetas',
        );
    }

    public function puedeConsultarTransformacionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], ['materiales.recetas', 'materiales.ordenes']);
    }

    public function puedeGestionarTransformacionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorMateriales],
            'materiales.ordenes',
        );
    }

    public function puedeOperarTransformacionesMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales,
        ], 'materiales.ordenes');
    }

    public function puedeRevertirTransformacionesMateriales(User $usuario): bool
    {
        return $this->puedeGestionarTransformacionesMateriales($usuario);
    }

    public function puedeAdministrarRecetasMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorMateriales],
            'materiales.recetas',
        );
    }

    public function puedeRetirarMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::CamareroMateriales,
        ], 'materiales.despachos');
    }

    public function puedeCancelarDespachosMateriales(User $usuario): bool
    {
        return $this->puedeGestionarDespachosMateriales($usuario);
    }

    public function puedeConsultarKardexMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorMateriales, RolUsuario::Consulta],
            'materiales.inventario',
        );
    }

    public function puedeCorregirItemsEstibadosMateriales(User $usuario): bool
    {
        return $this->permiteModuloTablet(
            $usuario,
            CatalogoModulosAcceso::TABLET_OPERACION_MATERIALES,
        ) && $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorMateriales],
            'materiales.inventario',
        );
    }

    public function puedeGestionarBloqueosMateriales(User $usuario): bool
    {
        return $this->puedeCorregirItemsEstibadosMateriales($usuario);
    }

    public function puedeValidarPallets(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Validador,
        ], 'frigorifico.validacion');
    }

    public function puedeRechazarPallets(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorFrio],
            'frigorifico.validacion',
        );
    }

    public function puedeConsultarValidacionesPallet(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Validador,
            RolUsuario::Consulta,
        ], 'frigorifico.validacion');
    }

    public function puedeImprimirEtiquetasPt(User $usuario): bool
    {
        return $this->puedeConsultarValidacionesPallet($usuario) || $this->puedeConsultarRepaletizajes($usuario);
    }

    /**
     * Repaletizaje: se conserva el acceso histórico desde Validación PT y se suma
     * el módulo propio del tarjador. Desde una PDA o tablet, el token debe traer
     * alguno de los dos espacios móviles.
     */
    public function puedeRegistrarRepaletizajes(User $usuario): bool
    {
        return $this->permiteAlgunModuloTablet($usuario, [
            CatalogoModulosAcceso::TABLET_REPALETIZAJE,
            CatalogoModulosAcceso::TABLET_VALIDACION_PT,
        ]) && $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Validador,
            RolUsuario::Tarjador,
        ], ['frigorifico.validacion', CatalogoModulosAcceso::OFICINA_REPALETIZAJE]);
    }

    public function puedeAnularRepaletizajes(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::SupervisorFrio],
            ['frigorifico.validacion', CatalogoModulosAcceso::OFICINA_REPALETIZAJE],
        );
    }

    public function puedeConsultarRepaletizajes(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Validador,
            RolUsuario::Tarjador,
            RolUsuario::Consulta,
        ], ['frigorifico.validacion', CatalogoModulosAcceso::OFICINA_REPALETIZAJE]);
    }

    public function puedeConsultarCatalogosValidacion(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::Consulta],
            [
                CatalogoModulosAcceso::OFICINA_MAESTROS_TEMPORADA,
                CatalogoModulosAcceso::OFICINA_CATALOGOS_VALIDACION_LEGADO,
            ],
        );
    }

    public function puedeAdministrarCatalogosValidacion(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            [
                CatalogoModulosAcceso::OFICINA_MAESTROS_TEMPORADA,
                CatalogoModulosAcceso::OFICINA_CATALOGOS_VALIDACION_LEGADO,
            ],
        );
    }

    public function puedeCorregirValidacionesPallet(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'frigorifico.validacion',
        ) && $this->puedeAdministrarCatalogosValidacion($usuario);
    }

    public function puedeConsultarPrefrio(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::OperadorPrefrio,
            RolUsuario::Consulta,
        ], 'frigorifico.prefrio');
    }

    public function puedeConsultarInspeccionSag(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Consulta,
        ], 'frigorifico.inspeccion-sag');
    }

    public function puedeGestionarInspeccionSag(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], 'frigorifico.inspeccion-sag');
    }

    public function puedeOperarPrefrio(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::OperadorPrefrio,
        ], 'frigorifico.prefrio');
    }

    public function puedeSupervisarPrefrio(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], 'frigorifico.prefrio');
    }

    public function puedeAdministrarTunelesPrefrio(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'frigorifico.prefrio',
        );
    }

    public function puedeConsultarPanelGerencial(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::SupervisorMateriales,
            RolUsuario::Consulta,
        ], 'gerencia.panel');
    }

    public function puedeConsultarRomana(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::OperadorRomana,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], 'materia-prima.romana');
    }

    public function puedeOperarRomana(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::OperadorRomana,
        ], 'materia-prima.romana');
    }

    public function puedeCorregirRecepcionesRomana(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador],
            'materia-prima.romana',
        );
    }

    public function puedeValidarMp(User $usuario): bool
    {
        return $this->rolActivoEnModulo(
            $usuario,
            [RolUsuario::Administrador, RolUsuario::ValidadorMp],
            'materia-prima.validacion-mp',
        );
    }

    public function puedeAuditarDefectosRecepcionMp(User $usuario): bool
    {
        return in_array($usuario->rol, [RolUsuario::Administrador, RolUsuario::SupervisorFrio], true)
            && ($this->puedeConsultarRomana($usuario) || $this->puedeConsultarMateriaPrima($usuario));
    }

    public function puedeConsultarMateriaPrima(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::OperadorRomana,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Consulta,
        ], 'materia-prima.digitacion');
    }

    public function puedeGestionarLotesMateriaPrima(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::DigitadorMateriaPrima,
        ], 'materia-prima.digitacion');
    }

    public function puedeSupervisarLotesMateriaPrima(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], 'materia-prima.digitacion');
    }

    public function puedeConsultarHidrocoolerMateriaPrima(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::OperadorRomana,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Consulta,
        ], 'materia-prima.hidrocooler');
    }

    public function puedeOperarHidrocoolerMateriaPrima(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::DigitadorMateriaPrima,
        ], 'materia-prima.hidrocooler');
    }

    public function puedeConsultarFrutaProceso(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::CamareroFrio,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Consulta,
        ], 'materia-prima.fruta-proceso');
    }

    public function puedeConsultarDespachoComercial(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Consulta,
        ], 'materia-prima.despacho-comercial');
    }

    public function puedeGestionarDespachoComercial(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::DigitadorMateriaPrima,
        ], 'materia-prima.despacho-comercial');
    }

    public function puedeEntregarFrutaProceso(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::CamareroFrio,
        ], 'materia-prima.fruta-proceso')
            && $this->permiteModuloTablet(
                $usuario,
                CatalogoModulosAcceso::TABLET_FRUTA_PROCESO,
            );
    }

    public function puedeCorregirEntregasFrutaProceso(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], 'materia-prima.fruta-proceso');
    }

    public function puedeConsultarOficinaConsultas(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::Despachador,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Consulta,
        ], ['consultas.busqueda', 'consultas.sag', 'consultas.productores']);
    }

    /** Roles de operación de frío cuyo PIN puede restablecer un supervisor de frío. */
    public const ROLES_PIN_SUPERVISADOS = [
        RolUsuario::CamareroFrio,
        RolUsuario::OperadorPrefrio,
        RolUsuario::Validador,
    ];

    public function puedeGestionarPinesOperadores(User $usuario): bool
    {
        return $this->puedeAdministrarAccesos($usuario)
            || $this->rolActivoEnModulo($usuario, [RolUsuario::SupervisorFrio], 'frigorifico.camaras');
    }

    /**
     * El administrador restablece cualquier PIN. El supervisor de frío solo el de los
     * operadores de frío, nunca el propio ni el de otro supervisor.
     */
    public function puedeRestablecerPinOperacional(User $actor, User $objetivo): bool
    {
        if ($this->puedeAdministrarAccesos($actor)) {
            return true;
        }

        return $actor->id !== $objetivo->id
            && $this->puedeGestionarPinesOperadores($actor)
            && in_array($objetivo->rol, self::ROLES_PIN_SUPERVISADOS, true);
    }

    public function puedeConsultarSag(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Consulta,
        ], 'consultas.sag');
    }

    public function puedeAsociarProductoresCsg(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
        ], 'consultas.productores');
    }

    public function puedeConsultarCuentaEnvases(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::OperadorRomana,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::Despachador,
            RolUsuario::Consulta,
        ], 'materia-prima.cuenta-envases');
    }

    public function puedeRevisarCuentaEnvases(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
            RolUsuario::OperadorRomana,
        ], 'materia-prima.cuenta-envases');
    }

    public function puedeGestionarDespachoEnvases(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::OperadorRomana,
            RolUsuario::Despachador,
        ], 'materia-prima.despacho-envases');
    }

    public function puedeAnularDespachoEnvases(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorMateriales,
        ], 'materia-prima.despacho-envases');
    }

    public function puedeAccederOficina(User $usuario): bool
    {
        return $this->rolActivoEnModulo($usuario, [
            RolUsuario::Administrador,
            RolUsuario::SupervisorFrio,
            RolUsuario::SupervisorMateriales,
            RolUsuario::Despachador,
            RolUsuario::OperadorRomana,
            RolUsuario::DigitadorMateriaPrima,
            RolUsuario::CamareroFrio,
            RolUsuario::Consulta,
        ], $this->catalogoModulos->claves());
    }

    /**
     * @return array<string, mixed>
     */
    public function capacidadesApi(User $usuario): array
    {
        return [
            'solo_consulta' => $this->esSoloConsulta($usuario),
            'modulos_acceso' => $this->catalogoModulos->modulosUsuario($usuario),
            'modulos_tablet' => $this->catalogoModulos->modulosTabletUsuario($usuario),
            'perfil_acceso' => $usuario->perfilAcceso ? [
                'id' => $usuario->perfilAcceso->id,
                'codigo' => $usuario->perfilAcceso->codigo,
                'nombre' => $usuario->perfilAcceso->nombre,
            ] : null,
            'ambito_camaras' => $this->ambitoCamaras($usuario),
            'puede_supervisar' => $this->puedeSupervisarCamara($usuario, ContenidoCamara::Productos)
                || $this->puedeSupervisarCamara($usuario, ContenidoCamara::Materiales),
            'puede_operar_productos' => $this->puedeOperarCamara($usuario, ContenidoCamara::Productos),
            'puede_operar_materiales' => $this->puedeOperarCamara($usuario, ContenidoCamara::Materiales),
            'puede_consultar_control_ambiental' => $this->puedeConsultarControlAmbiental($usuario),
            'puede_registrar_control_ambiental' => $this->puedeRegistrarControlAmbiental($usuario),
            'puede_corregir_control_ambiental' => $this->puedeCorregirControlAmbiental($usuario),
            'puede_consultar_materia_prima' => $this->puedeConsultarMateriaPrima($usuario),
            'puede_gestionar_lotes_materia_prima' => $this->puedeGestionarLotesMateriaPrima($usuario),
            'puede_supervisar_lotes_materia_prima' => $this->puedeSupervisarLotesMateriaPrima($usuario),
            'puede_consultar_hidrocooler_materia_prima' => $this->puedeConsultarHidrocoolerMateriaPrima($usuario),
            'puede_operar_hidrocooler_materia_prima' => $this->puedeOperarHidrocoolerMateriaPrima($usuario),
            'puede_consultar_fruta_proceso' => $this->puedeConsultarFrutaProceso($usuario),
            'puede_consultar_despacho_comercial' => $this->puedeConsultarDespachoComercial($usuario),
            'puede_gestionar_despacho_comercial' => $this->puedeGestionarDespachoComercial($usuario),
            'puede_entregar_fruta_proceso' => $this->puedeEntregarFrutaProceso($usuario),
            'puede_corregir_entregas_fruta_proceso' => $this->puedeCorregirEntregasFrutaProceso($usuario),
            'puede_consultar_oficina_consultas' => $this->puedeConsultarOficinaConsultas($usuario),
            'puede_gestionar_pines_operadores' => $this->puedeGestionarPinesOperadores($usuario),
            'puede_consultar_sag' => $this->puedeConsultarSag($usuario),
            'puede_asociar_productores_csg' => $this->puedeAsociarProductoresCsg($usuario),
            'puede_consultar_cargas' => $this->puedeConsultarCargas($usuario),
            'puede_consultar_catalogo_cargas' => $this->puedeConsultarCatalogoCargas($usuario),
            'puede_gestionar_cargas' => $this->puedeGestionarCargas($usuario),
            'puede_autorizar_sobrecupo_embarques' => $this->puedeAutorizarSobrecupoEmbarques($usuario),
            'puede_reportar_incidencias_carga' => $this->puedeReportarIncidenciasCarga($usuario),
            'puede_resolver_comercialmente_carga' => $this->puedeResolverComercialmenteCarga($usuario),
            'puede_resolver_reparacion_carga' => $this->puedeResolverReparacionCarga($usuario),
            'puede_enviar_folios_anden' => $this->puedeEnviarFoliosAnden($usuario),
            'puede_cerrar_despacho_frigorifico' => $this->puedeCerrarDespachoFrigorifico($usuario),
            'puede_gestionar_andenes' => $this->puedeGestionarAndenes($usuario),
            'puede_consultar_despachos_materiales' => $this->puedeConsultarDespachosMateriales($usuario),
            'puede_gestionar_despachos_materiales' => $this->puedeGestionarDespachosMateriales($usuario),
            'puede_retirar_materiales' => $this->puedeRetirarMateriales($usuario),
            'puede_cancelar_despachos_materiales' => $this->puedeCancelarDespachosMateriales($usuario),
            'puede_consultar_kardex_materiales' => $this->puedeConsultarKardexMateriales($usuario),
            'puede_corregir_items_estibados_materiales' => $this->puedeCorregirItemsEstibadosMateriales($usuario),
            'puede_gestionar_bloqueos_materiales' => $this->puedeGestionarBloqueosMateriales($usuario),
            'puede_consultar_recepciones_materiales' => $this->puedeConsultarRecepcionesMateriales($usuario),
            'puede_gestionar_recepciones_materiales' => $this->puedeGestionarRecepcionesMateriales($usuario),
            'puede_anular_recepciones_materiales' => $this->puedeAnularRecepcionesMateriales($usuario),
            'puede_administrar_recepciones_materiales' => $this->puedeAdministrarRecepcionesMateriales($usuario),
            'puede_imprimir_etiquetas_materiales' => $this->puedeImprimirEtiquetasMateriales($usuario),
            'puede_consultar_transformaciones_materiales' => $this->puedeConsultarTransformacionesMateriales($usuario),
            'puede_gestionar_transformaciones_materiales' => $this->puedeGestionarTransformacionesMateriales($usuario),
            'puede_operar_transformaciones_materiales' => $this->puedeOperarTransformacionesMateriales($usuario),
            'puede_revertir_transformaciones_materiales' => $this->puedeRevertirTransformacionesMateriales($usuario),
            'puede_administrar_recetas_materiales' => $this->puedeAdministrarRecetasMateriales($usuario),
            'puede_validar_pallets' => $this->puedeValidarPallets($usuario),
            'puede_rechazar_pallets' => $this->puedeRechazarPallets($usuario),
            'puede_consultar_validaciones_pallet' => $this->puedeConsultarValidacionesPallet($usuario),
            'puede_imprimir_etiquetas_pt' => $this->puedeImprimirEtiquetasPt($usuario),
            'puede_registrar_repaletizajes' => $this->puedeRegistrarRepaletizajes($usuario),
            'puede_anular_repaletizajes' => $this->puedeAnularRepaletizajes($usuario),
            'puede_consultar_repaletizajes' => $this->puedeConsultarRepaletizajes($usuario),
            'puede_corregir_validaciones_pallet' => $this->puedeCorregirValidacionesPallet($usuario),
            'puede_administrar_catalogos_validacion' => $this->puedeAdministrarCatalogosValidacion($usuario),
            'puede_consultar_catalogos_validacion' => $this->puedeConsultarCatalogosValidacion($usuario),
            'puede_consultar_accesos' => $this->puedeConsultarAccesos($usuario),
            'puede_consultar_configuracion_camaras' => $this->puedeConsultarConfiguracionCamaras($usuario),
            'puede_consultar_integridad_operacional' => $this->puedeConsultarIntegridadOperacional($usuario),
            'puede_ejecutar_integridad_operacional' => $this->puedeEjecutarIntegridadOperacional($usuario),
            'puede_consultar_prefrio' => $this->puedeConsultarPrefrio($usuario),
            'puede_consultar_inspeccion_sag' => $this->puedeConsultarInspeccionSag($usuario),
            'puede_gestionar_inspeccion_sag' => $this->puedeGestionarInspeccionSag($usuario),
            'puede_operar_prefrio' => $this->puedeOperarPrefrio($usuario),
            'puede_supervisar_prefrio' => $this->puedeSupervisarPrefrio($usuario),
            'puede_administrar_tuneles_prefrio' => $this->puedeAdministrarTunelesPrefrio($usuario),
            'puede_consultar_panel_gerencial' => $this->puedeConsultarPanelGerencial($usuario),
            'puede_consultar_romana' => $this->puedeConsultarRomana($usuario),
            'puede_auditar_defectos_recepcion_mp' => $this->puedeAuditarDefectosRecepcionMp($usuario),
            'puede_operar_romana' => $this->puedeOperarRomana($usuario),
            'puede_corregir_recepciones_romana' => $this->puedeCorregirRecepcionesRomana($usuario),
            'puede_validar_mp' => $this->puedeValidarMp($usuario),
            'puede_consultar_cuenta_envases' => $this->puedeConsultarCuentaEnvases($usuario),
            'puede_revisar_cuenta_envases' => $this->puedeRevisarCuentaEnvases($usuario),
            'puede_gestionar_despacho_envases' => $this->puedeGestionarDespachoEnvases($usuario),
            'puede_anular_despacho_envases' => $this->puedeAnularDespachoEnvases($usuario),
        ];
    }

    public function esSoloConsulta(User $usuario): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        $usuario->loadMissing('perfilAcceso');

        return $usuario->rol === RolUsuario::Consulta
            || $usuario->perfilAcceso?->rol_base === RolUsuario::Consulta;
    }

    /**
     * @param  array<int, RolUsuario>  $roles
     * @param  string|array<int, string>  $modulos
     */
    private function rolActivoEnModulo(User $usuario, array $roles, string|array $modulos): bool
    {
        if (! $usuario->activo) {
            return false;
        }

        $usuario->loadMissing('perfilAcceso');
        $perfil = $usuario->perfilAcceso;

        if ($usuario->perfil_acceso_id !== null && (! $perfil || ! $perfil->activo)) {
            return false;
        }

        /*
         * Todo perfil activo asignado representa una decisión explícita de
         * administración: sus módulos son la autoridad operacional, sin importar si
         * el perfil es predeterminado o personalizado. La matriz legacy del rol se
         * conserva únicamente para usuarios sin perfil.
         *
         * "Solo consulta" sigue siendo una frontera transversal: un perfil de consulta
         * únicamente recibe capacidades cuyo permiso histórico admite Consulta.
         */
        if ($perfil) {
            if ($perfil->rol_base === RolUsuario::Consulta) {
                return in_array(RolUsuario::Consulta, $roles, true)
                    && $this->catalogoModulos->usuarioTieneModulo($usuario, $modulos);
            }

            return $this->catalogoModulos->usuarioTieneModulo($usuario, $modulos);
        }

        return in_array($this->rolEfectivo($usuario), $roles, true)
            && $this->catalogoModulos->usuarioTieneModulo($usuario, $modulos);
    }

    private function rolEfectivo(User $usuario): RolUsuario
    {
        return $this->esSoloConsulta($usuario)
            ? RolUsuario::Consulta
            : $usuario->rol;
    }

    /** @param  array<int, string>  $modulos */
    private function permiteAlgunModuloTablet(User $usuario, array $modulos): bool
    {
        foreach ($modulos as $modulo) {
            if ($this->permiteModuloTablet($usuario, $modulo)) {
                return true;
            }
        }

        return false;
    }

    private function permiteModuloTablet(User $usuario, string $modulo): bool
    {
        $token = $usuario->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->dispositivo_id === null) {
            return true;
        }

        return $token->can(CatalogoModulosAcceso::habilidadTablet($modulo));
    }
}
