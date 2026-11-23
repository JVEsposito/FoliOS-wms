<?php

namespace App\Services\Asistente;

use Carbon\CarbonImmutable;

/**
 * Parámetros reconocidos o elegidos para una consulta. Las fechas son límites en UTC:
 * `desde` incluido y `hasta` excluido, siempre alineados a jornadas operativas (o a un
 * turno cuando `turno` viene). Sin fechas, el periodo es la jornada en curso.
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
        public ?string $turno = null,
        public ?string $etiquetaPeriodo = null,
        public bool $periodoExplicito = false,
        public ?string $agrupar = null,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function rango(): array
    {
        if ($this->desde && $this->hasta) {
            return [$this->desde, $this->hasta];
        }
        $periodo = app(PeriodoOperativo::class);
        $hoy = $periodo->jornada();
        [$desde, $hasta] = $this->turno ? $periodo->rangoTurno($hoy, $this->turno) : $periodo->rango($hoy, $hoy);

        return [$this->desde ?? $desde, $this->hasta ?? $hasta];
    }

    /** @return array{0: string, 1: string} primera y última jornada (Y-m-d) del periodo */
    public function jornadas(): array
    {
        [$desde, $hasta] = $this->rango();
        $periodo = app(PeriodoOperativo::class);

        return [$periodo->jornada($desde), $periodo->jornada($hasta->subSecond())];
    }

    /** Descripción para la línea «Entendí», por ejemplo «Jornada 21-11-2026 · turno B». */
    public function descripcionPeriodo(): string
    {
        if ($this->etiquetaPeriodo !== null) {
            return $this->etiquetaPeriodo;
        }
        [$primera, $ultima] = $this->jornadas();
        $texto = $primera === $ultima
            ? 'Jornada '.PeriodoOperativo::fechaLegible($primera)
            : 'Jornadas '.PeriodoOperativo::fechaLegible($primera).' al '.PeriodoOperativo::fechaLegible($ultima);

        return $this->turno ? "{$texto} · turno {$this->turno}" : $texto;
    }

    /** @param  array<string, mixed>  $datos */
    public static function desdeArreglo(array $datos): self
    {
        $periodo = app(PeriodoOperativo::class);
        $desde = $datos['desde'] ?? null;
        $hasta = $datos['hasta'] ?? $desde;
        $desde ??= $hasta;
        $turno = in_array($datos['turno'] ?? null, ['A', 'B'], true) ? $datos['turno'] : null;
        $limites = [null, null];
        if ($desde !== null) {
            $limites = $turno !== null && $desde === $hasta ? $periodo->rangoTurno($desde, $turno) : $periodo->rango($desde, $hasta);
            $turno = $desde === $hasta ? $turno : null;
        }

        return new self(
            desde: $limites[0],
            hasta: $limites[1],
            clienteId: $datos['cliente_id'] ?? null,
            csgId: $datos['csg_id'] ?? null,
            especieId: $datos['especie_id'] ?? null,
            codigo: $datos['codigo'] ?? null,
            temporadaAnterior: (bool) ($datos['temporada_anterior'] ?? false),
            turno: $turno,
            etiquetaPeriodo: isset($datos['periodo']) && is_string($datos['periodo']) ? mb_substr($datos['periodo'], 0, 80) : null,
            periodoExplicito: $desde !== null || (bool) ($datos['temporada_anterior'] ?? false),
            agrupar: ($datos['agrupar'] ?? null) === 'dia' ? 'dia' : null,
        );
    }

    /** @return array<string, mixed> forma serializable, con jornadas en la zona operativa */
    public function toArray(): array
    {
        [$desde, $hasta] = $this->desde && $this->hasta ? $this->jornadas() : [null, null];

        return array_filter([
            'desde' => $desde,
            'hasta' => $hasta,
            'turno' => $this->turno,
            'periodo' => $this->etiquetaPeriodo,
            'cliente_id' => $this->clienteId,
            'csg_id' => $this->csgId,
            'especie_id' => $this->especieId,
            'codigo' => $this->codigo,
            'temporada_anterior' => $this->temporadaAnterior ?: null,
            'agrupar' => $this->agrupar,
        ], fn ($valor): bool => $valor !== null);
    }
}
