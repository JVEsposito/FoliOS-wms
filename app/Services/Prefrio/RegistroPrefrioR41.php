<?php

namespace App\Services\Prefrio;

use App\Enums\EstadoFolioProcesoPrefrio;
use App\Enums\EstadoProcesoPrefrio;
use App\Enums\TipoEventoPrefrio;
use App\Models\EventoPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Services\Jornadas\CalendarioOperativo;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Registro de prefrío POEMP R-41.
 *
 * Cada lectura del túnel toma tres sectores (ventilador, medio y puerta), con sensor exterior
 * e interior a cada lado: doce temperaturas. Las lecturas horarias pueden quedar parciales;
 * al terminar el enfriado se exige una lectura completa, que puede ser la última horaria o
 * la que acompaña el envío a verificación.
 */
class RegistroPrefrioR41
{
    /** @var array<string, string> */
    public const SECTORES = ['ventilador' => 'Ventilador', 'medio' => 'Medio', 'puerta' => 'Puerta'];

    /**
     * Sensores de cada sector, en el orden de la planilla: izquierda exterior e interior,
     * derecha interior y exterior.
     *
     * @var array<string, string>
     */
    public const PUNTOS = ['izq_ex' => 'izq. exterior', 'izq_in' => 'izq. interior', 'der_in' => 'der. interior', 'der_ex' => 'der. exterior'];

    public const TEMPERATURA_MINIMA = -10.0;

    public const TEMPERATURA_MAXIMA = 40.0;

    /** @var array<int, string> */
    public const DATOS_PLANILLA = ['temperatura_objetivo', 'supervisor', 'jefe_frio', 'servicio'];

    /**
     * Valida y completa una lectura por sectores. Cada valor es una temperatura en °C con un
     * decimal o null si no se midió.
     *
     * @return array<string, array<string, float|null>>
     */
    public static function normalizar(mixed $sectores, bool $exigirCompleta = false, string $campo = 'sectores'): array
    {
        if (! is_array($sectores)) {
            throw ValidationException::withMessages([$campo => 'La lectura debe indicar las temperaturas por sector.']);
        }
        $desconocidos = array_diff(array_keys($sectores), array_keys(self::SECTORES));
        if ($desconocidos !== []) {
            throw ValidationException::withMessages([$campo => 'Los sectores de la lectura son ventilador, medio y puerta.']);
        }
        $lectura = [];
        foreach (self::SECTORES as $sector => $nombre) {
            $valores = $sectores[$sector] ?? [];
            if (! is_array($valores) || array_diff(array_keys($valores), array_keys(self::PUNTOS)) !== []) {
                throw ValidationException::withMessages([$campo => "El sector {$nombre} lleva sensores izquierdo y derecho, exterior e interior."]);
            }
            foreach (self::PUNTOS as $punto => $etiqueta) {
                $valor = $valores[$punto] ?? null;
                if ($valor === null || $valor === '') {
                    $lectura[$sector][$punto] = null;

                    continue;
                }
                $numero = is_string($valor) ? str_replace(',', '.', trim($valor)) : $valor;
                if (! is_numeric($numero) || (float) $numero < self::TEMPERATURA_MINIMA || (float) $numero > self::TEMPERATURA_MAXIMA) {
                    throw ValidationException::withMessages([$campo => sprintf('%s %s: la temperatura debe estar entre %d y %d °C.',
                        $nombre, $etiqueta, (int) self::TEMPERATURA_MINIMA, (int) self::TEMPERATURA_MAXIMA)]);
                }
                $lectura[$sector][$punto] = round((float) $numero, 1);
            }
        }
        $faltantes = self::faltantes($lectura);
        if (count($faltantes) === count(self::SECTORES) * count(self::PUNTOS)) {
            throw ValidationException::withMessages([$campo => 'Ingresa al menos una temperatura de la lectura.']);
        }
        if ($exigirCompleta && $faltantes !== []) {
            throw ValidationException::withMessages([$campo => 'La lectura final lleva los 3 sectores, interior y exterior de ambos lados. Falta: '.implode(', ', $faltantes).'.']);
        }

        return $lectura;
    }

    /** @param  array<string, mixed>|null  $sectores */
    public static function completa(?array $sectores): bool
    {
        return $sectores !== null && self::faltantes($sectores) === [];
    }

    /**
     * @param  array<string, mixed>  $sectores
     * @return array<int, string> Sensores sin temperatura, en palabras.
     */
    public static function faltantes(array $sectores): array
    {
        $faltantes = [];
        foreach (self::SECTORES as $sector => $nombre) {
            foreach (self::PUNTOS as $punto => $etiqueta) {
                if (! is_numeric($sectores[$sector][$punto] ?? null)) {
                    $faltantes[] = "{$nombre} {$etiqueta}";
                }
            }
        }

        return $faltantes;
    }

    /**
     * Lecturas del proceso en orden, incluida la lectura final que acompañó el envío a
     * verificación. Las lecturas antiguas de una sola temperatura conservan ese valor.
     *
     * @param  Collection<int, EventoPrefrio>  $eventos
     * @return Collection<int, array{id: string, ocurrido_at: CarbonImmutable, sectores: ?array, temperatura: ?float, final: bool, observacion: ?string, usuario: ?string}>
     */
    public static function lecturas(Collection $eventos): Collection
    {
        return $eventos
            ->filter(fn (EventoPrefrio $evento): bool => $evento->tipo === TipoEventoPrefrio::Lectura
                || ($evento->tipo === TipoEventoPrefrio::VerificacionFinal && is_array($evento->datos['datos']['lectura_final'] ?? null)))
            ->sortBy(fn (EventoPrefrio $evento): string => $evento->ocurrido_at?->format('Y-m-d H:i:s.u').$evento->created_at?->format('Y-m-d H:i:s.u'))
            ->values()
            ->map(function (EventoPrefrio $evento): array {
                $final = $evento->tipo === TipoEventoPrefrio::VerificacionFinal;
                $sectores = $final ? $evento->datos['datos']['lectura_final'] : ($evento->datos['sectores'] ?? null);

                return [
                    'id' => $evento->id,
                    'ocurrido_at' => CarbonImmutable::parse($evento->ocurrido_at),
                    'sectores' => is_array($sectores) ? $sectores : null,
                    'temperatura' => is_numeric($evento->datos['temperatura'] ?? null) ? (float) $evento->datos['temperatura'] : null,
                    'final' => $final,
                    'observacion' => $final ? null : $evento->observacion,
                    'usuario' => $evento->relationLoaded('usuario') ? $evento->usuario?->name : null,
                ];
            });
    }

    /**
     * Estado del registro para la tablet y la oficina: próxima lectura horaria y si el
     * túnel ya tiene la lectura final que pide el término del enfriado.
     *
     * @param  Collection<int, EventoPrefrio>  $eventos
     * @return array<string, mixed>
     */
    public static function estado(ProcesoPrefrio $proceso, Collection $eventos, ?CarbonImmutable $ahora = null): array
    {
        $intervalo = max(1, (int) config('prefrio.r41.intervalo_lectura_minutos', 60));
        $lecturas = self::lecturas($eventos);
        $ultima = $lecturas->last();
        $base = $ultima['ocurrido_at'] ?? ($proceso->iniciado_at ? CarbonImmutable::parse($proceso->iniciado_at) : null);
        $enProceso = $proceso->estado === EstadoProcesoPrefrio::EnProceso;
        $proxima = $enProceso && $base ? $base->addMinutes($intervalo) : null;

        return [
            'intervalo_lectura_minutos' => $intervalo,
            'exige_lectura_final' => (bool) config('prefrio.r41.exige_lectura_final', true),
            'lecturas' => $lecturas->count(),
            'ultima_lectura_at' => $ultima ? $ultima['ocurrido_at']->toAtomString() : null,
            'ultima_lectura_completa' => $ultima !== null && self::completa($ultima['sectores']),
            'proxima_lectura_at' => $proxima?->toAtomString(),
            'lectura_vencida' => $proxima !== null && ($ahora ?? CarbonImmutable::now())->greaterThanOrEqualTo($proxima),
            'datos' => self::datosPlanilla($proceso),
        ];
    }

    /** @return array<string, mixed> */
    public static function datosPlanilla(ProcesoPrefrio $proceso): array
    {
        $guardados = is_array($proceso->registro_r41) ? $proceso->registro_r41 : [];

        return [
            'temperatura_objetivo' => isset($guardados['temperatura_objetivo']) ? (float) $guardados['temperatura_objetivo'] : null,
            'supervisor' => $guardados['supervisor'] ?? null,
            'jefe_frio' => $guardados['jefe_frio'] ?? null,
            'servicio' => $guardados['servicio'] ?? null,
        ];
    }

    /**
     * Todo lo que va en la planilla: encabezado, pallets por lado y profundidad, lecturas,
     * deshielos y observaciones.
     *
     * @return array<string, mixed>
     */
    public static function detalle(ProcesoPrefrio $proceso): array
    {
        $proceso->loadMissing(['tunel', 'iniciadoPor', 'finalizadoPor', 'eventos.usuario', 'folios.folio', 'folios.posicion']);
        $zona = config('app.operational_timezone', config('app.timezone'));
        $hora = fn (mixed $momento): ?string => $momento ? CarbonImmutable::parse($momento)->setTimezone($zona)->format('H:i') : null;
        $eventos = $proceso->eventos;
        $datos = self::datosPlanilla($proceso);
        $asignaciones = $proceso->folios
            ->filter(fn (ProcesoPrefrioFolio $asignacion): bool => ! in_array($asignacion->estado, [EstadoFolioProcesoPrefrio::Retirado, EstadoFolioProcesoPrefrio::Cancelado], true))
            ->values();
        $externos = fn (ProcesoPrefrioFolio $asignacion): array => $asignacion->folio?->datos_externos ?? [];
        $unicos = fn (Collection $valores): ?string => $valores->filter(fn ($valor): bool => filled($valor))->map(fn ($valor): string => trim((string) $valor))
            ->unique()->sort()->implode(', ') ?: null;

        $posiciones = [];
        foreach ($asignaciones as $asignacion) {
            $numero = (int) ($asignacion->posicion?->numero ?? 0);
            if ($numero < 1) {
                continue;
            }
            $lado = $numero % 2 === 1 ? 'izquierda' : 'derecha';
            $profundidad = intdiv($numero + 1, 2);
            $posiciones[$lado][$profundidad][] = [
                'folio' => $asignacion->folio?->numero_folio,
                'calibre' => $asignacion->folio?->calibre,
                'embalaje' => $externos($asignacion)['envase'] ?? ($externos($asignacion)['embalaje'] ?? null),
            ];
        }
        $inicio = $proceso->iniciado_at ? CarbonImmutable::parse($proceso->iniciado_at) : null;
        $fin = $proceso->pendiente_verificacion_at ? CarbonImmutable::parse($proceso->pendiente_verificacion_at) : null;
        $deshielos = $eventos->filter(fn (EventoPrefrio $e): bool => $e->tipo === TipoEventoPrefrio::Deshielo)->sortBy('ocurrido_at')->values();
        $inversion = $eventos->filter(fn (EventoPrefrio $e): bool => $e->tipo === TipoEventoPrefrio::InversionRegistrada)->sortBy('ocurrido_at')->first();
        $jornada = app(CalendarioOperativo::class)->datos($inicio ?? $proceso->created_at);
        $observacionJornada = $jornada['turno_operativo']
            ? 'Jornada de inicio '.CarbonImmutable::parse($jornada['fecha_jornada'])->format('d-m-Y').' · Turno '.$jornada['turno_operativo']
            : null;
        $observaciones = collect([$observacionJornada, $proceso->observacion])
            ->merge($eventos->filter(fn (EventoPrefrio $e): bool => in_array($e->tipo, [TipoEventoPrefrio::Pausa, TipoEventoPrefrio::Reanudacion, TipoEventoPrefrio::Deshielo, TipoEventoPrefrio::InversionRegistrada], true))
                ->sortBy('ocurrido_at')
                ->map(fn (EventoPrefrio $e): string => sprintf('%s %s%s', $hora($e->ocurrido_at), mb_strtolower(self::nombreEvento($e->tipo)), $e->observacion ? ': '.$e->observacion : '')))
            ->merge($deshielos->slice(3)->isNotEmpty() ? ['Más de 3 deshielos: ver las horas en el detalle anterior.'] : [])
            ->filter(fn ($texto): bool => filled($texto))
            ->values()
            ->all();

        $salidasParciales = $asignaciones->whereNotNull('finalizado_parcial_at')
            ->groupBy(fn (ProcesoPrefrioFolio $asignacion): string => $asignacion->finalizado_parcial_at->toAtomString())
            ->sortKeys()
            ->map(fn (Collection $grupo, string $momento): string => sprintf(
                '%s finalización parcial de %d folios (%s).',
                $hora($momento),
                $grupo->count(),
                $grupo->map(fn (ProcesoPrefrioFolio $asignacion): string => (string) $asignacion->folio?->numero_folio)->implode(', '),
            ))->values()->all();

        return [
            'codigo' => $proceso->codigo,
            'tunel' => $proceso->tunel?->codigo,
            'numero_proceso' => $unicos($asignaciones->flatMap(fn ($a) => collect($externos($a)['composicion'] ?? [])->pluck('proceso_packing'))),
            'temperatura_objetivo' => $datos['temperatura_objetivo'],
            'setpoint' => $proceso->setpoint !== null ? (float) $proceso->setpoint : null,
            'pallets' => $asignaciones->count(),
            'fecha' => $inicio?->setTimezone($zona)->format('d-m-Y') ?? CarbonImmutable::parse($proceso->created_at)->setTimezone($zona)->format('d-m-Y'),
            'supervisor' => $datos['supervisor'] ?? $proceso->iniciadoPor?->name,
            'jefe_frio' => $datos['jefe_frio'] ?? ($proceso->estado === EstadoProcesoPrefrio::Aprobado ? $proceso->finalizadoPor?->name : null),
            'servicio' => $datos['servicio'],
            'especie' => $unicos($asignaciones->map(fn ($a) => $externos($a)['especie'] ?? null)),
            'variedad' => $unicos($asignaciones->map(fn ($a) => $a->folio?->variedad)),
            'hora_inicio' => $hora($inicio),
            'hora_inversion' => $hora($inversion?->ocurrido_at),
            'hora_fin' => $hora($fin),
            'tiempo_total' => $inicio && $fin ? sprintf('%d h %02d min', intdiv((int) $inicio->diffInMinutes($fin), 60), (int) $inicio->diffInMinutes($fin) % 60) : null,
            'posiciones' => $posiciones,
            'profundidades' => max(1, (int) ceil(((int) ($proceso->tunel?->capacidad_posiciones ?? 0)) / 2)),
            'lecturas' => self::lecturas($eventos)->map(fn (array $lectura): array => [...$lectura, 'hora' => $hora($lectura['ocurrido_at'])])->all(),
            'deshielos' => $deshielos->map(fn (EventoPrefrio $e): array => ['fecha' => CarbonImmutable::parse($e->ocurrido_at)->setTimezone($zona)->format('d-m'), 'hora' => $hora($e->ocurrido_at)])->all(),
            'observaciones' => [...$salidasParciales, ...$observaciones],
            'estado' => $proceso->estado->value,
        ];
    }

    private static function nombreEvento(TipoEventoPrefrio $tipo): string
    {
        return match ($tipo) {
            TipoEventoPrefrio::Pausa => 'Pausa',
            TipoEventoPrefrio::Reanudacion => 'Reanudación',
            TipoEventoPrefrio::Deshielo => 'Deshielo',
            TipoEventoPrefrio::InversionRegistrada => 'Inversión',
            default => $tipo->value,
        };
    }
}
