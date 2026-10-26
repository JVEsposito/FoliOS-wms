<?php

namespace Tests\Feature\Database;

use App\Models\CategoriaComercialMaterial;
use App\Models\ClienteProveedorMaterial;
use App\Models\ProveedorMaterial;
use App\Services\Materiales\NombreClasificacionMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransferenciaClienteMaterialFixture;
use Tests\TestCase;

class NormalizacionCatalogoMaterialesMigracionTest extends TestCase
{
    use RefreshDatabase, TransferenciaClienteMaterialFixture;

    public function test_un_nombre_que_crece_al_convertir_a_mayusculas_rechaza_toda_la_migracion(): void
    {
        $this->prepararTransferencia();
        $this->itemA->update(['nombre' => 'Cartón minúsculo']);
        $this->itemB->update(['nombre' => str_repeat('ß', 180)]);
        $antes = DB::table('items_materiales')->orderBy('id')->get()->toJson();
        $migracion = require database_path('migrations/2026_10_25_150000_normalizar_etiquetas_catalogo_materiales.php');
        try {
            $migracion->up();
            $this->fail('Aceptó un nombre que no cabe en el campo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('exceden 180 caracteres', $e->getMessage());
        }
        $this->assertSame($antes, DB::table('items_materiales')->orderBy('id')->get()->toJson());
    }

    public function test_normaliza_catalogos_y_habilitaciones_sin_cambiar_stock_identidades_unidades_o_auditoria(): void
    {
        $this->prepararTransferencia();
        $this->itemA->update(['nombre' => '  Cartón   pequeño ñandú  ', 'categoria' => ' Embalaje ']);
        $categoria = CategoriaComercialMaterial::create(['nombre' => ' Embalaje ', 'nombre_normalizado' => NombreClasificacionMaterial::normalizar('Embalaje'), 'activo' => true]);
        $asignacion = ClienteProveedorMaterial::create(['cliente_id' => $this->clienteA->id, 'proveedor_material_id' => ProveedorMaterial::firstOrFail()->id, 'activo' => true, 'categorias' => [' Embalaje ', 'Cajas']]);
        $unidadId = $this->itemA->unidad_material_id;
        DB::table('unidades_materiales')->where('id', $unidadId)->update(['nombre' => 'Rollos']);
        $tipoId = DB::table('tipos_items_materiales')->where('codigo_base', 'insumo')->value('id');
        DB::table('tipos_items_materiales')->where('id', $tipoId)->update(['nombre' => 'Insumo']);
        $antes = [];
        foreach (['folios', 'folios_materiales', 'ubicaciones_actuales', 'alias_unidades_materiales', 'eventos_unidad_materiales'] as $tabla) {
            $antes[$tabla] = DB::table($tabla)->get()->toJson();
        }
        $itemAntes = $this->itemA->getRawOriginal();
        $migracion = require database_path('migrations/2026_10_25_150000_normalizar_etiquetas_catalogo_materiales.php');
        $migracion->up();
        $this->assertSame('CARTÓN PEQUEÑO ÑANDÚ', $this->itemA->refresh()->nombre);
        $this->assertSame('EMBALAJE', $this->itemA->categoria);
        $this->assertSame('EMBALAJE', $categoria->refresh()->nombre);
        $this->assertSame(['EMBALAJE', 'CAJAS'], $asignacion->refresh()->categorias);
        $this->assertDatabaseHas('unidades_materiales', ['id' => $unidadId, 'nombre' => 'ROLLOS', 'codigo' => 'rollos']);
        $this->assertDatabaseHas('tipos_items_materiales', ['id' => $tipoId, 'nombre' => 'INSUMO', 'codigo_base' => 'insumo']);
        $this->assertSame(array_diff_key($itemAntes, array_flip(['nombre', 'categoria'])), array_diff_key($this->itemA->getRawOriginal(), array_flip(['nombre', 'categoria'])));
        foreach ($antes as $tabla => $contenido) {
            $this->assertSame($contenido, DB::table($tabla)->get()->toJson(), $tabla);
        }
        $despues = DB::table('items_materiales')->get()->toJson();
        $migracion->up();
        $migracion->down();
        $this->assertSame($despues, DB::table('items_materiales')->get()->toJson());
    }
}
