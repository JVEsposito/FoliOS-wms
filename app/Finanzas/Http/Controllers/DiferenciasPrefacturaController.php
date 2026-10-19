<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Cierre\CalendarioCierres;
use App\Finanzas\Cierre\GestorDiferencias;
use App\Finanzas\Cierre\RevisionDiferencias;
use App\Finanzas\Http\PresentadorDiferencias;
use App\Finanzas\Http\RespuestaPrefactura;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Diferencias de los meses cerrados: revisarlas y decidir, una por una. */
class DiferenciasPrefacturaController extends Controller
{
    private const DECIMAL_CON_SIGNO = '/^-?\d{1,18}(\.\d{1,6})?$/';

    public function __construct(
        private readonly CalendarioCierres $calendario,
        private readonly RevisionDiferencias $revision,
        private readonly GestorDiferencias $gestor,
        private readonly PresentadorDiferencias $presentador,
        private readonly RespuestaPrefactura $respuesta,
    ) {}

    /** Diferencias pendientes de un mes cerrado y las decisiones ya tomadas sobre él. */
    public function index(Request $request): JsonResponse
    {
        $cierre = $this->cierre($request);

        return response()->json($this->presentador->revision($cierre, $this->revision->calcular($cierre)));
    }

    /** Meses cerrados con diferencias por revisar; un mes sin cambios no se recalcula. */
    public function resumen(): JsonResponse
    {
        $meses = Cierre::query()->where('estado', Cierre::ESTADO_CERRADO)->orderByDesc('mes')->get()
            ->map(fn (Cierre $cierre): array => ['mes' => $cierre->mes, ...$this->revision->resumen($cierre)])
            ->filter(fn (array $mes): bool => $mes['diferencias'] > 0)
            ->values();

        return response()->json(['data' => $meses]);
    }

    public function store(Request $request): JsonResponse
    {
        $periodo = $this->respuesta->periodo($request->all(), obligatorio: true);
        $datos = $request->validate([
            'cliente_id' => ['required', 'string', 'uuid'],
            'tarifa_id' => ['required', 'integer', 'min:1'],
            'moneda' => ['required', 'string', 'max:10'],
            'decision' => ['required', Rule::in([Diferencia::ACEPTADA, Diferencia::DESCARTADA])],
            'cantidad' => ['required', 'string', 'regex:'.self::DECIMAL_CON_SIGNO],
            'dias' => ['required', 'integer'],
            'monto' => ['required', 'string', 'regex:'.self::DECIMAL_CON_SIGNO],
            'motivo' => ['nullable', 'required_if:decision,'.Diferencia::DESCARTADA, 'string', 'max:500'],
        ], [
            'cliente_id.*' => 'La diferencia indicada no es válida.',
            'tarifa_id.*' => 'La diferencia indicada no es válida.',
            'moneda.*' => 'La diferencia indicada no es válida.',
            'decision.required' => 'Indique si acepta o descarta la diferencia.',
            'decision.in' => 'Indique si acepta o descarta la diferencia.',
            'cantidad.*' => 'La diferencia revisada no es válida: vuelva a cargarla.',
            'dias.*' => 'La diferencia revisada no es válida: vuelva a cargarla.',
            'monto.*' => 'La diferencia revisada no es válida: vuelva a cargarla.',
            'motivo.required_if' => 'Indique por qué se descarta la diferencia.',
            'motivo.max' => 'El motivo no puede superar 500 caracteres.',
        ]);

        $diferencia = $this->gestor->decidir($periodo->clave(), [...$datos, 'motivo' => isset($datos['motivo']) ? trim($datos['motivo']) : null], $request->user());

        return response()->json(['data' => $this->presentador->decision($diferencia)], 201);
    }

    public function anular(Request $request, Diferencia $diferencia): JsonResponse
    {
        $datos = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [
            'motivo.required' => 'Indique por qué se deshace la decisión.',
            'motivo.min' => 'Explique el motivo en al menos 5 caracteres.',
            'motivo.max' => 'El motivo no puede superar 500 caracteres.',
        ]);

        $diferencia = $this->gestor->anular($diferencia, trim($datos['motivo']), $request->user());

        return response()->json(['data' => $this->presentador->decision($diferencia)]);
    }

    private function cierre(Request $request): Cierre
    {
        $periodo = $this->respuesta->periodo($request->query(), obligatorio: true);
        $cierre = $this->calendario->vigente($periodo->clave());
        if ($cierre === null) {
            throw ValidationException::withMessages(['mes' => 'El mes no está cerrado: no tiene diferencias que revisar.']);
        }

        return $cierre;
    }
}
