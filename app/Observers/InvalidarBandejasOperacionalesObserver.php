<?php

namespace App\Observers;

use App\Models\Anden;
use App\Models\Camara;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Embarque;
use App\Models\EventoPrefrio;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\InstructivoEmbarque;
use App\Models\Movimiento;
use App\Models\Posicion;
use App\Models\PosicionTunelPrefrio;
use App\Models\PresenciaCargaAnden;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\ReservaCargaFolio;
use App\Models\TareaCarga;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\UbicacionActual;
use App\Services\Revisiones\RevisionBandejasOperacionales;
use Illuminate\Database\Eloquent\Model;

final class InvalidarBandejasOperacionalesObserver
{
    public function __construct(
        private readonly RevisionBandejasOperacionales $revisiones,
    ) {}

    /**
     * @return array<int, class-string<Model>>
     */
    public static function modelosObservados(): array
    {
        return array_values(array_unique([
            ...self::modelosPrefrio(),
            ...self::modelosCargas(),
        ]));
    }

    public function saved(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    public function updated(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    public function deleted(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    public function restored(Model $modelo): void
    {
        $this->invalidar($modelo);
    }

    /**
     * @return array<int, class-string<Model>>
     */
    private static function modelosPrefrio(): array
    {
        return [
            Temporada::class,
            Folio::class,
            TunelPrefrio::class,
            PosicionTunelPrefrio::class,
            ProcesoPrefrio::class,
            ProcesoPrefrioFolio::class,
            EventoPrefrio::class,
            UbicacionActual::class,
            CargaFolio::class,
            ReservaCargaFolio::class,
        ];
    }

    /**
     * @return array<int, class-string<Model>>
     */
    private static function modelosCargas(): array
    {
        return [
            Temporada::class,
            Folio::class,
            Carga::class,
            CargaFolio::class,
            Embarque::class,
            InstructivoEmbarque::class,
            ReservaCargaFolio::class,
            IncidenciaCargaFolio::class,
            TareaCarga::class,
            TareaMovimiento::class,
            ProcesoPrefrio::class,
            ProcesoPrefrioFolio::class,
            Camara::class,
            Posicion::class,
            UbicacionActual::class,
            Movimiento::class,
            Anden::class,
            PresenciaCargaAnden::class,
        ];
    }

    private function invalidar(Model $modelo): void
    {
        $bandejas = [];

        if (in_array($modelo::class, self::modelosPrefrio(), true)) {
            $bandejas[] = RevisionBandejasOperacionales::PREFRIO;
        }

        if (in_array($modelo::class, self::modelosCargas(), true)) {
            $bandejas[] = RevisionBandejasOperacionales::CARGAS;
        }

        if ($bandejas !== []) {
            $this->revisiones->invalidar(...$bandejas);
        }
    }
}
