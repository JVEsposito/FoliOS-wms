<?php

namespace App\Services\Envases;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoMovimientoEnvase;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\ValidacionMp;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Consultas para registrar o asociar la devolución de envases de un productor: los productores CSG
 * del cliente y lo que salió con una guía (productor, envases y propiedad), para no interpretar a mano.
 */
class ServicioDevolucionEnvases
{
    /** @return list<array{id: string, codigo: string, nombre: string}> */
    public function productores(Cliente $cliente): array
    {
        return $cliente->productoresCsg()->wherePivot('activo', true)
            ->orderBy('razon_social')->orderBy('codigo')->get(['productores_csg.id', 'codigo', 'razon_social'])
            ->map(fn ($productor): array => ['id' => $productor->id, 'codigo' => $productor->codigo, 'nombre' => $productor->razon_social])
            ->values()->all();
    }

    /**
     * Salidas del cliente con ese número de guía en la temporada: despachos con guía emitida y envases
     * que salieron en un destare de Romana. Incluye las devoluciones ya registradas con esa guía.
     *
     * @return array{numero: string, salidas: list<array<string, mixed>>, devoluciones: list<array<string, mixed>>}
     */
    public function salida(Cliente $cliente, Temporada $temporada, string $numero): array
    {
        $numero = mb_strtoupper(trim($numero));
        $salidas = [];

        $guias = GuiaDespachoEnvase::query()
            ->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)
            ->where('numero_guia_despacho', $numero)
            ->where('estado', EstadoGuiaDespachoEnvase::Confirmada->value)
            ->get();
        foreach ($guias as $guia) {
            $movimientos = MovimientoEnvase::query()->where('documento_id', $guia->id)
                ->whereIn('tipo_movimiento', [TipoMovimientoEnvase::DespachoCliente->value, TipoMovimientoEnvase::ReversionDespacho->value])
                ->get();
            $salidas[] = [
                'origen' => 'despacho',
                'documento' => $guia->numero,
                'fecha' => $this->local($movimientos->min('ocurrido_at')),
                'productor' => $guia->productorDestino(),
                'envases' => $this->envases($movimientos),
            ];
        }

        $destares = MovimientoEnvase::query()
            ->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::SalidaRecepcionFruta->value)
            ->where('numero_documento', $numero)->whereNull('movimiento_origen_id')
            ->get();
        if ($destares->isNotEmpty()) {
            $compensaciones = MovimientoEnvase::query()->where('temporada_id', $temporada->id)
                ->where(fn ($q) => $q->whereIn('movimiento_origen_id', $destares->pluck('id'))
                    ->orWhere(fn ($q) => $q->where('tipo_movimiento', TipoMovimientoEnvase::ConciliacionSalida->value)
                        ->whereIn('datos->movimiento_conciliado_id', $destares->pluck('id'))))
                ->get();
            $recepciones = RecepcionRomana::query()->whereKey($destares->pluck('recepcion_romana_id')->filter()->unique())
                ->get(['id', 'numero_recepcion', 'numero_guia_despacho'])->keyBy('id');
            foreach ($destares->groupBy('recepcion_romana_id') as $recepcionId => $lineas) {
                $ids = $lineas->pluck('id')->all();
                $vinculadas = $compensaciones->filter(fn (MovimientoEnvase $m): bool => in_array($m->movimiento_origen_id, $ids, true)
                    || in_array($m->datos['movimiento_conciliado_id'] ?? null, $ids, true));
                $recepcion = $recepciones->get($recepcionId);
                $salidas[] = [
                    'origen' => 'destare',
                    'documento' => $recepcion ? $recepcion->numero_recepcion.' · guía de ingreso '.$recepcion->numero_guia_despacho : $numero,
                    'fecha' => $this->local($lineas->min('ocurrido_at')),
                    'productor' => $recepcionId ? $this->productorRecepcion((string) $recepcionId) : null,
                    'envases' => $this->envases($lineas->concat($vinculadas)),
                ];
            }
        }

        $devoluciones = RecepcionRomana::query()
            ->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)
            ->where('devolucion_guia_salida', $numero)
            ->where('estado', '!=', EstadoRecepcionRomana::Anulada->value)
            ->orderBy('ingreso_at')
            ->get(['id', 'numero_recepcion', 'ingreso_at', 'devolucion_productor_snapshot'])
            ->map(fn (RecepcionRomana $r): array => [
                'id' => $r->id, 'numero_recepcion' => $r->numero_recepcion, 'fecha' => $this->local($r->ingreso_at),
                'productor' => $r->devolucion_productor_snapshot,
            ])->values()->all();

        return [
            'numero' => $numero,
            'salidas' => array_values(array_filter($salidas, fn (array $salida): bool => $salida['envases'] !== [])),
            'devoluciones' => $devoluciones,
        ];
    }

    /**
     * Una devolución no puede ser anterior a la salida que devuelve: el caso de una
     * recepción documental con fecha retroactiva que conserva la hora del registro.
     *
     * @throws ValidationException
     */
    public function asegurarPosteriorASalida(Cliente $cliente, Temporada $temporada, ?string $guia, CarbonImmutable $ingresoAt, string $campo): void
    {
        $guia = mb_strtoupper(trim((string) $guia));
        if ($guia === '') {
            return;
        }
        $salidaAt = $this->primeraSalida($cliente, $temporada, $guia);
        if ($salidaAt !== null && $ingresoAt->lt($salidaAt)) {
            throw ValidationException::withMessages([$campo => sprintf(
                'La devolución no puede ser anterior a la salida de la guía %s (%s). Indica la fecha y hora reales del reingreso.',
                $guia,
                $salidaAt->setTimezone(config('app.operational_timezone'))->format('d-m-Y H:i'),
            )]);
        }
    }

    /** Momento de la primera salida registrada con esa guía: despacho emitido o destare de Romana. */
    public function primeraSalida(Cliente $cliente, Temporada $temporada, string $numero): ?CarbonImmutable
    {
        $guias = GuiaDespachoEnvase::query()
            ->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)
            ->where('numero_guia_despacho', $numero)
            ->where('estado', EstadoGuiaDespachoEnvase::Confirmada->value)
            ->pluck('id');
        $despacho = $guias->isEmpty() ? null : MovimientoEnvase::query()->whereIn('documento_id', $guias)
            ->where('tipo_movimiento', TipoMovimientoEnvase::DespachoCliente->value)->min('ocurrido_at');
        $destare = MovimientoEnvase::query()
            ->where('temporada_id', $temporada->id)->where('cliente_id', $cliente->id)
            ->where('tipo_movimiento', TipoMovimientoEnvase::SalidaRecepcionFruta->value)
            ->where('numero_documento', $numero)->whereNull('movimiento_origen_id')
            ->min('ocurrido_at');
        $fechas = collect([$despacho, $destare])->filter()
            ->map(fn (mixed $fecha): CarbonImmutable => CarbonImmutable::parse($fecha, 'UTC'));

        return $fechas->isEmpty() ? null : $fechas->sort()->first();
    }

    /**
     * Productor de una recepción con fruta cuando todos sus segmentos validados son de un mismo productor.
     *
     * @return array{id: string, codigo: string, nombre: string}|null
     */
    private function productorRecepcion(string $recepcionId): ?array
    {
        $validacion = ValidacionMp::query()->where('recepcion_romana_id', $recepcionId)
            ->where('estado', EstadoValidacionMp::Validada->value)->with('segmentos.csg.productor')->first();
        $productores = ($validacion?->segmentos ?? collect())->map(fn ($segmento) => $segmento->csg?->productor)
            ->unique(fn ($productor) => $productor?->id);
        $productor = $productores->count() === 1 ? $productores->first() : null;

        return $productor ? ['id' => $productor->id, 'codigo' => $productor->codigo, 'nombre' => $productor->razon_social] : null;
    }

    /**
     * Envases netos que salieron, por tipo y propiedad.
     *
     * @param  Collection<int, MovimientoEnvase>  $movimientos
     * @return list<array{tipo_envase: string, propiedad: string, cantidad: int}>
     */
    private function envases(Collection $movimientos): array
    {
        return $movimientos->groupBy(fn (MovimientoEnvase $m): string => $m->tipo_envase->value.'|'.$m->propiedad->value)
            ->map(function (Collection $grupo, string $clave): array {
                [$tipo, $propiedad] = explode('|', $clave, 2);

                return ['tipo_envase' => $tipo, 'propiedad' => $propiedad,
                    'cantidad' => -(int) $grupo->sum(fn (MovimientoEnvase $m): int => $m->cantidad * $m->signo_existencia)];
            })
            ->filter(fn (array $linea): bool => $linea['cantidad'] > 0)
            ->sortBy('tipo_envase')->values()->all();
    }

    private function local(?DateTimeInterface $fecha): ?string
    {
        return $fecha ? CarbonImmutable::instance($fecha)->setTimezone(config('app.operational_timezone'))->format('Y-m-d H:i') : null;
    }
}
