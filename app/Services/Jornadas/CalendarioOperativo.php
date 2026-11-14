<?php

namespace App\Services\Jornadas;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalendarioOperativo
{
    private ?Collection $reglas = null;

    public function zona(): string
    {
        return config('app.operational_timezone', 'America/Santiago');
    }

    public function sugerido(): object
    {
        return $this->reglas()->last() ?? (object) ['inicio_jornada' => '07:00', 'inicio_turno_b' => '19:00'];
    }

    public function reglas(): Collection
    {
        if ($this->reglas !== null) {
            return $this->reglas;
        }

        try {
            return $this->reglas = DB::table('calendarios_operativos')
                ->orderByDesc('vigente_desde')->orderByDesc('version')->get();
        } catch (QueryException $exception) {
            // Durante la migración la lectura puede seguir usando las reglas históricas.
            $codigo = $exception->errorInfo[1] ?? null;
            if ((int) $codigo === 1146 || ((int) $codigo === 1 && str_contains($exception->getMessage(), 'no such table: calendarios_operativos'))) {
                return $this->reglas = collect();
            }

            throw $exception;
        }
    }

    private function regla(string $fecha): ?object
    {
        return $this->reglas()->first(fn (object $regla): bool => $regla->vigente_desde !== null && $regla->vigente_desde <= $fecha);
    }

    private function cambiarDia(string $fecha, int $dias): string
    {
        return CarbonImmutable::parse($fecha, 'UTC')->addDays($dias)->toDateString();
    }

    private function horaLocal(string $fecha, string $hora, string $zona): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha.' '.$hora, $zona)->utc();
    }

    public function primeraVigencia(): ?CarbonImmutable
    {
        $regla = $this->reglas()->whereNotNull('vigente_desde')->last();
        if (! $regla) {
            return null;
        }

        return $this->inicio($regla->vigente_desde);
    }

    public function inicio(string $fecha, ?string $zonaHistorica = null, string $inicioHistorico = '00:00'): CarbonImmutable
    {
        $regla = $this->regla($fecha);

        return $regla
            ? $this->horaLocal($fecha, $regla->inicio_jornada, $this->zona())
            : $this->horaLocal($fecha, $inicioHistorico, $zonaHistorica ?? $this->zona());
    }

    private function inicioConsulta(string $fecha, ?string $zonaHistorica, string $inicioHistorico): CarbonImmutable
    {
        $primera = $this->reglas()->whereNotNull('vigente_desde')->last();
        if ($fecha === $primera?->vigente_desde) {
            return $this->horaLocal($fecha, $inicioHistorico, $zonaHistorica ?? $this->zona())->min($this->inicio($fecha));
        }

        return $this->inicio($fecha, $zonaHistorica, $inicioHistorico);
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} Límites UTC, el segundo es exclusivo. */
    public function rango(?string $desde, ?string $hasta, ?string $zonaHistorica = null, string $inicioHistorico = '00:00'): array
    {
        return [
            $desde ? $this->inicioConsulta($desde, $zonaHistorica, $inicioHistorico) : null,
            $hasta ? $this->inicioConsulta($this->cambiarDia($hasta, 1), $zonaHistorica, $inicioHistorico) : null,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public function rangoTurno(string $fecha, string $turno): ?array
    {
        $regla = $this->regla($fecha);
        if (! $regla) {
            return null;
        }

        $inicio = $this->inicio($fecha);
        $fin = $this->inicio($this->cambiarDia($fecha, 1));
        $diaB = $regla->inicio_turno_b < $regla->inicio_jornada ? $this->cambiarDia($fecha, 1) : $fecha;
        $cambio = $this->horaLocal($diaB, $regla->inicio_turno_b, $this->zona())->min($fin);

        return $turno === 'A' ? [$inicio, $cambio] : [$cambio, $fin];
    }

    public function filtrar(Builder|QueryBuilder $consulta, string $columna, ?string $desde, ?string $hasta, ?string $zonaHistorica = null): Builder|QueryBuilder
    {
        [$inicio, $fin] = $this->rango($desde, $hasta, $zonaHistorica);

        return $consulta
            ->when($inicio, fn ($q) => $q->where($columna, '>=', $inicio))
            ->when($fin, fn ($q) => $q->where($columna, '<', $fin));
    }

    public function filtrarHoy(Builder $consulta, string $columna, ?string $zonaHistorica = null): Builder
    {
        $fecha = $this->fecha(zonaHistorica: $zonaHistorica);

        return $this->filtrar($consulta, $columna, $fecha, $fecha, $zonaHistorica);
    }

    public function fecha(?DateTimeInterface $instante = null, ?string $zonaHistorica = null, string $inicioHistorico = '00:00'): string
    {
        $momento = $instante ? CarbonImmutable::instance($instante) : CarbonImmutable::now();
        $fechaPlanta = $momento->setTimezone($this->zona())->toDateString();
        $fecha = $fechaPlanta;

        if (! $this->regla($fechaPlanta) && $zonaHistorica !== null) {
            $fecha = $momento->setTimezone($zonaHistorica)->toDateString();
        }

        for ($dia = $this->cambiarDia($fecha, 1); $this->inicioConsulta($dia, $zonaHistorica, $inicioHistorico)->lte($momento); $dia = $this->cambiarDia($dia, 1)) {
            $fecha = $dia;
        }
        while ($this->inicioConsulta($fecha, $zonaHistorica, $inicioHistorico)->gt($momento)) {
            $fecha = $this->cambiarDia($fecha, -1);
        }

        return $fecha;
    }

    /** @return array{fecha_jornada: string, turno_operativo: ?string} */
    public function clasificar(DateTimeInterface $instante, ?string $zonaHistorica = null, string $inicioHistorico = '00:00'): array
    {
        $fecha = $this->fecha($instante, $zonaHistorica, $inicioHistorico);
        $regla = $this->regla($fecha);
        $turno = null;
        if ($regla && $this->primeraVigencia()->lte($instante)) {
            [, $cambio] = $this->rangoTurno($fecha, 'A');
            $turno = CarbonImmutable::instance($instante)->lt($cambio) ? 'A' : 'B';
        }

        return ['fecha_jornada' => $fecha, 'turno_operativo' => $turno];
    }

    /** @return array{fecha_jornada: ?string, turno_operativo: ?string} */
    public function datos(?DateTimeInterface $instante, ?string $zonaHistorica = null): array
    {
        return $instante ? $this->clasificar($instante, $zonaHistorica) : ['fecha_jornada' => null, 'turno_operativo' => null];
    }

    /** @return array{inicio: CarbonImmutable, fin: CarbonImmutable, nombre: string, fecha_jornada: string}|null */
    public function turnoActual(?CarbonImmutable $ahora = null): ?array
    {
        $jornada = $this->clasificar($ahora ?? CarbonImmutable::now());
        $turno = $jornada['turno_operativo'];
        if (! $turno) {
            return null;
        }

        [$inicio, $fin] = $this->rangoTurno($jornada['fecha_jornada'], $turno);

        return ['inicio' => $inicio, 'fin' => $fin, 'nombre' => $turno, 'fecha_jornada' => $jornada['fecha_jornada']];
    }

    public function revision(): int
    {
        return (int) ($this->reglas()->first()?->id ?? 0);
    }

    public function publicar(array $datos, int $usuarioId): void
    {
        DB::transaction(function () use ($datos, $usuarioId): void {
            DB::table('calendarios_operativos')->whereNull('vigente_desde')->lockForUpdate()->first();
            $ultima = DB::table('calendarios_operativos')->whereNotNull('vigente_desde')
                ->orderByDesc('vigente_desde')->orderByDesc('version')->lockForUpdate()->first();
            $fecha = $datos['vigente_desde'];
            $zona = $this->zona();
            $inicio = $this->horaLocal($fecha, $datos['inicio_jornada'], $zona);
            $limiteActual = $ultima ? $this->horaLocal($fecha, $ultima->inicio_jornada, $zona) : null;
            if ($inicio->lte(now()) || $limiteActual?->lte(now()) || ($ultima && $fecha < $ultima->vigente_desde)) {
                throw ValidationException::withMessages(['vigente_desde' => 'Selecciona una jornada futura, igual o posterior a la última fecha programada.']);
            }
            if ($inicio->setTimezone($zona)->format('H:i') !== $datos['inicio_jornada']) {
                throw ValidationException::withMessages(['inicio_jornada' => 'La hora indicada no existe en la fecha de vigencia por el cambio de hora.']);
            }
            if ((int) ($datos['version_conocida'] ?? 0) !== (int) ($ultima->id ?? 0)) {
                throw ValidationException::withMessages(['version_conocida' => 'Otro administrador cambió el calendario. Actualiza la pantalla y vuelve a guardar.']);
            }

            $anterior = DB::table('calendarios_operativos')->where('vigente_desde', '<', $fecha)
                ->orderByDesc('vigente_desde')->orderByDesc('version')->first();
            if ($anterior) {
                $fechaTurnoB = $anterior->inicio_turno_b < $anterior->inicio_jornada ? $fecha : $this->cambiarDia($fecha, -1);
                if ($inicio->lte($this->horaLocal($fechaTurnoB, $anterior->inicio_turno_b, $zona))) {
                    throw ValidationException::withMessages(['inicio_jornada' => 'El inicio debe dejar tiempo para completar el último turno B del horario anterior.']);
                }
            }

            DB::table('calendarios_operativos')->insert([
                'vigente_desde' => $fecha,
                'version' => $fecha === ($ultima->vigente_desde ?? null) ? $ultima->version + 1 : 1,
                'inicio_jornada' => $datos['inicio_jornada'],
                'inicio_turno_b' => $datos['inicio_turno_b'],
                'creado_por_user_id' => $usuarioId,
                'created_at' => now(),
            ]);
        });

        $this->reglas = null;
    }

    public function contexto(): array
    {
        $ahora = CarbonImmutable::now();
        $jornada = $this->clasificar($ahora);
        $regla = $jornada['turno_operativo'] ? $this->regla($jornada['fecha_jornada']) : null;
        $proxima = $this->reglas()->reverse()->first(fn (object $fila): bool => $fila->vigente_desde !== null
            && $this->inicio($fila->vigente_desde)->gt($ahora));
        if ($proxima) {
            $fechaProxima = $proxima->vigente_desde;
            $proxima = $this->regla($fechaProxima);
        }

        return [
            ...$jornada,
            'zona_horaria' => $this->zona(),
            'inicio_jornada' => $regla->inicio_jornada ?? null,
            'inicio_turno_b' => $regla->inicio_turno_b ?? null,
            'reglas' => $this->reglas()->whereNotNull('vigente_desde')->unique('vigente_desde')->values()
                ->map(fn (object $fila): array => [
                    ...$this->horario($fila),
                    'vigente_desde_at' => $this->inicio($fila->vigente_desde)->toISOString(),
                ])->all(),
            'proxima' => $proxima ? $this->horario($proxima) : null,
        ];
    }

    private function horario(object $regla): array
    {
        return [
            'vigente_desde' => $regla->vigente_desde,
            'inicio_jornada' => $regla->inicio_jornada,
            'inicio_turno_b' => $regla->inicio_turno_b,
        ];
    }
}
