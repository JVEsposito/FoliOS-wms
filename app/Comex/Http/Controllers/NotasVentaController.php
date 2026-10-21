<?php

namespace App\Comex\Http\Controllers;

use App\Comex\Comercial\CantidadesComerciales;
use App\Comex\Comercial\NotasVenta;
use App\Comex\Models\EventoComercial;
use App\Comex\Models\NotaVenta;
use App\Comex\Models\NotaVentaRevision;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NotasVentaController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', 'in:abierta,cerrada,anulada']]);

        return response()->json(['data' => NotaVenta::query()->when($d['buscar'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('folio', 'like', '%'.$s.'%')->orWhere('cliente->nombre', 'like', '%'.$s.'%')))->when($d['estado'] ?? null, fn ($q, $s) => $q->where('estado', $s))->orderByDesc('created_at')->orderBy('folio')->paginate(25), 'monedas' => CantidadesComerciales::MONEDAS, 'incoterms' => NotasVenta::INCOTERMS, 'roles' => NotasVenta::ROLES]);
    }

    public function show(Request $r, NotaVenta $nota, NotasVenta $g): JsonResponse
    {
        $data = $g->detalle($nota);

        return response()->json(['data' => $data, 'puede_decidir' => $r->user()->can('aprobar-comercial-comex') && (! config('comex.aprobador_distinto', true) || $data['propuesta']['preparado_por_user_id'] !== $r->user()->id)]);
    }

    public function guardar(Request $r, NotasVenta $g, ?NotaVenta $nota = null): JsonResponse
    {
        $numerico = ['required', 'string', 'regex:'.CantidadesComerciales::DECIMAL];
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'folio' => ['required', 'string', 'max:80'], 'cliente_operacion_id' => ['required', 'uuid'], 'gestion' => ['required', 'in:servicio,propia'],
            'fecha' => ['required', 'date_format:Y-m-d'], 'vigente_hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha'], 'temporada_referencia' => ['required', 'string', 'max:80'], 'referencia_pedido' => ['required', 'string', 'max:200'],
            'negocio' => ['required', 'in:firme,consignacion,precio_abierto'], 'moneda' => ['required', Rule::in(array_keys(CantidadesComerciales::MONEDAS))], 'incoterm' => ['required', Rule::in(NotasVenta::INCOTERMS)], 'lugar_incoterm' => ['required', 'string', 'max:200'],
            'modalidad' => ['required', 'in:maritimo,aereo,terrestre'], 'condicion_pago' => ['required', 'string', 'min:5', 'max:500'], 'contrata_flete_id' => ['required', 'uuid', Rule::exists('comex.cx_partes_comerciales', 'id')],
            'partes' => ['required', 'array:'.implode(',', [...NotasVenta::ROLES, 'notify'])], ...collect(NotasVenta::ROLES)->mapWithKeys(fn ($rol) => ['partes.'.$rol => ['required', 'uuid', Rule::exists('comex.cx_partes_comerciales', 'id')]])->all(),
            'partes.notify' => ['nullable', 'array', 'max:10'], 'partes.notify.*' => ['uuid', 'distinct', Rule::exists('comex.cx_partes_comerciales', 'id')],
            'lineas' => ['required', 'array', 'min:1', 'max:100'], 'lineas.*' => ['array:id,sku,descripcion,especie,variedad,calibre,categoria,embalaje,unidad,cantidad,kilos,precio'], 'lineas.*.id' => ['nullable', 'uuid', 'distinct'],
            'lineas.*.sku' => ['required', 'string', 'max:60'], 'lineas.*.descripcion' => ['required', 'string', 'max:200'], 'lineas.*.especie' => ['required', 'string', 'max:80'], 'lineas.*.variedad' => ['required', 'string', 'max:80'], 'lineas.*.calibre' => ['required', 'string', 'max:40'], 'lineas.*.categoria' => ['required', 'string', 'max:40'], 'lineas.*.embalaje' => ['required', 'string', 'max:100'],
            'lineas.*.unidad' => ['required', 'in:caja,kg,pallet,unidad'], 'lineas.*.cantidad' => $numerico, 'lineas.*.kilos' => ['nullable', 'string', 'regex:'.CantidadesComerciales::DECIMAL], 'lineas.*.precio' => ['nullable', 'string', 'regex:'.CantidadesComerciales::DECIMAL],
            'respaldo' => ['required', 'string', 'min:10', 'max:1000'], 'observaciones' => ['nullable', 'string', 'max:2000'], 'motivo' => [$nota ? 'required' : 'nullable', 'string', 'min:10', 'max:1000']]);

        return response()->json(['data' => $g->guardar($d, $r->user(), $nota)], $nota ? 200 : 201);
    }

    public function transicion(Request $r, NotaVenta $nota, NotasVenta $g): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'], 'accion' => ['required', 'in:nueva_revision,enviar_revision,retirar_revision,aprobar,rechazar,cerrar,anular'], 'motivo' => ['required', 'string', 'min:10', 'max:1000']]);
        if (in_array($d['accion'], ['aprobar', 'rechazar', 'cerrar', 'anular'], true)) {
            abort_unless($r->user()->can('aprobar-comercial-comex'), 403);
        }

        return response()->json(['data' => $g->cambiar($nota, $d, $r->user())]);
    }

    public function revisiones(NotaVenta $nota): JsonResponse
    {
        return response()->json(['data' => NotaVentaRevision::query()->where('nota_id', $nota->id)->orderByDesc('numero')->paginate(25)]);
    }

    public function historial(NotaVenta $nota): JsonResponse
    {
        return response()->json(['data' => EventoComercial::query()->where('entidad', 'nota')->where('entidad_id', $nota->id)->orderByDesc('id')->paginate(50)]);
    }
}
