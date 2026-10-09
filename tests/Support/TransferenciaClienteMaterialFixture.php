<?php

namespace Tests\Support;

use App\Enums\CategoriaOperacionalMaterial;
use App\Enums\ContenidoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Cliente;
use App\Models\ClienteMaterial;
use App\Models\Folio;
use App\Models\FolioMaterial;
use App\Models\ItemMaterial;
use App\Models\Posicion;
use App\Models\ProveedorMaterial;
use App\Models\UbicacionActual;
use App\Models\User;
use Illuminate\Support\Str;

trait TransferenciaClienteMaterialFixture
{
    private User $admin;

    private Cliente $clienteA;

    private Cliente $clienteB;

    private ItemMaterial $itemA;

    private ItemMaterial $itemB;

    private FolioMaterial $origen;

    private Camara $camara;

    private Posicion $posicion;

    private function prepararTransferencia(): void
    {
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $catalogoA = ClienteMaterial::where('codigo', 'GENERAL')->whereHas('temporada', fn ($q) => $q->where('activa', true))->firstOrFail();
        $this->clienteA = $catalogoA->cliente;
        $this->clienteA->update(['codigo_folio_materiales' => 'AA']);
        $auditoria = ['creado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id];
        $this->clienteB = Cliente::create(['codigo' => 'BB', 'nombre' => 'Cliente destino', 'codigo_folio_materiales' => 'BB', 'activo' => true, ...$auditoria]);
        $catalogoB = ClienteMaterial::create(['temporada_material_id' => $catalogoA->temporada_material_id, 'cliente_id' => $this->clienteB->id, 'codigo' => 'BB', 'nombre' => 'Cliente destino', 'activo' => true, ...$auditoria]);
        foreach (['A' => $catalogoA, 'B' => $catalogoB] as $lado => $catalogo) {
            $this->{'item'.$lado} = ItemMaterial::create(['cliente_material_id' => $catalogo->id, 'codigo' => 'FILM-TR', 'nombre' => 'Film de transferencia',
                'categoria' => 'Embalaje', 'categoria_operacional' => CategoriaOperacionalMaterial::Insumo, 'unidad_medida' => 'rollos', 'activo' => true, 'origen_sistema' => 'manual', ...$auditoria]);
        }
        $this->camara = Camara::create(['codigo' => 'MAT-CLIENTES', 'nombre' => 'Bodega de transferencias', 'contenido' => ContenidoCamara::Materiales]);
        $this->posicion = Posicion::create(['camara_id' => $this->camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $proveedor = ProveedorMaterial::create(['codigo' => 'TR-PROV', 'nombre' => 'Proveedor original', 'activo' => true, ...$auditoria]);
        $folio = Folio::create(['temporada_id' => $catalogoA->temporada->temporada_id, 'numero_folio' => 'FAA0000001', 'tipo_bulto' => TipoBulto::Material,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion, 'activo' => true, 'fecha_ingreso' => now()->subDays(40)]);
        $this->origen = FolioMaterial::create(['folio_id' => $folio->id, 'item_material_id' => $this->itemA->id, 'categoria_operacional' => CategoriaOperacionalMaterial::Insumo,
            'cantidad_inicial' => 100, 'cantidad_actual' => 100, 'cantidad_reservada' => 0, 'unidad_medida' => 'rollos', 'lote' => 'ANTIGUO-01', 'proveedor' => 'Proveedor original', 'proveedor_material_id' => $proveedor->id,
            'fecha_fabricacion' => now()->subDays(50)->toDateString(), 'fecha_vencimiento' => now()->addDays(10)->toDateString()]);
        UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $this->camara->id, 'posicion_id' => $this->posicion->id, 'ubicado_at' => now()]);
    }

    private function datosTransferencia(float $cantidad = 100): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'cliente_destino_id' => $this->clienteB->id, 'item_destino_id' => $this->itemB->id,
            'cantidad' => $cantidad, 'motivo' => 'Préstamo autorizado entre clientes.', 'documento_respaldo' => 'Acuerdo 123'];
    }

    private function rutaTransferencia(?string $folioId = null): string
    {
        return '/api/materiales/inventario/'.($folioId ?? $this->origen->folio_id).'/transferir-cliente';
    }
}
