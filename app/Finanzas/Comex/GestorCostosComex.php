<?php

namespace App\Finanzas\Comex;

use App\Finanzas\Models\AplicacionProveedor;
use App\Finanzas\Models\CostoComex;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\ImputacionComex;
use App\Finanzas\Models\OperacionCostoComex;
use App\Finanzas\Proveedores\RegistroCompra;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Distribución auxiliar: nunca crea documentos, asientos, pagos ni cargos a clientes. */
final class GestorCostosComex
{
    public function __construct(private RegistroCompra $registro, private LectorComex $lector, private ValorCosto $valor) {}

    public function guardar(array $d, User $actor, ?CostoComex $costo = null): array
    {
        return $this->operar('guardar', $d, $costo, function () use ($d, $actor, $costo) {
            $c = $costo ? $this->bloquear($costo, $d['version']) : null;
            if (! $c && $d['version'] !== 0) {
                $this->error('version', 'Un control nuevo parte de cero.');
            }
            if ($c && ($c->booking_id !== $d['booking_id'] || $c->contenedor_id !== ($d['contenedor_id'] ?? null))) {
                $this->error('booking_id', 'La reserva y el contenedor identifican este control y son permanentes.');
            }
            $fuente = $this->lector->booking($d['booking_id'], $d['contenedor_id'] ?? null);
            if (! in_array($fuente['booking']['estado'], ['confirmado', 'cerrado'], true)) {
                $this->error('booking_id', 'Seleccione un booking confirmado o cerrado.');
            }
            $antes = $c?->toArray();
            $c ??= new CostoComex(['id' => (string) Str::uuid(), 'booking_id' => $d['booking_id'], 'contenedor_id' => $d['contenedor_id'] ?? null, 'version' => 0, 'estado' => 'abierto']);
            $c->fill([...collect($d)->only(['concepto', 'tratamiento', 'fecha', 'moneda', 'previsto', 'respaldo'])->all(), 'comprometido' => $d['comprometido'] ?? null]);
            $c->conversion = $this->valor->convertir($d);
            $c->fuente = $fuente;
            $c->source_version = $this->registro->hash($fuente);
            $c->captured_at = now();
            $c->version++;
            $c->save();
            $this->registro->evento('costo_comex', $c->id, 'guardado', ['antes' => $antes, 'despues' => $c->fresh()->toArray(), 'motivo' => $d['motivo'] ?? null], $actor);

            return $this->detalle($c->fresh());
        });
    }

    public function cambiar(CostoComex $costo, array $d, User $actor): array
    {
        return $this->operar($d['accion'], $d, $costo, function () use ($costo, $d, $actor) {
            $c = $this->bloquear($costo, $d['version'], $d['accion'] === 'reabrir' ? ['cerrado'] : ['abierto']);
            $antes = $c->toArray();
            switch ($d['accion']) {
                case 'imputar':
                    $doc = DocumentoCompra::query()->lockForUpdate()->findOrFail($d['documento_id']);
                    $linea = $doc->lineas[$d['linea']] ?? null;
                    if (! $doc->registrado_at || $doc->anulado_at || ! in_array($doc->tipo, ['33', '34', '56', '61', 'gasto'], true) || ! $linea || $linea['cuenta_tipo'] !== 'gasto') {
                        $this->error('documento_id', 'Seleccione una línea de gasto de un documento registrado y vigente; las guías no generan costo.');
                    }
                    $monto = BigDecimal::of($d['monto']);
                    $ocupado = BigDecimal::zero();
                    foreach (ImputacionComex::query()->where('documento_id', $doc->id)->where('linea', $d['linea'])->where('activa', true)->pluck('monto') as $v) {
                        $ocupado = $ocupado->plus($v);
                    }
                    if (! $monto->isPositive() || $ocupado->plus($monto)->isGreaterThan($linea['monto'])) {
                        $this->error('monto', 'La distribución excedería el monto disponible de esa línea de compra.');
                    }
                    if ($doc->tipo === '61') {
                        $base = BigDecimal::zero();
                        $creditos = BigDecimal::zero();
                        foreach (ImputacionComex::query()->where('costo_id', $c->id)->where('activa', true)->get() as $i) {
                            if ($i->documento_id === $doc->referencia_id) {
                                $base = $base->plus($i->monto);
                            }
                            if ($i->signo === -1 && ($i->documento['referencia_id'] ?? null) === $doc->referencia_id) {
                                $creditos = $creditos->plus($i->monto);
                            }
                        }
                        if ($creditos->plus($monto)->isGreaterThan($base)) {
                            $this->error('monto', 'El abono excede la factura de origen distribuida a este mismo costo.');
                        }
                    }
                    $copia = $this->documento($doc, $d['linea']);
                    ImputacionComex::query()->create(['id' => (string) Str::uuid(), 'costo_id' => $c->id, 'documento_id' => $doc->id, 'linea' => $d['linea'], 'monto' => (string) $monto, 'signo' => $doc->tipo === '61' ? -1 : 1, 'documento' => $copia, 'source_version' => $this->registro->hash($copia)]);
                    break;
                case 'retirar_imputacion':
                    $i = ImputacionComex::query()->where('costo_id', $c->id)->where('activa', true)->findOrFail($d['imputacion_id']);
                    if ($i->signo === 1 && ImputacionComex::query()->where('costo_id', $c->id)->where('activa', true)->where('signo', -1)->where('documento->referencia_id', $i->documento_id)->exists()) {
                        $this->error('imputacion_id', 'Retire primero las notas de crédito distribuidas contra esa factura.');
                    }
                    $i->update(['activa' => false]);
                    break;
                case 'cerrar':
                    $detalle = $this->detalle($c);
                    if ($detalle['alertas'] !== [] || ! ImputacionComex::query()->where('costo_id', $c->id)->where('activa', true)->exists()) {
                        $this->error('cierre', 'Revise las diferencias de fuentes y distribuya al menos un documento vigente antes de cerrar.');
                    }
                    $c->cierre = ['real_clp' => $detalle['real_vigente_clp'], 'conversion' => $c->conversion, 'imputaciones' => $detalle['imputaciones'], 'source_version' => $c->source_version, 'fecha' => now()->toIso8601String(), 'user_id' => $actor->id, 'user_nombre' => $actor->name];
                    $c->estado = 'cerrado';
                    break;
                case 'reabrir':
                    $c->estado = 'abierto';
                    break;
                case 'anular':
                    if (ImputacionComex::query()->where('costo_id', $c->id)->where('activa', true)->exists()) {
                        $this->error('estado', 'Retire las distribuciones antes de anular el control.');
                    }
                    $c->estado = 'anulado';
                    break;
            }
            $c->version++;
            $c->save();
            $this->registro->evento('costo_comex', $c->id, $d['accion'], ['antes' => $antes, 'despues' => $c->toArray(), 'solicitud' => collect($d)->except('operacion_id')->all()], $actor);

            return $this->detalle($c->fresh());
        });
    }

    public function detalle(CostoComex $c): array
    {
        $alertas = [];
        $fuente = null;
        try {
            $fuente = $this->lector->booking($c->booking_id, $c->contenedor_id);
        } catch (QueryException|ValidationException) {
            $alertas[] = 'La fuente COMEX no está disponible o el contenedor fue retirado.';
        }
        if ($fuente && $this->registro->hash($fuente) !== $c->source_version) {
            $alertas[] = 'El booking cambió desde su captura. Revise y actualice el control abierto.';
        }
        if ($fuente && ! in_array($fuente['booking']['estado'], ['confirmado', 'cerrado'], true)) {
            $alertas[] = 'El booking ya no está confirmado ni cerrado.';
        }
        $real = BigDecimal::zero();
        $imputaciones = [];
        foreach (ImputacionComex::query()->where('costo_id', $c->id)->orderBy('created_at')->orderBy('id')->get() as $i) {
            $doc = DocumentoCompra::query()->find($i->documento_id);
            $copia = $doc ? $this->documento($doc, $i->linea) : null;
            $vigente = $doc && $doc->registrado_at && ! $doc->anulado_at && $copia && $this->registro->hash($copia) === $i->source_version;
            if ($i->activa && $vigente) {
                $real = $real->plus(BigDecimal::of($i->monto)->multipliedBy($i->signo));
            }
            if ($i->activa && ! $vigente) {
                $alertas[] = 'Cambió o se anuló el documento '.$i->documento['folio'].'. Revise su distribución.';
            }
            $aplicado = BigDecimal::zero();
            foreach (AplicacionProveedor::query()->where('documento_id', $i->documento_id)->whereNull('anulado_at')->pluck('monto') as $m) {
                $aplicado = $aplicado->plus($m);
            }
            $imputaciones[] = [...$i->toArray(), 'fuente_vigente' => (bool) $vigente, 'aplicado_documento_clp' => (string) $aplicado, 'estado_documento' => $doc?->anulado_at ? 'anulado' : ($doc?->registrado_at ? 'registrado' : 'no disponible')];
        }

        return ['costo' => $c->toArray(), 'fuente_actual' => $fuente, 'imputaciones' => $imputaciones, 'real_vigente_clp' => (string) $real, 'real_cierre_clp' => $c->cierre['real_clp'] ?? null, 'alertas' => array_values(array_unique($alertas))];
    }

    private function documento(DocumentoCompra $d, int $linea): array
    {
        return [...collect($d->toArray())->only(['id', 'proveedor_id', 'proveedor_snapshot', 'tipo', 'folio', 'revision', 'version', 'referencia_id', 'registrado_at', 'anulado_at', 'fecha_contable', 'total'])->all(), 'linea_indice' => $linea, 'linea' => $d->lineas[$linea] ?? null];
    }

    private function operar(string $accion, array $d, ?CostoComex $costo, callable $fn): array
    {
        return $this->registro->exclusivo('comex', function () use ($accion, $d, $costo, $fn) {
            $hash = $this->registro->hash(['costo_id' => $costo?->id, ...$d]);
            $previa = OperacionCostoComex::query()->find($d['operacion_id']);
            if ($previa) {
                if ($previa->accion !== $accion || $previa->payload_hash !== $hash) {
                    $this->error('operacion_id', 'Ese UUID ya se usó para otra solicitud.');
                }

                return $previa->resultado;
            }
            $r = $fn();
            OperacionCostoComex::query()->create(['id' => $d['operacion_id'], 'accion' => $accion, 'payload_hash' => $hash, 'resultado' => $r, 'created_at' => now()]);

            return $r;
        });
    }

    private function bloquear(CostoComex $c, int $version, array $estados = ['abierto']): CostoComex
    {
        $c = CostoComex::query()->lockForUpdate()->findOrFail($c->id);
        if ($c->version !== $version || ! in_array($c->estado, $estados, true)) {
            $this->error('version', 'El control cambió o está cerrado/anulado. Recargue.');
        }

        return $c;
    }

    private function error(string $campo, string $texto): never
    {
        throw ValidationException::withMessages([$campo => $texto]);
    }
}
