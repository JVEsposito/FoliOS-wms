<?php

namespace Tests\Feature\Comex;

use App\Comex\EsquemaComex;
use App\Comex\Models\ClienteComex;
use App\Finanzas\EsquemaFinanzas;
use App\Gestion\ModulosGestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EsquemaComexTest extends TestCase
{
    use PreparaOperacionComex;
    use RefreshDatabase;

    public function test_comex_vive_en_su_propia_base(): void
    {
        $esquema = app(EsquemaComex::class);

        $this->assertSame(config('database.connections.mysql.database').'_comex', $esquema->nombreBase());
        $this->assertNotSame(DB::connection()->getDatabaseName(), $esquema->conexion()->getDatabaseName());
        $this->assertTrue($esquema->conexion()->getSchemaBuilder()->hasTable('cx_documentos'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('cx_documentos'));
    }

    public function test_finanzas_y_comex_se_registran_como_modulos_de_gestion(): void
    {
        $esquemas = array_map(fn (object $esquema): string => $esquema::class, app(ModulosGestion::class)->esquemas());

        $this->assertSame([EsquemaFinanzas::class, EsquemaComex::class], $esquemas);
    }

    public function test_no_retira_tablas_con_datos_que_el_registro_de_migraciones_no_conoce(): void
    {
        $esquema = app(EsquemaComex::class);
        ClienteComex::query()->create(['cliente_id' => fake()->uuid(), 'cliente_nombre' => 'CLIENTE DEMO', 'gestion' => 'propia']);

        $esquema->retirarHuerfanas([EsquemaComex::MIGRACION_INICIAL]);

        try {
            $esquema->retirarHuerfanas([]);
            $this->fail('Una base de Comex con datos no debe borrarse al migrar.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('La base de Comex', $error->getMessage());
            $this->assertStringContainsString('docs/COMEX.md', $error->getMessage());
        }

        $this->assertSame(1, ClienteComex::query()->count());
    }
}
