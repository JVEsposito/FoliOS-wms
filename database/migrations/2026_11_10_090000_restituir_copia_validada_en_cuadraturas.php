<?php

use App\Services\Validacion\ComparadorConciliacionValidacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Las cuadraturas cruzaban lo validado con la existencia de planta en cada lectura:
 * un repaletizaje o una salida posterior las dejaba con diferencias que no eran de
 * la validación. Se restituye, desde su propio historial, la copia de planta que
 * correspondía al pallet validado. El historial no se reescribe: se agrega un evento.
 */
return new class extends Migration
{
    private const FIJA = ['sin_diferencias', 'cuadrado'];

    private const COMPROBACIONES = ['captura', 'comprobacion', 'correccion_validacion'];

    public function up(): void
    {
        $comparador = new ComparadorConciliacionValidacion;
        DB::table('conciliaciones_validacion_origen')->orderBy('id')
            ->chunkById(200, function ($casos) use ($comparador): void {
                foreach ($casos as $caso) {
                    $this->restituir($caso, $comparador);
                }
            });
    }

    public function down(): void
    {
        // No se vuelve a una comparación contra la existencia posterior.
    }

    private function restituir(object $caso, ComparadorConciliacionValidacion $comparador): void
    {
        $eventos = DB::table('eventos_conciliacion_validacion')->where('conciliacion_id', $caso->id)
            ->whereIn('tipo', self::COMPROBACIONES)->orderBy('id')->get()
            ->map(fn (object $e): array => [...json_decode($e->datos, true), 'created_at' => $e->created_at])
            ->filter(fn (array $e): bool => ($e['despues']['numero_folio'] ?? null) === $caso->numero_folio)
            ->values();
        $datos = json_decode($caso->validacion_actual, true)['datos'] ?? [];
        $actual = ['estado' => $caso->estado, 'planta_actual' => $caso->planta_actual, 'comparacion' => $caso->comparacion,
            'lectura_id' => $caso->lectura_id, 'lectura_at' => $caso->lectura_at, 'tuvo_diferencias' => (bool) $caso->tuvo_diferencias,
            'cuadrado_at' => $caso->cuadrado_at];

        // 1. Primera copia que coincidió con lo validado: queda fija.
        $base = $eventos->first(fn (array $e): bool => in_array($e['despues']['estado'] ?? null, self::FIJA, true));
        if ($base) {
            $comparacion = $comparador->comparar($datos, $base['planta'] ?? null);
            if (collect($comparacion)->contains(fn (array $c): bool => $c['estado'] !== 'coincide')) {
                return; // Una corrección posterior de la validación la dejó distinta: no es este caso.
            }
            $estado = $base['despues']['estado'];
            $nuevo = ['estado' => $estado, 'planta' => $base['planta'], 'comparacion' => $comparacion, 'evento' => $base,
                'tuvo_diferencias' => $estado === 'cuadrado',
                'cuadrado_at' => $estado === 'cuadrado' ? ($caso->cuadrado_at ?? $base['created_at']) : null];
        } elseif ($caso->contexto !== 'vigente') {
            // 2. Nunca coincidió y el folio ya cambió: la última copia previa al cambio.
            $previa = $eventos->last(fn (array $e): bool => ($e['despues']['contexto'] ?? null) === 'vigente');
            $estado = $caso->estado;
            if (! $previa && in_array($estado, ['pendiente', 'reabierto'], true)) {
                $estado = 'por_revisar';
            }
            $nuevo = ['estado' => $estado, 'planta' => $previa['planta'] ?? null, 'evento' => $previa,
                'comparacion' => $comparador->comparar($datos, $previa['planta'] ?? null),
                'tuvo_diferencias' => $previa ? (bool) $caso->tuvo_diferencias : false,
                'cuadrado_at' => $estado === 'cuadrado' ? $caso->cuadrado_at : null];
        } else {
            return;
        }

        $lecturaAt = isset($nuevo['evento']['lectura_at']) ? Carbon::parse($nuevo['evento']['lectura_at'])->utc()->format('Y-m-d H:i:s') : null;
        $cambios = ['estado' => $nuevo['estado'], 'planta_actual' => $nuevo['planta'] === null ? null : json_encode($nuevo['planta'], JSON_THROW_ON_ERROR),
            'comparacion' => json_encode($nuevo['comparacion'], JSON_THROW_ON_ERROR), 'lectura_id' => $nuevo['evento']['lectura_id'] ?? null,
            'lectura_at' => $lecturaAt, 'tuvo_diferencias' => $nuevo['tuvo_diferencias'], 'cuadrado_at' => $nuevo['cuadrado_at']];
        $igual = $cambios['estado'] === $actual['estado'] && $cambios['tuvo_diferencias'] === $actual['tuvo_diferencias']
            && json_decode((string) $cambios['planta_actual'], true) == json_decode((string) $actual['planta_actual'], true)
            && json_decode($cambios['comparacion'], true) == json_decode((string) $actual['comparacion'], true);
        if ($igual) {
            return;
        }

        $respaldo = fn (string $estado, ?string $comparacion): array => ['numero_folio' => $caso->numero_folio, 'estado' => $estado,
            'contexto' => $caso->contexto, 'validacion' => json_decode($caso->validacion_actual, true),
            'comparacion' => json_decode((string) $comparacion, true)];
        DB::table('conciliaciones_validacion_origen')->where('id', $caso->id)
            ->update([...$cambios, 'version' => $caso->version + 1, 'comprobado_at' => now(), 'updated_at' => now()]);
        DB::table('eventos_conciliacion_validacion')->insert(['conciliacion_id' => $caso->id, 'tipo' => 'ajuste_regla',
            'comentario' => 'Se restituye la copia de planta del pallet validado. Las lecturas posteriores a un repaletizaje, '
                .'una salida o una coincidencia no se cruzan con la validación.',
            'datos' => json_encode(['antes' => $respaldo($actual['estado'], $actual['comparacion']),
                'despues' => $respaldo($cambios['estado'], $cambios['comparacion']), 'planta' => $nuevo['planta'],
                'lectura_id' => $cambios['lectura_id'], 'lectura_at' => $nuevo['evento']['lectura_at'] ?? null], JSON_THROW_ON_ERROR),
            'created_at' => now()]);
    }
};
