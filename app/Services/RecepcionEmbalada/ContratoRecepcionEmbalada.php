<?php

namespace App\Services\RecepcionEmbalada;

use App\Models\CalibreValidacion;
use App\Models\ClienteValidacion;
use App\Models\CondicionSag;
use App\Models\CsgValidacion;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\VariedadValidacion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Adaptador de la captura del PR 1. El contrato completo está documentado en docs. */
class ContratoRecepcionEmbalada
{
    public function cargar(string $id, bool $bloquear = false): array
    {
        if (! Schema::hasTable('recepciones_fruta_embalada') || ! Schema::hasTable('recepciones_fruta_embalada_pallets') || ! Schema::hasTable('plantas_origen')) {
            throw new DomainException('La base de Recepción de fruta embalada todavía no está instalada.');
        }
        $cabecera = DB::table('recepciones_fruta_embalada')->where('id', $id)
            ->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
        if (! $cabecera) {
            throw new DomainException('No existe la recepción de fruta embalada.');
        }
        $pallets = DB::table('recepciones_fruta_embalada_pallets')->where('recepcion_id', $id)
            ->orderBy('orden')->orderBy('id')->when($bloquear, fn ($q) => $q->lockForUpdate())->get()->map(fn ($p) => (array) $p)->all();
        $cabecera = (array) $cabecera;
        Validator::make($cabecera, [
            'temporada_id' => ['required', 'uuid'], 'cliente_validacion_id' => ['required', 'uuid'],
            'planta_origen_id' => ['required', 'uuid'], 'numero_guia' => ['required', 'string', 'max:100'],
            'servicio' => ['required', 'in:almacenaje,prefrio'], 'llega_con_prefrio' => ['required', 'boolean'],
            'estado' => ['required', 'in:borrador,aceptada,anulada'], 'recibido_at' => ['required', 'date'],
        ])->validate();
        if ($pallets === [] || count($pallets) > 500) {
            throw new DomainException('La recepción debe tener entre 1 y 500 pallets o saldos.');
        }
        $cliente = ClienteValidacion::whereKey($cabecera['cliente_validacion_id'])->where('temporada_id', $cabecera['temporada_id'])->where('activo', true)->first();
        $planta = DB::table('plantas_origen')->where('id', $cabecera['planta_origen_id'])->where('activa', true)->first();
        if (! $cliente || ! $planta) {
            throw new DomainException('Revisa el cliente de la temporada y la planta de origen activa.');
        }
        if (count(array_unique(array_column($pallets, 'orden'))) !== count($pallets)) {
            throw new DomainException('El orden de los pallets de la recepción no puede repetirse.');
        }
        $detalles = array_map(fn ($p) => $this->pallet($p, $cliente), $pallets);
        $snapshot = ['cabecera' => $cabecera, 'cliente' => $cliente->nombre, 'planta_origen' => ['id' => $planta->id, 'nombre' => $planta->nombre, 'codigo' => $planta->codigo], 'pallets' => $detalles];

        return [...$snapshot, 'version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }

    private function pallet(array $p, ClienteValidacion $cliente): array
    {
        Validator::make($p, [
            'orden' => ['required', 'integer', 'min:1'], 'id' => ['required', 'uuid'], 'folio_origen' => ['required', 'string', 'max:50', 'regex:/^[\x20-\x7E]+$/D'],
            'tipo_bulto' => ['required', 'in:pallet,saldo'], 'cantidad_cajas' => ['required', 'integer', 'min:1', 'max:100000'],
            'especie_validacion_id' => ['required', 'uuid'], 'variedad_validacion_id' => ['required', 'uuid'],
            'envase_validacion_id' => ['required', 'uuid'], 'calibre_validacion_id' => ['required', 'uuid'],
            'csg_validacion_id' => ['required', 'uuid'], 'csp' => ['nullable', 'string', 'max:100'],
            'fecha_proceso_origen' => ['required', 'date_format:Y-m-d'], 'temperatura_pulpa' => ['required', 'numeric', 'between:-50,100'],
            'condicion_sag_id' => ['nullable', 'uuid'],
        ])->validate();
        $especie = EspecieValidacion::whereKey($p['especie_validacion_id'])->where('temporada_id', $cliente->temporada_id)->where('activo', true)->first();
        $variedad = VariedadValidacion::whereKey($p['variedad_validacion_id'])->where('especie_validacion_id', $especie?->id)->where('activo', true)->first();
        $envase = EnvaseValidacion::whereKey($p['envase_validacion_id'])->where('especie_validacion_id', $especie?->id)->where('cliente_validacion_id', $cliente->id)->where('activo', true)->first();
        $calibre = CalibreValidacion::whereKey($p['calibre_validacion_id'])->where('especie_validacion_id', $especie?->id)->where('activo', true)->first();
        $csg = CsgValidacion::whereKey($p['csg_validacion_id'])->where('temporada_id', $cliente->temporada_id)->where('activo', true)
            ->disponibleParaCliente($cliente->cliente_id ?? $cliente->id)->first();
        if (! $especie || ! $variedad || ! $envase || ! $calibre || ! $csg || ! $csg->variedades()->whereKey($variedad->id)->exists()) {
            throw new DomainException('El CSG, especie, variedad, embalaje o calibre ya no pertenece al catálogo autorizado de esta recepción.');
        }
        if (($p['condicion_sag_id'] ?? null) && ! CondicionSag::whereKey($p['condicion_sag_id'])->where('activo', true)->exists()) {
            throw new DomainException('La condición SAG del pallet no está activa.');
        }

        return [...$p, 'folio_origen' => trim($p['folio_origen']), 'especie' => $especie->nombre,
            'variedad' => $variedad->nombre, 'envase' => $envase->nombre, 'calibre' => $calibre->nombre,
            'csg' => $csg->codigo, 'predio' => $csg->predio, 'umbral_prefrio' => $especie->getAttribute('umbral_prefrio'),
        ];
    }
}
