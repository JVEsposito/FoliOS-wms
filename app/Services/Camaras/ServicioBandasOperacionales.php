<?php

namespace App\Services\Camaras;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoPosicion;
use App\Enums\EstadoTareaMovimiento;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\ModoBandaOperacional;
use App\Enums\TipoPlanOperacional;
use App\Enums\UsoBandaOperacional;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\Posicion;
use App\Models\ReservaPosicionInspeccionSag;
use App\Models\TareaMovimiento;
use App\Models\UbicacionActual;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicioBandasOperacionales
{
    public function __construct(
        private readonly CalculadorAfinidadBanda $afinidad,
    ) {}

    public function sincronizar(Camara $camara, ?User $usuario = null): void
    {
        if ($camara->contenido !== ContenidoCamara::Productos) {
            return;
        }

        for ($numero = 1; $numero <= $camara->cantidad_bandas; $numero++) {
            BandaOperacional::query()->firstOrCreate(
                [
                    'camara_id' => $camara->id,
                    'numero' => $numero,
                ],
                [
                    'usos_permitidos' => UsoBandaOperacional::valores(),
                    'modo' => ModoBandaOperacional::Operativa,
                    'actualizado_por_user_id' => $usuario?->id,
                    'version' => 1,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function configurar(
        Camara $camara,
        BandaOperacional $banda,
        array $datos,
        User $usuario,
    ): BandaOperacional {
        return DB::transaction(function () use ($camara, $banda, $datos, $usuario): BandaOperacional {
            $camaraBloqueada = Camara::query()->lockForUpdate()->findOrFail($camara->id);
            $bandaBloqueada = BandaOperacional::query()->lockForUpdate()->findOrFail($banda->id);

            if ($camaraBloqueada->contenido !== ContenidoCamara::Productos) {
                throw new DomainException('Solo las cámaras de producto terminado poseen bandas operacionales.');
            }

            if ($bandaBloqueada->camara_id !== $camaraBloqueada->id
                || $bandaBloqueada->numero > $camaraBloqueada->cantidad_bandas) {
                throw new DomainException('La banda no pertenece al plano vigente de la cámara.');
            }

            if ($camaraBloqueada->bloqueo()->exists()) {
                throw new DomainException(
                    'La cámara está siendo modificada desde una tablet. Cierre esa sesión antes de configurar la banda.',
                );
            }

            if ($bandaBloqueada->version !== (int) $datos['version']) {
                throw new DomainException(
                    'La banda cambió desde la última consulta. Actualice el plano antes de volver a guardar.',
                );
            }

            $ordenUsos = array_flip(UsoBandaOperacional::valores());
            $usos = collect($datos['usos_permitidos'])
                ->unique()
                ->sortBy(fn (string $uso): int => (int) $ordenUsos[$uso])
                ->values()
                ->all();
            $modo = ModoBandaOperacional::from($datos['modo']);
            $retiraDisponibilidadInspeccion = $modo !== ModoBandaOperacional::Operativa
                || ! in_array(UsoBandaOperacional::Inspeccion->value, $usos, true);
            $retiraDisponibilidadRetenidos = $modo !== ModoBandaOperacional::Operativa
                || ! in_array(UsoBandaOperacional::Retenidos->value, $usos, true);

            if ($retiraDisponibilidadInspeccion
                && ReservaPosicionInspeccionSag::query()
                    ->whereNotNull('clave_bloqueo')
                    ->whereHas('posicion', fn ($consulta) => $consulta
                        ->where('camara_id', $camaraBloqueada->id)
                        ->where('banda', $bandaBloqueada->numero))
                    ->lockForUpdate()
                    ->exists()) {
                throw new DomainException(
                    'La banda conserva capacidad reservada para una inspección SAG activa.',
                );
            }

            if ($retiraDisponibilidadRetenidos
                && (UbicacionActual::query()
                    ->whereHas('folio', fn ($consulta) => $consulta
                        ->where('habilitacion_almacenamiento',
                            HabilitacionAlmacenamientoFolio::Retenido->value))
                    ->whereHas('posicion', fn ($consulta) => $consulta
                        ->where('camara_id', $camaraBloqueada->id)
                        ->where('banda', $bandaBloqueada->numero))
                    ->lockForUpdate()
                    ->exists()
                    || TareaMovimiento::query()
                        ->whereIn('estado', [
                            EstadoTareaMovimiento::Bloqueada->value,
                            EstadoTareaMovimiento::Pendiente->value,
                            EstadoTareaMovimiento::Asumida->value,
                            EstadoTareaMovimiento::EnProceso->value,
                        ])
                        ->whereHas('planOperacional', fn ($consulta) => $consulta
                            ->where('tipo', TipoPlanOperacional::SegregacionRetenido->value))
                        ->whereHas('posicionDestino', fn ($consulta) => $consulta
                            ->where('camara_id', $camaraBloqueada->id)
                            ->where('banda', $bandaBloqueada->numero))
                        ->lockForUpdate()
                        ->exists())) {
                throw new DomainException(
                    'La banda conserva pallets o maniobras activas de producto retenido.',
                );
            }

            $bandaBloqueada->update([
                'usos_permitidos' => $usos,
                'modo' => $modo,
                'motivo_estado' => $modo === ModoBandaOperacional::Operativa
                    ? null
                    : trim((string) $datos['motivo_estado']),
                'actualizado_por_user_id' => $usuario->id,
                'version' => $bandaBloqueada->version + 1,
            ]);

            $camaraBloqueada->update([
                'version_plano' => $camaraBloqueada->version_plano + 1,
                'actualizado_por_user_id' => $usuario->id,
            ]);

            return $bandaBloqueada->refresh();
        }, attempts: 3);
    }

    public function enriquecer(Camara $camara): Camara
    {
        $camara->loadMissing([
            'bandasOperacionales' => fn ($consulta) => $consulta
                ->where('numero', '<=', $camara->cantidad_bandas)
                ->with('actualizadoPor:id,name'),
            'posiciones.ubicacionesActuales.folio:id,tipo_bulto,marca,exportadora,datos_externos,activo',
            'posiciones.reservaTareaActiva' => fn ($consulta) => $consulta
                ->conCompromisoVigente()->with('tareaMovimiento.folio:id,tipo_bulto,marca,exportadora,datos_externos,activo'),
            'posiciones.reservaPreparacionSagActiva',
        ]);

        /** @var Collection<int, Posicion> $posiciones */
        $posiciones = $camara->posiciones
            ->filter(fn ($posicion): bool => $posicion->banda <= $camara->cantidad_bandas
                && $posicion->posicion <= $camara->posiciones_por_banda
                && $posicion->nivel <= $camara->cantidad_niveles);
        $porBanda = $posiciones->groupBy('banda');

        foreach ($camara->bandasOperacionales as $banda) {
            $coordenadas = $porBanda->get($banda->numero, collect());
            $activas = $coordenadas->filter(
                fn ($posicion): bool => $posicion->estado === EstadoPosicion::Activa,
            );
            $ocupadas = $activas->filter(
                fn ($posicion): bool => $posicion->ubicacionesActuales->isNotEmpty(),
            )->count();
            $reservadas = $activas->filter(
                fn ($posicion): bool => $posicion->ubicacionesActuales->isEmpty()
                    && ($posicion->reservaTareaActiva !== null
                        || $posicion->reservaPreparacionSagActiva !== null),
            )->count();

            $banda->setAttribute('capacidad_fisica_calculada', $coordenadas->count());
            $banda->setAttribute('capacidad_efectiva_calculada', $activas->count());
            $banda->setAttribute('ocupadas_calculadas', $ocupadas);
            $banda->setAttribute('reservadas_calculadas', $reservadas);
            $banda->setAttribute('afinidad_calculada', $this->afinidad->resumirConEntradas($activas));
        }

        return $camara;
    }
}
