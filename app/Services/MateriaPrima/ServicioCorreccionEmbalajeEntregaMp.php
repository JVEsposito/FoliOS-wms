<?php

namespace App\Services\MateriaPrima;

use App\Exceptions\ConflictoOperacion;
use App\Models\CorreccionEmbalajeMp;
use App\Models\EntregaFrutaProceso;
use App\Models\EventoLoteMateriaPrima;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioCorreccionEmbalajeEntregaMp
{
    public function corregir(EntregaFrutaProceso $entrega, array $datos, User $usuario): EntregaFrutaProceso
    {
        $payload = [
            'entrega_id' => $entrega->id,
            'version_embalaje' => (int) $datos['version_embalaje'],
            'embalaje' => trim($datos['embalaje']),
            'kilos_por_caja' => filled($datos['kilos_por_caja'] ?? null) ? round((float) $datos['kilos_por_caja'], 3) : null,
            'motivo' => trim($datos['motivo']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            return DB::transaction(function () use ($entrega, $datos, $usuario, $payload, $hash): EntregaFrutaProceso {
                $bloqueada = EntregaFrutaProceso::query()->lockForUpdate()->findOrFail($entrega->id);
                abort_unless($bloqueada->lote->temporada_id === app(ServicioTemporadaActiva::class)->buscar()?->id, 404);
                $anterior = CorreccionEmbalajeMp::query()->where('operacion_id', $datos['operacion_id'])->first();
                if ($anterior) {
                    $this->verificarRepetida($anterior, $hash);

                    return $this->cargar($bloqueada);
                }
                if ($bloqueada->anulado_at !== null) {
                    throw new ConflictoOperacion('La entrega está anulada y no se puede corregir su embalaje.');
                }
                if ($bloqueada->version_embalaje !== $payload['version_embalaje']) {
                    throw new ConflictoOperacion('Otra persona corrigió el embalaje de esta entrega. Actualiza antes de guardar.');
                }
                $antes = $bloqueada->embalajeRegistrado();
                $nuevo = ['embalaje' => $payload['embalaje'], 'kilos_por_caja' => $payload['kilos_por_caja']];
                if ($antes === $nuevo) {
                    throw ValidationException::withMessages(['embalaje' => 'El embalaje y los kilos por caja coinciden con lo registrado.']);
                }
                $version = $bloqueada->version_embalaje + 1;
                $bloqueada->update(['embalaje_snapshot' => $nuevo['embalaje'], 'kilos_por_caja_snapshot' => $nuevo['kilos_por_caja'], 'version_embalaje' => $version]);
                CorreccionEmbalajeMp::create([
                    'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash,
                    'entrega_fruta_proceso_id' => $bloqueada->id, 'version' => $version,
                    'anterior' => $antes, 'nuevo' => $nuevo, 'motivo' => $payload['motivo'], 'user_id' => $usuario->id,
                ]);
                EventoLoteMateriaPrima::create([
                    'lote_materia_prima_id' => $bloqueada->lote_materia_prima_id,
                    'operacion_id' => $datos['operacion_id'], 'tipo' => 'embalaje_entrega_corregido',
                    'estado_anterior' => $bloqueada->lote->estado->value, 'estado_nuevo' => $bloqueada->lote->estado->value,
                    'user_id' => $usuario->id, 'ocurrido_at' => now(),
                    'datos' => ['entrega_id' => $bloqueada->id, 'cantidad_envases' => $bloqueada->cantidad_envases, 'anterior' => $antes, 'nuevo' => $nuevo, 'motivo' => $payload['motivo']],
                ]);

                return $this->cargar($bloqueada);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $error) {
            $repetida = CorreccionEmbalajeMp::query()->where('operacion_id', $datos['operacion_id'])->first();
            if (! $repetida) {
                throw $error;
            }
            $this->verificarRepetida($repetida, $hash);

            return $this->cargar($entrega->fresh());
        }
    }

    private function verificarRepetida(CorreccionEmbalajeMp $correccion, string $hash): void
    {
        if (! hash_equals($correccion->payload_hash, $hash)) {
            throw new ConflictoOperacion('El identificador de corrección ya fue utilizado con otros datos.');
        }
    }

    private function cargar(EntregaFrutaProceso $entrega): EntregaFrutaProceso
    {
        return $entrega->load(['lote:id,numero_lote,temporada_id', 'versionOrdenProceso.lotes', 'entregadoPor:id,name', 'correccionesEmbalaje.usuario:id,name']);
    }
}
