<?php

namespace App\Finanzas\Captura;

use App\Finanzas\Models\Hecho;
use Carbon\CarbonImmutable;

/**
 * Lo que una fuente leyó de la operación, antes de compararlo con la versión vigente.
 * La huella resume todo su contenido: si no cambia, no se crea una versión nueva.
 */
final class HechoCapturado
{
    /**
     * @param  array<string, int|float|string>  $medidas
     * @param  array<string, mixed>  $datos
     */
    public function __construct(
        public readonly string $origenId,
        public readonly string $estado,
        public readonly CarbonImmutable $ocurridoAt,
        public readonly ?CarbonImmutable $terminaAt = null,
        public readonly ?string $clienteId = null,
        public readonly ?string $clienteNombre = null,
        public readonly ?string $temporadaId = null,
        public readonly ?string $temporadaCodigo = null,
        public readonly ?string $referencia = null,
        public readonly array $medidas = [],
        public readonly array $datos = [],
    ) {}

    public function anulado(): bool
    {
        return $this->estado === Hecho::ESTADO_ANULADO;
    }

    public function fechaOperacional(): string
    {
        return $this->ocurridoAt->setTimezone((string) config('app.operational_timezone'))->toDateString();
    }

    public function huella(): string
    {
        return hash('sha256', json_encode([
            $this->estado,
            $this->clienteId,
            $this->clienteNombre,
            $this->temporadaId,
            $this->temporadaCodigo,
            $this->referencia,
            $this->ocurridoAt->utc()->format('Y-m-d H:i:s'),
            $this->terminaAt?->utc()->format('Y-m-d H:i:s'),
            self::ordenado($this->medidas),
            self::ordenado($this->datos),
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private static function ordenado(array $valores): array
    {
        ksort($valores);

        return array_map(
            fn (mixed $valor): mixed => is_array($valor) && ! array_is_list($valor) ? self::ordenado($valor) : $valor,
            $valores,
        );
    }
}
