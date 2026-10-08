<?php

namespace App\Services\Verificaciones;

use App\Models\Camara;
use Illuminate\Support\Facades\DB;

class ServicioIndicadoresVerificacionMateriales
{
    public function resumen(string $temporada): array
    {
        $periodos = [];
        $camaras = Camara::query()->where('contenido', 'materiales')->get(['id', 'codigo']);
        foreach ([7, 30] as $dias) {
            $desde = now()->subDays($dias);
            $base = DB::table('verificaciones_ubicacion_items as i')
                ->join('verificaciones_ubicacion as r', 'r.id', '=', 'i.verificacion_ubicacion_id')
                ->join('posiciones as p', 'p.id', '=', 'i.posicion_id')
                ->where('r.temporada_id', $temporada)->where('r.contenido', 'materiales');
            $presencia = (clone $base)->whereBetween('i.verificada_at', [$desde, now()])
                ->whereNotNull('i.resultado')->where('i.resultado', '!=', 'no_aplica')
                ->selectRaw("p.camara_id, COUNT(*) total, SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM verificaciones_ubicacion_folios f WHERE f.verificacion_ubicacion_item_id = i.id AND f.resultado IN ('folio_faltante', 'folio_sobrante')) THEN 1 ELSE 0 END) correctas")
                ->groupBy('p.camara_id')->get()->keyBy('camara_id');
            $cantidad = (clone $base)->join('verificaciones_ubicacion_folios as f', 'f.verificacion_ubicacion_item_id', '=', 'i.id')
                ->whereBetween('i.verificada_at', [$desde, now()])->where('r.verificar_cantidad', true)
                ->whereIn('f.resultado', ['coincide', 'diferencia_cantidad'])
                ->selectRaw("p.camara_id, COUNT(*) total, SUM(CASE WHEN f.resultado = 'coincide' THEN 1 ELSE 0 END) correctas")
                ->groupBy('p.camara_id')->get()->keyBy('camara_id');
            $rondas = (clone $base)->whereBetween('r.turno_inicio_at', [$desde, now()])
                ->where(fn ($q) => $q->whereNull('i.resultado')->orWhere('i.resultado', '!=', 'no_aplica'))
                ->selectRaw("p.camara_id, COUNT(DISTINCT r.id) generadas, COUNT(DISTINCT CASE WHEN r.estado = 'completada' THEN r.id END) completadas")
                ->groupBy('p.camara_id')->get()->keyBy('camara_id');
            $periodos[$dias] = ['camaras' => $camaras->map(fn ($c) => [
                'id' => $c->id, 'codigo' => $c->codigo,
                'ubicacion' => $this->exactitud($presencia->get($c->id)),
                'cantidad' => $this->exactitud($cantidad->get($c->id)),
                'cumplimiento' => ['completadas' => (int) ($rondas->get($c->id)?->completadas ?? 0),
                    'generadas' => (int) ($rondas->get($c->id)?->generadas ?? 0),
                    'porcentaje' => ($rondas->get($c->id)?->generadas ?? 0) > 0
                        ? round($rondas[$c->id]->completadas * 100 / $rondas[$c->id]->generadas, 1) : null],
            ])->all()];
        }

        return ['habilitada' => (bool) config('verificaciones.habilitada') && (bool) config('verificaciones.materiales.habilitada'),
            'periodos' => $periodos];
    }

    private function exactitud(?object $fila): array
    {
        $total = (int) ($fila?->total ?? 0);
        $correctas = (int) ($fila?->correctas ?? 0);

        return ['correctas' => $correctas, 'total' => $total,
            'porcentaje' => $total > 0 ? round($correctas * 100 / $total, 1) : null];
    }
}
