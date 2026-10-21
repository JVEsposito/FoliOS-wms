<?php

namespace App\Comex\Comercial;

use App\Comex\Expediente\EmbarquesOperacion;
use App\Comex\Models\LugarComercial;
use App\Comex\Models\ParteComercial;
use App\Models\User;
use App\Rules\RutChileno;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CatalogoComercial
{
    public const ROLES = ['operador', 'vendedor', 'exportador', 'propietario', 'comprador', 'recibidor', 'consignatario', 'notify', 'pagador', 'naviera', 'forwarder', 'agente_aduana', 'transportista'];

    public const LUGARES = ['puerto', 'terminal', 'deposito', 'entrega'];

    public function __construct(private RegistroComercial $registro, private EmbarquesOperacion $operacion) {}

    public function parte(array $d, User $actor, ?ParteComercial $parte = null): array
    {
        return $this->registro->operar($d['operacion_id'], 'parte.guardar', ['id' => $parte?->id, ...$d], function () use ($d, $actor, $parte) {
            $actual = $parte ? ParteComercial::query()->lockForUpdate()->findOrFail($parte->id) : null;
            $this->version($actual, $d);
            $this->pais($d['pais_iso2']);
            $identidad = $this->identidad($d['pais_iso2'], $d['tax_id']);
            if (ParteComercial::query()->where(fn ($q) => $q->where('codigo', $d['codigo'])->orWhere(fn ($q) => $q->where('pais_iso2', $d['pais_iso2'])->where('identidad_fiscal', $identidad)))->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->exists()) {
                $this->registro->error('tax_id', 'Ya existe una parte con ese código o identidad fiscal en ese país.');
            }
            if ($actual && ($actual->codigo !== $d['codigo'] || $actual->pais_iso2 !== $d['pais_iso2'] || $actual->identidad_fiscal !== $identidad)) {
                $this->registro->error('tax_id', 'El código, país e identidad fiscal son permanentes; cree la parte correcta y desactive la anterior.');
            }
            $cliente = $d['cliente_operacion_id'] ?? null;
            if ($actual?->cliente_operacion_id && $actual->cliente_operacion_id !== $cliente) {
                $this->registro->error('cliente_operacion_id', 'El vínculo explícito con el cliente operativo es permanente.');
            }
            $snapshot = $cliente ? $this->operacion->cliente($cliente) : null;
            if ($cliente && (! $snapshot || ParteComercial::query()->where('cliente_operacion_id', $cliente)->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->exists())) {
                $this->registro->error('cliente_operacion_id', 'El cliente no existe o ya está vinculado a otra identidad comercial.');
            }
            $antes = $actual?->toArray();
            $campos = [...collect($d)->except(['operacion_id', 'motivo'])->all(), 'identidad_fiscal' => $identidad, 'tax_id' => $d['pais_iso2'] === 'CL' ? $identidad : trim($d['tax_id']), 'cliente_operacion' => $snapshot, 'version' => ($actual?->version ?? 0) + 1];
            if ($actual) {
                $actual->update($campos);
            } else {
                $actual = ParteComercial::query()->create(['id' => (string) Str::uuid(), ...$campos]);
            }
            $actual->refresh();
            $this->registro->evento('parte', $actual->id, $antes ? 'actualizada' : 'creada', ['antes' => $antes, 'despues' => $actual->toArray(), 'motivo' => $d['motivo'] ?? null], $actor);

            return $actual->toArray();
        });
    }

    public function lugar(array $d, User $actor, ?LugarComercial $lugar = null): array
    {
        return $this->registro->operar($d['operacion_id'], 'lugar.guardar', ['id' => $lugar?->id, ...$d], function () use ($d, $actor, $lugar) {
            $actual = $lugar ? LugarComercial::query()->lockForUpdate()->findOrFail($lugar->id) : null;
            $this->version($actual, $d);
            $this->pais($d['pais_iso2']);
            if (LugarComercial::query()->where('codigo', $d['codigo'])->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->exists()) {
                $this->registro->error('codigo', 'Ya existe un lugar con ese código.');
            }
            if ($actual && ($actual->codigo !== $d['codigo'] || $actual->tipo !== $d['tipo'] || $actual->pais_iso2 !== $d['pais_iso2'])) {
                $this->registro->error('codigo', 'El código, tipo y país identifican el lugar y son permanentes.');
            }
            $puerto = empty($d['puerto_id']) ? null : LugarComercial::query()->find($d['puerto_id']);
            if ($puerto && ($d['tipo'] !== 'terminal' || $puerto->tipo !== 'puerto' || ! $puerto->activo || $puerto->pais_iso2 !== $d['pais_iso2'])) {
                $this->registro->error('puerto_id', 'Un terminal solo se vincula a un puerto activo del mismo país.');
            }
            $antes = $actual?->toArray();
            $campos = [...collect($d)->except(['operacion_id', 'motivo'])->all(), 'puerto_id' => $puerto?->id, 'version' => ($actual?->version ?? 0) + 1];
            if ($actual) {
                $actual->update($campos);
            } else {
                $actual = LugarComercial::query()->create(['id' => (string) Str::uuid(), ...$campos]);
            }
            $actual->refresh();
            $this->registro->evento('lugar', $actual->id, $antes ? 'actualizado' : 'creado', ['antes' => $antes, 'despues' => $actual->toArray(), 'motivo' => $d['motivo'] ?? null], $actor);

            return $actual->toArray();
        });
    }

    public function identidad(string $pais, string $tax): string
    {
        $id = mb_strtoupper(preg_replace('/\s/u', '', trim($tax)));
        if ($pais !== 'CL') {
            return $id;
        }
        $id = str_replace(['.', '-'], '', $id);
        if (! preg_match('/^[0-9]{1,8}[0-9K]$/', $id) || (int) substr($id, 0, -1) < 1 || Validator::make(['tax_id' => $id], ['tax_id' => new RutChileno])->fails()) {
            $this->registro->error('tax_id', 'Ingrese un RUT chileno válido con su dígito verificador.');
        }

        return ltrim(substr($id, 0, -1), '0').'-'.substr($id, -1);
    }

    private function pais(string $iso): void
    {
        if (! in_array($iso, array_column($this->operacion->paises(), 'iso2'), true)) {
            $this->registro->error('pais_iso2', 'Seleccione un país del catálogo operativo.');
        }
    }

    private function version(?object $actual, array $d): void
    {
        if (($actual?->version ?? 0) !== (int) $d['version']) {
            $this->registro->error('version', 'El registro cambió. Recargue antes de guardar.');
        }
    }
}
