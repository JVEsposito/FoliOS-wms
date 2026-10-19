<?php

namespace App\Finanzas\Cierre;

use App\Finanzas\Models\AporteDiferencia;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Periodo;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Decisiones sobre las diferencias de un mes cerrado, una por una. Aceptar lleva la diferencia
 * al primer mes abierto, donde se cobra o se abona; descartar exige un motivo. Antes de
 * registrar, se recalcula: si la diferencia cambió desde que se revisó, no se decide a ciegas.
 */
final class GestorDiferencias
{
    private const LOTE = 1000;

    public function __construct(
        private readonly CandadoCierres $candado,
        private readonly CalendarioCierres $calendario,
        private readonly DiferenciasCierre $diferencias,
    ) {}

    /**
     * @param  array{cliente_id: string, tarifa_id: int, moneda: string, decision: string, cantidad: string, dias: int, monto: string, motivo?: ?string}  $datos  la diferencia tal como se revisó
     *
     * @throws ValidationException
     */
    public function decidir(string $mes, array $datos, User $usuario): Diferencia
    {
        return $this->candado->ejecutar(function () use ($mes, $datos, $usuario): Diferencia {
            $cierre = $this->calendario->vigente($mes);
            if ($cierre === null) {
                throw ValidationException::withMessages(['mes' => 'El mes no está cerrado: sus diferencias no se revisan.']);
            }

            $clave = DiferenciasCierre::clave($datos['cliente_id'], (int) $datos['tarifa_id'], $datos['moneda']);
            $pendiente = collect($this->diferencias->calcular($cierre)['diferencias'])->firstWhere('clave', $clave);
            if ($pendiente === null) {
                throw ValidationException::withMessages(['diferencia' => 'Esa diferencia ya no está pendiente: alguien la decidió o la operación volvió a cuadrar.']);
            }
            if (! $this->coincide($pendiente['diferencia'], $datos)) {
                throw ValidationException::withMessages(['diferencia' => 'La diferencia cambió desde que la revisó. Revísela de nuevo antes de decidir.']);
            }

            $aceptada = $datos['decision'] === Diferencia::ACEPTADA;
            $diferencia = Diferencia::query()->create([
                'cierre_id' => $cierre->id,
                'mes' => $mes,
                'cliente_id' => $pendiente['cliente_id'],
                'cliente_nombre' => Str::limit($pendiente['cliente_nombre'], 180, ''),
                'tarifa_id' => $pendiente['tarifa_id'],
                'servicio' => $pendiente['servicio'],
                'unidad' => $pendiente['unidad'],
                'moneda' => $pendiente['moneda'],
                'simbolo' => $pendiente['simbolo'],
                'decimales' => $pendiente['decimales'],
                'precio_anterior' => $pendiente['antes']['precio'] ?? null,
                'precio' => $pendiente['ahora']['precio'] ?? null,
                'cantidad' => $pendiente['diferencia']['cantidad'],
                'dias' => $pendiente['diferencia']['dias'],
                'monto' => $pendiente['diferencia']['monto'],
                'decision' => $datos['decision'],
                'motivo' => ($datos['motivo'] ?? null) ?: null,
                'mes_destino' => $aceptada ? $this->calendario->primerMesAbierto() : null,
                'decidida_at' => now(),
                'decidida_por_user_id' => $usuario->id,
                'decidida_por_nombre' => $usuario->name,
            ]);

            $filas = array_map(fn (array $hecho): array => [
                'diferencia_id' => $diferencia->id,
                'fuente' => $hecho['fuente'],
                'origen_id' => $hecho['origen_id'],
                'hecho_id' => $hecho['hecho_id'],
                'referencia' => $hecho['referencia'],
                'tipo' => $hecho['tipo'],
                'desde' => $hecho['desde'],
                'hasta' => $hecho['hasta'],
                'dias' => $hecho['dias'],
                'cantidad' => $hecho['cantidad'],
            ], $pendiente['hechos']);
            foreach (array_chunk($filas, self::LOTE) as $lote) {
                AporteDiferencia::query()->insert($lote);
            }

            return $diferencia;
        });
    }

    /**
     * Deshace una decisión: la diferencia vuelve a quedar pendiente. Un ajuste que ya quedó en
     * el cierre de su mes de destino no se deshace sin reabrir ese mes.
     *
     * @throws ValidationException
     */
    public function anular(Diferencia $diferencia, string $motivo, User $usuario): Diferencia
    {
        return $this->candado->ejecutar(function () use ($diferencia, $motivo, $usuario): Diferencia {
            $diferencia = Diferencia::query()->lockForUpdate()->findOrFail($diferencia->id);
            if ($diferencia->anulada()) {
                throw ValidationException::withMessages(['diferencia' => 'Esa decisión ya estaba deshecha.']);
            }
            if (Cierre::query()->whereKey($diferencia->cierre_id)->value('estado') !== Cierre::ESTADO_CERRADO) {
                throw ValidationException::withMessages(['diferencia' => 'El mes de esa diferencia se reabrió: la decisión ya no rige.']);
            }
            if ($diferencia->aceptada() && $diferencia->mes_destino !== null && $this->calendario->vigente($diferencia->mes_destino) !== null) {
                throw ValidationException::withMessages([
                    'diferencia' => 'El ajuste ya quedó en el cierre de '.Periodo::mes($diferencia->mes_destino)->nombre().'. Para deshacerlo, reabra ese mes.',
                ]);
            }

            $diferencia->update([
                'anulada_at' => now(),
                'anulada_por_user_id' => $usuario->id,
                'anulada_por_nombre' => $usuario->name,
                'motivo_anulacion' => $motivo,
            ]);

            return $diferencia;
        });
    }

    /**
     * @param  array{cantidad: string, dias: int, monto: string}  $actual
     * @param  array<string, mixed>  $revisada
     */
    private function coincide(array $actual, array $revisada): bool
    {
        return BigDecimal::of($actual['cantidad'])->isEqualTo((string) $revisada['cantidad'])
            && $actual['dias'] === (int) $revisada['dias']
            && BigDecimal::of($actual['monto'])->isEqualTo((string) $revisada['monto']);
    }
}
