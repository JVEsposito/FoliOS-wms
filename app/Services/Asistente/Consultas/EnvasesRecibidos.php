<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\TipoEnvaseRomana;
use App\Models\DetalleEnvaseRecepcionRomana;
use App\Models\SalidaEnvaseRecepcionRomana;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;

/**
 * Envases en Romana: los que entraron con la fruta en el periodo, por tipo (la cantidad
 * validada en Validación MP o, si todavía no se valida, la declarada en Romana), y los
 * que salieron con el camión en el destare.
 */
class EnvasesRecibidos extends ConsultaPorTema
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

    protected function palabrasDelTema(): array
    {
        return ['bins', 'bin', 'totes', 'tote', 'esponjas', 'esponja', 'smartpick', 'cosechero', 'cosecheros'];
    }

    public function vistaPorDefecto(): ?string
    {
        return 'recibidos';
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('recibidos', 'Llegaron con la fruta', 'Bins, totes y otros envases que llegaron con la fruta, por tipo, con los vacíos contados en Validación MP.',
                [], ['periodo', 'cliente']),
            // Solo frases: «destare» o «salieron» solos también son de tiempos de Romana o de despacho.
            new VistaConsulta('salidas-destare', 'Salidas en el destare', 'Envases que salieron con el camión en el destare de Romana, por recepción y tipo.',
                ['salieron con envases', 'envases salieron', 'en el destare', 'salidas de envases', 'devueltos en destare'], ['periodo', 'cliente']),
        ];
    }

    public function palabrasSecundarias(): array
    {
        return ['envases', 'envase', 'entraron', 'llegaron', 'recibidos', 'recibimos'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        return $vista->clave === 'salidas-destare' ? $this->salidasDestare($parametros) : $this->recibidos($parametros);
    }

    /** Envases que salieron con el camión en el destare de recepciones del periodo, por su hora de salida. */
    private function salidasDestare(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Envases que salieron en el destare';
        $temporada = $this->temporada($parametros);
        [$desde, $hasta] = $parametros->rango();
        $filas = $temporada === null ? collect() : SalidaEnvaseRecepcionRomana::query()
            ->join('recepciones_romana', 'recepciones_romana.id', '=', 'salidas_envases_recepcion_romana.recepcion_romana_id')
            ->where('recepciones_romana.temporada_id', $temporada->id)
            ->whereNull('recepciones_romana.eliminada_at')
            ->where('recepciones_romana.estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->where('recepciones_romana.salida_at', '>=', $desde)->where('recepciones_romana.salida_at', '<', $hasta)
            ->where('salidas_envases_recepcion_romana.cantidad', '>', 0)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('recepciones_romana.cliente_id', $id))
            ->orderBy('recepciones_romana.salida_at')
            ->get(['recepciones_romana.numero_recepcion', 'recepciones_romana.cliente_nombre_snapshot', 'recepciones_romana.patente_camion',
                'salidas_envases_recepcion_romana.tipo_envase', 'salidas_envases_recepcion_romana.cantidad']);
        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, "No salieron envases en el destare el {$this->periodo($parametros)}.", '/oficina/romana');
        }
        // Por salida, recepción y luego el orden del catálogo de envases.
        $filas = $filas->sortBy(fn (SalidaEnvaseRecepcionRomana $fila): array => [$fila->numero_recepcion, $fila->tipo_envase->orden()])
            ->map(fn (SalidaEnvaseRecepcionRomana $fila): array => [
                'recepcion' => $fila->numero_recepcion,
                'cliente' => $fila->cliente_nombre_snapshot,
                'patente' => $fila->patente_camion,
                'envase' => $fila->tipo_envase->etiqueta(),
                'cantidad' => $fila->cantidad,
            ])->values();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('El %s salieron %d envases en el destare de %d recepciones.', $this->periodo($parametros), $filas->sum('cantidad'), $filas->unique('recepcion')->count()),
            [ResultadoConsulta::cifra('Envases', $filas->sum('cantidad'))],
            [
                ['clave' => 'recepcion', 'titulo' => 'Recepción', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'patente', 'titulo' => 'Patente', 'tipo' => 'codigo'],
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'cantidad', 'titulo' => 'Cantidad', 'tipo' => 'numero'],
            ],
            $filas->all(),
            '/oficina/romana',
        );
    }

    private function recibidos(ParametrosConsulta $parametros): ResultadoConsulta
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
