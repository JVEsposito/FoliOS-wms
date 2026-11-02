<?php

namespace Tests\Feature\Api;

use App\Models\Carga;
use App\Models\ReingresoFolioCamara;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class RetornoCargaConcurrenciaTest extends TestCase
{
    use DatabaseMigrations;
    use EjecutaSolicitudesConcurrentes;
    use PreparaDespachoFrigorifico;

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

    public function test_dos_oficinas_que_registran_el_mismo_retorno_no_duplican_reingresos(): void
    {
        $contexto = $this->crearContextoFrio(2, 'RT1');
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        foreach (array_reverse($contexto['folios']) as $folio) {
            $this->enviarAlAnden($contexto, $carga, $folio);
        }
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cerrar-despacho", [
            'operacion_id' => (string) Str::uuid(),
            'patente' => 'AB-CD-12',
            'conductor' => 'María Pérez',
        ])->assertOk();
        $version = $carga->refresh()->version;
        $tokenOtro = $contexto['administrador']->createToken('oficina-otro', ['oficina'])->plainTextToken;

        $solicitudes = array_map(fn (string $token): array => [
            'POST', "/api/cargas/{$carga->id}/retorno", $token, ['version_esperada' => $version, 'motivo' => 'Contenedor rechazado'],
        ], [$contexto['tokenOficina'], $tokenOtro]);
        $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));
        $estados = array_column($resultados, 'status');
        sort($estados);

        $this->assertSame([200, 409], $estados, json_encode(array_column($resultados, 'body')));
        $this->assertSame('retornada', Carga::query()->whereKey($carga->id)->toBase()->value('estado'));
        $this->assertSame(2, ReingresoFolioCamara::query()->where('carga_id', $carga->id)->count());
    }
}
