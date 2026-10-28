<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\EventoRecepcionRomana;
use App\Models\Folio;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\TrazabilidadFolioOrigen;
use App\Models\User;
use App\Services\Romana\ServicioAnulacionRecepcionRomana;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\PreparaCorreccionesEnvases;
use Tests\Concerns\PreparaRecepcionEnvases;
use Tests\TestCase;

class EliminacionRecepcionRomanaTest extends TestCase
{
    use PreparaCorreccionesEnvases, PreparaRecepcionEnvases, RefreshDatabase;

    public function test_elimina_fruta_cerrada_validada_y_lotizada_con_ubicacion_sin_consumos(): void
    {
        [$c, $lotes, $admin, $camara] = $this->frutaLotizada();
        $recepcion = $c['recepcion']->refresh();
        $this->assertSame('cerrado', $recepcion->estado->value);
        $this->assertSame('validada', $recepcion->estado_validacion_mp->value);
        $datos = $this->datos($recepcion->version);
        $ruta = '/api/romana/recepciones/'.$recepcion->id;

        $this->deleteJson($ruta, $datos)->assertOk()->assertJsonPath('data.estado', 'anulada')
            ->assertJsonPath('data.puede_eliminar', false);
        $this->deleteJson($ruta, $datos)->assertOk();

        $this->assertSame(0, $this->saldo('signo_cuenta', $recepcion->cliente));
        $this->assertSame(0, $this->saldo('signo_existencia', $recepcion->cliente));
        foreach ($lotes as $lote) {
            $this->assertSame('anulado', $lote->refresh()->estado->value);
            $this->assertSame($admin->id, $lote->anulado_por_user_id);
            $this->assertNull($lote->clave_numero_vigente);
        }
        $this->assertSame($camara->id, $lotes[0]->asignacionCamara->camara_id);
        $this->assertDatabaseCount('recepciones_romana', 1);
        $this->assertDatabaseCount('lotes_materia_prima', 2);
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'recepcion_eliminada')->count());
        $this->getJson('/api/romana/recepciones')->assertJsonPath('meta.total', 0)->assertJsonPath('resumen.cerradas', 0);
        $this->getJson('/api/romana/recepciones?estado=anulada')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/materia-prima/lotes')->assertJsonCount(0, 'data');
        $this->getJson('/api/materia-prima/segmentos-pendientes')->assertJsonCount(0, 'data');
        $this->postJson('/api/materia-prima/lotes', ['operacion_id' => (string) Str::uuid(),
            'segmento_validacion_mp_id' => $c['segmentos'][0]['id'], 'numero_lote' => 'LOTE-RECREADO',
            'sdp' => '123', 'fecha_cosecha' => now()->toDateString(), 'predio' => 'Fundo',
            'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false])->assertConflict();
    }

    public function test_elimina_validacion_en_curso_y_rechaza_su_confirmacion_posterior(): void
    {
        $c = $this->preparar(['bins' => 4], confirmar: false);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/romana/recepciones/'.$c['recepcion']->id,
            $this->datos($c['recepcion']->refresh()->version))->assertOk();
        $ruta = '/api/validacion-mp/validaciones/'.$c['validacion'].'/confirmar';
        $this->actingAs($c['validador'], 'sanctum')->postJson($ruta, $this->payloadConInspeccionRc02($ruta, $c['mp']))->assertConflict();
        $this->assertDatabaseCount('movimientos_envases', 0);
        $this->assertDatabaseCount('segmentos_validacion_mp', 0);
    }

    public function test_compensa_entrada_y_salida_de_envases_de_la_misma_recepcion(): void
    {
        $c = $this->preparar(['bins' => 4]);
        $this->cerrar($c, 'mismos')->assertOk();
        $r = $c['recepcion']->refresh();
        $originales = $r->movimientosEnvases()->count();
        $this->assertGreaterThanOrEqual(2, $originales);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum')
            ->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertOk();
        $this->assertSame(0, $this->saldo('signo_cuenta', $r->cliente));
        $this->assertSame(0, $this->saldo('signo_existencia', $r->cliente));
        $this->assertSame($originales * 2, $r->movimientosEnvases()->count());
    }

    public function test_exige_administrador_activo_motivo_version_y_reintentos_identicos(): void
    {
        [$r, , , , $admin] = $this->ingresoEnvases(false);
        $ruta = '/api/romana/recepciones/'.$r->id;
        $datos = $this->datos($r->version);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->getJson($ruta)->assertOk()->assertJsonPath('data.puede_eliminar', false);
        $this->deleteJson($ruta, $datos)->assertForbidden();
        $this->actingAs($admin, 'sanctum')->deleteJson($ruta, [...$datos, 'motivo' => ' '])->assertUnprocessable();
        $this->deleteJson($ruta, [...$datos, 'version_conocida' => 999])->assertConflict();
        $this->deleteJson($ruta, $datos)->assertOk();
        $this->deleteJson($ruta, $datos)->assertOk();
        $this->deleteJson($ruta, [...$datos, 'motivo' => 'Motivo distinto para la misma operación'])->assertConflict();
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'recepcion_eliminada')->count());
    }

    public function test_libera_la_guia_eliminada_y_mantiene_la_unicidad_entre_recepciones_vigentes(): void
    {
        [$r] = $this->ingresoEnvases(false);
        $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))
            ->assertOk()->assertJsonPath('data.eliminada', true);
        $ingreso = ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $r->temporada_id,
            'cliente_id' => $r->cliente_id, 'tipo_recepcion' => 'solo_envases', 'concepto_envases' => 'arriendo',
            'fecha_ingreso' => now(config('app.operational_timezone'))->toDateString(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 138]],
            'numero_guia_despacho' => $r->numero_guia_despacho, 'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor de prueba'];
        $this->postJson('/api/romana/recepciones', $ingreso)->assertCreated();
        $this->postJson('/api/romana/recepciones', [...$ingreso, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $vigente = RecepcionRomana::whereNull('eliminada_at')->sole();
        try {
            $vigente->replicate(['numero_recepcion', 'operacion_id'])->fill(['operacion_id' => (string) Str::uuid()])->save();
            $this->fail('La base también debe impedir una guía vigente duplicada.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertDatabaseCount('recepciones_romana', 2);
        $this->getJson('/api/romana/recepciones')->assertJsonPath('meta.total', 1);
    }

    public function test_no_elimina_una_recepcion_cuyos_lotes_ya_fueron_entregados_a_packing(): void
    {
        [$c, $lotes, , $camara] = $this->frutaLotizada();
        $this->postJson('/api/materia-prima/fruta-proceso/lotes/'.$lotes[0]->id.'/entregas', [
            'operacion_id' => (string) Str::uuid(), 'camara_origen_id' => $camara->id,
            'cantidad_envases' => 1, 'linea_proceso' => 'Línea 1', 'turno' => 'A', 'numero_orden' => 'OP-1'])->assertOk();
        $r = $c['recepcion']->refresh();
        $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict();
        $this->assertSame('cerrado', $r->refresh()->estado->value);
        $this->assertSame('borrador', $lotes[1]->refresh()->estado->value);
        $this->assertSame(4, $this->saldo('signo_existencia', $r->cliente));
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    public function test_no_elimina_el_origen_de_un_pallet_de_producto_terminado(): void
    {
        [$c, $lotes] = $this->frutaLotizada();
        $r = $c['recepcion']->refresh();
        $folio = Folio::create(['temporada_id' => $r->temporada_id, 'numero_folio' => 'PT-ELIMINACION',
            'tipo_bulto' => 'pallet', 'estado_operacional' => 'disponible', 'fecha_ingreso' => now()]);
        $origen = TrazabilidadFolioOrigen::create(['folio_id' => $folio->id, 'temporada_id' => $r->temporada_id,
            'cliente_id' => $r->cliente_id, 'lote_materia_prima_id' => $lotes[0]->id,
            'numero_lote_materia_prima' => $lotes[0]->numero_lote, 'cantidad_cajas' => 120]);
        $this->deleteJson('/api/romana/recepciones/'.$r->id, $this->datos($r->version))->assertConflict();
        $this->assertSame($lotes[0]->id, $origen->refresh()->lote_materia_prima_id);
        $this->assertSame('cerrado', $r->refresh()->estado->value);
    }

    public function test_un_fallo_de_auditoria_restituye_lotes_segmentos_y_saldos(): void
    {
        [$c, $lotes, $admin] = $this->frutaLotizada();
        $r = $c['recepcion']->refresh();
        $evento = 'eloquent.creating: '.EventoRecepcionRomana::class;
        Event::listen($evento, function (EventoRecepcionRomana $registro): void {
            if ($registro->tipo->value === 'recepcion_eliminada') {
                throw new RuntimeException('Fallo simulado de auditoría');
            }
        });
        try {
            app(ServicioAnulacionRecepcionRomana::class)->eliminar($r, $this->datos($r->version), $admin);
            $this->fail('La eliminación no debe publicarse si falla su auditoría.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo simulado de auditoría', $e->getMessage());
        } finally {
            Event::forget($evento);
        }
        $this->assertSame('cerrado', $r->refresh()->estado->value);
        $this->assertSame('asignado_camara', $lotes[0]->refresh()->estado->value);
        $this->assertSame('borrador', $lotes[1]->refresh()->estado->value);
        $this->assertSame(4, $this->saldo('signo_existencia', $r->cliente));
        $this->assertDatabaseMissing('segmentos_validacion_mp', ['estado' => 'anulado']);
        $this->assertSame(0, MovimientoEnvase::where('tipo_movimiento', 'anulacion_recepcion')->count());
    }

    private function frutaLotizada(): array
    {
        $c = $this->preparar(['bins' => 4]);
        $this->cerrar($c, 'vacio')->assertOk();
        $lotes = $this->lotizar($c);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/materia-prima/lotes/'.$lotes[0]->id.'/confirmar', [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $lotes[0]->version])->assertOk();
        $camara = Camara::create(['codigo' => 'MP-ELIMINAR', 'nombre' => 'Cámara de prueba',
            'tipo' => 'almacenaje', 'contenido' => ContenidoCamara::MateriaPrima,
            'cantidad_bandas' => 1, 'posiciones_por_banda' => 1, 'cantidad_niveles' => 1]);
        $this->postJson('/api/materia-prima/lotes/'.$lotes[0]->id.'/asignar-camara', [
            'operacion_id' => (string) Str::uuid(), 'camara_id' => $camara->id])->assertOk();

        return [$c, $lotes, $admin, $camara];
    }

    private function datos(int $version): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $version,
            'motivo' => 'Recepción ingresada por equivocación durante las pruebas.'];
    }
}
