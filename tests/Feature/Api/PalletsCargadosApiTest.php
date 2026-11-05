<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\CargaFolio;
use App\Models\User;
use App\Services\Existencias\ServicioExistencias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class PalletsCargadosApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public function test_en_anden_no_es_cargado_y_el_camarero_registra_cada_pallet_al_subirlo(): void
    {
        $contexto = $this->crearContextoFrio(3);
        [$primero, $segundo, $tercero] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        $this->enviarAlAnden($contexto, $carga, $tercero);
        $this->enviarAlAnden($contexto, $carga, $segundo);

        // Un pallet que sigue en cámara no puede subir al camión.
        $this->registrarCargado($contexto, $carga->id, $primero->numero_folio)
            ->assertUnprocessable()
            ->assertJsonPath('message', "El folio {$primero->numero_folio} todavía no sale de la cámara: envíalo al andén antes de cargarlo.");

        $operacion = (string) Str::uuid();
        // El reenvío lleva la misma hora del dispositivo: en un CI lento, now() puede cambiar de segundo entre ambos.
        $generadoAt = now()->toAtomString();
        $respuesta = $this->registrarCargado($contexto, $carga->id, strtolower($segundo->numero_folio), $operacion, $generadoAt)
            ->assertOk()
            ->assertJsonPath('data.progreso.en_anden', 2)
            ->assertJsonPath('data.progreso.cargados', 1)
            ->assertJsonPath('data.progreso.por_cargar', 2);
        $folio = collect($respuesta->json('data.folios'))->firstWhere('numero_folio', $segundo->numero_folio);
        $this->assertSame('cargado', $folio['estado_carga']);
        $this->assertNotNull($folio['cargado_at']);

        // La misma lectura reenviada por la tablet no duplica el evento.
        $this->registrarCargado($contexto, $carga->id, strtolower($segundo->numero_folio), $operacion, $generadoAt)->assertOk();
        $this->assertSame(1, $carga->eventos()->where('tipo', 'folio_cargado')->count());
        // Una segunda lectura nueva del mismo pallet se informa, sin cambiar nada.
        $this->registrarCargado($contexto, $carga->id, $segundo->numero_folio)
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->assertDatabaseHas('carga_folios', [
            'folio_id' => $segundo->id,
            'estado' => 'cargado',
            'cargado_por_user_id' => $contexto['operador']->id,
            'cargado_desde_dispositivo_id' => $contexto['dispositivo']->id,
        ]);
        $this->assertDatabaseHas('reservas_carga_folio', ['folio_id' => $segundo->id]);
    }

    public function test_rechaza_el_pallet_de_otra_carga_nombrando_su_carga(): void
    {
        $contexto = $this->crearContextoFrio(2);
        [$propio, $ajeno] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$propio]);
        $otra = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$ajeno]);
        $this->enviarAlAnden($contexto, $otra, $ajeno);

        $this->registrarCargado($contexto, $carga->id, $ajeno->numero_folio)
            ->assertUnprocessable()
            ->assertJsonPath('message', "El folio {$ajeno->numero_folio} pertenece a la carga {$otra->codigo}, no a {$carga->codigo}. No lo subas a este camión.");
        $this->registrarCargado($contexto, $carga->id, 'NO-EXISTE-1')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El folio NO-EXISTE-1 no existe en el sistema.');

        $this->assertDatabaseHas('carga_folios', ['folio_id' => $ajeno->id, 'estado' => 'en_anden']);
    }

    public function test_bajar_del_camion_exige_motivo_y_vuelve_a_en_anden(): void
    {
        $contexto = $this->crearContextoFrio(1);
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);
        $this->registrarCargado($contexto, $carga->id, $folio->numero_folio)->assertOk();
        $asignacion = CargaFolio::query()->where('folio_id', $folio->id)->firstOrFail();

        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/asignaciones/{$asignacion->id}/deshacer-cargado", ['operacion_id' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');

        $this->conToken($contexto['token'])
            ->postJson("/api/cargas/asignaciones/{$asignacion->id}/deshacer-cargado", [
                'operacion_id' => (string) Str::uuid(),
                'motivo' => 'Leído por error',
            ])
            ->assertOk()
            ->assertJsonPath('data.folios.0.estado_carga', 'en_anden')
            ->assertJsonPath('data.folios.0.cargado_at', null)
            ->assertJsonPath('data.progreso.cargados', 0);

        $this->assertDatabaseHas('eventos_carga', ['carga_id' => $carga->id, 'tipo' => 'folio_descargado']);
        $this->assertSame('Leído por error', $carga->eventos()->where('tipo', 'folio_descargado')->first()->datos['motivo']);
    }

    public function test_el_cierre_despacha_los_cargados_y_puede_exigir_el_registro_completo(): void
    {
        $contexto = $this->crearContextoFrio(2);
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->enviarAlAnden($contexto, $carga, $primero);
        $this->registrarCargado($contexto, $carga->id, $primero->numero_folio)->assertOk();
        $this->assertSame('despachada', $carga->refresh()->estado->value);

        // La tablet sigue viendo la carga mientras los pallets suben al camión.
        $this->conToken($contexto['token'])->getJson('/api/cargas/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.codigo', $carga->codigo)
            ->assertJsonPath('data.0.estado', 'despachada');
        $this->conToken($contexto['token'])->getJson("/api/cargas/{$carga->id}/plan-extraccion")->assertOk();

        config(['despacho.cierre_exige_pallets_cargados' => true]);
        $this->cerrar($contexto, $carga->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', "Faltan 1 pallets por registrar como cargados en el camión: {$segundo->numero_folio}.");

        config(['despacho.cierre_exige_pallets_cargados' => false]);
        $this->cerrar($contexto, $carga->id)
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrada')
            ->assertJsonPath('data.folios.0.estado_carga', 'despachado')
            ->assertJsonPath('data.folios.1.estado_carga', 'despachado');
        $cierre = $carga->eventos()->where('tipo', 'cierre_despacho')->firstOrFail()->datos;
        $this->assertSame(1, $cierre['cantidad_cargados']);
        $this->assertSame([$segundo->numero_folio], $cierre['sin_registro_carga']);

        // El reporte «Despachos PT» lee lo que el cierre deja como despachado.
        $filas = app(ServicioExistencias::class)->filas(ServicioExistencias::DESPACHOS_PRODUCTO_TERMINADO, [])->values();
        $this->assertEqualsCanonicalizing([$primero->numero_folio, $segundo->numero_folio], $filas->pluck('folio')->unique()->values()->all());

        // Cerrada la carga, el carguío ya no admite cambios.
        $this->registrarCargado($contexto, $carga->id, $segundo->numero_folio)
            ->assertUnprocessable()
            ->assertJsonPath('message', "La carga {$carga->codigo} ya no admite movimientos de carguío.");
    }

    public function test_operacion_ahora_muestra_barras_de_separacion_y_carguio_por_carga(): void
    {
        $contexto = $this->crearContextoFrio(4);
        [$primero, $segundo, $tercero, $cuarto] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo, $tercero]);
        $this->enviarAlAnden($contexto, $carga, $tercero);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->registrarCargado($contexto, $carga->id, $tercero->numero_folio)->assertOk();

        $respuesta = $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->getJson('/api/operacion-ahora')
            ->assertOk()
            ->assertJsonPath('data.despacho.resumen.activas', 1)
            ->assertJsonPath('data.despacho.resumen.pallets_por_cargar_en_anden', 1);
        $fila = $respuesta->json('data.despacho.cargas.0');

        $this->assertSame($carga->codigo, $fila['codigo']);
        $this->assertSame(['Exportadora prueba'], $fila['clientes']);
        $this->assertSame($contexto['anden']->codigo, $fila['anden']);
        // Dos en andén y uno acopiado en la banda: 3/3 juntos para el despacho.
        $this->assertSame(['acopiados' => 3, 'total' => 3, 'porcentaje' => 100, 'umbral_porcentaje' => 80, 'cumple_umbral' => true, 'en_anden' => 2], $fila['separacion']);
        $this->assertSame(['cargados' => 1, 'total' => 3, 'porcentaje' => 33, 'en_anden_por_cargar' => 1], $fila['carguio']);
        $this->assertFalse($fila['lista_para_cerrar']);
    }

    public function test_solo_quien_opera_el_anden_registra_el_carguio(): void
    {
        $contexto = $this->crearContextoFrio(1);
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Consulta]), 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/cargados", [
                'operacion_id' => (string) Str::uuid(),
                'numero_folio' => $folio->numero_folio,
                'generado_dispositivo_at' => now()->toAtomString(),
            ])
            ->assertForbidden();
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $folio->id, 'estado' => 'en_anden']);
    }

    /** @param  array<string, mixed>  $contexto */
    private function registrarCargado(array $contexto, string $cargaId, string $numero, ?string $operacion = null, ?string $generadoAt = null): TestResponse
    {
        return $this->conToken($contexto['token'])->postJson("/api/cargas/{$cargaId}/cargados", [
            'operacion_id' => $operacion ?? (string) Str::uuid(),
            'numero_folio' => $numero,
            'generado_dispositivo_at' => $generadoAt ?? now()->toAtomString(),
        ]);
    }

    /** @param  array<string, mixed>  $contexto */
    private function cerrar(array $contexto, string $cargaId): TestResponse
    {
        return $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$cargaId}/cerrar-despacho", [
            'operacion_id' => (string) Str::uuid(),
            'patente' => 'AB-CD-12',
            'conductor' => 'María Pérez',
        ]);
    }
}
