<?php

namespace App\Services\Asistente\Consultas;

use App\Models\AnulacionValidacionPallet;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/** Validaciones PT anuladas en el periodo, con el folio, el motivo y quién la anuló. */
class AnulacionesValidacion extends ConsultaBase
{
    public function clave(): string
    {
        return 'anulaciones-validacion';
    }

    public function titulo(): string
    {
        return 'Folios anulados';
    }

    public function descripcion(): string
    {
        return 'Validaciones PT anuladas en el periodo, con folio, motivo y responsable.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasClave(): array
    {
        return ['anularon', 'anulados', 'anuladas', 'anulado', 'anulacion', 'anulaciones'];
    }

    public function palabrasSecundarias(): array
    {
        return ['folios', 'folio', 'validaciones', 'validacion'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarValidacionesPallet($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        [$desde, $hasta] = $parametros->rango();
        $anulaciones = AnulacionValidacionPallet::query()
            ->where('anulado_at', '>=', $desde)->where('anulado_at', '<', $hasta)
            ->with('anuladoPor:id,name')
            ->orderByDesc('anulado_at')
            ->get(['id', 'numero_folio', 'motivo_categoria', 'motivo', 'anulado_por_user_id', 'anulado_at']);

        if ($anulaciones->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No se anularon validaciones el {$this->periodo($parametros)}.", '/oficina/validacion');
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se anularon %d validaciones de folios.', $this->periodo($parametros), $anulaciones->count()),
            [ResultadoConsulta::cifra('Anulaciones', $anulaciones->count())],
            [
                ['clave' => 'folio', 'titulo' => 'Folio', 'tipo' => 'folio'],
                ['clave' => 'motivo', 'titulo' => 'Motivo'],
                ['clave' => 'por', 'titulo' => 'Anulado por'],
                ['clave' => 'cuando', 'titulo' => 'Cuándo'],
            ],
            $anulaciones->map(fn (AnulacionValidacionPallet $a): array => [
                'folio' => $a->numero_folio,
                'motivo' => trim(($a->motivo_categoria ? $a->motivo_categoria.': ' : '').(string) $a->motivo, ': '),
                'por' => $a->anuladoPor?->name,
                'cuando' => $this->hora($a->anulado_at),
            ])->all(),
            '/oficina/validacion',
        );
    }
}
