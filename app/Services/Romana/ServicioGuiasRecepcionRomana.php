<?php

namespace App\Services\Romana;

use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\GuiaRecepcionRomana;
use App\Models\RecepcionRomana;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ServicioGuiasRecepcionRomana
{
    public static function resumen(array $guias): string
    {
        $numeros = array_column($guias, 'numero_guia_despacho');
        $texto = implode(' / ', $numeros);

        return mb_strlen($texto) <= 80 ? $texto : mb_substr($numeros[0], 0, 64).' (+'.(count($numeros) - 1).' guías)';
    }

    /** Conserva el hash de solicitudes anteriores que no incluían guías. */
    public function preparar(array $datos): ?array
    {
        if (! array_key_exists('guias', $datos)) {
            return null;
        }
        $guias = array_map(fn ($g) => ['numero_guia_despacho' => mb_strtoupper(trim($g['numero_guia_despacho'])),
            'envases' => collect($g['envases'])->map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'cantidad' => (int) $e['cantidad']])->sortBy('tipo_envase')->values()->all()], $datos['guias']);
        if (count(array_unique(array_column($guias, 'numero_guia_despacho'))) !== count($guias)) {
            throw ValidationException::withMessages(['guias' => 'No repitas una guía dentro del mismo camión.']);
        }
        if (count($guias) > 1 && $datos['tipo_recepcion'] === TipoRecepcionRomana::SoloEnvases->value) {
            throw ValidationException::withMessages(['guias' => 'El desglose de varias guías corresponde a recepciones con fruta.']);
        }
        $sumas = [];
        foreach ($guias as $i => $guia) {
            $tipos = array_column($guia['envases'], 'tipo_envase');
            if (count($tipos) !== count(array_unique($tipos))) {
                throw ValidationException::withMessages(["guias.{$i}.envases" => 'No repitas tipos de envase en una guía.']);
            }
            foreach ($guia['envases'] as $envase) {
                $sumas[$envase['tipo_envase']] = ($sumas[$envase['tipo_envase']] ?? 0) + $envase['cantidad'];
            }
        }
        $totales = array_map('intval', array_column($datos['envases'], 'cantidad', 'tipo_envase'));
        ksort($sumas);
        ksort($totales);
        if ($sumas !== $totales) {
            throw ValidationException::withMessages(['guias' => 'Los envases de las guías deben sumar exactamente los totales del camión por tipo.']);
        }

        return $guias;
    }

    /** Dentro de la transacción y bloqueo de la recepción; la clave única cubre altas concurrentes. */
    public function guardar(RecepcionRomana $recepcion, array $payload): void
    {
        $actuales = $recepcion->guiasRecepcion()->lockForUpdate()->get();
        if (! isset($payload['guias']) && $actuales->count() > 1) {
            throw ValidationException::withMessages(['guias' => 'Esta recepción tiene varias guías. Usa el desglose para conservar todos sus documentos.']);
        }
        $guias = $payload['guias'] ?? [['numero_guia_despacho' => $payload['numero_guia_despacho'],
            'envases' => array_map(fn ($e) => ['tipo_envase' => $e['tipo_envase'], 'cantidad' => $e['cantidad']], $payload['envases'])]];
        $recepcion->guiasRecepcion()->update(['vigente' => null]);
        $ordenadas = collect($guias)->map(fn ($g, $i) => [...$g, 'secuencia' => $i + 1])->sortBy('numero_guia_despacho');
        foreach ($ordenadas as $guia) {
            if (GuiaRecepcionRomana::query()->where('temporada_id', $recepcion->temporada_id)->where('cliente_id', $recepcion->cliente_id)
                ->where('numero_guia_despacho', $guia['numero_guia_despacho'])->where('vigente', 1)->where('recepcion_romana_id', '!=', $recepcion->id)->exists()) {
                throw new ConflictoOperacion("La guía {$guia['numero_guia_despacho']} ya está registrada para este cliente en otra recepción.");
            }
            try {
                GuiaRecepcionRomana::query()->updateOrCreate(['recepcion_romana_id' => $recepcion->id, 'temporada_id' => $recepcion->temporada_id,
                    'cliente_id' => $recepcion->cliente_id, 'numero_guia_despacho' => $guia['numero_guia_despacho']],
                    ['secuencia' => $guia['secuencia'], 'envases' => $guia['envases'], 'envases_validados' => null, 'vigente' => 1]);
            } catch (UniqueConstraintViolationException $error) {
                if (! str_contains($error->getMessage(), 'guia_romana_documento_vigente_unique')) {
                    throw $error;
                }
                throw new ConflictoOperacion("La guía {$guia['numero_guia_despacho']} ya fue registrada por otra operación. Actualiza el expediente.");
            }
        }
        $recepcion->unsetRelation('guiasRecepcion');
    }

    public function datos(RecepcionRomana $recepcion): array
    {
        return $recepcion->guiasRecepcion->map(fn ($g) => ['id' => $g->id, 'numero_guia_despacho' => $g->numero_guia_despacho,
            'envases' => $g->envases, 'envases_validados' => $g->envases_validados])->values()->all();
    }

    public function distribuirSegmentos(RecepcionRomana $recepcion, array $segmentos, Collection $cantidades): array
    {
        $guias = $recepcion->guiasRecepcion()->lockForUpdate()->get()->keyBy('id');
        if ($guias->count() > 1 && count($segmentos) < 2) {
            throw ValidationException::withMessages(['segmentos' => 'Desglosa la validación por las guías registradas en Romana.']);
        }
        $sumas = [];
        foreach ($segmentos as $i => &$segmento) {
            $id = $segmento['guia_recepcion_romana_id'] ?? ($guias->count() === 1 ? $guias->first()->id : null);
            if (($guias->isNotEmpty() && ! $guias->has($id)) || ($guias->isEmpty() && $id !== null)) {
                throw ValidationException::withMessages(["segmentos.{$i}.guia_recepcion_romana_id" => 'Selecciona una guía de esta recepción.']);
            }
            $segmento['guia_recepcion_romana_id'] = $id;
            foreach ($segmento['envases'] as $envase) {
                // Lo validado de la guía incluye los envases que llegaron vacíos con ella.
                $sumas[$id][$envase['tipo_envase']] = ($sumas[$id][$envase['tipo_envase']] ?? 0)
                    + (int) $envase['cantidad'] + (int) ($envase['cantidad_vacia'] ?? 0);
            }
        }
        unset($segmento);
        foreach ($guias as $id => $guia) {
            if ($guias->count() > 1 && ! isset($sumas[$id])) {
                throw ValidationException::withMessages(['segmentos' => "La guía {$guia->numero_guia_despacho} no tiene un segmento. Completa su desglose."]);
            }
            $validados = $segmentos ? $sumas[$id] ?? [] : $cantidades->mapWithKeys(fn ($e, $tipo) => [$tipo => (int) $e['cantidad_validada']])->all();
            $guia->update(['envases_validados' => collect($validados)->map(fn ($n, $tipo) => ['tipo_envase' => $tipo, 'cantidad' => $n])->values()->all()]);
        }
        $recepcion->unsetRelation('guiasRecepcion');

        return $segmentos;
    }
}
