<?php

namespace App\Observers;

use App\Enums\TipoEventoCarga;
use App\Events\EventoCargaRegistrado;
use App\Models\EventoCarga;
use App\Models\User;
use App\Services\Planificador\ServicioDepuracionIngresosCamara;
use Illuminate\Support\Facades\DB;

class EventoCargaObserver
{
    public function created(EventoCarga $evento): void
    {
        EventoCargaRegistrado::dispatch($evento);

        if ($evento->folio_id && in_array($evento->tipo, [TipoEventoCarga::FolioEnviadoAnden, TipoEventoCarga::FolioCargado, TipoEventoCarga::FolioDespachado], true)) {
            // Cancelar las labores obsoletas después de confirmar la salida, fuera de los bloqueos de la carga.
            DB::afterCommit(fn () => app(ServicioDepuracionIngresosCamara::class)
                ->depurarFolio($evento->folio_id, User::find($evento->user_id)));
        }
    }
}
