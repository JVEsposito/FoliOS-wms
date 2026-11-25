<?php

namespace App\Services\Asistente\Consultas;

use App\Models\RangoTaraAsistente;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Asistente\RevisionTaras;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\Temporadas\ServicioTemporadaActiva;

/** «Revisa las taras de hoy»: posibles errores de tara o destare en el periodo. */
class RevisarTaras extends ConsultaBase
{
    private const REGLAS = [
        'neto_por_tote' => 'Neto por tote fuera de rango',
        'tara_envase' => 'Tara distinta a la oficial',
        'esponja' => 'Esponja fuera de lo habitual',
        'historico_csg' => 'Lote alejado de su CSG',
    ];

    public function __construct(
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        private readonly RevisionTaras $revision,
    ) {
        parent::__construct($alcance, $temporadas);
    }

    public function clave(): string
    {
        return 'revisar-taras';
    }

    public function titulo(): string
    {
        return 'Revisión de taras';
    }

    public function descripcion(): string
    {
        return 'Posibles errores de tara o destare: neto por tote, tara oficial, esponjas e histórico por CSG.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente', 'csg', 'especie'];
    }

    public function palabrasClave(): array
    {
        return ['tara', 'taras', 'destare', 'destares', 'destara', 'revisa', 'revisar', 'revision', 'error', 'errores', 'raro', 'raros', 'sospechoso', 'esponja', 'esponjas'];
    }

    public function admiteRecomendacion(): bool
    {
        return true;
    }

    public function capacidades(): array
    {
        return RangoTaraAsistente::query()->where('activo', true)->orderBy('tipo_envase')->get()
            ->flatMap(fn (RangoTaraAsistente $r): array => [
                ['nombre' => "Neto mínimo por {$r->tipo_envase}".($r->especie ? " ({$r->especie})" : ''), 'valor' => (float) $r->minimo_kg],
                ['nombre' => "Neto máximo por {$r->tipo_envase}".($r->especie ? " ({$r->especie})" : ''), 'valor' => (float) $r->maximo_kg],
            ])->values()->all();
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        if ($temporada === null) {
            return ResultadoConsulta::sinDatos($this->titulo(), 'No hay temporada activa.', '/oficina/romana');
        }
        [$desde, $hasta] = $parametros->rango();
        $hallazgos = $this->revision->revisar($temporada, $desde, $hasta, $parametros->clienteId, $parametros->csgId, $parametros->especieId);

        if ($hallazgos === []) {
            return new ResultadoConsulta(
                ResultadoConsulta::RESPONDIDA,
                $this->titulo(),
                "No se encontraron taras ni destares fuera de lo normal el {$this->periodo($parametros)}.",
                [ResultadoConsulta::cifra('Para revisar', 0)],
                enlace: '/oficina/romana',
            );
        }

        $porRegla = collect($hallazgos)->countBy('regla');
        $cifras = [ResultadoConsulta::cifra('Para revisar', count($hallazgos))];
        foreach (self::REGLAS as $regla => $etiqueta) {
            if ($porRegla->has($regla)) {
                $cifras[] = ResultadoConsulta::cifra($etiqueta, $porRegla[$regla]);
            }
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s hay %d posibles errores de tara o destare para revisar.', $this->periodo($parametros), count($hallazgos)),
            $cifras,
            [
                ['clave' => 'recepcion', 'titulo' => 'Recepción', 'tipo' => 'codigo'],
                ['clave' => 'regla', 'titulo' => 'Revisión'],
                ['clave' => 'detalle', 'titulo' => 'Detalle'],
                ['clave' => 'estado', 'titulo' => 'Estado', 'tipo' => 'estado'],
            ],
            array_map(fn (array $h): array => [
                'recepcion' => $h['recepcion'],
                'regla' => self::REGLAS[$h['regla']],
                'detalle' => $h['detalle'],
                'estado' => 'Revisar',
            ], $hallazgos),
            '/oficina/romana',
        );
    }
}
