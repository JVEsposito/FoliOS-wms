<?php

namespace App\Finanzas\Proveedores;

use App\Finanzas\Models\AsientoCompra;
use App\Finanzas\Models\DocumentoCompra;
use App\Finanzas\Models\ProveedorFinanciero;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GestorDocumentosCompra
{
    public const TIPOS = ['33' => 'Factura afecta recibida', '34' => 'Factura exenta recibida', '52' => 'Guía de despacho recibida', '56' => 'Nota de débito recibida', '61' => 'Nota de crédito recibida', 'gasto' => 'Comprobante interno de gasto'];

    public function __construct(private readonly RegistroCompra $registro, private readonly PlanCuentasCompra $plan, private readonly ContabilidadCompra $contabilidad) {}

    public function guardar(array $datos, User $usuario, ?DocumentoCompra $documento = null): DocumentoCompra
    {
        $datos['tipo'] = (string) $datos['tipo'];
        if ($datos['tipo'] !== 'gasto' && (! ctype_digit($datos['folio']) || (int) $datos['folio'] < 1)) {
            throw ValidationException::withMessages(['folio' => 'El folio del documento tributario debe ser un número positivo.']);
        }
        $datos['folio'] = ctype_digit($datos['folio']) ? (ltrim($datos['folio'], '0') ?: '0') : mb_strtoupper(trim($datos['folio']));
        foreach (['neto', 'exento', 'iva', 'otros'] as $campo) {
            $datos[$campo] = (string) BigDecimal::of($datos[$campo])->toScale(0);
        }
        foreach ($datos['lineas'] as &$linea) {
            $linea['monto'] = (string) BigDecimal::of($linea['monto'])->toScale(0);
        }
        unset($linea);
        $datos['guias'] = array_values(array_unique($datos['guias'] ?? []));
        sort($datos['guias']);
        $datos['referencia_id'] ??= null;
        $datos['operacion_id'] ??= $documento?->operacion_id;
        $hash = $this->registro->hash(collect($datos)->except(['version'])->all());

        return $this->registro->exclusivo('documentos', function () use ($datos, $hash, $usuario, $documento): DocumentoCompra {
            if ($documento === null && ($previo = DocumentoCompra::query()->where('operacion_id', $datos['operacion_id'])->first())) {
                if (! hash_equals($previo->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['operacion_id' => 'Ese identificador ya se utilizó con otros datos.']);
                }

                return $previo;
            }
            $proveedor = ProveedorFinanciero::query()->lockForUpdate()->findOrFail($datos['proveedor_id']);
            $actual = $documento ? DocumentoCompra::query()->lockForUpdate()->findOrFail($documento->id) : null;
            if ($actual && ($actual->registrado_at !== null || $actual->anulado_at !== null || $actual->version !== $datos['version'])) {
                throw ValidationException::withMessages(['version' => 'Solo se puede corregir el borrador vigente. Vuelva a consultarlo.']);
            }
            if ($actual && $actual->proveedor_id !== $datos['proveedor_id']) {
                throw ValidationException::withMessages(['proveedor_id' => 'El proveedor de un borrador no se puede cambiar. Anule el borrador y cree el correcto.']);
            }
            if (! $proveedor->activo && ! in_array($datos['tipo'], ['56', '61'], true)) {
                throw ValidationException::withMessages(['proveedor_id' => 'La ficha está inactiva. Actívela para registrar nuevas compras.']);
            }
            if (DocumentoCompra::query()->where('proveedor_id', $proveedor->id)->where('tipo', $datos['tipo'])->where('folio', $datos['folio'])->when($actual, fn ($q) => $q->whereKeyNot($actual->id))->exists()) {
                throw ValidationException::withMessages(['folio' => 'El documento ya está ingresado para este proveedor y tipo. Corrija su borrador o consulte el original.']);
            }
            $campos = $this->comprobar($datos);
            $campos['proveedor_snapshot'] = collect($proveedor->toArray())->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'proveedor_material_id'])->all();
            $campos['version'] = ($actual?->version ?? 0) + 1;
            if ($actual) {
                $actual->update($campos);
            } else {
                $actual = DocumentoCompra::query()->create(['id' => (string) Str::uuid(), 'operacion_id' => $datos['operacion_id'], 'payload_hash' => $hash, ...$campos]);
            }
            $this->registro->evento('documento', $actual->id, $documento ? 'corregido' : 'borrador', $campos, $usuario);

            return $actual;
        });
    }

    private function comprobar(array $datos): array
    {
        $costo = BigDecimal::of($datos['neto'])->plus($datos['exento'])->plus($datos['otros']);
        $total = $costo->plus($datos['iva']);
        if ($datos['tipo'] !== '52' && ! $total->isPositive()) {
            throw ValidationException::withMessages(['total' => 'El documento debe tener un total positivo.']);
        }
        if (in_array($datos['tipo'], ['34', '52', 'gasto'], true) && BigDecimal::of($datos['iva'])->isPositive()) {
            throw ValidationException::withMessages(['iva' => 'Este tipo de documento no admite IVA en este registro.']);
        }
        if ($datos['tipo'] === '34' && BigDecimal::of($datos['neto'])->isPositive()) {
            throw ValidationException::withMessages(['neto' => 'En la factura exenta ingrese el importe en exento.']);
        }
        if (! in_array($datos['tipo'], ['34', '52', 'gasto'], true)) {
            $esperado = BigDecimal::of($datos['neto'])->multipliedBy('0.19')->toScale(0, RoundingMode::HalfUp);
            if (! $esperado->isEqualTo($datos['iva']) && blank($datos['observacion_iva'] ?? null)) {
                throw ValidationException::withMessages(['observacion_iva' => 'El IVA ingresado difiere del 19% del neto redondeado. Registre la explicación del documento recibido.']);
            }
        }
        $distribuido = BigDecimal::zero();
        $lineas = [];
        foreach ($datos['lineas'] as $linea) {
            $cuenta = $this->plan->buscar($linea['cuenta'], ['gasto', 'activo']);
            $distribuido = $distribuido->plus($linea['monto']);
            $lineas[] = [...$linea, 'cuenta_nombre' => $cuenta->nombre, 'cuenta_tipo' => $cuenta->tipo];
        }
        if (! $distribuido->isEqualTo($costo)) {
            throw ValidationException::withMessages(['lineas' => 'La distribución contable debe sumar neto + exento + otros. El IVA se registra por separado, pendiente de revisión.']);
        }
        $referencia = $datos['referencia_id'];
        if (in_array($datos['tipo'], ['56', '61'], true)) {
            $original = DocumentoCompra::query()->find($referencia);
            if ($original === null || $original->proveedor_id !== $datos['proveedor_id'] || ! in_array($original->tipo, ['33', '34'], true) || ! $original->registrado_at || $original->anulado_at) {
                throw ValidationException::withMessages(['referencia_id' => 'La nota debe referenciar una factura registrada y vigente del mismo proveedor.']);
            }
            if ($datos['fecha_emision'] < $original->fecha_emision->toDateString()) {
                throw ValidationException::withMessages(['fecha_emision' => 'La nota no puede preceder a su factura.']);
            }
        } elseif ($referencia !== null) {
            throw ValidationException::withMessages(['referencia_id' => 'La referencia a factura se utiliza solo para notas de crédito o débito.']);
        }
        if ($datos['guias'] !== [] && ! in_array($datos['tipo'], ['33', '34'], true)) {
            throw ValidationException::withMessages(['guias' => 'Las guías se vinculan desde la factura recibida.']);
        }
        foreach ($datos['guias'] as $id) {
            $guia = DocumentoCompra::query()->find($id);
            if ($guia === null || $guia->tipo !== '52' || $guia->proveedor_id !== $datos['proveedor_id'] || ! $guia->registrado_at || $guia->anulado_at) {
                throw ValidationException::withMessages(['guias' => 'Seleccione guías registradas y vigentes del mismo proveedor.']);
            }
        }
        $limite = CarbonImmutable::parse($datos['fecha_recepcion'])->addDays(30)->toDateString();
        if ($datos['vencimiento'] > $limite && blank($datos['respaldo_plazo'] ?? null)) {
            throw ValidationException::withMessages(['respaldo_plazo' => 'Un plazo superior a 30 días desde recepción requiere el respaldo del acuerdo excepcional legalmente procedente.']);
        }

        return [...collect($datos)->except(['operacion_id', 'version'])->all(), 'total' => (string) $total, 'lineas' => $lineas];
    }

    public function registrar(DocumentoCompra $documento, string $fecha, int $version, User $usuario): DocumentoCompra
    {
        return $this->registro->exclusivo('proveedor-'.$documento->proveedor_id, function () use ($documento, $fecha, $version, $usuario): DocumentoCompra {
            ProveedorFinanciero::query()->lockForUpdate()->findOrFail($documento->proveedor_id);
            $documento = DocumentoCompra::query()->lockForUpdate()->findOrFail($documento->id);
            if ($documento->anulado_at) {
                throw ValidationException::withMessages(['documento' => 'El documento está anulado internamente.']);
            }
            if ($documento->registrado_at) {
                if ($documento->fecha_contable->toDateString() !== $fecha) {
                    throw ValidationException::withMessages(['fecha_contable' => 'El documento ya se registró con otra fecha.']);
                }

                return $documento;
            }
            if ($documento->version !== $version) {
                throw ValidationException::withMessages(['version' => 'El borrador cambió. Vuelva a consultarlo antes de registrar.']);
            }
            if ($fecha < $documento->fecha_emision->toDateString()) {
                throw ValidationException::withMessages(['fecha_contable' => 'La fecha contable no puede preceder a la emisión.']);
            }
            // Revalida referencias y distribución; una ficha posterior no altera el snapshot histórico.
            $this->comprobar([...$documento->toArray(), 'fecha_emision' => $documento->fecha_emision->toDateString(), 'fecha_recepcion' => $documento->fecha_recepcion->toDateString(), 'vencimiento' => $documento->vencimiento->toDateString()]);
            if ($documento->referencia_id !== null) {
                $original = DocumentoCompra::query()->lockForUpdate()->findOrFail($documento->referencia_id);
                if ($fecha < $original->fecha_contable->toDateString()) {
                    throw ValidationException::withMessages(['fecha_contable' => 'La nota no puede contabilizarse antes que su factura.']);
                }
                if ($documento->tipo === '61') {
                    $acumulado = BigDecimal::of(DocumentoCompra::query()->where('referencia_id', $original->id)->where('tipo', '61')->whereNotNull('registrado_at')->whereNull('anulado_at')->sum('total'));
                    if ($acumulado->plus($documento->total)->isGreaterThan($original->total)) {
                        throw ValidationException::withMessages(['total' => 'Las notas de crédito excederían el total de la factura referenciada.']);
                    }
                }
            }
            $this->contabilidad->periodoAbierto($fecha);
            if ($documento->tipo !== '52') {
                $pasivo = $this->plan->buscar(config('finanzas-compras.pasivo'), ['pasivo']);
                $lineas = [];
                foreach ($documento->lineas as $l) {
                    if (BigDecimal::of($l['monto'])->isPositive()) {
                        $lineas[] = ['cuenta' => $l['cuenta'], 'cuenta_nombre' => $l['cuenta_nombre'], 'cuenta_tipo' => $l['cuenta_tipo'], 'descripcion' => $l['descripcion'], 'ceco' => $l['ceco'] ?? null, 'debe' => $l['monto'], 'haber' => '0'];
                    }
                }
                if (BigDecimal::of($documento->iva)->isPositive()) {
                    $iva = $this->plan->buscar(config('finanzas-compras.iva'), ['iva']);
                    $lineas[] = ['cuenta' => $iva->codigo, 'cuenta_nombre' => $iva->nombre, 'cuenta_tipo' => 'iva', 'descripcion' => 'IVA soportado pendiente de revisión', 'ceco' => null, 'debe' => $documento->iva, 'haber' => '0'];
                }
                $lineas[] = ['cuenta' => $pasivo->codigo, 'cuenta_nombre' => $pasivo->nombre, 'cuenta_tipo' => 'pasivo', 'descripcion' => self::TIPOS[$documento->tipo].' '.$documento->folio, 'ceco' => null, 'debe' => '0', 'haber' => $documento->total];
                if ($documento->tipo === '61') {
                    foreach ($lineas as &$linea) {
                        [$linea['debe'], $linea['haber']] = [$linea['haber'], $linea['debe']];
                    }
                }
                unset($linea);
                $this->contabilidad->registrar($documento->proveedor_id, 'documento', $documento->id, $fecha, self::TIPOS[$documento->tipo].' '.$documento->folio, $lineas, $usuario);
            }
            $documento->update(['registrado_at' => now(), 'fecha_contable' => $fecha, 'version' => $documento->version + 1]);
            $this->registro->evento('documento', $documento->id, 'registrado', ['fecha_contable' => $fecha], $usuario);

            return $documento;
        });
    }

    public function anular(DocumentoCompra $documento, string $fecha, string $motivo, User $usuario): DocumentoCompra
    {
        return $this->registro->exclusivo('proveedor-'.$documento->proveedor_id, function () use ($documento, $fecha, $motivo, $usuario): DocumentoCompra {
            ProveedorFinanciero::query()->lockForUpdate()->findOrFail($documento->proveedor_id);
            $documento = DocumentoCompra::query()->lockForUpdate()->findOrFail($documento->id);
            if ($documento->anulado_at) {
                return $documento;
            }
            if ($documento->registrado_at) {
                $vinculados = DocumentoCompra::query()->whereNotNull('registrado_at')->whereNull('anulado_at')->where(fn ($q) => $q->where('referencia_id', $documento->id)->orWhereJsonContains('guias', $documento->id))->exists();
                if ($vinculados) {
                    throw ValidationException::withMessages(['documento' => 'Hay notas o facturas vinculadas vigentes. Revíselas antes de revertir el documento.']);
                }
                if ($fecha < $documento->fecha_contable->toDateString()) {
                    throw ValidationException::withMessages(['fecha_contable' => 'El reverso no puede preceder al registro original.']);
                }
                $this->contabilidad->periodoAbierto($fecha);
                if ($documento->tipo !== '52') {
                    $this->contabilidad->reversar(AsientoCompra::query()->where('origen_tipo', 'documento')->where('origen_id', $documento->id)->firstOrFail(), $fecha, $motivo, $usuario);
                }
            }
            $documento->update(['anulado_at' => now(), 'fecha_anulacion' => $documento->registrado_at ? $fecha : null, 'motivo_anulacion' => $motivo, 'version' => $documento->version + 1]);
            $this->registro->evento('documento', $documento->id, 'anulado_internamente', ['motivo' => $motivo, 'fecha' => $fecha], $usuario);

            return $documento;
        });
    }
}
