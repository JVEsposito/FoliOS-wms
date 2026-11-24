<?php

namespace App\Services\Asistente\Consultas;

use App\Enums\EstadoOperacionalFolio;
use App\Models\RetencionLoteSag;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Asistente\ParametrosConsulta;
use App\Services\Asistente\ResultadoConsulta;
use App\Services\Autorizacion\AlcanceOperacionalUsuario;
use App\Services\InspeccionSag\ServicioRetencionesLoteSag;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Illuminate\Support\Collection;

/**
 * Lotes retenidos SAG con las mismas reglas que su módulo: el estado sale del accessor de
 * `RetencionLoteSag` (una retención con destinos parciales es «liberado con restricciones»)
 * y los pallets, de `foliosDelLote()`, por cliente y lote de recepción, incluso en mezclas.
 */
class RetencionesSag extends ConsultaPorTema
{
    private const ENLACE = '/oficina/frigorifico/lotes-retenidos-sag';

    /** Pallets que se listan, como máximo; el resto se ve en el módulo. */
    private const MAXIMO_PALLETS = 100;

    private const ESTADOS = [
        'con_observaciones' => 'Con observaciones',
        'retenido' => 'Retenido',
        RetencionLoteSag::LIBERADO_CON_RESTRICCIONES => 'Liberado con restricciones',
    ];

    public function __construct(
        AlcanceOperacionalUsuario $alcance,
        ServicioTemporadaActiva $temporadas,
        private readonly ServicioRetencionesLoteSag $retenciones,
    ) {
        parent::__construct($alcance, $temporadas);
    }

    public function clave(): string
    {
        return 'retenciones-sag';
    }

    public function titulo(): string
    {
        return 'Lotes retenidos SAG';
    }

    public function descripcion(): string
    {
        return 'Seguimientos SAG vigentes de la temporada (retenidos, con observaciones o liberados con restricciones) y los pallets en planta con cajas de esos lotes. No lista lotes ya liberados ni pallets despachados, ni responde lotes retenidos por Hidrocooler.';
    }

    public function dominio(): string
    {
        return 'frigorifico';
    }

    public function palabrasSecundarias(): array
    {
        return ['lote', 'lotes'];
    }

    /** Solo contrapartes SAG y administración, como el módulo. */
    public function puedeEjecutar(User $usuario): bool
    {
        return $this->alcance->puedeGestionarRetencionesLoteSag($usuario);
    }

    protected function palabrasDelTema(): array
    {
        return ['sag'];
    }

    protected function vistasDelTema(): array
    {
        return [
            new VistaConsulta('vigentes', 'Lotes vigentes', 'Lotes con seguimiento SAG no liberado: estado, destinos restringidos, fecha de detección y cuántos pallets y cajas del lote siguen en planta.',
                ['retenido', 'retenidos', 'retenida', 'retenidas', 'retencion', 'retenciones', 'vigentes', 'observaciones',
                    'restricciones', 'restriccion', 'destinos', 'lotes retenidos', 'con observaciones'],
                ['cliente']),
            new VistaConsulta('folios-afectados', 'Pallets con fruta retenida', 'Pallets en planta con cajas de un lote SAG vigente, aunque sea una caja o una mezcla: folio, lote, cajas del lote, ubicación y carga asignada.',
                // Pallets, folios y cajas solo en frases: sueltas son de Existencias PT.
                ['afectados', 'afectado', 'ubicacion', 'fruta retenida', 'pallets retenidos', 'folios retenidos', 'cajas retenidas',
                    'pallets afectados', 'folios afectados', 'pallets con fruta'],
                ['cliente']),
        ];
    }

    protected function ejecutarVista(VistaConsulta $vista, User $usuario, ParametrosConsulta $parametros): ResultadoConsulta
    {
        $casos = $this->vigentes($this->temporada($parametros), $parametros);

        return $vista->clave === 'folios-afectados' ? $this->foliosAfectados($casos) : $this->listaVigentes($casos);
    }

    /** @param  Collection<int, array{caso: RetencionLoteSag, folios: Collection}>  $casos */
    private function listaVigentes(Collection $casos): ResultadoConsulta
    {
        $titulo = 'Lotes retenidos SAG vigentes';
        if ($casos->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, 'No hay lotes SAG retenidos, con observaciones ni liberados con restricciones en la temporada.', self::ENLACE);
        }
        $filas = $casos->map(fn (array $c): array => [
            'lote' => $c['caso']->numero_lote, 'cliente' => $c['caso']->cliente?->nombre,
            'estado' => self::ESTADOS[$c['caso']->estado] ?? $c['caso']->estado, 'destinos' => $this->destinos($c['caso']),
            'deteccion' => $c['caso']->fecha_deteccion->format('Y-m-d'),
            'pallets' => $c['folios']->count(), 'cajas' => (int) $c['folios']->sum('cajas_lote'),
        ])->values()->all();
        $porEstado = collect($filas)->countBy('estado');
        $detalle = collect(self::ESTADOS)->map(fn (string $etiqueta): string => mb_strtolower($etiqueta).': '.($porEstado[$etiqueta] ?? 0))->implode(', ');
        // Un pallet mezclado con cajas de dos lotes vigentes cuenta una vez en el total.
        $pallets = $casos->flatMap(fn (array $c): Collection => $c['folios']->pluck('id'))->unique()->count();

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('Hay %d lotes SAG vigentes (%s). Sus cajas están en %d pallets en planta.', count($filas), $detalle, $pallets),
            [ResultadoConsulta::cifra('Lotes vigentes', count($filas)), ResultadoConsulta::cifra('Pallets en planta', $pallets)],
            [
                ['clave' => 'lote', 'titulo' => 'Lote', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'estado', 'titulo' => 'Estado'],
                ['clave' => 'destinos', 'titulo' => 'Destinos restringidos'],
                ['clave' => 'deteccion', 'titulo' => 'Detección'],
                ['clave' => 'pallets', 'titulo' => 'Pallets en planta', 'tipo' => 'numero'],
                ['clave' => 'cajas', 'titulo' => 'Cajas del lote', 'tipo' => 'numero'],
            ],
            $filas,
            self::ENLACE,
        );
    }

    /** @param  Collection<int, array{caso: RetencionLoteSag, folios: Collection}>  $casos */
    private function foliosAfectados(Collection $casos): ResultadoConsulta
    {
        $titulo = 'Pallets con fruta retenida SAG';
        $filas = $casos->flatMap(fn (array $c): Collection => $c['folios']->map(fn (array $f): array => [
            'folio' => $f['folio'], 'lote' => $c['caso']->numero_lote, 'cliente' => $c['caso']->cliente?->nombre,
            'estado' => self::ESTADOS[$c['caso']->estado] ?? $c['caso']->estado,
            'cajas' => $f['cajas_lote'], 'ubicacion' => $f['ubicacion'] !== '' ? $f['ubicacion'] : null, 'carga' => $f['carga'],
        ]))->values();
        if ($filas->isEmpty()) {
            return ResultadoConsulta::sinDatos($titulo, 'Ningún pallet en planta tiene cajas de lotes SAG vigentes.', self::ENLACE);
        }
        $pallets = $filas->pluck('folio')->unique()->count();
        $mas = $filas->count() > self::MAXIMO_PALLETS ? sprintf(' Muestro %d de %d líneas.', self::MAXIMO_PALLETS, $filas->count()) : '';

        return new ResultadoConsulta(
            ResultadoConsulta::RESPONDIDA,
            $titulo,
            sprintf('%d pallets en planta tienen cajas de %d lotes SAG vigentes.%s', $pallets, $filas->pluck('lote')->unique()->count(), $mas),
            [ResultadoConsulta::cifra('Pallets', $pallets), ResultadoConsulta::cifra('Cajas de lotes vigentes', (int) $filas->sum('cajas'))],
            [
                ['clave' => 'folio', 'titulo' => 'Folio', 'tipo' => 'codigo'],
                ['clave' => 'lote', 'titulo' => 'Lote', 'tipo' => 'codigo'],
                ['clave' => 'cliente', 'titulo' => 'Cliente'],
                ['clave' => 'estado', 'titulo' => 'Estado del lote'],
                ['clave' => 'cajas', 'titulo' => 'Cajas del lote', 'tipo' => 'numero'],
                ['clave' => 'ubicacion', 'titulo' => 'Ubicación', 'tipo' => 'codigo'],
                ['clave' => 'carga', 'titulo' => 'Carga', 'tipo' => 'codigo'],
            ],
            $filas->take(self::MAXIMO_PALLETS)->all(),
            self::ENLACE,
        );
    }

    /**
     * Seguimientos no liberados, cada uno con sus pallets todavía en planta.
     *
     * @return Collection<int, array{caso: RetencionLoteSag, folios: Collection}>
     */
    private function vigentes(?Temporada $temporada, ParametrosConsulta $parametros): Collection
    {
        if ($temporada === null) {
            return collect();
        }
        $fuera = [EstadoOperacionalFolio::Despachado->value, EstadoOperacionalFolio::Anulado->value,
            EstadoOperacionalFolio::Agotado->value, EstadoOperacionalFolio::RetiradoDefinitivo->value];

        return RetencionLoteSag::with('cliente:id,nombre')
            ->where('temporada_id', $temporada->id)->where('estado', '!=', 'liberado')
            ->when($parametros->clienteId, fn ($q, $id) => $q->where('cliente_id', $id))
            ->orderBy('fecha_deteccion')->orderBy('numero_lote')->get()
            ->map(fn (RetencionLoteSag $caso): array => ['caso' => $caso, 'folios' => $this->retenciones->foliosDelLote($caso)
                ->filter(fn (array $f): bool => $f['activo'] && ! in_array($f['estado'], $fuera, true))->values()]);
    }

    /** El mismo texto de destinos que muestra el módulo. */
    private function destinos(RetencionLoteSag $caso): string
    {
        if ($caso->todos_destinos) {
            return 'Todos los destinos';
        }

        return collect($caso->destinos)->pluck('nombre')->filter()->implode(' · ') ?: 'Sin restricciones de destino';
    }
}
