<?php

namespace App\Observers;

use App\Models\CustodiaTemporalManiobra;
use App\Models\ManiobraOperacional;
use App\Services\Operacion\ServicioDisponibilidadCamareros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class DisponibilidadCamareroObserver
{
    public function saved(Model $modelo): void
    {
        $ids = [$modelo->responsable_user_id, $modelo->getRawOriginal('responsable_user_id')];
        if ($modelo instanceof CustodiaTemporalManiobra) {
            $ids[] = ManiobraOperacional::whereKey($modelo->maniobra_operacional_id)->value('responsable_user_id');
        }
        $ids = array_values(array_unique(array_filter($ids)));
        DB::afterCommit(function () use ($ids): void {
            foreach ($ids as $id) {
                try {
                    app(ServicioDisponibilidadCamareros::class)->actualizarUsuario((int) $id);
                } catch (Throwable $error) {
                    report($error);
                }
            }
        });
    }
}
