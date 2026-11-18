<?php

namespace App\Services\Operacion;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionPallet;
use App\Enums\ResultadoValidacionPallet;
use App\Enums\TipoRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\ValidacionPallet;
use App\Services\Jornadas\CalendarioOperativo;
use Carbon\CarbonImmutable;

class ResumenIngresosOperacion
{
    private const LIMITE_RECEPCIONES = 20;

    public function __construct(private readonly CalendarioOperativo $calendario) {}

    public function recepcion(string $temporadaId, CarbonImmutable $ahora): array
    {
        $fruta = RecepcionRomana::query()
            ->where('temporada_id', $temporadaId)
            ->whereNull('eliminada_at')
            ->where('tipo_recepcion', '!=', TipoRecepcionRomana::SoloEnvases->value);
        $enCurso = (clone $fruta)->whereIn('estado', [
            EstadoRecepcionRomana::EnBasculaIngreso->value,
            EstadoRecepcionRomana::EnPesajeEnvases->value,
            EstadoRecepcionRomana::EnBasculaSalida->value,
        ]);
        $porEstado = (clone $enCurso)->select('estado')->selectRaw('COUNT(*) as total')
            ->groupBy('estado')->pluck('total', 'estado');
        $fecha = $this->calendario->fecha($ahora);
        $cerradas = $this->calendario->filtrar(
            (clone $fruta)->where('estado', EstadoRecepcionRomana::Cerrado->value),
            'ingreso_at',
            $fecha,
            $fecha,
        )->selectRaw('COUNT(*) as total, COALESCE(SUM(peso_neto), 0) as kilos')->first();

        $recepciones = $enCurso->with('guiasRecepcion:id,recepcion_romana_id,numero_guia_despacho')
            ->orderBy('ingreso_at')->orderBy('id')->limit(self::LIMITE_RECEPCIONES)
            ->get(['id', 'numero_recepcion', 'numero_guia_despacho', 'cliente_nombre_snapshot', 'patente_camion',
                'ingreso_at', 'estado', 'estado_validacion_mp']);

        return [
            'en_curso' => (int) $porEstado->sum(),
            'en_ingreso' => (int) $porEstado->get(EstadoRecepcionRomana::EnBasculaIngreso->value, 0),
            'en_pesaje' => (int) $porEstado->get(EstadoRecepcionRomana::EnPesajeEnvases->value, 0),
            'pendientes_destare' => (int) $porEstado->get(EstadoRecepcionRomana::EnBasculaSalida->value, 0),
            'cerradas_jornada' => (int) $cerradas->total,
            'kilos_netos_jornada' => (float) $cerradas->kilos,
            'recepciones' => $recepciones->map(fn (RecepcionRomana $r): array => [
                'numero' => $r->numero_recepcion,
                'cliente' => $r->cliente_nombre_snapshot,
                'guias' => $r->guiasRecepcion->isNotEmpty()
                    ? $r->guiasRecepcion->pluck('numero_guia_despacho')->all()
                    : array_values(array_filter([$r->numero_guia_despacho], fn ($numero) => $numero !== null && $numero !== '')),
                'patente' => $r->patente_camion,
                'ingreso_at' => $r->ingreso_at?->toAtomString(),
                'estado' => $r->estado->value,
                'validacion_mp' => $r->estado_validacion_mp?->value,
            ])->all(),
        ];
    }

    public function validacion(string $temporadaId, CarbonImmutable $ahora): array
    {
        $jornada = $this->calendario->fecha($ahora, inicioHistorico: '07:00');
        $base = ValidacionPallet::query()->where('temporada_id', $temporadaId)
            ->whereIn('estado', [EstadoValidacionPallet::Aceptada->value, EstadoValidacionPallet::Conflicto->value])
            ->deJornada($jornada);
        $conteos = (clone $base)->toBase()->select('estado', 'resultado')
            ->selectRaw('COUNT(*) as total')->groupBy('estado', 'resultado')->get();
        $aceptadas = $conteos->where('estado', EstadoValidacionPallet::Aceptada->value)->pluck('total', 'resultado');
        $recientes = $base->orderByDesc('recibido_servidor_at')->orderBy('id')->limit(12)
            ->get(['numero_folio', 'resultado', 'estado', 'linea_proceso', 'turno', 'recibido_servidor_at']);

        return [
            'procesadas' => (int) $aceptadas->sum(),
            'aprobadas' => (int) $aceptadas->get(ResultadoValidacionPallet::Aprobado->value, 0),
            'observadas' => (int) $aceptadas->get(ResultadoValidacionPallet::Observado->value, 0),
            'rechazadas' => (int) $aceptadas->get(ResultadoValidacionPallet::Rechazado->value, 0),
            'conflictos' => (int) $conteos->where('estado', EstadoValidacionPallet::Conflicto->value)->sum('total'),
            'recientes' => $recientes->map(fn (ValidacionPallet $v): array => [
                'folio' => $v->numero_folio,
                'resultado' => $v->estado === EstadoValidacionPallet::Conflicto
                    ? EstadoValidacionPallet::Conflicto->value : $v->resultado->value,
                'linea' => $v->linea_proceso,
                'turno' => $v->turno,
                'recibido_at' => $v->recibido_servidor_at?->toAtomString(),
            ])->all(),
        ];
    }
}
