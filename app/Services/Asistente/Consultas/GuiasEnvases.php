<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Models\GuiaDespachoEnvase;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use Illuminate\Support\Collection;

/**
 * Guías de despacho de envases: las que siguen en borrador, las confirmadas en un
 * periodo y las confirmadas sin el N° de la guía emitida en facturación.
 */
class GuiasEnvases extends ConsultaPorTema
{
    private const ENLACE = '/oficina/envases/despachos';

    public function clave(): string
    {
        return 'guias-envases';
    }

    public function titulo(): string
    {
        return 'Guías de despacho de envases';
    }

    public function descripcion(): string
    {
        return 'Guías de despacho de envases: en borrador, confirmadas en un periodo o sin el N° de la guía emitida.';
    }

    public function dominio(): string
    {
        return 'envases';
    }

    public function vistaPorDefecto(): ?string
    {
        return 'borrador';
    }

    public function palabrasSecundarias(): array
    {
        return ['despacho', 'despachos', 'envases', 'envase', 'guia', 'guias'];
    }

    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeConsultarCuentaEnvases($usuario) || $this->alcance->puedeGestionarDespachoEnvases($usuario);
    }

    protected function palabrasDelTema(): array
    {
        return ['gde'];
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('borrador', 'En borrador', 'Guías de despacho de envases en borrador, armadas pero sin salida confirmada, con su cliente y patente.',
                ['borrador', 'borradores', 'pendiente', 'pendientes', 'por confirmar', 'sin confirmar', 'faltan por confirmar', 'falta confirmar'], ['cliente']),
            new VistaConsulta('confirmadas', 'Confirmadas', 'Guías de despacho de envases confirmadas en el periodo, por su hora de salida.',
                ['confirmada', 'confirmadas', 'confirmaron', 'confirmo', 'salida', 'salidas'], ['periodo', 'cliente']),
            new VistaConsulta('sin-numero', 'Sin N° de guía emitida', 'Guías ya confirmadas que todavía no tienen registrado el N° de la guía emitida.',
                ['numero', 'numeros', 'emitida', 'emitidas', 'registrar', 'sin numero'], ['cliente']),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        return match ($vista->clave) {
            'confirmadas' => $this->confirmadas($parametros),
            'sin-numero' => $this->sinNumero($parametros),
            default => $this->borrador($parametros),
        };
    }

    private function borrador(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $guias = $this->guias(EstadoGuiaDespachoEnvase::Borrador, $parametros)
            ->orderBy('created_at')
            ->get(['numero', 'numero_guia_despacho', 'cliente_nombre_snapshot', 'patente_camion', 'created_at']);

        if ($guias->isEmpty()) {
            return ResultadoConsulta::sinDatos('Despachos de envases sin confirmar', 'No hay despachos de envases en borrador.', self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            'Despachos de envases sin confirmar',
            sprintf('Hay %d despachos de envases en borrador, sin salida confirmada.', $guias->count()),
            [ResultadoConsulta::cifra('En borrador', $guias->count())],
            [
                ['clave' => 'numero', 'titulo' => 'Despacho', 'tipo' => 'codigo'],
                ['clave' => 'guia', 'titulo' => 'Guía'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'patente', 'titulo' => 'Patente', 'tipo' => 'codigo'],
                ['clave' => 'creado', 'titulo' => 'Creado'],
            ],
            $guias->map(fn (GuiaDespachoEnvase $g): array => [
                'numero' => $g->numero,
                'guia' => $g->numero_guia_despacho,
                'cliente' => $g->cliente_nombre_snapshot,
                'patente' => $g->patente_camion,
                'creado' => $this->hora($g->created_at),
            ])->all(),
            self::ENLACE,
        );
    }

    private function confirmadas(ParametrosConsulta $parametros): ResultadoConsulta
    {
        [$desde, $hasta] = $parametros->rango();
        $guias = $this->guias(EstadoGuiaDespachoEnvase::Confirmada, $parametros)
            ->where('salida_at', '>=', $desde)->where('salida_at', '<', $hasta)
            ->orderBy('salida_at')
            ->get(['numero', 'numero_guia_despacho', 'cliente_nombre_snapshot', 'patente_camion', 'salida_at']);
        $titulo = 'Guías de envases confirmadas';

        if ($guias->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, "No hay guías de envases confirmadas el {$this->periodo($parametros)}.", self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('El %s se confirmaron %d guías de despacho de envases.', $this->periodo($parametros), $guias->count()),
            [ResultadoConsulta::cifra('Confirmadas', $guias->count())],
            $this->columnasConfirmadas(),
            $this->filasConfirmadas($guias),
            self::ENLACE,
        );
    }

    private function sinNumero(ParametrosConsulta $parametros): ResultadoConsulta
    {
        $guias = $this->guias(EstadoGuiaDespachoEnvase::Confirmada, $parametros)
            ->whereNull('numero_guia_despacho')
            ->orderBy('salida_at')
            ->get(['numero', 'numero_guia_despacho', 'cliente_nombre_snapshot', 'patente_camion', 'salida_at']);
        $titulo = 'Guías de envases sin N° de guía emitida';

        if ($guias->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, 'Todas las guías de envases confirmadas tienen registrado el N° de la guía emitida.', self::ENLACE);
        }

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('Hay %d guías de envases confirmadas sin el N° de la guía emitida.', $guias->count()),
            [ResultadoConsulta::cifra('Sin N° emitido', $guias->count())],
            array_values(array_filter($this->columnasConfirmadas(), fn (array $c): bool => $c['clave'] !== 'guia')),
            array_map(fn (array $fila): array => array_diff_key($fila, ['guia' => true]), $this->filasConfirmadas($guias)),
            self::ENLACE,
        );
    }

    /** Guías de la temporada activa en un estado, filtradas por cliente si la pregunta lo nombra. */
    private function guias(EstadoGuiaDespachoEnvase $estado, ParametrosConsulta $parametros)
    {
        $temporada = $this->temporada($parametros);

        return GuiaDespachoEnvase::query()
            ->where('estado', $estado->value)
            ->when($estado !== EstadoGuiaDespachoEnvase::Borrador && $temporada !== null, fn ($q) => $q->where('temporada_id', $temporada->id))
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id));
    }

    /** @return list<array<string, string>> */
    private function columnasConfirmadas(): array
    {
        return [
            ['clave' => 'numero', 'titulo' => 'Despacho', 'tipo' => 'codigo'],
            ['clave' => 'guia', 'titulo' => 'Guía'],
            ['clave' => 'cliente', 'titulo' => 'Cliente'],
            ['clave' => 'patente', 'titulo' => 'Patente', 'tipo' => 'codigo'],
            ['clave' => 'salida', 'titulo' => 'Salida'],
        ];
    }

    /**
     * @param  Collection<int, GuiaDespachoEnvase>  $guias
     * @return list<array<string, ?string>>
     */
    private function filasConfirmadas(Collection $guias): array
    {
        return $guias->map(fn (GuiaDespachoEnvase $g): array => [
            'numero' => $g->numero,
            'guia' => $g->numero_guia_despacho,
            'cliente' => $g->cliente_nombre_snapshot,
            'patente' => $g->patente_camion,
            'salida' => $this->hora($g->salida_at),
        ])->values()->all();
    }
}
