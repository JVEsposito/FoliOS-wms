<?php

namespace App\Services\Romana;

use App\Enums\EstadoRecepcionRomana;
use App\Enums\EstadoValidacionMp;
use App\Enums\TipoEnvaseRomana;
use App\Exceptions\ConflictoOperacion;
use App\Models\EventoInspeccionEnvase;
use App\Models\InspeccionEnvases;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Services\Documentos\ServicioFormatosRegistro;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServicioInspeccionEnvases
{
    public const CONTROLES = ['coincide_especie_variedad', 'coincide_cantidad_bins', 'bins_bien_etiquetados'];

    public static function reglas(bool $recepcion, bool $obligatoria = true): array
    {
        $p = 'inspeccion_envases';
        $reglas = [$p => [$obligatoria ? 'required' : 'nullable', 'array'], "$p.items" => ['required_with:'.$p, 'array', 'max:7'], "$p.items.*.tipo_envase" => ['required', 'distinct', Rule::enum(TipoEnvaseRomana::class)], "$p.items.*.limpieza" => ['required', 'boolean'], "$p.items.*.condicion" => ['required', Rule::in(['buena', 'regular', 'mala'])], "$p.items.*.nota" => ['nullable', 'string', 'max:500'], "$p.observacion" => ['nullable', 'string', 'max:2000']];
        if ($recepcion) {
            foreach (self::CONTROLES as $campo) {
                $reglas["$p.$campo"] = ['required_with:'.$p, 'boolean'];
            }
        }

        return $reglas;
    }

    public function cantidades(RecepcionRomana $recepcion, string $tipo): array
    {
        return $tipo === 'recepcion'
            ? $recepcion->detallesEnvases()->get()->mapWithKeys(fn ($e) => [$e->tipo_envase->value => (int) $e->cantidad_validada])->all()
            : $recepcion->salidasEnvases()->get()->mapWithKeys(fn ($e) => [$e->tipo_envase->value => (int) $e->cantidad])->all();
    }

    // Llamado bajo el bloqueo de la recepción y en la transacción de MP/destare.
    public function guardar(RecepcionRomana $recepcion, string $tipo, array $cantidades, ?array $datos, User $usuario, string $operacion, ?string $motivo = null): InspeccionEnvases
    {
        app(GuardiaTemporadaActiva::class)->asegurar($recepcion);
        Validator::make(['inspeccion_envases' => $datos], self::reglas($tipo === 'recepcion'))->validate();
        $cantidades = array_filter($cantidades, fn ($n) => $n > 0);
        ksort($cantidades);
        $items = collect($datos['items'])->sortBy('tipo_envase')->values();
        if (array_keys($cantidades) !== $items->pluck('tipo_envase')->all()) {
            throw ValidationException::withMessages(['inspeccion_envases.items' => 'Inspecciona cada tipo con cantidad positiva; las cantidades corresponden al registro de recepción o salida.']);
        }
        $hash = hash('sha256', json_encode([$recepcion->id, $tipo, $cantidades, $datos, $motivo], JSON_THROW_ON_ERROR));
        $evento = EventoInspeccionEnvase::where('operacion_id', $operacion)->first();
        if ($evento) {
            $existente = InspeccionEnvases::findOrFail($evento->inspeccion_envases_id);
            if ($evento->payload_hash !== $hash || $existente->recepcion_romana_id !== $recepcion->id || $existente->tipo !== $tipo) {
                throw new ConflictoOperacion('La operación ya fue usada para otra inspección.');
            }

            return $existente->load('items', 'usuario');
        }
        $inspeccion = InspeccionEnvases::where('recepcion_romana_id', $recepcion->id)->where('tipo', $tipo)->lockForUpdate()->first();
        $antes = $inspeccion ? $this->serializar($inspeccion) : null;
        $atributos = ['recepcion_romana_id' => $recepcion->id, 'temporada_id' => $recepcion->temporada_id, 'tipo' => $tipo, 'observacion' => $datos['observacion'] ?? null, 'user_id' => $usuario->id, 'inspeccionada_at' => now(), 'version' => ($inspeccion?->version ?? 0) + 1];
        foreach (self::CONTROLES as $campo) {
            $atributos[$campo] = $tipo === 'recepcion' ? (bool) $datos[$campo] : null;
        }
        if ($inspeccion) {
            $inspeccion->update($atributos);
        } else {
            $inspeccion = InspeccionEnvases::create([...$atributos, 'formato' => app(ServicioFormatosRegistro::class)->vigente('RC-02', bloquear: true)]);
        }
        $inspeccion->items()->delete();
        foreach ($items as $fila) {
            $inspeccion->items()->create(['tipo_envase' => $fila['tipo_envase'], 'cantidad' => $cantidades[$fila['tipo_envase']], 'limpieza' => (bool) $fila['limpieza'], 'condicion' => $fila['condicion'], 'nota' => $fila['nota'] ?? null]);
        }
        $inspeccion->unsetRelation('items')->unsetRelation('usuario');
        $inspeccion->eventos()->create(['operacion_id' => $operacion, 'user_id' => $usuario->id, 'motivo' => $motivo, 'antes' => $antes, 'despues' => $this->serializar($inspeccion), 'payload_hash' => $hash]);

        return $inspeccion;
    }

    public function corregir(RecepcionRomana $recepcion, string $tipo, array $datos, User $usuario): InspeccionEnvases
    {
        return DB::transaction(function () use ($recepcion, $tipo, $datos, $usuario) {
            $recepcion = RecepcionRomana::lockForUpdate()->findOrFail($recepcion->id);
            app(GuardiaTemporadaActiva::class)->asegurar($recepcion);
            if (! $this->disponible($recepcion, $tipo, exigirInspeccion: false)) {
                throw new ConflictoOperacion('La recepción o la salida de envases todavía no está confirmada.');
            }
            $inspeccion = InspeccionEnvases::where('recepcion_romana_id', $recepcion->id)->where('tipo', $tipo)->first();
            if (! EventoInspeccionEnvase::where('operacion_id', $datos['operacion_id'])->exists() && (int) ($inspeccion?->version ?? 0) !== $datos['version_conocida']) {
                throw new ConflictoOperacion('La inspección cambió. Actualiza antes de corregirla.');
            }

            return $this->guardar($recepcion, $tipo, $this->cantidades($recepcion, $tipo), $datos['inspeccion_envases'], $usuario, $datos['operacion_id'], $datos['motivo']);
        }, attempts: 3);
    }

    public function disponible(RecepcionRomana $recepcion, string $tipo, bool $exigirInspeccion = true): bool
    {
        $completa = $tipo === 'recepcion' ? $recepcion->estado_validacion_mp === EstadoValidacionMp::Validada
            : ($recepcion->estado === EstadoRecepcionRomana::Cerrado && $recepcion->modo_salida_envases !== null && $recepcion->modo_salida_envases !== 'vacio' && $recepcion->salidasEnvases()->where('cantidad', '>', 0)->exists());

        return $completa && (! $exigirInspeccion || InspeccionEnvases::where('recepcion_romana_id', $recepcion->id)->where('tipo', $tipo)->exists());
    }

    public function serializar(InspeccionEnvases $inspeccion): array
    {
        $inspeccion->loadMissing('items', 'usuario');

        return ['id' => $inspeccion->id, 'tipo' => $inspeccion->tipo, 'version' => $inspeccion->version, 'formato' => $inspeccion->formato, 'inspeccionada_at' => $inspeccion->inspeccionada_at->toAtomString(), 'usuario' => ['id' => $inspeccion->user_id, 'nombre' => $inspeccion->usuario->name], ...collect(self::CONTROLES)->mapWithKeys(fn ($c) => [$c => $inspeccion->$c])->all(), 'observacion' => $inspeccion->observacion, 'items' => $inspeccion->items->sortBy(fn ($i) => TipoEnvaseRomana::from($i->tipo_envase)->orden())->map(fn ($i) => ['tipo_envase' => $i->tipo_envase, 'cantidad' => $i->cantidad, 'limpieza' => $i->limpieza, 'condicion' => $i->condicion, 'nota' => $i->nota])->values()->all()];
    }
}
