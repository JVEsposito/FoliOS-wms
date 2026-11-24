<?php

namespace Tests\Feature\Domain;

use App\Enums\EstadoTransicionOperacional;
use App\Enums\RolUsuario;
use App\Models\TransicionOperacional;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MotorTransicionesConcurrenciaTest extends TestCase
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

    public function test_uuid_independientes_y_repetidos_con_snapshot_previo_conservan_la_idempotencia(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requiere los bloqueos y snapshots de MySQL.');
        }

        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        foreach (['independientes', 'repetida', 'payload_distinto'] as $caso) {
            $uuid = '10000000-0000-4000-8000-000000000001';
            $segundoUuid = $caso === 'independientes' ? '20000000-0000-4000-8000-000000000001' : $uuid;
            $resultados = $this->ejecutarConcurrentes($usuario, $caso, [
                [$uuid, 'uno'],
                [$segundoUuid, $caso === 'payload_distinto' ? 'dos' : 'uno'],
            ]);

            $estados = array_column($resultados, 'estado');
            sort($estados);
            $this->assertSame(
                $caso === 'payload_distinto' ? ['aplicada', 'conflicto'] : ['aplicada', 'aplicada'],
                $estados,
                $caso,
            );
            if ($caso === 'independientes') {
                $this->assertSame([1, 1], array_column($resultados, 'intentos'),
                    'UUID distintos no deben provocar un deadlock y reejecutar sus transacciones.');
            }

            $transiciones = TransicionOperacional::query()->where('tipo', 'prueba.'.$caso)->get();
            $this->assertCount($caso === 'independientes' ? 2 : 1, $transiciones);
            foreach ($transiciones as $transicion) {
                $this->assertSame(EstadoTransicionOperacional::Aplicada, $transicion->estado);
                $this->assertNull($transicion->error_mensaje);
                $this->assertSame($transicion->payload['valor'], $transicion->resultado['datos']['valor']);
            }
        }
    }

    /** @param list<array{string, string}> $solicitudes */
    private function ejecutarConcurrentes(User $usuario, string $caso, array $solicitudes): array
    {
        $directorio = sys_get_temp_dir().'/transiciones-concurrentes-'.bin2hex(random_bytes(8));
        mkdir($directorio);
        $worker = $directorio.'/worker.php';
        file_put_contents($worker, <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$usuario = App\Models\User::findOrFail($argv[2]);
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$intentos = 0;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionBeginning::class,
    function ($evento) use (&$intentos): void {
        if ($evento->connection->transactionLevel() === 1) {
            $intentos++;
            // El perdedor del INSERT debe ver el registro nuevo incluso con un snapshot anterior.
            $evento->connection->table('users')->value('id');
        }
    });
$primeraCreacion = true;
App\Models\TransicionOperacional::creating(function () use (&$primeraCreacion, $argv): void {
    if (! $primeraCreacion) {
        return;
    }
    $primeraCreacion = false;
    // Ambos procesos llegan a crear la operación antes de permitir sus INSERT.
    file_put_contents($argv[6], 'listo');
    $limite = microtime(true) + 10;
    while (! file_exists($argv[7]) && microtime(true) < $limite) {
        usleep(10000);
    }
    if (! file_exists($argv[7])) {
        throw new RuntimeException('No se liberó la barrera de concurrencia.');
    }
});
try {
    $resultado = app(App\Services\Transiciones\MotorTransicionesOperacionales::class)->ejecutar(
        new App\Services\Transiciones\ComandoTransicionOperacional(
            dominio: App\Enums\DominioTransicionOperacional::Validacion,
            tipo: 'prueba.'.$argv[3], usuario: $usuario,
            payload: ['valor' => $argv[5]], operacionId: $argv[4]),
        fn (): array => ['valor' => $argv[5]]);
    print json_encode(['estado' => 'aplicada', 'intentos' => $intentos, 'resultado' => $resultado], JSON_THROW_ON_ERROR);
} catch (App\Exceptions\ConflictoOperacion $error) {
    print json_encode(['estado' => 'conflicto', 'intentos' => $intentos], JSON_THROW_ON_ERROR);
}
PHP);
        $procesos = [];
        try {
            foreach ($solicitudes as $numero => [$uuid, $valor]) {
                $proceso = new Process([PHP_BINARY, $worker, base_path(), (string) $usuario->id,
                    $caso, $uuid, $valor, $directorio.'/listo'.$numero, $directorio.'/continuar'], base_path());
                $proceso->setTimeout(25);
                $proceso->start();
                $procesos[] = $proceso;
            }
            $limite = microtime(true) + 10;
            while ((! file_exists($directorio.'/listo0') || ! file_exists($directorio.'/listo1')) && microtime(true) < $limite) {
                usleep(10000);
            }
            foreach ($procesos as $numero => $proceso) {
                $this->assertFileExists($directorio.'/listo'.$numero, $proceso->getErrorOutput().$proceso->getOutput());
            }
            file_put_contents($directorio.'/continuar', 'continuar');

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
            foreach (glob($directorio.'/*') as $archivo) {
                unlink($archivo);
            }
            rmdir($directorio);
        }
    }
}
