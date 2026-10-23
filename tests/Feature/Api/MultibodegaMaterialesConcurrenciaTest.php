<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\MultibodegaMaterialFixture;
use Tests\TestCase;

class MultibodegaMaterialesConcurrenciaTest extends TestCase
{
    use DatabaseMigrations, MultibodegaMaterialFixture;

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

    public function test_reservas_simultaneas_en_distintas_bodegas_conservan_la_proyeccion_global(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La prueba de bloqueos requiere MySQL.');
        }
        $this->prepararMultibodega();
        $this->repartir();
        $dir = sys_get_temp_dir().'/multibodega-concurrente-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/worker.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
$pendiente = Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
    // Ambas transacciones observan primero el mismo estado bajo REPEATABLE READ.
    Illuminate\Support\Facades\DB::table('folios_materiales')->count();
    file_put_contents($argv[4], 'ready');
    $fin = microtime(true) + 15;
    while (!file_exists($argv[5]) && microtime(true) < $fin) { usleep(20000); }
    return app(App\Services\Materiales\ServicioReservaFifoMaterial::class)
        ->reservar($argv[2], 20, static function () {}, almacenId: $argv[3]);
}, attempts: 3);
print json_encode(['pendiente' => $pendiente]);
} catch (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()); exit(1); }
PHP);
        $procesos = [];
        try {
            foreach ([$this->central->id, $this->norte['id']] as $n => $almacen) {
                $p = new Process([PHP_BINARY, $dir.'/worker.php', base_path(), $this->itemA->id, $almacen, $dir.'/ready'.$n, $dir.'/go'], base_path());
                $p->setTimeout(30);
                $p->start();
                $procesos[] = $p;
            }
            $fin = microtime(true) + 15;
            while ((! file_exists($dir.'/ready0') || ! file_exists($dir.'/ready1')) && microtime(true) < $fin) {
                usleep(20000);
            }
            $this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            file_put_contents($dir.'/go', 'go');
            foreach ($procesos as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
                $this->assertJson($p->getOutput(), $p->getOutput());
                $this->assertSame(0, json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR)['pendiente']);
            }
            $this->assertSame('20.000', $this->saldo($this->central->id)->cantidad_reservada);
            $this->assertSame('20.000', $this->saldo($this->norte['id'])->cantidad_reservada);
            $this->assertSame('40.000', $this->origen->fresh()->cantidad_reservada);
            $this->assertSame('100.000', $this->origen->fresh()->cantidad_actual);
        } finally {
            foreach ($procesos as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach (glob($dir.'/*') as $archivo) {
                unlink($archivo);
            }
            rmdir($dir);
        }
    }
}
