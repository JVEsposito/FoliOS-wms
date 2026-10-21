<?php

namespace App\Finanzas\Comex;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Proyección mínima de COMEX: conexión SELECT/READ ONLY, sin importar sus modelos. */
final class LectorComex
{
    public function bookings(?string $buscar): LengthAwarePaginator
    {
        return DB::connection(config('finanzas.conexion_comex'))->table('cx_bookings')->whereIn('estado', ['confirmado', 'cerrado'])->when($buscar, fn ($q) => $q->where('codigo', 'like', '%'.$buscar.'%'))->orderByDesc('created_at')->orderBy('id')->paginate(25, ['id', 'codigo', 'cliente', 'gestion', 'estado', 'version'])->through(function ($b) {
            $b->cliente = json_decode($b->cliente, true, flags: JSON_THROW_ON_ERROR);

            return $b;
        });
    }

    public function booking(string $id, ?string $contenedor = null): array
    {
        $c = DB::connection(config('finanzas.conexion_comex'));

        return $c->transaction(function () use ($c, $id, $contenedor) {
            $b = $c->table('cx_bookings')->where('id', $id)->first(['id', 'codigo', 'cliente_operacion_id', 'cliente', 'gestion', 'estado', 'datos', 'version']);
            if (! $b) {
                throw ValidationException::withMessages(['booking_id' => 'La reserva COMEX no está disponible.']);
            }
            $datos = json_decode($b->datos, true, flags: JSON_THROW_ON_ERROR);
            $b = [...(array) $b, 'cliente' => json_decode($b->cliente, true, flags: JSON_THROW_ON_ERROR), 'datos' => collect($datos)->only(['nave', 'viaje', 'fecha_salida', 'fecha_llegada', 'origen', 'destino', 'naviera', 'forwarder'])->all()];
            $contenedores = $c->table('cx_booking_contenedores')->where('booking_id', $id)->where('activo', true)->orderBy('referencia')->get(['id', 'referencia', 'numero', 'tipo', 'sello'])->map(fn ($r) => (array) $r)->all();
            if ($contenedor && ! collect($contenedores)->contains('id', $contenedor)) {
                throw ValidationException::withMessages(['contenedor_id' => 'El contenedor no pertenece a la reserva vigente.']);
            }

            return ['source_type' => 'comex.booking', 'source_id' => $id, 'booking' => $b, 'contenedores' => $contenedores];
        });
    }
}
