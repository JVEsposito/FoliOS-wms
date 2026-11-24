<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoLoteMateriaPrima;
use App\Models\LoteMateriaPrima;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lotes de materia prima de la temporada activa. Los estados se cuentan como en el panel
 * gerencial, sin anulados, y los bins por entregar como en Fruta a proceso: bins del lote
 * menos las entregas no anuladas.
 */
class LotesMateriaPrima extends ConsultaPorTema
{
    private const ENLACE = '/oficina/materia-prima/lotes';

    /** Lotes que se muestran, como máximo, en la vista de antigüedad; el resto se ve en la oficina. */
    private const MAXIMO_LOTES = 30;

    /** Activos como los cuenta el panel gerencial: recibidos y sin entregar del todo a proceso. */
    private const SIN_ENTREGAR = [
        EstadoLoteMateriaPrima::PendienteHidrocooler, EstadoLoteMateriaPrima::HidrocoolerEnCurso,
        EstadoLoteMateriaPrima::HidrocoolerRetenido, EstadoLoteMateriaPrima::PendienteAsignacion,
        EstadoLoteMateriaPrima::DisponibleProceso, EstadoLoteMateriaPrima::AsignadoCamara,
        EstadoLoteMateriaPrima::EntregaParcialProceso,
    ];

    public function clave(): string
    {
        return 'lotes-mp';
    }

    public function titulo(): string
    {
        return 'Lotes de materia prima';
    }

    public function descripcion(): string
    {
        return 'Lotes de materia prima de la temporada: cuántos hay en cada estado (hidrocooler, cámara, disponibles, entregados a Packing) y los más antiguos que siguen sin entregarse a proceso. No responde kilos recibidos por CSG, órdenes de proceso por turno ni lotes retenidos por SAG.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function vistaPorDefecto(): ?string
    {
        return 'por-estado';
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarMateriaPrima($usuario);
    }

    protected function palabrasDelTema(): array
    {
        // «Proceso» suelto también es de órdenes de proceso por turno y de prefrío: va en frases.
        return ['lote', 'lotes'];
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('por-estado', 'Por estado', 'Lotes, kilos netos y bins por entregar en cada estado: hidrocooler, retenidos por Hidrocooler, pendientes de cámara, en cámara, disponibles directo a proceso, entrega parcial y entregados a Packing.',
                ['estado', 'estados', 'hidrocooler', 'disponibles', 'disponible', 'camara', 'parcial', 'entregados',
                    'por estado', 'retenidos en hidrocooler', 'retenido en hidrocooler', 'retenidos por hidrocooler', 'entrega parcial',
                    'para proceso', 'directo a proceso'],
                ['cliente', 'csg', 'especie']),
            new VistaConsulta('antiguedad', 'Más antiguos', 'Lotes recibidos que siguen sin entregarse del todo a proceso, del más antiguo al más nuevo según su ingreso a Romana, con días, cámara y bins por entregar.',
                ['antiguos', 'antiguo', 'antiguedad', 'viejos', 'dias', 'resguardo', 'esperando',
                    'mas antiguos', 'sin proceso', 'sin entregar', 'siguen sin'],
                ['cliente', 'csg', 'especie'], 'ingreso a Romana, del más antiguo al más nuevo'),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);

        return $vista->clave === 'antiguedad' ? $this->antiguedad($temporada, $parametros) : $this->porEstado($temporada, $parametros);
    }

    private function porEstado(?Temporada $temporada, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Lotes de materia prima por estado';
        $grupos = $temporada === null ? collect() : $this->base($temporada, $parametros)
            ->groupBy('lotes_materia_prima.estado')
            ->select('lotes_materia_prima.estado')
            ->selectRaw('count(*) as lotes, sum(lotes_materia_prima.kilos_netos_confirmados) as kilos')
            ->selectRaw($this->binsPorEntregar(sumar: true).' as bins')
            ->toBase()->get()->keyBy('estado');
        if ($grupos->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, 'No hay lotes de materia prima en la temporada.', self::ENLACE);
        }
        $filas = [];
        foreach (EstadoLoteMateriaPrima::cases() as $estado) {
            if ($estado !== EstadoLoteMateriaPrima::Anulado && $grupos->has($estado->value)) {
                $grupo = $grupos->get($estado->value);
                $filas[] = ['estado' => $estado->etiqueta(), 'lotes' => (int) $grupo->lotes, 'kilos' => round((float) $grupo->kilos, 1), 'bins' => (int) $grupo->bins];
            }
        }
        $activos = $grupos->only(array_map(fn (EstadoLoteMateriaPrima $e): string => $e->value, self::SIN_ENTREGAR));
        $lotes = (int) $activos->sum('lotes');
        $kilos = round((float) $activos->sum('kilos'), 1);

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('Hay %d lotes activos, recibidos y sin entregar del todo a proceso, con %s kg netos.', $lotes, number_format($kilos, 1, ',', '.')),
            [ResultadoConsulta::cifra('Lotes activos', $lotes), ResultadoConsulta::cifra('Kilos activos', $kilos, 'kg')],
            [
                ['clave' => 'estado', 'titulo' => 'Estado'],
                ['clave' => 'lotes', 'titulo' => 'Lotes', 'tipo' => 'numero'],
                ['clave' => 'kilos', 'titulo' => 'Kilos netos', 'tipo' => 'numero'],
                ['clave' => 'bins', 'titulo' => 'Bins por entregar', 'tipo' => 'numero'],
            ],
            $filas,
            self::ENLACE,
        );
    }

    private function antiguedad(?Temporada $temporada, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Lotes de materia prima más antiguos';
        $consulta = $temporada === null ? null : $this->base($temporada, $parametros)
            ->whereIn('lotes_materia_prima.estado', array_map(fn (EstadoLoteMateriaPrima $e): string => $e->value, self::SIN_ENTREGAR));
        $total = $consulta?->count() ?? 0;
        if ($total === 0) {
            return ResultadoConsulta::sinDatos($titulo, 'No hay lotes de materia prima esperando proceso.', self::ENLACE);
        }
        $ahora = CarbonImmutable::now();
        $lotes = $consulta
            ->join('recepciones_romana', 'recepciones_romana.id', '=', 'lotes_materia_prima.recepcion_romana_id')
            ->join('clientes', 'clientes.id', '=', 'lotes_materia_prima.cliente_id')
            ->leftJoin('asignaciones_camara_lote_materia_prima as asignacion', 'asignacion.lote_materia_prima_id', '=', 'lotes_materia_prima.id')
            ->leftJoin('camaras', 'camaras.id', '=', 'asignacion.camara_id')
            ->orderBy('recepciones_romana.ingreso_at')->orderBy('lotes_materia_prima.numero_lote')
            ->limit(self::MAXIMO_LOTES)
            ->select(['lotes_materia_prima.numero_lote', 'clientes.nombre as cliente', 'lotes_materia_prima.csg_snapshot as csg',
                'lotes_materia_prima.estado', 'camaras.codigo as camara', 'recepciones_romana.ingreso_at', 'lotes_materia_prima.kilos_netos_confirmados as kilos'])
            ->selectRaw($this->binsPorEntregar(sumar: false).' as bins')
            ->toBase()->get();
        $filas = $lotes->map(fn ($lote): array => [
            'lote' => $lote->numero_lote, 'cliente' => $lote->cliente, 'csg' => $lote->csg,
            'estado' => EstadoLoteMateriaPrima::from($lote->estado)->etiqueta(), 'camara' => $lote->camara,
            'dias' => (int) floor(CarbonImmutable::parse($lote->ingreso_at)->diffInDays($ahora, true)),
            'bins' => (int) $lote->bins, 'kilos' => round((float) $lote->kilos, 1),
        ])->all();
        $mas = $total > count($filas) ? sprintf(' Muestro los %d más antiguos de %d.', count($filas), $total) : '';

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('El lote más antiguo sin entregar a proceso es %s, con %d días desde su ingreso a Romana.%s', $filas[0]['lote'], $filas[0]['dias'], $mas),
            [ResultadoConsulta::cifra('Lotes esperando proceso', $total)],
            [
                ['clave' => 'lote', 'titulo' => 'Lote', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'csg', 'titulo' => 'CSG', 'tipo' => 'codigo'],
                ['clave' => 'estado', 'titulo' => 'Estado'],
                ['clave' => 'camara', 'titulo' => 'Cámara', 'tipo' => 'codigo'],
                ['clave' => 'dias', 'titulo' => 'Días', 'tipo' => 'numero'],
                ['clave' => 'bins', 'titulo' => 'Bins por entregar', 'tipo' => 'numero'],
                ['clave' => 'kilos', 'titulo' => 'Kilos netos', 'tipo' => 'numero'],
            ],
            $filas,
            self::ENLACE,
        );
    }

    /** Lotes de la temporada sin anulados, con los filtros reconocidos y las entregas a proceso no anuladas. */
    private function base(Temporada $temporada, ParametrosConsulta $parametros): Builder
    {
        $entregas = DB::table('entregas_fruta_proceso')->whereNull('anulado_at')
            ->groupBy('lote_materia_prima_id')->select('lote_materia_prima_id')->selectRaw('sum(cantidad_envases) as entregados');

        return LoteMateriaPrima::query()
            ->leftJoinSub($entregas, 'entregas', 'entregas.lote_materia_prima_id', '=', 'lotes_materia_prima.id')
            ->where('lotes_materia_prima.temporada_id', $temporada->id)
            ->where('lotes_materia_prima.estado', '!=', EstadoLoteMateriaPrima::Anulado->value)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('lotes_materia_prima.cliente_id', $id))
            ->when($parametros->csgId, fn ($q, $id) => $q->where('lotes_materia_prima.csg_validacion_id', $id))
            ->when($parametros->especieId, fn ($q, $id) => $q->where('lotes_materia_prima.especie_validacion_id', $id));
    }

    /** Fruta a proceso solo entrega lotes en bins: los de otro envase no tienen bins por entregar. */
    private function binsPorEntregar(bool $sumar): string
    {
        $bins = "case when lotes_materia_prima.envase_primario = 'bins' and lotes_materia_prima.cantidad_envases_primarios > coalesce(entregas.entregados, 0)"
            .' then lotes_materia_prima.cantidad_envases_primarios - coalesce(entregas.entregados, 0) else 0 end';

        return $sumar ? "sum({$bins})" : $bins;
    }
}
