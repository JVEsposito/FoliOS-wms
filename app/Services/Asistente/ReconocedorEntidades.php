<?php

namespace App\Services\Asistente;

use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\Temporada;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;

/**
 * Reconoce en el texto de una pregunta lo que FoliOS ya conoce: periodos en jornadas
 * operativas (hoy, anoche, turno B, el martes, la temporada…), códigos de documentos,
 * guías, lotes, folios, clientes, CSG y especies. No adivina: lo que no reconoce queda
 * como palabra suelta para el puntaje de intención, y las palabras que sí reconoció
 * salen de ese puntaje.
 */
class ReconocedorEntidades
{
    private const PATRON_CODIGO = '/\b(REC-\d+(?:-\d+)?|CAR-\d+|PF-\d+|GDE-\d+(?:-\d+)?)\b/i';

    private const PATRON_FECHA = '/\b(\d{1,2})[-\/](\d{1,2})(?:[-\/](\d{4}))?\b/';

    private const DIAS = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    public function __construct(
        private readonly ServicioTemporadaActiva $temporadas,
        private readonly PeriodoOperativo $periodo,
        private readonly TemporadasAsistente $temporadasAsistente,
    ) {}

    /**
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable, turno: ?string, periodo: ?string,
     *     periodo_explicito: bool, cliente_id: ?string, cliente: ?string, csg_id: ?string, csg: ?string,
     *     especie_id: ?string, especie: ?string, codigo: ?string, temporada_anterior: bool,
     *     palabras: list<string>, consumidas: list<string>}
     */
    public function reconocer(string $texto): array
    {
        $normalizado = TextoNormalizado::de($texto);
        $palabras = TextoNormalizado::palabras($texto);
        $activa = $this->temporadas->buscar();
        $temporadaAnterior = (bool) preg_match('/\btemporada (pasada|anterior)\b/', $normalizado);
        $periodo = $this->periodo($normalizado, $activa, $temporadaAnterior);
        $consumidas = [];

        $cliente = $this->cliente($normalizado, $palabras, $consumidas);
        $csg = $activa ? $this->csg($normalizado, $activa->id, $consumidas) : null;
        $especie = $activa ? $this->especie($palabras, $activa->id, $consumidas) : null;
        $codigo = $this->codigo($texto, $normalizado, $consumidas);

        return [
            ...$periodo,
            'cliente_id' => $cliente?->id,
            'cliente' => $cliente?->nombre,
            'csg_id' => $csg?->id,
            'csg' => $csg?->codigo,
            'especie_id' => $especie?->id,
            'especie' => $especie?->nombre,
            'codigo' => $codigo,
            'temporada_anterior' => $temporadaAnterior,
            'palabras' => $palabras,
            'consumidas' => array_values(array_unique($consumidas)),
        ];
    }

    /**
     * @return array{desde: CarbonImmutable, hasta: CarbonImmutable, turno: ?string, periodo: ?string, periodo_explicito: bool}
     */
    private function periodo(string $texto, ?Temporada $activa, bool $temporadaAnterior): array
    {
        $hoy = $this->periodo->jornada();
        $jornadas = $this->jornadas($texto, $hoy);
        $etiqueta = null;

        if ($jornadas === null && $temporadaAnterior && $activa && ($anterior = $this->temporadasAsistente->anterior($activa))) {
            $ultima = $this->temporadasAsistente->ultimaJornada($anterior)
                ?? $this->periodo->sumarDias($this->temporadasAsistente->primeraJornada($activa), -1);
            $jornadas = [$this->temporadasAsistente->primeraJornada($anterior), $ultima, null];
            $etiqueta = 'Temporada anterior ('.$anterior->codigo.')';
        }
        if ($jornadas === null && $activa && preg_match('/\b(la|esta|toda la|en la|de la) temporada\b/', $texto) && ! $temporadaAnterior) {
            $jornadas = [$this->temporadasAsistente->primeraJornada($activa), $hoy, null];
            $etiqueta = 'Temporada en curso (desde '.PeriodoOperativo::fechaLegible($jornadas[0]).')';
        }

        $explicito = $jornadas !== null;
        [$desdeFecha, $hastaFecha, $turno] = $jornadas ?? [$hoy, $hoy, null];
        if ($desdeFecha > $hastaFecha) {
            [$desdeFecha, $hastaFecha] = [$hastaFecha, $desdeFecha];
        }
        $turno ??= $this->turnoNombrado($texto);
        if ($turno !== null && $desdeFecha !== $hastaFecha) {
            $turno = null;
        }
        [$desde, $hasta] = $turno !== null
            ? $this->periodo->rangoTurno($desdeFecha, $turno)
            : $this->periodo->rango($desdeFecha, $hastaFecha);

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'turno' => $turno,
            'periodo' => $etiqueta,
            'periodo_explicito' => $explicito || $turno !== null,
        ];
    }

    /** @return array{0: string, 1: string, 2: ?string}|null primera jornada, última jornada y turno, si el texto nombra un periodo */
    private function jornadas(string $texto, string $hoy): ?array
    {
        $dia = fn (int $dias): string => $this->periodo->sumarDias($hoy, $dias);
        $semana = CarbonImmutable::parse($hoy, 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $mes = CarbonImmutable::parse($hoy, 'UTC')->startOfMonth();

        if (preg_match('/\banoche\b/', $texto)) {
            // Durante el turno B «anoche» es la noche en curso; de día, la noche que terminó.
            return $this->periodo->turno() === 'B' ? [$hoy, $hoy, 'B'] : [$dia(-1), $dia(-1), 'B'];
        }
        if (preg_match('/\b(esta|en la|de la|por la) manana\b/', $texto)) {
            return [$hoy, $hoy, 'A'];
        }
        $relativas = [
            '/\bpasado manana\b/' => fn () => [$dia(2), $dia(2), null],
            '/\bmanana\b/' => fn () => [$dia(1), $dia(1), null],
            '/\bhoy\b/' => fn () => [$hoy, $hoy, null],
            '/\bantes de ayer\b|\banteayer\b/' => fn () => [$dia(-2), $dia(-2), null],
            '/\bayer\b/' => fn () => [$dia(-1), $dia(-1), null],
            '/\besta semana\b/' => fn () => [$semana, $hoy, null],
            '/\b(la )?semana pasada\b/' => fn () => [
                $this->periodo->sumarDias($semana, -7), $this->periodo->sumarDias($semana, -1), null,
            ],
            '/\beste mes\b/' => fn () => [$mes->toDateString(), $hoy, null],
            '/\b(el )?mes pasado\b/' => fn () => [$mes->subMonth()->toDateString(), $mes->subDay()->toDateString(), null],
        ];
        foreach ($relativas as $patron => $rango) {
            if (preg_match($patron, $texto)) {
                return $rango();
            }
        }

        if (preg_match('/\b(lunes|martes|miercoles|jueves|viernes|sabado|domingo)\b( pasado)?/', $texto, $m)) {
            $actual = CarbonImmutable::parse($hoy, 'UTC')->dayOfWeekIso;
            $atras = ($actual - self::DIAS[$m[1]] + 7) % 7;
            if ($atras === 0 && ! empty($m[2])) {
                $atras = 7;
            }

            return [$dia(-$atras), $dia(-$atras), null];
        }

        $fechas = [];
        if (preg_match_all(self::PATRON_FECHA, $texto, $coincidencias, PREG_SET_ORDER)) {
            $anioActual = (int) substr($hoy, 0, 4);
            foreach ($coincidencias as $m) {
                $anio = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $anioActual;
                if (checkdate((int) $m[2], (int) $m[1], $anio)) {
                    $fechas[] = sprintf('%04d-%02d-%02d', $anio, (int) $m[2], (int) $m[1]);
                }
            }
        }
        if ($fechas === []) {
            return null;
        }
        sort($fechas);

        return [$fechas[0], end($fechas), null];
    }

    private function turnoNombrado(string $texto): ?string
    {
        if (preg_match('/\bturno (a|b)\b/', $texto, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\bturno (de )?(la )?noche\b|\b(en la|de|por la) noche\b/', $texto)) {
            return 'B';
        }
        if (preg_match('/\bturno (de )?dia\b/', $texto)) {
            return 'A';
        }

        return null;
    }

    /** @param  list<string>  $consumidas */
    private function codigo(string $texto, string $normalizado, array &$consumidas): ?string
    {
        if (preg_match(self::PATRON_CODIGO, $texto, $m)) {
            return strtoupper($m[1]);
        }
        // Guías, lotes y folios nombrados: el número que sigue a la palabra es el código.
        if (preg_match('/\b(guia|lote|folio)\s*(?:n[o°]?\s*)?(\d{3,})\b/', $normalizado, $m)) {
            $consumidas[] = $m[2];

            return $m[2];
        }
        // Un número largo suelto es un folio solo si existe y no es el CSG nombrado.
        if (preg_match_all('/(?<!csg )\b\d{5,}\b/', $normalizado, $numeros)) {
            foreach ($numeros[0] as $numero) {
                if (Folio::query()->where('numero_folio', $numero)->exists()) {
                    return $numero;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $palabras
     * @param  list<string>  $consumidas
     */
    private function cliente(string $texto, array $palabras, array &$consumidas): ?Cliente
    {
        $clientes = Cliente::query()->where('activo', true)->get(['id', 'codigo', 'nombre']);
        foreach ($clientes as $cliente) {
            $nombre = TextoNormalizado::de((string) $cliente->nombre);
            if (strlen($nombre) >= 3 && str_contains($texto, $nombre)) {
                array_push($consumidas, ...TextoNormalizado::palabras($nombre));

                return $cliente;
            }
            $codigo = TextoNormalizado::de((string) $cliente->codigo);
            if (strlen($codigo) >= 3 && in_array($codigo, $palabras, true)) {
                $consumidas[] = $codigo;

                return $cliente;
            }
        }
        foreach ($clientes as $cliente) {
            foreach (TextoNormalizado::palabras((string) $cliente->nombre) as $parte) {
                if (strlen($parte) < 5) {
                    continue;
                }
                foreach ($palabras as $palabra) {
                    if (strlen($palabra) >= 5 && levenshtein($parte, $palabra) <= 1) {
                        $consumidas[] = $palabra;

                        return $cliente;
                    }
                }
            }
        }

        return null;
    }

    /** @param  list<string>  $consumidas */
    private function csg(string $texto, string $temporadaId, array &$consumidas): ?CsgValidacion
    {
        if (! preg_match_all('/\b\d{3,}\b/', $texto, $numeros)) {
            return null;
        }
        $csg = CsgValidacion::query()->where('temporada_id', $temporadaId)
            ->whereIn('codigo', $numeros[0])->first(['id', 'codigo']);
        if ($csg) {
            $consumidas[] = (string) $csg->codigo;
        }

        return $csg;
    }

    /**
     * @param  list<string>  $palabras
     * @param  list<string>  $consumidas
     */
    private function especie(array $palabras, string $temporadaId, array &$consumidas): ?EspecieValidacion
    {
        $especies = EspecieValidacion::query()->where('temporada_id', $temporadaId)->where('activo', true)->get(['id', 'nombre']);
        foreach ($especies as $especie) {
            $nombre = TextoNormalizado::de((string) $especie->nombre);
            foreach ($palabras as $palabra) {
                $singular = preg_replace('/s$/', '', $palabra);
                if ($palabra === $nombre || $singular === $nombre || (strlen($nombre) >= 5 && levenshtein($nombre, $singular) <= 1)) {
                    $consumidas[] = $palabra;

                    return $especie;
                }
            }
        }

        return null;
    }
}
