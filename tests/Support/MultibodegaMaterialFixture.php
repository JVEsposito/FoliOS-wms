<?php

namespace Tests\Support;

use App\Models\AlmacenMaterial;
use App\Models\Camara;
use App\Models\DestinoMaterial;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\Posicion;
use App\Models\SaldoMaterialAlmacen;
use App\Services\Materiales\ServicioAlmacenMaterial;
use Illuminate\Support\Str;

trait MultibodegaMaterialFixture
{
    use TransferenciaClienteMaterialFixture;

    private array $norte;

    private AlmacenMaterial $central;

    private Camara $camaraNorte;

    private Posicion $posicionNorte;

    private string $tokenMultibodega;

    private DestinoMaterial $centroCosto;

    private function prepararMultibodega(): void
    {
        $this->prepararTransferencia();
        // El folio del fixture es una recepción heredada, ajena al correlativo de salidas.
        $this->origen->folio->update(['numero_folio' => 'MB-ORIGEN-0001']);
        $this->tokenMultibodega = $this->admin->createToken('multibodega', ['oficina'])->plainTextToken;
        $this->withToken($this->tokenMultibodega);
        $this->norte = $this->postJson('/api/materiales/bodegas', $this->datosBodega())->assertCreated()->json('data');
        $this->central = app(ServicioAlmacenMaterial::class)->bodegaCentral();
        $this->camaraNorte = Camara::create(['codigo' => 'MAT-NORTE', 'nombre' => 'Recinto norte', 'contenido' => 'materiales']);
        $this->posicionNorte = Posicion::create(['camara_id' => $this->camaraNorte->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $this->centroCosto = DestinoMaterial::create(['nombre' => 'Packing multibodega', 'centro_costo' => 'PACK-MB', 'activo' => true,
            'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
    }

    private function datosBodega(array $cambios = []): array
    {
        return [...['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => 'BOD-NORTE', 'nombre' => 'Bodega Norte', 'descripcion' => null, 'activo' => true], ...$cambios];
    }

    private function datosMovimiento(float $cantidad = 40, ?FolioMaterial $folio = null, ?Posicion $posicion = null): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'tipo' => 'transferencia', 'folio_id' => ($folio ?? $this->origen)->folio_id,
            'almacen_origen_id' => $this->central->id, 'almacen_destino_id' => $this->norte['id'], 'cantidad' => $cantidad,
            'camara_destino_id' => $this->camaraNorte->id, 'posicion_destino_id' => ($posicion ?? $this->posicionNorte)->id];
    }

    private function repartir(float $cantidad = 40): void
    {
        $this->withToken($this->tokenMultibodega)->postJson('/api/materiales/almacenes/movimientos', $this->datosMovimiento($cantidad))->assertCreated();
    }

    private function saldo(string $almacenId): SaldoMaterialAlmacen
    {
        return SaldoMaterialAlmacen::query()->where('folio_id', $this->origen->folio_id)->where('almacen_material_id', $almacenId)->firstOrFail();
    }

    private function despacho(string $almacenId, float $cantidad = 20): array
    {
        return $this->withToken($this->tokenMultibodega)->postJson('/api/materiales/despachos', [
            'operacion_id' => (string) Str::uuid(), 'destino_material_id' => $this->centroCosto->id, 'almacen_origen_id' => $almacenId,
            'items' => [['item_material_id' => $this->itemA->id, 'cantidad' => $cantidad]],
        ])->assertCreated()->json('data');
    }

    private function ordenNorte(float $cantidad = 20): array
    {
        $this->itemA->update(['categoria_operacional' => 'material_mp']);
        $this->origen->update(['categoria_operacional' => 'material_mp']);
        $salida = ItemMaterial::create(['cliente_material_id' => $this->itemA->cliente_material_id, 'codigo' => 'PT-MB', 'nombre' => 'Caja preparada',
            'categoria' => 'Embalaje', 'categoria_operacional' => 'material_pt', 'unidad_medida' => 'rollos', 'activo' => true,
            'creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        $receta = $this->withToken($this->tokenMultibodega)->postJson('/api/materiales/transformaciones/recetas', [
            'cliente_id' => $this->clienteA->id, 'item_salida_id' => $salida->id, 'nombre' => 'Proceso multibodega',
            'cantidad_base_salida' => 10, 'unidades_por_folio_salida' => 10,
            'componentes' => [['item_entrada_id' => $this->itemA->id, 'cantidad_estandar' => 10, 'es_componente_principal' => true]],
        ])->assertCreated()->json('data');

        return $this->postJson('/api/materiales/transformaciones/ordenes', ['operacion_id' => (string) Str::uuid(),
            'version_receta_material_id' => $receta['versiones'][0]['id'], 'almacen_origen_id' => $this->norte['id'],
            'cantidad_planificada_salida' => $cantidad, 'fecha_operacional' => now()->toDateString(),
        ])->assertCreated()->json('data');
    }

    private function planificarNorte(float $cantidad = 20): array
    {
        $orden = $this->ordenNorte($cantidad);

        return $this->postJson("/api/materiales/transformaciones/ordenes/{$orden['id']}/planificar", ['operacion_id' => (string) Str::uuid(), 'version_conocida' => 1])->assertOk()->json('data');
    }
}
