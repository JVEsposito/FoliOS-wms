<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Events\EventoCargaRegistrado;
use App\Listeners\CrearNotificacionesOperacionales;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\NotificacionOperacional;
use App\Models\ReingresoFolioCamara;
use App\Models\User;
use App\Services\Cargas\ServicioCarga;
use App\Services\Existencias\ServicioExistencias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class RetornoCargaApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public function test_el_retorno_total_reactiva_los_folios_y_los_deja_pendientes_de_reingreso(): void
    {
        [$contexto, $carga] = $this->cargaCerrada(2);
        [$primero, $segundo] = $contexto['folios'];
        $llegada = now()->subMinutes(5)->startOfMinute();

        $this->retorno($contexto, $carga, ['motivo' => 'Contenedor rechazado en puerto', 'llegada_at' => $llegada->toAtomString()])
            ->assertOk()
            ->assertJsonPath('data.estado', 'retornada')
            ->assertJsonPath('data.retorno.motivo', 'Contenedor rechazado en puerto')
            ->assertJsonPath('data.retorno.parcial', false)
            ->assertJsonPath('data.folios.0.estado_carga', 'retornado');

        foreach ([$primero, $segundo] as $folio) {
            $this->assertDatabaseHas('folios', ['id' => $folio->id, 'activo' => true, 'estado_operacional' => 'disponible', 'numero_folio' => $folio->numero_folio]);
            $this->assertDatabaseHas('carga_folios', ['folio_id' => $folio->id, 'estado' => 'retornado', 'retornado_por_user_id' => $contexto['despachador']->id]);
            $this->assertDatabaseHas('reingresos_folio_camara', [
                'folio_id' => $folio->id,
                'carga_id' => $carga->id,
                'origen' => 'retorno_carga',
                'estado' => 'pendiente',
                'estado_carga_previo' => 'despachado',
                'anden_id' => $contexto['anden']->id,
            ]);
            $this->assertDatabaseHas('saldos_existencia_sag', ['folio_id' => $folio->id]);
        }
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cancelar", [
            'version_esperada' => $carga->refresh()->version,
            'motivo' => 'Ya volvió',
        ])->assertUnprocessable()->assertJsonPath('message', 'La carga ya retornó a planta y no puede cancelarse.');
        $evento = $carga->eventos()->where('tipo', 'retornada')->firstOrFail()->datos;
        $this->assertFalse($evento['parcial']);
        $this->assertSame(0, $evento['siguen_despachados']);

        $notificacion = NotificacionOperacional::query()->where('tipo', 'carga_retornada')->sole();
        $this->assertSame(2, $notificacion->datos['cantidad_folios']);
        $this->assertEqualsCanonicalizing([$primero->numero_folio, $segundo->numero_folio], $notificacion->datos['folios']);
        app(CrearNotificacionesOperacionales::class)->handle(new EventoCargaRegistrado($carga->eventos()->where('tipo', 'retornada')->sole()));
        $this->assertSame(1, NotificacionOperacional::query()->where('tipo', 'carga_retornada')->count());
        $feed = $this->conToken($contexto['token'])->getJson('/api/notificaciones-operacionales')->assertOk();
        $this->assertContains($notificacion->id, array_column($feed->json('data'), 'id'));
        $this->conToken($contexto['token'])->postJson("/api/notificaciones-operacionales/{$notificacion->id}/confirmar")->assertOk();
        // Acusar recibo no sustituye el movimiento de ubicación.
        $this->assertSame(2, ReingresoFolioCamara::query()->where('carga_id', $carga->id)->where('estado', 'pendiente')->count());

        // Mientras no vuelva a una posición, el pallet no entra a otra carga.
        $nueva = app(ServicioCarga::class)->crear([], $contexto['despachador']);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$nueva->id}/folios", [
            'folios' => [$primero->numero_folio],
            'version_esperada' => $nueva->version,
        ])->assertUnprocessable()->assertJsonPath('errores.0.codigo', 'pendiente_reingreso');
    }

    public function test_el_retorno_parcial_conserva_despachado_lo_que_no_volvio(): void
    {
        [$contexto, $carga] = $this->cargaCerrada(3);
        [$primero, $segundo, $tercero] = $contexto['folios'];

        $this->retorno($contexto, $carga, ['motivo' => 'Dos pallets con frío roto', 'folios' => [$primero->id, $segundo->id]])
            ->assertOk()
            ->assertJsonPath('data.estado', 'retorno_parcial')
            ->assertJsonPath('data.retorno.parcial', true);
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $tercero->id, 'estado' => 'despachado']);
        $this->assertDatabaseHas('folios', ['id' => $tercero->id, 'activo' => false, 'estado_operacional' => 'despachado']);

        // El reporte de despachos conserva lo que sí salió y omite lo que volvió.
        $despachados = app(ServicioExistencias::class)->filas(ServicioExistencias::DESPACHOS_PRODUCTO_TERMINADO, [])->pluck('folio')->unique()->values()->all();
        $this->assertSame([$tercero->numero_folio], $despachados);

        $this->retorno($contexto, $carga->refresh(), ['motivo' => 'Repetido', 'folios' => [$primero->id]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Uno o más pallets indicados no salieron despachados en esta carga o ya retornaron.');
        $this->retorno($contexto, $carga, ['motivo' => 'Volvió el último pallet'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'retornada');
        $this->assertSame(3, DB::table('reingresos_folio_camara')->where('carga_id', $carga->id)->count());
    }

    public function test_solo_una_carga_cerrada_retorna_y_con_motivo_y_hora_valida(): void
    {
        $contexto = $this->crearContextoFrio(1);
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);

        $this->retorno($contexto, $carga->refresh(), ['motivo' => 'Aún no sale'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Solo una carga cerrada (o con retorno parcial) puede registrar un retorno a planta.');

        $this->travel(10)->minutes();
        $this->cerrar($contexto, $carga);
        $carga->refresh();
        $this->retorno($contexto, $carga, [])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->retorno($contexto, $carga, ['motivo' => 'Antes de salir', 'llegada_at' => $carga->cerrada_at->subMinute()->toAtomString()])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La llegada a planta no puede ser anterior a la salida del camión.');
        $this->retorno($contexto, $carga, ['motivo' => 'Ajeno', 'folios' => [(string) Str::uuid()]])
            ->assertUnprocessable();

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]), 'sanctum')
            ->postJson("/api/cargas/{$carga->id}/retorno", ['version_esperada' => $carga->version, 'motivo' => 'Sin permiso'])
            ->assertForbidden();
        $this->assertSame('cerrada', $carga->refresh()->estado->value);
        $this->travelBack();
    }

    /** @return array{0: array<string, mixed>, 1: Carga} */
    private function cargaCerrada(int $cantidad): array
    {
        $contexto = $this->crearContextoFrio($cantidad);
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        foreach (array_reverse($contexto['folios']) as $folio) {
            $this->enviarAlAnden($contexto, $carga, $folio);
        }
        $this->cerrar($contexto, $carga);
        // El camión vuelve a planta un rato después de salir.
        $this->travel(20)->minutes();
        $this->assertSame(0, CargaFolio::query()->where('carga_id', $carga->id)->where('estado', '!=', 'despachado')->count());

        return [$contexto, $carga->refresh()];
    }

    /** @param  array<string, mixed>  $contexto */
    private function cerrar(array $contexto, Carga $carga): void
    {
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cerrar-despacho", [
            'operacion_id' => (string) Str::uuid(),
            'patente' => 'AB-CD-12',
            'conductor' => 'María Pérez',
        ])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $contexto
     * @param  array<string, mixed>  $datos
     */
    private function retorno(array $contexto, Carga $carga, array $datos): TestResponse
    {
        return $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/retorno", [
            'version_esperada' => $carga->refresh()->version,
            ...$datos,
        ]);
    }
}
