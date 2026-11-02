<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Dispositivo;
use App\Models\EventoCarga;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class PalletsCargadosConcurrenciaTest extends TestCase
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

    public function test_dos_tablets_que_leen_el_mismo_pallet_lo_registran_una_sola_vez(): void
    {
        $contexto = $this->crearContextoFrio(1, 'CC1');
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);
        $segundaTablet = $contexto['operador']
            ->crearTokenParaDispositivo(Dispositivo::create(['codigo' => 'TABLET-CC1-B', 'nombre' => 'Tablet andén B']), 'tablet-cc1-b')
            ->plainTextToken;

        $solicitudes = array_map(fn (string $token): array => [
            'POST', "/api/cargas/{$carga->id}/cargados", $token, $this->lectura($folio->numero_folio),
        ], [$contexto['token'], $segundaTablet]);
        $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));
        $estados = array_column($resultados, 'status');
        sort($estados);

        $this->assertSame([200, 422], $estados);
        $this->assertSame(1, EventoCarga::query()->where('carga_id', $carga->id)->where('tipo', 'folio_cargado')->count());
        $this->assertSame('cargado', CargaFolio::query()->where('folio_id', $folio->id)->toBase()->value('estado'));
    }

    public function test_el_cierre_y_una_lectura_simultanea_no_dejan_pallets_reservados_ni_bloqueos(): void
    {
        $contexto = $this->crearContextoFrio(3, 'CC2');
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        foreach (array_reverse($contexto['folios']) as $folio) {
            $this->enviarAlAnden($contexto, $carga, $folio);
        }
        $tokenOficina = $contexto['despachador']->createToken('oficina-cierre', ['oficina'])->plainTextToken;
        $this->assertSame(RolUsuario::Despachador, $contexto['despachador']->rol);

        $solicitudes = [
            ['POST', "/api/cargas/{$carga->id}/cargados", $contexto['token'], $this->lectura($contexto['folios'][0]->numero_folio)],
            ['POST', "/api/cargas/{$carga->id}/cargados", $contexto['token'], $this->lectura($contexto['folios'][1]->numero_folio)],
            ['POST', "/api/cargas/{$carga->id}/cerrar-despacho", $tokenOficina, [
                'operacion_id' => (string) Str::uuid(), 'patente' => 'AB-CD-12', 'conductor' => 'María Pérez',
            ]],
        ];
        $resultados = $this->reintentarConcurrenciaTemporal($solicitudes, $this->solicitudesConcurrentes($solicitudes));

        // Cada lectura entra antes del cierre (200) o lo encuentra cerrado (422); nunca un 500.
        foreach (array_slice($resultados, 0, 2) as $resultado) {
            $this->assertContains($resultado['status'], [200, 422], json_encode($resultado['body']));
        }
        $this->assertSame(200, $resultados[2]['status'], json_encode($resultados[2]['body']));
        $this->assertSame('cerrada', Carga::query()->whereKey($carga->id)->toBase()->value('estado'));
        $this->assertSame(['despachado'], CargaFolio::query()->where('carga_id', $carga->id)->toBase()->distinct()->pluck('estado')->all());
        $this->assertDatabaseCount('reservas_carga_folio', 0);
    }

    /** @return array<string, string> */
    private function lectura(string $numero): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $numero,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];
    }
}
