<?php

namespace Tests\Feature\Finanzas;

use App\Finanzas\EsquemaFinanzas;
use App\Finanzas\Models\Lectura;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Cada prueba usa bases desechables propias; nunca reinicia las de la suite. */
class MigracionesFinanzasTest extends TestCase
{
    private array $bases = [];

    private string $operacion;

    private string $conexionOriginal;

    protected function setUp(): void
    {
        parent::setUp();
        // Laravel omite estos eventos en PHPUnit; activarlos reproduce el CLI real.
        app(Kernel::class)->rerouteSymfonyCommandEvents();
        $this->conexionOriginal = config('database.default');
        $config = DB::connection()->getConfig();
        unset($config['name']);
        $this->operacion = $this->base('fin_prueba_op_');
        $finanzas = $this->base('fin_prueba_fin_');
        config([
            'database.default' => 'operacion_finanzas_prueba',
            'finanzas.conexion_operacion' => 'operacion_finanzas_prueba',
            'database.connections.operacion_finanzas_prueba' => [...$config, 'url' => null, 'database' => $this->operacion],
            'database.connections.finanzas' => [...$config, 'url' => null, 'database' => $finanzas],
        ]);
        DB::purge('finanzas');
        DB::setDefaultConnection('operacion_finanzas_prueba');
        $this->assertSame(0, $this->comando('migrate', ['--path' => $this->rutas(), '--force' => true]));
        DB::connection()->getSchemaBuilder()->create('control_operacion', fn ($tabla) => $tabla->integer('valor'));
        DB::table('control_operacion')->insert(['valor' => 230]);
        Lectura::query()->create(['origen' => 'consola', 'estado' => 'completada', 'iniciada_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        DB::setDefaultConnection($this->conexionOriginal);
        DB::purge('finanzas');
        DB::purge('operacion_finanzas_prueba');
        foreach ($this->bases as $base) {
            DB::connection($this->conexionOriginal)->statement("DROP DATABASE IF EXISTS `{$base}`");
        }
        parent::tearDown();
    }

    public function test_cancelar_fresh_y_wipe_en_produccion_conserva_las_dos_bases(): void
    {
        $this->app['env'] = 'production';
        foreach (['migrate:fresh', 'db:wipe'] as $comando) {
            $this->assertSame(1, $this->comando($comando));
            $this->assertSame(230, DB::table('control_operacion')->value('valor'));
            $this->assertSame(1, Lectura::query()->count());
        }

        // Una migración posterior en el mismo proceso no hereda la solicitud cancelada.
        $this->app['env'] = 'testing';
        app('migrator')->getRepository()->delete((object) ['migration' => basename($this->rutas()[2], '.php')]);
        DB::connection('finanzas')->getSchemaBuilder()->table('fin_cursores', fn ($tabla) => $tabla->dropColumn('conciliada_at'));
        $this->assertSame(0, $this->comando('migrate', ['--path' => $this->rutas(), '--force' => true]));
        $this->assertSame(1, Lectura::query()->count());
        $this->assertSame(230, DB::table('control_operacion')->value('valor'));
    }

    public function test_fresh_confirmado_recrea_finanzas_despues_del_reinicio_operativo(): void
    {
        $this->assertSame(0, $this->comando('migrate:fresh', ['--path' => $this->rutas(), '--force' => true]));
        $this->assertFalse(DB::connection()->getSchemaBuilder()->hasTable('control_operacion'));
        $this->assertSame(0, Lectura::query()->count());
        $this->assertTrue(DB::connection('finanzas')->getSchemaBuilder()->hasColumn('fin_cursores', 'conciliada_at'));
    }

    public function test_wipe_suelto_conserva_finanzas_y_migrate_se_detiene_sin_borrarla(): void
    {
        $this->assertSame(0, $this->comando('db:wipe', ['--force' => true]));
        $this->assertSame(1, Lectura::query()->count());
        $this->assertSame(1, $this->comando('migrate', ['--path' => $this->rutas(), '--force' => true]));
        $this->assertSame(1, Lectura::query()->count());
    }

    public function test_listado_de_tablas_no_confunde_otra_copia_en_el_mismo_servidor(): void
    {
        $otra = $this->base('fin_prueba_ajena_');
        DB::statement("CREATE TABLE `{$otra}`.fin_solo_en_otra_base (valor INT)");
        DB::statement("INSERT INTO `{$otra}`.fin_solo_en_otra_base VALUES (17)");

        DB::connection('finanzas')->table('fin_lecturas')->delete();
        app(EsquemaFinanzas::class)->retirarHuerfanas([]);
        $this->assertSame(17, DB::table("{$otra}.fin_solo_en_otra_base")->value('valor'));
    }

    public function test_migracion_repara_tablas_ausentes_aunque_figuren_como_migradas(): void
    {
        app(EsquemaFinanzas::class)->vaciar();
        app('migrator')->getRepository()->delete((object) ['migration' => basename($this->rutas()[2], '.php')]);
        $this->assertSame(0, $this->comando('migrate', ['--path' => $this->rutas(), '--force' => true]));
        $this->assertSame(0, Lectura::query()->count());
        $this->assertTrue(DB::connection('finanzas')->getSchemaBuilder()->hasColumn('fin_cursores', 'conciliada_at'));
        $this->assertSame(230, DB::table('control_operacion')->value('valor'));
    }

    public function test_finanzas_url_crea_la_base_indicada_sin_seleccionarla_antes(): void
    {
        $nombre = 'fin_prueba_url_'.Str::lower(Str::random(12));
        $this->bases[] = $nombre;
        $config = DB::connection()->getConfig();
        $url = 'mysql://'.rawurlencode($config['username']).':'.rawurlencode($config['password']).'@'.$config['host'].':'.$config['port'].'/'.$nombre;
        config(['database.connections.finanzas.url' => $url, 'database.connections.finanzas.database' => 'nombre_ignorado']);
        DB::purge('finanzas');
        $esquema = app(EsquemaFinanzas::class);
        $esquema->asegurarBase();
        $this->assertSame($nombre, $esquema->nombreBase());
        $this->assertSame($nombre, $esquema->conexion()->scalar('SELECT DATABASE()'));
    }

    public function test_esquema_parcial_se_detiene_y_conserva_los_datos_restantes(): void
    {
        DB::connection('finanzas')->getSchemaBuilder()->drop('fin_cursores');
        app('migrator')->getRepository()->delete((object) ['migration' => basename($this->rutas()[2], '.php')]);
        $this->assertSame(1, $this->comando('migrate', ['--path' => $this->rutas(), '--force' => true]));
        $this->assertSame(1, Lectura::query()->count());
        $this->assertSame(230, DB::table('control_operacion')->value('valor'));
    }

    public function test_fresh_de_otra_conexion_no_reinicia_finanzas(): void
    {
        $otra = $this->base('fin_prueba_otro_op_');
        $config = DB::connection()->getConfig();
        unset($config['name']);
        config(['database.connections.otra_operacion_finanzas' => [...$config, 'url' => null, 'database' => $otra]]);
        $registro = new DatabaseMigrationRepository(app('db'), 'migrations');
        $registro->setSource('otra_operacion_finanzas');
        $registro->createRepository();
        DB::connection('otra_operacion_finanzas')->getSchemaBuilder()->create('control_ajeno', fn ($tabla) => $tabla->integer('valor'));
        $directorio = storage_path('framework/testing/finanzas_'.Str::random(12));
        mkdir($directorio, 0777, true);
        $archivo = $directorio.'/2026_10_18_000000_control.php';
        file_put_contents($archivo, <<<'PHP'
            <?php
            return new class extends Illuminate\Database\Migrations\Migration {
                public function up(): void {
                    Illuminate\Support\Facades\Schema::create('control_nuevo', fn ($tabla) => $tabla->integer('valor'));
                }
            };
            PHP);
        try {
            $this->assertSame(0, $this->comando('migrate:fresh', ['--database' => 'otra_operacion_finanzas', '--path' => [$directorio], '--realpath' => true, '--force' => true]));
            $this->assertFalse(DB::connection('otra_operacion_finanzas')->getSchemaBuilder()->hasTable('control_ajeno'));
            $this->assertTrue(DB::connection('otra_operacion_finanzas')->getSchemaBuilder()->hasTable('control_nuevo'));
            $this->assertSame(1, Lectura::query()->count());
            $this->assertSame(230, DB::table('control_operacion')->value('valor'));
        } finally {
            unlink($archivo);
            rmdir($directorio);
            DB::purge('otra_operacion_finanzas');
        }
    }

    private function base(string $prefijo): string
    {
        $base = $prefijo.Str::lower(Str::random(12));
        DB::connection($this->conexionOriginal)->statement("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->bases[] = $base;

        return $base;
    }

    private function rutas(): array
    {
        return [
            'database/migrations/2026_10_17_100000_crear_base_finanzas.php',
            'database/migrations/2026_10_17_100100_crear_captura_hechos_finanzas.php',
            'database/migrations/2026_10_18_100000_reparar_esquema_y_conciliar_por_fuente_finanzas.php',
        ];
    }

    private function comando(string $comando, array $opciones = []): int
    {
        $entrada = new ArrayInput(['command' => $comando, ...$opciones]);
        $entrada->setInteractive(false);

        return app(Kernel::class)->handle($entrada, new BufferedOutput);
    }
}
