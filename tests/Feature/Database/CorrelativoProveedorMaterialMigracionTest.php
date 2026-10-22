<?php

namespace Tests\Feature\Database;

use App\Models\Cliente;
use App\Models\ClienteProveedorMaterial;
use App\Models\ProveedorMaterial;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorrelativoProveedorMaterialMigracionTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_asigna_codigos_sin_cambiar_identidades_vinculos_codigos_comerciales_o_fechas_historicas(): void
    {
        $migracion = require database_path('migrations/2026_10_22_190000_agregar_correlativo_interno_proveedores_materiales.php');
        $migracion->down();
        $proveedores = [];
        foreach (['CSF', 'PRV001', 'PRV-001'] as $indice => $codigo) {
            $id = (string) Str::uuid();
            $proveedores[$id] = $codigo;
            DB::table('proveedores_materiales')->insert([
                'id' => $id, 'codigo' => $codigo, 'nombre' => 'Proveedor '.$codigo,
                'activo' => $indice !== 1, 'codigo_externo' => 'ERP-'.$indice,
                'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-02-01 10:00:00',
            ]);
        }
        $primeroId = array_key_first($proveedores);
        ClienteProveedorMaterial::create([
            'cliente_id' => Cliente::firstOrFail()->id, 'proveedor_material_id' => $primeroId,
            'activo' => true, 'categorias' => ['Embalaje'],
        ]);

        $migracion->up();

        $this->assertDatabaseCount('proveedores_materiales', 3);
        foreach ($proveedores as $id => $codigo) {
            $this->assertDatabaseHas('proveedores_materiales', [
                'id' => $id, 'codigo' => $codigo,
                'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-02-01 10:00:00',
            ]);
        }
        $codigos = ProveedorMaterial::orderBy('codigo_interno')->pluck('codigo_interno')->all();
        $this->assertSame(['PRV-002', 'PRV-003', 'PRV-004'], $codigos);
        $this->assertSame(1, ClienteProveedorMaterial::where('proveedor_material_id', $primeroId)->count());
        $this->assertSame(3, DB::table('proveedores_materiales')->whereNotNull('codigo_externo')->count());
        $nuevo = ProveedorMaterial::create(['nombre' => 'Proveedor posterior', 'activo' => true]);
        $this->assertSame('PRV-005', $nuevo->codigo_interno);
    }
}
