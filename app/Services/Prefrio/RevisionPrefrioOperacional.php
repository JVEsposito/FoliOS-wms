<?php

namespace App\Services\Prefrio;

use App\Services\Jornadas\CalendarioOperativo;
use App\Services\Revisiones\RevisionBandejasOperacionales;

class RevisionPrefrioOperacional
{
    public function __construct(
        private readonly RevisionBandejasOperacionales $revisiones,
    ) {}

    public function tuneles(): string
    {
        return $this->huella([
            'revision' => $this->revisionActual(),
            'bandeja' => 'tuneles',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function procesos(array $filtros = []): string
    {
        ksort($filtros);

        return $this->huella([
            'revision' => $this->revisionActual(),
            'bandeja' => 'procesos',
            'calendario' => app(CalendarioOperativo::class)->revision(),
            'filtros' => $filtros,
        ]);
    }

    public function foliosElegibles(int $limite): string
    {
        return $this->huella([
            'revision' => $this->revisionActual(),
            'bandeja' => 'folios-elegibles',
            'limite' => $limite,
        ]);
    }

    private function revisionActual(): string
    {
        return $this->revisiones->obtener(RevisionBandejasOperacionales::PREFRIO);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function huella(array $datos): string
    {
        return hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
    }
}
