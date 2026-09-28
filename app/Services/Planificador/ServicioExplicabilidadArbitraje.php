<?php

namespace App\Services\Planificador;

use App\Enums\DecisionArbitrajeManiobra;
use App\Models\Camara;
use App\Models\ManiobraOperacional;
use App\Models\Posicion;
use Illuminate\Support\Collection;

final class ServicioExplicabilidadArbitraje
{
    private const VERSION_CONTRATO = 1;

    /**
     * @param  array<string, mixed>  $componentes
     * @param  array<string, int>  $capacidad
     * @param  array<int, string>  $recursos
     * @param  array<int, array<string, string>>  $conflictos
     * @param  Collection<int, ManiobraOperacional>  $maniobras
     * @return array<string, mixed>
     */
    public function construir(
        string $reglas,
        ManiobraOperacional $maniobra,
        DecisionArbitrajeManiobra $decision,
        string $factorDecisivo,
        string $motivo,
        array $componentes,
        array $capacidad,
        array $recursos,
        array $conflictos,
        Collection $maniobras,
    ): array {
        $recursosExplicables = $this->recursosExplicables($maniobra, $recursos);
        $conflictosExplicables = $this->conflictosExplicables(
            $conflictos,
            $recursosExplicables,
            $maniobras,
        );

        return [
            'version' => self::VERSION_CONTRATO,
            'reglas' => $reglas,
            'decision' => $decision->value,
            'resumen' => $motivo,
            'factor_decisivo' => [
                'codigo' => $factorDecisivo,
                'etiqueta' => $this->etiquetaFactor($factorDecisivo),
            ],
            'formula' => 'prioridad × 1.000.000.000 + objetivo × 10.000.000 + beneficio neto',
            'componentes' => $componentes,
            'capacidad' => $capacidad,
            'restricciones' => array_values(array_merge([
                [
                    'codigo' => $factorDecisivo,
                    'etiqueta' => $this->etiquetaFactor($factorDecisivo),
                    'resultado' => 'determinante',
                    'detalle' => $motivo,
                ],
            ], array_map(
                fn (array $conflicto): array => [
                    'codigo' => 'conflicto_'.$conflicto['tipo'],
                    'etiqueta' => 'Conflicto de '.$conflicto['tipo'],
                    'resultado' => 'bloquea',
                    'detalle' => sprintf(
                        '%s está comprometido por %s.',
                        $conflicto['nombre'],
                        $conflicto['maniobra_titulo'],
                    ),
                ],
                $conflictosExplicables,
            ))),
            'recursos' => [
                'requeridos' => $recursosExplicables,
                'conflictos' => $conflictosExplicables,
            ],
            'snapshot' => $this->snapshotOperacional($maniobra),
        ];
    }

    /**
     * @param  array<int, string>  $recursos
     * @return array<int, array<string, string>>
     */
    private function recursosExplicables(
        ManiobraOperacional $maniobra,
        array $recursos,
    ): array {
        $catalogo = [];

        foreach ($maniobra->pasos as $paso) {
            if ($paso->folio_id) {
                $clave = "folio:{$paso->folio_id}";
                $catalogo[$clave] = [
                    'clave' => $clave,
                    'tipo' => 'folio',
                    'nombre' => $paso->folio?->numero_folio
                        ? "Pallet {$paso->folio->numero_folio}"
                        : 'Pallet sin folio visible',
                ];
            }
            if ($paso->posicion_origen_id) {
                $clave = "posicion:{$paso->posicion_origen_id}";
                $catalogo[$clave] = [
                    'clave' => $clave,
                    'tipo' => 'posicion',
                    'nombre' => $this->etiquetaUbicacion(
                        $paso->camaraOrigen,
                        $paso->posicionOrigen,
                    ),
                ];
            }
            if ($paso->posicion_destino_id) {
                $clave = "posicion:{$paso->posicion_destino_id}";
                $catalogo[$clave] = [
                    'clave' => $clave,
                    'tipo' => 'posicion',
                    'nombre' => $this->etiquetaUbicacion(
                        $paso->camaraDestino,
                        $paso->posicionDestino,
                    ),
                ];
            }
        }

        foreach ($maniobra->reservasBandas as $reserva) {
            $clave = implode(':', [
                'banda',
                $reserva->camara_id,
                $reserva->banda,
                $reserva->nivel,
            ]);
            $camara = $reserva->camara;
            $catalogo[$clave] = [
                'clave' => $clave,
                'tipo' => 'banda',
                'nombre' => sprintf(
                    '%s · Banda %d · Nivel %d',
                    $camara?->nombre ?: ($camara?->codigo ?: 'Cámara sin nombre'),
                    $reserva->banda,
                    $reserva->nivel,
                ),
            ];
        }

        return collect($recursos)
            ->map(fn (string $clave): array => $catalogo[$clave] ?? [
                'clave' => $clave,
                'tipo' => explode(':', $clave)[0],
                'nombre' => 'Recurso operacional',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $conflictos
     * @param  array<int, array<string, string>>  $recursos
     * @param  Collection<int, ManiobraOperacional>  $maniobras
     * @return array<int, array<string, string>>
     */
    private function conflictosExplicables(
        array $conflictos,
        array $recursos,
        Collection $maniobras,
    ): array {
        $recursosPorClave = collect($recursos)->keyBy('clave');
        $maniobrasPorId = $maniobras->keyBy('id');

        return collect($conflictos)
            ->map(function (array $conflicto) use ($recursosPorClave, $maniobrasPorId): array {
                $clave = $conflicto['recurso'];
                $recurso = $recursosPorClave->get($clave, [
                    'tipo' => explode(':', $clave)[0],
                    'nombre' => 'Recurso operacional',
                ]);
                $bloqueadora = $maniobrasPorId->get($conflicto['maniobra_id']);

                return [
                    'clave' => $clave,
                    'tipo' => $recurso['tipo'],
                    'nombre' => $recurso['nombre'],
                    'maniobra_id' => $conflicto['maniobra_id'],
                    'maniobra_titulo' => $bloqueadora?->titulo ?: 'otra maniobra prioritaria',
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function snapshotOperacional(ManiobraOperacional $maniobra): array
    {
        return [
            'maniobra' => [
                'id' => $maniobra->id,
                'version' => $maniobra->version,
                'titulo' => $maniobra->titulo,
                'estado' => $maniobra->estado->value,
                'prioridad' => $maniobra->prioridad->value,
                'creada_at' => $maniobra->created_at?->toIso8601String(),
            ],
            'objetivo' => $maniobra->planOperacional ? [
                'id' => $maniobra->planOperacional->id,
                'version' => $maniobra->planOperacional->version,
                'tipo' => $maniobra->planOperacional->tipo->value,
                'estado' => $maniobra->planOperacional->estado->value,
                'titulo' => $maniobra->planOperacional->titulo,
            ] : null,
            'objetivos' => $maniobra->objetivos
                ->sortBy('id')
                ->map(fn ($objetivo): array => [
                    'id' => $objetivo->id,
                    'tipo' => $objetivo->tipo->value,
                    'estado' => $objetivo->estado->value,
                    'prioridad' => $objetivo->prioridad->value,
                    'titulo' => $objetivo->titulo,
                    'beneficio_estimado' => (int) $objetivo->pivot->beneficio_estimado,
                ])
                ->values()
                ->all(),
            'pasos' => $maniobra->pasos
                ->sortBy('secuencia_maniobra')
                ->map(fn ($paso): array => [
                    'id' => $paso->id,
                    'version' => $paso->version,
                    'secuencia' => $paso->secuencia_maniobra,
                    'estado' => $paso->estado->value,
                    'tipo_movimiento' => $paso->tipo_movimiento->value,
                    'instruccion' => $paso->instruccion,
                    'folio' => $paso->folio ? [
                        'id' => $paso->folio->id,
                        'numero_folio' => $paso->folio->numero_folio,
                    ] : null,
                    'origen' => $this->snapshotUbicacion(
                        $paso->camaraOrigen,
                        $paso->posicionOrigen,
                    ),
                    'destino' => $this->snapshotUbicacion(
                        $paso->camaraDestino,
                        $paso->posicionDestino,
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function snapshotUbicacion(?Camara $camara, ?Posicion $posicion): ?array
    {
        if (! $camara && ! $posicion) {
            return null;
        }

        return [
            'camara_id' => $camara?->id,
            'camara_nombre' => $camara?->nombre ?: $camara?->codigo,
            'posicion_id' => $posicion?->id,
            'posicion_etiqueta' => $posicion?->etiqueta,
            'etiqueta' => $this->etiquetaUbicacion($camara, $posicion),
        ];
    }

    private function etiquetaUbicacion(?Camara $camara, ?Posicion $posicion): string
    {
        $partes = array_values(array_filter([
            $camara?->nombre ?: $camara?->codigo,
            $posicion?->etiqueta,
        ]));

        return $partes !== [] ? implode(' · ', $partes) : 'Ubicación no informada';
    }

    private function etiquetaFactor(string $factor): string
    {
        return match ($factor) {
            'fuera_planificador' => 'Flujo independiente',
            'pausa_supervision' => 'Pausa de supervisión',
            'pausa_discrepancia_sin_retiro' => 'Discrepancia antes del retiro',
            'realidad_fisica_iniciada' => 'Realidad física iniciada',
            'fuera_rollout' => 'Fuera del rollout dirigido',
            'objetivo_pausado' => 'Objetivo pausado',
            'conflicto_recursos' => 'Conflicto de recursos',
            'cupo_disponible' => 'Cupo de ejecución disponible',
            'alternativa_sin_reserva' => 'Alternativa sin reserva',
            default => 'Frontera operacional completa',
        };
    }
}
