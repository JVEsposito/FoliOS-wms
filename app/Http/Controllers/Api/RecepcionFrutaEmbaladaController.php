<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarRecepcionFrutaEmbaladaRequest;
use App\Models\Cliente;
use App\Models\CondicionSag;
use App\Models\Folio;
use App\Models\PersonalAccessToken;
use App\Models\PlantaOrigen;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\User;
use App\Services\RecepcionFrutaEmbalada\ServicioBorradorRecepcionFrutaEmbalada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class RecepcionFrutaEmbaladaController extends Controller
{
    public function index(Request $request, ServicioTemporadaActiva $temporadas): JsonResponse
    {
        $f = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('desde') ? ['after_or_equal:desde'] : [])],
            'cliente_id' => ['nullable', 'uuid'], 'planta_origen_id' => ['nullable', 'uuid'],
            'temporada_id' => ['nullable', 'uuid', 'exists:temporadas,id'],
            'estado' => ['nullable', Rule::in(['borrador', 'aceptada', 'anulada'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = RecepcionFrutaEmbalada::query()->with(['cliente:id,nombre', 'plantaOrigen:id,nombre,codigo'])
            ->withCount('pallets')->where('temporada_id', $f['temporada_id'] ?? $temporadas->buscar()?->id);
        foreach (['cliente_id', 'planta_origen_id', 'estado'] as $campo) {
            if (filled($f[$campo] ?? null)) {
                $query->where($campo, $f[$campo]);
            }
        }
        foreach (['desde' => '>=', 'hasta' => '<='] as $campo => $comparacion) {
            if (filled($f[$campo] ?? null)) {
                $fecha = CarbonImmutable::parse($f[$campo], config('app.operational_timezone'));
                $query->where('recepcion_at', $comparacion, ($campo === 'desde' ? $fecha->startOfDay() : $fecha->endOfDay())->utc());
            }
        }

        return response()->json($query->orderByDesc('recepcion_at')->orderByDesc('id')->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    public function opciones(ServicioTemporadaActiva $temporadas): JsonResponse
    {
        $temporada = $temporadas->buscar();

        return response()->json([
            'temporada' => $temporada,
            'clientes' => Cliente::query()->with(['catalogosValidacion' => fn ($q) => $q->where('temporada_id', $temporada?->id)->where('activo', true)])->where('activo', true)->whereHas('catalogosValidacion', fn ($q) => $q->where('temporada_id', $temporada?->id)->where('activo', true))->orderBy('nombre')->get(['id', 'nombre'])->map(fn ($c) => [...$c->toArray(), 'catalogo_validacion_ids' => $c->catalogosValidacion->pluck('id')->all()]),
            'plantas_origen' => PlantaOrigen::where('activa', true)->orderBy('nombre')->get(),
            'condiciones_sag' => CondicionSag::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'codigo']),
            'validadores' => User::with('perfilAcceso')->where('activo', true)->orderBy('name')->get()->filter(fn ($u) => $u->can('gestionar-recepciones-fruta-embalada'))->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function catalogoPt(Request $request, ServicioTemporadaActiva $temporadas): Response
    {
        return app(CatalogoValidacionController::class)($request, $temporadas);
    }

    public function revisarGuia(Request $request, ServicioBorradorRecepcionFrutaEmbalada $servicio): JsonResponse
    {
        $datos = $request->validate(['cliente_id' => ['required', 'uuid'], 'planta_origen_id' => ['required', 'uuid'], 'numero_guia' => ['required', 'string', 'max:50'], 'excluir_id' => ['nullable', 'uuid']]);
        $duplicadas = $servicio->guiasDuplicadas($datos['cliente_id'], $datos['planta_origen_id'], $datos['numero_guia'], $datos['excluir_id'] ?? null);

        return response()->json(['duplicada' => $duplicadas->isNotEmpty(), 'mensaje' => $duplicadas->isNotEmpty() ? 'Esta guía ya se recibió del mismo cliente y planta de origen. Revisa antes de guardar.' : null, 'recepciones' => $duplicadas]);
    }

    public function revisarFolio(Request $request): JsonResponse
    {
        $datos = $request->validate(['folio_origen' => ['required', 'string', 'max:50']]);
        $repetido = $this->foliosVigentes([mb_strtoupper(trim($datos['folio_origen']))])->isNotEmpty();

        return response()->json(['repetido' => $repetido, 'mensaje' => $repetido ? 'Se asignará folio interno al aceptar' : null]);
    }

    public function show(RecepcionFrutaEmbalada $recepcionFrutaEmbalada): JsonResponse
    {
        return response()->json(['data' => $this->recurso($recepcionFrutaEmbalada)]);
    }

    public function store(GuardarRecepcionFrutaEmbaladaRequest $request, ServicioBorradorRecepcionFrutaEmbalada $servicio): JsonResponse
    {
        return $this->guardar($request, $servicio);
    }

    public function update(GuardarRecepcionFrutaEmbaladaRequest $request, RecepcionFrutaEmbalada $recepcionFrutaEmbalada, ServicioBorradorRecepcionFrutaEmbalada $servicio): JsonResponse
    {
        return $this->guardar($request, $servicio, $recepcionFrutaEmbalada);
    }

    private function guardar(GuardarRecepcionFrutaEmbaladaRequest $request, ServicioBorradorRecepcionFrutaEmbalada $servicio, ?RecepcionFrutaEmbalada $recepcion = null): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        [$resultado, $nueva] = $servicio->guardar($request->validated(), $request->user(), $token instanceof PersonalAccessToken ? $token->dispositivo_id : null, $recepcion);

        return response()->json(['data' => $this->recurso($resultado)], $nueva ? 201 : 200);
    }

    private function recurso(RecepcionFrutaEmbalada $recepcion): array
    {
        $recepcion->load(['cliente:id,nombre', 'plantaOrigen:id,nombre,codigo', 'validador:id,name', 'pallets']);
        $vigentes = $this->foliosVigentes($recepcion->pallets->pluck('folio_origen')->all());
        $repetidosEnRecepcion = $recepcion->pallets->pluck('folio_origen')->duplicates();

        return [...$recepcion->toArray(), 'editable' => $recepcion->estado->value === 'borrador' && $recepcion->temporada_id === app(ServicioTemporadaActiva::class)->buscar()?->id,
            'pallets' => $recepcion->pallets->map(fn ($p): array => [...$p->toArray(), 'fecha_proceso_origen' => $p->fecha_proceso_origen->format('Y-m-d'),
                'folio_repetido' => $vigentes->contains($p->folio_origen) || $repetidosEnRecepcion->contains($p->folio_origen),
                'aviso_folio' => $vigentes->contains($p->folio_origen) || $repetidosEnRecepcion->contains($p->folio_origen) ? 'Se asignará folio interno al aceptar' : null,
            ])->all(),
        ];
    }

    private function foliosVigentes(array $numeros)
    {
        return Folio::query()->whereIn('numero_folio', $numeros)->pluck('numero_folio');
    }
}
