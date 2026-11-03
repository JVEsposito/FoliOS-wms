<?php

namespace Tests\Concerns;

use App\Enums\EstadoIntegracionFolio;
use App\Models\Folio;

/**
 * La tablet ya no crea folios: el pallet debe existir antes de su primera
 * ubicación. Para las pruebas de Cámaras se crea como lo dejaba la antigua alta
 * manual, disponible y sin condición térmica.
 */
trait CreaFolioParaUbicar
{
    /** @param  array<string, mixed>  $atributos */
    protected function folioParaUbicar(string $numero, string $tipoBulto = 'pallet', array $atributos = []): Folio
    {
        return Folio::query()->firstOrCreate(['numero_folio' => $numero], [
            'tipo_bulto' => $tipoBulto,
            'fecha_ingreso' => now(),
            'origen_sistema' => 'manual',
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
            ...$atributos,
        ]);
    }
}
