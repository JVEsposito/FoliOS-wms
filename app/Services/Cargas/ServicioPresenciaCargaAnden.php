<?php

namespace App\Services\Cargas;

use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoCarga;
use App\Enums\EstadoPresenciaCargaAnden;
use App\Enums\TipoEventoCarga;
use App\Exceptions\ConflictoOperacion;
use App\Models\Anden;
use App\Models\Carga;
use App\Models\EventoCarga;
use App\Models\PresenciaCargaAnden;
use App\Models\ProcesoPrefrio;
use App\Models\User;
use App\Services\Prefrio\ServicioGeneracionRecepcionTunel;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use App\Services\Transiciones\NormalizadorTransicionOperacional;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

class ServicioPresenciaCargaAnden
{
    public function __construct(
        private readonly MotorTransicionesOperacionales $transiciones,
        private readonly NormalizadorTransicionOperacional $normalizador,
        private readonly ServicioPlanDespachoDirecto $planificador,
        private readonly ServicioGeneracionRecepcionTunel $recepcionTunel,
    ) {}

    /** @param array<string, mixed> $datos */
    public function registrar(Carga $carga, array $datos, User $usuario): PresenciaCargaAnden
    {
        $operacionId = (string) $datos['operacion_id'];
        $ingresadaAtInformada = isset($datos['ingresada_at']);
        $ingresadaAt = $ingresadaAtInformada
            ? CarbonImmutable::parse($datos['ingresada_at'])
            : CarbonImmutable::now();
        $patente = Str::upper(Str::squish((string) $datos['patente']));
        $conductor = $this->textoOpcional($datos['conductor'] ?? null);
        $observacion = $this->textoOpcional($datos['observacion'] ?? null);
        $payload = [
            'carga_id' => $carga->id,
            'version_esperada' => (int) $datos['version_esperada'],
            'anden_id' => (string) $datos['anden_id'],
            'patente' => $patente,
            'conductor' => $conductor,
            'observacion' => $observacion,
            'ingresada_at' => $ingresadaAtInformada
                ? $ingresadaAt->toAtomString()
                : null,
            'usuario_id' => $usuario->id,
        ];
        $payloadHash = $this->normalizador->hash($payload);

        $accion = function () use (
            $carga,
            $datos,
            $usuario,
            $operacionId,
            $ingresadaAt,
            $patente,
            $conductor,
            $observacion,
            $payloadHash,
        ): PresenciaCargaAnden {
            $existente = PresenciaCargaAnden::query()
                ->where('operacion_ingreso_id', $operacionId)
                ->lockForUpdate()
                ->first();
            if ($existente) {
                if ($existente->carga_id !== $carga->id
                    || ! hash_equals($existente->ingreso_payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de ingreso al andén ya fue utilizado con datos diferentes.',
                    );
                }

                return $existente;
            }

            $carga = Carga::query()->with('temporada')->lockForUpdate()->findOrFail($carga->id);
            $this->asegurarVersion($carga, (int) $datos['version_esperada']);
            if (! $carga->temporada?->activa
                || ! in_array($carga->estado, EstadoCarga::visiblesEnOperacion(), true)) {
                throw new DomainException('La carga no está publicada en la temporada activa.');
            }

            $anden = Anden::query()
                ->whereKey($datos['anden_id'])
                ->where('activo', true)
                ->lockForUpdate()
                ->first();
            if (! $anden) {
                throw new DomainException('El andén indicado no existe o se encuentra inactivo.');
            }
            if (PresenciaCargaAnden::query()
                ->where('bloqueo_carga_id', $carga->id)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion('La carga ya posee un camión presente en un andén.');
            }
            if (PresenciaCargaAnden::query()
                ->where('bloqueo_anden_id', $anden->id)
                ->lockForUpdate()
                ->exists()) {
                throw new ConflictoOperacion('El andén ya está ocupado por otra carga.');
            }

            try {
                $presencia = PresenciaCargaAnden::create([
                    'carga_id' => $carga->id,
                    'anden_id' => $anden->id,
                    'bloqueo_carga_id' => $carga->id,
                    'bloqueo_anden_id' => $anden->id,
                    'estado' => EstadoPresenciaCargaAnden::Activa,
                    'operacion_ingreso_id' => $operacionId,
                    'ingreso_payload_hash' => $payloadHash,
                    'patente' => $patente,
                    'conductor' => $conductor,
                    'observacion_ingreso' => $observacion,
                    'ingresada_por_user_id' => $usuario->id,
                    'ingresada_at' => $ingresadaAt,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new ConflictoOperacion(
                    'La carga o el andén fueron ocupados por otra operación concurrente.',
                    previous: $exception,
                );
            }

            $carga->update([
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
            $this->evento($carga, TipoEventoCarga::CamionEnAnden, $usuario, [
                'presencia_id' => $presencia->id,
                'anden_id' => $anden->id,
                'patente' => $patente,
                'conductor' => $conductor,
                'ingresada_at' => $ingresadaAt->toAtomString(),
                'coincide_anden_previsto' => $carga->anden_previsto_id === $anden->id,
            ]);

            $this->planificador->sincronizar($presencia, $usuario);

            return $presencia->refresh();
        };

        return $this->transicionar(
            'carga.camion_en_anden',
            $operacionId,
            $usuario,
            $payload,
            $accion,
            $carga,
            $ingresadaAt,
        );
    }

    /** @param array<string, mixed> $datos */
    public function finalizar(Carga $carga, array $datos, User $usuario): PresenciaCargaAnden
    {
        $operacionId = (string) $datos['operacion_id'];
        $motivo = Str::squish((string) $datos['motivo']);
        $payload = [
            'carga_id' => $carga->id,
            'version_esperada' => (int) $datos['version_esperada'],
            'motivo' => $motivo,
            'usuario_id' => $usuario->id,
        ];
        $payloadHash = $this->normalizador->hash($payload);

        $accion = function () use (
            $carga,
            $datos,
            $usuario,
            $operacionId,
            $motivo,
            $payloadHash,
        ): PresenciaCargaAnden {
            $existente = PresenciaCargaAnden::query()
                ->where('operacion_salida_id', $operacionId)
                ->lockForUpdate()
                ->first();
            if ($existente) {
                if ($existente->carga_id !== $carga->id
                    || ! hash_equals((string) $existente->salida_payload_hash, $payloadHash)) {
                    throw new ConflictoOperacion(
                        'El UUID de liberación del andén ya fue utilizado con datos diferentes.',
                    );
                }

                return $existente;
            }

            $carga = Carga::query()->lockForUpdate()->findOrFail($carga->id);
            $this->asegurarVersion($carga, (int) $datos['version_esperada']);
            $presencia = PresenciaCargaAnden::query()
                ->where('bloqueo_carga_id', $carga->id)
                ->lockForUpdate()
                ->first();
            if (! $presencia) {
                throw new DomainException('La carga no posee un camión activo en andén.');
            }

            $this->cerrarPresencia(
                $presencia,
                $carga,
                $usuario,
                $motivo,
                $operacionId,
                $payloadHash,
            );

            return $presencia->refresh();
        };

        return $this->transicionar(
            'carga.camion_fuera_anden',
            $operacionId,
            $usuario,
            $payload,
            $accion,
            $carga,
        );
    }

    public function finalizarPorCierre(Carga $carga, User $usuario, string $operacionId): void
    {
        $presencia = PresenciaCargaAnden::query()
            ->where('bloqueo_carga_id', $carga->id)
            ->lockForUpdate()
            ->first();
        if (! $presencia) {
            return;
        }

        $motivo = 'Salida del camión confirmada y despacho cerrado.';
        $payload = [
            'carga_id' => $carga->id,
            'motivo' => $motivo,
            'usuario_id' => $usuario->id,
        ];
        $this->cerrarPresencia(
            $presencia,
            $carga,
            $usuario,
            $motivo,
            $operacionId,
            $this->normalizador->hash($payload),
            false,
        );
    }

    private function cerrarPresencia(
        PresenciaCargaAnden $presencia,
        Carga $carga,
        User $usuario,
        string $motivo,
        string $operacionId,
        string $payloadHash,
        bool $incrementarVersionCarga = true,
    ): void {
        $this->planificador->cancelar($presencia, $usuario, $motivo);
        $presencia->update([
            'estado' => EstadoPresenciaCargaAnden::Finalizada,
            'bloqueo_carga_id' => null,
            'bloqueo_anden_id' => null,
            'operacion_salida_id' => $operacionId,
            'salida_payload_hash' => $payloadHash,
            'motivo_finalizacion' => $motivo,
            'finalizada_por_user_id' => $usuario->id,
            'finalizada_at' => now(),
        ]);
        // Si el prefrío se aprobó mientras estaba el camión, todavía no había
        // una tarea de recepción que restaurar. Publicarla ahora para los
        // pallets que permanecen físicamente en el túnel.
        $procesos = ProcesoPrefrio::query()
            ->where('estado', 'aprobado')
            ->whereHas('folios', fn ($folios) => $folios
                ->whereHas('folio.asignacionCargaActual', fn ($asignacion) => $asignacion
                    ->where('carga_id', $carga->id)))
            ->get();
        foreach ($procesos as $proceso) {
            $this->recepcionTunel->generar($proceso, $usuario);
        }
        if ($incrementarVersionCarga) {
            $carga->update([
                'version' => $carga->version + 1,
                'actualizada_por_user_id' => $usuario->id,
            ]);
        }
        $this->evento($carga, TipoEventoCarga::CamionFueraAnden, $usuario, [
            'presencia_id' => $presencia->id,
            'anden_id' => $presencia->anden_id,
            'patente' => $presencia->patente,
            'motivo' => $motivo,
        ]);
    }

    private function asegurarVersion(Carga $carga, int $versionEsperada): void
    {
        if ($carga->version !== $versionEsperada) {
            throw new ConflictoOperacion(sprintf(
                'La carga %s cambió en otra sesión. Se esperaba la versión %d y la actual es %d.',
                $carga->codigo,
                $versionEsperada,
                $carga->version,
            ));
        }
    }

    /** @param array<string, mixed> $datos */
    private function evento(Carga $carga, TipoEventoCarga $tipo, User $usuario, array $datos): void
    {
        EventoCarga::create([
            'carga_id' => $carga->id,
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'datos' => ['version' => $carga->version, ...$datos],
        ]);
    }

    private function transicionar(
        string $tipo,
        string $operacionId,
        User $usuario,
        array $payload,
        Closure $accion,
        Carga $carga,
        ?DateTimeInterface $ocurridoAt = null,
    ): mixed {
        return $this->transiciones->ejecutar(
            new ComandoTransicionOperacional(
                dominio: DominioTransicionOperacional::Despacho,
                tipo: $tipo,
                usuario: $usuario,
                payload: $payload,
                operacionId: $operacionId,
                sujetoTipo: Carga::class,
                sujetoId: (string) $carga->id,
                referencia: $carga->codigo,
                ocurridoAt: $ocurridoAt,
            ),
            $accion,
        );
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = Str::squish((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
