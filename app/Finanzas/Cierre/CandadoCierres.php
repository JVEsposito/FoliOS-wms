<?php

namespace App\Finanzas\Cierre;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\SinTelescope;
use Illuminate\Validation\ValidationException;

/**
 * Cierres, reaperturas y decisiones sobre diferencias ocurren de a uno: aceptar una diferencia
 * mientras se cierra el mes de destino la dejaría fuera de ese cierre.
 */
final class CandadoCierres
{
    private const NOMBRE = 'cierres-prefactura';

    private const ESPERA_SEGUNDOS = 15;

    public function __construct(private readonly EsquemaFinanzas $esquema) {}

    /**
     * @template T
     *
     * @param  callable(): T  $operacion
     * @return T
     */
    public function ejecutar(callable $operacion): mixed
    {
        if (! $this->esquema->tomarCandado(self::NOMBRE, self::ESPERA_SEGUNDOS)) {
            throw ValidationException::withMessages([
                'mes' => 'Otra persona está cerrando la prefactura o revisando sus diferencias. Intente de nuevo en unos segundos.',
            ]);
        }

        try {
            return SinTelescope::ejecutar(fn (): mixed => $this->esquema->transaccion($operacion));
        } finally {
            $this->esquema->liberarCandado(self::NOMBRE);
        }
    }
}
