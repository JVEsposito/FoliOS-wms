<?php

namespace App\Services\InspeccionSag;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Buscador público de Sitios de Producción del SAG para México. */
class RegistroSdpMexico
{
    public const URL = 'https://sispmex.sag.gob.cl/pubsber/reporteSDP.asp';

    public function buscar(array $codigos): array
    {
        $claves = array_combine($codigos, array_map(fn (string $codigo): string => 'sag:mexico:sdp:'.$codigo, $codigos));
        $cache = Cache::many(array_values($claves));
        $pendientes = array_filter($claves, fn (string $clave): bool => $cache[$clave] === null);
        $respuestas = $pendientes === [] ? [] : Http::pool(
            fn (Pool $pool): array => array_map(fn ($codigo) => $pool->as($codigo)
                ->accept('text/html')->withHeaders(['User-Agent' => 'FoliOS-WMS/1.0'])
                ->withoutRedirecting()->connectTimeout(8)->timeout(12)
                ->get(self::URL, ['BCODSAG' => $codigo, 'BRIDREGION' => 0, 'BRIDCOMUNA' => 0, 'BRMUESTREO' => 99, 'BRESTADO' => 99]),
                array_keys($pendientes)),
            concurrency: 4,
        );

        $resultados = [];
        foreach ($claves as $codigo => $clave) {
            if ($cache[$clave] !== null) {
                $resultados[$codigo] = $cache[$clave];

                continue;
            }
            $respuesta = $respuestas[$codigo] ?? null;
            $resultado = $respuesta instanceof Response && $respuesta->successful()
                ? $this->interpretar($respuesta->body(), (string) $codigo)
                : ['estado' => 'no_verificable', 'registros' => []];
            $resultado['consultado'] = now(config('app.operational_timezone'))->toIso8601String();
            if ($resultado['estado'] !== 'no_verificable') {
                Cache::put($clave, $resultado, now()->addMinutes(10));
            }
            $resultados[$codigo] = $resultado;
        }

        return $resultados;
    }

    private function interpretar(string $html, string $codigo): array
    {
        $utf8 = mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        $utf8 = preg_replace('/<meta\b[^>]*charset[^>]*>/i', '', $utf8);
        $documento = new DOMDocument;
        $registros = [];
        if ($utf8 === '' || ! $documento->loadHTML('<?xml encoding="UTF-8">'.$utf8, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return ['estado' => 'no_verificable', 'registros' => []];
        }
        $xpath = new DOMXPath($documento);
        if ($xpath->query('//form[@id="frmbuscar"]//input[@name="BCODSAG"]')->length !== 1) {
            return ['estado' => 'no_verificable', 'registros' => []];
        }

        $encontrado = false;
        foreach ($xpath->query('//tr[td[1]/span[@class="titulo2"]]') as $fila) {
            $id = trim($xpath->evaluate('string(./td[1]/span[@class="titulo2"])', $fila));
            if ($id !== $codigo) {
                continue;
            }
            $encontrado = true;
            $celda = $xpath->query('./td[3]', $fila)->item(0);
            if (! $celda) {
                continue;
            }
            $fragmento = str_replace(["\r", "\n", "\t"], ' ', $documento->saveHTML($celda));
            $lineas = preg_replace('/<br\b[^>]*>/i', "\n", $fragmento);
            $lineas = html_entity_decode(strip_tags($lineas), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Las líneas HTML se separan por BR. Saltos de formato dentro de una línea no separan variedades.
            foreach (explode("\n", $lineas) as $linea) {
                if (preg_match('/^\s*([^.]+)\.\s*(.+?)(?:\s+-\s+Clasificaci[oó]n\s*:\s*(.+?))?\s*$/iu', str_replace("\u{00a0}", ' ', $linea), $partes)) {
                    $registros[] = [
                        'especie' => trim($partes[1]),
                        'variedad' => trim($partes[2]),
                        'clasificacion' => trim($partes[3] ?? ''),
                    ];
                }
            }
        }
        $sinResultados = preg_match('/NO\s+se\s+registran\s+Sitios\s+de\s+Producci[oó]n/iu', $documento->textContent);

        return [
            'estado' => $encontrado ? 'encontrado' : ($sinResultados ? 'no_encontrado' : 'no_verificable'),
            'registros' => array_values(array_unique($registros, SORT_REGULAR)),
        ];
    }
}
