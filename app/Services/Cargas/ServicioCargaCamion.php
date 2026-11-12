<?php

namespace App\Services\Cargas;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\TipoEventoCarga;
use App\Exceptions\OperacionNoAutorizada;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use App\Models\Folio;
use App\Models\ReservaCargaFolio;
use App\Models\User;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\ContextoEjecucionTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Str;

/**
 * Registro físico de los pallets que suben al camión o contenedor.
 *
 * «En andén» y «cargado» son estados distintos: el primero indica que el pallet
 * salió de la cámara; el segundo, que el camarero lo leyó al subirlo. Ambos
 * conservan la reserva hasta el cierre del despacho.
 */
class ServicioCargaCamion
{
    public function __construct(
        private readonly AlcanceOperacionalUsuario $alcance,
        private readonly MotorTransicionesOperacionales $motorTransiciones,
        private readonly ContextoEjecucionTransicionOperacional $contexto,
    ) {}

    public function registrarCargado(
        string $operacionId,
        Carga $carga,
        string $numeroFolio,
        User $usuario,
        Dispositivo $dispositivo,
        DateTimeInterface $generadoDispositivoAt,
    ): CargaFolio {
        $this->asegurarOperador($usuario);
        $numero = Str::upper(trim($numeroFolio));
        if ($numero === '') {
            throw new DomainException('Lee o digita el folio que subió al camión.');
        }
        $leidoAt = CarbonImmutable::instance($generadoDispositivoAt);
        if ($leidoAt->isAfter(CarbonImmutable::now()->addMinutes(5))) {
            throw new DomainException('La hora de lectura del equipo está adelantada; revisa el reloj de la tablet.');
        }

        $accion = function () use ($carga, $numero, $usuario, $dispositivo, $leidoAt): CargaFolio {
            if ($this->contexto->actual() === null) {
                // Repetición de una lectura ya aplicada: solo se rehidrata la respuesta.
                return $this->asignacionDeFolio($carga, $numero);
            }

            $cargaBloqueada = Carga::query()->lockForUpdate()->findOrFail($carga->id);
            $this->asegurarCargaEnDespacho($cargaBloqueada);
            $folio = Folio::query()->where('numero_folio', $numero)->first();
            if (! $folio) {
                throw new DomainException("El folio {$numero} no existe en el sistema.");
            }

            $asignacion = CargaFolio::query()
                ->where('carga_id', $cargaBloqueada->id)
                ->where('folio_id', $folio->id)
                ->whereHas('reservaActiva')
                ->lockForUpdate()
                ->first();
            if (! $asignacion) {
                // Solo informa al camarero: no bloquea filas de la otra carga.
                $reservaAjena = ReservaCargaFolio::query()
                    ->where('folio_id', $folio->id)
                    ->with('asignacion.carga:id,codigo')
                    ->first();
                throw new DomainException($reservaAjena
                    ? "El folio {$numero} pertenece a la carga {$reservaAjena->asignacion->carga->codigo}, no a {$cargaBloqueada->codigo}. No lo subas a este camión."
                    : "El folio {$numero} no pertenece a la carga {$cargaBloqueada->codigo}. No lo subas a este camión.");
            }

            if ($asignacion->estado === EstadoCargaFolio::Cargado) {
                throw new DomainException(sprintf(
                    'El folio %s ya está registrado como cargado desde las %s.',
                    $numero,
                    $asignacion->cargado_at?->setTimezone(config('app.operational_timezone', config('app.timezone')))->format('H:i'),
                ));
            }
            if ($asignacion->estado !== EstadoCargaFolio::EnAnden) {
                throw new DomainException("El folio {$numero} todavía no sale de la cámara: envíalo al andén antes de cargarlo.");
            }

            return $this->aplicarCargado($cargaBloqueada, $asignacion, $folio, $usuario, $dispositivo, $leidoAt);
        };

        return $this->transicionar('folio.cargar_camion', $operacionId, $usuario, [
            'carga_id' => $carga->id,
            'numero_folio' => $numero,
            'generado_dispositivo_at' => $leidoAt->toAtomString(),
        ], $accion, Carga::class, (string) $carga->id, $carga->codigo, $dispositivo, $leidoAt);
    }

    /**
     * Sube al camión un pallet que espera en el andén. Se ejecuta dentro de una transición
     * ya abierta, con la carga y la asignación bloqueadas. Lo usan la lectura del camarero y
     * la planilla R-24 de consolidación: ambas dejan el mismo estado «cargado».
     *
     * @param  array<string, mixed>  $datosEvento
     */
    public function aplicarCargado(
        Carga $cargaBloqueada,
        CargaFolio $asignacion,
        Folio $folio,
        User $usuario,
        Dispositivo $dispositivo,
        CarbonImmutable $leidoAt,
        array $datosEvento = [],
    ): CargaFolio {
        if ($asignacion->estado !== EstadoCargaFolio::EnAnden) {
            throw new DomainException("El folio {$folio->numero_folio} todavía no sale de la cámara: envíalo al andén antes de cargarlo.");
        }

        $asignacion->update([
            'estado' => EstadoCargaFolio::Cargado,
            'cargado_por_user_id' => $usuario->id,
            'cargado_desde_dispositivo_id' => $dispositivo->id,
            'cargado_at' => $leidoAt->isFuture() ? CarbonImmutable::now() : $leidoAt,
        ]);
        $cargaBloqueada->update([
            'version' => $cargaBloqueada->version + 1,
            'actualizada_por_user_id' => $usuario->id,
        ]);
        $this->registrarEvento($cargaBloqueada, TipoEventoCarga::FolioCargado, $usuario, $folio, [
            'carga_folio_id' => $asignacion->id,
            'anden_id' => $asignacion->anden_id,
            'presencia_carga_anden_id' => $cargaBloqueada->presenciaAndenActiva()->value('id'),
            'dispositivo_id' => $dispositivo->id,
            'leido_at' => $leidoAt->toAtomString(),
            ...$datosEvento,
        ]);

        return $asignacion->refresh();
    }

    public function deshacerCargado(
        string $operacionId,
        CargaFolio $asignacion,
        string $motivo,
        User $usuario,
        Dispositivo $dispositivo,
    ): CargaFolio {
        $this->asegurarOperador($usuario);
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw new DomainException('Indica por qué el pallet se baja del camión.');
        }

        $accion = function () use ($asignacion, $motivo, $usuario, $dispositivo): CargaFolio {
            if ($this->contexto->actual() === null) {
                return CargaFolio::query()->findOrFail($asignacion->id);
            }

            $cargaId = CargaFolio::query()->whereKey($asignacion->id)->value('carga_id');
            $carga = Carga::query()->lockForUpdate()->findOrFail($cargaId);
            $this->asegurarCargaEnDespacho($carga);
            $bloqueada = CargaFolio::query()->with('folio')->lockForUpdate()->findOrFail($asignacion->id);
            if ($bloqueada->estado !== EstadoCargaFolio::Cargado
                || ! $bloqueada->reservaActiva()->lockForUpdate()->exists()) {
                throw new DomainException('Solo un pallet registrado como cargado puede bajarse del camión.');
            }

            $cargadoAt = $bloqueada->cargado_at;
            $bloqueada->update([
                'estado' => EstadoCargaFolio::EnAnden,
                'cargado_por_user_id' => null,
                'cargado_desde_dispositivo_id' => null,
                'cargado_at' => null,
            ]);
            $carga->update([
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->registrarEvento($carga, TipoEventoCarga::FolioDescargado, $usuario, $bloqueada->folio, [
                'carga_folio_id' => $bloqueada->id,
                'motivo' => $motivo,
                'cargado_at' => $cargadoAt?->toAtomString(),
                'dispositivo_id' => $dispositivo->id,
            ]);

            return $bloqueada->refresh();
        };

        return $this->transicionar('folio.descargar_camion', $operacionId, $usuario, [
            'carga_folio_id' => $asignacion->id,
            'motivo' => $motivo,
        ], $accion, CargaFolio::class, (string) $asignacion->id, dispositivo: $dispositivo);
    }

    private function asegurarOperador(User $usuario): void
    {
        if (! $this->alcance->puedeEnviarFoliosAnden($usuario)) {
            throw new OperacionNoAutorizada('El usuario no está autorizado para registrar la carga del camión.');
        }
    }

    private function asegurarCargaEnDespacho(Carga $carga): void
    {
        if (! in_array($carga->estado, [...EstadoCarga::visiblesEnOperacion(), EstadoCarga::Despachada], true)) {
            throw new DomainException("La carga {$carga->codigo} ya no admite movimientos de carguío.");
        }
    }

    private function asignacionDeFolio(Carga $carga, string $numero): CargaFolio
    {
        return CargaFolio::query()
            ->where('carga_id', $carga->id)
            ->whereHas('folio', fn ($consulta) => $consulta->where('numero_folio', $numero))
            ->latest('asignado_at')
            ->firstOrFail();
    }

    /** @param  array<string, mixed>  $datos */
    private function registrarEvento(Carga $carga, TipoEventoCarga $tipo, User $usuario, Folio $folio, array $datos): void
    {
        EventoCarga::create([
            'carga_id' => $carga->id,
            'folio_id' => $folio->id,
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'datos' => ['version' => $carga->version, ...$datos],
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function transicionar(
        string $tipo,
        string $operacionId,
        User $usuario,
        array $payload,
        Closure $accion,
        string $sujetoTipo,
        string $sujetoId,
        ?string $referencia = null,
        ?Dispositivo $dispositivo = null,
        ?DateTimeInterface $ocurridoAt = null,
    ): CargaFolio {
        if (! Str::isUuid($operacionId)) {
            throw new DomainException('El identificador de la operación debe ser un UUID válido.');
        }

        return $this->motorTransiciones->ejecutar(new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Despacho,
            tipo: $tipo,
            usuario: $usuario,
            payload: $payload,
            operacionId: $operacionId,
            dispositivo: $dispositivo,
            sujetoTipo: $sujetoTipo,
            sujetoId: $sujetoId,
            referencia: $referencia,
            ocurridoAt: $ocurridoAt,
        ), $accion);
    }
}
