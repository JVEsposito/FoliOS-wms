<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Captura\CapturaEnCurso;
use App\Finanzas\Captura\CatalogoFuentes;
use App\Finanzas\Captura\ServicioCapturaHechos;
use App\Finanzas\Http\PresentadorHechos;
use App\Finanzas\Models\Cursor;
use App\Finanzas\Models\Lectura;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lecturas de la operación: el historial y la lectura a pedido. */
class CapturasFinanzasController extends Controller
{
    public function __construct(private readonly PresentadorHechos $presentador) {}

    public function index(CatalogoFuentes $fuentes): JsonResponse
    {
        $cursores = Cursor::query()->get()->keyBy('fuente');

        return response()->json([
            'data' => Lectura::query()
                ->latest('iniciada_at')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (Lectura $lectura): array => $this->presentador->lectura($lectura))
                ->values(),
            'fuentes' => collect($fuentes->descripcion())->map(fn (array $fuente): array => [
                ...$fuente,
                'leida_hasta' => $cursores->get($fuente['clave'])?->marca_at?->toIso8601String(),
            ])->values(),
            'configuracion' => [
                'programada' => (bool) config('finanzas.captura.habilitada'),
                'cada_minutos' => 10,
                'margen_minutos' => (int) config('finanzas.captura.margen_minutos'),
            ],
        ]);
    }

    public function store(Request $request, ServicioCapturaHechos $captura): JsonResponse
    {
        // La primera lectura recorre toda la operación; las siguientes, solo lo cambiado.
        set_time_limit(600);

        try {
            $lectura = $captura->capturar(Lectura::ORIGEN_MANUAL, $request->user());
        } catch (CapturaEnCurso $enCurso) {
            return response()->json(['message' => $enCurso->getMessage(), 'codigo' => 'captura_en_curso'], 409);
        }

        return response()->json([
            'data' => $this->presentador->lectura($lectura),
            'message' => $lectura->estado === Lectura::ESTADO_COMPLETADA
                ? 'Lectura completada.'
                : ($lectura->error ?? 'La lectura terminó con errores.'),
        ], 201);
    }
}
