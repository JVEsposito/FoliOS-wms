<?php

namespace App\Finanzas\Http;

use App\Finanzas\Cierre\CalendarioCierres;
use App\Finanzas\Models\AporteDiferencia;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Prefactura\LibroPrefactura;
use App\Finanzas\Prefactura\Montos;
use App\Finanzas\Tarifario\CatalogoServicios;
use Illuminate\Support\Collection;

/** Diferencias de un mes cerrado y decisiones, como las lee la oficina. */
final class PresentadorDiferencias
{
    /** Hechos que viajan por diferencia: una corrección de precio puede mover miles. */
    public const HECHOS_POR_DIFERENCIA = 50;

    public function __construct(
        private readonly CalendarioCierres $calendario,
        private readonly CatalogoServicios $servicios,
        private readonly LibroPrefactura $libro,
    ) {}

    /**
     * @param  array{diferencias: list<array<string, mixed>>, resumen: array<string, mixed>}  $resultado
     * @return array<string, mixed>
     */
    public function revision(Cierre $cierre, array $resultado): array
    {
        $diferencias = array_map(fn (array $diferencia): array => [
            ...$diferencia,
            'hechos' => array_slice($diferencia['hechos'], 0, self::HECHOS_POR_DIFERENCIA),
            'hechos_total' => count($diferencia['hechos']),
        ], $resultado['diferencias']);

        return [
            'cierre' => $this->libro->cierre($cierre),
            'primer_mes_abierto' => $this->calendario->primerMesAbierto(),
            'diferencias' => $this->explicar($cierre, $diferencias),
            'resumen' => $resultado['resumen'],
            'decisiones' => Diferencia::query()->where('mes', $cierre->mes)->orderByDesc('id')->get()
                ->map(fn (Diferencia $decision): array => $this->decision($decision))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function decision(Diferencia $decision): array
    {
        return [
            'id' => $decision->id,
            'mes' => $decision->mes,
            'cliente_id' => $decision->cliente_id,
            'cliente_nombre' => $decision->cliente_nombre,
            'tarifa_id' => $decision->tarifa_id,
            'servicio' => $decision->servicio,
            'servicio_nombre' => $this->servicios->nombreServicio($decision->servicio),
            'unidad' => $decision->unidad,
            'unidad_nombre' => $this->servicios->nombreUnidad($decision->servicio, $decision->unidad),
            'moneda' => $decision->moneda,
            'simbolo' => $decision->simbolo,
            'decimales' => $decision->decimales,
            'precio_anterior' => $decision->precio_anterior === null ? null : Montos::texto($decision->precio_anterior),
            'precio' => $decision->precio === null ? null : Montos::texto($decision->precio),
            'cantidad' => Montos::texto($decision->cantidad),
            'dias' => $decision->dias,
            'monto' => Montos::enMoneda($decision->monto, $decision->decimales),
            'decision' => $decision->decision,
            'motivo' => $decision->motivo,
            'mes_destino' => $decision->mes_destino,
            'decidida_at' => $decision->decidida_at->toIso8601String(),
            'decidida_por' => $decision->decidida_por_nombre,
            'anulada_at' => $decision->anulada_at?->toIso8601String(),
            'anulada_por' => $decision->anulada_por_nombre,
            'motivo_anulacion' => $decision->motivo_anulacion,
            'puede_deshacerse' => $this->puedeDeshacerse($decision),
        ];
    }

    private function puedeDeshacerse(Diferencia $decision): bool
    {
        if ($decision->anulada() || Cierre::query()->whereKey($decision->cierre_id)->value('estado') !== Cierre::ESTADO_CERRADO) {
            return false;
        }

        return ! $decision->aceptada() || $decision->mes_destino === null || $this->calendario->vigente($decision->mes_destino) === null;
    }

    /**
     * Por qué cambió cada hecho: si es una versión posterior al cierre (llegó o se corrigió en la
     * operación) o si el hecho es el mismo y lo que cambió fue el precio. De lo retirado, en qué
     * quedó: anulado, de otro cliente o cobrado con otro precio.
     *
     * @param  list<array<string, mixed>>  $diferencias
     * @return list<array<string, mixed>>
     */
    private function explicar(Cierre $cierre, array $diferencias): array
    {
        $ids = [];
        $retirados = [];
        foreach ($diferencias as $diferencia) {
            foreach ($diferencia['hechos'] as $hecho) {
                if ($hecho['tipo'] === AporteDiferencia::RETIRADO) {
                    $retirados[$hecho['fuente']][] = $hecho['origen_id'];
                } elseif ($hecho['hecho_id'] !== null) {
                    $ids[] = $hecho['hecho_id'];
                }
            }
        }

        $versiones = Hecho::query()->whereIn('id', array_unique($ids))->get(['id', 'motivo_version', 'capturado_at'])->keyBy('id');
        $actuales = $this->vigentes($retirados);

        return array_map(fn (array $diferencia): array => [
            ...$diferencia,
            'hechos' => array_map(function (array $hecho) use ($cierre, $diferencia, $versiones, $actuales): array {
                if ($hecho['tipo'] !== AporteDiferencia::RETIRADO) {
                    $version = $versiones[$hecho['hecho_id']] ?? null;

                    return [
                        ...$hecho,
                        'posterior_al_cierre' => (int) $hecho['hecho_id'] > $cierre->hechos_hasta_id,
                        'motivo_version' => $version?->motivo_version,
                        'capturado_at' => $version?->capturado_at?->toIso8601String(),
                        'situacion' => null,
                        'cliente_actual' => null,
                    ];
                }

                $actual = $actuales[$hecho['fuente'].'|'.$hecho['origen_id']] ?? null;

                return [
                    ...$hecho,
                    'posterior_al_cierre' => $actual !== null && $actual->id > $cierre->hechos_hasta_id,
                    'motivo_version' => $actual?->motivo_version,
                    'capturado_at' => $actual?->capturado_at?->toIso8601String(),
                    'situacion' => match (true) {
                        $actual === null => 'sin_version',
                        $actual->estado === Hecho::ESTADO_ANULADO => 'anulado',
                        $actual->cliente_id !== $diferencia['cliente_id'] => 'otro_cliente',
                        default => 'otro_precio',
                    },
                    'cliente_actual' => $actual?->cliente_id !== $diferencia['cliente_id'] ? $actual?->cliente_nombre : null,
                ];
            }, $diferencia['hechos']),
        ], $diferencias);
    }

    /**
     * Versión vigente de cada hecho retirado, por fuente y origen.
     *
     * @param  array<string, list<string>>  $porFuente
     * @return Collection<string, Hecho>
     */
    private function vigentes(array $porFuente): Collection
    {
        $hechos = collect();
        foreach ($porFuente as $fuente => $origenes) {
            foreach (array_chunk(array_values(array_unique($origenes)), 500) as $lote) {
                $hechos = $hechos->concat(Hecho::query()->vigentes()->where('fuente', $fuente)->whereIn('origen_id', $lote)
                    ->get(['id', 'fuente', 'origen_id', 'estado', 'motivo_version', 'cliente_id', 'cliente_nombre', 'capturado_at']));
            }
        }

        return $hechos->keyBy(fn (Hecho $hecho): string => $hecho->fuente.'|'.$hecho->origen_id);
    }
}
