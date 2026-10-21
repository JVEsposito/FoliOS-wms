<?php

namespace App\Rrhh\Nomina;

use App\Models\User;
use App\Rrhh\Auditoria;
use App\Rrhh\EsquemaRrhh;
use App\Rrhh\Models\ConceptoNomina;
use App\Rrhh\Models\ContratoLaboral;
use App\Rrhh\Models\Empleador;
use App\Rrhh\Models\Institucion;
use App\Rrhh\Models\MandatoDescuento;
use App\Rrhh\Models\MovimientoNomina;
use App\Rrhh\Models\Persona;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DestinosNomina
{
    public function mandato(array $d, User $actor): MandatoDescuento
    {
        return app(EsquemaRrhh::class)->transaccion(function () use ($d, $actor) {
            Persona::query()->lockForUpdate()->findOrFail($d['persona_id']);
            $empleador = Empleador::query()->findOrFail($d['empleador_id']);
            if ($empleador->tipo !== 'propio' || ! $empleador->activo) {
                $this->error('empleador_id', 'El descuento de nómina propia debe identificar un empleador propio activo. El cumplimiento de contratistas se controla por separado.');
            }
            $c = ConceptoNomina::query()->findOrFail($d['concepto_id']);
            $i = $this->institucion($d['institucion_id'], $c, $d['desde']);
            if ($c->tipo !== 'descuento' || $c->calculo === 'horas_extra' || ! $c->activo || $i['hasta'] < $d['hasta']) {
                $this->error('concepto_id', 'El mandato requiere un descuento activo y una institución que cubra la vigencia.');
            }
            if (! ReglasChile::decimal($d['cuota'])->isGreaterThan(0)) {
                $this->error('cuota', 'La cuota o porcentaje debe ser positivo.');
            }
            if ($c->calculo === 'porcentaje') {
                if (ReglasChile::decimal($d['cuota'])->isGreaterThan(100) || empty($d['base_porcentaje']) || isset($d['deuda_total']) || isset($d['cuotas'])) {
                    $this->error('cuota', 'El porcentaje requiere una base identificada, hasta 100%, sin deuda ni número fijo de cuotas.');
                }
            } elseif (isset($d['base_porcentaje']) || (isset($d['deuda_total']) !== isset($d['cuotas'])) || (isset($d['deuda_total']) && (ReglasChile::decimal($d['deuda_total'])->isLessThanOrEqualTo(0) || ReglasChile::decimal($d['cuota'])->multipliedBy($d['cuotas'])->isLessThan($d['deuda_total'])))) {
                $this->error('deuda_total', 'La deuda en CLP requiere cuotas suficientes para cubrirla; un monto fijo no lleva base porcentual.');
            }
            if ($c->grupo_descuento === 'vivienda' && ! $d['pago_directo_institucion']) {
                $this->error('pago_directo_institucion', 'Vivienda/educación requiere pago directo a la institución según artículo 58.');
            }
            if (! empty($d['excepcion_ccaf']) && $c->grupo_descuento !== 'ccaf') {
                $this->error('excepcion_ccaf', 'La excepción documentada solo corresponde a créditos CCAF.');
            }
            $this->regimenApv($c, $d['regimen_apv'] ?? null);
            if (MandatoDescuento::query()->where('persona_id', $d['persona_id'])->where('institucion_id', $d['institucion_id'])->where('referencia', $d['referencia'])->exists()) {
                $this->error('referencia', 'Esta referencia ya tiene mandato para la persona y la institución.');
            }
            $m = MandatoDescuento::query()->create(['id' => (string) Str::uuid(), 'concepto' => $c->toArray(), 'institucion' => $i, ...$d]);
            app(Auditoria::class)->registrar('mandato_descuento', $m->id, 'creado', $m->toArray(), $actor);

            return $m;
        });
    }

    public function saldo(MandatoDescuento $m): array
    {
        $q = MovimientoNomina::query()->where('mandato_id', $m->id)->where('estado', 'aprobado');
        $descontado = ReglasChile::decimal((string) (clone $q)->sum('total'));
        $saldo = $m->deuda_total === null ? null : ReglasChile::decimal($m->deuda_total)->minus($descontado);

        return ['descontado' => ReglasChile::pesos($descontado), 'saldo_por_descontar' => $saldo === null ? null : ReglasChile::pesos($saldo), 'cuotas_descontadas' => $q->count()];
    }

    public function preparar(array $d, ConceptoNomina $c): array
    {
        $mandato = null;
        $empleador = null;
        if (! empty($d['mandato_id'])) {
            $m = MandatoDescuento::query()->findOrFail($d['mandato_id']);
            if ($m->persona_id !== $d['persona_id'] || $m->concepto_id !== $c->id || $m->desde > $d['fecha'] || $m->hasta < $d['fecha'] || ! empty($d['pacto_id'])) {
                $this->error('mandato_id', 'El mandato debe corresponder a la persona, concepto y fecha, sin combinarlo con una asignación mensual.');
            }
            $saldo = $this->saldo($m);
            if (($saldo['saldo_por_descontar'] !== null && ! ReglasChile::decimal($saldo['saldo_por_descontar'])->isGreaterThan(0)) || ($m->cuotas !== null && $saldo['cuotas_descontadas'] >= $m->cuotas)) {
                $this->error('mandato_id', 'El mandato ya completó su deuda o sus cuotas.');
            }
            if ($c->calculo === 'porcentaje') {
                $base = $d[$m->base_porcentaje] ?? null;
                if ($base === null) {
                    $this->error($m->base_porcentaje, 'Indique la base documentada del porcentaje autorizado.');
                }
                $d['cantidad'] = $m->cuota;
                $d['valor'] = $base;
            } else {
                $cuota = ReglasChile::decimal($m->cuota);
                if ($saldo['saldo_por_descontar'] !== null && $cuota->isGreaterThan($saldo['saldo_por_descontar'])) {
                    $cuota = ReglasChile::decimal($saldo['saldo_por_descontar']);
                }
                $d['cantidad'] = '1';
                $d['valor'] = ReglasChile::texto($cuota);
            }
            $d['institucion_id'] = $m->institucion_id;
            $d['autorizacion'] = $m->respaldo;
            $d['pago_directo_institucion'] = $m->pago_directo_institucion;
            $d['excepcion_ccaf'] = $m->excepcion_ccaf;
            if ($m->regimen_apv) {
                $d['regimen_apv'] = $m->regimen_apv;
            }
            $empleador = $m->empleador_id;
            $mandato = [...$m->toArray(), ...$saldo];
        }
        $id = $d['institucion_id'] ?? $c->institucion_id;
        $this->regimenApv($c, $d['regimen_apv'] ?? null);
        $institucion = $id ? $this->institucion($id, $c, $d['fecha']) : null;
        if (in_array($c->grupo_descuento, ['ccaf', 'sindical', 'apv'], true) && ! $institucion) {
            $this->error('institucion_id', 'Este régimen necesita identificar la institución destinataria.');
        }
        $contrato = null;
        if ($institucion) {
            $contratos = ContratoLaboral::query()->where('persona_id', $d['persona_id'])->where('desde', '<=', $d['fecha'])->where('hasta', '>=', $d['fecha'])
                ->when($empleador, fn ($q) => $q->where('empleador_id', $empleador))->when($d['contrato_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get()
                ->filter(fn ($fila) => $fila->empleador['tipo'] === 'propio' && $fila->tipo !== 'honorarios');
            if ($contratos->count() !== 1) {
                $this->error('contrato_id', 'Seleccione un contrato laboral propio vigente y del empleador del mandato. Si hay varios, identifique cuál corresponde.');
            }
            $contrato = $contratos->first()->toArray();
        }

        return ['entrada' => $d, 'institucion' => $institucion, 'mandato' => $mandato, 'contrato' => $contrato];
    }

    public function institucion(string $id, ConceptoNomina $c, ?string $fecha = null): array
    {
        $i = Institucion::query()->findOrFail($id);
        $tipos = ['cooperativa' => ['cooperativa'], 'ccaf' => ['ccaf'], 'sindical' => ['sindicato'], 'apv' => ['apv', 'afp']];
        if ($c->tipo === 'haber' || ! $i->activo || ($fecha && ($i->desde > $fecha || $i->hasta < $fecha)) || (isset($tipos[$c->grupo_descuento]) && ! in_array($i->tipo, $tipos[$c->grupo_descuento], true))) {
            $this->error('institucion_id', 'La institución debe estar activa, vigente y corresponder al régimen del concepto.');
        }

        return $i->toArray();
    }

    private function error(string $campo, string $mensaje): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }

    private function regimenApv(ConceptoNomina $c, ?string $regimen): void
    {
        if (($c->grupo_descuento === 'apv' && ! in_array($regimen, ['A', 'B'], true)) || ($c->grupo_descuento !== 'apv' && $regimen !== null)) {
            $this->error('regimen_apv', 'El APV requiere régimen tributario A o B. No aplique esa selección a otros descuentos.');
        }
    }
}
