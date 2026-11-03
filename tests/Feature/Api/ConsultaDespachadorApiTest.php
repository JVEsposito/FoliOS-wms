<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCamara;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoPosicion;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\User;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultaDespachadorApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_despachador_consulta_camaras_sin_administrarlas(): void
    {
        $despachador = $this->despachador();

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/camaras')
            ->assertOk();

        $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/configuracion/camaras', [])
            ->assertForbidden();
    }

    public function test_consulta_solo_folios_disponibles_ubicados_y_sin_carga(): void
    {
        $despachador = $this->despachador();
        $folio = $this->crearFolioUbicado('FOLIO-DISPONIBLE-GUI');

        Folio::create([
            'numero_folio' => 'FOLIO-SIN-UBICACION-GUI',
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::Disponible,
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', $folio->numero_folio)
            ->assertJsonPath('data.0.ubicacion.camara.codigo', 'CAM-01')
            ->assertJsonPath('data.0.ubicacion.posicion.etiqueta', 'B01-P01-N1')
            ->assertJsonPath('meta.total', 1);

        $cargaId = $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/cargas', [])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/cargas/{$cargaId}/folios", [
                'folios' => [$folio->numero_folio],
                'version_esperada' => 1,
            ])
            ->assertOk();

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_busca_folios_disponibles_por_datos_descriptivos_y_ubicacion(): void
    {
        $despachador = $this->despachador();
        $folio = $this->crearFolioUbicado('FOLIO-BUSQUEDA-001');
        $folio->update([
            'variedad' => 'Santina',
            'calibre' => '2J',
            'marca' => 'Marca Andina',
            'exportadora' => 'Exportadora Los Olmos',
        ]);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles?q=Los%20Olmos&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', 'FOLIO-BUSQUEDA-001')
            ->assertJsonPath('data.0.variedad', 'Santina')
            ->assertJsonPath('data.0.calibre', '2J')
            ->assertJsonPath('data.0.marca', 'Marca Andina')
            ->assertJsonPath('data.0.exportadora', 'Exportadora Los Olmos')
            ->assertJsonPath('meta.per_page', 10);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles?q=B01-P01-N1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles?q=NO-EXISTE')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_excluye_folios_en_posiciones_o_camaras_inactivas(): void
    {
        $despachador = $this->despachador();
        $folio = $this->crearFolioUbicado('FOLIO-UBICACION-INACTIVA');
        $ubicacion = $folio->ubicacionActual()->with('posicion.camara')->firstOrFail();

        $ubicacion->posicion->update(['estado' => EstadoPosicion::FueraDeServicio]);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $ubicacion->posicion->update(['estado' => EstadoPosicion::Activa]);
        $ubicacion->posicion->camara->update(['estado' => EstadoCamara::Inactiva]);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/folios-disponibles')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pagina_y_busca_el_catalogo_de_ordenes_en_el_servidor(): void
    {
        $despachador = $this->despachador();

        foreach (range(1, 12) as $numero) {
            $this->actingAs($despachador, 'sanctum')
                ->postJson('/api/cargas', [
                    'numero_orden_externa' => $numero === 7
                        ? 'ORDEN-ESPECIAL-007'
                        : sprintf('ORDEN-%03d', $numero),
                ])
                ->assertCreated();
        }

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas?q=ORDEN-ESPECIAL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_orden_externa', 'ORDEN-ESPECIAL-007')
            ->assertJsonPath('meta.total', 1);
    }

    private function despachador(): User
    {
        return User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);
    }

    private function crearFolioUbicado(string $numeroFolio): Folio
    {
        $operador = User::factory()->create([
            'rol' => RolUsuario::CamareroFrio,
            'activo' => true,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'TABLET-'.Str::upper(Str::random(6)),
            'nombre' => 'Tablet de prueba',
        ]);
        $camara = Camara::create([
            'codigo' => 'CAM-01',
            'nombre' => 'Cámara 01',
            'cantidad_bandas' => 1,
            'posiciones_por_banda' => 1,
            'cantidad_niveles' => 1,
        ]);
        $posicion = Posicion::create([
            'camara_id' => $camara->id,
            'banda' => 1,
            'posicion' => 1,
            'nivel' => 1,
            'etiqueta' => 'B01-P01-N1',
        ]);
        $sesion = app(ServicioSesionEstiba::class)
            ->abrir($camara, $operador, $dispositivo);
        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $numeroFolio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $operador,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
            permitirFolioNuevo: true,
        );

        return $movimiento->folio;
    }
}
