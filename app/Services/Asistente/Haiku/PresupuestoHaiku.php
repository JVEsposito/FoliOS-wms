<?php

namespace App\Services\Asistente\Haiku;

use App\Models\ConfiguracionAsistente;
use App\Models\LlamadaIaAsistente;
use App\Models\User;
use App\Services\Asistente\PeriodoOperativo;
use Carbon\CarbonImmutable;

/**
 * Topes de Haiku y registro de cada llamada. Al llegar al tope del mes o al límite
 * diario del usuario, el asistente responde sin IA y dice por qué.
 */
class PresupuestoHaiku
{
    public const SIN_CLAVE = 'sin_clave';

    public const TOPE_MENSUAL = 'tope_mensual';

    public const LIMITE_DIARIO = 'limite_diario';

    public const FUERA_DEL_PILOTO = 'fuera_del_piloto';

    /** Costo máximo que se reserva antes de llamar, para no pasar el tope con la última pregunta. */
    private const RESERVA_USD = 0.002;

    public function __construct(private readonly ClienteAnthropic $cliente) {}

    /** Null si se puede llamar a Haiku; si no, el motivo. */
    public function impedimento(User $usuario): ?string
    {
        $configuracion = ConfiguracionAsistente::actual();

        return match (true) {
            ! $this->cliente->disponible() => self::SIN_CLAVE,
            ! $configuracion->enPiloto($usuario) => self::FUERA_DEL_PILOTO,
            $this->consumoMes() + self::RESERVA_USD > $configuracion->topeMensual() => self::TOPE_MENSUAL,
            $this->llamadasHoy($usuario) >= $configuracion->limiteDiario() => self::LIMITE_DIARIO,
            default => null,
        };
    }

    public function consumoMes(): float
    {
        $zona = config('app.operational_timezone');
        $inicio = CarbonImmutable::now($zona)->startOfMonth()->utc();

        return round((float) LlamadaIaAsistente::query()->where('created_at', '>=', $inicio)->sum('costo_usd'), 6);
    }

    /**
     * Consumo del mes calendario en la zona de la planta, por día y por usuario.
     *
     * @return array{usd: float, llamadas: int, errores: int, por_dia: list<array{fecha: string, llamadas: int, usd: float}>,
     *     por_usuario: list<array{usuario: string, llamadas: int, usd: float}>}
     */
    public function resumenMes(): array
    {
        $zona = config('app.operational_timezone');
        $inicio = CarbonImmutable::now($zona)->startOfMonth();
        $llamadas = LlamadaIaAsistente::query()->where('created_at', '>=', $inicio->utc())
            ->with('usuario:id,name')->get(['user_id', 'estado', 'costo_usd', 'created_at']);

        return [
            'usd' => round((float) $llamadas->sum('costo_usd'), 6),
            'llamadas' => $llamadas->count(),
            'errores' => $llamadas->where('estado', LlamadaIaAsistente::ERROR)->count(),
            'por_dia' => $llamadas->groupBy(fn (LlamadaIaAsistente $l): string => $l->created_at->setTimezone($zona)->toDateString())
                ->sortKeys()->map(fn ($grupo, string $fecha): array => ['fecha' => $fecha, 'llamadas' => $grupo->count(), 'usd' => round((float) $grupo->sum('costo_usd'), 6)])
                ->values()->all(),
            'por_usuario' => $llamadas->groupBy('user_id')
                ->map(fn ($grupo): array => ['usuario' => (string) ($grupo->first()->usuario?->name ?? '—'), 'llamadas' => $grupo->count(), 'usd' => round((float) $grupo->sum('costo_usd'), 6)])
                ->sortByDesc('llamadas')->values()->all(),
        ];
    }

    /** Llamadas del usuario en la jornada en curso (07:00 a 06:59). */
    public function llamadasHoy(User $usuario): int
    {
        $periodo = app(PeriodoOperativo::class);
        $hoy = $periodo->jornada();
        [$desde, $hasta] = $periodo->rango($hoy, $hoy);

        return LlamadaIaAsistente::query()->where('user_id', $usuario->id)
            ->where('created_at', '>=', $desde)->where('created_at', '<', $hasta)->count();
    }

    /** @param  array<string, mixed>  $uso  campo usage de la respuesta de la API */
    public static function costo(array $uso): float
    {
        $precio = config('asistente.precios');
        $tokens = fn (string $clave): int => (int) ($uso[$clave] ?? 0);

        return round((
            $tokens('input_tokens') * $precio['entrada']
            + $tokens('output_tokens') * $precio['salida']
            + $tokens('cache_read_input_tokens') * $precio['cache_lectura']
            + $tokens('cache_creation_input_tokens') * $precio['cache_escritura']
        ) / 1_000_000, 6);
    }

    /** @param  array<string, mixed>  $uso */
    public function registrar(User $usuario, string $estado, array $uso, int $duracionMs, ?string $herramienta = null, ?string $consulta = null, ?string $detalle = null): LlamadaIaAsistente
    {
        return LlamadaIaAsistente::query()->create([
            'user_id' => $usuario->id,
            'modelo' => $this->cliente->modelo(),
            'estado' => $estado,
            'herramienta' => $herramienta,
            'consulta' => $consulta,
            'tokens_entrada' => (int) ($uso['input_tokens'] ?? 0),
            'tokens_salida' => (int) ($uso['output_tokens'] ?? 0),
            'tokens_cache_lectura' => (int) ($uso['cache_read_input_tokens'] ?? 0),
            'tokens_cache_escritura' => (int) ($uso['cache_creation_input_tokens'] ?? 0),
            'costo_usd' => self::costo($uso),
            'duracion_ms' => $duracionMs,
            'detalle' => $detalle === null ? null : mb_substr($detalle, 0, 500),
            'created_at' => now(),
        ]);
    }
}
