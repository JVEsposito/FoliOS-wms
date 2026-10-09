<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\TransferenciaClienteMaterialFixture;
use Tests\TestCase;

class TransferenciaClienteMaterialConcurrenciaTest extends TestCase
{
    use DatabaseMigrations, TransferenciaClienteMaterialFixture;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // Restaurar el esquema completo sin ejecutar down() históricos: MySQL reutiliza índices para sus FK.
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_dos_transacciones_concurrentes_no_transfieren_mas_que_el_disponible(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La prueba de bloqueos usa MySQL como CI.');
        }
        $this->prepararTransferencia();
        $dir = sys_get_temp_dir().'/transferencia-concurrente-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $worker = $dir.'/worker.php';
        $codigo = <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::connection()->getPdo();
file_put_contents($argv[5], 'ready');
$fin = microtime(true) + 15;
while (!file_exists($argv[6]) && microtime(true) < $fin) { usleep(20000); }
try {
    $t = app(App\Services\Materiales\ServicioTransferenciaClienteMaterial::class)->transferir(
        App\Models\FolioMaterial::findOrFail($argv[2]), json_decode($argv[3], true), App\Models\User::findOrFail($argv[4]));
    print json_encode(['status' => 'ok', 'id' => $t->id]);
} catch (DomainException $e) { print json_encode(['status' => 'rechazada', 'message' => $e->getMessage()]); }
PHP;
        file_put_contents($worker, $codigo);
        $procesos = [];
        try {
            foreach ([1, 2] as $n) {
                $p = new Process([PHP_BINARY, $worker, base_path(), $this->origen->folio_id, json_encode($this->datosTransferencia(60)), (string) $this->admin->id, $dir.'/ready'.$n, $dir.'/go'], base_path());
                $p->setTimeout(30);
                $p->start();
                $procesos[] = $p;
            }
            $fin = microtime(true) + 15;
            while ((! file_exists($dir.'/ready1') || ! file_exists($dir.'/ready2')) && microtime(true) < $fin) {
                usleep(20000);
            }
            $this->assertFileExists($dir.'/ready1');
            $this->assertFileExists($dir.'/ready2');
            file_put_contents($dir.'/go', 'go');
            $estados = [];
            foreach ($procesos as $p) {
                $p->wait();
                $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
                $estados[] = json_decode($p->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($estados);
            $this->assertSame(['ok', 'rechazada'], $estados);
            $this->assertSame('40.000', $this->origen->fresh()->cantidad_actual);
            $this->assertDatabaseCount('transferencias_clientes_materiales', 1);
            $this->assertDatabaseCount('movimientos_inventario_materiales', 2);
            $this->assertDatabaseMissing('saldos_materiales_almacenes', ['cantidad_actual' => -20]);
        } finally {
            foreach ($procesos as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach (glob($dir.'/*') as $archivo) {
                unlink($archivo);
            } rmdir($dir);
        }
    }
}
