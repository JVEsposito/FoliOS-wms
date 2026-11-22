<?php

namespace App\Services\Cargas;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\Camara;
use App\Models\Folio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Illuminate\Database\Eloquent\Collection;

final class ServicioUbicacionesDespachadas
{
    private const TIPOS_PT = [TipoBulto::Pallet->value, TipoBulto::Saldo->value];

    public function __construct(private readonly MotorTransicionesOperacionales $motor) {}

    public function previsualizar(): array
    {
        $ubicaciones = $this->candidatas();

        return [
            'cantidad' => $ubicaciones->count(),
            'revision' => $this->revision($ubicaciones),
            'folios' => $ubicaciones->take(8)->load('folio:id,numero_folio')->pluck('folio.numero_folio')->filter()->values(),
        ];
    }

    public function purgar(User $usuario, string $revision): int
    {
        return $this->motor->ejecutar(new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Administracion,
            tipo: 'pallet.purgar_ubicaciones_despachadas',
            usuario: $usuario,
            payload: ['revision' => $revision],
        ), function () use ($revision): int {
            $candidatas = $this->candidatas();
            $this->asegurarRevision($candidatas, $revision);
            if ($candidatas->isEmpty()) {
                return 0;
            }

            $folios = Folio::whereKey($candidatas->pluck('folio_id')->all())
                ->orderBy('id')->lockForUpdate()->get();
            $ubicaciones = $this->bloquearUbicaciones($folios->modelKeys());
            $this->asegurarRevision($ubicaciones, $revision);

            if ($folios->contains(fn (Folio $folio): bool => $folio->activo
                || $folio->estado_operacional !== EstadoOperacionalFolio::Despachado
                || ! in_array($folio->tipo_bulto->value, self::TIPOS_PT, true))) {
                throw new ConflictoOperacion('Un folio cambió de estado. Revisa la lista y vuelve a confirmar.');
            }

            return $this->liberar($ubicaciones);
        });
    }

    /** El cierre ya bloqueó los folios y confirmó su salida en la misma transacción. */
    public function liberarEnCierre(array $folioIds): void
    {
        $this->liberar($this->bloquearUbicaciones($folioIds));
    }

    private function candidatas(): Collection
    {
        return UbicacionActual::whereHas('folio', fn ($folio) => $folio
            ->where('estado_operacional', EstadoOperacionalFolio::Despachado->value)
            ->where('activo', false)
            ->whereIn('tipo_bulto', self::TIPOS_PT))
            ->orderBy('folio_id')->get();
    }

    private function bloquearUbicaciones(array $folios): Collection
    {
        return UbicacionActual::whereIn('folio_id', $folios)->orderBy('folio_id')->lockForUpdate()->get();
    }

    private function asegurarRevision(Collection $ubicaciones, string $revision): void
    {
        if (! hash_equals($this->revision($ubicaciones), $revision)) {
            throw new ConflictoOperacion('Cambiaron las ubicaciones pendientes. Revisa la lista y vuelve a confirmar.');
        }
    }

    private function revision(Collection $ubicaciones): string
    {
        return hash('sha256', json_encode($ubicaciones->map(fn (UbicacionActual $ubicacion): array => $ubicacion->only([
            'id', 'folio_id', 'camara_id', 'posicion_id', 'movimiento_id', 'ubicado_at',
        ])), JSON_THROW_ON_ERROR));
    }

    private function liberar(Collection $ubicaciones): int
    {
        foreach ($ubicaciones as $ubicacion) {
            $ubicacion->delete();
        }
        foreach ($ubicaciones->pluck('camara_id')->unique()->sort() as $camaraId) {
            Camara::whereKey($camaraId)->increment('version_plano');
        }

        return $ubicaciones->count();
    }
}
