<?php

namespace App\Services\Romana;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoRevisionMovimientoEnvase;
use App\Enums\EstadoValidacionMp;
use App\Enums\PropiedadEnvase;
use App\Enums\TipoEnvaseRomana;
use App\Enums\TipoEventoRomana;
use App\Enums\TipoMovimientoEnvase;
use App\Enums\TipoRecepcionRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\DetalleGuiaDespachoEnvase;
use App\Models\EventoRecepcionRomana;
use App\Models\GuiaRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\User;
use App\Models\ValidacionMp;
use App\Services\Envases\DocumentoSalidaEnvases;
use App\Services\MateriaPrima\ServicioLoteMateriaPrima;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Corrección administrativa de una recepción ya validada en Validación MP, en la temporada activa.
 *
 * El administrador envía el estado correcto completo: número y envases declarados de cada guía, conteo de cada
 * segmento de la validación, propiedad por tipo de envase y, si la recepción está cerrada, el destare (tara,
 * envases que salen y su guía). El servicio:
 *
 * - nunca reescribe movimientos de envases: agrega correcciones enlazadas al ingreso o reversiones de salida,
 *   que la cuenta corriente pliega sobre la línea original;
 * - actualiza detalles, guías, segmentos y lotes MP, y concilia los kilos de los lotes con el destare corregido;
 * - deja un evento con el estado anterior, el posterior y el motivo.
 *
 * Bloquea solo lo que físicamente ya no puede cambiar: un lote con entregas a proceso o una salida ya conciliada
 * con un despacho, si la corrección los afecta.
 */
class ServicioCorreccionIntegralRecepcion
{
    private const INGRESOS = [TipoMovimientoEnvase::RecepcionFruta, TipoMovimientoEnvase::RecepcionArriendo, TipoMovimientoEnvase::RecepcionCompra];

    /** Movimientos que la cuenta corriente pliega sobre el ingreso de origen. */
    private const CORRECCIONES = [TipoMovimientoEnvase::CorreccionCantidad, TipoMovimientoEnvase::CorreccionPropiedad,
        TipoMovimientoEnvase::CorreccionCuenta, TipoMovimientoEnvase::AnulacionRecepcion];

    /** Estado editable que Oficina muestra antes de corregir. */
    public function estado(RecepcionRomana $recepcion): array
    {
        $recepcion->load(['detallesEnvases', 'guiasRecepcion', 'salidasEnvases']);
        $validacion = ValidacionMp::query()->where('recepcion_romana_id', $recepcion->id)
            ->with(['segmentos.envases', 'segmentos.csg', 'segmentos.lotesMateriaPrima'])->first();
        $lotes = LoteMateriaPrima::query()->where('recepcion_romana_id', $recepcion->id)
            ->where('estado', '!=', EstadoLoteMateriaPrima::Anulado->value)->get();

        return [
            'recepcion_id' => $recepcion->id, 'numero_recepcion' => $recepcion->numero_recepcion, 'version' => $recepcion->version,
            'tipo_recepcion' => $recepcion->tipo_recepcion->value, 'estado' => $recepcion->estado->value,
            'validada' => $validacion?->estado === EstadoValidacionMp::Validada,
            'cerrada' => $recepcion->estado === EstadoRecepcionRomana::Cerrado,
            'propiedad_editable' => $recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases,
            'cantidades_editables' => $recepcion->tipo_recepcion !== TipoRecepcionRomana::FrutaPesajeEnvases,
            'tipos_envase' => TipoEnvaseRomana::catalogo(),
            'guias' => $recepcion->guiasRecepcion->sortBy('secuencia')->values()->map(fn (GuiaRecepcionRomana $g): array => [
                'id' => $g->id, 'numero_guia_despacho' => $g->numero_guia_despacho, 'envases' => $g->envases,
            ])->all(),
            'segmentos' => ($validacion?->segmentos ?? collect())->sortBy('secuencia')->values()->map(fn (SegmentoValidacionMp $s): array => [
                'id' => $s->id, 'secuencia' => $s->secuencia, 'guia_recepcion_romana_id' => $s->guia_recepcion_romana_id,
                'csg' => $s->csg_snapshot ?: $s->csg?->codigo, 'variedad' => $s->variedad_snapshot,
                'lotes' => $s->lotesMateriaPrima->where('estado', '!=', EstadoLoteMateriaPrima::Anulado)->pluck('numero_lote')->values()->all(),
                'envases' => $s->envases->map(fn ($e): array => ['tipo_envase' => $e->tipo_envase->value, 'cantidad' => (int) $e->cantidad])->values()->all(),
            ])->all(),
            'envases' => $recepcion->detallesEnvases->map(fn ($d): array => [
                'tipo_envase' => $d->tipo_envase->value, 'propiedad' => $recepcion->propiedadEnvase($d->tipo_envase->value)->value,
                'cantidad_declarada' => (int) $d->cantidad_declarada, 'cantidad_validada' => (int) $d->cantidad_validada,
                'tara_unitaria_salida' => $d->tara_unitaria_salida !== null ? (float) $d->tara_unitaria_salida : null,
            ])->values()->all(),
            'destare' => $recepcion->estado === EstadoRecepcionRomana::Cerrado ? [
                'peso_bruto' => (float) $recepcion->peso_bruto, 'peso_tara' => (float) $recepcion->peso_tara,
                'peso_neto' => (float) $recepcion->peso_neto, 'modo_salida_envases' => $recepcion->modo_salida_envases,
                'numero_guia_salida' => $recepcion->numero_guia_salida,
                'salida_envases' => $recepcion->salidasEnvases->map(fn ($s): array => ['tipo_envase' => $s->tipo_envase->value, 'cantidad' => (int) $s->cantidad])->values()->all(),
            ] : null,
            'lotes' => $lotes->map(fn (LoteMateriaPrima $l): array => ['numero_lote' => $l->numero_lote, 'estado' => $l->estado->value,
                'kilos_netos' => $l->kilos_netos_confirmados !== null ? (float) $l->kilos_netos_confirmados : null,
                'con_entregas' => in_array($l->estado, [EstadoLoteMateriaPrima::EntregaParcialProceso, EstadoLoteMateriaPrima::EntregadoProceso], true)])->values()->all(),
        ];
    }

    public function corregir(RecepcionRomana $recepcion, array $datos, User $usuario): RecepcionRomana
    {
        Gate::forUser($usuario)->authorize('corregir-recepciones-validadas');
        $payload = $this->normalizar($datos);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($recepcion, $datos, $usuario, $payload, $hash): RecepcionRomana {
            $recepcion = RecepcionRomana::query()->with(['detallesEnvases', 'guiasRecepcion', 'salidasEnvases'])
                ->lockForUpdate()->findOrFail($recepcion->id);
            $evento = EventoRecepcionRomana::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($evento) {
                if ($evento->recepcion_romana_id !== $recepcion->id || $evento->payload_hash !== $hash || $evento->tipo !== TipoEventoRomana::CorreccionIntegral) {
                    throw new ConflictoOperacion('El identificador de operación ya fue utilizado con datos diferentes.');
                }

                return $recepcion;
            }
            $this->asegurarCorregible($recepcion, $payload);
            $validacion = ValidacionMp::query()->where('recepcion_romana_id', $recepcion->id)->lockForUpdate()->firstOrFail();
            $segmentos = $validacion->segmentos()->with(['envases', 'lotesMateriaPrima.envasesDetalle'])->lockForUpdate()->get()->keyBy('id');
            $antes = $this->estado($recepcion);
            $ahora = CarbonImmutable::now();
            $contexto = ['operacion_id' => $datos['operacion_id'], 'motivo' => $payload['motivo'], 'usuario_id' => $usuario->id, 'corregido_at' => $ahora->toAtomString()];

            $declarados = $this->corregirGuias($recepcion, $payload['guias']);
            $validados = $recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases
                ? $this->validadosSoloEnvases($payload)
                : $this->corregirSegmentos($recepcion, $segmentos, $payload['segmentos']);
            $this->asegurarCantidadesPermitidas($recepcion, $validados);
            $propiedades = $this->propiedades($recepcion, $payload, $validados);
            $this->sincronizarDetalles($recepcion, $declarados, $validados, $propiedades, $payload['destare']['taras_envases'] ?? []);
            $this->guiasValidadas($recepcion, $segmentos, $validados);
            $this->ajustarIngresos($recepcion, $validados, $propiedades, $usuario, $contexto);
            $cambioKilos = $recepcion->estado === EstadoRecepcionRomana::Cerrado
                && $this->corregirDestare($recepcion, $payload['destare'], $validados, $usuario, $ahora, $contexto);
            $cambioLotes = $this->sincronizarLotes($recepcion, $segmentos, $usuario);
            if (($cambioKilos || $cambioLotes) && $recepcion->peso_neto !== null && $recepcion->tipo_envase_calculo_neto) {
                app(ServicioLoteMateriaPrima::class)->conciliarDestare($recepcion->refresh(), $usuario,
                    correccionPeso: ['operacion_romana_id' => (string) $datos['operacion_id'], 'motivo' => $payload['motivo']]);
            }
            $recepcion->update(['version' => $recepcion->version + 1]);
            $despues = $this->estado($recepcion->refresh());
            if ($this->comparable($antes) === $this->comparable($despues)) {
                throw new ConflictoOperacion('La corrección no presenta cambios respecto de lo registrado.');
            }
            EventoRecepcionRomana::create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, 'recepcion_romana_id' => $recepcion->id,
                'tipo' => TipoEventoRomana::CorreccionIntegral, 'estado_anterior' => $recepcion->estado, 'estado_nuevo' => $recepcion->estado,
                'user_id' => $usuario->id, 'ocurrido_at' => $ahora,
                'datos' => ['motivo' => $payload['motivo'], 'anterior' => $this->comparable($antes), 'posterior' => $this->comparable($despues)],
            ]);

            return $recepcion->refresh();
        }, attempts: 3);
    }

    private function normalizar(array $datos): array
    {
        $envases = fn (array $lista): array => collect($lista)->map(fn ($e): array => ['tipo_envase' => (string) $e['tipo_envase'], 'cantidad' => (int) $e['cantidad']])
            ->filter(fn ($e): bool => $e['cantidad'] > 0)->sortBy('tipo_envase')->values()->all();
        $destare = $datos['destare'] ?? null;

        return [
            'recepcion_version' => (int) $datos['version_conocida'],
            'motivo' => trim((string) $datos['motivo_correccion']),
            'guias' => collect($datos['guias'])->map(fn ($g): array => ['id' => (string) $g['id'],
                'numero_guia_despacho' => mb_strtoupper(trim((string) $g['numero_guia_despacho'])), 'envases' => $envases($g['envases'] ?? [])])->sortBy('id')->values()->all(),
            'segmentos' => collect($datos['segmentos'] ?? [])->map(fn ($s): array => ['id' => (string) $s['id'], 'envases' => $envases($s['envases'] ?? [])])->sortBy('id')->values()->all(),
            'envases_validados' => $envases($datos['envases_validados'] ?? []),
            'propiedades' => collect($datos['propiedades'] ?? [])->mapWithKeys(fn ($p): array => [(string) $p['tipo_envase'] => (string) $p['propiedad']])->sortKeys()->all(),
            'destare' => $destare === null ? null : [
                'peso_tara' => round((float) $destare['peso_tara'], 2),
                'modo_salida_envases' => (string) $destare['modo_salida_envases'],
                'numero_guia_salida' => filled($destare['numero_guia_salida'] ?? null) ? trim((string) $destare['numero_guia_salida']) : null,
                'salida_envases' => $envases($destare['salida_envases'] ?? []),
                'taras_envases' => collect($destare['taras_envases'] ?? [])->mapWithKeys(fn ($t): array => [(string) $t['tipo_envase'] => round((float) $t['tara_unitaria'], 3)])->sortKeys()->all(),
            ],
        ];
    }

    private function asegurarCorregible(RecepcionRomana $recepcion, array $payload): void
    {
        if ($recepcion->estado === EstadoRecepcionRomana::Anulada || $recepcion->eliminada_at !== null) {
            throw new ConflictoOperacion('La recepción está anulada o eliminada y no admite correcciones.');
        }
        if ($recepcion->estado_validacion_mp !== EstadoValidacionMp::Validada) {
            throw new ConflictoOperacion('La recepción aún no está validada: corrígela con la corrección administrativa de Romana.');
        }
        if ($recepcion->temporada_id !== app(ServicioTemporadaActiva::class)->buscar(bloquear: true)?->id) {
            throw new ConflictoOperacion('Solo se corrigen recepciones de la temporada activa.');
        }
        if ($recepcion->version !== $payload['recepcion_version']) {
            throw new ConflictoOperacion('La recepción cambió. Actualiza el expediente antes de corregirla.');
        }
        if ($payload['destare'] !== null && $recepcion->estado !== EstadoRecepcionRomana::Cerrado) {
            throw ValidationException::withMessages(['destare' => 'La recepción aún no tiene destare: corrige solo guías y conteos.']);
        }
        if ($recepcion->estado === EstadoRecepcionRomana::Cerrado && $recepcion->tipo_recepcion === TipoRecepcionRomana::FrutaConEnvases
            && $payload['destare'] === null) {
            throw ValidationException::withMessages(['destare' => 'Incluye el destare de la recepción cerrada.']);
        }
        if ($payload['destare'] !== null && $recepcion->tipo_recepcion !== TipoRecepcionRomana::FrutaConEnvases) {
            throw ValidationException::withMessages(['destare' => 'El destare por camión solo se corrige en recepciones de fruta con envases.']);
        }
    }

    /** @return array<string, int> declarados por tipo */
    private function corregirGuias(RecepcionRomana $recepcion, array $guias): array
    {
        $vigentes = $recepcion->guiasRecepcion()->where('vigente', 1)->lockForUpdate()->get()->keyBy('id');
        if ($vigentes->keys()->sort()->values()->all() !== collect($guias)->pluck('id')->sort()->values()->all()) {
            throw ValidationException::withMessages(['guias' => 'Envía cada guía vigente de la recepción, sin agregar ni quitar documentos.']);
        }
        $numeros = array_column($guias, 'numero_guia_despacho');
        if (in_array('', $numeros, true) || count($numeros) !== count(array_unique($numeros))) {
            throw ValidationException::withMessages(['guias' => 'Cada guía necesita un número, sin repetirlo dentro del camión.']);
        }
        $declarados = [];
        foreach ($guias as $guia) {
            $existe = GuiaRecepcionRomana::query()->where('temporada_id', $recepcion->temporada_id)->where('cliente_id', $recepcion->cliente_id)
                ->where('numero_guia_despacho', $guia['numero_guia_despacho'])->where('vigente', 1)->where('recepcion_romana_id', '!=', $recepcion->id)->exists();
            if ($existe) {
                throw new ConflictoOperacion("La guía {$guia['numero_guia_despacho']} ya está registrada para este cliente en otra recepción.");
            }
            foreach ($guia['envases'] as $envase) {
                $this->tipoValido($envase['tipo_envase']);
                $declarados[$envase['tipo_envase']] = ($declarados[$envase['tipo_envase']] ?? 0) + $envase['cantidad'];
            }
        }
        // Dos pasos para no chocar con la clave única al intercambiar números entre guías del mismo camión.
        foreach ($vigentes as $vigente) {
            $vigente->update(['numero_guia_despacho' => 'TMP-'.Str::lower(Str::random(12))]);
        }
        foreach ($guias as $guia) {
            $vigentes[$guia['id']]->update(['numero_guia_despacho' => $guia['numero_guia_despacho'], 'envases' => $guia['envases']]);
        }
        $ordenadas = $recepcion->guiasRecepcion()->where('vigente', 1)->orderBy('secuencia')->get(['numero_guia_despacho'])->toArray();
        $resumen = ServicioGuiasRecepcionRomana::resumen($ordenadas);
        $recepcion->update(['numero_guia_despacho' => $resumen, 'cantidad_envases_declarados' => array_sum($declarados)]);
        // El número de documento de los ingresos sigue a la guía corregida (dato documental, no cantidades).
        MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
            ->whereIn('tipo_movimiento', array_map(fn ($t) => $t->value, [...self::INGRESOS, ...self::CORRECCIONES]))
            ->update(['numero_documento' => $resumen]);
        $recepcion->unsetRelation('guiasRecepcion');

        return $declarados;
    }

    /**
     * @param  Collection<string, SegmentoValidacionMp>  $segmentos
     * @return array<string, int> validados por tipo
     */
    private function corregirSegmentos(RecepcionRomana $recepcion, Collection $segmentos, array $corregidos): array
    {
        if ($segmentos->keys()->sort()->values()->all() !== collect($corregidos)->pluck('id')->sort()->values()->all()) {
            throw ValidationException::withMessages(['segmentos' => 'Envía el conteo de cada segmento de la validación.']);
        }
        $reparto = app(ServicioRepartoEnvases::class)->tipos($recepcion);
        $validados = [];
        foreach ($corregidos as $i => $corregido) {
            $segmento = $segmentos[$corregido['id']];
            $tieneReparto = false;
            foreach ($corregido['envases'] as $envase) {
                $this->tipoValido($envase['tipo_envase']);
                $validados[$envase['tipo_envase']] = ($validados[$envase['tipo_envase']] ?? 0) + $envase['cantidad'];
                $tieneReparto = $tieneReparto || in_array($envase['tipo_envase'], $reparto, true);
            }
            if (! $tieneReparto) {
                throw ValidationException::withMessages(["segmentos.{$i}.envases" => "El segmento {$segmento->secuencia} debe conservar al menos un envase de reparto con fruta."]);
            }
            $actual = $segmento->envases->mapWithKeys(fn ($e): array => [$e->tipo_envase->value => (int) $e->cantidad])->filter()->sortKeys()->all();
            $nuevo = collect($corregido['envases'])->mapWithKeys(fn ($e): array => [$e['tipo_envase'] => $e['cantidad']])->sortKeys()->all();
            if ($actual === $nuevo) {
                continue;
            }
            $segmento->envases()->whereNotIn('tipo_envase', array_keys($nuevo))->delete();
            foreach ($nuevo as $tipo => $cantidad) {
                $segmento->envases()->updateOrCreate(['tipo_envase' => $tipo], ['cantidad' => $cantidad]);
            }
            $segmento->load('envases');
        }

        return $validados;
    }

    private function validadosSoloEnvases(array $payload): array
    {
        $validados = [];
        foreach ($payload['envases_validados'] as $envase) {
            $this->tipoValido($envase['tipo_envase']);
            $validados[$envase['tipo_envase']] = $envase['cantidad'];
        }
        if ($validados === []) {
            throw ValidationException::withMessages(['envases_validados' => 'Indica los envases recibidos.']);
        }

        return $validados;
    }

    private function asegurarCantidadesPermitidas(RecepcionRomana $recepcion, array $validados): void
    {
        if ($recepcion->tipo_recepcion !== TipoRecepcionRomana::FrutaPesajeEnvases) {
            return;
        }
        $actuales = $recepcion->detallesEnvases->mapWithKeys(fn ($d): array => [$d->tipo_envase->value => (int) $d->cantidad_validada])->filter()->sortKeys()->all();
        ksort($validados);
        if ($actuales !== $validados) {
            throw new ConflictoOperacion('En pesaje acumulativo las cantidades vienen de las tandas: corrige la tanda en Romana.');
        }
    }

    /** @return array<string, PropiedadEnvase> */
    private function propiedades(RecepcionRomana $recepcion, array $payload, array $validados): array
    {
        $propiedades = [];
        foreach (array_keys($validados) as $tipo) {
            if ($recepcion->tipo_recepcion === TipoRecepcionRomana::SoloEnvases) {
                $propiedades[$tipo] = $recepcion->propiedadEnvase($tipo);

                continue;
            }
            $indicada = $payload['propiedades'][$tipo] ?? null;
            $propiedades[$tipo] = $indicada !== null
                ? (PropiedadEnvase::tryFrom($indicada) ?? throw ValidationException::withMessages(['propiedades' => "La propiedad de {$tipo} no es válida."]))
                : $recepcion->propiedadEnvase($tipo);
        }

        return $propiedades;
    }

    private function sincronizarDetalles(RecepcionRomana $recepcion, array $declarados, array $validados, array $propiedades, array $taras): void
    {
        $tipos = array_unique([...array_keys($declarados), ...array_keys($validados)]);
        $recepcion->detallesEnvases()->whereNotIn('tipo_envase', $tipos)->delete();
        foreach ($tipos as $tipo) {
            $recepcion->detallesEnvases()->updateOrCreate(['tipo_envase' => $tipo], [
                'cantidad_declarada' => $declarados[$tipo] ?? 0, 'cantidad_validada' => $validados[$tipo] ?? 0,
                ...($recepcion->tipo_recepcion !== TipoRecepcionRomana::SoloEnvases && isset($propiedades[$tipo]) ? ['propiedad' => $propiedades[$tipo]] : []),
                ...(isset($taras[$tipo]) ? ['tara_unitaria_salida' => $taras[$tipo]] : []),
            ]);
        }
        $recepcion->load('detallesEnvases');
    }

    private function guiasValidadas(RecepcionRomana $recepcion, Collection $segmentos, array $validados): void
    {
        $guias = $recepcion->guiasRecepcion()->where('vigente', 1)->get();
        foreach ($guias as $guia) {
            $sumas = [];
            if ($guias->count() === 1) {
                $sumas = $validados;
            } else {
                foreach ($segmentos->where('guia_recepcion_romana_id', $guia->id) as $segmento) {
                    foreach ($segmento->envases as $envase) {
                        $sumas[$envase->tipo_envase->value] = ($sumas[$envase->tipo_envase->value] ?? 0) + (int) $envase->cantidad;
                    }
                }
            }
            ksort($sumas);
            $guia->update(['envases_validados' => collect($sumas)->filter()->map(fn ($n, $t): array => ['tipo_envase' => $t, 'cantidad' => $n])->values()->all()]);
        }
        $recepcion->unsetRelation('guiasRecepcion');
    }

    /**
     * Lleva la cuenta corriente y la existencia de cada tipo a lo validado, con correcciones enlazadas al ingreso.
     */
    private function ajustarIngresos(RecepcionRomana $recepcion, array $validados, array $propiedades, User $usuario, array $contexto): void
    {
        $ingresos = MovimientoEnvase::query()->where('recepcion_romana_id', $recepcion->id)
            ->whereIn('tipo_movimiento', array_map(fn ($t) => $t->value, self::INGRESOS))->lockForUpdate()->orderBy('created_at')->get();
        $correcciones = MovimientoEnvase::query()->whereIn('movimiento_origen_id', $ingresos->pluck('id'))->lockForUpdate()->get()->groupBy('movimiento_origen_id');
        $cadenas = $ingresos->map(function (MovimientoEnvase $ingreso) use ($correcciones): array {
            $propias = $correcciones->get($ingreso->id, collect());
            if ($propias->contains(fn ($m): bool => ! in_array($m->tipo_movimiento, self::CORRECCIONES, true)
                || in_array($m->tipo_movimiento, [TipoMovimientoEnvase::CorreccionPropiedad, TipoMovimientoEnvase::CorreccionCuenta, TipoMovimientoEnvase::AnulacionRecepcion], true))) {
                throw new ConflictoOperacion("El ingreso de {$ingreso->tipo_envase->etiqueta()} ya tiene una corrección de propiedad, de cuenta o un vínculo previo. Corrígelo desde la cuenta corriente.");
            }

            return ['ingreso' => $ingreso, 'neto' => $ingreso->cantidad * $ingreso->signo_existencia
                + $propias->sum(fn ($m): int => $m->cantidad * $m->signo_existencia)];
        })->groupBy(fn (array $cadena): string => $cadena['ingreso']->tipo_envase->value);

        foreach (array_unique([...array_keys($validados), ...$cadenas->keys()->all()]) as $tipo) {
            $objetivo = $validados[$tipo] ?? 0;
            $propiedad = $propiedades[$tipo] ?? null;
            $vigentes = $cadenas->get($tipo, collect())->filter(fn (array $c): bool => $c['neto'] !== 0)->values();
            $actual = $vigentes->sum('neto');
            $mismaPropiedad = $vigentes->every(fn (array $c): bool => $c['ingreso']->propiedad === $propiedad);
            if ($actual === $objetivo && ($objetivo === 0 || $mismaPropiedad)) {
                continue;
            }
            if ($vigentes->isNotEmpty() && $mismaPropiedad && $objetivo > 0) {
                // Misma propiedad: una corrección de cantidad sobre el ingreso principal.
                $principal = $vigentes->sortByDesc('neto')->first()['ingreso'];
                $this->correccion($principal, $objetivo - $actual, $usuario, $contexto);

                continue;
            }
            foreach ($vigentes as $cadena) {
                $this->correccion($cadena['ingreso'], -$cadena['neto'], $usuario, $contexto);
            }
            if ($objetivo > 0) {
                $this->nuevoIngreso($recepcion, $tipo, $objetivo, $propiedad, $usuario, $contexto);
            }
        }
    }

    private function correccion(MovimientoEnvase $ingreso, int $diferencia, User $usuario, array $contexto): void
    {
        if ($diferencia === 0) {
            return;
        }
        $signo = $diferencia > 0 ? 1 : -1;
        MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $ingreso->temporada_id, 'cliente_id' => $ingreso->cliente_id,
            'recepcion_romana_id' => $ingreso->recepcion_romana_id, 'documento_tipo' => $ingreso->documento_tipo,
            'documento_id' => $ingreso->documento_id, 'numero_documento' => $ingreso->numero_documento,
            'tipo_movimiento' => TipoMovimientoEnvase::CorreccionCantidad, 'tipo_envase' => $ingreso->tipo_envase,
            'cantidad' => abs($diferencia), 'signo_cuenta' => $signo * $ingreso->signo_cuenta, 'signo_existencia' => $signo,
            'propiedad' => $ingreso->propiedad, 'movimiento_origen_id' => $ingreso->id, 'ocurrido_at' => now(),
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $usuario->id,
            'datos' => ['correccion_integral' => [...$contexto, 'diferencia' => $diferencia]],
        ]);
    }

    private function nuevoIngreso(RecepcionRomana $recepcion, string $tipo, int $cantidad, ?PropiedadEnvase $propiedad, User $usuario, array $contexto): void
    {
        [$movimiento, $signoCuenta] = match ($recepcion->tipo_recepcion) {
            TipoRecepcionRomana::SoloEnvases => $propiedad === PropiedadEnvase::Arrendada
                ? [TipoMovimientoEnvase::RecepcionArriendo, 1] : [TipoMovimientoEnvase::RecepcionCompra, 0],
            default => [TipoMovimientoEnvase::RecepcionFruta, 1],
        };
        MovimientoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $recepcion->temporada_id, 'cliente_id' => $recepcion->cliente_id,
            'recepcion_romana_id' => $recepcion->id, 'documento_tipo' => 'recepcion_romana', 'documento_id' => $recepcion->id,
            'numero_documento' => $recepcion->numero_guia_despacho, 'tipo_movimiento' => $movimiento, 'tipo_envase' => $tipo,
            'cantidad' => $cantidad, 'signo_cuenta' => $signoCuenta, 'signo_existencia' => 1,
            'propiedad' => $propiedad ?? PropiedadEnvase::Cliente,
            // Conserva la fecha del ingreso para que la cuenta corriente lo muestre en su lugar cronológico.
            'ocurrido_at' => $recepcion->ingreso_at, 'ingreso_at' => $recepcion->ingreso_at,
            'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $usuario->id,
            'datos' => ['numero_recepcion' => $recepcion->numero_recepcion, 'cantidad_validada' => $cantidad,
                'guias' => app(ServicioGuiasRecepcionRomana::class)->datos($recepcion->load('guiasRecepcion')),
                'correccion_integral' => $contexto],
        ]);
    }

    /** Devuelve true si cambió el neto o el reparto, para conciliar los kilos de los lotes. */
    private function corregirDestare(RecepcionRomana $recepcion, ?array $destare, array $validados, User $usuario, CarbonImmutable $ahora, array $contexto): bool
    {
        if ($destare === null) {
            // Pesaje acumulativo: el destare viene de las tandas y las cantidades no cambian.
            return false;
        }
        $modo = $destare['modo_salida_envases'];
        if (! in_array($modo, ['mismos', 'diferentes', 'vacio'], true)) {
            throw ValidationException::withMessages(['destare.modo_salida_envases' => 'Indica si el camión salió con los mismos envases, con otros o vacío.']);
        }
        $guiaSalida = $modo === 'vacio' ? null : $destare['numero_guia_salida'];
        if ($modo !== 'vacio' && $guiaSalida === null) {
            throw ValidationException::withMessages(['destare.numero_guia_salida' => 'Indica la guía con que salieron los envases.']);
        }
        $salidas = match ($modo) {
            'mismos' => $validados,
            'diferentes' => collect($destare['salida_envases'])->mapWithKeys(fn ($e): array => [$e['tipo_envase'] => $e['cantidad']])->all(),
            'vacio' => [],
        };
        foreach (array_keys($salidas) as $tipo) {
            $this->tipoValido($tipo);
        }
        $taras = $recepcion->detallesEnvases->mapWithKeys(fn ($d): array => [$d->tipo_envase->value => (float) $d->tara_unitaria_salida]);
        $taraEnvases = 0.0;
        $tarasUnitarias = [];
        foreach (array_unique([...array_keys($validados), ...array_keys($salidas)]) as $tipo) {
            $tara = $destare['taras_envases'][$tipo] ?? $taras->get($tipo)
                ?? $recepcion->salidasEnvases->first(fn ($s): bool => $s->tipo_envase->value === $tipo)?->tara_unitaria;
            if (! $tara || $tara <= 0) {
                throw ValidationException::withMessages(['destare.taras_envases' => "Indica la tara unitaria de {$tipo}."]);
            }
            $tarasUnitarias[$tipo] = (float) $tara;
            $taraEnvases += (($validados[$tipo] ?? 0) - ($salidas[$tipo] ?? 0)) * (float) $tara;
        }
        $neto = round((float) $recepcion->peso_bruto - $destare['peso_tara'] - $taraEnvases, 3);
        if ($destare['peso_tara'] <= 0 || $neto <= 0) {
            throw ValidationException::withMessages(['destare.peso_tara' => 'Con esa tara y esos envases el neto de fruta queda en cero o negativo.']);
        }
        $documentos = app(DocumentoSalidaEnvases::class);
        if ($guiaSalida !== null && $guiaSalida !== $recepcion->numero_guia_salida) {
            $documentos->bloquear($recepcion->temporada_id, $recepcion->cliente_id, $guiaSalida, 'numero_guia_salida');
            $documentos->asegurarSinDespacho($recepcion->temporada_id, $recepcion->cliente_id, $guiaSalida, $recepcion->id);
        }
        $this->corregirSalidas($recepcion, $salidas, $tarasUnitarias, $guiaSalida, $usuario, $ahora, $contexto);
        foreach ($recepcion->detallesEnvases as $detalle) {
            $detalle->update(['tara_unitaria_salida' => $tarasUnitarias[$detalle->tipo_envase->value] ?? $detalle->tara_unitaria_salida]);
        }
        $repartoActual = app(ServicioRepartoEnvases::class)->snapshot($recepcion);
        if ($recepcion->reparto_neto_envases) {
            $seleccion = array_values(array_filter(array_column($recepcion->reparto_neto_envases, 'tipo_envase'), fn ($t): bool => ($validados[$t] ?? 0) > 0));
            $reparto = app(ServicioRepartoEnvases::class)->calcular($neto, $validados, $seleccion,
                array_column($recepcion->reparto_neto_envases, 'peso_referencia', 'tipo_envase'));
            $calculo = ['reparto_neto_envases' => $reparto, 'tipo_envase_calculo_neto' => $reparto[0]['tipo_envase'],
                'cantidad_envase_calculo_neto' => $reparto[0]['cantidad'], 'peso_neto_por_envase' => round($reparto[0]['peso_neto_unitario'], 3)];
        } else {
            $cantidad = $validados[$recepcion->tipo_envase_calculo_neto] ?? 0;
            if ($cantidad < 1) {
                throw new ConflictoOperacion('No puedes dejar en cero el envase con que se calcula el neto por envase.');
            }
            $calculo = ['cantidad_envase_calculo_neto' => $cantidad, 'peso_neto_por_envase' => round($neto / $cantidad, 3)];
        }
        $netoAnterior = (float) $recepcion->peso_neto;
        $recepcion->update([...$calculo, 'peso_tara' => $destare['peso_tara'], 'peso_tara_envases' => round($taraEnvases, 3), 'peso_neto' => $neto,
            'modo_salida_envases' => $modo, 'salida_sin_envases' => $modo === 'vacio', 'numero_guia_salida' => $guiaSalida]);

        return abs($netoAnterior - $neto) > 0.0005 || $repartoActual !== app(ServicioRepartoEnvases::class)->snapshot($recepcion->refresh());
    }

    private function corregirSalidas(RecepcionRomana $recepcion, array $salidas, array $taras, ?string $guia, User $usuario, CarbonImmutable $ahora, array $contexto): void
    {
        $anteriores = $recepcion->salidasEnvases()->lockForUpdate()->get()->keyBy(fn ($s): string => $s->tipo_envase->value);
        foreach (array_unique([...array_keys($salidas), ...$anteriores->keys()->all()]) as $tipo) {
            $anterior = $anteriores->get($tipo);
            $cantidad = (int) ($salidas[$tipo] ?? 0);
            $propiedad = $recepcion->propiedadEnvase($tipo);
            $movimiento = $anterior?->movimiento_envase_id ? MovimientoEnvase::query()->lockForUpdate()->find($anterior->movimiento_envase_id) : null;
            $igual = (int) ($anterior?->cantidad ?? 0) === $cantidad
                && ($movimiento === null || ($movimiento->propiedad === $propiedad && $movimiento->numero_documento === $guia));
            if ($igual) {
                $anterior?->update(['tara_unitaria' => $taras[$tipo] ?? $anterior->tara_unitaria]);

                continue;
            }
            if ($movimiento) {
                if (DetalleGuiaDespachoEnvase::query()->where('movimiento_origen_id', $movimiento->id)->exists()
                    || MovimientoEnvase::query()->where('movimiento_origen_id', $movimiento->id)->exists()) {
                    throw new ConflictoOperacion("La salida de {$movimiento->tipo_envase->etiqueta()} ya está conciliada con un despacho; corrige primero esa conciliación.");
                }
                MovimientoEnvase::create([
                    'operacion_id' => (string) Str::uuid(), 'temporada_id' => $recepcion->temporada_id, 'cliente_id' => $recepcion->cliente_id,
                    'recepcion_romana_id' => $recepcion->id, 'documento_tipo' => 'recepcion_romana', 'documento_id' => $recepcion->id,
                    'numero_documento' => $movimiento->numero_documento, 'tipo_movimiento' => TipoMovimientoEnvase::ReversionSalidaRecepcionFruta,
                    'tipo_envase' => $tipo, 'cantidad' => $movimiento->cantidad, 'signo_cuenta' => -$movimiento->signo_cuenta,
                    'signo_existencia' => -$movimiento->signo_existencia, 'propiedad' => $movimiento->propiedad,
                    'movimiento_origen_id' => $movimiento->id, 'ocurrido_at' => $ahora, 'salida_at' => $ahora,
                    'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $usuario->id,
                    'datos' => ['motivo_correccion' => $contexto['motivo'], 'correccion_integral' => $contexto],
                ]);
            }
            $nuevo = $cantidad > 0 ? MovimientoEnvase::create([
                'operacion_id' => (string) Str::uuid(), 'temporada_id' => $recepcion->temporada_id, 'cliente_id' => $recepcion->cliente_id,
                'recepcion_romana_id' => $recepcion->id, 'documento_tipo' => 'recepcion_romana', 'documento_id' => $recepcion->id,
                'numero_documento' => $guia, 'tipo_movimiento' => TipoMovimientoEnvase::SalidaRecepcionFruta, 'tipo_envase' => $tipo,
                'cantidad' => $cantidad, 'signo_cuenta' => -1, 'signo_existencia' => -1, 'propiedad' => $propiedad,
                // La salida corregida conserva la fecha del destare original.
                'ocurrido_at' => $movimiento?->ocurrido_at ?? $recepcion->salida_at ?? $ahora, 'salida_at' => $movimiento?->salida_at ?? $recepcion->salida_at ?? $ahora,
                'estado_revision' => EstadoRevisionMovimientoEnvase::Pendiente, 'creado_por_user_id' => $usuario->id,
                'datos' => ['numero_recepcion' => $recepcion->numero_recepcion, 'correccion_integral' => $contexto],
            ]) : null;
            if ($anterior) {
                $anterior->update(['cantidad' => $cantidad, 'tara_unitaria' => $taras[$tipo] ?? $anterior->tara_unitaria, 'movimiento_envase_id' => $nuevo?->id]);
            } elseif ($nuevo) {
                $recepcion->salidasEnvases()->create(['tipo_envase' => $tipo, 'cantidad' => $cantidad, 'tara_unitaria' => $taras[$tipo], 'movimiento_envase_id' => $nuevo->id]);
            }
        }
        $recepcion->unsetRelation('salidasEnvases');
    }

    /** Lleva los envases de cada lote al conteo corregido de su segmento. Devuelve true si algún lote cambió. */
    private function sincronizarLotes(RecepcionRomana $recepcion, Collection $segmentos, User $usuario): bool
    {
        $cambio = false;
        foreach ($segmentos as $segmento) {
            $conteo = $segmento->envases->mapWithKeys(fn ($e): array => [$e->tipo_envase->value => (int) $e->cantidad])->filter()->sortKeys()->all();
            foreach ($segmento->lotesMateriaPrima->where('estado', '!=', EstadoLoteMateriaPrima::Anulado) as $lote) {
                $lote = LoteMateriaPrima::query()->with('envasesDetalle')->lockForUpdate()->findOrFail($lote->id);
                $actual = $lote->envasesDetalle->mapWithKeys(fn ($e): array => [$e->tipo_envase->value => (int) $e->cantidad])->filter()->sortKeys()->all();
                if ($actual === $conteo) {
                    continue;
                }
                if (in_array($lote->estado, [EstadoLoteMateriaPrima::EntregaParcialProceso, EstadoLoteMateriaPrima::EntregadoProceso], true)
                    || $lote->entregasProceso()->whereNull('anulado_at')->exists()) {
                    throw new ConflictoOperacion("El lote {$lote->numero_lote} ya tiene entregas a proceso: su conteo no se puede cambiar sin anular esas entregas.");
                }
                $taras = $recepcion->detallesEnvases->keyBy(fn ($d): string => $d->tipo_envase->value);
                $lote->envasesDetalle()->whereNotIn('tipo_envase', array_keys($conteo))->delete();
                foreach ($conteo as $tipo => $cantidad) {
                    $lote->envasesDetalle()->updateOrCreate(['tipo_envase' => $tipo], ['cantidad' => $cantidad, 'tara_unitaria' => $taras->get($tipo)?->tara_unitaria_salida]);
                }
                // Mismo criterio que al crear el lote: el envase físico con fruta manda.
                $conFruta = collect($conteo)->filter(fn ($n, $t): bool => TipoEnvaseRomana::from($t)->contieneFruta());
                $primario = $conFruta->has($recepcion->tipo_envase_declarado?->value ?? '') ? $recepcion->tipo_envase_declarado->value : $conFruta->keys()->first();
                $cantidadPrimarios = (int) ($conteo[$primario] ?? 0);
                if ((int) $segmento->bins_prestados_planta > 0) {
                    $primario = TipoEnvaseRomana::Bins->value;
                    $cantidadPrimarios = (int) ($conteo[$primario] ?? 0) + (int) $segmento->bins_prestados_planta;
                }
                $secundario = collect($conteo)->except($primario)->keys()->first();
                $lote->update(['envase_primario' => $primario, 'cantidad_envases_primarios' => $cantidadPrimarios,
                    'envase_secundario' => $secundario, 'cantidad_envases_secundarios' => $secundario ? $conteo[$secundario] : 0,
                    'version' => $lote->version + 1, 'actualizado_por_user_id' => $usuario->id]);
                $cambio = true;
            }
        }

        return $cambio;
    }

    private function tipoValido(string $tipo): void
    {
        if (! TipoEnvaseRomana::tryFrom($tipo)) {
            throw ValidationException::withMessages(['envases' => "El tipo de envase {$tipo} no existe."]);
        }
    }

    /** Lo que importa comparar y auditar (sin versiones ni estados de lote). */
    private function comparable(array $estado): array
    {
        return ['guias' => $estado['guias'], 'envases' => $estado['envases'], 'destare' => $estado['destare'],
            'segmentos' => array_map(fn ($s): array => ['id' => $s['id'], 'envases' => $s['envases']], $estado['segmentos'])];
    }
}
