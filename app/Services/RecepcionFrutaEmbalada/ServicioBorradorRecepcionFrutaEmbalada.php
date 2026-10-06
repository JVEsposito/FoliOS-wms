<?php

namespace App\Services\RecepcionFrutaEmbalada;

use App\Enums\EstadoRecepcionFrutaEmbalada;
use App\Exceptions\ConflictoOperacion;
use App\Models\ArticuloValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\EventoRecepcionFrutaEmbalada;
use App\Models\OrigenValidacion;
use App\Models\PlantaOrigen;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\RecepcionFrutaEmbaladaPallet;
use App\Models\User;
use App\Services\Temporadas\GuardiaTemporadaActiva;
use App\Services\Temporadas\ServicioTemporadaActiva;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServicioBorradorRecepcionFrutaEmbalada
{
    public function __construct(private readonly ServicioTemporadaActiva $temporadas, private readonly GuardiaTemporadaActiva $guardia) {}

    /** @return array{RecepcionFrutaEmbalada, bool} */
    public function guardar(array $datos, User $usuario, ?string $dispositivoId, ?RecepcionFrutaEmbalada $recepcion = null): array
    {
        return DB::transaction(function () use ($datos, $usuario, $dispositivoId, $recepcion): array {
            // Serializa reintentos y cambios de temporada antes de cualquier escritura.
            $temporada = $this->temporadas->obtener(bloquear: true);
            if ($temporada->id !== $datos['temporada_id']) {
                throw ValidationException::withMessages(['temporada_id' => 'Solo se puede escribir en la temporada activa.']);
            }
            if ($recepcion) {
                $recepcion = RecepcionFrutaEmbalada::query()->lockForUpdate()->findOrFail($recepcion->id);
                $this->guardia->asegurar($recepcion);
                if ($recepcion->estado !== EstadoRecepcionFrutaEmbalada::Borrador) {
                    throw new ConflictoOperacion('La recepción ya no está en borrador. Actualiza el detalle.');
                }
            }
            $hash = hash('sha256', json_encode($datos, JSON_THROW_ON_ERROR));
            $existente = EventoRecepcionFrutaEmbalada::query()->where('operacion_id', $datos['operacion_id'])->first();
            if ($existente) {
                if ($existente->user_id !== $usuario->id || $existente->dispositivo_id !== $dispositivoId
                    || ! hash_equals($existente->payload_hash, $hash)
                    || ($recepcion && $existente->recepcion_fruta_embalada_id !== $recepcion->id)
                    || (! $recepcion && $existente->antes !== null)) {
                    throw new ConflictoOperacion('La operación ya fue utilizada con otros datos.');
                }

                return [RecepcionFrutaEmbalada::findOrFail($existente->recepcion_fruta_embalada_id), false];
            }
            if ($recepcion && $recepcion->version !== $datos['version_conocida']) {
                throw new ConflictoOperacion('Otro operador modificó el borrador. Actualiza antes de guardar.');
            }
            $clienteCatalogo = ClienteValidacion::query()->where('cliente_id', $datos['cliente_id'])
                ->where('temporada_id', $temporada->id)->where('activo', true)->first();
            if (! $clienteCatalogo || ! Cliente::whereKey($datos['cliente_id'])->where('activo', true)->exists()) {
                throw ValidationException::withMessages(['cliente_id' => 'El cliente no pertenece al catálogo activo de la temporada.']);
            }
            if (! PlantaOrigen::whereKey($datos['planta_origen_id'])->where('activa', true)->exists()) {
                throw ValidationException::withMessages(['planta_origen_id' => 'Selecciona una planta de origen activa.']);
            }
            $validador = User::whereKey($datos['validador_id'])->where('activo', true)->first();
            if (! $validador || ! $validador->can('gestionar-recepciones-fruta-embalada')) {
                throw ValidationException::withMessages(['validador_id' => 'Selecciona un validador activo con acceso a recepción de fruta embalada.']);
            }
            // También se verifica al guardar para cubrir una recepción creada tras el aviso previo.
            if ($this->guiasDuplicadas($datos['cliente_id'], $datos['planta_origen_id'], $datos['numero_guia'], $recepcion?->id)->isNotEmpty()
                && ! ($datos['confirmar_guia_duplicada'] ?? false)) {
                throw ValidationException::withMessages(['numero_guia' => 'Esta guía ya se recibió del mismo cliente y planta. Revisa el aviso y confirma antes de guardar.']);
            }
            $pallets = $this->validarPallets($datos, $clienteCatalogo->id, $temporada->id, $recepcion);
            $antes = $recepcion ? $recepcion->load('pallets')->toArray() : null;
            $nueva = $recepcion === null;
            $recepcion ??= new RecepcionFrutaEmbalada;
            $cabecera = collect($datos)->except(['pallets', 'operacion_id', 'version_conocida', 'confirmar_guia_duplicada'])->all();
            foreach (['recepcion_at', 'salida_at'] as $campo) {
                $cabecera[$campo] = filled($cabecera[$campo] ?? null) ? CarbonImmutable::parse($cabecera[$campo])->utc() : null;
            }
            $recepcion->fill([
                ...$cabecera, 'estado' => EstadoRecepcionFrutaEmbalada::Borrador,
                'version' => $nueva ? 1 : $recepcion->version + 1,
                'operacion_id' => $nueva ? $datos['operacion_id'] : $recepcion->operacion_id,
                'creado_por_user_id' => $nueva ? $usuario->id : $recepcion->creado_por_user_id,
                'actualizado_por_user_id' => $usuario->id,
            ])->save();
            $conservados = [];
            foreach ($pallets as $pallet) {
                $modelo = isset($pallet['id']) ? $recepcion->pallets()->find($pallet['id']) : null;
                $modelo ??= new RecepcionFrutaEmbaladaPallet;
                $modelo->fill($pallet);
                $recepcion->pallets()->save($modelo);
                $conservados[] = $modelo->id;
            }
            // Solo detalle de borrador: no hay folios, movimientos ni reservas involucrados.
            $recepcion->pallets()->whereNotIn('id', $conservados)->get()->each->delete();
            $despues = $recepcion->refresh()->load('pallets')->toArray();
            $recepcion->eventos()->create([
                'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash,
                'user_id' => $usuario->id, 'dispositivo_id' => $dispositivoId,
                'antes' => $antes, 'despues' => $despues,
            ]);

            return [$recepcion, $nueva];
        }, attempts: 3);
    }

    public function guiasDuplicadas(string $cliente, string $planta, string $guia, ?string $excluir = null)
    {
        return RecepcionFrutaEmbalada::query()->where('cliente_id', $cliente)->where('planta_origen_id', $planta)
            ->where('numero_guia', mb_strtoupper(trim($guia)))->where('estado', '!=', EstadoRecepcionFrutaEmbalada::Anulada->value)
            ->when($excluir, fn ($query) => $query->whereKeyNot($excluir))->limit(5)->get(['id', 'estado', 'recepcion_at', 'temporada_id']);
    }

    private function validarPallets(array $datos, string $clienteCatalogoId, string $temporadaId, ?RecepcionFrutaEmbalada $recepcion): array
    {
        $filas = collect($datos['pallets']);
        $origenes = OrigenValidacion::query()->whereIn('id', $filas->pluck('origen_validacion_id'))
            ->where('temporada_id', $temporadaId)->where('cliente_validacion_id', $clienteCatalogoId)->where('activo', true)->get()->keyBy('id');
        $articulos = ArticuloValidacion::query()->whereIn('id', $filas->pluck('articulo_validacion_id'))
            ->where('temporada_id', $temporadaId)->where('activo', true)->get()->keyBy('id');
        $combinaciones = DB::table('combinaciones_validacion')->where('temporada_id', $temporadaId)->where('activo', true)
            ->whereIn('origen_validacion_id', $origenes->keys())->whereIn('articulo_validacion_id', $articulos->keys())
            ->get()->keyBy(fn ($c) => $c->origen_validacion_id.'|'.$c->articulo_validacion_id);
        $variedades = DB::table('csg_variedades_validacion')->whereIn('csg_validacion_id', $origenes->pluck('csg_validacion_id')->filter())
            ->get()->keyBy(fn ($v) => $v->csg_validacion_id.'|'.$v->variedad_validacion_id);
        $idsExistentes = RecepcionFrutaEmbaladaPallet::query()->whereIn('id', $filas->pluck('id')->filter())->get()->keyBy('id');

        return $filas->map(function (array $fila, int $orden) use ($origenes, $articulos, $combinaciones, $variedades, $idsExistentes, $recepcion, $datos, $clienteCatalogoId): array {
            $origen = $origenes->get($fila['origen_validacion_id']);
            $articulo = $articulos->get($fila['articulo_validacion_id']);
            if (! $origen) {
                throw ValidationException::withMessages(["pallets.{$orden}.origen_validacion_id" => 'El CSG no pertenece al cliente y catálogo activos de esta recepción.']);
            }
            if (! $articulo || ($articulo->cliente_validacion_id !== null && $articulo->cliente_validacion_id !== $clienteCatalogoId)
                || ! $combinaciones->has($origen->id.'|'.$articulo->id)) {
                throw ValidationException::withMessages(["pallets.{$orden}.articulo_validacion_id" => 'La combinación de especie, variedad, embalaje y calibre no está habilitada para este CSG.']);
            }
            if ($origen->csg_validacion_id !== null && $articulo->variedad_validacion_id !== null
                && ! $variedades->has($origen->csg_validacion_id.'|'.$articulo->variedad_validacion_id)) {
                throw ValidationException::withMessages(["pallets.{$orden}.articulo_validacion_id" => 'La variedad seleccionada no está asociada al CSG elegido.']);
            }
            $id = $fila['id'] ?? null;
            if ($id && $idsExistentes->has($id) && $idsExistentes->get($id)->recepcion_fruta_embalada_id !== $recepcion?->id) {
                throw new ConflictoOperacion('Un pallet pertenece a otra recepción. Actualiza el borrador.');
            }

            return [
                ...$fila, 'orden' => $orden + 1,
                'embalaje' => $articulo->envase, 'especie' => $articulo->especie,
                'variedad' => $articulo->variedad, 'calibre' => $articulo->calibre, 'csg' => $origen->csg,
                'condicion_sag_id' => $fila['condicion_sag_personalizada'] ? ($fila['condicion_sag_id'] ?? null) : ($datos['condicion_sag_id'] ?? null),
            ];
        })->all();
    }
}
