<?php

namespace App\Services\RecepcionEmbalada;

use App\Models\ArticuloValidacion;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\CondicionSag;
use App\Models\OrigenValidacion;
use App\Models\RecepcionFrutaEmbalada;
use App\Models\UmbralPrefrioEspecie;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Revisión del borrador y del catálogo real de la captura (#394). */
class ContratoRecepcionEmbalada
{
    public function cargar(string $id, bool $bloquear = false): array
    {
        $recepcion = RecepcionFrutaEmbalada::query()->when($bloquear, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        $cabecera = $recepcion->getAttributes();
        $pallets = $recepcion->pallets()->when($bloquear, fn ($q) => $q->lockForUpdate())->get();
        if ($pallets->isEmpty() || $pallets->count() > 500) {
            throw new DomainException('La recepción debe tener entre 1 y 500 pallets o saldos.');
        }
        $cliente = Cliente::whereKey($cabecera['cliente_id'])->where('activo', true)->first();
        $catalogos = ClienteValidacion::where('cliente_id', $cabecera['cliente_id'])->where('temporada_id', $cabecera['temporada_id'])->where('activo', true)->pluck('id');
        $planta = $recepcion->plantaOrigen()->where('activa', true)->first();
        if (! $cliente || $catalogos->isEmpty() || ! $planta) {
            throw new DomainException('Revisa el cliente de la temporada y la planta de origen activa.');
        }
        $origenes = OrigenValidacion::whereIn('id', $pallets->pluck('origen_validacion_id'))->whereIn('cliente_validacion_id', $catalogos)
            ->where('temporada_id', $cabecera['temporada_id'])->where('activo', true)->get()->keyBy('id');
        $articulos = ArticuloValidacion::whereIn('id', $pallets->pluck('articulo_validacion_id'))->where('temporada_id', $cabecera['temporada_id'])->where('activo', true)->get()->keyBy('id');
        $combinaciones = DB::table('combinaciones_validacion')->where('temporada_id', $cabecera['temporada_id'])->where('activo', true)
            ->whereIn('origen_validacion_id', $origenes->keys())->whereIn('articulo_validacion_id', $articulos->keys())->get()->keyBy(fn ($c) => $c->origen_validacion_id.'|'.$c->articulo_validacion_id);
        $variedades = DB::table('csg_variedades_validacion')->whereIn('csg_validacion_id', $origenes->pluck('csg_validacion_id')->filter())->get()->keyBy(fn ($v) => $v->csg_validacion_id.'|'.$v->variedad_validacion_id);
        $umbrales = UmbralPrefrioEspecie::all()->keyBy(fn ($u) => mb_strtoupper(trim($u->especie)));
        $sagActivas = CondicionSag::whereIn('id', $pallets->pluck('condicion_sag_id')->filter())->where('activo', true)->pluck('id');
        $detalles = $pallets->map(function ($modelo) use ($origenes, $articulos, $combinaciones, $variedades, $umbrales, $sagActivas): array {
            $p = $modelo->getAttributes();
            Validator::make($p, [
                'folio_origen' => ['required', 'string', 'max:50', 'regex:/^[\x20-\x7E]+$/D'],
                'tipo_bulto' => ['required', 'in:pallet,saldo'], 'cantidad_cajas' => ['required', 'integer', 'between:1,100000'],
                'fecha_proceso_origen' => ['required', 'date_format:Y-m-d'], 'temperatura_pulpa_c' => ['required', 'numeric', 'between:-50,80'],
            ])->validate();
            $origen = $origenes->get($p['origen_validacion_id']);
            $articulo = $articulos->get($p['articulo_validacion_id']);
            if (! $origen || ! $articulo || ($articulo->cliente_validacion_id !== null && $articulo->cliente_validacion_id !== $origen->cliente_validacion_id)
                || ! $combinaciones->has($origen->id.'|'.$articulo->id)
                || ($origen->csg_validacion_id && $articulo->variedad_validacion_id && ! $variedades->has($origen->csg_validacion_id.'|'.$articulo->variedad_validacion_id))) {
                throw new DomainException('El CSG, especie, variedad, embalaje o calibre ya no pertenece al catálogo autorizado de esta recepción.');
            }
            if ($p['condicion_sag_id'] && ! $sagActivas->contains($p['condicion_sag_id'])) {
                throw new DomainException('La condición SAG del pallet no está activa.');
            }
            // La captura comercial debe seguir correspondiendo a sus referencias autorizadas.
            foreach (['especie', 'variedad', 'calibre'] as $campo) {
                if ($p[$campo] !== $articulo->$campo) {
                    throw new DomainException('El catálogo cambió: vuelve a guardar la captura comercial antes de aceptar.');
                }
            }
            if ($p['embalaje'] !== $articulo->envase || $p['csg'] !== $origen->csg) {
                throw new DomainException('El catálogo cambió: vuelve a guardar el embalaje y CSG antes de aceptar.');
            }

            return [...$p, 'envase' => $p['embalaje'], 'predio' => $origen->predio,
                'envase_validacion_id' => $articulo->envase_validacion_id, 'temperatura_pulpa' => $p['temperatura_pulpa_c'],
                'umbral_prefrio' => $umbrales->get(mb_strtoupper(trim($p['especie'])))?->temperatura_maxima_c];
        })->all();
        $snapshot = ['cabecera' => $cabecera, 'cliente' => $cliente->nombre,
            'planta_origen' => ['id' => $planta->id, 'nombre' => $planta->nombre, 'codigo' => $planta->codigo], 'pallets' => $detalles];

        return [...$snapshot, 'version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }
}
