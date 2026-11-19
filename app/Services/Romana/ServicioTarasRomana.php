<?php

namespace App\Services\Romana;

use App\Models\RecepcionRomana;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ServicioTarasRomana
{
    /** Taras estándar: las esponjas son siempre variables según el camión. */
    private const FIJAS = ['bins', 'totes'];

    public function esFija(string $tipo): bool
    {
        return in_array($tipo, self::FIJAS, true);
    }

    /** @return array<string, float> */
    public function vigentes(): array
    {
        return DB::table('configuracion_taras_romana')
            ->pluck('tara_unitaria', 'tipo_envase')
            ->map(fn ($tara): float => (float) $tara)->all();
    }

    /** Tara congelada para la recepción; las recepciones antiguas conservan sus taras documentadas. */
    public function paraRecepcion(?RecepcionRomana $recepcion, string $tipo): ?float
    {
        if (! $this->esFija($tipo)) {
            return null;
        }

        $snapshot = $recepcion?->taras_romana_snapshot ?? [];
        if (array_key_exists($tipo, $snapshot)) {
            return (float) $snapshot[$tipo];
        }

        if ($recepcion !== null) {
            $anterior = $recepcion->detallesEnvases()
                ->where('tipo_envase', $tipo)->value('tara_unitaria_salida');
            if ($anterior !== null) {
                return (float) $anterior;
            }
        }

        $valor = $this->vigentes()[$tipo] ?? null;
        if ($valor === null) {
            throw ValidationException::withMessages([
                'taras_envases' => "La administración debe configurar la tara oficial de {$tipo}.",
            ]);
        }

        return $valor;
    }

    public function asegurarValor(string $tipo, mixed $valor, ?RecepcionRomana $recepcion = null, string $campo = 'tara_unitaria'): void
    {
        if (! $this->esFija($tipo)) {
            return;
        }

        $oficial = $this->paraRecepcion($recepcion, $tipo);
        if (! is_numeric($valor) || abs((float) $valor - $oficial) > 0.0005) {
            throw ValidationException::withMessages([
                $campo => "La tara oficial de {$tipo} es ".number_format($oficial, 3, ',', '.')." kg por unidad y solo administración puede configurarla.",
            ]);
        }
    }

    /** @return array{tipo_envase: string, tara_unitaria: float} */
    public function actualizar(string $tipo, float $valor, string $motivo, User $usuario): array
    {
        if (! $this->esFija($tipo)) {
            throw ValidationException::withMessages(['tipo_envase' => 'Este tipo no tiene una tara fija configurable.']);
        }

        return DB::transaction(function () use ($tipo, $valor, $motivo, $usuario): array {
            $registro = DB::table('configuracion_taras_romana')->where('tipo_envase', $tipo)->lockForUpdate()->first();
            if ($registro === null) {
                throw ValidationException::withMessages(['tipo_envase' => 'No existe configuración de tara para este envase.']);
            }
            $anterior = (float) $registro->tara_unitaria;
            if (abs($anterior - $valor) < 0.0005) {
                throw ValidationException::withMessages(['tara_unitaria' => 'La tara ingresada ya es la vigente.']);
            }

            DB::table('configuracion_taras_romana')->where('tipo_envase', $tipo)->update([
                'tara_unitaria' => $valor,
                'actualizado_por_user_id' => $usuario->id,
                'updated_at' => now(),
            ]);
            DB::table('historial_configuracion_taras_romana')->insert([
                'id' => (string) Str::uuid(),
                'tipo_envase' => $tipo,
                'tara_anterior' => $anterior,
                'tara_nueva' => $valor,
                'motivo' => $motivo,
                'modificado_por_user_id' => $usuario->id,
                'created_at' => now(),
            ]);

            return ['tipo_envase' => $tipo, 'tara_unitaria' => $valor];
        });
    }
}
