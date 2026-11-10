<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModalidadEmbarque;
use App\Http\Controllers\Controller;
use App\Models\SolicitudCargaCliente;
use App\Services\Portal\ServicioSolicitudesCargaCliente;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Despacho responde los horarios de carga que piden los clientes desde el portal. */
class SolicitudCargaClienteController extends Controller
{
    public function __construct(private readonly ServicioSolicitudesCargaCliente $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate(['estado' => ['nullable', Rule::in(['pendientes', 'todas'])]]);
        $temporada = app(ServicioTemporadaActiva::class)->buscar();
        $solicitudes = SolicitudCargaCliente::where('temporada_id', $temporada?->id)
            ->when(($filtros['estado'] ?? 'pendientes') === 'pendientes', fn ($q) => $q->where('estado', SolicitudCargaCliente::SOLICITADA))
            ->orderBy('fecha')->orderBy('hora')->limit(200)->get();

        return response()->json(['data' => $solicitudes->map(fn (SolicitudCargaCliente $s): array => $this->servicio->serializar($s, interno: true))->values()]);
    }

    public function aceptar(Request $request, SolicitudCargaCliente $solicitud): JsonResponse
    {
        $datos = $request->validate([
            'version_esperada' => ['required', 'integer', 'min:1'],
            'modalidad' => ['nullable', Rule::enum(ModalidadEmbarque::class)],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'autorizar_sobrecupo' => ['sometimes', 'boolean'],
            'motivo_sobrecupo' => [Rule::requiredIf($request->boolean('autorizar_sobrecupo')), 'nullable', 'string', 'min:10', 'max:1000'],
        ]);

        return response()->json(['data' => $this->servicio->serializar($this->servicio->aceptar($request->user(), $solicitud, $datos), interno: true)]);
    }

    public function rechazar(Request $request, SolicitudCargaCliente $solicitud): JsonResponse
    {
        $datos = $request->validate(['version_esperada' => ['required', 'integer', 'min:1'], 'motivo' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $this->servicio->serializar(
            $this->servicio->rechazar($request->user(), $solicitud, (int) $datos['version_esperada'], $datos['motivo']), interno: true,
        )]);
    }
}
