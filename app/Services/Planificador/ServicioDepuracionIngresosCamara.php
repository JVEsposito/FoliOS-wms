<?php

namespace App\Services\Planificador;

use App\Enums\AccionResolucionDiscrepancia;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Models\DiscrepanciaManiobra;
use App\Models\Folio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suelta los ingresos a cámara (salida de túnel, reingreso o pallet pendiente) que ya no corresponden:
 *
 * - el folio es saldo o ya no está activo (consumido en una repa, despachado): el planificador nunca pide
 *   estibar saldos, sin importar cómo nació la tarea (generación, repa posterior o restauración);
 * - el folio ya está ubicado en una posición de cámara (por ejemplo, el camarero lo estibó por su cuenta):
 *   la salida se da por cumplida y no se vuelve a pedir. Si quedó fuera de afinidad, lo corrige el
 *   reordenamiento, nunca otra salida de túnel.
 *
 * Un paso «en movimiento» de un pallet que ya está ubicado nunca podrá completarse, así que también se cierra.
 * Una maniobra con custodia temporal activa se respeta. Si la maniobra estaba pausada por una discrepancia,
 * la discrepancia queda resuelta como cancelación con el mismo motivo.
 */
class ServicioDepuracionIngresosCamara
{
    /** Planes cuyo pallet entra a cámara desde afuera (túnel, andén o ubicación pendiente). */
    private const PLANES_INGRESO = [TipoPlanOperacional::RecepcionTunel, TipoPlanOperacional::AlmacenamientoPallet];

    private const ESTADOS_SIN_INICIAR = [EstadoTareaMovimiento::Bloqueada, EstadoTareaMovimiento::Pendiente, EstadoTareaMovimiento::Asumida];

    private const ESTADOS_ACTIVOS = [...self::ESTADOS_SIN_INICIAR, EstadoTareaMovimiento::EnProceso];

    public function __construct(private readonly ServicioPlanesOperacionales $planes) {}

    /** Depura los ingresos del folio indicado. Devuelve cuántas tareas soltó. */
    public function depurarFolio(string $folioId, ?User $usuario = null): int
    {
        return $this->depurar($this->candidatas()->where('folio_id', $folioId)->get(), $usuario);
    }

    /** Barrido periódico de la temporada. Devuelve cuántas tareas soltó. */
    public function depurarTemporada(Temporada $temporada, int $limite = 200): int
    {
        return $this->depurar($this->candidatas()
            ->whereHas('planOperacional', fn (Builder $plan) => $plan->where('temporada_id', $temporada->id))
            ->orderBy('created_at')->limit(max(1, $limite))->get(), null);
    }

    /** @return Builder<TareaMovimiento> */
    private function candidatas(): Builder
    {
        return TareaMovimiento::query()
            ->where('tipo_movimiento', TipoMovimiento::UbicacionInicial->value)
            ->whereIn('estado', array_map(fn ($e) => $e->value, self::ESTADOS_ACTIVOS))
            ->where(fn (Builder $paso) => $paso->whereNull('tipo_paso_maniobra')
                ->orWhere('tipo_paso_maniobra', TipoPasoManiobra::MovimientoPermanente->value))
            ->whereHas('planOperacional', fn (Builder $plan) => $plan->whereIn('tipo', array_map(fn ($t) => $t->value, self::PLANES_INGRESO)))
            ->whereHas('folio', fn (Builder $folio) => $folio
                ->where('tipo_bulto', '!=', TipoBulto::Pallet->value)
                ->orWhere('activo', false)
                ->orWhereHas('ubicacionActual', fn (Builder $ubicacion) => $ubicacion->whereNotNull('posicion_id')))
            ->with(['folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion', 'planOperacional.creadoPor']);
    }

    /** @param iterable<TareaMovimiento> $tareas */
    private function depurar(iterable $tareas, ?User $usuario): int
    {
        $soltadas = 0;
        $temporadas = [];
        foreach ($tareas as $tarea) {
            $motivo = $this->motivoObsolescencia($tarea);
            $responsable = $usuario ?? $tarea->planOperacional?->creadoPor;
            if ($motivo === null || ! $responsable) {
                continue;
            }
            try {
                $resultado = $tarea->maniobra_operacional_id
                    ? $this->cancelarManiobra($tarea, $responsable, $motivo)
                    : $this->planes->cancelarPorReplanificacion($tarea, $responsable, $motivo);
                if ($resultado?->estado === EstadoTareaMovimiento::Cancelada) {
                    $soltadas++;
                    $temporadas[$tarea->planOperacional->temporada_id] = true;
                }
            } catch (Throwable $error) {
                // Una tarea que cambió entremedio no debe frenar el resto; el siguiente barrido la reevalúa.
                report($error);
            }
        }
        // El arbitraje vigente aún muestra esas maniobras: pedir un ciclo nuevo.
        foreach (array_keys($temporadas) as $temporadaId) {
            app(ServicioEstadoArbitrajePlanificador::class)->solicitar($temporadaId, 'depuracion_ingresos_camara');
        }

        return $soltadas;
    }

    private function cancelarManiobra(TareaMovimiento $tarea, User $responsable, string $motivo): ?TareaMovimiento
    {
        return DB::transaction(function () use ($tarea, $responsable, $motivo): ?TareaMovimiento {
            $maniobra = $tarea->maniobraOperacional()->firstOrFail();
            $cancelada = app(ServicioManiobrasOperacionales::class)->cancelarReversible($maniobra, $responsable, $motivo,
                fn (TareaMovimiento $paso): bool => $this->motivoObsolescencia($paso) !== null);
            if (! $cancelada) {
                return null;
            }
            DiscrepanciaManiobra::query()->where('maniobra_operacional_id', $maniobra->id)
                ->where('estado', EstadoDiscrepanciaManiobra::Abierta->value)->lockForUpdate()->get()
                ->each(fn (DiscrepanciaManiobra $discrepancia) => $discrepancia->update([
                    'estado' => EstadoDiscrepanciaManiobra::Resuelta, 'accion_resolucion' => AccionResolucionDiscrepancia::CancelarManiobra,
                    'resuelta_por_user_id' => $responsable->id, 'resuelta_at' => now(),
                    'resolucion' => Str::limit('Resuelta automáticamente: '.$motivo, 1000, ''),
                ]));

            return $tarea->refresh();
        }, attempts: 3);
    }

    /**
     * Motivo por el que un ingreso a cámara activo ya no corresponde, o null si sigue vigente. Lo usan el barrido y
     * supervisión al resolver una discrepancia (para permitir cancelar y no reanudar un paso que entraría en bucle).
     */
    public function motivoObsolescencia(TareaMovimiento $tarea): ?string
    {
        $tarea->loadMissing(['planOperacional', 'folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion']);
        $folio = $tarea->folio;
        if (! $folio instanceof Folio
            || $tarea->tipo_movimiento !== TipoMovimiento::UbicacionInicial
            || ! in_array($tarea->estado, self::ESTADOS_ACTIVOS, true)
            || ! in_array($tarea->tipo_paso_maniobra, [null, TipoPasoManiobra::MovimientoPermanente], true)
            || ! in_array($tarea->planOperacional?->tipo, self::PLANES_INGRESO, true)) {
            return null;
        }
        if (! $folio->activo) {
            return "{$folio->numero_folio} ya no está activo (consumido o despachado): no hay nada que estibar.";
        }
        if ($folio->tipo_bulto !== TipoBulto::Pallet) {
            return "{$folio->numero_folio} es saldo: el planificador no pide estibar saldos.";
        }
        $ubicacion = $folio->ubicacionActual;
        if ($ubicacion?->posicion_id === null) {
            return null;
        }
        // Si otra labor activa lo saca de su posición (retiro, despeje), la ubicación actual es transitoria.
        $otraLabor = TareaMovimiento::query()->where('folio_id', $folio->id)->whereKeyNot($tarea->id)
            ->whereIn('estado', array_map(fn ($e) => $e->value, self::ESTADOS_ACTIVOS))
            ->where('tipo_movimiento', '!=', TipoMovimiento::UbicacionInicial->value)->exists();
        if ($otraLabor) {
            return null;
        }
        $lugar = trim(($ubicacion->camara?->nombre ?? 'cámara').' · '.($ubicacion->posicion?->etiqueta ?? ''), ' ·');

        return "{$folio->numero_folio} ya está ubicado en {$lugar}: la salida se da por cumplida.";
    }
}
