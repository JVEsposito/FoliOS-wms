<?php

namespace App\Services\Asistente\Consultas;

use App\Models\ProcesoPrefrio;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Duración de los procesos de prefrío finalizados contra su objetivo. */
class TiemposPrefrio extends ConsultaBase
{
    public function clave(): string
    {
        return 'tiempos-prefrio';
    }

    public function titulo(): string
    {
        return 'Tiempos de prefrío';
    }

    public function descripcion(): string
    {
        return 'Duración de los procesos finalizados contra su objetivo, por túnel.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['prefrio', 'tunel', 'tuneles', 'proceso', 'procesos', 'enfriado', 'enfriamiento', 'objetivo', 'tiempo', 'tiempos', 'demora'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarPrefrio($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $procesos = $temporada === null ? collect() : ProcesoPrefrio::query()
            ->where('temporada_id', $temporada->id)
            ->whereNotNull('iniciado_at')
            ->where('finalizado_at', '>=', $desde)->where('finalizado_at', '<', $hasta)
            ->with('tunel:id,nombre')
            ->get(['id', 'codigo', 'tunel_prefrio_id', 'iniciado_at', 'finalizado_at', 'duracion_objetivo_minutos']);

        if ($procesos->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay procesos de prefrío finalizados el {$this->periodo($parametros)}.", '/oficina/prefrio');
        }

        $filas = $procesos->map(function (ProcesoPrefrio $proceso): array {
            $minutos = self::minutos($proceso->iniciado_at->diffInMinutes($proceso->finalizado_at, true));
            $objetivo = $proceso->duracion_objetivo_minutos !== null ? (int) $proceso->duracion_objetivo_minutos : null;

            return [
                'codigo' => $proceso->codigo,
                'tunel' => $proceso->tunel?->nombre ?? '—',
                'minutos' => $minutos,
                'objetivo' => $objetivo,
                'exceso' => $objetivo !== null ? max(0, $minutos - $objetivo) : null,
            ];
        })->sortByDesc('minutos')->values();
        $sobreObjetivo = $filas->filter(fn (array $fila): bool => ($fila['exceso'] ?? 0) > 0)->count();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s finalizaron %d procesos con %d min de promedio; %d pasaron su objetivo.', $this->periodo($parametros), $filas->count(), self::minutos($filas->avg('minutos')), $sobreObjetivo),
            [
                ResultadoConsulta::cifra('Procesos', $filas->count()),
                ResultadoConsulta::cifra('Duración promedio', self::minutos($filas->avg('minutos')), 'min'),
                ResultadoConsulta::cifra('Sobre objetivo', $sobreObjetivo),
            ],
            [
                ['clave' => 'codigo', 'titulo' => 'Proceso', 'tipo' => 'codigo'],
                ['clave' => 'tunel', 'titulo' => 'Túnel'],
                ['clave' => 'minutos', 'titulo' => 'Minutos', 'tipo' => 'numero'],
                ['clave' => 'objetivo', 'titulo' => 'Objetivo', 'tipo' => 'numero'],
                ['clave' => 'exceso', 'titulo' => 'Exceso', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/prefrio',
        );
    }
}
