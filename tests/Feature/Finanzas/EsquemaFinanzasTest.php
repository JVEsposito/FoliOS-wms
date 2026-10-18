<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\Lectura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class EsquemaFinanzasTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    public function test_finanzas_vive_en_su_propia_base(): void
    {
        $esquema = app(EsquemaFinanzas::class);

        $this->assertSame(config('database.connections.mysql.database').'_finanzas', $esquema->nombreBase());
        $this->assertNotSame(DB::connection()->getDatabaseName(), $esquema->conexion()->getDatabaseName());
        $this->assertTrue($esquema->conexion()->getSchemaBuilder()->hasTable('fin_hechos'));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('fin_hechos'));
    }

    public function test_no_retira_tablas_con_datos_que_el_registro_de_migraciones_no_conoce(): void
    {
        $esquema = app(EsquemaFinanzas::class);
        Lectura::query()->create(['origen' => Lectura::ORIGEN_CONSOLA, 'estado' => Lectura::ESTADO_COMPLETADA, 'iniciada_at' => now()]);

        // Con sus migraciones registradas no hay nada que revisar.
        $esquema->retirarHuerfanas([EsquemaFinanzas::MIGRACION_INICIAL]);

        try {
            $esquema->retirarHuerfanas([]);
            $this->fail('Una base de Finanzas con datos no debe borrarse al migrar.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('tiene datos, pero la base operativa no registra sus migraciones', $error->getMessage());
        }

        $this->assertSame(1, Lectura::query()->count());
    }
}
