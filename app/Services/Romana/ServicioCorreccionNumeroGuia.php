<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Exceptions\ConflictoOperacion;
use App\Models\DefectoRecepcionMp;
use App\Models\EventoRecepcionRomana;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Corrige solo el número de las guías de ingreso de una recepción, en cualquier etapa: no cambia envases,
 * conteos, lotes ni kilos. El número nuevo llega a la recepción, a sus guías, a los asientos de la cuenta
 * de envases que lo llevan como documento y al registro de defectos. Queda un evento con antes, después y motivo.
 */
class ServicioCorreccionNumeroGuia
{
    /** Asientos de la cuenta de envases que muestran la guía de ingreso como su documento. */
    private const MOVIMIENTOS = [TipoMovimientoEnvase::RecepcionFruta, TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::RecepcionCompra,
        TipoMovimientoEnvase::DevolucionEnvases, TipoMovimientoEnvase::CorreccionCantidad, TipoMovimientoEnvase::CorreccionPropiedad,
        TipoMovimientoEnvase::CorreccionCuenta, TipoMovimientoEnvase::AnulacionRecepcion, TipoMovimientoEnvase::AjusteCuenta,
        TipoMovimientoEnvase::ReversionAjusteCuenta];

    public function corregir(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        Gate::forUser($usuario)->authorize('corregir-recepciones-validadas');
        $payload = [
            'version' => (int) $datos['version_conocida'], 'motivo' => trim((string) $datos['motivo']),
            'guias' => collect($datos['guias'])->map(fn ($g): array => ['id' => (string) $g['id'],
                'numero_guia_despacho' => mb_strtoupper(trim((string) $g['numero_guia_despacho']))])->sortBy('id')->values()->all(),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->payload_hash !== $hash || $evento->tipo !== TipoEventoRomana::NumeroGuiaCorregido) {
                    throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
                }

                return $recepcion;
            }
            if ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->eliminada_at !== null) {
                throw new ConflictoOperacion('La recepción está anulada o eliminada.');
            }
            if ($recepcion->temporada_id !== app(ServicioTemporadaActiva::class)->buscar(bloquear: true)?->id) {
                throw new ConflictoOperacion('Solo se corrigen recepciones de la temporada activa.');
            }
            if ($recepcion->version !== $payload['version']) {
                throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de corregir la guía.');
            }
            $vigentes = $recepcion->guiasRecepcion()->where('vigente', 1)->lockForUpdate()->orderBy('secuencia')->get()->keyBy('id');
            if ($vigentes->keys()->sort()->values()->all() !== collect($payload['guias'])->pluck('id')->sort()->values()->all()) {
                throw ValidationException::withMessages(['guias' => 'Envía cada guía vigente de la recepción, sin agregar ni quitar documentos.']);
            }
            $numeros = array_column($payload['guias'], 'numero_guia_despacho', 'id');
            if (in_array('', $numeros, true) || count($numeros) !== count(array_unique($numeros))) {
                throw ValidationException::withMessages(['guias' => 'Cada guía necesita un número, sin repetirlo dentro del camión.']);
            }
            $antes = $vigentes->map(fn (GuiaRecepcionRomana $g): string => $g->numero_guia_despacho)->all();
            $cambios = array_filter($numeros, fn (string $numero, string $id): bool => $antes[$id] !== $numero, ARRAY_FILTER_USE_BOTH);
            if ($cambios === []) {
                throw new ConflictoOperacion('Los números de guía no presentan cambios.');
            }
            foreach ($cambios as $numero) {
                $usada = GuiaRecepcionRomana::query()->where('temporada_id', $recepcion->temporada_id)->where('cliente_id', $recepcion->cliente_id)
                    ->where('numero_guia_despacho', $numero)->where('vigente', 1)->where('recepcion_romana_id', '!=', $recepcion->id)
                    ->with('recepcion:id,numero_recepcion')->first();
                if ($usada) {
                    throw ValidationException::withMessages(['guias' => "La guía {$numero} ya está registrada para este cliente en {$usada->recepcion?->numero_recepcion}."]);
                }
            }
            // Dos pasos para no chocar con la clave única al intercambiar números entre guías del mismo camión.
            foreach ($cambios as $id => $numero) {
                $vigentes[$id]->update(['numero_guia_despacho' => 'TMP-'.Str::lower(Str::random(12))]);
            }
            foreach ($cambios as $id => $numero) {
                $vigentes[$id]->update(['numero_guia_despacho' => $numero]);
            }
            $resumenAnterior = $recepcion->numero_guia_despacho;
            $resumen = ServicioGuiasRecepcionRomana::resumen($vigentes->map(fn (GuiaRecepcionRomana $g): array => ['numero_guia_despacho' => $g->numero_guia_despacho])->values()->all());
            $recepcion->update(['numero_guia_despacho' => $resumen, 'version' => $recepcion->version + 1]);
            self::propagar($recepcion, $resumenAnterior, $resumen);
            $ahora = CarbonImmutable::now();
            EventoRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'recepcion_romana_id' => $recepcion->id,
                'tipo' => TipoEventoRomana::NumeroGuiaCorregido, 'estado_anterior' => $recepcion->estado, 'estado_nuevo' => $recepcion->estado,
                'user_id' => $usuario->id, 'ocurrido_at' => $ahora,
                'datos' => ['motivo' => $payload['motivo'], 'resumen' => collect($cambios)->map(fn (string $numero, string $id): string => "Guía {$antes[$id]} → {$numero}")->implode('; '),
                    'guias' => collect($cambios)->map(fn (string $numero, string $id): array => ['id' => $id, 'anterior' => $antes[$id], 'nuevo' => $numero])->values()->all()],
            ]);

            return $recepcion->refresh();
        }, attempts: 3);
    }

    /**
     * Lleva el número nuevo a donde quedó copiado: los asientos de la cuenta de envases que lo muestran como
     * documento (ingresos, sus correcciones y los ajustes de recibidos) y el registro de defectos. Los asientos de
     * la salida tienen su propia guía y no se tocan.
     */
    public static function propagar(RecepcionRomana $recepcion, ?string $anterior, string $nuevo): void
    {
        if ($anterior === null || $anterior === $nuevo) {
            return;
        }
        MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)->where('numero_documento', $anterior)
            ->whereIn('tipo_movimiento', array_map(fn (TipoMovimientoEnvase $t): string => $t->value, self::MOVIMIENTOS))
            ->update(['numero_documento' => $nuevo]);
        DefectoRecepcionMp::query()->where('recepcion_romana_id', $recepcion->id)->update(['numero_guia_snapshot' => $nuevo]);
    }
}
