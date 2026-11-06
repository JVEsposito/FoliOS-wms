<?php

namespace Tests\Unit;

use App\Services\Envases\ServicioGuiaEmitidaEnvases;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NumeroDocumentoSalidaNormalizadoTest extends TestCase
{
    public function test_la_migracion_normaliza_historial_y_cambios_en_ambas_tablas_sin_cambiar_el_numero_original(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.documento_salida_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('documento_salida_test');
        Schema::clearResolvedInstance('db.schema');
        try {
            foreach (['temporadas', 'clientes'] as $tabla) {
                Schema::create($tabla, fn (Blueprint $t) => $t->uuid('id')->primary());
            }
            foreach (['recepciones_romana' => 'numero_guia_salida', 'guias_despacho_envases' => 'numero_guia_despacho'] as $tabla => $campo) {
                Schema::create($tabla, function (Blueprint $t) use ($campo): void {
                    $t->id();
                    $t->uuid('temporada_id')->nullable();
                    $t->uuid('cliente_id')->nullable();
                    $t->string($campo, 100)->nullable();
                });
                DB::table($tabla)->insert([$campo => ' 002.236 ']);
            }
            $migracion = require database_path('migrations/2026_11_06_170000_coordinar_documentos_salida_envases.php');
            $migracion->up();
            $normalizador = app(ServicioGuiaEmitidaEnvases::class);
            foreach (['recepciones_romana' => 'numero_guia_salida', 'guias_despacho_envases' => 'numero_guia_despacho'] as $tabla => $campo) {
                $this->assertSame('2236', DB::table($tabla)->value('documento_salida_normalizado'));
                $this->assertSame(' 002.236 ', DB::table($tabla)->value($campo));
                foreach (['2236', '0002.236', ' GS-002 ', '0ABC', '000ABC', '000', '99999999999999999999', null] as $valor) {
                    DB::table($tabla)->where('id', 1)->update([$campo => $valor]);
                    $this->assertSame($normalizador->normalizar($valor), DB::table($tabla)->value('documento_salida_normalizado') ?: null);
                }
            }
            $migracion->down();
            $this->assertFalse(Schema::hasColumn('recepciones_romana', 'documento_salida_normalizado'));
            $this->assertFalse(Schema::hasTable('documentos_salida_envases'));
        } finally {
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('documento_salida_test');
        }
    }
}
