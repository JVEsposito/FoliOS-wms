<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoEnvaseRomana;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Envases que entraron con la fruta en el periodo, por tipo: la cantidad validada en
 * Validación MP o, si todavía no se valida, la declarada en Romana.
 */
class EnvasesRecibidos extends ConsultaBase
{
    public function clave(): string
    {
        return 'envases-recibidos';
    }

    public function titulo(): string
    {
        return 'Envases recibidos';
    }

    public function descripcion(): string
    {
        return 'Bins, totes y otros envases que llegaron con la fruta, por tipo.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function palabrasClave(): array
    {
        return ['bins', 'bin', 'totes', 'tote', 'esponjas', 'esponja', 'smartpick', 'cosechero', 'cosecheros'];
    }

    public function palabrasSecundarias(): array
    {
        return ['envases', 'envase', 'entraron', 'llegaron', 'recibidos', 'recibimos'];
    }

    public function filtros(): array
    {
        return ['periodo', 'cliente'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $filas = $temporada === null ? collect() : DetalleEnvaseRecepcionRomana::query()
            ->join('recepciones_romana', 'recepciones_romana.id', '=', 'detalles_envases_recepcion_romana.recepcion_romana_id')
            ->where('recepciones_romana.temporada_id', $temporada->id)
            ->whereNull('recepciones_romana.eliminada_at')
            ->where('recepciones_romana.estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->where('recepciones_romana.ingreso_at', '>=', $desde)->where('recepciones_romana.ingreso_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('recepciones_romana.cliente_id', $id))
            ->groupBy('detalles_envases_recepcion_romana.tipo_envase')
            ->selectRaw('detalles_envases_recepcion_romana.tipo_envase as tipo, count(distinct recepciones_romana.id) as recepciones, sum(coalesce(detalles_envases_recepcion_romana.cantidad_validada, detalles_envases_recepcion_romana.cantidad_declarada, 0)) as cantidad, sum(coalesce(detalles_envases_recepcion_romana.cantidad_vacia, 0)) as vacios')
            ->get();

        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No entraron envases el {$this->periodo($parametros)}.", '/oficina/romana');
        }

        $filas = $filas->map(fn ($fila): array => [
            'tipo' => TipoEnvaseRomana::tryFrom((string) $fila->tipo)?->etiqueta() ?? (string) $fila->tipo,
            'cantidad' => (int) $fila->cantidad,
            'vacios' => (int) $fila->vacios,
            'recepciones' => (int) $fila->recepciones,
        ])->sortByDesc('cantidad')->values();
        $resumen = $filas->map(fn (array $f): string => number_format($f['cantidad'], 0, ',', '.').' '.mb_strtolower($f['tipo']))->implode(', ');

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            "El {$this->periodo($parametros)} entraron {$resumen}.",
            $filas->take(3)->map(fn (array $f): array => ResultadoConsulta::cifra($f['tipo'], $f['cantidad']))->all(),
            [
                ['clave' => 'tipo', 'titulo' => 'Envase'],
                ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'numero'],
                ['clave' => 'vacios', 'titulo' => 'Vacíos', 'tipo' => 'numero'],
                ['clave' => 'recepciones', 'titulo' => 'Recepciones', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/romana',
        );
    }
}
