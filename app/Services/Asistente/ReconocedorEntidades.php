<?php

namespace App\Services\Asistente;

use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;

/**
 * Reconoce en el texto de una pregunta lo que FoliOS ya conoce: fechas relativas o
 * exactas, códigos de documentos, folios, clientes, CSG y especies. No adivina: lo que
 * no reconoce queda como palabra suelta para el puntaje de intención.
 */
class ReconocedorEntidades
{
    private const PATRON_CODIGO = '/\b(REC-\d+(?:-\d+)?|CAR-\d+|PF-\d+)\b/i';

    private const PATRON_FECHA = '/\b(\d{1,2})[-\/](\d{1,2})(?:[-\/](\d{4}))?\b/';

    public function __construct(private readonly ServicioTemporadaActiva $temporadas) {}

    /**
     * @return array{desde: ?CarbonImmutable, hasta: ?CarbonImmutable, cliente_id: ?string,
     *     csg_id: ?string, especie_id: ?string, especie: ?string, codigo: ?string,
     *     temporada_anterior: bool, palabras: list<string>}
     */
    public function reconocer(string $texto): array
    {
        $normalizado = TextoNormalizado::de($texto);
        [$desde, $hasta] = $this->fechas($normalizado);
        $codigo = $this->codigo($texto);
        $palabras = TextoNormalizado::palabras($texto);
        $temporada = $this->temporadas->buscar();

        $cliente = $this->cliente($normalizado, $palabras);
        $csg = $temporada ? $this->csg($palabras, $temporada->id) : null;
        $especie = $temporada ? $this->especie($palabras, $temporada->id) : null;

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'cliente_id' => $cliente,
            'csg_id' => $csg,
            'especie_id' => $especie?->id,
            'especie' => $especie?->nombre,
            'codigo' => $codigo,
            'temporada_anterior' => (bool) preg_match('/\btemporada (pasada|anterior)\b/', $normalizado),
            'palabras' => $palabras,
        ];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} límites en UTC, hasta excluido */
    private function fechas(string $texto): array
    {
        $zona = config('app.operational_timezone');
        $hoy = CarbonImmutable::now($zona)->startOfDay();
        $dia = fn (CarbonImmutable $inicio, CarbonImmutable $fin): array => [$inicio->utc(), $fin->utc()];

        $relativas = [
            '/\bhoy\b/' => fn () => $dia($hoy, $hoy->addDay()),
            '/\bayer\b/' => fn () => $dia($hoy->subDay(), $hoy),
            '/\besta semana\b/' => fn () => $dia($hoy->startOfWeek(CarbonImmutable::MONDAY), $hoy->addDay()),
            '/\b(la )?semana pasada\b/' => fn () => $dia(
                $hoy->startOfWeek(CarbonImmutable::MONDAY)->subWeek(),
                $hoy->startOfWeek(CarbonImmutable::MONDAY),
            ),
            '/\beste mes\b/' => fn () => $dia($hoy->startOfMonth(), $hoy->addDay()),
            '/\b(el )?mes pasado\b/' => fn () => $dia($hoy->startOfMonth()->subMonth(), $hoy->startOfMonth()),
        ];
        foreach ($relativas as $patron => $rango) {
            if (preg_match($patron, $texto)) {
                return $rango();
            }
        }

        $fechas = [];
        if (preg_match_all(self::PATRON_FECHA, $texto, $coincidencias, PREG_SET_ORDER)) {
            foreach ($coincidencias as $m) {
                $anio = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $hoy->year;
                if (checkdate((int) $m[2], (int) $m[1], $anio)) {
                    $fechas[] = CarbonImmutable::create($anio, (int) $m[2], (int) $m[1], 0, 0, 0, $zona);
                }
            }
        }
        if ($fechas === []) {
            return [null, null];
        }
        usort($fechas, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $dia($fechas[0], end($fechas)->addDay());
    }

    private function codigo(string $texto): ?string
    {
        if (preg_match(self::PATRON_CODIGO, $texto, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match_all('/\b\d{5,}\b/', $texto, $numeros)) {
            foreach ($numeros[0] as $numero) {
                if (Folio::query()->where('numero_folio', $numero)->exists()) {
                    return $numero;
                }
            }
        }

        return null;
    }

    /** @param  list<string>  $palabras */
    private function cliente(string $texto, array $palabras): ?string
    {
        $clientes = Cliente::query()->where('activo', true)->get(['id', 'codigo', 'nombre']);
        foreach ($clientes as $cliente) {
            $nombre = TextoNormalizado::de((string) $cliente->nombre);
            if (strlen($nombre) >= 3 && str_contains($texto, $nombre)) {
                return $cliente->id;
            }
            $codigo = TextoNormalizado::de((string) $cliente->codigo);
            if (strlen($codigo) >= 3 && in_array($codigo, $palabras, true)) {
                return $cliente->id;
            }
        }
        foreach ($clientes as $cliente) {
            foreach (TextoNormalizado::palabras((string) $cliente->nombre) as $parte) {
                if (strlen($parte) < 5) {
                    continue;
                }
                foreach ($palabras as $palabra) {
                    if (strlen($palabra) >= 5 && levenshtein($parte, $palabra) <= 1) {
                        return $cliente->id;
                    }
                }
            }
        }

        return null;
    }

    /** @param  list<string>  $palabras */
    private function csg(array $palabras, string $temporadaId): ?string
    {
        $candidatos = array_values(array_filter($palabras, fn (string $p): bool => (bool) preg_match('/^\d{3,}$/', $p)));
        if ($candidatos === []) {
            return null;
        }

        return CsgValidacion::query()->where('temporada_id', $temporadaId)
            ->whereIn('codigo', $candidatos)->value('id');
    }

    /** @param  list<string>  $palabras */
    private function especie(array $palabras, string $temporadaId): ?EspecieValidacion
    {
        $especies = EspecieValidacion::query()->where('temporada_id', $temporadaId)->where('activo', true)->get(['id', 'nombre']);
        foreach ($especies as $especie) {
            $nombre = TextoNormalizado::de((string) $especie->nombre);
            foreach ($palabras as $palabra) {
                $singular = preg_replace('/s$/', '', $palabra);
                if ($palabra === $nombre || $singular === $nombre || (strlen($nombre) >= 5 && levenshtein($nombre, $singular) <= 1)) {
                    return $especie;
                }
            }
        }

        return null;
    }
}
