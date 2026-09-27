<?php

namespace App\Http\Controllers\Api;

use App\Enums\AccionResolucionDiscrepancia;
use App\Enums\EstadoCustodiaTemporal;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoTareaMovimiento;
use App\Http\Controllers\Controller;
use App\Models\Camara;
use App\Models\DiscrepanciaManiobra;
use App\Models\Posicion;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioReplanificacionDiscrepancia;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiscrepanciaManiobraController extends Controller
{
    public function __construct(
        private readonly ServicioReplanificacionDiscrepancia $replanificador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $datos = validator($request->query(), [
            'estado' => ['nullable', Rule::in(['abierta', 'resuelta', 'todas'])],
            'q' => ['nullable', 'string', 'max:120'],
            'pagina' => ['nullable', 'integer', 'min:1'],
            'por_pagina' => ['nullable', 'integer', 'min:10', 'max:50'],
        ])->validate();

        $base = DiscrepanciaManiobra::query()
            ->whereHas(
                'maniobraOperacional.planOperacional.temporada',
                fn (Builder $consulta): Builder => $consulta->whereKey(app(ServicioTemporadaActiva::class)->buscar()?->id),
            );
        $conteos = (clone $base)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $consulta = (clone $base)->with([
            'folio:id,numero_folio',
            'reportadaPor:id,name',
            'resueltaPor:id,name',
            'dispositivo:id,codigo,nombre',
            'maniobraOperacional.planOperacional:id,tipo,titulo,temporada_id,referencia_tipo,referencia_id',
            'maniobraOperacional.custodiasTemporales' => fn ($relacion) => $relacion
                ->where('estado', EstadoCustodiaTemporal::Activa->value)
                ->select(['id', 'maniobra_operacional_id']),
            'tareaMovimiento.camaraOrigen:id,codigo,nombre',
            'tareaMovimiento.posicionOrigen:id,etiqueta',
            'tareaMovimiento.camaraDestino:id,codigo,nombre',
            'tareaMovimiento.posicionDestino:id,etiqueta',
        ]);

        if (($datos['estado'] ?? 'abierta') !== 'todas') {
            $consulta->where('estado', $datos['estado'] ?? EstadoDiscrepanciaManiobra::Abierta->value);
        }
        if (filled($datos['q'] ?? null)) {
            $termino = trim((string) $datos['q']);
            $consulta->where(function (Builder $filtro) use ($termino): void {
                $filtro->where('tipo', 'like', "%{$termino}%")
                    ->orWhere('detalle', 'like', "%{$termino}%")
                    ->orWhereHas('folio', fn (Builder $folio): Builder => $folio
                        ->where('numero_folio', 'like', "%{$termino}%"))
                    ->orWhereHas('maniobraOperacional', fn (Builder $maniobra): Builder => $maniobra
                        ->where('titulo', 'like', "%{$termino}%"));
            });
        }

        $discrepancias = $consulta
            ->orderByRaw("CASE estado WHEN 'abierta' THEN 1 ELSE 2 END")
            ->orderByDesc('reportada_at')
            ->paginate(
                perPage: (int) ($datos['por_pagina'] ?? 20),
                page: (int) ($datos['pagina'] ?? 1),
            );

        return response()->json([
            'resumen' => [
                'abiertas' => (int) ($conteos[EstadoDiscrepanciaManiobra::Abierta->value] ?? 0),
                'resueltas' => (int) ($conteos[EstadoDiscrepanciaManiobra::Resuelta->value] ?? 0),
            ],
            'data' => $discrepancias->getCollection()
                ->map(fn (DiscrepanciaManiobra $discrepancia): array => $this->detalle($discrepancia))
                ->values(),
            'meta' => [
                'pagina_actual' => $discrepancias->currentPage(),
                'ultima_pagina' => $discrepancias->lastPage(),
                'por_pagina' => $discrepancias->perPage(),
                'total' => $discrepancias->total(),
            ],
        ]);
    }

    public function resolver(
        Request $request,
        DiscrepanciaManiobra $discrepanciaManiobra,
        ServicioManiobrasOperacionales $maniobras,
    ): JsonResponse {
        $datos = $request->validate([
            'accion' => ['required', Rule::enum(AccionResolucionDiscrepancia::class)],
            'version_maniobra' => ['required', 'integer', 'min:1'],
            'resolucion' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $discrepancia = $maniobras->resolverDiscrepancia(
            $discrepanciaManiobra,
            $request->user(),
            AccionResolucionDiscrepancia::from($datos['accion']),
            (int) $datos['version_maniobra'],
            $datos['resolucion'],
        );

        $maniobra = $discrepancia->maniobraOperacional()->firstOrFail();
        $tarea = $discrepancia->tareaMovimiento()->firstOrFail();
        $recuperacion = filled($maniobra->contexto['maniobra_recuperacion_id'] ?? null)
            ? $maniobra->newQuery()->find($maniobra->contexto['maniobra_recuperacion_id'])
            : null;
        $tareaRecuperacion = $recuperacion?->pasos()->orderBy('secuencia_maniobra')->first();

        return response()->json(['data' => [
            'id' => $discrepancia->id,
            'estado' => $discrepancia->estado->value,
            'accion_resolucion' => $discrepancia->accion_resolucion?->value,
            'resolucion' => $discrepancia->resolucion,
            'resuelta_at' => $discrepancia->resuelta_at?->toAtomString(),
            'resuelta_por_user_id' => $discrepancia->resuelta_por_user_id,
            'maniobra' => [
                'id' => $maniobra->id,
                'estado' => $maniobra->estado->value,
                'version' => $maniobra->version,
            ],
            'tarea' => [
                'id' => $tarea->id,
                'estado' => $tarea->estado->value,
            ],
            'recuperacion' => $recuperacion ? [
                'maniobra_id' => $recuperacion->id,
                'estado' => $recuperacion->estado->value,
                'tarea_id' => $tareaRecuperacion?->id,
                'tarea_estado' => $tareaRecuperacion?->estado->value,
                'responsable_user_id' => $recuperacion->responsable_user_id,
                'dispositivo_id' => $recuperacion->dispositivo_id,
            ] : null,
        ]]);
    }

    /** @return array<string, mixed> */
    private function detalle(DiscrepanciaManiobra $discrepancia): array
    {
        $maniobra = $discrepancia->maniobraOperacional;
        $tarea = $discrepancia->tareaMovimiento;
        $bloqueoCancelacion = match (true) {
            $tarea->estado === EstadoTareaMovimiento::EnProceso => 'tarea_en_proceso',
            $maniobra->custodiasTemporales->isNotEmpty() => 'custodia_temporal_activa',
            default => null,
        };
        $bloqueoReplanificacion = match (true) {
            $tarea->estado === EstadoTareaMovimiento::EnProceso => 'tarea_en_proceso',
            $maniobra->custodiasTemporales->isNotEmpty() => 'custodia_temporal_activa',
            ! $this->replanificador->admite($maniobra->planOperacional) => 'plan_no_replanificable',
            default => null,
        };
        $bloqueoRetorno = match (true) {
            $tarea->estado === EstadoTareaMovimiento::EnProceso => 'tarea_en_proceso',
            $maniobra->custodiasTemporales->isEmpty() => 'sin_custodia_temporal',
            default => null,
        };

        return [
            'id' => $discrepancia->id,
            'tipo' => $discrepancia->tipo,
            'detalle' => $discrepancia->detalle,
            'estado' => $discrepancia->estado->value,
            'reportada_at' => $discrepancia->reportada_at?->toAtomString(),
            'reportada_por' => $discrepancia->reportadaPor ? [
                'id' => $discrepancia->reportadaPor->id,
                'nombre' => $discrepancia->reportadaPor->name,
            ] : null,
            'dispositivo' => $discrepancia->dispositivo ? [
                'id' => $discrepancia->dispositivo->id,
                'codigo' => $discrepancia->dispositivo->codigo,
                'nombre' => $discrepancia->dispositivo->nombre,
            ] : null,
            'folio' => [
                'id' => $discrepancia->folio->id,
                'numero' => $discrepancia->folio->numero_folio,
            ],
            'maniobra' => [
                'id' => $maniobra->id,
                'titulo' => $maniobra->titulo,
                'estado' => $maniobra->estado->value,
                'prioridad' => $maniobra->prioridad->value,
                'version' => $maniobra->version,
                'custodias_activas' => $maniobra->custodiasTemporales->count(),
                'plan' => [
                    'id' => $maniobra->planOperacional->id,
                    'titulo' => $maniobra->planOperacional->titulo,
                    'tipo' => $maniobra->planOperacional->tipo->value,
                ],
            ],
            'tarea' => [
                'id' => $tarea->id,
                'estado' => $tarea->estado->value,
                'secuencia' => $tarea->secuencia_maniobra,
                'tipo_paso' => $tarea->tipo_paso_maniobra?->value,
                'origen' => $this->ubicacion($tarea->camaraOrigen, $tarea->posicionOrigen),
                'destino' => $this->ubicacion($tarea->camaraDestino, $tarea->posicionDestino),
            ],
            'restricciones' => [
                'cancelar' => $bloqueoCancelacion,
                'replanificar' => $bloqueoReplanificacion,
                'retorno_seguro' => $bloqueoRetorno,
            ],
            'resolucion' => $discrepancia->resolucion,
            'accion_resolucion' => $discrepancia->accion_resolucion?->value,
            'resuelta_at' => $discrepancia->resuelta_at?->toAtomString(),
            'resuelta_por' => $discrepancia->resueltaPor ? [
                'id' => $discrepancia->resueltaPor->id,
                'nombre' => $discrepancia->resueltaPor->name,
            ] : null,
        ];
    }

    /** @return array{id: string, camara: string, posicion: ?string}|null */
    private function ubicacion(?Camara $camara, ?Posicion $posicion): ?array
    {
        if (! $camara) {
            return null;
        }

        return [
            'id' => $camara->id,
            'camara' => $camara->nombre,
            'posicion' => $posicion?->etiqueta,
        ];
    }
}
