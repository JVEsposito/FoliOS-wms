<?php

namespace App\Services\Prefrio\PuntoRocio;

use App\Enums\EtapaMedicionPuntoRocio;
use App\Exceptions\ConflictoOperacion;
use App\Models\MargenPuntoRocioEtapa;
use App\Models\PlanCadenaFrioEtapa;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plan de cadena de frío, del packing al contenedor. La regla es la misma en
 * cada etapa: el punto de rocío del aire que toca la fruta debe quedar bajo la
 * T° más baja que tendrá esa fruta en ese aire, menos el margen de la etapa.
 *
 * El sellado tiene una exigencia más: el aire que queda dentro de la bolsa viaja
 * con la fruta por el resto de la cadena, así que su rocío debe quedar bajo la
 * T° de fruta más baja de las etapas siguientes (menos el margen de cada una).
 */
class ServicioPlanCadenaFrio
{
    /** Distancia al punto de congelación desde la que el plan avisa. */
    private const AVISO_CONGELACION_C = 0.5;

    /** @return array{etapas: list<array<string, mixed>>, temperatura_congelacion_c: float} */
    public function plan(): array
    {
        return [
            'etapas' => $this->etapas()->all(),
            'temperatura_congelacion_c' => $this->congelacion(),
        ];
    }

    /** Rocío máximo que admite el plan para una etapa, o null si no está planificada. */
    public function rocioMaximo(EtapaMedicionPuntoRocio $etapa): ?float
    {
        return $this->etapas()->firstWhere('etapa', $etapa->value)['rocio_maximo_c'] ?? null;
    }

    /**
     * @param  list<array{etapa: string, temperatura_aire_c?: float|int|string|null, temperatura_fruta_c?: float|int|string|null, version_conocida?: int}>  $filas
     * @return array{etapas: list<array<string, mixed>>, temperatura_congelacion_c: float}
     */
    public function guardar(array $filas, User $usuario): array
    {
        DB::transaction(function () use ($filas, $usuario): void {
            foreach ($filas as $fila) {
                $etapa = EtapaMedicionPuntoRocio::from($fila['etapa']);
                $aire = $this->decimal($fila['temperatura_aire_c'] ?? null);
                $fruta = $this->decimal($fila['temperatura_fruta_c'] ?? null);
                $actual = PlanCadenaFrioEtapa::query()->lockForUpdate()->find($etapa->value);
                if (isset($fila['version_conocida']) && (int) $fila['version_conocida'] !== ($actual?->version ?? 0)) {
                    throw new ConflictoOperacion("El plan de {$etapa->etiqueta()} cambió mientras lo editabas. Recarga la página.");
                }
                if (! $actual) {
                    if ($aire !== null || $fruta !== null) {
                        PlanCadenaFrioEtapa::create([
                            'etapa' => $etapa->value,
                            'temperatura_aire_c' => $aire,
                            'temperatura_fruta_c' => $fruta,
                            'actualizado_por_user_id' => $usuario->id,
                        ]);
                    }

                    continue;
                }
                if ($actual->temperatura_aire_c === $aire && $actual->temperatura_fruta_c === $fruta) {
                    continue;
                }
                $actual->update([
                    'temperatura_aire_c' => $aire,
                    'temperatura_fruta_c' => $fruta,
                    'version' => $actual->version + 1,
                    'actualizado_por_user_id' => $usuario->id,
                ]);
            }
        }, attempts: 3);

        return $this->plan();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function etapas(): Collection
    {
        $filas = PlanCadenaFrioEtapa::query()->get()->keyBy(fn (PlanCadenaFrioEtapa $fila): string => $fila->etapa->value);
        $margenes = MargenPuntoRocioEtapa::query()->get()
            ->mapWithKeys(fn (MargenPuntoRocioEtapa $fila): array => [$fila->etapa->value => $fila->margen_minimo_c]);
        $margenPorDefecto = (float) config('prefrio.punto_rocio.margen_minimo_c', 2.0);
        $congelacion = $this->congelacion();

        $base = collect(EtapaMedicionPuntoRocio::cadena())->values()->map(function (EtapaMedicionPuntoRocio $etapa, int $orden) use ($filas, $margenes, $margenPorDefecto): array {
            $fila = $filas->get($etapa->value);
            $margen = $margenes->get($etapa->value, $margenPorDefecto);
            $fruta = $fila?->temperatura_fruta_c;

            return [
                'etapa' => $etapa,
                'orden' => $orden + 1,
                'aire' => $fila?->temperatura_aire_c,
                'fruta' => $fruta,
                'margen' => (float) $margen,
                'rocio_fruta' => $fruta === null ? null : round($fruta - $margen, 2),
                'version' => $fila?->version ?? 0,
            ];
        });

        return $base->map(function (array $fila, int $indice) use ($base, $congelacion): array {
            $envase = $fila['etapa'] === EtapaMedicionPuntoRocio::Sellado
                ? $base->slice($indice + 1)->pluck('rocio_fruta')->filter(fn (?float $valor): bool => $valor !== null)->min()
                : null;
            $limites = array_values(array_filter([$fila['rocio_fruta'], $envase], fn (?float $valor): bool => $valor !== null));
            $maximo = $limites === [] ? null : min($limites);
            $limitante = match (true) {
                $maximo === null => null,
                $envase !== null && $envase === $maximo && ($fila['rocio_fruta'] === null || $envase < $fila['rocio_fruta']) => 'envase',
                default => 'fruta',
            };
            $frias = array_filter([$fila['aire'], $fila['fruta']], fn (?float $valor): bool => $valor !== null);

            return [
                'etapa' => $fila['etapa']->value,
                'etiqueta' => $fila['etapa']->etiqueta(),
                'orden' => $fila['orden'],
                'planificada' => $fila['fruta'] !== null,
                'temperatura_aire_c' => $fila['aire'],
                'temperatura_fruta_c' => $fila['fruta'],
                'margen_minimo_c' => $fila['margen'],
                'rocio_fruta_c' => $fila['rocio_fruta'],
                'rocio_envase_c' => $envase,
                'rocio_maximo_c' => $maximo,
                'limitante' => $limitante,
                'humedad_maxima' => $maximo !== null && $fila['aire'] !== null
                    ? round(CalculadoraPuntoRocio::humedadParaRocio($fila['aire'], $maximo), 1)
                    : null,
                'cerca_congelacion' => $frias !== [] && min($frias) <= $congelacion + self::AVISO_CONGELACION_C,
                'version' => $fila['version'],
            ];
        })->values();
    }

    private function congelacion(): float
    {
        return (float) config('prefrio.punto_rocio.temperatura_congelacion_c', -1.7);
    }

    private function decimal(float|int|string|null $valor): ?float
    {
        return $valor === null || $valor === '' ? null : round((float) $valor, 1);
    }
}
