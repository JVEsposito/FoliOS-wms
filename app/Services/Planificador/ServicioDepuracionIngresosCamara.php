<?php

namespace App\Services\Planificador;

use App\Enums\AccionResolucionDiscrepancia;
use App\Enums\EstadoDiscrepanciaManiobra;
use App\Enums\EstadoManiobraOperacional;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPasoManiobra;
use App\Enums\TipoPlanOperacional;
use App\Models\CustodiaTemporalManiobra;
use App\Models\DiscrepanciaManiobra;
use App\Models\Folio;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Estiba\ServicioManiobrasOperacionales;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioReservasTareasMovimiento;
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

    /** Repara también tareas históricas de cualquier clase, sin depender de la consola. */
    public function depurarNoOperablesTemporada(Temporada $temporada, int $limite = 200): int
    {
        return $this->depurar($this->candidatas()
            ->whereHas('folio', ElegibilidadFolioPlanificador::noOperables(...))
            ->whereHas('planOperacional', fn ($q) => $q->where('temporada_id', $temporada->id))
            ->orderBy('id')->limit($limite)->get(), null);
    }

    /** @return Builder<TareaMovimiento> */
    private function candidatas(): Builder
    {
        return TareaMovimiento::query()->whereIn('estado', ElegibilidadFolioPlanificador::TAREAS_VIVAS)
            ->where(fn ($q) => $q->whereHas('folio', ElegibilidadFolioPlanificador::noOperables(...))
                ->orWhere(fn ($ingreso) => $ingreso
                    ->where('tipo_movimiento', TipoMovimiento::UbicacionInicial->value)
                    ->where(fn ($paso) => $paso->whereNull('tipo_paso_maniobra')->orWhere('tipo_paso_maniobra', TipoPasoManiobra::MovimientoPermanente->value))
                    ->whereHas('planOperacional', fn ($plan) => $plan->whereIn('tipo', array_map(fn ($t) => $t->value, self::PLANES_INGRESO)))
                    ->whereHas('folio', fn ($folio) => $folio->where('tipo_bulto', '!=', TipoBulto::Pallet->value)
                        ->orWhereHas('ubicacionActual', fn ($ubicacion) => $ubicacion->whereNotNull('posicion_id')))))
            ->with(['folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion', 'planOperacional.creadoPor']);
    }

    /** @param iterable<TareaMovimiento> $tareas */
    private function depurar(iterable $tareas, ?User $usuario): int
    {
        $soltadas = 0;
        $temporadas = [];
        foreach ($tareas as $tarea) {
            try {
                $resultado = DB::transaction(function () use ($tarea, $usuario): ?TareaMovimiento {
                    // La comprobación original puede ser anterior a un retorno de carga. Releer después del mutex.
                    $ids = TareaMovimiento::where('maniobra_operacional_id', $tarea->maniobra_operacional_id)
                        ->when($tarea->maniobra_operacional_id === null, fn ($q) => $q->whereKey($tarea->id))
                        ->pluck('folio_id')->push($tarea->folio_id)->unique()->sort()->values();
                    $folios = Folio::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $actual = TareaMovimiento::lockForUpdate()->find($tarea->id);
                    if (! $actual || $actual->estado->esFinal()) {
                        return null;
                    }
                    $actual->setRelation('folio', $folios[$actual->folio_id]);
                    $motivo = $this->motivoObsolescencia($actual);
                    $responsable = $usuario ?? $actual->planOperacional?->creadoPor;
                    if ($motivo === null || ! $responsable) {
                        return null;
                    }
                    if ($actual->maniobra_operacional_id) {
                        return $this->cancelarManiobra($actual, $responsable, $motivo);
                    }
                    if (! ElegibilidadFolioPlanificador::operable($actual->folio)) {
                        // Los registros anteriores a maniobras también pueden conservar un paso en proceso.
                        if (CustodiaTemporalManiobra::where('folio_id', $actual->folio_id)->where('estado', 'activa')->lockForUpdate()->exists()) {
                            return null;
                        }
                        $actual->update(['estado' => EstadoTareaMovimiento::Cancelada, 'cancelada_at' => now(),
                            'cancelada_por_user_id' => $responsable->id, 'motivo_cancelacion' => Str::limit($motivo, 255, ''), 'version' => $actual->version + 1]);
                        app(ServicioReservasTareasMovimiento::class)->liberarPasoObsoleto($actual, $motivo);

                        return $actual;
                    }

                    return $this->planes->cancelarPorReplanificacion($actual, $responsable, $motivo);
                }, attempts: 3);
                if ($resultado?->estado === EstadoTareaMovimiento::Cancelada) {
                    $soltadas++;
                    $temporadas[$resultado->planOperacional->temporada_id] = true;
                }
            } catch (Throwable $error) {
                // Un conflicto de un pallet no frena el barrido del resto.
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
                function (TareaMovimiento $paso): bool {
                    $paso->setRelation('folio', Folio::lockForUpdate()->findOrFail($paso->folio_id));

                    return $this->motivoObsolescencia($paso) !== null;
                });
            if (! $cancelada) {
                $this->registrarDiscrepanciaConCustodia($tarea, $motivo);

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

    /** La custodia se conserva y queda un caso concreto para ejecutar el retorno seguro existente. */
    private function registrarDiscrepanciaConCustodia(TareaMovimiento $tarea, string $motivo): void
    {
        if (ElegibilidadFolioPlanificador::operable($tarea->folio)) {
            return;
        }
        $maniobra = $tarea->maniobraOperacional()->lockForUpdate()->firstOrFail();
        $custodia = $maniobra->custodiasTemporales()->where('estado', 'activa')->lockForUpdate()->first();
        if (! $custodia || $maniobra->discrepancias()->where('estado', EstadoDiscrepanciaManiobra::Abierta)->lockForUpdate()->exists()) {
            return;
        }
        DiscrepanciaManiobra::create([
            'maniobra_operacional_id' => $maniobra->id, 'tarea_movimiento_id' => $tarea->id, 'folio_id' => $tarea->folio_id,
            'tipo' => 'folio_fuera_operacion', 'detalle' => 'Detección automática del planificador: '.$motivo.' Se conservan las custodias y sus responsables. Supervisión debe comprobar y resolver el retorno seguro.',
            'estado' => EstadoDiscrepanciaManiobra::Abierta, 'reportada_por_user_id' => $custodia->user_id,
            'dispositivo_id' => $custodia->dispositivo_id, 'reportada_at' => now(),
        ]);
        $maniobra->update(['estado' => EstadoManiobraOperacional::PausadaDiscrepancia, 'pausada_at' => now(),
            'contexto' => [...($maniobra->contexto ?? []), 'bloqueo_automatico_folio_no_operable' => $tarea->folio_id],
            'version' => $maniobra->version + 1]);
        if ($tarea->estado !== EstadoTareaMovimiento::EnProceso) {
            $tarea->update(['estado' => EstadoTareaMovimiento::Bloqueada, 'version' => $tarea->version + 1]);
        }
    }

    /**
     * Motivo por el que un ingreso a cámara activo ya no corresponde, o null si sigue vigente. Lo usan el barrido y
     * supervisión al resolver una discrepancia (para permitir cancelar y no reanudar un paso que entraría en bucle).
     */
    public function motivoObsolescencia(TareaMovimiento $tarea): ?string
    {
        $tarea->loadMissing(['planOperacional', 'folio.ubicacionActual.camara', 'folio.ubicacionActual.posicion']);
        $folio = $tarea->folio;
        if ($folio && (! array_key_exists('activo', $folio->getAttributes()) || ! array_key_exists('estado_operacional', $folio->getAttributes()))) {
            $folio = $folio->fresh(['ubicacionActual.camara', 'ubicacionActual.posicion']);
            $tarea->setRelation('folio', $folio);
        }
        if ($folio instanceof Folio && in_array($tarea->estado, self::ESTADOS_ACTIVOS, true)
            && ! ElegibilidadFolioPlanificador::operable($folio)) {
            return ElegibilidadFolioPlanificador::motivo($folio);
        }
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
