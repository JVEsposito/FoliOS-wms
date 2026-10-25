<?php

namespace App\Services\Materiales;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\EstadoOrdenTransformacionMaterial;
use App\Enums\TipoMovimientoAlmacenMaterial;
use App\Models\FolioMaterial;
use App\Models\OrdenTransformacionMaterial;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaActiva;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/** La imputación reparte un único consumo físico, sin crear otro inventario. */
class ServicioImputacionConsumoMaterial
{
    public function bloquear(array $datos, TipoMovimientoAlmacenMaterial $tipo, User $usuario): Collection
    {
        $lineas = $datos['imputaciones_ot'] ?? [];
        if ($lineas === []) {
            return new Collection;
        }
        Gate::forUser($usuario)->authorize('gestionar-transformaciones-materiales');
        if ($tipo !== TipoMovimientoAlmacenMaterial::Consumo || count($lineas) > 50) {
            throw new DomainException('Solo un consumo puede repartirse entre hasta 50 órdenes.');
        }
        $ids = array_column($lineas, 'orden_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new DomainException('Una orden no puede repetirse en la distribución del consumo.');
        }
        // Mismo orden de bloqueo que la transformación: orden antes de folios/saldos.
        $temporada = app(ServicioTemporadaActiva::class)->obtener(bloquear: true);
        $ordenes = OrdenTransformacionMaterial::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($ordenes->count() !== count($lineas) || $ordenes->contains(fn ($o) => $o->temporada_id !== $temporada->id
            || ! in_array($o->estado, [EstadoOrdenTransformacionMaterial::EnProceso, EstadoOrdenTransformacionMaterial::PendienteCierre], true))) {
            throw new DomainException('Distribuye el consumo en órdenes en ejecución de la temporada activa.');
        }

        return $ordenes;
    }

    public function preparar(array $datos, FolioMaterial $folio, Collection $ordenes, User $usuario): array
    {
        if ($ordenes->isEmpty()) {
            return [];
        }
        if ($folio->categoria_operacional !== CategoriaOperacionalMaterial::Insumo) {
            throw new DomainException('El consumo compartido entre órdenes solo admite insumos.');
        }
        $filas = [];
        $total = 0;
        foreach ($datos['imputaciones_ot'] as $linea) {
            $orden = $ordenes->firstWhere('id', $linea['orden_id']);
            if ($orden->cliente_id !== $folio->item->cliente->cliente_id || $orden->temporada_id !== $folio->folio->temporada_id) {
                throw new DomainException('El insumo y todas las órdenes deben pertenecer al mismo cliente y temporada.');
            }
            $cantidad = $this->milesimas($linea['cantidad']);
            $merma = $this->milesimas($linea['cantidad_merma'] ?? 0);
            if ($cantidad <= 0 || $merma < 0 || $merma > $cantidad) {
                throw new DomainException('Cada orden requiere una cantidad positiva y una merma que no supere su consumo.');
            }
            $total += $cantidad;
            $filas[] = ['orden_transformacion_material_id' => $orden->id, 'cantidad' => $cantidad / 1000, 'cantidad_merma' => $merma / 1000,
                'snapshot' => ['item_id' => $folio->item_material_id, 'codigo' => $folio->item->codigo, 'nombre' => $folio->item->nombre,
                    'unidad' => $folio->unidad_medida, 'folio' => $folio->folio->numero_folio,
                    'referencia_ot' => $orden->referencia_ot, 'proceso' => $orden->proceso, 'turno' => $orden->turno,
                    'linea' => $orden->linea, 'fecha_operacional' => $orden->fecha_operacional?->toDateString(),
                    'operador' => trim((string) ($datos['operador_nombre'] ?? '')) ?: $orden->operador_nombre,
                    'registrado_por_id' => $usuario->id, 'registrado_por' => $usuario->name]];
        }
        if ($total !== $this->milesimas($datos['cantidad'])) {
            throw new DomainException('La suma distribuida entre órdenes debe coincidir exactamente con el consumo físico.');
        }

        return $filas;
    }

    private function milesimas(mixed $valor): int
    {
        if (! is_numeric($valor) || ! is_finite((float) $valor) || abs((float) $valor) > 99999999999.999
            || abs((float) $valor * 1000 - round((float) $valor * 1000)) > 0.01) {
            throw new DomainException('Utiliza cantidades válidas con hasta tres decimales.');
        }

        return (int) round((float) $valor * 1000);
    }
}
