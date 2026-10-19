<?php

namespace App\Finanzas\Cierre;

use App\Finanzas\ConsultaHechos;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\EventoTarifa;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\SinTelescope;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;

/**
 * Cuántas diferencias tiene cada mes cerrado, sin recalcularlo si nada cambió desde la última
 * revisión: ni hechos de ese mes, ni precios, ni monedas, ni decisiones. La revisión se guarda
 * en el propio cierre.
 *
 * Los hechos solo los registra la captura, una lectura a la vez y en orden, así que el mayor
 * identificador visible es un corte limpio: lo que llegue después tendrá uno mayor.
 */
final class RevisionDiferencias
{
    public function __construct(
        private readonly EsquemaFinanzas $esquema,
        private readonly ConsultaHechos $consulta,
        private readonly DiferenciasCierre $diferencias,
    ) {}

    /**
     * Recalcula las diferencias del mes cerrado y deja la revisión al día.
     *
     * @return array{diferencias: list<array<string, mixed>>, resumen: array{diferencias: int, montos: list<array<string, mixed>>}}
     */
    public function calcular(Cierre $cierre): array
    {
        // Una sola foto de la base: un hecho que se corrige a mitad del cálculo no cuenta doble.
        return SinTelescope::ejecutar(fn (): array => $this->esquema->transaccion(function () use ($cierre): array {
            $huella = $this->huella($cierre, (int) Hecho::query()->max('id'));
            $resultado = $this->diferencias->calcular($cierre);
            $this->registrar($cierre, $huella, $resultado['resumen']);

            return $resultado;
        }));
    }

    /**
     * Resumen de diferencias pendientes del mes cerrado; reutiliza la última revisión si sigue
     * valiendo.
     *
     * @return array{diferencias: int, montos: list<array<string, mixed>>}
     */
    public function resumen(Cierre $cierre): array
    {
        $huella = $this->huella($cierre, (int) Hecho::query()->max('id'));
        $revision = $cierre->revision;
        if (is_array($revision) && $this->sigueValiendo($cierre, $revision, $huella)) {
            if ($huella['hechos_id'] > (int) $revision['hechos_id']) {
                $this->registrar($cierre, $huella, $revision['resumen']);
            }

            return $revision['resumen'];
        }

        return $this->calcular($cierre)['resumen'];
    }

    /** Sin diferencias: así queda la revisión al cerrar el mes. */
    public function inicial(Cierre $cierre): void
    {
        $this->registrar($cierre, $this->huella($cierre, $cierre->hechos_hasta_id), ['diferencias' => 0, 'montos' => []]);
    }

    /**
     * @param  array<string, mixed>  $huella
     * @param  array{diferencias: int, montos: list<array<string, mixed>>}  $resumen
     */
    private function registrar(Cierre $cierre, array $huella, array $resumen): void
    {
        $revision = [...$huella, 'resumen' => $resumen, 'revisada_at' => now()->toIso8601String()];
        Cierre::query()->whereKey($cierre->id)->update(['revision' => json_encode($revision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $cierre->setAttribute('revision', $revision);
    }

    /** @return array{hechos_id: int, eventos_id: int, monedas_at: ?string, decisiones: string} */
    private function huella(Cierre $cierre, int $hechosHasta): array
    {
        $decisiones = Diferencia::query()->where('cierre_id', $cierre->id);

        return [
            'hechos_id' => $hechosHasta,
            'eventos_id' => (int) EventoTarifa::query()->max('id'),
            'monedas_at' => ($monedas = Moneda::query()->max('updated_at')) === null ? null : (string) $monedas,
            'decisiones' => (clone $decisiones)->count().'|'.(int) (clone $decisiones)->max('id').'|'.(string) (clone $decisiones)->max('updated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $revision
     * @param  array<string, mixed>  $huella
     */
    private function sigueValiendo(Cierre $cierre, array $revision, array $huella): bool
    {
        return isset($revision['hechos_id'], $revision['resumen'])
            && ($revision['eventos_id'] ?? null) === $huella['eventos_id']
            && ($revision['monedas_at'] ?? null) === $huella['monedas_at']
            && ($revision['decisiones'] ?? null) === $huella['decisiones']
            && ! $this->cambiaronHechos($cierre, (int) $revision['hechos_id']);
    }

    /**
     * ¿Llegó, después de $desde, alguna versión de un hecho que cae en el mes, o que antes caía
     * en él (una corrección pudo moverlo a otro mes)?
     */
    private function cambiaronHechos(Cierre $cierre, int $desde): bool
    {
        $periodo = Periodo::mes($cierre->mes);

        return Hecho::query()->from('fin_hechos as h')
            ->where('h.id', '>', $desde)
            ->where(function (QueryBuilder $consulta) use ($periodo): void {
                $this->consulta->cruzaPeriodo($consulta, $periodo, 'h');
                $consulta->orWhereExists(function (QueryBuilder $anterior) use ($periodo): void {
                    $anterior->selectRaw('1')->from('fin_hechos as p')
                        ->whereColumn('p.fuente', 'h.fuente')
                        ->whereColumn('p.origen_id', 'h.origen_id')
                        ->whereColumn('p.version', '<', 'h.version');
                    $this->consulta->cruzaPeriodo($anterior, $periodo, 'p');
                });
            })
            ->exists();
    }
}
