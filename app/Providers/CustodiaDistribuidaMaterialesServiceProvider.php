<?php

namespace App\Providers;

use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\MovimientoAlmacenMaterial;
use App\Models\MovimientoInventarioMaterial;
use App\Models\ReservaMaterial;
use App\Models\ReservaTransformacionMaterial;
use App\Models\SaldoMaterialAlmacen;
use App\Models\UbicacionActual;
use App\Observers\FolioMaterialAlmacenObserver;
use App\Observers\MovimientoAlmacenMaterialObserver;
use App\Observers\ReposicionMaterialObserver;
use App\Observers\ReservaMaterialAlmacenObserver;
use App\Observers\ReservaTransformacionMaterialAlmacenObserver;
use App\Observers\SaldoMaterialAlmacenObserver;
use App\Observers\UbicacionActualAlmacenObserver;
use App\Services\Materiales\ContextoSaldoReservaMaterial;
use App\Services\Materiales\ServicioConsultaInventarioMaterial;
use App\Services\Materiales\ServicioConsultaInventarioMaterialDistribuido;
use App\Services\Materiales\ServicioDespachoMaterial;
use App\Services\Materiales\ServicioDespachoMaterialDistribuido;
use App\Services\Materiales\ServicioReservaFifoMaterial;
use App\Services\Materiales\ServicioReservaFifoMaterialDistribuido;
use Illuminate\Support\ServiceProvider;

class CustodiaDistribuidaMaterialesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ContextoSaldoReservaMaterial::class);
        $this->app->bind(
            ServicioReservaFifoMaterial::class,
            ServicioReservaFifoMaterialDistribuido::class,
        );
        $this->app->bind(
            ServicioDespachoMaterial::class,
            ServicioDespachoMaterialDistribuido::class,
        );
        $this->app->bind(
            ServicioConsultaInventarioMaterial::class,
            ServicioConsultaInventarioMaterialDistribuido::class,
        );
    }

    public function boot(): void
    {
        foreach ([ItemMaterial::class, FolioMaterial::class, SaldoMaterialAlmacen::class, MovimientoAlmacenMaterial::class, MovimientoInventarioMaterial::class] as $modelo) {
            $modelo::observe(ReposicionMaterialObserver::class);
        }
        FolioMaterial::observe(FolioMaterialAlmacenObserver::class);
        ReservaMaterial::observe(ReservaMaterialAlmacenObserver::class);
        ReservaTransformacionMaterial::observe(
            ReservaTransformacionMaterialAlmacenObserver::class,
        );
        SaldoMaterialAlmacen::observe(SaldoMaterialAlmacenObserver::class);
        MovimientoAlmacenMaterial::observe(MovimientoAlmacenMaterialObserver::class);
        UbicacionActual::observe(UbicacionActualAlmacenObserver::class);
    }
}
