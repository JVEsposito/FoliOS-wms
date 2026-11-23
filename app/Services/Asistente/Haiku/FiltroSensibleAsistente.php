<?php

namespace App\Services\Asistente\Haiku;

use App\Models\User;
use App\Services\Asistente\TextoNormalizado;
use Illuminate\Support\Facades\Cache;

/**
 * Preguntas que no salen de la planta: RRHH, Finanzas y personas. Se responden solo con
 * las reglas locales y el usuario recibe el aviso.
 */
class FiltroSensibleAsistente
{
    private const PATRON = '/\b(sueldos?|remuneracion(es)?|liquidacion(es)?|finiquitos?|horas extras?|horas extra|vacaciones|licencias? medicas?|asistencia del personal|trabajador(es|as)?|personal|contratos?|despidos?|rut|conductor(es)?|chofer(es)?|facturas?|prefacturas?|pagos?|cobranzas?|deudas?|costos?|precios?|tarifas?|margen(es)?|presupuestos?|cuenta corriente bancaria|banco)\b/';

    public function esSensible(string $texto): bool
    {
        $normalizado = TextoNormalizado::de($texto);

        return (bool) preg_match(self::PATRON, $normalizado) || $this->nombraPersona($normalizado);
    }

    /** Un nombre y apellido de un usuario de FoliOS en la pregunta: la pregunta no sale. */
    private function nombraPersona(string $texto): bool
    {
        $nombres = Cache::remember('asistente:nombres-personas', 600, fn (): array => User::query()
            ->pluck('name')
            ->map(fn ($nombre): string => TextoNormalizado::de((string) $nombre))
            ->filter(fn (string $nombre): bool => substr_count($nombre, ' ') >= 1 && strlen($nombre) >= 7)
            ->values()->all());

        foreach ($nombres as $nombre) {
            if (str_contains($texto, $nombre)) {
                return true;
            }
        }

        return false;
    }
}
