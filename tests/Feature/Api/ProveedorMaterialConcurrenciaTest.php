<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\ProveedorMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProveedorMaterialConcurrenciaTest extends TestCase
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

    public function test_dos_altas_simultaneas_reciben_correlativos_diferentes(): void
    {
        $resultados = $this->altasConcurrentes([[], []]);
        $codigos = [];
        foreach ($resultados as $resultado) {
            $this->assertSame(201, $resultado['status'], json_encode($resultado));
            $codigos[] = $resultado['body']['data']['codigo_interno'];
        }
        sort($codigos);
        $this->assertSame(['PRV-001', 'PRV-002'], $codigos);
        $this->assertDatabaseCount('proveedores_materiales', 2);
    }

    public function test_dos_altas_simultaneas_no_duplican_un_codigo_comercial_equivalente(): void
    {
        $resultados = $this->altasConcurrentes([['codigo' => 'CSF-01'], ['codigo' => 'csf01']]);
        $estados = array_column($resultados, 'status');
        sort($estados);
        $this->assertSame([201, 422], $estados, json_encode($resultados));
        $this->assertDatabaseCount('proveedores_materiales', 1);
        $this->assertSame('PRV-001', ProveedorMaterial::firstOrFail()->codigo_interno);
    }

    private function altasConcurrentes(array $cambios): array
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La prueba de altas simultáneas requiere MySQL, como el servidor y CI.');
        }
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $token = $admin->createToken('proveedores-concurrentes', ['oficina'])->plainTextToken;
        $cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        ItemMaterial::create([
            'cliente_material_id' => $cliente->id, 'codigo' => 'CAJA-CONCURRENTE', 'nombre' => 'Caja',
            'categoria' => 'Embalaje', 'categoria_operacional' => 'material_mp',
            'unidad_medida' => 'unidades', 'activo' => true,
            'creado_por_user_id' => $admin->id, 'actualizado_por_user_id' => $admin->id,
        ]);
        $dir = sys_get_temp_dir().'/proveedores-concurrentes-'.bin2hex(random_bytes(8));
        mkdir($dir);
        $worker = $dir.'/worker.php';
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::connection()->getPdo();
file_put_contents($argv[4], 'ready');
$fin = microtime(true) + 15;
while (!file_exists($argv[5]) && microtime(true) < $fin) { usleep(20000); }
$request = Illuminate\Http\Request::create('/api/administracion/materiales/proveedores', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$argv[2],
], content: $argv[3]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
print json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
$kernel->terminate($request, $response);
PHP);
        $procesos = [];

        try {
            foreach ($cambios as $indice => $datos) {
                $payload = [
                    'nombre' => 'Proveedor concurrente '.$indice, 'activo' => true,
                    'cliente_ids' => [$cliente->cliente_id],
                    'categorias' => [['cliente_id' => $cliente->cliente_id, 'categoria' => 'Embalaje']],
                    ...$datos,
                ];
                $proceso = new Process([
                    PHP_BINARY, $worker, base_path(), $token, json_encode($payload), $dir.'/ready'.$indice, $dir.'/go',
                ], base_path());
                $proceso->setTimeout(30);
                $proceso->start();
                $procesos[] = $proceso;
            }
            $fin = microtime(true) + 15;
            while ((! file_exists($dir.'/ready0') || ! file_exists($dir.'/ready1')) && microtime(true) < $fin) {
                usleep(20000);
            }
            $this->assertFileExists($dir.'/ready0');
            $this->assertFileExists($dir.'/ready1');
            file_put_contents($dir.'/go', 'go');
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
            foreach (glob($dir.'/*') as $archivo) {
                unlink($archivo);
            }
            rmdir($dir);
        }
    }
}
