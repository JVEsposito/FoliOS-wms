<?php

namespace App\Services\Asistente;

use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Services\Asistente\Consultas\ConsultaAsistente;

/**
 * La línea «Entendí» de cada respuesta: qué consulta se ejecutó, con qué periodo y qué
 * filtros. Un filtro reconocido que la consulta no aplica se dice aquí, para que nadie
 * lea una cifra como filtrada cuando no lo está.
 */
class LineaEntendido
{
    /** @return array{consulta: string, fichas: list<string>, no_aplicados: list<string>} */
    public function construir(ConsultaAsistente $consulta, ParametrosConsulta $parametros): array
    {
        $filtros = $consulta->filtros();
        $fichas = [];
        $noAplicados = [];

        if (in_array('periodo', $filtros, true)) {
            $fichas[] = $parametros->temporadaAnterior && $parametros->etiquetaPeriodo === null
                ? 'Temporada anterior · '.$parametros->descripcionPeriodo()
                : $parametros->descripcionPeriodo();
        } elseif ($consulta->clave() !== 'expediente-por-codigo') {
            $fichas[] = 'Existencia actual';
            if ($parametros->periodoExplicito || $parametros->temporadaAnterior) {
                $noAplicados[] = 'Muestra lo que hay ahora: no filtra por fecha ni por temporada anterior.';
            }
        }

        $nombres = [
            'cliente' => $parametros->clienteId ? Cliente::query()->find($parametros->clienteId, ['nombre'])?->nombre : null,
            'csg' => $parametros->csgId ? CsgValidacion::query()->find($parametros->csgId, ['codigo'])?->codigo : null,
            'especie' => $parametros->especieId ? EspecieValidacion::query()->find($parametros->especieId, ['nombre'])?->nombre : null,
        ];
        $avisos = [
            'cliente' => fn (string $nombre): string => "No filtra por cliente ({$nombre}): incluye todos los clientes.",
            'csg' => fn (string $codigo): string => "No filtra por CSG ({$codigo}): incluye todos los CSG.",
            'especie' => fn (string $nombre): string => "No filtra por especie ({$nombre}): incluye todas las especies.",
        ];
        $etiquetas = ['cliente' => fn (string $n): string => $n, 'csg' => fn (string $c): string => "CSG {$c}", 'especie' => fn (string $n): string => $n];

        foreach ($nombres as $filtro => $nombre) {
            if ($nombre === null) {
                continue;
            }
            if (in_array($filtro, $filtros, true)) {
                $fichas[] = $etiquetas[$filtro]($nombre);
            } else {
                $noAplicados[] = $avisos[$filtro]($nombre);
            }
        }
        if ($parametros->codigo !== null && in_array('codigo', $filtros, true)) {
            $fichas[] = 'Código '.$parametros->codigo;
        }

        return ['consulta' => $consulta->titulo(), 'fichas' => $fichas, 'no_aplicados' => $noAplicados];
    }
}
