<?php

namespace App\Comex\Comercial;

use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Models\Booking;
use App\Comex\Models\BookingAsignacion;
use App\Comex\Models\BookingContenedor;
use App\Comex\Models\BookingDespacho;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\LugarComercial;
use App\Comex\Models\NotaVenta;
use App\Comex\Models\NotaVentaRevision;
use App\Comex\Models\ParteComercial;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;

final class Bookings
{
    public function __construct(private RegistroComercial $registro, private EmbarquesOperacion $operacion) {}

    public function guardar(array $d, User $actor, ?Booking $booking = null): array
    {
        return $this->registro->operar($d['operacion_id'], 'booking.guardar', ['id' => $booking?->id, ...$d], function () use ($d, $actor, $booking) {
            $b = $booking ? $this->bloquear($booking, $d['version'], ['borrador']) : null;
            if (! $b && $d['version'] !== 0) {
                $this->registro->error('version', 'Una reserva nueva parte de cero.');
            }
            foreach (['cutoff_fisico', 'cutoff_documental'] as $campo) {
                if (substr($d[$campo], 0, 10) > $d['fecha_salida']) {
                    $this->registro->error($campo, 'El cutoff no puede quedar después del día de salida.');
                }
            }
            $cliente = $this->operacion->cliente($d['cliente_operacion_id']);
            $gestion = $b?->gestion ?? ClienteComex::query()->find($d['cliente_operacion_id'])?->gestion ?? 'servicio';
            if (! $cliente || ! $cliente['activo'] || $gestion !== $d['gestion'] || $gestion === 'sin_seguimiento') {
                $this->registro->error('gestion', 'Revise el cliente activo y su gestión configurada.');
            }
            if ($b && ($b->codigo !== $d['codigo'] || $b->naviera_id !== $d['naviera_id'] || $b->cliente_operacion_id !== $cliente['id'] || $b->gestion !== $gestion)) {
                $this->registro->error('codigo', 'Código, naviera, cliente y gestión son permanentes.');
            }
            if (! $b && Booking::query()->where('naviera_id', $d['naviera_id'])->where('codigo', $d['codigo'])->exists()) {
                $this->registro->error('codigo', 'La naviera ya tiene ese booking.');
            }
            $datos = collect($d)->except(['operacion_id', 'version', 'codigo', 'cliente_operacion_id', 'gestion', 'contenedores', 'motivo'])->all();
            foreach (['naviera', 'forwarder'] as $rol) {
                $p = ParteComercial::query()->findOrFail($d[$rol.'_id']);
                if (! $p->activo || ! in_array($rol, $p->roles, true)) {
                    $this->registro->error($rol.'_id', 'Revise la función y el estado de '.$rol.'.');
                }
                $datos[$rol] = $p->toArray();
            }
            foreach (['origen', 'destino'] as $rol) {
                $p = LugarComercial::query()->findOrFail($d[$rol.'_id']);
                if (! $p->activo || $p->tipo !== 'puerto') {
                    $this->registro->error($rol.'_id', 'Seleccione un puerto activo.');
                }
                $datos[$rol] = $p->toArray();
            }
            if ($d['origen_id'] === $d['destino_id']) {
                $this->registro->error('destino_id', 'Origen y destino deben ser diferentes.');
            }
            $antes = $b?->toArray();
            $b ??= new Booking(['id' => (string) Str::uuid(), 'codigo' => $d['codigo'], 'naviera_id' => $d['naviera_id'], 'cliente_operacion_id' => $cliente['id'], 'cliente' => $cliente, 'gestion' => $gestion, 'estado' => 'borrador', 'version' => 0]);
            foreach (BookingAsignacion::query()->where('booking_id', $b->id)->where('activa', true)->get() as $a) {
                $acuerdo = $a->acuerdo['revision']['datos'];
                if ($d['fecha_salida'] < $acuerdo['fecha'] || $d['fecha_salida'] > $acuerdo['vigente_hasta']) {
                    $this->registro->error('fecha_salida', 'La nueva salida excede la vigencia de una nota asignada.');
                }
            }
            $b->datos = $datos;
            $b->version++;
            $b->save();
            $conservados = [];
            foreach ($d['contenedores'] as $c) {
                if (BookingContenedor::query()->where('booking_id', $b->id)->where('referencia', $c['referencia'])->when(isset($c['id']), fn ($q) => $q->where('id', '!=', $c['id']))->exists()) {
                    $this->registro->error('contenedores', 'Esa referencia de contenedor ya está registrada, incluso si fue retirada.');
                }
                $id = $c['id'] ?? (string) Str::uuid();
                $fila = isset($c['id']) ? BookingContenedor::query()->where('booking_id', $b->id)->where('activo', true)->findOrFail($id) : new BookingContenedor(['id' => $id, 'booking_id' => $b->id]);
                if ($fila->exists && $fila->referencia !== $c['referencia']) {
                    $this->registro->error('contenedores', 'La referencia interna del contenedor es permanente.');
                }
                $fila->fill(collect($c)->except('id')->all())->save();
                $conservados[] = $id;
            }
            $retirar = BookingContenedor::query()->where('booking_id', $b->id)->where('activo', true)->whereNotIn('id', $conservados)->pluck('id');
            if (BookingAsignacion::query()->whereIn('contenedor_id', $retirar)->where('activa', true)->exists()) {
                $this->registro->error('contenedores', 'Retire las asignaciones antes de quitar un contenedor.');
            }
            BookingContenedor::query()->whereIn('id', $retirar)->update(['activo' => false]);
            $this->registro->evento('booking', $b->id, 'borrador_guardado', ['antes' => $antes, 'despues' => $b->toArray(), 'contenedores' => $d['contenedores'], 'motivo' => $d['motivo'] ?? null], $actor);

            return $this->detalle($b);
        });
    }

    public function cambiar(Booking $booking, array $d, User $actor): array
    {
        return $this->registro->operar($d['operacion_id'], 'booking.'.$d['accion'], ['id' => $booking->id, ...$d], function () use ($booking, $d, $actor) {
            $b = $this->bloquear($booking, $d['version'], ['borrador', 'confirmado']);
            $antes = $b->toArray();
            $puentes = BookingDespacho::query()->where('booking_id', $b->id)->where('activo', true)->get();
            switch ($d['accion']) {
                case 'asignar':
                    $this->borrador($b);
                    if (BookingAsignacion::query()->where('booking_id', $b->id)->where('activa', true)->count() >= 500) {
                        $this->registro->error('nota_id', 'El booking alcanzó el máximo de 500 asignaciones activas.');
                    }
                    $c = BookingContenedor::query()->where('booking_id', $b->id)->where('activo', true)->findOrFail($d['contenedor_id']);
                    $n = NotaVenta::query()->lockForUpdate()->findOrFail($d['nota_id']);
                    if ($n->estado !== 'abierta' || ! $n->revision_activa_id || $n->cliente_operacion_id !== $b->cliente_operacion_id || $n->gestion !== $b->gestion) {
                        $this->registro->error('nota_id', 'Seleccione una nota aprobada, abierta, del mismo cliente y gestión.');
                    }
                    $rev = NotaVentaRevision::query()->findOrFail($n->revision_activa_id);
                    if ($rev->datos['modalidad'] !== 'maritimo' || $b->datos['fecha_salida'] < $rev->datos['fecha'] || $b->datos['fecha_salida'] > $rev->datos['vigente_hasta']) {
                        $this->registro->error('nota_id', 'La modalidad o la vigencia de la nota no admite esta reserva marítima.');
                    }
                    $linea = collect($rev->datos['lineas'])->firstWhere('id', $d['linea_id']);
                    if (! $linea) {
                        $this->registro->error('linea_id', 'La línea no pertenece al acuerdo aprobado.');
                    }
                    $cantidad = BigDecimal::of($d['cantidad']);
                    if (! $cantidad->isPositive() || $this->asignado($n->id, $linea['id'])->plus($cantidad)->isGreaterThan($linea['cantidad'])) {
                        $this->registro->error('cantidad', 'La cantidad excede el saldo comercial disponible o no es positiva.');
                    }
                    BookingAsignacion::query()->create(['id' => (string) Str::uuid(), 'booking_id' => $b->id, 'contenedor_id' => $c->id, 'nota_id' => $n->id, 'revision_id' => $rev->id, 'linea_id' => $linea['id'], 'cantidad' => (string) $cantidad, 'acuerdo' => ['nota' => $n->toArray(), 'revision' => $rev->toArray(), 'linea' => $linea, 'captured_at' => now()->toIso8601String()]]);
                    break;
                case 'retirar_asignacion':
                    $this->borrador($b);
                    BookingAsignacion::query()->where('booking_id', $b->id)->where('activa', true)->findOrFail($d['asignacion_id'])->update(['activa' => false]);
                    break;
                case 'confirmar':
                    $this->borrador($b);
                    if (! BookingAsignacion::query()->where('booking_id', $b->id)->where('activa', true)->exists()) {
                        $this->registro->error('estado', 'Asigne cantidades antes de confirmar.');
                    }
                    foreach (['naviera', 'forwarder', 'origen', 'destino'] as $key) {
                        $copia = $b->datos[$key];
                        $actual = in_array($key, ['origen', 'destino']) ? LugarComercial::query()->findOrFail($copia['id']) : ParteComercial::query()->findOrFail($copia['id']);
                        if (! $actual->activo || $actual->version !== $copia['version']) {
                            $this->registro->error('datos', 'Cambió un actor o puerto. Actualice el borrador antes de confirmar.');
                        }
                    }
                    $b->estado = 'confirmado';
                    break;
                case 'volver_borrador':
                    if ($puentes->isNotEmpty()) {
                        $this->registro->error('estado', 'Retire los vínculos operativos antes de modificar la reserva.');
                    }
                    $b->estado = 'borrador';
                    break;
                case 'vincular':
                    if ($b->estado !== 'confirmado') {
                        $this->registro->error('estado', 'Confirme el booking antes de vincular el despacho.');
                    }
                    $c = BookingContenedor::query()->where('booking_id', $b->id)->where('activo', true)->findOrFail($d['contenedor_id']);
                    $e = $this->operacion->uno($d['embarque_id']);
                    if (! $e || $e['cliente_id'] !== $b->cliente_operacion_id || $e['modalidad'] !== 'maritimo' || $e['estado'] === 'cancelado' || ! $c->numero || strtoupper(trim($e['contenedor'] ?? '')) !== $c->numero) {
                        $this->registro->error('embarque_id', 'Revise cliente, modalidad, estado y número de contenedor del embarque operativo.');
                    }
                    if (! BookingAsignacion::query()->where('contenedor_id', $c->id)->where('activa', true)->exists()) {
                        $this->registro->error('contenedor_id', 'Este contenedor no tiene asignaciones comerciales.');
                    }
                    if (BookingDespacho::query()->where('activo', true)->where(fn ($q) => $q->where('embarque_id', $e['id'])->orWhere('contenedor_id', $c->id))->exists()) {
                        $this->registro->error('embarque_id', 'El embarque o contenedor ya está vinculado.');
                    }
                    BookingDespacho::query()->create(['id' => (string) Str::uuid(), 'booking_id' => $b->id, 'contenedor_id' => $c->id, 'embarque_id' => $e['id'], 'embarque_vigente' => $e['id'], 'contenedor_vigente' => $c->id, 'evidencia' => $e, 'source_version' => $this->registro->hash($e), 'captured_at' => now()]);
                    break;
                case 'retirar_vinculo':
                    $p = $puentes->firstWhere('id', $d['vinculo_id']);
                    if (! $p) {
                        $this->registro->error('vinculo_id', 'El vínculo ya no está vigente.');
                    }
                    $actual = $this->operacion->uno($p->embarque_id);
                    if (! $actual || ($actual['carga']['salio'] ?? false)) {
                        $this->registro->error('vinculo_id', 'No se retira un despacho salido o no verificable. Requiere rectificación formal.');
                    }
                    $p->update(['activo' => false, 'embarque_vigente' => null, 'contenedor_vigente' => null]);
                    break;
                case 'anular':
                    if ($puentes->isNotEmpty()) {
                        $this->registro->error('estado', 'Retire los vínculos operativos antes de anular.');
                    }
                    BookingAsignacion::query()->where('booking_id', $b->id)->where('activa', true)->update(['activa' => false]);
                    $b->estado = 'anulado';
                    break;
                case 'cerrar':
                    if ($b->estado !== 'confirmado' || $puentes->isEmpty()) {
                        $this->registro->error('estado', 'Confirme y vincule los despachos antes de cerrar.');
                    }
                    $conAsignacion = BookingAsignacion::query()->where('booking_id', $b->id)->where('activa', true)->distinct()->pluck('contenedor_id');
                    if ($conAsignacion->diff($puentes->pluck('contenedor_id'))->isNotEmpty()) {
                        $this->registro->error('estado', 'Hay contenedores asignados sin despacho vinculado.');
                    }
                    $cierre = ['captured_at' => now()->toIso8601String(), 'despachos' => []];
                    foreach ($puentes as $p) {
                        $e = $this->operacion->uno($p->embarque_id);
                        $contenedor = BookingContenedor::query()->findOrFail($p->contenedor_id);
                        if ($e && ($e['cliente_id'] !== $b->cliente_operacion_id || $e['modalidad'] !== 'maritimo' || strtoupper(trim($e['contenedor'] ?? '')) !== $contenedor->numero)) {
                            $this->registro->error('estado', 'Cambió el cliente, modalidad o contenedor del despacho. Revise el vínculo antes de cerrar.');
                        }
                        if (! $e || ! ($e['carga']['salio'] ?? false) || $e['estado'] === 'cancelado') {
                            $this->registro->error('estado', 'Todos los despachos vinculados deben haber salido.');
                        }
                        $cierre['despachos'][] = ['vinculo_id' => $p->id, 'evidencia' => $e, 'source_version' => $this->registro->hash($e)];
                    }
                    $b->datos = [...$b->datos, 'cierre_despachos' => $cierre];
                    $b->estado = 'cerrado';
                    break;
            }
            $b->version++;
            $b->save();
            $this->registro->evento('booking', $b->id, $d['accion'], ['antes' => $antes, 'despues' => $b->toArray(), 'solicitud' => collect($d)->except('operacion_id')->all()], $actor);

            return $this->detalle($b);
        });
    }

    public function detalle(Booking $b): array
    {
        return ['booking' => $b->toArray(), 'contenedores' => BookingContenedor::query()->where('booking_id', $b->id)->where('activo', true)->orderBy('referencia')->get()->toArray(), 'asignaciones' => BookingAsignacion::query()->where('booking_id', $b->id)->orderBy('created_at')->get()->toArray(), 'vinculos' => BookingDespacho::query()->where('booking_id', $b->id)->get()->map(function ($p) use ($b) {
            $actual = $this->operacion->uno($p->embarque_id);

            return [...$p->toArray(), 'actual' => $actual, 'cambio_operacion' => $actual === null || $this->registro->hash($actual) !== $p->source_version, 'al_cierre' => collect($b->datos['cierre_despachos']['despachos'] ?? [])->firstWhere('vinculo_id', $p->id)];
        })->all()];
    }

    public function asignado(string $nota, string $linea): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach (BookingAsignacion::query()->where('nota_id', $nota)->where('linea_id', $linea)->where('activa', true)->pluck('cantidad') as $c) {
            $total = $total->plus($c);
        }

        return $total;
    }

    private function bloquear(Booking $b, int $version, array $estados): Booking
    {
        $b = Booking::query()->lockForUpdate()->findOrFail($b->id);
        if ($b->version !== $version || ! in_array($b->estado, $estados, true)) {
            $this->registro->error('version', 'La reserva cambió o ya no admite modificaciones. Recargue.');
        }

        return $b;
    }

    private function borrador(Booking $b): void
    {
        if ($b->estado !== 'borrador') {
            $this->registro->error('estado', 'Vuelva a borrador antes de cambiar la reserva o sus asignaciones.');
        }
    }
}
