<?php

namespace App\Services\Asistente\Haiku;

use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\Consultas\VistaConsulta;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\TextoNormalizado;

/**
 * Límites del catálogo: se explican al intérprete y se comprueban en el servidor.
 * Una consulta cercana no reemplaza una métrica, un estado o un filtro que no existe.
 */
class CoberturaConsultaHaiku
{
    public function limites(ConsultaAsistente $consulta): string
    {
        return match ($consulta->clave()) {
            'revisar-taras' => 'Solo anomalías de tara y neto fuera de rango. No calcula promedios de neto por envase agrupados por CSG ni lista entregas de envases al destare.',
            'envases-recibidos' => 'Ninguna vista selecciona un tipo de envase ni responde el total exclusivo de vacíos o llenos de Validación MP; la de recibidos solo trae una columna de vacíos.',
            'validaciones-pt' => 'Solo validaciones PT aceptadas y terminadas. No lista pendientes, faltantes ni folios sin validar.',
            'cajas-pt', 'formatos-pt', 'saldos-pt' => 'Existencia actual de fruta embalada. No es producción histórica ni inventario de insumos, cajas vacías o cartón.',
            'repaletizajes' => 'Solo repaletizajes confirmados. No son retornos, devoluciones ni pallets que volvieron desde cargas.',
            'folios-despachados' => 'Cuenta folios, pallets y saldos, no cajas ni kilos. No filtra por país, destino, puerto, mercado o recibidor.',
            default => 'Responde exclusivamente lo descrito y aplica únicamente los filtros indicados.',
        };
    }

    /** @param array<string, mixed> $entidades reconocidas por FoliOS antes de llamar a Haiku */
    public function impedimento(ConsultaAsistente $consulta, VistaConsulta $vista, string $pregunta, ParametrosConsulta $parametros, array $entidades): ?string
    {
        $texto = TextoNormalizado::de($pregunta);
        $filtros = $vista->filtros;
        foreach (['cliente' => 'clienteId', 'csg' => 'csgId', 'especie' => 'especieId'] as $filtro => $campo) {
            $reconocido = $entidades[$filtro.'_id'] ?? null;
            if (($parametros->$campo !== null || $reconocido !== null) && ! in_array($filtro, $filtros, true)) {
                return "la consulta no aplica el filtro {$filtro} solicitado";
            }
            if ($reconocido !== null && $reconocido !== $parametros->$campo) {
                return "la consulta omitió o cambió el {$filtro} reconocido en la pregunta";
            }
        }
        if (($parametros->agrupar !== null || ($entidades['agrupar'] ?? null) !== null) && ! in_array('por_dia', $filtros, true)) {
            return 'la consulta no agrupa por jornada';
        }
        if (! in_array('periodo', $filtros, true)) {
            if ($parametros->temporadaAnterior || ($entidades['temporada_anterior'] ?? false)) {
                return 'la consulta solo muestra el estado actual, no otra temporada';
            }
            $hoy = app(PeriodoOperativo::class)->jornada();
            if (($parametros->periodoExplicito && $parametros->jornadas() !== [$hoy, $hoy])
                || (($entidades['periodo_explicito'] ?? false) && $this->otrasJornadas($entidades, $hoy))) {
                return 'la consulta solo muestra el estado actual, no el periodo solicitado';
            }
        } elseif (($entidades['periodo_explicito'] ?? false) && isset($entidades['desde'], $entidades['hasta'])) {
            [$desde, $hasta] = $parametros->rango();
            if (! $desde->equalTo($entidades['desde']) || ! $hasta->equalTo($entidades['hasta'])) {
                return 'la consulta omitió o cambió el periodo reconocido en la pregunta';
            }
        }

        return match ($consulta->clave()) {
            'revisar-taras' => $this->revisionTaras($texto),
            'envases-recibidos' => preg_match('/\b(vaci[oa]s?|llenos?|llenas?)\b|\b(sin|con) fruta\b/', $texto)
                ? 'la consulta no selecciona exclusivamente envases vacíos o con fruta' : null,
            'validaciones-pt' => preg_match('/\b(pendientes?|faltantes?|falta|faltan)\b|\b(sin|por|no) validar\b|\bno validados?\b/', $texto)
                ? 'la consulta cuenta validaciones terminadas, no pendientes' : null,
            'cajas-pt', 'formatos-pt', 'saldos-pt' => $this->existenciaPt($texto),
            'repaletizajes' => preg_match('/\b(retornos?|devoluciones?|volv\w*|devuelt\w*|regres\w*)\b/', $texto)
                ? 'la consulta registra repaletizajes, no retornos o devoluciones' : null,
            'folios-despachados' => preg_match('/\b(cajas?|kilos?|kilogramos?|kg|pais|paises|destinos?|puertos?|mercados?|recibidor\w*|hacia)\b/', $texto)
                ? 'la consulta cuenta folios despachados, sin cajas, kilos ni filtros de destino' : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $entidades */
    private function otrasJornadas(array $entidades, string $hoy): bool
    {
        $periodo = app(PeriodoOperativo::class);

        return isset($entidades['desde'], $entidades['hasta'])
            && [$periodo->jornada($entidades['desde']), $periodo->jornada($entidades['hasta']->subSecond())] !== [$hoy, $hoy];
    }

    private function revisionTaras(string $texto): ?string
    {
        if (preg_match('/\b(envases?|bins?|totes?)\b/', $texto)
            && preg_match('/\b(salieron|salen|salidas?|entreg\w*|despach\w*)\b/', $texto)) {
            return 'la revisión de taras no lista entregas o salidas de envases';
        }
        if (preg_match('/\b(neto|promedio|media)\b/', $texto)
            && preg_match('/\bpor (cada )?(csg|productor\w*)\b/', $texto)
            && ! preg_match('/\b(revis\w*|taras?|anomali\w*|errores?|fuera de rango)\b/', $texto)) {
            return 'la revisión de anomalías no calcula promedios de neto agrupados por productor';
        }

        return null;
    }

    private function existenciaPt(string $texto): ?string
    {
        if (preg_match('/\b(carton\w*|insumos?|bolsas?|zunchos?|esquineros?|film)\b|\bcajas? vacias?\b/', $texto)) {
            return 'la consulta es de fruta embalada, no de insumos o cajas vacías de Materiales';
        }
        if (preg_match('/\b(embalamos|embalaron|produj\w*|produccion|producid\w*)\b/', $texto)) {
            return 'la existencia actual no es la producción de un periodo';
        }

        return null;
    }
}
