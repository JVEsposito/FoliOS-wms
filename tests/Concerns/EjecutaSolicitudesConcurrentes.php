<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Lanza solicitudes HTTP en procesos PHP separados, cada uno con su conexión a MySQL, y las
 * libera a la vez. Requiere DatabaseMigrations: los datos deben estar confirmados.
 */
trait EjecutaSolicitudesConcurrentes
{
    /**
     * @param  array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>  $solicitudes  [método, uri, token, payload]
     * @return array<int, array{status: int, body: mixed}>
     */
    protected function solicitudesConcurrentes(array $solicitudes): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Las solicitudes simultáneas requieren MySQL, como el servidor y CI.');
        }

        $dir = sys_get_temp_dir().'/solicitudes-concurrentes-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $worker = $dir.'/worker.php';
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::connection()->getPdo();
file_put_contents($argv[6], 'ready');
$fin = microtime(true) + 20;
while (!file_exists($argv[7]) && microtime(true) < $fin) { usleep(10000); }
$request = Illuminate\Http\Request::create($argv[3], $argv[2], server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$argv[4],
], content: $argv[5]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
print json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
PHP);
        $procesos = [];

        try {
            foreach ($solicitudes as $indice => [$metodo, $uri, $token, $payload]) {
                $proceso = new Process([
                    PHP_BINARY, $worker, base_path(), $metodo, $uri, $token, json_encode($payload),
                    "{$dir}/ready{$indice}", "{$dir}/go",
                ], base_path());
                $proceso->setTimeout(40);
                $proceso->start();
                $procesos[] = $proceso;
            }
            $fin = microtime(true) + 20;
            while (count(glob("{$dir}/ready*")) < count($solicitudes) && microtime(true) < $fin) {
                usleep(10000);
            }
            $this->assertCount(count($solicitudes), glob("{$dir}/ready*"), 'Los procesos no alcanzaron a prepararse.');
            file_put_contents("{$dir}/go", 'go');

            $resultados = [];
            foreach ($procesos as $proceso) {
                $proceso->wait();
                $this->assertTrue($proceso->isSuccessful(), $proceso->getErrorOutput().$proceso->getOutput());
                $resultados[] = json_decode($proceso->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $resultados;
        } finally {
            foreach ($procesos as $proceso) {
                if ($proceso->isRunning()) {
                    $proceso->stop();
                }
            }
            foreach (glob("{$dir}/*") as $archivo) {
                unlink($archivo);
            }
            rmdir($dir);
        }
    }
}
