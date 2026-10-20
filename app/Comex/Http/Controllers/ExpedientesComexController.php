<?php

namespace App\Comex\Http\Controllers;

use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Expediente\Expedientes;
use App\Comex\Expediente\PackingList;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Panel de expedientes y detalle de cada embarque. Solo lee. */
class ExpedientesComexController extends Controller
{
    public function __construct(
        private readonly Expedientes $expedientes,
        private readonly EmbarquesOperacion $embarques,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $datos = Validator::make($request->query(), [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d'],
            'cliente' => ['nullable', 'uuid'],
        ], [
            'desde.date_format' => 'La fecha de inicio no es válida.',
            'hasta.date_format' => 'La fecha de término no es válida.',
            'cliente.uuid' => 'El cliente indicado no es válido.',
        ])->validate();

        $hoy = CarbonImmutable::now((string) config('app.operational_timezone'))->startOfDay();
        $desde = isset($datos['desde']) ? CarbonImmutable::parse($datos['desde']) : $hoy->subDays(7);
        $hasta = isset($datos['hasta']) ? CarbonImmutable::parse($datos['hasta']) : $hoy->addDays(21);
        if ($hasta->lessThan($desde)) {
            throw ValidationException::withMessages(['hasta' => 'La fecha de término debe ser igual o posterior a la de inicio.']);
        }
        $maximo = (int) config('comex.dias_maximos_panel', 93);
        if ($desde->diffInDays($hasta) > $maximo) {
            throw ValidationException::withMessages(['hasta' => "Consulte como máximo {$maximo} días a la vez."]);
        }

        return response()->json([
            ...$this->expedientes->panel($desde, $hasta, $datos['cliente'] ?? null),
            'permisos' => $this->permisos($request),
        ]);
    }

    public function show(Request $request, string $embarque): JsonResponse
    {
        $detalle = $this->expedientes->detalle($embarque);
        abort_if($detalle === null, 404, 'El embarque no existe.');

        return response()->json([...$detalle, 'permisos' => $this->permisos($request)]);
    }

    public function packingList(Request $request, string $embarque, PackingList $packingList): BinaryFileResponse
    {
        $datos = $this->embarques->uno($embarque);
        abort_if($datos === null, 404, 'El embarque no existe.');
        $archivo = $packingList->generar($datos, $request->user()->name);

        return response()->download($archivo['ruta'], $archivo['nombre'])->deleteFileAfterSend();
    }

    /** @return array{gestionar: bool, configurar: bool} */
    private function permisos(Request $request): array
    {
        return [
            'gestionar' => $request->user()->can('gestionar-documentos-comex'),
            'configurar' => $request->user()->can('configurar-comex'),
        ];
    }
}
