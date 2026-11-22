<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Kilos brutos, tara y netos de las recepciones cerradas, por cliente.
 */
class KilosRecepciones extends ConsultaBase
{
    public function clave(): string
    {
        return 'kilos-recepciones';
    }

    public function titulo(): string
    {
        return 'Kilos recibidos';
    }

    public function descripcion(): string
    {
        return 'Kilos brutos, tara y netos de las recepciones cerradas, por cliente.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function palabrasClave(): array
    {
        return ['kilos', 'kg', 'kilo', 'neto', 'netos', 'bruto', 'brutos', 'recepcion', 'recepciones', 'recibido', 'recibidos', 'ingreso', 'peso'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    public function ejecutar(User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $filas = $temporada === null ? collect() : RecepcionRomana::query()
            ->where('temporada_id', $temporada->id)
            ->whereNull('eliminada_at')
            ->where('estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('ingreso_at', '>=', $desde)->where('ingreso_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->groupBy('cliente_id', 'cliente_nombre_snapshot')
            ->selectRaw('cliente_nombre_snapshot as cliente, count(*) as recepciones, sum(peso_bruto) as bruto, sum(peso_tara) as tara, sum(peso_neto) as neto')
            ->orderByDesc('neto')
            ->get();

        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($this->titulo(), "No hay recepciones cerradas el {$this->periodo($parametros)}.", '/oficina/romana');
        }

        $redondeo = fn ($valor): float => round((float) $valor, 1);
        $neto = $redondeo($filas->sum('neto'));

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $this->titulo(),
            sprintf('El %s se recibieron %s kg netos en %d recepciones.', $this->periodo($parametros), number_format($neto, 1, ',', '.'), (int) $filas->sum('recepciones')),
            [
                ResultadoConsulta::cifra('Kilos netos', $neto, 'kg'),
                ResultadoConsulta::cifra('Kilos brutos', $redondeo($filas->sum('bruto')), 'kg'),
                ResultadoConsulta::cifra('Recepciones', (int) $filas->sum('recepciones')),
            ],
            [
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'recepciones', 'titulo' => 'Recepciones', 'tipo' => 'numero'],
                ['clave' => 'bruto', 'titulo' => 'Bruto (kg)', 'tipo' => 'numero'],
                ['clave' => 'tara', 'titulo' => 'Tara (kg)', 'tipo' => 'numero'],
                ['clave' => 'neto', 'titulo' => 'Neto (kg)', 'tipo' => 'numero'],
            ],
            $filas->map(fn ($fila): array => [
                'cliente' => $fila->cliente,
                'recepciones' => (int) $fila->recepciones,
                'bruto' => $redondeo($fila->bruto),
                'tara' => $redondeo($fila->tara),
                'neto' => $redondeo($fila->neto),
            ])->all(),
            '/oficina/romana',
        );
    }
}
