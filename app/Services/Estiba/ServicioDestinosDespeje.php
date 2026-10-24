<?php

namespace App\Services\Estiba;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\ModoBandaOperacional;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\CargaFolio;
use App\Models\Posicion;
use App\Models\ReservaBandaManiobra;
use App\Models\TareaMovimiento;
use Illuminate\Support\Collection;

class ServicioDestinosDespeje
{
    public function aplica(TareaMovimiento $tarea): bool
    {
        return in_array($tarea->contexto['tipo_decision'] ?? null, ['despeje_salida_directa', 'despeje_concentracion'], true);
    }

    /** Bandas de salida conocidas: un despeje no debe volver a bloquear su carga. */
    public function bandasProtegidas(TareaMovimiento $tarea): array
    {
        $origen = $tarea->posicionOrigen;
        $puntos = $origen ? [['camara_id' => $origen->camara_id, 'banda' => $origen->banda, 'nivel' => $origen->nivel]] : [];
        $cargaId = $tarea->contexto['carga_id'] ?? null;
        if ($cargaId) {
            $asignaciones = CargaFolio::query()->where('carga_id', $cargaId)->whereHas('reservaActiva')
                ->with('folio.ubicacionActual.posicion')->get();
            foreach ($asignaciones as $asignacion) {
                $p = $asignacion->folio?->ubicacionActual?->posicion;
                if ($p) {
                    $puntos[] = ['camara_id' => $p->camara_id, 'banda' => $p->banda, 'nivel' => $p->nivel];
                }
            }
        }

        return collect($puntos)->unique(fn (array $p) => implode(':', $p))->values()->all();
    }

    public function validar(TareaMovimiento $tarea, Posicion $destino): void
    {
        if (! $this->aplica($tarea)) {
            return;
        }
        $protegidas = $this->bandasProtegidas($tarea);
        if (collect($protegidas)->contains(fn (array $p) => $p['camara_id'] === $destino->camara_id
            && $p['banda'] === $destino->banda && $p['nivel'] === $destino->nivel)) {
            throw new ConflictoOperacion('El despeje no puede ubicarse en una banda necesaria para extraer la carga.');
        }
        $locales = $this->locales($tarea, $protegidas);
        if ($destino->camara_id === $tarea->camara_origen_id && ! $locales->contains('id', $destino->id)) {
            throw new ConflictoOperacion('La posición local no está disponible o volvería a bloquear el acceso. Recalcula el despeje.');
        }
        if ($destino->camara_id !== $tarea->camara_origen_id && $locales->isNotEmpty()) {
            throw new ConflictoOperacion('Hay espacio válido en la cámara de origen para este despeje. Recalcula allí antes de trasladar a otra cámara.');
        }
    }

    /** @return Collection<int, Posicion> */
    private function locales(TareaMovimiento $tarea, array $protegidas): Collection
    {
        $camara = Camara::query()->with('bandasOperacionales')->find($tarea->camara_origen_id);
        if (! $camara || $camara->estado !== EstadoCamara::Activa || $camara->contenido !== ContenidoCamara::Productos) {
            return collect();
        }
        $posiciones = $camara->posiciones()->where('estado', EstadoPosicion::Activa)
            ->where('banda', '<=', $camara->cantidad_bandas)->where('posicion', '<=', $camara->posiciones_por_banda)
            ->where('nivel', '<=', $camara->cantidad_niveles)
            ->with(['ubicacionesActuales.folio', 'reservaTareaActiva' => fn ($r) => $r->conCompromisoVigente(), 'reservaPreparacionSagActiva'])
            ->get();
        $porBanda = $posiciones->groupBy('banda');
        $bloqueos = ReservaBandaManiobra::query()->where('camara_id', $camara->id)->whereNotNull('clave_bloqueo')
            ->where('maniobra_operacional_id', '!=', $tarea->maniobra_operacional_id ?? '')->get();

        return $posiciones->filter(function (Posicion $p) use ($tarea, $camara, $porBanda, $bloqueos, $protegidas): bool {
            $reservaAjena = $p->reservaTareaActiva
                && $p->reservaTareaActiva->tarea_movimiento_id !== $tarea->id;
            if ($p->id === $tarea->posicion_origen_id || $p->ubicacionesActuales->isNotEmpty() || $reservaAjena || $p->reservaPreparacionSagActiva) {
                return false;
            }
            if (collect($protegidas)->contains(fn (array $b) => $b['camara_id'] === $p->camara_id && $b['banda'] === $p->banda && $b['nivel'] === $p->nivel)) {
                return false;
            }
            $banda = $camara->bandasOperacionales->firstWhere('numero', $p->banda);
            if (! $banda || $banda->modo !== ModoBandaOperacional::Operativa || ! in_array('transito_pt', $banda->usos_permitidos ?? [], true)) {
                return false;
            }
            if ($bloqueos->contains(fn ($b) => $b->banda === $p->banda && $b->nivel === $p->nivel)) {
                return false;
            }
            $mismaBanda = $porBanda->get($p->banda, collect());
            if ($mismaBanda->flatMap->ubicacionesActuales->contains(fn ($u) => ! $u->folio?->activo || $u->folio?->tipo_bulto !== TipoBulto::Pallet)) {
                return false;
            }
            if ($p->nivel > 1 && ! $mismaBanda->contains(fn (Posicion $soporte) => $soporte->posicion === $p->posicion && $soporte->nivel === $p->nivel - 1 && $soporte->ubicacionesActuales->isNotEmpty())) {
                return false;
            }

            return ! $mismaBanda->contains(fn (Posicion $frente) => $frente->nivel === $p->nivel && $frente->posicion > $p->posicion && ($frente->ubicacionesActuales->isNotEmpty() || $frente->reservaTareaActiva || $frente->reservaPreparacionSagActiva));
        })->values();
    }
}
