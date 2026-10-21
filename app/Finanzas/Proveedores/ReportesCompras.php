<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\CuentaCompra;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\LineaAsientoCompra;
use App\Finanzas\Models\PeriodoCompra;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ReportesCompras
{
    public function __construct(private readonly EsquemaFinanzas $esquema, private readonly RegistroCompra $registro) {}

    public function balance(string $desde, string $hasta, ?string $proveedor): array
    {
        return $this->esquema->transaccion(function () use ($desde, $hasta, $proveedor): array {
            $q = LineaAsientoCompra::query()->join('fin_asientos_compra as a', 'a.id', '=', 'fin_asiento_compra_lineas.asiento_id')->join('fin_proveedores as p', 'p.id', '=', 'a.proveedor_id')->when($proveedor, fn ($q) => $q->where('a.proveedor_id', $proveedor));
            $aperturas = (clone $q)->where('a.fecha', '<', $desde)->selectRaw('cuenta, SUM(debe - haber) as saldo')->groupBy('cuenta')->pluck('saldo', 'cuenta');
            $movimientos = (clone $q)->whereBetween('a.fecha', [$desde, $hasta])->selectRaw('cuenta, SUM(debe) as debe, SUM(haber) as haber')->groupBy('cuenta')->get()->keyBy('cuenta');
            $codigos = $aperturas->keys()->merge($movimientos->keys())->unique()->sort()->values();
            $cuentas = CuentaCompra::query()->whereIn('codigo', $codigos)->get()->keyBy('codigo');
            $filas = [];
            foreach ($codigos as $codigo) {
                $inicial = BigDecimal::of($aperturas[$codigo] ?? '0');
                $m = $movimientos[$codigo] ?? null;
                $debe = (string) ($m?->debe ?? '0');
                $haber = (string) ($m?->haber ?? '0');
                $filas[] = ['cuenta' => (string) $codigo, 'nombre' => $cuentas[$codigo]->nombre, 'tipo' => $cuentas[$codigo]->tipo, 'saldo_inicial' => (string) $inicial, 'debe' => $debe, 'haber' => $haber, 'saldo_final' => (string) $inicial->plus($debe)->minus($haber)];
            }
            $gastos = (clone $q)->whereBetween('a.fecha', [$desde, $hasta])->where('cuenta_tipo', 'gasto')->selectRaw('cuenta, ceco, p.razon_social as proveedor_nombre, p.rut as proveedor_rut, SUM(debe - haber) as monto')->groupBy('cuenta', 'ceco', 'p.id', 'p.razon_social', 'p.rut')->orderBy('cuenta')->orderBy('ceco')->get()->toArray();

            return ['desde' => $desde, 'hasta' => $hasta, 'moneda' => 'CLP', 'cuentas' => $filas, 'gastos' => $gastos];
        });
    }

    public function mayor(string $cuenta, string $desde, string $hasta): array
    {
        return $this->esquema->transaccion(function () use ($cuenta, $desde, $hasta): array {
            $q = LineaAsientoCompra::query()->join('fin_asientos_compra as a', 'a.id', '=', 'fin_asiento_compra_lineas.asiento_id')->join('fin_proveedores as p', 'p.id', '=', 'a.proveedor_id')->where('cuenta', $cuenta);
            $inicial = BigDecimal::of((clone $q)->where('a.fecha', '<', $desde)->selectRaw('COALESCE(SUM(debe - haber), 0) as saldo')->value('saldo'));
            $movimiento = BigDecimal::of((clone $q)->whereBetween('a.fecha', [$desde, $hasta])->selectRaw('COALESCE(SUM(debe - haber), 0) as saldo')->value('saldo'));
            $filas = (clone $q)->whereBetween('a.fecha', [$desde, $hasta])->select(['fin_asiento_compra_lineas.*', 'a.fecha', 'a.glosa', 'p.razon_social as proveedor_nombre', 'p.rut as proveedor_rut'])
                ->selectRaw('SUM(debe - haber) OVER (ORDER BY a.fecha, a.created_at, a.id, fin_asiento_compra_lineas.id) as acumulado')
                ->orderBy('a.fecha')->orderBy('a.created_at')->orderBy('a.id')->orderBy('fin_asiento_compra_lineas.id')->paginate(50);
            $filas->through(fn ($l) => ['fecha' => $l->fecha, 'glosa' => $l->glosa, 'proveedor_nombre' => $l->proveedor_nombre, 'proveedor_rut' => $l->proveedor_rut, 'descripcion' => $l->descripcion, 'ceco' => $l->ceco, 'debe' => $l->debe, 'haber' => $l->haber, 'saldo' => (string) $inicial->plus($l->acumulado)]);

            return ['cuenta' => $cuenta, 'saldo_inicial' => (string) $inicial, 'saldo_final' => (string) $inicial->plus($movimiento), 'movimientos' => $filas];
        });
    }

    public function periodo(string $mes, bool $cerrar, string $motivo, User $usuario): PeriodoCompra
    {
        return $this->registro->exclusivo('cierre-'.$mes, function () use ($mes, $cerrar, $motivo, $usuario): PeriodoCompra {
            PeriodoCompra::query()->insertOrIgnore(['mes' => $mes, 'created_at' => now(), 'updated_at' => now()]);
            $p = PeriodoCompra::query()->lockForUpdate()->findOrFail($mes);
            if ($cerrar === ($p->cerrado_at !== null)) {
                return $p;
            }
            if ($cerrar && DocumentoCompra::query()->whereNull('registrado_at')->whereNull('anulado_at')->whereBetween('fecha_emision', [$mes.'-01', CarbonImmutable::parse($mes.'-01')->endOfMonth()->toDateString()])->exists()) {
                throw ValidationException::withMessages(['mes' => 'Hay borradores del mes. Regístrelos o anúlelos antes de cerrar.']);
            }
            if (! $cerrar && PeriodoCompra::query()->whereNotNull('cerrado_at')->where('mes', '>', $mes)->exists()) {
                throw ValidationException::withMessages(['mes' => 'Solo se puede reabrir el último mes cerrado.']);
            }
            $p->update(['cerrado_at' => $cerrar ? now() : null]);
            $this->registro->evento('periodo', $mes, $cerrar ? 'cerrado' : 'reabierto', ['motivo' => $motivo], $usuario);

            return $p;
        });
    }
}
