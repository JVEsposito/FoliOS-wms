<?php

namespace App\Finanzas\Http\Controllers;

use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Proveedores\GestorDocumentosCompra;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentosCompraController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['proveedor_id' => ['required', 'uuid'], 'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde']]);

        return response()->json(['data' => DocumentoCompra::query()->where('proveedor_id', $d['proveedor_id'])->when($d['desde'] ?? null, fn ($q, $v) => $q->where('fecha_emision', '>=', $v))->when($d['hasta'] ?? null, fn ($q, $v) => $q->where('fecha_emision', '<=', $v))->orderByDesc('fecha_emision')->orderByDesc('id')->paginate(30)]);
    }

    public function referencias(Request $request): JsonResponse
    {
        $d = $request->validate(['proveedor_id' => ['required', 'uuid'], 'buscar' => ['nullable', 'string', 'max:40'], 'ids' => ['sometimes', 'array', 'max:101'], 'ids.*' => ['uuid', 'distinct']]);
        $base = DocumentoCompra::query()->where('proveedor_id', $d['proveedor_id'])->whereIn('tipo', ['33', '34', '52'])->whereNotNull('registrado_at')->whereNull('anulado_at');
        $campos = ['id', 'tipo', 'folio', 'fecha_emision'];
        $recientes = (clone $base)->when(filled($d['buscar'] ?? null), fn ($q) => $q->where('folio', 'like', '%'.$d['buscar'].'%'))->orderByDesc('fecha_emision')->limit(50)->get($campos);
        $seleccionados = (clone $base)->whereIn('id', $d['ids'] ?? [])->get($campos);

        return response()->json(['data' => $recientes->merge($seleccionados)->unique('id')->values()]);
    }

    public function show(DocumentoCompra $documento): JsonResponse
    {
        return response()->json(['data' => $documento, 'asientos' => AsientoCompra::query()->with('lineas')->where('origen_id', $documento->id)->orWhereIn('reversa_de_id', AsientoCompra::query()->where('origen_tipo', 'documento')->where('origen_id', $documento->id)->select('id'))->orderBy('created_at')->get()]);
    }

    public function store(Request $request, GestorDocumentosCompra $gestor): JsonResponse
    {
        return response()->json(['data' => $gestor->guardar($this->datos($request), $request->user())], 201);
    }

    public function update(Request $request, DocumentoCompra $documento, GestorDocumentosCompra $gestor): JsonResponse
    {
        return response()->json(['data' => $gestor->guardar($this->datos($request, true), $request->user(), $documento)]);
    }

    private function datos(Request $r, bool $editar = false): array
    {
        $monto = ['required', 'regex:/^\d{1,15}$/'];

        return $r->validate([
            'operacion_id' => [$editar ? 'prohibited' : 'required', 'uuid'], 'version' => [$editar ? 'required' : 'sometimes', 'integer', 'min:1'],
            'proveedor_id' => ['required', 'uuid'], 'tipo' => ['required', Rule::in(array_keys(GestorDocumentosCompra::TIPOS))],
            'folio' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{1,40}$/'],
            'fecha_emision' => ['required', 'date_format:Y-m-d'], 'fecha_recepcion' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_emision'],
            'vencimiento' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_recepcion'], 'respaldo_plazo' => ['nullable', 'string', 'min:10', 'max:500'],
            'neto' => $monto, 'exento' => $monto, 'iva' => $monto, 'otros' => $monto,
            'lineas' => ['required', 'array', 'min:1', 'max:100'], 'lineas.*' => ['array:cuenta,descripcion,ceco,monto'],
            'lineas.*.cuenta' => ['required', 'string', 'max:30'], 'lineas.*.descripcion' => ['required', 'string', 'max:250'],
            'lineas.*.ceco' => ['nullable', 'string', 'max:80'], 'lineas.*.monto' => $monto,
            'reemplaza_id' => [$editar ? 'prohibited' : 'nullable', 'uuid'],
            'referencia_id' => ['nullable', 'uuid'], 'guias' => ['present', 'array', 'max:100'], 'guias.*' => ['required', 'uuid', 'distinct'],
            'respaldo' => ['required', 'string', 'min:5', 'max:1000'], 'observacion_iva' => ['nullable', 'string', 'min:5', 'max:500'],
        ]);
    }

    public function registrar(Request $request, DocumentoCompra $documento, GestorDocumentosCompra $gestor): JsonResponse
    {
        $d = $request->validate(['fecha_contable' => ['required', 'date_format:Y-m-d'], 'version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $gestor->registrar($documento, $d['fecha_contable'], $d['version'], $request->user())]);
    }

    public function anular(Request $request, DocumentoCompra $documento, GestorDocumentosCompra $gestor): JsonResponse
    {
        $d = $request->validate(['fecha_contable' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['data' => $gestor->anular($documento, $d['fecha_contable'], $d['motivo'], $request->user())]);
    }
}
