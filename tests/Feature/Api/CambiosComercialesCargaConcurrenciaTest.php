<?php

namespace Tests\Feature\Api;

use App\Models\Carga;
use App\Models\ReingresoFolioCamara;
use App\Models\ReservaCargaFolio;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class CambiosComercialesCargaConcurrenciaTest extends TestCase
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

    public function test_dos_cargas_no_toman_el_mismo_pallet_de_reemplazo(): void
    {
        $contexto = $this->crearContextoFrio(3, 'CX1');
        [$primero, $segundo, $reemplazo] = $contexto['folios'];
        $cargaA = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero]);
        $cargaB = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$segundo]);
        $tokenOtro = $contexto['administrador']->createToken('oficina-otro', ['oficina'])->plainTextToken;

        $solicitudes = [
            ['POST', "/api/cargas/{$cargaA->id}/folios/{$primero->id}/cambiar", $contexto['tokenOficina'], $this->cambio($cargaA, $reemplazo->numero_folio)],
            ['POST', "/api/cargas/{$cargaB->id}/folios/{$segundo->id}/cambiar", $tokenOtro, $this->cambio($cargaB, $reemplazo->numero_folio)],
        ];
        $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));
        $estados = array_column($resultados, 'status');
        sort($estados);

        $this->assertSame([200, 422], $estados, json_encode(array_column($resultados, 'body')));
        $this->assertSame(1, ReservaCargaFolio::query()->where('folio_id', $reemplazo->id)->count());
        // La carga que ganó soltó su original; la que perdió conserva el suyo.
        $this->assertSame(1, ReservaCargaFolio::query()->whereIn('folio_id', [$primero->id, $segundo->id])->count());
    }

    public function test_cancelar_mientras_la_tablet_carga_deja_el_pallet_pendiente_de_reingreso(): void
    {
        $contexto = $this->crearContextoFrio(2, 'CX2');
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->enviarAlAnden($contexto, $carga, $primero);

        $solicitudes = [
            ['POST', "/api/cargas/{$carga->id}/cargados", $contexto['token'], [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => $primero->numero_folio,
                'generado_dispositivo_at' => now()->toAtomString(),
            ]],
            ['POST', "/api/cargas/{$carga->id}/cancelar", $contexto['tokenOficina'], [
                'version_esperada' => $carga->refresh()->version,
                'motivo' => 'Cliente anuló el pedido',
            ]],
        ];
        $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));

        // Si la lectura entra primero, la versión cambió y la cancelación responde 409;
        // si la cancelación entra primero, la lectura encuentra la carga cancelada.
        $estados = array_column($resultados, 'status');
        $this->assertNotContains(500, $estados, json_encode(array_column($resultados, 'body')));
        if ($resultados[1]['status'] === 200) {
            $this->assertSame(422, $resultados[0]['status']);
            $this->assertSame('cancelada', Carga::query()->whereKey($carga->id)->toBase()->value('estado'));
            $this->assertSame(2, ReingresoFolioCamara::query()->where('carga_id', $carga->id)->where('estado', 'pendiente')->count());
            $this->assertSame(0, ReservaCargaFolio::query()->whereIn('folio_id', [$primero->id, $segundo->id])->count());
        } else {
            $this->assertSame([200, 409], $estados);
            $this->assertSame(0, ReingresoFolioCamara::query()->count());
            $this->assertSame(2, ReservaCargaFolio::query()->whereIn('folio_id', [$primero->id, $segundo->id])->count());
        }
    }

    /** @return array<string, mixed> */
    private function cambio(Carga $carga, string $entrante): array
    {
        return [
            'version_esperada' => $carga->refresh()->version,
            'numero_folio_entrante' => $entrante,
            'motivo' => 'Cambio pedido por el cliente',
            'confirmar_diferencias' => true,
        ];
    }
}
