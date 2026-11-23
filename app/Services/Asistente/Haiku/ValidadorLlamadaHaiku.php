<?php

namespace App\Services\Asistente\Haiku;

use App\Models\EspecieValidacion;
use App\Services\Asistente\Consultas\ConsultaAsistente;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\PeriodoOperativo;
use App\Services\Asistente\TemporadasAsistente;
use App\Services\Asistente\TextoNormalizado;
use App\Services\Temporadas\ServicioTemporadaActiva;

/**
 * Valida en FoliOS lo que Haiku pidió antes de ejecutar nada: consulta permitida, fichas
 * existentes, especie de la temporada, fechas válidas y periodo coherente. Si algo no
 * calza, no se ejecuta: el asistente repregunta.
 */
class ValidadorLlamadaHaiku
{
    /** Periodo más largo que se acepta en un rango, en jornadas. */
    private const MAXIMO_JORNADAS = 400;

    public function __construct(
        private readonly PeriodoOperativo $periodo,
        private readonly TemporadasAsistente $temporadasAsistente,
        private readonly ServicioTemporadaActiva $temporadas,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada  parámetros de la herramienta consultar
     * @param  list<ConsultaAsistente>  $permitidas
     * @param  array<string, array{tipo: string, id: string, etiqueta: string}>  $fichas
     * @return array{consulta: ConsultaAsistente, parametros: ParametrosConsulta}|string la llamada validada o el motivo del rechazo
     */
    public function validar(array $entrada, array $permitidas, array $fichas): array|string
    {
        $consulta = null;
        foreach ($permitidas as $permitida) {
            if ($permitida->clave() === ($entrada['consulta'] ?? null)) {
                $consulta = $permitida;
            }
        }
        if ($consulta === null) {
            return 'la consulta elegida no está en tu catálogo';
        }

        $cliente = $this->ficha($entrada['cliente'] ?? '', 'cliente', $fichas);
        $csg = $this->ficha($entrada['csg'] ?? '', 'csg', $fichas);
        if ($cliente === false || $csg === false) {
            return 'la consulta usó un cliente o CSG que no está en la pregunta';
        }
        $especie = $this->especie((string) ($entrada['especie'] ?? 'todas'));
        if ($especie === false) {
            return 'la especie no está en la temporada';
        }
        $periodo = $this->periodo($entrada);
        if (is_string($periodo)) {
            return $periodo;
        }

        return [
            'consulta' => $consulta,
            'parametros' => new ParametrosConsulta(
                desde: $periodo['desde'],
                hasta: $periodo['hasta'],
                clienteId: $cliente,
                csgId: $csg,
                especieId: $especie,
                temporadaAnterior: $periodo['temporada_anterior'],
                turno: $periodo['turno'],
                etiquetaPeriodo: $periodo['etiqueta'],
                periodoExplicito: $periodo['explicito'],
                agrupar: ($entrada['agrupar_por'] ?? 'nada') === 'jornada' ? 'dia' : null,
            ),
        ];
    }

    /** @return string|null|false el id de la ficha, null si viene vacía o false si no existe */
    private function ficha(mixed $valor, string $tipo, array $fichas): string|null|false
    {
        $ficha = strtoupper(trim((string) $valor, " []\t"));
        if ($ficha === '') {
            return null;
        }

        return isset($fichas[$ficha]) && $fichas[$ficha]['tipo'] === $tipo ? $fichas[$ficha]['id'] : false;
    }

    private function especie(string $valor): string|null|false
    {
        $nombre = TextoNormalizado::de($valor);
        if ($nombre === '' || $nombre === 'todas') {
            return null;
        }
        $temporada = $this->temporadas->buscar();
        $especie = $temporada === null ? null : EspecieValidacion::query()->where('temporada_id', $temporada->id)->where('activo', true)
            ->get(['id', 'nombre'])->first(fn (EspecieValidacion $e): bool => TextoNormalizado::de((string) $e->nombre) === $nombre);

        return $especie?->id ?? false;
    }

    /** @return array{desde: mixed, hasta: mixed, turno: ?string, etiqueta: ?string, explicito: bool, temporada_anterior: bool}|string */
    private function periodo(array $entrada): array|string
    {
        $tipo = (string) ($entrada['periodo_tipo'] ?? 'actual');
        $hoy = $this->periodo->jornada();
        $activa = $this->temporadas->buscar();
        $sinFechas = ['desde' => null, 'hasta' => null, 'turno' => null, 'etiqueta' => null, 'explicito' => false, 'temporada_anterior' => false];

        if ($tipo === 'actual') {
            return $sinFechas;
        }
        if ($tipo === 'temporada' || $tipo === 'temporada_anterior') {
            if ($activa === null) {
                return 'no hay temporada activa';
            }
            if ($tipo === 'temporada') {
                $primera = $this->temporadasAsistente->primeraJornada($activa);
                [$desde, $hasta] = $this->periodo->rango($primera, $hoy);

                return [...$sinFechas, 'desde' => $desde, 'hasta' => $hasta, 'explicito' => true,
                    'etiqueta' => 'Temporada en curso (desde '.PeriodoOperativo::fechaLegible($primera).')'];
            }
            $anterior = $this->temporadasAsistente->anterior($activa);
            if ($anterior === null) {
                return 'no hay una temporada anterior registrada';
            }
            $primera = $this->temporadasAsistente->primeraJornada($anterior);
            $ultima = $this->temporadasAsistente->ultimaJornada($anterior)
                ?? $this->periodo->sumarDias($this->temporadasAsistente->primeraJornada($activa), -1);
            [$desde, $hasta] = $this->periodo->rango($primera, $ultima);

            return [...$sinFechas, 'desde' => $desde, 'hasta' => $hasta, 'explicito' => true, 'temporada_anterior' => true,
                'etiqueta' => 'Temporada anterior ('.$anterior->codigo.')'];
        }

        $desde = $this->fecha($entrada['desde'] ?? '');
        $hasta = $tipo === 'rango' ? $this->fecha($entrada['hasta'] ?? '') : $desde;
        if ($desde === null || $hasta === null) {
            return 'el periodo no trae una fecha válida';
        }
        if ($desde > $hasta) {
            return 'el periodo empieza después de terminar';
        }
        if ($hasta > $this->periodo->sumarDias($hoy, 1)) {
            return 'el periodo es futuro';
        }
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > self::MAXIMO_JORNADAS) {
            return 'el periodo es demasiado largo';
        }
        $turno = in_array($entrada['turno'] ?? null, ['A', 'B'], true) ? $entrada['turno'] : null;
        if ($tipo === 'turno' && $turno === null) {
            return 'falta el turno';
        }
        $turno = $tipo === 'turno' ? $turno : null;
        [$inicio, $fin] = $turno !== null ? $this->periodo->rangoTurno($desde, $turno) : $this->periodo->rango($desde, $hasta);

        return [...$sinFechas, 'desde' => $inicio, 'hasta' => $fin, 'turno' => $turno, 'explicito' => true];
    }

    private function fecha(mixed $valor): ?string
    {
        $texto = trim((string) $valor);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $texto;
    }
}
