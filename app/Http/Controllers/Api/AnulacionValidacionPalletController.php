<?php

namespace App\Http\Controllers\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoValidacionPallet;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ResultadoValidacionPallet;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnularValidacionPalletRequest;
use App\Models\AnulacionValidacionPallet;
use App\Models\ValidacionPallet;
use App\Services\Temporadas\ServicioTemporadaActiva;
use App\Services\Validacion\ServicioAnulacionValidacionPallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnulacionValidacionPalletController extends Controller
{
    public function index(
        Request $request,
        ServicioAnulacionValidacionPallet $servicio,
        ServicioTemporadaActiva $temporadaActiva,
    ): JsonResponse {
        $filtros = $request->validate([
            'folio' => ['nullable', 'string', 'max:80'],
            'motivo_categoria' => [
                'nullable',
                'string',
                Rule::in(AnularValidacionPalletRequest::CATEGORIAS),
            ],
        ]);
        $folio = mb_strtoupper(trim((string) ($filtros['folio'] ?? '')));
        $categoria = trim((string) ($filtros['motivo_categoria'] ?? ''));

        // El historial y los contadores muestran solo la temporada activa. Sin
        // temporada activa no se mezclan temporadas: la lista queda vacía.
        $temporadaId = $temporadaActiva->buscar()?->id;

        // Solo pallets que siguen pendientes de prefrío: el filtro va en la consulta, y la
        // actividad posterior se revisa para todos juntos (una consulta por tipo).
        $candidatas = $temporadaId === null ? collect() : $servicio->anulables(ValidacionPallet::query()
            ->where('temporada_id', $temporadaId)
            ->where('estado', EstadoValidacionPallet::Aceptada->value)
            ->where('resultado', ResultadoValidacionPallet::Aprobado->value)
            ->whereHas('folio', fn (Builder $consulta): Builder => $consulta
                ->where('activo', true)
                ->where('origen_sistema', 'validacion')
                ->where('estado_operacional', EstadoOperacionalFolio::PendientePrefrio->value)
                ->where('condicion_termica', CondicionTermicaFolio::PendientePrefrio->value)
                ->where('habilitacion_almacenamiento', HabilitacionAlmacenamientoFolio::NoHabilitado->value))
            ->when($folio !== '', fn (Builder $consulta): Builder => $consulta
                ->where('numero_folio', 'like', "%{$folio}%"))
            ->with([
                'folio:id,numero_folio,tipo_bulto,estado_operacional,condicion_termica,habilitacion_almacenamiento,activo,origen_sistema,datos_externos',
                'usuario:id,name',
                'dispositivo:id,codigo,nombre',
            ])
            ->latest('recibido_servidor_at')
            ->limit(100)
            ->get())
            ->map(fn (ValidacionPallet $validacion): array => $this->recursoCandidata($validacion))
            ->values();
        $deLaTemporada = fn (): Builder => AnulacionValidacionPallet::query()
            ->whereHas('validacion', fn (Builder $consulta): Builder => $temporadaId === null
                ? $consulta->whereRaw('1 = 0')
                : $consulta->where('temporada_id', $temporadaId));

        $anulaciones = $deLaTemporada()
            ->when($folio !== '', fn (Builder $consulta): Builder => $consulta
                ->where('numero_folio', 'like', "%{$folio}%"))
            ->when($categoria !== '', fn (Builder $consulta): Builder => $consulta
                ->where('motivo_categoria', $categoria))
            ->with([
                'validacion.usuario:id,name',
                'anuladoPor:id,name',
                'folio:id,numero_folio,estado_operacional,condicion_termica,activo',
            ])
            ->latest('anulado_at')
            ->limit(200)
            ->get()
            ->map(fn (AnulacionValidacionPallet $anulacion): array => $this->recursoAnulacion($anulacion))
            ->values();

        $zona = config('app.operational_timezone');
        $inicioHoy = now($zona)->startOfDay()->utc();
        $finHoy = now($zona)->addDay()->startOfDay()->utc();
        $porCategoria = $deLaTemporada()
            ->selectRaw('motivo_categoria, COUNT(*) as total')
            ->groupBy('motivo_categoria')
            ->orderByDesc('total')
            ->pluck('total', 'motivo_categoria')
            ->map(fn ($total): int => (int) $total);

        return response()->json([
            'candidatas' => $candidatas,
            'anulaciones' => $anulaciones,
            'resumen' => [
                'total' => $deLaTemporada()->count(),
                'hoy' => $deLaTemporada()
                    ->where('anulado_at', '>=', $inicioHoy)
                    ->where('anulado_at', '<', $finHoy)
                    ->count(),
                'por_categoria' => $porCategoria,
            ],
        ]);
    }

    public function store(
        AnularValidacionPalletRequest $request,
        ValidacionPallet $validacionPallet,
        ServicioAnulacionValidacionPallet $servicio,
    ): JsonResponse {
        $anulacion = $servicio->anular(
            $validacionPallet,
            $request->validated(),
            $request->user(),
        );

        return response()->json([
            'data' => $this->recursoAnulacion($anulacion),
            'message' => 'Validación anulada. El número de folio quedó disponible para ingresarlo nuevamente.',
        ]);
    }

    /** @return array<string, mixed> */
    private function recursoCandidata(ValidacionPallet $validacion): array
    {
        return [
            'id' => $validacion->id,
            'numero_folio' => $validacion->numero_folio,
            'tipo_bulto' => $validacion->tipo_bulto,
            'cantidad_cajas' => $validacion->cantidad_cajas,
            'linea_proceso' => $validacion->linea_proceso,
            'turno' => $validacion->turno,
            'articulo_validacion_id' => $validacion->articulo_validacion_id,
            'origen_validacion_id' => $validacion->origen_validacion_id,
            'categoria_validacion_id' => $validacion->categoria_validacion_id,
            'validador' => $validacion->usuario ? [
                'id' => $validacion->usuario->id,
                'nombre' => $validacion->usuario->name,
            ] : null,
            'dispositivo' => $validacion->dispositivo ? [
                'id' => $validacion->dispositivo->id,
                'codigo' => $validacion->dispositivo->codigo,
                'nombre' => $validacion->dispositivo->nombre,
            ] : null,
            'validado_at' => $validacion->generado_dispositivo_at?->toAtomString(),
            'folio' => $validacion->folio ? [
                'id' => $validacion->folio->id,
                'estado_operacional' => $validacion->folio->estado_operacional?->value,
                'condicion_termica' => $validacion->folio->condicion_termica?->value,
                'activo' => $validacion->folio->activo,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function recursoAnulacion(AnulacionValidacionPallet $anulacion): array
    {
        return [
            'id' => $anulacion->id,
            'operacion_id' => $anulacion->operacion_id,
            'numero_folio' => $anulacion->numero_folio,
            'motivo_categoria' => $anulacion->motivo_categoria,
            'motivo' => $anulacion->motivo,
            'anulado_at' => $anulacion->anulado_at?->toAtomString(),
            'anulado_por' => $anulacion->anuladoPor ? [
                'id' => $anulacion->anuladoPor->id,
                'nombre' => $anulacion->anuladoPor->name,
            ] : null,
            'validacion' => $anulacion->validacion ? [
                'id' => $anulacion->validacion->id,
                'numero_intento' => $anulacion->validacion->numero_intento,
                'resultado_original' => $anulacion->snapshot['validacion']['resultado'] ?? null,
                'validador' => $anulacion->validacion->usuario ? [
                    'id' => $anulacion->validacion->usuario->id,
                    'nombre' => $anulacion->validacion->usuario->name,
                ] : null,
                'validado_at' => $anulacion->validacion->generado_dispositivo_at?->toAtomString(),
            ] : null,
            'folio' => $anulacion->folio ? [
                'id' => $anulacion->folio->id,
                'estado_operacional' => $anulacion->folio->estado_operacional?->value,
                'condicion_termica' => $anulacion->folio->condicion_termica?->value,
                'activo' => $anulacion->folio->activo,
            ] : null,
        ];
    }
}
