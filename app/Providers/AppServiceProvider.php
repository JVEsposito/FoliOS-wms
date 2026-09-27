<?php

namespace App\Providers;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Events\EventoCargaRegistrado;
use App\Listeners\CrearNotificacionesOperacionales;
use App\Models\AnulacionValidacionPallet;
use App\Models\AutorizacionSagFolio;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\CorreccionValidacionPallet;
use App\Models\DestinoLoteInspeccionSag;
use App\Models\EventoCarga;
use App\Models\EventoPrefrio;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\LoteInspeccionSag;
use App\Models\LoteInspeccionSagFolio;
use App\Models\ManiobraOperacional;
use App\Models\Movimiento;
use App\Models\PersonalAccessToken;
use App\Models\PresenciaCargaAnden;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\RegistroHabilitacionAlmacenamiento;
use App\Models\Repaletizaje;
use App\Models\RepaletizajeDetalle;
use App\Models\RepaletizajeResultado;
use App\Models\ReservaCargaFolio;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\ReservaTareaMovimiento;
use App\Models\ResultadoDestinoInspeccionSag;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\ValidacionPallet;
use App\Observers\AuditarCambioTransicionOperacionalObserver;
use App\Observers\EventoCargaObserver;
use App\Observers\InvalidarBandejasOperacionalesObserver;
use App\Observers\InvalidarPanelGerencialObserver;
use App\Observers\ReplanificarDesocupacionMovimientoObserver;
use App\Observers\ReplanificarOportunidadReordenamientoObserver;
use App\Observers\ReplanificarSegregacionMovimientoObserver;
use App\Observers\SolicitarArbitrajePlanificadorObserver;
use App\Observers\UbicacionActualObserver;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Transiciones\ContextoEjecucionTransicionOperacional;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ContextoEjecucionTransicionOperacional::class);

        if ($this->app->environment('local') &&
            class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'existencias-cortes',
            fn (Request $request): Limit => Limit::perMinute(3)->by(
                'existencias:cortes:usuario:'.($request->user()?->id ?? $request->ip()),
            ),
        );
        RateLimiter::for('existencias-consultas', function (Request $request): array {
            $token = trim((string) $request->query('token', ''));
            $identificador = $token !== '' ? hash('sha256', $token) : 'sin-token';

            return [
                Limit::perMinute(6)->by('existencias:consultas:token:'.$identificador),
                Limit::perMinute(30)->by('existencias:consultas:ip:'.$request->ip()),
            ];
        });

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        EventoCarga::observe(EventoCargaObserver::class);
        UbicacionActual::observe(UbicacionActualObserver::class);
        Movimiento::observe(ReplanificarSegregacionMovimientoObserver::class);
        Movimiento::observe(ReplanificarOportunidadReordenamientoObserver::class);
        Movimiento::observe(ReplanificarDesocupacionMovimientoObserver::class);
        foreach ([
            Folio::class,
            UbicacionActual::class,
            Movimiento::class,
            RegistroHabilitacionAlmacenamiento::class,
            ValidacionPallet::class,
            CorreccionValidacionPallet::class,
            AnulacionValidacionPallet::class,
            ProcesoPrefrio::class,
            ProcesoPrefrioFolio::class,
            EventoPrefrio::class,
            Carga::class,
            CargaFolio::class,
            ReservaCargaFolio::class,
            EventoCarga::class,
            IncidenciaCargaFolio::class,
            PresenciaCargaAnden::class,
            Repaletizaje::class,
            RepaletizajeDetalle::class,
            RepaletizajeResultado::class,
            LoteInspeccionSag::class,
            LoteInspeccionSagFolio::class,
            DestinoLoteInspeccionSag::class,
            ResultadoDestinoInspeccionSag::class,
            AutorizacionSagFolio::class,
            ReservaPosicionInspeccionSag::class,
            ManiobraOperacional::class,
            TareaMovimiento::class,
            ReservaTareaMovimiento::class,
        ] as $modeloAuditable) {
            $modeloAuditable::observe(AuditarCambioTransicionOperacionalObserver::class);
        }
        foreach (InvalidarPanelGerencialObserver::modelosObservados() as $modelo) {
            $modelo::observe(InvalidarPanelGerencialObserver::class);
        }
        foreach (InvalidarBandejasOperacionalesObserver::modelosObservados() as $modelo) {
            $modelo::observe(InvalidarBandejasOperacionalesObserver::class);
        }
        foreach (SolicitarArbitrajePlanificadorObserver::modelosObservados() as $modelo) {
            $modelo::observe(SolicitarArbitrajePlanificadorObserver::class);
        }
        Event::listen(EventoCargaRegistrado::class, CrearNotificacionesOperacionales::class);

        $alcance = app(AlcanceOperacionalUsuario::class);

        Gate::define(
            'consultar-configuracion-camaras',
            fn (User $usuario): bool => $alcance->puedeConsultarConfiguracionCamaras($usuario),
        );
        Gate::define(
            'crear-camaras-productos',
            fn (User $usuario): bool => $alcance->puedeCrearCamara(
                $usuario,
                ContenidoCamara::Productos,
            ),
        );
        Gate::define(
            'crear-camaras-materiales',
            fn (User $usuario): bool => $alcance->puedeCrearCamara(
                $usuario,
                ContenidoCamara::Materiales,
            ),
        );
        Gate::define(
            'crear-camaras-materia-prima',
            fn (User $usuario): bool => $alcance->puedeCrearCamara(
                $usuario,
                ContenidoCamara::MateriaPrima,
            ),
        );
        Gate::define(
            'operar-camaras-productos',
            fn (User $usuario): bool => $alcance->puedeOperarCamara(
                $usuario,
                ContenidoCamara::Productos,
            ),
        );
        Gate::define(
            'operar-camaras-materiales',
            fn (User $usuario): bool => $alcance->puedeOperarCamara(
                $usuario,
                ContenidoCamara::Materiales,
            ),
        );
        Gate::define(
            'supervisar-camaras-productos',
            fn (User $usuario): bool => $alcance->puedeSupervisarCamara(
                $usuario,
                ContenidoCamara::Productos,
            ),
        );
        Gate::define(
            'supervisar-camaras-materiales',
            fn (User $usuario): bool => $alcance->puedeSupervisarCamara(
                $usuario,
                ContenidoCamara::Materiales,
            ),
        );
        Gate::define(
            'consultar-control-ambiental',
            fn (User $usuario): bool => $alcance->puedeConsultarControlAmbiental($usuario),
        );
        Gate::define(
            'registrar-control-ambiental',
            fn (User $usuario): bool => $alcance->puedeRegistrarControlAmbiental($usuario),
        );
        Gate::define(
            'corregir-control-ambiental',
            fn (User $usuario): bool => $alcance->puedeCorregirControlAmbiental($usuario),
        );
        Gate::define(
            'administrar-camaras',
            fn (User $usuario): bool => $alcance->puedeAdministrarCamaras($usuario),
        );
        Gate::define(
            'administrar-accesos',
            fn (User $usuario): bool => $alcance->puedeAdministrarAccesos($usuario),
        );
        Gate::define(
            'consultar-accesos',
            fn (User $usuario): bool => $alcance->puedeConsultarAccesos($usuario),
        );
        Gate::define(
            'consultar-integridad-operacional',
            fn (User $usuario): bool => $alcance->puedeConsultarIntegridadOperacional($usuario),
        );
        Gate::define(
            'ejecutar-integridad-operacional',
            fn (User $usuario): bool => $alcance->puedeEjecutarIntegridadOperacional($usuario),
        );
        Gate::define(
            'reiniciar-datos-operacionales',
            fn (User $usuario): bool => $usuario->activo
                && $usuario->rol === RolUsuario::Administrador,
        );
        Gate::define(
            'gestionar-cargas',
            fn (User $usuario): bool => $alcance->puedeGestionarCargas($usuario),
        );
        Gate::define(
            'autorizar-sobrecupo-embarques',
            fn (User $usuario): bool => $alcance->puedeAutorizarSobrecupoEmbarques($usuario),
        );
        Gate::define(
            'consultar-cargas-operacion',
            fn (User $usuario): bool => $alcance->puedeConsultarCargas($usuario),
        );
        Gate::define(
            'consultar-catalogo-cargas',
            fn (User $usuario): bool => $alcance->puedeConsultarCatalogoCargas($usuario),
        );
        Gate::define(
            'gestionar-andenes',
            fn (User $usuario): bool => $alcance->puedeGestionarAndenes($usuario),
        );
        Gate::define(
            'administrar-catalogos-materiales',
            fn (User $usuario): bool => $alcance->puedeAdministrarCatalogosMateriales($usuario),
        );
        Gate::define(
            'gestionar-despachos-materiales',
            fn (User $usuario): bool => $alcance->puedeGestionarDespachosMateriales($usuario),
        );
        Gate::define(
            'consultar-despachos-materiales',
            fn (User $usuario): bool => $alcance->puedeConsultarDespachosMateriales($usuario),
        );
        Gate::define(
            'retirar-materiales',
            fn (User $usuario): bool => $alcance->puedeRetirarMateriales($usuario),
        );
        Gate::define(
            'cancelar-despachos-materiales',
            fn (User $usuario): bool => $alcance->puedeCancelarDespachosMateriales($usuario),
        );
        Gate::define(
            'consultar-kardex-materiales',
            fn (User $usuario): bool => $alcance->puedeConsultarKardexMateriales($usuario),
        );
        Gate::define(
            'corregir-items-estibados-materiales',
            fn (User $usuario): bool => $alcance->puedeCorregirItemsEstibadosMateriales($usuario),
        );
        Gate::define(
            'gestionar-bloqueos-materiales',
            fn (User $usuario): bool => $alcance->puedeGestionarBloqueosMateriales($usuario),
        );
        Gate::define(
            'consultar-recepciones-materiales',
            fn (User $usuario): bool => $alcance->puedeConsultarRecepcionesMateriales($usuario),
        );
        Gate::define(
            'gestionar-recepciones-materiales',
            fn (User $usuario): bool => $alcance->puedeGestionarRecepcionesMateriales($usuario),
        );
        Gate::define(
            'anular-recepciones-materiales',
            fn (User $usuario): bool => $alcance->puedeAnularRecepcionesMateriales($usuario),
        );
        Gate::define(
            'administrar-recepciones-materiales',
            fn (User $usuario): bool => $alcance->puedeAdministrarRecepcionesMateriales($usuario),
        );
        Gate::define(
            'imprimir-etiquetas-materiales',
            fn (User $usuario): bool => $alcance->puedeImprimirEtiquetasMateriales($usuario),
        );
        Gate::define(
            'consultar-transformaciones-materiales',
            fn (User $usuario): bool => $alcance->puedeConsultarTransformacionesMateriales($usuario),
        );
        Gate::define(
            'gestionar-transformaciones-materiales',
            fn (User $usuario): bool => $alcance->puedeGestionarTransformacionesMateriales($usuario),
        );
        Gate::define(
            'operar-transformaciones-materiales',
            fn (User $usuario): bool => $alcance->puedeOperarTransformacionesMateriales($usuario),
        );
        Gate::define(
            'revertir-transformaciones-materiales',
            fn (User $usuario): bool => $alcance->puedeRevertirTransformacionesMateriales($usuario),
        );
        Gate::define(
            'administrar-recetas-materiales',
            fn (User $usuario): bool => $alcance->puedeAdministrarRecetasMateriales($usuario),
        );
        Gate::define(
            'validar-pallets',
            fn (User $usuario): bool => $alcance->puedeValidarPallets($usuario),
        );
        Gate::define(
            'rechazar-pallets',
            fn (User $usuario): bool => $alcance->puedeRechazarPallets($usuario),
        );
        Gate::define(
            'registrar-repaletizajes',
            fn (User $usuario): bool => $alcance->puedeRegistrarRepaletizajes($usuario),
        );
        Gate::define(
            'anular-repaletizajes',
            fn (User $usuario): bool => $alcance->puedeAnularRepaletizajes($usuario),
        );
        Gate::define(
            'consultar-repaletizajes',
            fn (User $usuario): bool => $alcance->puedeConsultarRepaletizajes($usuario),
        );
        Gate::define(
            'consultar-validaciones-pallet',
            fn (User $usuario): bool => $alcance->puedeConsultarValidacionesPallet($usuario),
        );
        Gate::define(
            'corregir-validaciones-pallet',
            fn (User $usuario): bool => $alcance->puedeCorregirValidacionesPallet($usuario),
        );
        Gate::define(
            'administrar-catalogos-validacion',
            fn (User $usuario): bool => $alcance->puedeAdministrarCatalogosValidacion($usuario),
        );
        Gate::define(
            'consultar-catalogos-validacion',
            fn (User $usuario): bool => $alcance->puedeConsultarCatalogosValidacion($usuario),
        );
        Gate::define(
            'consultar-prefrio',
            fn (User $usuario): bool => $alcance->puedeConsultarPrefrio($usuario),
        );
        Gate::define(
            'consultar-inspeccion-sag',
            fn (User $usuario): bool => $alcance->puedeConsultarInspeccionSag($usuario),
        );
        Gate::define(
            'gestionar-inspeccion-sag',
            fn (User $usuario): bool => $alcance->puedeGestionarInspeccionSag($usuario),
        );
        Gate::define(
            'operar-prefrio',
            fn (User $usuario): bool => $alcance->puedeOperarPrefrio($usuario),
        );
        Gate::define(
            'supervisar-prefrio',
            fn (User $usuario): bool => $alcance->puedeSupervisarPrefrio($usuario),
        );
        Gate::define(
            'administrar-tuneles-prefrio',
            fn (User $usuario): bool => $alcance->puedeAdministrarTunelesPrefrio($usuario),
        );
        Gate::define(
            'corregir-procesos-prefrio',
            fn (User $usuario): bool => $usuario->activo
                && $usuario->rol === RolUsuario::Administrador,
        );
        Gate::define(
            'consultar-panel-gerencial',
            fn (User $usuario): bool => $alcance->puedeConsultarPanelGerencial($usuario),
        );
        Gate::define(
            'administrar-plano-planta',
            fn (User $usuario): bool => $usuario->activo
                && $usuario->rol === RolUsuario::Administrador,
        );
        Gate::define(
            'consultar-romana',
            fn (User $usuario): bool => $alcance->puedeConsultarRomana($usuario),
        );
        Gate::define(
            'operar-romana',
            fn (User $usuario): bool => $alcance->puedeOperarRomana($usuario),
        );
        Gate::define(
            'corregir-recepciones-romana',
            fn (User $usuario): bool => $alcance->puedeCorregirRecepcionesRomana($usuario),
        );
        Gate::define(
            'validar-mp',
            fn (User $usuario): bool => $alcance->puedeValidarMp($usuario),
        );
        Gate::define(
            'auditar-defectos-recepcion-mp',
            fn (User $usuario): bool => $alcance->puedeAuditarDefectosRecepcionMp($usuario),
        );
        Gate::define(
            'consultar-materia-prima',
            fn (User $usuario): bool => $alcance->puedeConsultarMateriaPrima($usuario),
        );
        Gate::define(
            'gestionar-lotes-materia-prima',
            fn (User $usuario): bool => $alcance->puedeGestionarLotesMateriaPrima($usuario),
        );
        Gate::define(
            'supervisar-lotes-materia-prima',
            fn (User $usuario): bool => $alcance->puedeSupervisarLotesMateriaPrima($usuario),
        );
        Gate::define(
            'consultar-hidrocooler-materia-prima',
            fn (User $usuario): bool => $alcance->puedeConsultarHidrocoolerMateriaPrima($usuario),
        );
        Gate::define(
            'operar-hidrocooler-materia-prima',
            fn (User $usuario): bool => $alcance->puedeOperarHidrocoolerMateriaPrima($usuario),
        );
        Gate::define(
            'consultar-fruta-proceso',
            fn (User $usuario): bool => $alcance->puedeConsultarFrutaProceso($usuario),
        );
        Gate::define(
            'entregar-fruta-proceso',
            fn (User $usuario): bool => $alcance->puedeEntregarFrutaProceso($usuario),
        );
        Gate::define(
            'anular-entregas-fruta-proceso',
            fn (User $usuario): bool => $alcance->puedeEntregarFrutaProceso($usuario)
                || $alcance->puedeCorregirEntregasFrutaProceso($usuario),
        );
        Gate::define(
            'archivar-temporadas',
            fn (User $usuario): bool => $usuario->activo && $usuario->rol === RolUsuario::Administrador,
        );
        Gate::define(
            'gestionar-pines-operadores',
            fn (User $usuario): bool => $alcance->puedeGestionarPinesOperadores($usuario),
        );
        Gate::define(
            'restablecer-pin-operacional',
            fn (User $usuario, User $objetivo): bool => $alcance->puedeRestablecerPinOperacional($usuario, $objetivo),
        );
        Gate::define(
            'consultar-oficina-consultas',
            fn (User $usuario): bool => $alcance->puedeConsultarOficinaConsultas($usuario),
        );
        Gate::define(
            'consultar-sag',
            fn (User $usuario): bool => $alcance->puedeConsultarSag($usuario),
        );
        Gate::define(
            'asociar-productores-csg',
            fn (User $usuario): bool => $alcance->puedeAsociarProductoresCsg($usuario),
        );
        Gate::define(
            'consultar-cuenta-envases',
            fn (User $usuario): bool => $alcance->puedeConsultarCuentaEnvases($usuario),
        );
        Gate::define(
            'revisar-cuenta-envases',
            fn (User $usuario): bool => $alcance->puedeRevisarCuentaEnvases($usuario),
        );
        Gate::define(
            'corregir-propiedad-envases',
            fn (User $usuario): bool => $usuario->activo
                && $usuario->rol === RolUsuario::Administrador
                && $alcance->puedeConsultarCuentaEnvases($usuario),
        );
        Gate::define(
            'corregir-cantidad-envases',
            fn (User $usuario): bool => $usuario->activo
                && $usuario->rol === RolUsuario::Administrador
                && $alcance->puedeConsultarCuentaEnvases($usuario),
        );
        Gate::define(
            'gestionar-despacho-envases',
            fn (User $usuario): bool => $alcance->puedeGestionarDespachoEnvases($usuario),
        );
        Gate::define(
            'anular-despacho-envases',
            fn (User $usuario): bool => $alcance->puedeAnularDespachoEnvases($usuario),
        );

        Sanctum::authenticateAccessTokensUsing(
            function (PersonalAccessToken $token, bool $esValido): bool {
                if (! $esValido || ! $token->tokenable instanceof User) {
                    return false;
                }

                if (! $token->tokenable->activo) {
                    return false;
                }

                if ($token->dispositivo_id === null) {
                    return in_array('oficina', $token->abilities ?? [], true);
                }

                return $token->dispositivo()->where('activo', true)->exists();
            },
        );
    }
}
