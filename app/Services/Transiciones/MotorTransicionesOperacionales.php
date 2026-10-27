<?php

namespace App\Services\Transiciones;

use App\Enums\EstadoTransicionOperacional;
use App\Exceptions\ConflictoOperacion;
use App\Models\TransicionOperacional;
use Closure;
use DomainException;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MotorTransicionesOperacionales
{
    public function __construct(
        private readonly ContextoEjecucionTransicionOperacional $contexto,
        private readonly NormalizadorTransicionOperacional $normalizador,
    ) {}

    public function ejecutar(
        ComandoTransicionOperacional $comando,
        Closure $accion,
    ): mixed {
        $tipo = $this->validarTipo($comando->tipo);
        $this->validarOperacionId($comando->operacionId);
        $payload = $this->normalizador->normalizar($comando->payload);
        $payloadHash = $this->normalizador->hash($comando->payload);

        /**
         * @var array{
         *     valor?: mixed,
         *     error?: Throwable,
         *     repetida?: bool
         * } $resultado
         */
        try {
            $resultado = DB::transaction(function () use (
                $comando,
                $tipo,
                $payload,
                $payloadHash,
                $accion,
            ): array {
                $existente = $this->buscarExistente($comando, $tipo);

                if ($existente) {
                    $this->validarRepeticion($existente, $comando, $payloadHash);

                    if ($existente->estado === EstadoTransicionOperacional::Aplicada) {
                        return ['repetida' => true];
                    }

                    // Un fallo técnico histórico no es una nueva excepción de MySQL.
                    // Reemitir su SQLSTATE hace que Laravel lo confunda con otro deadlock
                    // y evita que el cliente reciba el rechazo definitivo del UUID.
                    $mensaje = $existente->error_mensaje;
                    if ($existente->estado === EstadoTransicionOperacional::Fallida
                        || Str::contains((string) $mensaje, ['SQLSTATE[', 'Deadlock found'])) {
                        $mensaje = 'El intento anterior terminó con un fallo técnico. Comprueba el resultado antes de registrar un nuevo intento.';
                    }
                    throw new ConflictoOperacion(
                        $mensaje ?: 'La transición ya fue procesada y no puede reintentarse con el mismo UUID.',
                    );
                }

                $transicion = TransicionOperacional::create([
                    ...$this->atributosBase($comando, $tipo, $payload, $payloadHash),
                    'estado' => EstadoTransicionOperacional::Procesando,
                    'cantidad_cambios' => 0,
                ]);

                try {
                    $valor = DB::transaction(
                        fn (): mixed => $this->contexto->ejecutar($transicion, $accion),
                    );
                } catch (DomainException $exception) {
                    $transicion->update([
                        'estado' => EstadoTransicionOperacional::Rechazada,
                        'error_tipo' => $exception::class,
                        'error_codigo' => 'regla_dominio',
                        'error_mensaje' => Str::limit($exception->getMessage(), 65000, ''),
                        'finalizado_at' => now(),
                    ]);

                    return ['error' => $exception];
                }

                $transicion->update([
                    'estado' => EstadoTransicionOperacional::Aplicada,
                    'resultado' => $this->resumirResultado($valor),
                    'error_tipo' => null,
                    'error_codigo' => null,
                    'error_mensaje' => null,
                    'finalizado_at' => now(),
                ]);

                return ['valor' => $valor];
            }, attempts: 3);
        } catch (Throwable $exception) {
            // La transacción exterior es quien puede reintentar el comando completo.
            // No escribir una transición final para una operación que MySQL revirtió.
            if (app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                throw $exception;
            }
            $this->registrarFallo(
                $comando,
                $tipo,
                $payload,
                $payloadHash,
                $exception,
            );

            throw $exception;
        }

        if ($resultado['repetida'] ?? false) {
            // Los comandos con UUID se conectan únicamente a servicios que ya son
            // idempotentes. Reejecutarlos permite rehidratar su modelo de respuesta
            // sin crear una segunda transición ni repetir cambios de negocio.
            return $accion();
        }

        if (isset($resultado['error'])) {
            throw $resultado['error'];
        }

        return $resultado['valor'];
    }

    private function registrarFallo(
        ComandoTransicionOperacional $comando,
        string $tipo,
        array $payload,
        string $payloadHash,
        Throwable $exception,
    ): void {
        try {
            DB::transaction(function () use (
                $comando,
                $tipo,
                $payload,
                $payloadHash,
                $exception,
            ): void {
                if ($this->buscarExistente($comando, $tipo)) {
                    return;
                }

                TransicionOperacional::create([
                    ...$this->atributosBase($comando, $tipo, $payload, $payloadHash),
                    'estado' => EstadoTransicionOperacional::Fallida,
                    'error_tipo' => $exception::class,
                    'error_codigo' => 'fallo_no_controlado',
                    'error_mensaje' => Str::limit($exception->getMessage(), 65000, ''),
                    'cantidad_cambios' => 0,
                    'finalizado_at' => now(),
                ]);
            }, attempts: 3);
        } catch (Throwable $errorAuditoria) {
            report($errorAuditoria);
        }
    }

    private function buscarExistente(
        ComandoTransicionOperacional $comando,
        string $tipo,
    ): ?TransicionOperacional {
        return $comando->operacionId
            ? TransicionOperacional::query()
                ->where('dominio', $comando->dominio->value)
                ->where('tipo', $tipo)
                ->where('operacion_id', $comando->operacionId)
                ->lockForUpdate()
                ->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function atributosBase(
        ComandoTransicionOperacional $comando,
        string $tipo,
        array $payload,
        string $payloadHash,
    ): array {
        return [
            'dominio' => $comando->dominio,
            'tipo' => $tipo,
            'operacion_id' => $comando->operacionId,
            'sujeto_tipo' => $comando->sujetoTipo,
            'sujeto_id' => $comando->sujetoId,
            'referencia' => $this->textoOpcional($comando->referencia),
            'user_id' => $comando->usuario->id,
            'dispositivo_id' => $comando->dispositivo?->id,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'ocurrido_at' => $comando->ocurridoAt ?? now(),
        ];
    }

    private function validarRepeticion(
        TransicionOperacional $existente,
        ComandoTransicionOperacional $comando,
        string $payloadHash,
    ): void {
        $mismaSolicitud = $existente->user_id === $comando->usuario->id
            && $existente->dispositivo_id === $comando->dispositivo?->id
            && hash_equals($existente->payload_hash, $payloadHash);

        if (! $mismaSolicitud) {
            throw new ConflictoOperacion(
                'El UUID de transición ya fue utilizado con datos o actor diferentes.',
            );
        }
    }

    private function validarOperacionId(?string $operacionId): void
    {
        if ($operacionId !== null && ! Str::isUuid($operacionId)) {
            throw new DomainException(
                'El identificador de la transición operacional debe ser un UUID válido.',
            );
        }
    }

    private function validarTipo(string $tipo): string
    {
        $normalizado = Str::lower(trim($tipo));
        if ($normalizado === ''
            || strlen($normalizado) > 100
            || preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $normalizado) !== 1) {
            throw new DomainException('El tipo de transición operacional no es válido.');
        }

        return $normalizado;
    }

    /** @return array<string, mixed> */
    private function resumirResultado(mixed $resultado): array
    {
        if ($resultado instanceof Model) {
            return [
                'modelo' => $resultado::class,
                'id' => (string) $resultado->getKey(),
            ];
        }

        if (is_array($resultado)) {
            return ['datos' => $this->normalizador->normalizar($resultado)];
        }

        if (is_bool($resultado) || is_int($resultado) || is_float($resultado)
            || is_string($resultado) || $resultado === null) {
            return ['valor' => $resultado];
        }

        return ['tipo' => get_debug_type($resultado)];
    }

    private function textoOpcional(?string $valor): ?string
    {
        $texto = Str::of((string) $valor)->squish()->toString();

        return $texto === '' ? null : Str::limit($texto, 160, '');
    }
}
