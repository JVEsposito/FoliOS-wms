<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\TestCase;

class ComunicacionesConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;
    use EjecutaSolicitudesConcurrentes;

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

    public function test_dos_equipos_no_duplican_el_privado_ni_un_mismo_chat_o_correo(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('La concurrencia se verifica en MySQL.');
        }
        $a = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $b = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $tokenA = $a->createToken('equipo-a', ['oficina'])->plainTextToken;
        $tokenB = $b->createToken('equipo-b', ['oficina'])->plainTextToken;
        $ruta = '/api/comunicaciones/chat/privados';
        $privados = $this->solicitudesConcurrentes([
            ['POST', $ruta, $tokenA, ['usuario_id' => $b->id]], ['POST', $ruta, $tokenB, ['usuario_id' => $a->id]],
        ]);
        $this->assertSame([200, 200], array_column($privados, 'status'), json_encode($privados));
        $id = $privados[0]['body']['data']['id'];
        $this->assertSame($id, $privados[1]['body']['data']['id']);
        $this->assertDatabaseCount('com_participantes', 2);
        $payload = ['texto' => 'Un solo mensaje', 'operacion_id' => (string) Str::uuid()];
        $solicitud = ['POST', "/api/comunicaciones/chat/conversaciones/{$id}/mensajes", $tokenA, $payload];
        $mensajes = $this->solicitudesConcurrentes([$solicitud, $solicitud]);
        $this->assertSame([200, 200], array_column($mensajes, 'status'), json_encode($mensajes));
        $this->assertSame($mensajes[0]['body']['data']['id'], $mensajes[1]['body']['data']['id']);
        $this->assertDatabaseCount('com_mensajes', 1);
        $correo = ['POST', '/api/comunicaciones/correos', $tokenA, ['destinatarios' => [$b->id], 'asunto' => 'Una entrega',
            'texto' => 'Desde dos equipos', 'operacion_id' => (string) Str::uuid()]];
        $correos = $this->solicitudesConcurrentes([$correo, $correo]);
        $this->assertSame([200, 200], array_column($correos, 'status'), json_encode($correos));
        $this->assertSame($correos[0]['body']['data']['id'], $correos[1]['body']['data']['id']);
        $this->assertDatabaseCount('com_correos', 1);
        $this->assertDatabaseCount('com_destinatarios', 1);
    }
}
