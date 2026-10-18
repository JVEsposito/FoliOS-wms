<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\ConsultaHechos;
use App\Finanzas\Http\PresentadorHechos;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;
use App\Finanzas\Periodo;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Hechos valorizables capturados: lo que Finanzas sabe de la operación, mes a mes. */
class HechosFinanzasController extends Controller
{
    private const POR_PAGINA = 50;

    public function __construct(
        private readonly CatalogoFuentes $fuentes,
        private readonly ConsultaHechos $consulta,
        private readonly PresentadorHechos $presentador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $datos = validator($request->query(), [
            'mes' => ['nullable', 'date_format:Y-m'],
            'fuente' => ['nullable', Rule::in(array_keys($this->fuentes->todas()))],
            'cliente' => ['nullable', 'string', 'max:40'],
            'estado' => ['nullable', Rule::in(['vigentes', 'anulados', 'todos'])],
            'q' => ['nullable', 'string', 'max:100'],
            'pagina' => ['nullable', 'integer', 'min:1'],
        ], [], [
            'mes' => 'mes', 'fuente' => 'fuente', 'cliente' => 'cliente', 'estado' => 'estado', 'q' => 'búsqueda',
        ])->validate();

        $periodo = Periodo::mes($datos['mes'] ?? null);
        $delMes = $this->consulta->delPeriodo($periodo);
        $resumen = $this->consulta->resumen($this->porCliente(clone $delMes, $datos['cliente'] ?? null));

        $consulta = $this->porCliente(clone $delMes, $datos['cliente'] ?? null)
            ->when($datos['fuente'] ?? null, fn (Builder $filtro, string $fuente) => $filtro->where('fuente', $fuente))
            ->when(($datos['estado'] ?? 'vigentes') !== 'todos', fn (Builder $filtro) => $filtro->where(
                'estado',
                ($datos['estado'] ?? 'vigentes') === 'anulados' ? Hecho::ESTADO_ANULADO : Hecho::ESTADO_VIGENTE,
            ))
            ->when(filled($datos['q'] ?? null), function (Builder $filtro) use ($datos): void {
                $termino = '%'.addcslashes(trim((string) $datos['q']), '%_\\').'%';
                $filtro->where(fn (Builder $texto) => $texto->where('referencia', 'like', $termino)->orWhere('cliente_nombre', 'like', $termino));
            });

        $pagina = $consulta
            ->orderByDesc('ocurrido_at')
            ->orderByDesc('id')
            ->paginate(perPage: self::POR_PAGINA, page: (int) ($datos['pagina'] ?? 1));

        $clientes = (clone $delMes)->toBase()
            ->where('estado', Hecho::ESTADO_VIGENTE)
            ->selectRaw('cliente_id, MAX(cliente_nombre) AS nombre, COUNT(*) AS hechos')
            ->groupBy('cliente_id')
            ->orderByRaw('cliente_id IS NULL, nombre')
            ->get()
            ->map(fn (object $cliente): array => [
                'id' => $cliente->cliente_id,
                'nombre' => $cliente->cliente_id === null ? 'Sin cliente vinculado' : $cliente->nombre,
                'hechos' => (int) $cliente->hechos,
            ]);

        $ultima = Lectura::query()->latest('iniciada_at')->latest('id')->first();

        return response()->json([
            'data' => $pagina->getCollection()->map(fn (Hecho $hecho): array => $this->presentador->hecho($hecho))->values(),
            'meta' => [
                'pagina' => $pagina->currentPage(),
                'paginas' => $pagina->lastPage(),
                'total' => $pagina->total(),
                'por_pagina' => $pagina->perPage(),
            ],
            'periodo' => $periodo->aArreglo(),
            'resumen' => $resumen,
            'fuentes' => $this->fuentes->descripcion(),
            'clientes' => $clientes,
            'ultima_captura' => $ultima ? $this->presentador->lectura($ultima) : null,
            'puede_capturar' => $request->user()->can('capturar-hechos-finanzas'),
        ]);
    }

    public function show(Hecho $hecho): JsonResponse
    {
        $versiones = Hecho::query()
            ->where('fuente', $hecho->fuente)
            ->where('origen_id', $hecho->origen_id)
            ->orderByDesc('version')
            ->get();

        return response()->json([
            'data' => $this->presentador->hecho($versiones->firstWhere('vigente', true) ?? $hecho),
            'versiones' => $versiones->map(fn (Hecho $version): array => $this->presentador->hecho($version))->values(),
        ]);
    }

    /**
     * @param  Builder<Hecho>  $consulta
     * @return Builder<Hecho>
     */
    private function porCliente(Builder $consulta, ?string $cliente): Builder
    {
        return match ($cliente) {
            null, '' => $consulta,
            'sin_cliente' => $consulta->whereNull('cliente_id'),
            default => $consulta->where('cliente_id', $cliente),
        };
    }
}
