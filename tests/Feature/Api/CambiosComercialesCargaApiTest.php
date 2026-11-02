<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\IncidenciaCargaFolio;
use App\Models\Posicion;
use App\Models\ReingresoFolioCamara;
use App\Models\User;
use App\Services\Estiba\ServicioMovimientoEstiba;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class CambiosComercialesCargaApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public function test_cancelar_en_plena_carga_libera_lo_que_sigue_en_camara_y_deja_pendiente_lo_que_salio(): void
    {
        $contexto = $this->crearContextoFrio(4);
        [$enCamara, $enAnden, $cargado, $libre] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$enCamara, $enAnden, $cargado]);
        $this->enviarAlAnden($contexto, $carga, $cargado);
        $this->enviarAlAnden($contexto, $carga, $enAnden);
        $this->cargar($contexto, $carga, $cargado);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/camion-en-anden", [
            'operacion_id' => (string) Str::uuid(),
            'version_esperada' => $carga->refresh()->version,
            'anden_id' => $contexto['anden']->id,
            'patente' => 'AB-CD-12',
        ])->assertOk();

        $this->cancelar($contexto, $carga->refresh(), null)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Indica el motivo comercial de la cancelación.');
        $this->cancelar($contexto, $carga, 'El cliente anuló el pedido')
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada')
            ->assertJsonPath('data.motivo_cancelacion', 'El cliente anuló el pedido');

        $this->assertDatabaseMissing('reservas_carga_folio', ['folio_id' => $enCamara->id]);
        $this->assertNull($carga->refresh()->presenciaAndenActiva);
        foreach ([[$enAnden, 'en_anden'], [$cargado, 'cargado']] as [$folio, $estadoPrevio]) {
            $this->assertDatabaseHas('reingresos_folio_camara', [
                'folio_id' => $folio->id,
                'folio_pendiente_id' => $folio->id,
                'carga_id' => $carga->id,
                'origen' => 'cancelacion_carga',
                'estado' => 'pendiente',
                'estado_carga_previo' => $estadoPrevio,
                'anden_id' => $contexto['anden']->id,
                'motivo' => 'El cliente anuló el pedido',
            ]);
        }
        $cancelacion = $carga->eventos()->where('tipo', 'cancelada')->firstOrFail()->datos;
        $this->assertSame([$enCamara->numero_folio], $cancelacion['folios_liberados']);
        $this->assertEqualsCanonicalizing([$enAnden->numero_folio, $cargado->numero_folio], $cancelacion['pendientes_reingreso']);
        $this->assertSame('despacho_parcial', $cancelacion['estado_previo']);

        // El pallet en cámara vuelve a ofrecerse; el que quedó afuera no, hasta ubicarse.
        $otra = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$enCamara, $libre]);
        $this->assertSame(2, $otra->asignacionesActuales()->count());
        $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$otra->id}/folios", [
            'folios' => [$enAnden->numero_folio],
            'version_esperada' => $otra->version,
        ])->assertUnprocessable()->assertJsonPath('errores.0.codigo', 'pendiente_reingreso');

        $this->cancelar($contexto, $carga->refresh(), 'Otra vez')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La carga ya está cancelada.');
    }

    public function test_quitar_un_pallet_del_anden_exige_motivo_y_recalcula_el_estado_de_la_carga(): void
    {
        $contexto = $this->crearContextoFrio(2);
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo]);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->assertSame('despacho_parcial', $carga->refresh()->estado->value);

        $this->quitar($contexto, $carga, $segundo, null)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Indica por qué el pallet sale de la carga.');
        $this->quitar($contexto, $carga, $segundo, 'Pallet con daño de cajas')
            ->assertOk()
            ->assertJsonPath('data.estado', 'en_preparacion')
            ->assertJsonPath('data.total_folios', 1);
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $segundo->id, 'estado' => 'descartado', 'motivo_finalizacion' => 'Pallet con daño de cajas']);
        $this->assertDatabaseHas('reingresos_folio_camara', ['folio_id' => $segundo->id, 'origen' => 'pallet_quitado', 'estado' => 'pendiente']);

        $this->quitar($contexto, $carga->refresh(), $primero, 'Último pallet')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Una carga publicada debe conservar al menos un folio. Si ya no sale ningún pallet, cancela la carga.');
    }

    public function test_cambiar_un_pallet_advierte_las_diferencias_y_el_saliente_del_anden_queda_pendiente(): void
    {
        $contexto = $this->crearContextoFrio(3);
        [$primero, $segundo, $reemplazo] = $contexto['folios'];
        $reemplazo->update(['variedad' => 'Lapins', 'calibre' => '3J']);
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo]);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->enviarAlAnden($contexto, $carga, $primero);
        $this->assertSame('despachada', $carga->refresh()->estado->value);

        $sinConfirmar = $this->cambiar($contexto, $carga, $primero, $reemplazo->numero_folio, false)
            ->assertConflict()
            ->assertJsonPath('codigo', 'confirmacion_requerida');
        $this->assertSame(['diferencia_variedad', 'diferencia_calibre'], array_column($sinConfirmar->json('advertencias'), 'codigo'));
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $primero->id, 'estado' => 'en_anden']);

        $this->cambiar($contexto, $carga, $primero, strtolower($reemplazo->numero_folio), true)
            ->assertOk()
            ->assertJsonPath('data.estado', 'despacho_parcial');

        $saliente = CargaFolio::query()->where('folio_id', $primero->id)->firstOrFail();
        $this->assertSame('reemplazado', $saliente->estado->value);
        $this->assertDatabaseHas('carga_folios', [
            'carga_id' => $carga->id,
            'folio_id' => $reemplazo->id,
            'estado' => 'pendiente',
            'reemplaza_a_carga_folio_id' => $saliente->id,
        ]);
        $this->assertDatabaseHas('reservas_carga_folio', ['folio_id' => $reemplazo->id]);
        $this->assertDatabaseHas('reingresos_folio_camara', ['folio_id' => $primero->id, 'origen' => 'pallet_cambiado', 'estado' => 'pendiente']);
        $evento = $carga->eventos()->where('tipo', 'folio_reemplazado')->firstOrFail()->datos;
        $this->assertSame('cambio_comercial', $evento['causa']);
        $this->assertSame(['diferencia_variedad', 'diferencia_calibre'], $evento['diferencias']);

        // Un pallet que pertenece a otra carga no puede entrar.
        $otraContexto = $this->crearContextoFrio(1, 'OTRA');
        $otra = $this->crearCargaPublicada($otraContexto['despachador'], $otraContexto['anden'], $otraContexto['folios']);
        $this->cambiar($contexto, $carga->refresh(), $segundo, $otraContexto['folios'][0]->numero_folio, true)
            ->assertUnprocessable()
            ->assertJsonPath('message', "El folio {$otraContexto['folios'][0]->numero_folio} ya está asignado a la carga {$otra->codigo}.");
    }

    public function test_el_pallet_pendiente_cierra_su_reingreso_al_volver_a_una_posicion(): void
    {
        $contexto = $this->crearContextoFrio(2);
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo]);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->cancelar($contexto, $carga->refresh(), 'Cambio de programa')->assertOk();
        $posicion = Posicion::query()->where('camara_id', $contexto['camara']->id)->where('posicion', 2)->firstOrFail();

        $movimiento = app(ServicioMovimientoEstiba::class)->ubicar(
            operacionId: (string) Str::uuid(),
            numeroFolio: $segundo->numero_folio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $contexto['sesion'],
            usuario: $contexto['operador'],
            dispositivo: $contexto['dispositivo'],
            versionDestinoConocida: $contexto['camara']->refresh()->version_plano,
            generadoDispositivoAt: now(),
        );

        $reingreso = ReingresoFolioCamara::query()->where('folio_id', $segundo->id)->firstOrFail();
        $this->assertSame('ubicado', $reingreso->estado->value);
        $this->assertNull($reingreso->folio_pendiente_id);
        $this->assertSame($movimiento->id, $reingreso->movimiento_ubicacion_id);
        $this->assertSame($contexto['camara']->id, $reingreso->camara_ubicacion_id);
        $this->assertSame($segundo->numero_folio, Folio::query()->whereKey($segundo->id)->value('numero_folio'));
        // Ya en cámara, vuelve a poder asignarse.
        $nueva = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$segundo]);
        $this->assertSame(1, $nueva->asignacionesActuales()->count());
    }

    public function test_la_cancelacion_cierra_incidencias_abiertas_y_solo_gestiona_el_despachador_hacia_arriba(): void
    {
        $contexto = $this->crearContextoFrio(2);
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        $asignacion = CargaFolio::query()->where('folio_id', $contexto['folios'][0]->id)->firstOrFail();
        $this->conToken($contexto['token'])->postJson("/api/cargas/asignaciones/{$asignacion->id}/incidencias", [
            'operacion_id' => (string) Str::uuid(),
            'tipo' => 'caja_aplastada',
            'sesion_estiba_id' => $contexto['sesion']->id,
        ])->assertCreated();

        $this->conToken($contexto['token'])->postJson("/api/cargas/{$carga->id}/cancelar", [
            'version_esperada' => $carga->refresh()->version,
            'motivo' => 'No corresponde',
        ])->assertForbidden();

        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $this->actingAs($supervisor, 'sanctum')->postJson("/api/cargas/{$carga->id}/cancelar", [
            'version_esperada' => $carga->version,
            'motivo' => 'Cliente reprogramó el embarque',
        ])->assertOk()->assertJsonPath('data.estado', 'cancelada');

        $incidencia = IncidenciaCargaFolio::query()->where('carga_folio_id', $asignacion->id)->firstOrFail();
        $this->assertSame('resuelta', $incidencia->estado->value);
        $this->assertSame('retirado_de_carga', $incidencia->tipo_resolucion->value);
        $this->assertSame('Cliente reprogramó el embarque', $incidencia->observacion_resolucion);
        $this->assertDatabaseCount('reingresos_folio_camara', 0);
    }

    /** @param  array<string, mixed>  $contexto */
    private function cargar(array $contexto, Carga $carga, Folio $folio): void
    {
        $this->conToken($contexto['token'])->postJson("/api/cargas/{$carga->id}/cargados", [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $folio->numero_folio,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();
    }

    /** @param  array<string, mixed>  $contexto */
    private function cancelar(array $contexto, Carga $carga, ?string $motivo): TestResponse
    {
        return $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/cancelar", [
            'version_esperada' => $carga->version,
            'motivo' => $motivo,
        ]);
    }

    /** @param  array<string, mixed>  $contexto */
    private function quitar(array $contexto, Carga $carga, Folio $folio, ?string $motivo): TestResponse
    {
        return $this->conToken($contexto['tokenOficina'])->deleteJson("/api/cargas/{$carga->id}/folios/{$folio->id}", [
            'version_esperada' => $carga->refresh()->version,
            'motivo' => $motivo,
        ]);
    }

    /** @param  array<string, mixed>  $contexto */
    private function cambiar(array $contexto, Carga $carga, Folio $saliente, string $entrante, bool $confirmar): TestResponse
    {
        return $this->conToken($contexto['tokenOficina'])->postJson("/api/cargas/{$carga->id}/folios/{$saliente->id}/cambiar", [
            'version_esperada' => $carga->refresh()->version,
            'numero_folio_entrante' => $entrante,
            'motivo' => 'El cliente pidió otro calibre',
            'confirmar_diferencias' => $confirmar,
        ]);
    }
}
