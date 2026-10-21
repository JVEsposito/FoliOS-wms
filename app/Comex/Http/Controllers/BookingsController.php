<?php

namespace App\Comex\Http\Controllers;

use App\Comex\Comercial\Bookings;
use App\Comex\Comercial\CantidadesComerciales;
use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Models\Booking;
use App\Comex\Models\EventoComercial;
use App\Comex\Models\NotaVenta;
use App\Comex\Models\NotaVentaRevision;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BookingsController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => Booking::query()->when($d['buscar'] ?? null, fn ($q, $s) => $q->where('codigo', 'like', '%'.$s.'%'))->orderByDesc('created_at')->orderBy('id')->paginate(25)]);
    }

    public function show(Booking $booking, Bookings $g): JsonResponse
    {
        return response()->json(['data' => $g->detalle($booking)]);
    }

    public function guardar(Request $r, Bookings $g, ?Booking $booking = null): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'codigo' => ['required', 'string', 'max:80'], 'cliente_operacion_id' => ['required', 'uuid'], 'gestion' => ['required', 'in:servicio,propia'], 'naviera_id' => ['required', 'uuid'], 'forwarder_id' => ['required', 'uuid'], 'origen_id' => ['required', 'uuid'], 'destino_id' => ['required', 'uuid'], 'nave' => ['required', 'string', 'max:120'], 'viaje' => ['required', 'string', 'max:80'], 'fecha_salida' => ['required', 'date_format:Y-m-d'], 'fecha_llegada' => ['required', 'date_format:Y-m-d', 'after_or_equal:fecha_salida'], 'cutoff_fisico' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:fecha_salida'], 'cutoff_documental' => ['required', 'date_format:Y-m-d\TH:i', 'before_or_equal:fecha_salida'], 'zona_horaria' => ['required', 'timezone'], 'respaldo' => ['required', 'string', 'min:10', 'max:1000'], 'motivo' => [$booking ? 'required' : 'nullable', 'string', 'min:10', 'max:1000'],
            'contenedores' => ['required', 'array', 'min:1', 'max:50'], 'contenedores.*' => ['array:id,referencia,numero,tipo,sello'], 'contenedores.*.id' => ['nullable', 'uuid', 'distinct'], 'contenedores.*.referencia' => ['required', 'string', 'max:80', 'distinct'], 'contenedores.*.numero' => ['nullable', 'string', 'regex:/^[A-Z]{3}U[0-9]{7}$/', 'distinct'], 'contenedores.*.tipo' => ['required', 'in:20GP,40GP,40HC,20RF,40RF,40RH'], 'contenedores.*.sello' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $g->guardar($d, $r->user(), $booking)], $booking ? 200 : 201);
    }

    public function transicion(Request $r, Booking $booking, Bookings $g): JsonResponse
    {
        $d = $r->validate(['operacion_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'], 'accion' => ['required', 'in:asignar,retirar_asignacion,confirmar,volver_borrador,vincular,retirar_vinculo,cerrar,anular'], 'motivo' => ['required', 'string', 'min:10', 'max:1000'], 'nota_id' => ['required_if:accion,asignar', 'uuid'], 'linea_id' => ['required_if:accion,asignar', 'uuid'], 'contenedor_id' => ['required_if:accion,asignar,vincular', 'uuid'], 'cantidad' => ['required_if:accion,asignar', 'string', 'regex:'.CantidadesComerciales::DECIMAL], 'asignacion_id' => ['required_if:accion,retirar_asignacion', 'uuid'], 'embarque_id' => ['required_if:accion,vincular', 'uuid'], 'vinculo_id' => ['required_if:accion,retirar_vinculo', 'uuid']]);
        if (in_array($d['accion'], ['confirmar', 'cerrar', 'anular'], true)) {
            abort_unless($r->user()->can('aprobar-comercial-comex'), 403);
        }

        return response()->json(['data' => $g->cambiar($booking, $d, $r->user())]);
    }

    public function notas(Request $r, Booking $booking, Bookings $g): JsonResponse
    {
        $d = $r->validate(['buscar' => ['nullable', 'string', 'max:100']]);
        $p = NotaVenta::query()->where('cliente_operacion_id', $booking->cliente_operacion_id)->where('gestion', $booking->gestion)->where('estado', 'abierta')->whereNotNull('revision_activa_id')->when($d['buscar'] ?? null, fn ($q, $s) => $q->where('folio', 'like', '%'.$s.'%'))->orderBy('folio')->paginate(25);
        $p->through(function ($n) use ($g) {
            $rev = NotaVentaRevision::query()->findOrFail($n->revision_activa_id);

            return ['id' => $n->id, 'folio' => $n->folio, 'revision' => $rev->numero, 'lineas' => array_map(fn ($l) => [...$l, 'asignado' => (string) $g->asignado($n->id, $l['id']), 'saldo' => (string) BigDecimal::of($l['cantidad'])->minus($g->asignado($n->id, $l['id']))], $rev->datos['lineas'])];
        });

        return response()->json(['data' => $p]);
    }

    public function embarques(Request $r, Booking $booking, EmbarquesOperacion $op): JsonResponse
    {
        $d = $r->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde']]);
        if (CarbonImmutable::parse($d['hasta'])->isAfter(CarbonImmutable::parse($d['desde'])->addDays(31))) {
            throw ValidationException::withMessages(['hasta' => 'Consulte un rango máximo de 31 días.']);
        }

        return response()->json(['data' => array_values(array_filter($op->entre(CarbonImmutable::parse($d['desde']), CarbonImmutable::parse($d['hasta']), $booking->cliente_operacion_id), fn ($e) => $e['modalidad'] === 'maritimo'))]);
    }

    public function historial(Booking $booking): JsonResponse
    {
        return response()->json(['data' => EventoComercial::query()->where('entidad', 'booking')->where('entidad_id', $booking->id)->orderByDesc('id')->paginate(50)]);
    }
}
