<?php

namespace App\Services\Materiales;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\TipoAlmacenMaterial;
use App\Models\Camara;
use App\Models\SaldoMaterialAlmacen;
use App\Models\UbicacionActual;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Proyección de consulta: cada cámara muestra su saldo, conservando el contrato del plano. */
class ServicioPlanoBodegasMateriales
{
    public function resumir(Collection $camaras): void
    {
        $materiales = $camaras->filter(fn ($c) => $c->contenido === ContenidoCamara::Materiales);
        if ($materiales->isEmpty()) {
            return;
        }
        $saldos = DB::table('saldos_materiales_almacenes as s')
            ->join('destinos_materiales as a', 'a.id', '=', 's.almacen_material_id')
            ->leftJoin('posiciones as p', 'p.id', '=', 's.posicion_id')
            ->whereIn('s.camara_id', $materiales->modelKeys())->where('s.cantidad_actual', '>', 0)
            ->where('a.tipo', TipoAlmacenMaterial::Fisica->value)->where('a.activo', true)
            ->select('s.camara_id')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.estado = ? THEN s.posicion_id END) AS ocupadas', [EstadoPosicion::Activa->value])
            ->selectRaw('SUM(CASE WHEN s.posicion_id IS NULL THEN 1 ELSE 0 END) AS sin_posicion')
            ->groupBy('s.camara_id')->get()->keyBy('camara_id');
        foreach ($materiales as $camara) {
            $grupo = $saldos->get($camara->id);
            $camara->setAttribute('posiciones_ocupadas_count', (int) ($grupo->ocupadas ?? 0));
            $camara->setAttribute('ubicaciones_sin_posicion_count', (int) ($grupo->sin_posicion ?? 0));
        }
    }

    public function enriquecer(Camara $camara): void
    {
        if ($camara->contenido !== ContenidoCamara::Materiales) {
            return;
        }
        $ubicaciones = $this->consulta()->where('camara_id', $camara->id)
            ->with(['folioMaterial.item.cliente.temporada', 'folioMaterial.folio.condicionSag', 'folioMaterial.folio.temporada'])
            ->orderBy('updated_at')->orderBy('folio_id')->get()->map(function ($saldo) {
                // Copias solo para serialización. UbicacionActual continúa representando Central en la BD.
                $material = clone $saldo->folioMaterial;
                $material->cantidad_actual = $saldo->cantidad_actual;
                $material->cantidad_reservada = $saldo->cantidad_reservada;
                $folio = clone $material->folio;
                $folio->setRelation('material', $material);
                $ubicacion = new UbicacionActual(['folio_id' => $saldo->folio_id, 'camara_id' => $saldo->camara_id,
                    'posicion_id' => $saldo->posicion_id, 'ubicado_at' => $saldo->updated_at]);
                $ubicacion->setRelation('folio', $folio);

                return $ubicacion;
            });
        $porPosicion = $ubicaciones->groupBy('posicion_id');
        foreach ($camara->posiciones as $posicion) {
            $grupo = new Collection($porPosicion->get($posicion->id, collect())->all());
            $posicion->setRelation('ubicacionesActuales', $grupo);
            $posicion->setRelation('ubicacionActual', $grupo->first());
        }
        $camara->setRelation('ubicacionesSinPosicion', new Collection($ubicaciones->whereNull('posicion_id')->values()->all()));
        $this->resumir(new Collection([$camara]));
    }

    private function consulta()
    {
        return SaldoMaterialAlmacen::query()->where('cantidad_actual', '>', 0)
            ->whereHas('almacen', fn ($q) => $q->where('tipo', TipoAlmacenMaterial::Fisica->value)->where('activo', true));
    }
}
