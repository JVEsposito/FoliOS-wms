<?php

namespace Tests\Unit;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\FailoverQueue;
use Illuminate\Queue\QueueManager;
use Mockery;
use Tests\TestCase;

class QueueConnectionsConfigurationTest extends TestCase
{
    public function test_failover_publica_por_nombre_y_no_incluye_el_worker_exclusivo(): void
    {
        $job = new \stdClass;
        $destino = Mockery::mock(Queue::class);
        $destino->shouldReceive('push')->once()->with($job)->andReturn('job-test');
        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with('database')->andReturn($destino);
        $eventos = Mockery::mock(Dispatcher::class);
        $eventos->shouldNotReceive('dispatch');

        $cola = new FailoverQueue($manager, $eventos, config('queue.connections.failover.connections'));

        $this->assertSame('job-test', $cola->push($job));
        $this->assertNotContains('integraciones', config('queue.connections.failover.connections'));
        $this->assertSame('database', config('queue.connections.integraciones.driver'));
        $this->assertSame('integraciones', config('queue.connections.integraciones.queue'));
    }
}
