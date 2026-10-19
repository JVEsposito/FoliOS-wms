<?php

namespace App\Finanzas\Cierre;

use App\Finanzas\Captura\ServicioCapturaHechos;
use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\AporteCierre;
use App\Finanzas\Models\Cierre;
use App\Finanzas\Models\Diferencia;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;
use App\Finanzas\Models\LineaCierre;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Prefactura\Montos;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cierra y reabre meses de la prefactura. Cerrar congela lo que se cobra: las líneas por
 * cliente y lo que aportó cada hecho, junto con quién cerró, cuándo y con qué lectura de la
 * operación. Reabrir (solo el último mes cerrado) deja sin efecto las decisiones sobre sus
 * diferencias: al volver a cerrarlo, el mes ya las incluye.
 */
final class GestorCierres
{
    private const LOTE = 1000;

    public function __construct(
        private readonly EsquemaFinanzas $esquema,
        private readonly CandadoCierres $candado,
        private readonly CalendarioCierres $calendario,
        private readonly CalculadoraPrefactura $calculadora,
        private readonly RevisionDiferencias $revision,
    ) {}

    /** @throws ValidationException */
    public function cerrar(string $mes, bool $confirmarPendientes, User $usuario): Cierre
    {
        return $this->candado->ejecutar(function () use ($mes, $confirmarPendientes, $usuario): Cierre {
            // Sin una lectura en curso, el último hecho registrado separa lo cerrado de lo posterior.
            if (! $this->esquema->tomarCandado(ServicioCapturaHechos::CANDADO)) {
                throw ValidationException::withMessages([
                    'mes' => 'Hay una lectura de la operación en curso. Espere a que termine y vuelva a cerrar el mes.',
                ]);
            }

            try {
                return $this->congelar($mes, $confirmarPendientes, $usuario);
            } finally {
                $this->esquema->liberarCandado(ServicioCapturaHechos::CANDADO);
            }
        });
    }

    /** @throws ValidationException */
    public function reabrir(string $mes, string $motivo, User $usuario): Cierre
    {
        return $this->candado->ejecutar(function () use ($mes, $motivo, $usuario): Cierre {
            if (($impedimento = $this->calendario->impedimentoReapertura($mes)) !== null) {
                throw ValidationException::withMessages(['mes' => $impedimento]);
            }

            $cierre = Cierre::query()->whereKey($this->calendario->vigente($mes)?->id)->lockForUpdate()->firstOrFail();
            $cierre->update([
                'estado' => Cierre::ESTADO_REABIERTO,
                'reabierto_at' => now(),
                'reabierto_por_user_id' => $usuario->id,
                'reabierto_por_nombre' => $usuario->name,
                'motivo_reapertura' => $motivo,
            ]);
            Diferencia::query()->where('cierre_id', $cierre->id)->whereNull('anulada_at')->update([
                'anulada_at' => now(),
                'anulada_por_user_id' => $usuario->id,
                'anulada_por_nombre' => $usuario->name,
                'motivo_anulacion' => Str::limit('Se reabrió '.Periodo::mes($mes)->nombre().': '.$motivo, 500, ''),
            ]);

            return $cierre;
        });
    }

    private function congelar(string $mes, bool $confirmarPendientes, User $usuario): Cierre
    {
        if (($impedimento = $this->calendario->impedimento($mes)) !== null) {
            throw ValidationException::withMessages(['mes' => $impedimento]);
        }

        $lectura = Lectura::query()->latest('iniciada_at')->latest('id')->first();
        $cierre = Cierre::query()->create([
            'mes' => $mes,
            'version' => (int) Cierre::query()->where('mes', $mes)->max('version') + 1,
            'estado' => Cierre::ESTADO_CERRADO,
            'cerrado_at' => now(),
            'cerrado_por_user_id' => $usuario->id,
            'cerrado_por_nombre' => $usuario->name,
            'hechos_hasta_id' => (int) Hecho::query()->max('id'),
            'lectura_id' => $lectura?->id,
            'lectura_at' => $lectura?->iniciada_at,
            'totales' => [],
            'resumen' => [],
            'pendientes' => [],
        ]);

        $lote = [];
        $prefactura = $this->calculadora->calcular(Periodo::mes($mes), alAportar: function (array $aporte) use ($cierre, &$lote): void {
            $lote[] = ['cierre_id' => $cierre->id, ...$aporte];
            if (count($lote) >= self::LOTE) {
                AporteCierre::query()->insert($lote);
                $lote = [];
            }
        });
        if ($lote !== []) {
            AporteCierre::query()->insert($lote);
        }

        $resumen = $prefactura['resumen'];
        if (! $confirmarPendientes && $resumen['hechos_sin_precio'] + $resumen['hechos_sin_cliente'] > 0) {
            // La excepción deshace todo lo escrito: el mes sigue abierto.
            throw ValidationException::withMessages(['confirmar_pendientes' => self::avisoPendientes($resumen)]);
        }

        $lineas = [];
        $pendientes = [];
        foreach ($prefactura['clientes'] as $cliente) {
            foreach ($cliente['lineas'] as $linea) {
                $lineas[] = [
                    'cierre_id' => $cierre->id,
                    'cliente_id' => $cliente['cliente_id'],
                    'cliente_nombre' => Str::limit($cliente['cliente_nombre'], 180, ''),
                    'tarifa_id' => $linea['tarifa_id'],
                    'servicio' => $linea['servicio'],
                    'unidad' => $linea['unidad'],
                    'origen' => $linea['origen'],
                    'precio' => $linea['precio'],
                    'moneda' => $linea['moneda'],
                    'simbolo' => $linea['simbolo'],
                    'decimales' => $linea['decimales'],
                    'vigente_desde' => $linea['vigente_desde'],
                    'vigente_hasta' => $linea['vigente_hasta'],
                    'cantidad' => $linea['cantidad'],
                    'dias' => $linea['dias'],
                    'hechos' => $linea['hechos'],
                    'monto' => $linea['monto'],
                ];
            }
            if ($cliente['sin_precio'] !== []) {
                $pendientes[] = ['cliente_id' => $cliente['cliente_id'], 'cliente_nombre' => $cliente['cliente_nombre'], 'servicios' => $cliente['sin_precio']];
            }
        }
        foreach (array_chunk($lineas, self::LOTE) as $parte) {
            LineaCierre::query()->insert($parte);
        }

        // Las diferencias de meses anteriores que se aceptaron para cobrarse en este mes.
        $ajustes = Diferencia::query()->where('mes_destino', $mes)->where('decision', Diferencia::ACEPTADA)->whereNull('anulada_at')->get();
        $cierre->update([
            'totales' => Montos::totales([
                ...$lineas,
                ...$ajustes->map(fn (Diferencia $ajuste): array => ['moneda' => $ajuste->moneda, 'simbolo' => $ajuste->simbolo, 'decimales' => $ajuste->decimales, 'monto' => $ajuste->monto])->all(),
            ]),
            'resumen' => [...$resumen, 'lineas' => count($lineas), 'ajustes' => $ajustes->count()],
            'pendientes' => ['sin_precio' => $pendientes, 'sin_cliente' => $prefactura['sin_cliente']],
        ]);
        $this->revision->inicial($cierre);

        return $cierre;
    }

    /** @param  array<string, int>  $resumen */
    public static function avisoPendientes(array $resumen): string
    {
        $partes = array_filter([
            $resumen['hechos_sin_precio'] > 0 ? self::cuenta($resumen['hechos_sin_precio'], 'hecho sin precio', 'hechos sin precio') : null,
            $resumen['hechos_sin_cliente'] > 0 ? self::cuenta($resumen['hechos_sin_cliente'], 'hecho sin cliente vinculado', 'hechos sin cliente vinculado') : null,
        ]);
        $total = $resumen['hechos_sin_precio'] + $resumen['hechos_sin_cliente'];

        return ($total === 1 ? 'Queda ' : 'Quedan ').implode(' y ', $partes).($total === 1 ? ': no se cobrará' : ': no se cobrarán').' en este cierre. Confirme para cerrar igual.';
    }

    private static function cuenta(int $cantidad, string $singular, string $plural): string
    {
        return number_format($cantidad, 0, ',', '.').' '.($cantidad === 1 ? $singular : $plural);
    }
}
