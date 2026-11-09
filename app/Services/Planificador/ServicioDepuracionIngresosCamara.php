<?php

namespace App\Services\Planificador;

use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Models\Folio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Suelta los ingresos a cámara (salida de túnel, reingreso o pallet pendiente) que ya no corresponden:
 *
 * - el folio es saldo: el planificador nunca pide estibar saldos;
 * - el folio ya está ubicado en una posición de cámara (por ejemplo, el camarero lo estibó por su cuenta):
 *   la salida se da por cumplida y no se vuelve a pedir. Si quedó fuera de afinidad, lo corrige el
 *   reordenamiento, nunca otra salida de túnel.
 *
 * Solo toca pasos que aún no empiezan (bloqueados, pendientes o tomados sin retiro). Una maniobra con un
 * paso en ejecución o bajo custodia se respeta y se reintenta en el siguiente barrido.
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
            ->whereIn('estado', array_map(fn ($e) => $e->value, self::ESTADOS_SIN_INICIAR))
            ->where(fn (Builder $paso) => $paso->whereNull('tipo_paso_maniobra')
                ->orWhere('tipo_paso_maniobra', TipoPasoManiobra::MovimientoPermanente->value))
            ->whereHas('planOperacional', fn (Builder $plan) => $plan->whereIn('tipo', array_map(fn ($t) => $t->value, self::PLANES_INGRESO)))
            ->whereHas('folio', fn (Builder $folio) => $folio
                ->where('tipo_bulto', TipoBulto::Saldo->value)
                ->orWhereHas('ubicacionActual', fn (Builder $ubicacion) => $ubicacion->whereNotNull('posicion_id')))
            ->with(['folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion', 'planOperacional.creadoPor']);
    }

    /** @param iterable<TareaMovimiento> $tareas */
    private function depurar(iterable $tareas, ?User $usuario): int
    {
        $soltadas = 0;
        $temporadas = [];
        foreach ($tareas as $tarea) {
            $motivo = $this->motivo($tarea);
            $responsable = $usuario ?? $tarea->planOperacional?->creadoPor;
            if ($motivo === null || ! $responsable) {
                continue;
            }
            try {
                $resultado = $this->planes->cancelarPorReplanificacion($tarea, $responsable, $motivo);
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

    private function motivo(TareaMovimiento $tarea): ?string
    {
        $folio = $tarea->folio;
        if (! $folio instanceof Folio) {
            return null;
        }
        if ($folio->tipo_bulto === TipoBulto::Saldo) {
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
