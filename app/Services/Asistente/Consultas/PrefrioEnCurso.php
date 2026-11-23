<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoProcesoPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Procesos de prefrío iniciados que siguen corriendo, con minutos transcurridos contra su objetivo. */
class PrefrioEnCurso extends ConsultaBase
{
    public function clave(): string
    {
        return 'prefrio-en-curso';
    }

    public function titulo(): string
    {
        return 'Prefrío en curso';
    }

    public function descripcion(): string
    {
        return 'Túneles con proceso en curso, minutos que llevan y cuáles pasaron su objetivo.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['atrasados', 'atrasado', 'atraso', 'curso', 'corriendo', 'tuneles', 'tunel', 'enfriando'];
    }

    public function palabrasSecundarias(): array
    {
        return ['prefrio', 'proceso', 'procesos'];
    }

    public function filtros(): array
    {
        return [];
    }

    public function admiteRecomendacion(): bool
    {
        return true;
    }

    public function capacidades(): array
    {
        return TunelPrefrio::query()->where('estado_administrativo', 'activo')->orderBy('nombre')->get(['nombre', 'capacidad_posiciones', 'setpoint_habitual', 'estado_tecnico'])
            ->map(fn (TunelPrefrio $t): array => ['nombre' => "{$t->nombre}: posiciones", 'valor' => (int) $t->capacidad_posiciones])
            ->all();
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarPrefrio($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        $procesos = $temporada === null ? collect() : ProcesoPrefrio::query()
            ->where('temporada_id', $temporada->id)
            ->where('estado', EstadoProcesoPrefrio::EnProceso->value)
            ->whereNotNull('iniciado_at')
            ->with('tunel:id,nombre')
            ->orderBy('iniciado_at')
            ->get(['id', 'codigo', 'tunel_prefrio_id', 'iniciado_at', 'duracion_objetivo_minutos']);

        if ($procesos->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay procesos de prefrío en curso.', '/oficina/prefrio');
        }

        $ahora = now();
        $filas = $procesos->map(function (ProcesoPrefrio $proceso) use ($ahora): array {
            $minutos = self::minutos($proceso->iniciado_at->diffInMinutes($ahora, true));
            $objetivo = $proceso->duracion_objetivo_minutos !== null ? (int) $proceso->duracion_objetivo_minutos : null;

            return [
                'tunel' => $proceso->tunel?->nombre ?? '—',
                'proceso' => $proceso->codigo,
                'minutos' => $minutos,
                'objetivo' => $objetivo,
                'atraso' => $objetivo !== null && $minutos > $objetivo ? $minutos - $objetivo : 0,
            ];
        })->sortByDesc('atraso')->values();
        $atrasados = $filas->where('atraso', '>', 0)->count();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf(
                'Hay %d %s de prefrío en curso; %s.',
                $filas->count(), $filas->count() === 1 ? 'proceso' : 'procesos',
                match ($atrasados) {
                    0 => 'ninguno pasó su objetivo', 1 => '1 pasó su objetivo', default => "{$atrasados} pasaron su objetivo"
                },
            ),
            [ResultadoConsulta::cifra('En curso', $filas->count()), ResultadoConsulta::cifra('Pasados del objetivo', $atrasados)],
            [
                ['clave' => 'tunel', 'titulo' => 'Túnel'],
                ['clave' => 'proceso', 'titulo' => 'Proceso', 'tipo' => 'codigo'],
                ['clave' => 'minutos', 'titulo' => 'Minutos', 'tipo' => 'numero'],
                ['clave' => 'objetivo', 'titulo' => 'Objetivo (min)', 'tipo' => 'numero'],
                ['clave' => 'atraso', 'titulo' => 'Sobre el objetivo (min)', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/prefrio',
        );
    }
}
