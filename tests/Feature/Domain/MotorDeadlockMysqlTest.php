<?php

namespace Tests\Feature\Domain;

use App\Enums\DominioTransicionOperacional;
use App\Enums\RolUsuario;
use App\Models\Folio;
use App\Models\Temporada;
use App\Models\TransicionOperacional;
use App\Models\User;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MotorDeadlockMysqlTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_el_reintento_exterior_tras_deadlock_real_no_encuentra_un_uuid_envenenado(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requiere el rollback completo de MySQL ante un deadlock.');
        }
        config(['planificador.mode' => 'off', 'planificador.generacion_automatica' => false]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $crear = fn (string $numero) => Folio::create(['temporada_id' => $temporada->id,
            'numero_folio' => $numero, 'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()]);
        $a = $crear('DEADLOCK-A');
        $b = $crear('DEADLOCK-B');
        // La otra transacción tiene mayor peso: MySQL elige como víctima al comando probado.
        $pesos = [];
        foreach (range(1, 200) as $n) {
            $pesos[] = ['id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
                'numero_folio' => 'DEADLOCK-PESO-'.$n, 'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()];
        }
        DB::table('folios')->insert($pesos);
        $dir = sys_get_temp_dir().'/folios-deadlock-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $worker = $dir.'/worker.php';
        file_put_contents($dir.'/pesos.json', json_encode(array_column($pesos, 'id')));
        file_put_contents($worker, <<<'WORKER'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    Illuminate\Support\Facades\DB::transaction(function () use ($argv) {
        Illuminate\Support\Facades\DB::table('folios')->where('id', $argv[3])->lockForUpdate()->first();
        foreach (json_decode(file_get_contents($argv[4].'/pesos.json'), true) as $id) {
            Illuminate\Support\Facades\DB::table('folios')->where('id', $id)->update(['marca' => 'peso']);
        }
        file_put_contents($argv[4].'/ready', 'ready');
        $fin = microtime(true) + 15;
        while (!file_exists($argv[4].'/go') && microtime(true) < $fin) usleep(10000);
        if (!file_exists($argv[4].'/go')) throw new RuntimeException('No se inició el comando.');
        Illuminate\Support\Facades\DB::table('folios')->where('id', $argv[2])->lockForUpdate()->first();
    });
    print 'ok';
} catch (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()); exit(1); }
WORKER);
        $process = new Process([PHP_BINARY, $worker, base_path(), $a->id, $b->id, $dir], base_path());
        $process->setTimeout(30);
        $process->start();
        try {
            $fin = microtime(true) + 15;
            while (! file_exists($dir.'/ready') && microtime(true) < $fin) {
                usleep(10000);
            }
            $this->assertFileExists($dir.'/ready', $process->getErrorOutput());
            $uuid = (string) Str::uuid();
            $intentos = 0;
            $comando = new ComandoTransicionOperacional(dominio: DominioTransicionOperacional::Estiba,
                tipo: 'prueba.deadlock', usuario: $usuario, payload: ['folio_id' => $a->id], operacionId: $uuid);
            DB::transaction(function () use ($comando, $a, $b, $dir, &$intentos): void {
                app(MotorTransicionesOperacionales::class)->ejecutar($comando, function () use ($a, $b, $dir, &$intentos): Folio {
                    $intentos++;
                    $folio = Folio::query()->lockForUpdate()->findOrFail($a->id);
                    $folio->update(['marca' => 'confirmado']);
                    file_put_contents($dir.'/go', 'go');
                    DB::table('folios')->where('id', $b->id)->lockForUpdate()->first();

                    return $folio;
                });
            }, attempts: 3);
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame(2, $intentos, 'El comando completo debe reintentarse tras el deadlock.');
            $this->assertSame('confirmado', $a->refresh()->marca);
            $transicion = TransicionOperacional::where('operacion_id', $uuid)->sole();
            $this->assertSame('aplicada', $transicion->estado->value);
            $this->assertSame(1, $transicion->cantidad_cambios);
            $this->assertSame(1, $transicion->cambios()->count());
            $this->assertDatabaseCount('transiciones_operacionales', 1);
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
