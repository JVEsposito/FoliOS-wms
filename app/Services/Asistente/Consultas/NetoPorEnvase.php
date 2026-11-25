<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoRecepcionRomana;
use App\Models\RangoTaraAsistente;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kilos netos por tote o bin de las recepciones cerradas, como los calcula Romana
 * (`peso_neto_por_envase` sobre `cantidad_envase_calculo_neto`). El promedio pondera por
 * envases y el rango es el mismo de Revisión de taras: el de la especie, o el general.
 */
class NetoPorEnvase extends ConsultaPorTema
{
    private const ENLACE = '/oficina/romana';

    private const NOMBRE = ['totes' => 'tote', 'bins' => 'bin'];

    public function clave(): string
    {
        return 'neto-por-envase';
    }

    public function titulo(): string
    {
        return 'Neto por envase';
    }

    public function descripcion(): string
    {
        return 'Kilos netos de fruta por tote o por bin de las recepciones cerradas: promedio ponderado, mínimo, máximo y recepciones fuera del rango de la planta, o el neto por envase de cada CSG. No responde la tara de un envase vacío ni revisa errores de tara.';
    }

    public function dominio(): string
    {
        return 'materia-prima';
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarRomana($usuario);
    }

    protected function palabrasDelTema(): array
    {
        // Las frases van en las vistas: el intérprete solo lee frases de vistas.
        return ['promedio', 'promedios'];
    }

    protected function vistasDelTema(): array
    {
        $filtros = ['periodo', 'cliente', 'csg', 'especie'];

        return [
            new VistaConsulta('totes', 'Totes', 'Kilos netos por tote de cada recepción cerrada, con el promedio ponderado, el mínimo, el máximo y las recepciones fuera del rango de la planta.',
                ['tote', 'totes', 'neto por tote', 'kilos por tote', 'peso por tote', 'promedio de totes', 'promedio por tote'], $filtros),
            new VistaConsulta('bins', 'Bins', 'Kilos netos por bin de cada recepción cerrada, con el promedio ponderado, el mínimo, el máximo y las recepciones fuera del rango de la planta.',
                ['bin', 'bins', 'neto por bin', 'kilos por bin', 'peso por bin', 'promedio de bins', 'promedio por bin'], $filtros),
            new VistaConsulta('por-csg', 'Por CSG', 'Kilos netos por tote y por bin de cada CSG, según sus lotes de materia prima.',
                // «Neto por tote por CSG» también nombra el tote: estas frases pesan más que las de la vista de totes.
                ['por csg', 'por productor', 'cada csg', 'de cada csg', 'por cada csg', 'tote por csg', 'totes por csg', 'bin por csg', 'bins por csg'], $filtros),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $temporada = $this->temporada($parametros);

        return $vista->clave === 'por-csg' ? $this->porCsg($temporada, $parametros) : $this->porRecepcion($vista->clave, $temporada, $parametros);
    }

    private function porRecepcion(string $tipo, ?Temporada $temporada, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $nombre = self::NOMBRE[$tipo];
        $titulo = "Neto por {$nombre}";
        [$desde, $hasta] = $parametros->rango();
        $recepciones = $temporada === null ? collect() : RecepcionRomana::query()
            ->leftJoin('especies_validacion as especie', 'especie.id', '=', 'recepciones_romana.especie_validacion_id')
            ->where('recepciones_romana.temporada_id', $temporada->id)
            ->whereNull('recepciones_romana.eliminada_at')
            ->where('recepciones_romana.estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('recepciones_romana.ingreso_at', '>=', $desde)->where('recepciones_romana.ingreso_at', '<', $hasta)
            ->where('recepciones_romana.tipo_envase_calculo_neto', $tipo)
            ->whereNotNull('recepciones_romana.peso_neto_por_envase')
            ->where('recepciones_romana.cantidad_envase_calculo_neto', '>', 0)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('recepciones_romana.cliente_id', $id))
            // El CSG está en los lotes de la recepción, no en la recepción.
            ->when($parametros->csgId, fn ($q, $id) => $q->whereHas('lotesMateriaPrima', fn ($l) => $l->whereNull('anulado_at')->where('csg_validacion_id', $id)))
            ->when($parametros->especieId, fn ($q, $id) => $q->where('recepciones_romana.especie_validacion_id', $id))
            ->orderBy('recepciones_romana.ingreso_at')->orderBy('recepciones_romana.numero_recepcion')
            ->get(['recepciones_romana.numero_recepcion', 'recepciones_romana.ingreso_at', 'recepciones_romana.cliente_nombre_snapshot',
                'recepciones_romana.cantidad_envase_calculo_neto', 'recepciones_romana.peso_neto_por_envase', 'especie.nombre as especie']);
        if ($recepciones->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, "No hay recepciones cerradas en {$tipo} con neto por envase el {$this->periodo($parametros)}.", self::ENLACE);
        }
        $rangos = [];
        $filas = $recepciones->map(function (RecepcionRomana $recepcion) use ($tipo, &$rangos): array {
            $valor = round((float) $recepcion->peso_neto_por_envase, 3);
            $envases = (int) $recepcion->cantidad_envase_calculo_neto;
            $rango = $rangos[$recepcion->especie ?? ''] ??= RangoTaraAsistente::vigentePara($tipo, $recepcion->especie);

            return [
                'recepcion' => $recepcion->numero_recepcion, 'ingreso' => $recepcion->ingreso_at?->format('Y-m-d H:i'),
                'cliente' => $recepcion->cliente_nombre_snapshot, 'envases' => $envases,
                'neto' => round($valor * $envases, 1), 'neto_por_envase' => $valor, 'rango' => $this->rango($rango, $valor),
            ];
        });
        $envases = $filas->sum('envases');
        $promedio = round($filas->sum(fn (array $f): float => $f['neto_por_envase'] * $f['envases']) / $envases, 3);
        $minimo = $filas->min('neto_por_envase');
        $maximo = $filas->max('neto_por_envase');
        $fuera = $filas->filter(fn (array $f): bool => $f['rango'] !== null && $f['rango'] !== 'Dentro')->count();
        $conRango = $filas->contains(fn (array $f): bool => $f['rango'] !== null);
        $cierre = $conRango ? sprintf('%d quedaron fuera del rango de la planta.', $fuera) : "no hay un rango de neto configurado para {$tipo}.";

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('El %s, %d recepciones en %s promediaron %s kg netos por %s (entre %s y %s kg); %s',
                $this->periodo($parametros), $filas->count(), $tipo, $this->kg($promedio), $nombre, $this->kg($minimo), $this->kg($maximo), $cierre),
            [
                ResultadoConsulta::cifra("Promedio por {$nombre}", $promedio, 'kg'),
                ResultadoConsulta::cifra('Mínimo', $minimo, 'kg'),
                ResultadoConsulta::cifra('Máximo', $maximo, 'kg'),
                ResultadoConsulta::cifra('Recepciones', $filas->count()),
                ResultadoConsulta::cifra('Fuera de rango', $fuera),
            ],
            [
                ['clave' => 'recepcion', 'titulo' => 'Recepción', 'tipo' => 'codigo'],
                ['clave' => 'ingreso', 'titulo' => 'Ingreso'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'envases', 'titulo' => ucfirst($tipo), 'tipo' => 'numero'],
                ['clave' => 'neto', 'titulo' => 'Neto (kg)', 'tipo' => 'numero'],
                ['clave' => 'neto_por_envase', 'titulo' => "Kg por {$nombre}", 'tipo' => 'numero'],
                ['clave' => 'rango', 'titulo' => 'Rango'],
            ],
            $filas->all(),
            self::ENLACE,
        );
    }

    private function porCsg(?Temporada $temporada, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $titulo = 'Neto por envase por CSG';
        [$desde, $hasta] = $parametros->rango();
        $lotes = $temporada === null ? collect() : $this->lotes($temporada, $parametros, $desde, $hasta);
        if ($lotes->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, "No hay lotes en totes o bins de recepciones cerradas el {$this->periodo($parametros)}.", self::ENLACE);
        }
        $filas = $lotes->groupBy(fn ($lote): string => $lote->csg.'|'.$lote->envase)->sortKeys()
            ->map(fn (Collection $grupo): array => [
                'csg' => $grupo->first()->csg, 'envase' => $grupo->first()->envase, 'lotes' => $grupo->count(),
                'envases' => (int) $grupo->sum('cantidad'), 'neto' => round((float) $grupo->sum('kilos'), 1),
                'neto_por_envase' => round((float) $grupo->sum('kilos') / (int) $grupo->sum('cantidad'), 3),
            ])->values();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('El %s, %d CSG entregaron fruta en totes o bins. El neto por envase sale de los kilos netos y envases de sus lotes.', $this->periodo($parametros), $filas->pluck('csg')->unique()->count()),
            [ResultadoConsulta::cifra('CSG', $filas->pluck('csg')->unique()->count()), ResultadoConsulta::cifra('Lotes', (int) $filas->sum('lotes'))],
            [
                ['clave' => 'csg', 'titulo' => 'CSG', 'tipo' => 'codigo'],
                ['clave' => 'envase', 'titulo' => 'Envase'],
                ['clave' => 'lotes', 'titulo' => 'Lotes', 'tipo' => 'numero'],
                ['clave' => 'envases', 'titulo' => 'Envases', 'tipo' => 'numero'],
                ['clave' => 'neto', 'titulo' => 'Neto (kg)', 'tipo' => 'numero'],
                ['clave' => 'neto_por_envase', 'titulo' => 'Kg por envase', 'tipo' => 'numero'],
            ],
            $filas->all(),
            self::ENLACE,
        );
    }

    /** Lotes no anulados en totes o bins, de recepciones cerradas del periodo. */
    private function lotes(Temporada $temporada, ParametrosConsulta $parametros, $desde, $hasta): Collection
    {
        return DB::table('lotes_materia_prima as lote')
            ->join('recepciones_romana as recepcion', 'recepcion.id', '=', 'lote.recepcion_romana_id')
            ->where('lote.temporada_id', $temporada->id)
            ->whereNull('lote.anulado_at')->where('lote.estado', '!=', 'anulado')
            ->whereIn('lote.envase_primario', array_keys(self::NOMBRE))
            ->where('lote.cantidad_envases_primarios', '>', 0)
            ->whereNull('recepcion.eliminada_at')
            ->where('recepcion.estado', EstadoRecepcionRomana::Cerrado->value)
            ->where('recepcion.ingreso_at', '>=', $desde)->where('recepcion.ingreso_at', '<', $hasta)
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('lote.cliente_id', $id))
            ->when($parametros->csgId, fn ($q, $id) => $q->where('lote.csg_validacion_id', $id))
            ->when($parametros->especieId, fn ($q, $id) => $q->where('lote.especie_validacion_id', $id))
            ->get(['lote.csg_snapshot as csg', 'lote.envase_primario as envase', 'lote.cantidad_envases_primarios as cantidad', 'lote.kilos_netos_confirmados as kilos']);
    }

    private function rango(?RangoTaraAsistente $rango, float $valor): ?string
    {
        if ($rango === null) {
            return null;
        }
        if ($rango->contiene($valor)) {
            return 'Dentro';
        }

        return $valor < $rango->minimo_kg ? 'Bajo '.$this->kg($rango->minimo_kg) : 'Sobre '.$this->kg($rango->maximo_kg);
    }

    private function kg(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3, ',', '.'), '0'), ',');
    }
}
