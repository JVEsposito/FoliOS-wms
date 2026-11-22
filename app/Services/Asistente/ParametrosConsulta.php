<?php

namespace App\Services\Asistente;

use Carbon\CarbonImmutable;

/**
 * Parámetros reconocidos o elegidos para una consulta. Las fechas son límites en UTC:
 * `desde` incluido y `hasta` excluido.
 */
final readonly class ParametrosConsulta
{
    public function __construct(
        public ?CarbonImmutable $desde = null,
        public ?CarbonImmutable $hasta = null,
        public ?string $clienteId = null,
        public ?string $csgId = null,
        public ?string $especieId = null,
        public ?string $codigo = null,
        public bool $temporadaAnterior = false,
    ) {}

    /** Rango por defecto: la jornada de hoy en la zona operativa. */
    public function rango(): array
    {
        if ($this->desde && $this->hasta) {
            return [$this->desde, $this->hasta];
        }
        $zona = config('app.operational_timezone');
        $hoy = CarbonImmutable::now($zona)->startOfDay();

        return [$this->desde ?? $hoy->utc(), $this->hasta ?? $hoy->addDay()->utc()];
    }

    /** @param  array<string, mixed>  $datos */
    public static function desdeArreglo(array $datos): self
    {
        $zona = config('app.operational_timezone');
        $fecha = fn (?string $valor, bool $fin): ?CarbonImmutable => $valor === null || $valor === ''
            ? null
            : ($fin
                ? CarbonImmutable::parse($valor, $zona)->startOfDay()->addDay()->utc()
                : CarbonImmutable::parse($valor, $zona)->startOfDay()->utc());

        return new self(
            desde: $fecha($datos['desde'] ?? null, false),
            hasta: $fecha($datos['hasta'] ?? null, true),
            clienteId: $datos['cliente_id'] ?? null,
            csgId: $datos['csg_id'] ?? null,
            especieId: $datos['especie_id'] ?? null,
            codigo: $datos['codigo'] ?? null,
            temporadaAnterior: (bool) ($datos['temporada_anterior'] ?? false),
        );
    }

    /** @return array<string, mixed> forma serializable, con fechas en la zona operativa */
    public function toArray(): array
    {
        $zona = config('app.operational_timezone');

        return array_filter([
            'desde' => $this->desde?->setTimezone($zona)->toDateString(),
            'hasta' => $this->hasta?->setTimezone($zona)->subDay()->toDateString(),
            'cliente_id' => $this->clienteId,
            'csg_id' => $this->csgId,
            'especie_id' => $this->especieId,
            'codigo' => $this->codigo,
            'temporada_anterior' => $this->temporadaAnterior ?: null,
        ], fn ($valor): bool => $valor !== null);
    }
}
