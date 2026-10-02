<?php

namespace App\Services\Romana;

use App\Enums\TipoEnvaseRomana;
use App\Models\EspecieValidacion;
use App\Models\RecepcionRomana;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioRepartoEnvases
{
    public function precargarCereza(EspecieValidacion $especie): void
    {
        if (! in_array(mb_strtolower(trim($especie->nombre)), ['cereza', 'cerezas'], true)) {
            return;
        }
        foreach (['bins' => 210, 'totes' => 8.75] as $tipo => $peso) {
            DB::table('pesos_referencia_envases')->insertOrIgnore(['especie_validacion_id' => $especie->id, 'tipo_envase' => $tipo,
                'peso_referencia' => $peso, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('preferencias_envase_especie')->insertOrIgnore(['especie_validacion_id' => $especie->id,
            'tipo_envase' => 'totes', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function configuracion(?string $especieId): array
    {
        return ['sugerido' => DB::table('preferencias_envase_especie')->where('especie_validacion_id', $especieId)->value('tipo_envase'),
            'referencias' => DB::table('pesos_referencia_envases')->where('especie_validacion_id', $especieId)
                ->pluck('peso_referencia', 'tipo_envase')->map(fn ($peso): float => (float) $peso)->all()];
    }

    public function tipos(RecepcionRomana $recepcion): array
    {
        if ($recepcion->reparto_neto_envases) {
            return array_column($recepcion->reparto_neto_envases, 'tipo_envase');
        }
        if ($recepcion->tipo_envase_calculo_neto) {
            return [$recepcion->tipo_envase_calculo_neto];
        }
        $fruta = $recepcion->detallesEnvases->filter(fn ($detalle): bool => $detalle->tipo_envase->contieneFruta() && ($detalle->cantidad_validada ?? $detalle->cantidad_declarada) > 0);
        $sugerido = $this->configuracion($recepcion->especie_validacion_id)['sugerido'];
        if ($sugerido && $fruta->contains(fn ($detalle): bool => $detalle->tipo_envase->value === $sugerido)) {
            return [$sugerido];
        }
        $principal = $recepcion->tipo_envase_declarado?->value;

        return [$fruta->first(fn ($detalle): bool => $detalle->tipo_envase->value === $principal)?->tipo_envase->value ?? $fruta->first()?->tipo_envase->value];
    }

    public function preparar(RecepcionRomana $recepcion, float $neto, ?array $seleccion): array
    {
        $seleccion ??= $this->tipos($recepcion);
        $cantidades = $recepcion->detallesEnvases->mapWithKeys(fn ($d): array => [$d->tipo_envase->value => (int) $d->cantidad_validada])->all();
        $referencias = $this->configuracion($recepcion->especie_validacion_id)['referencias'];
        $reparto = $this->calcular($neto, $cantidades, $seleccion, $referencias);
        // MP ocurre antes del destare: la selección final debe servir para todos sus segmentos.
        foreach ($recepcion->validacionesMp()->with('segmentos.envases')->get()->flatMap->segmentos as $segmento) {
            if (! $segmento->envases->contains(fn ($e): bool => in_array($e->tipo_envase->value, $seleccion, true) && $e->cantidad > 0)) {
                throw ValidationException::withMessages(['envases_reparto' => "El segmento {$segmento->secuencia} no incluye ninguno de los envases seleccionados para repartir el neto."]);
            }
        }

        return $reparto;
    }

    /** Función pura. Las referencias se congelan al cerrar; no vuelven a leerse para lotizar. */
    public function calcular(float $neto, array $cantidades, array $seleccion, array $referencias): array
    {
        if (! $seleccion || count($seleccion) !== count(array_unique($seleccion))) {
            throw ValidationException::withMessages(['envases_reparto' => 'Selecciona al menos un envase de reparto, sin repetir.']);
        }
        $base = 0;
        foreach ($seleccion as $tipo) {
            if (! TipoEnvaseRomana::tryFrom($tipo)?->contieneFruta() || ($cantidades[$tipo] ?? 0) < 1) {
                throw ValidationException::withMessages(['envases_reparto' => 'Solo se puede repartir por envases con fruta y cantidad validada positiva.']);
            }
            if (count($seleccion) > 1 && ($referencias[$tipo] ?? 0) <= 0) {
                $nombre = TipoEnvaseRomana::from($tipo)->etiqueta();
                throw ValidationException::withMessages(['envases_reparto' => "Falta configurar el peso de referencia de {$nombre} para esta especie en Administración."]);
            }
            $base += $cantidades[$tipo] * (count($seleccion) === 1 ? 1000 : (int) round($referencias[$tipo] * 1000));
        }

        return array_map(fn ($tipo): array => ['tipo_envase' => $tipo, 'cantidad' => $cantidades[$tipo],
            'peso_referencia' => count($seleccion) === 1 ? null : (float) $referencias[$tipo],
            'peso_neto_unitario' => $neto * (count($seleccion) === 1 ? 1000 : (int) round($referencias[$tipo] * 1000)) / $base], $seleccion);
    }

    public function snapshot(RecepcionRomana $recepcion): array
    {
        return $recepcion->reparto_neto_envases ?: [['tipo_envase' => $recepcion->tipo_envase_calculo_neto,
            'cantidad' => (int) $recepcion->cantidad_envase_calculo_neto, 'peso_referencia' => null,
            'peso_neto_unitario' => (float) $recepcion->peso_neto_por_envase]];
    }

    public function miligramos(array $reparto, array $cantidades, float $neto): int
    {
        if (count($reparto) === 1) {
            return (int) round($reparto[0]['peso_neto_unitario'] * 1000) * ($cantidades[$reparto[0]['tipo_envase']] ?? 0);
        }
        $base = $peso = 0;
        foreach ($reparto as $fila) {
            $referencia = (int) round($fila['peso_referencia'] * 1000);
            $base += $fila['cantidad'] * $referencia;
            $peso += ($cantidades[$fila['tipo_envase']] ?? 0) * $referencia;
        }

        return (int) round(round($neto * 1000) * $peso / $base);
    }
}
