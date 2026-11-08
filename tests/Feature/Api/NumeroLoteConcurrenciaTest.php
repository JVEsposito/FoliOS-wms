<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\LoteMateriaPrima;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;

class NumeroLoteConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;
    use EjecutaSolicitudesConcurrentes;
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;

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

    public function test_dos_clientes_no_crean_ni_corrigen_al_mismo_numero_simultaneamente(): void
    {
        $a = $this->loteMpEnCamara('CA', ['numero_lote' => '10056'], prepararSinLote: true);
        $b = $this->loteMpEnCamara('CB', ['numero_lote' => '10056'], prepararSinLote: true);
        $token = User::factory()->create(['rol' => RolUsuario::Administrador])->createToken('concurrencia-lotes', ['oficina'])->plainTextToken;
        $respuestas = $this->solicitudesConcurrentes([
            ['POST', '/api/materia-prima/lotes', $token, $a['payload_lote']],
            ['POST', '/api/materia-prima/lotes', $token, $b['payload_lote']],
        ]);
        $estados = array_column($respuestas, 'status');
        sort($estados);
        $this->assertSame([201, 422], $estados, json_encode($respuestas));
        $this->assertSame(1, LoteMateriaPrima::where('numero_lote', '10056')->count());

        $ganador = $respuestas[0]['status'] === 201 ? $respuestas[0]['body']['data'] : $respuestas[1]['body']['data'];
        $pendiente = $respuestas[0]['status'] === 201 ? $b['payload_lote'] : $a['payload_lote'];
        $otro = $this->withToken($token)->postJson('/api/materia-prima/lotes', [...$pendiente, 'numero_lote' => '10057'])->assertCreated()->json('data');
        $corregir = fn ($lote): array => ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $lote['version'],
            'numero_lote' => '10060', 'motivo' => 'Corrección simultánea de números de lote.'];
        $respuestas = $this->solicitudesConcurrentes([
            ['PUT', "/api/materia-prima/lotes/{$ganador['id']}/corregir-numero", $token, $corregir($ganador)],
            ['PUT', "/api/materia-prima/lotes/{$otro['id']}/corregir-numero", $token, $corregir($otro)],
        ]);
        $estados = array_column($respuestas, 'status');
        sort($estados);
        $this->assertSame([200, 422], $estados, json_encode($respuestas));
        $this->assertSame(1, LoteMateriaPrima::where('numero_lote', '10060')->count());
    }
}
