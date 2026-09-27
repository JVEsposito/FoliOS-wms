<?php

namespace App\Services\Temporadas;

use App\Models\Temporada;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ServicioTemporadaActiva
{
    private bool $consultada = false;

    private ?Temporada $actual = null;

    public function buscar(bool $bloquear = false): ?Temporada
    {
        // Los bloqueos y las lecturas dentro de transacciones deben ver la BD
        // actual, aunque esta instancia ya haya atendido una lectura HTTP.
        $usarCache = ! $bloquear && DB::transactionLevel() === 0;
        if ($usarCache && $this->consultada) {
            return $this->actual === null ? null : clone $this->actual;
        }

        $consulta = $this->consulta();

        if ($bloquear) {
            $consulta->lockForUpdate();
        }

        $temporada = $consulta->first();
        if ($usarCache) {
            $this->actual = $temporada;
            $this->consultada = true;
        }

        return $temporada === null ? null : clone $temporada;
    }

    public function olvidar(): void
    {
        $this->actual = null;
        $this->consultada = false;
    }

    public function buscarConBloqueoCompartido(): ?Temporada
    {
        return $this->consulta()->sharedLock()->first();
    }

    /**
     * Los reportes que hacen un solo SELECT conservan la temporada en el mismo
     * snapshot SQL, sin convertir su subconsulta en una lectura anterior.
     *
     * @return Builder<Temporada>
     */
    public function subconsultaId(): Builder
    {
        return $this->consulta()->select('id')->limit(1);
    }

    /** @return Builder<Temporada> */
    private function consulta(): Builder
    {
        return Temporada::query()
            ->where('activa', true)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function obtener(bool $bloquear = false): Temporada
    {
        return $this->buscar($bloquear)
            ?? throw new DomainException('No existe una temporada global activa. Un administrador debe activarla desde Accesos.');
    }
}
