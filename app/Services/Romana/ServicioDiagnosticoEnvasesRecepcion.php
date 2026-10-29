<?php

namespace App\Services\Romana;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoMovimientoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ServicioDiagnosticoEnvasesRecepcion
{
    public function dependencias(RecepcionRomana $recepcion, Collection $detalles, Collection $movimientos, string $accion): string
    {
        $lineas = ["No se puede {$accion} {$recepcion->numero_recepcion}: hay operaciones vinculadas a sus envases."];
        foreach ($detalles as $detalle) {
            $lineas[] = '• '.$this->guia($detalle->guia).": {$detalle->cantidad} ".$this->envase($detalle)
                .'. '.($detalle->guia->estado === EstadoGuiaDespachoEnvase::Borrador
                    ? 'Reserva vigente; revisa esta guía en Envases → Guías de envases.'
                    : 'Despacho vigente; revisa esta guía en Envases → Guías de envases.');
        }
        if ($movimientos->isNotEmpty()) {
            $lineas[] = 'Movimientos vinculados que requieren conciliación (incluye reversas incompletas):';
            array_push($lineas, ...$this->movimientos($movimientos));
        }

        return implode("\n", $lineas);
    }

    public function disponibilidad(RecepcionRomana $recepcion, Collection $grupo, int $retirar, int $disponible, int $fisico): string
    {
        $primero = $grupo->first();
        $faltan = max($retirar - $disponible, $retirar - $fisico);
        $lineas = ["No se puede eliminar {$recepcion->numero_recepcion}: saldo insuficiente de ".$this->envase($primero).'.',
            "Se deben retirar {$retirar}; disponibles de sus orígenes: {$disponible}; existencia total de planta: {$fisico}. Faltan {$faltan}."];
        foreach ($grupo->whereNull('movimiento_origen_id') as $origen) {
            $lineas[] = "Ingreso guía {$origen->numero_documento}: {$origen->cantidad} unidades. Referencia del origen: {$origen->id}.";
        }

        $movimientos = MovimientoEnvase::where('temporada_id', $recepcion->temporada_id)
            ->where('tipo_envase', $primero->tipo_envase->value)->where('propiedad', $primero->propiedad->value)
            ->orderBy('ocurrido_at')->orderBy('id')->get();
        // Solo el saldo general permite conocer el efecto de salidas sin origen.
        // No atribuir esos consumos a esta recepción por compartir cliente o envase.
        $lineas[] = $fisico < $retirar
            ? 'Salidas que reducen el saldo general de planta; pueden pertenecer a otras recepciones:'
            : 'Consumos del origen o salidas históricas sin origen individual (los envases propios se asignan por antigüedad):';
        $salidas = $movimientos->where('signo_existencia', -1)->filter(fn ($m) => $fisico < $retirar
            || $m->movimiento_origen_id === null || $grupo->contains('id', $m->movimiento_origen_id));
        $pendientes = collect();
        $saldos = [];
        $devoluciones = $movimientos->where('signo_existencia', 1)
            ->groupBy(fn ($m) => $m->datos['reversa_de_movimiento_id'] ?? $m->movimiento_origen_id ?? '')
            ->map(fn (Collection $grupo) => $grupo->sum('cantidad'));
        foreach ($salidas as $salida) {
            $devuelto = $devoluciones->get($salida->id, 0);
            if ($salida->cantidad > $devuelto) {
                $pendientes->push($salida);
                $saldos[$salida->id] = $salida->cantidad - $devuelto;
            }
        }
        array_push($lineas, ...$this->movimientos($pendientes, $saldos));
        if ($pendientes->isEmpty()) {
            $lineas[] = 'No hay una salida individual identificable para esta diferencia. Revisa la conciliación de los ingresos y sus correcciones en Envases.';
        } elseif ($pendientes->contains(fn ($m) => $m->movimiento_origen_id === null)) {
            $lineas[] = 'Las salidas marcadas «sin origen individual» no permiten determinar qué recepción consumieron; requieren conciliación de Envases.';
        }

        return implode("\n", $lineas);
    }

    public function inventarioInconsistente(RecepcionRomana $recepcion, string $causa): string
    {
        $movimientos = MovimientoEnvase::where('temporada_id', $recepcion->temporada_id)
            ->whereNull('movimiento_origen_id')
            ->whereIn('tipo_movimiento', [TipoMovimientoEnvase::DespachoCliente->value, TipoMovimientoEnvase::ReversionDespacho->value])
            ->orderBy('ocurrido_at')->orderBy('id')->get();

        return implode("\n", ["No se puede eliminar {$recepcion->numero_recepcion}: {$causa}",
            'Operaciones históricas sin origen individual de la temporada; no pueden atribuirse a esta recepción:',
            ...$this->movimientos($movimientos)]);
    }

    private function movimientos(Collection $movimientos, array $saldos = []): array
    {
        if ($movimientos->isEmpty()) {
            return [];
        }
        $movimientos = new EloquentCollection($movimientos->all());
        $movimientos->loadMissing(['recepcion:id,numero_recepcion', 'cliente:id,nombre']);
        $guias = GuiaDespachoEnvase::whereIn('id', $movimientos->where('documento_tipo', 'guia_despacho_envases')->pluck('documento_id'))
            ->get()->keyBy('id');

        return $movimientos->map(function (MovimientoEnvase $movimiento) use ($guias, $saldos): string {
            $guia = $guias->get($movimiento->documento_id);
            $documento = $guia ? $this->guia($guia)
                : ($movimiento->recepcion ? 'Recepción '.$movimiento->recepcion->numero_recepcion.' · ' : '')
                    .'documento '.($movimiento->numero_documento ?: $movimiento->documento_id ?: $movimiento->id);
            $tipo = match ($movimiento->tipo_movimiento) {
                TipoMovimientoEnvase::DespachoCliente => 'Despacho a cliente',
                TipoMovimientoEnvase::ReversionDespacho => 'Reversa de despacho',
                TipoMovimientoEnvase::SalidaRecepcionFruta => 'Salida de envases de recepción',
                TipoMovimientoEnvase::ReversionSalidaRecepcionFruta => 'Reversa de salida de recepción',
                TipoMovimientoEnvase::AnulacionRecepcion => 'Anulación de recepción',
                TipoMovimientoEnvase::CorreccionCantidad => 'Corrección de cantidad',
                TipoMovimientoEnvase::CorreccionPropiedad => 'Corrección de propiedad',
                TipoMovimientoEnvase::CorreccionCuenta => 'Corrección de cuenta',
                default => ucfirst(str_replace('_', ' ', $movimiento->tipo_movimiento->value)),
            };
            $fecha = $movimiento->ocurrido_at?->timezone(config('app.operational_timezone', 'America/Santiago'))->format('d-m-Y H:i');
            $origen = $movimiento->movimiento_origen_id ? 'origen '.$movimiento->movimiento_origen_id : 'sin origen individual';

            return "• {$documento} · {$tipo}: {$movimiento->cantidad} ".$this->envase($movimiento)
                .(isset($saldos[$movimiento->id]) ? '; saldo sin compensar: '.$saldos[$movimiento->id] : '')
                .' · '.($movimiento->cliente?->nombre ?? 'Sin cliente').' · '.$fecha
                ." · {$origen}. Movimiento: {$movimiento->id}.";
        })->all();
    }

    private function guia(GuiaDespachoEnvase $guia): string
    {
        $estado = match ($guia->estado) {
            EstadoGuiaDespachoEnvase::Borrador => 'Borrador',
            EstadoGuiaDespachoEnvase::Confirmada => 'Confirmada',
            EstadoGuiaDespachoEnvase::Cancelada => 'Cancelada',
            EstadoGuiaDespachoEnvase::Anulada => 'Anulada',
        };

        return "Guía de envases {$guia->numero} ({$estado}"
            .($guia->numero_guia_despacho ? "; guía emitida {$guia->numero_guia_despacho}" : '')
            .') · '.$guia->cliente_nombre_snapshot;
    }

    private function envase(object $movimiento): string
    {
        $propiedad = match ($movimiento->propiedad) {
            PropiedadEnvase::Propia => 'propios', PropiedadEnvase::Cliente => 'del cliente',
            PropiedadEnvase::Arrendada => 'arrendados',
        };

        return $movimiento->tipo_envase->etiqueta()." ({$propiedad})";
    }
}
