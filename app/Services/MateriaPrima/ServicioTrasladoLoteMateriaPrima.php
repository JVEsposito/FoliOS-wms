<?php

namespace App\Services\MateriaPrima;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoLoteMateriaPrima;
use App\Exceptions\ConflictoOperacion;
use App\Models\AsignacionCamaraLoteMateriaPrima;
use App\Models\Camara;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioTrasladoLoteMateriaPrima
{
    public function trasladar(LoteMateriaPrima $lote, array $datos, User $usuario): LoteMateriaPrima
    {
        $hash = hash('sha256', json_encode([
            'version' => (int) $datos['version_conocida'],
            'origen' => $datos['camara_origen_id'],
            'destino' => $datos['camara_destino_id'],
            'cantidad' => (int) $datos['cantidad_envases'],
            'motivo' => trim($datos['motivo']),
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($lote, $datos, $usuario, $hash) {
                $actual = LoteMateriaPrima::query()
                    ->where('temporada_id', app(ServicioTemporadaActiva::class)->buscar()?->id)
                    ->lockForUpdate()->findOrFail($lote->id);
                $evento = EventoLoteMateriaPrima::where('operacion_id', $datos['operacion_id'])->first();
                if ($evento) {
                    $this->validarReintento($evento, $actual, $hash);

                    return $actual;
                }
                if ($actual->version !== (int) $datos['version_conocida']) {
                    throw new ConflictoOperacion('El lote cambió. Actualiza su ubicación y saldo antes de trasladarlo.');
                }
                if (! in_array($actual->estado, [EstadoLoteMateriaPrima::AsignadoCamara, EstadoLoteMateriaPrima::EntregaParcialProceso], true)) {
                    throw new ConflictoOperacion('Solo se puede trasladar un lote con saldo disponible en cámara.');
                }
                $asignacion = AsignacionCamaraLoteMateriaPrima::where('lote_materia_prima_id', $actual->id)->lockForUpdate()->first();
                if (! $asignacion || $asignacion->camara_id !== $datos['camara_origen_id']) {
                    throw new ConflictoOperacion('La cámara de origen cambió. Actualiza el lote antes de trasladarlo.');
                }
                $saldo = $actual->cantidad_envases_primarios - (int) $actual->entregasProceso()->whereNull('anulado_at')->sum('cantidad_envases');
                if ($saldo < 1 || $saldo !== (int) $datos['cantidad_envases']) {
                    throw new ConflictoOperacion('Debes trasladar el saldo completo vigente: '.$saldo.' envases. Actualiza el lote.');
                }
                $camaras = Camara::whereIn('id', [$asignacion->camara_id, $datos['camara_destino_id']])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $origen = $camaras->get($asignacion->camara_id);
                $destino = $camaras->get($datos['camara_destino_id']);
                if (! $destino || $destino->id === $origen?->id
                    || $destino->contenido !== ContenidoCamara::MateriaPrima || $destino->estado !== EstadoCamara::Activa) {
                    throw ValidationException::withMessages(['camara_destino_id' => 'Selecciona otra cámara activa exclusiva de materia prima.']);
                }
                $fecha = now();
                // La asignación conserva su identidad; cada viaje anterior conserva su propia cámara.
                $asignacion->update(['camara_id' => $destino->id, 'asignado_por_user_id' => $usuario->id,
                    'asignado_at' => $fecha, 'observacion' => trim($datos['motivo'])]);
                $actual->update(['version' => $actual->version + 1, 'actualizado_por_user_id' => $usuario->id]);
                EventoLoteMateriaPrima::create([
                    'lote_materia_prima_id' => $actual->id, 'operacion_id' => $datos['operacion_id'],
                    'tipo' => 'camara_trasladada', 'estado_anterior' => $actual->estado->value, 'estado_nuevo' => $actual->estado->value,
                    'user_id' => $usuario->id, 'ocurrido_at' => $fecha,
                    'datos' => ['payload_hash' => $hash, 'camara_origen_id' => $origen?->id,
                        'camara_origen_codigo' => $origen?->codigo, 'camara_origen_nombre' => $origen?->nombre,
                        'camara_destino_id' => $destino->id, 'camara_destino_codigo' => $destino->codigo, 'camara_destino_nombre' => $destino->nombre,
                        'cantidad_envases' => $saldo, 'tipo_envase' => $actual->envase_primario->value,
                        'usuario_nombre' => $usuario->name,
                        'motivo' => trim($datos['motivo']), 'version' => $actual->version],
                ]);

                return $actual;
            }, attempts: 3);
        } catch (QueryException $error) {
            $evento = EventoLoteMateriaPrima::where('operacion_id', $datos['operacion_id'])->first();
            if (! $evento) {
                throw $error;
            }
            $this->validarReintento($evento, $lote, $hash);

            return $lote->fresh();
        }
    }

    private function validarReintento(EventoLoteMateriaPrima $evento, LoteMateriaPrima $lote, string $hash): void
    {
        if ($evento->tipo !== 'camara_trasladada' || $evento->lote_materia_prima_id !== $lote->id
            || ! hash_equals($evento->datos['payload_hash'] ?? '', $hash)) {
            throw new ConflictoOperacion('El identificador de traslado ya fue utilizado con otros datos.');
        }
    }
}
