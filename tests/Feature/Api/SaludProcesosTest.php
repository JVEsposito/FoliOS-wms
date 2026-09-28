<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\User;
use App\Services\Planificador\ServicioSaludProcesos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SaludProcesosTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_worker_marca_latido_en_cada_bucle_con_un_minimo_de_treinta_segundos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00 UTC'));
        Cache::forget('salud:worker:latido');
        Event::dispatch(new Looping('database', 'default'));
        $primero = Cache::get('salud:worker:latido');
        $this->assertSame('activo', app(ServicioSaludProcesos::class)->consultar()['worker']['estado']);

        $this->travel(29)->seconds();
        Event::dispatch(new Looping('database', 'default'));
        $this->assertSame($primero, Cache::get('salud:worker:latido'));

        $this->travel(1)->seconds();
        Event::dispatch(new Looping('database', 'default'));
        $this->assertNotSame($primero, Cache::get('salud:worker:latido'));
    }

    public function test_comando_scheduler_y_estado_detectan_latidos_vencidos(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00 UTC'));
        Cache::forget('salud:worker:latido');
        Cache::forget('salud:scheduler:latido');
        Artisan::call('salud:latido-scheduler');
        Event::dispatch(new Looping('database', 'default'));
        $this->assertNotNull(Cache::get('salud:scheduler:latido'));

        Artisan::call('sistema:estado-procesos');
        $salida = Artisan::output();
        $this->assertStringContainsString('worker: activo', $salida);
        $this->assertStringContainsString('scheduler: activo', $salida);
        $this->assertStringContainsString('arbitraje:', $salida);

        $this->travel(151)->seconds();
        Artisan::call('sistema:estado-procesos');
        $salida = Artisan::output();
        $this->assertStringContainsString('worker: detenido', $salida);
        $this->assertStringContainsString('scheduler: detenido', $salida);
    }

    public function test_salud_y_operacion_ahora_muestran_ambos_estados_sin_modificar_las_lecturas(): void
    {
        config(['planificador.mode' => 'guided']);
        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00 UTC'));
        Cache::forget('salud:worker:latido');
        Cache::forget('salud:scheduler:latido');
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($administrador, 'sanctum');

        $this->getJson('/api/administracion/planificador/salud')
            ->assertOk()
            ->assertJsonPath('data.procesos.worker.estado', 'detenido')
            ->assertJsonPath('data.procesos.scheduler.estado', 'detenido');

        Artisan::call('salud:latido-scheduler');
        Event::dispatch(new Looping('database', 'default'));
        $this->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planificador.procesos.worker.estado', 'activo')
            ->assertJsonPath('data.planificador.procesos.scheduler.estado', 'activo');

        $this->travel(151)->seconds();
        $this->getJson('/api/administracion/planificador/salud')
            ->assertOk()
            ->assertJsonPath('data.procesos.worker.estado', 'detenido')
            ->assertJsonPath('data.procesos.worker.antiguedad_segundos', 151)
            ->assertJsonPath('data.procesos.scheduler.estado', 'detenido');
        $this->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.planificador.procesos.worker.estado', 'detenido')
            ->assertJsonPath('data.planificador.procesos.scheduler.estado', 'detenido');
    }
}
