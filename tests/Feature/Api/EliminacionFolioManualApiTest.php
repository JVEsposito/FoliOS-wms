<?php

namespace Tests\Feature\Api;

use App\Models\Camara;
use App\Models\EliminacionFolioManual;
use App\Models\Folio;
use App\Models\PlanOperacional;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class EliminacionFolioManualApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public function test_la_tablet_no_crea_un_folio_con_un_numero_desconocido(): void
    {
        $contexto = $this->crearContextoFrio(1, 'EF1');
        $posicion = Posicion::create(['camara_id' => $contexto['camara']->id, 'banda' => 1, 'posicion' => 2, 'nivel' => 1, 'etiqueta' => 'B01-P02-N1']);

        $this->conToken($contexto['token'])->getJson('/api/movimientos/consultar-folio?numero_folio=600000102')
            ->assertOk()
            ->assertJsonPath('data.existe', false);
        $this->conToken($contexto['token'])->postJson('/api/movimientos/ubicar', [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => '600000102',
            'tipo_bulto' => 'pallet',
            'camara_destino_id' => $contexto['camara']->id,
            'posicion_destino_id' => $posicion->id,
            'sesion_destino_id' => $contexto['sesion']->id,
            'version_destino_conocida' => Camara::query()->whereKey($contexto['camara']->id)->value('version_plano'),
            'generado_dispositivo_at' => now()->toAtomString(),
            'datos_folio' => ['variedad' => 'Santina'],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El folio 600000102 no existe. Valida el pallet en Validación PT antes de ubicarlo en cámara; si lo digitaste, revisa el número.');

        $this->assertDatabaseMissing('folios', ['numero_folio' => '600000102']);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['posicion_id' => $posicion->id]);
    }

    public function test_administracion_elimina_el_folio_manual_y_libera_su_posicion_y_su_numero(): void
    {
        $contexto = $this->crearContextoFrio(2, 'EF2');
        [$manual] = $contexto['folios'];
        $tokenAdmin = $contexto['administrador']->createToken('oficina-admin', ['oficina'])->plainTextToken;
        $version = Camara::query()->whereKey($contexto['camara']->id)->value('version_plano');
        $plan = PlanOperacional::create(['temporada_id' => $manual->temporada_id, 'tipo' => 'recepcion_tunel', 'estado' => 'programado',
            'prioridad' => 'alta', 'titulo' => 'Reordenamiento', 'programado_at' => now(), 'creado_por_user_id' => $contexto['administrador']->id]);
        $tarea = TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'secuencia' => 1, 'tipo_movimiento' => 'reubicacion',
            'estado' => 'pendiente', 'prioridad' => 'alta', 'folio_id' => $manual->id, 'camara_origen_id' => $contexto['camara']->id,
            'camara_destino_id' => $contexto['camara']->id]);

        $expediente = $this->conToken($tokenAdmin)->getJson("/api/consultas/folios/{$manual->id}")
            ->assertOk()
            ->assertJsonPath('folio.origen_sistema', 'manual')
            ->assertJsonPath('acciones.eliminar_folio_manual.disponible', true)
            ->json();
        // El expediente dice quién ubicó el folio y desde qué tablet.
        $this->assertSame($contexto['operador']->name, $expediente['timeline'][0]['meta']['Operador']);
        $this->assertSame('Tablet EF2', $expediente['timeline'][0]['meta']['Tablet']);

        // Solo administración ve y usa la acción.
        $this->conToken($contexto['tokenOficina'])->getJson("/api/consultas/folios/{$manual->id}")
            ->assertOk()->assertJsonPath('acciones.eliminar_folio_manual', null);
        $this->conToken($contexto['tokenOficina'])->postJson("/api/folios/{$manual->id}/eliminar-manual", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Pallet ubicado sin validar.',
        ])->assertForbidden();
        $this->conToken($tokenAdmin)->postJson("/api/folios/{$manual->id}/eliminar-manual", ['operacion_id' => (string) Str::uuid(), 'motivo' => 'corto'])
            ->assertUnprocessable()->assertJsonValidationErrors('motivo');

        $operacion = (string) Str::uuid();
        $payload = ['operacion_id' => $operacion, 'motivo' => 'Pallet ubicado sin validar; se validará en Validación PT.'];
        $this->conToken($tokenAdmin)->postJson("/api/folios/{$manual->id}/eliminar-manual", $payload)
            ->assertOk()
            ->assertJsonPath('data.numero_folio', 'FOLIO-EF2-01')
            ->assertJsonPath('data.numero_folio_archivado', 'FOLIO-EF2-01-ELIM-1')
            ->assertJsonPath('data.ubicacion_liberada.posicion', 'B01-P01-N1')
            ->assertJsonPath('data.eliminado_por', $contexto['administrador']->name);
        $this->conToken($tokenAdmin)->postJson("/api/folios/{$manual->id}/eliminar-manual", $payload)->assertOk();
        $this->assertSame(1, EliminacionFolioManual::query()->count());

        $this->assertDatabaseHas('folios', ['id' => $manual->id, 'numero_folio' => 'FOLIO-EF2-01-ELIM-1', 'activo' => false, 'estado_operacional' => 'anulado']);
        $this->assertDatabaseMissing('folios', ['numero_folio' => 'FOLIO-EF2-01']);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $manual->id]);
        $this->assertSame($version + 1, Camara::query()->whereKey($contexto['camara']->id)->value('version_plano'));
        $this->assertSame('cancelada', $tarea->refresh()->estado->value);
        // El otro pallet de la cámara no se toca.
        $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $contexto['folios'][1]->id]);

        $this->conToken($tokenAdmin)->getJson("/api/consultas/folios/{$manual->id}")
            ->assertOk()
            ->assertJsonPath('eliminacion.numero_folio', 'FOLIO-EF2-01')
            ->assertJsonPath('eliminacion.eliminado_por', $contexto['administrador']->name)
            ->assertJsonPath('acciones.eliminar_folio_manual', null);
        $this->conToken($tokenAdmin)->postJson("/api/folios/{$manual->id}/eliminar-manual", [...$payload, 'operacion_id' => (string) Str::uuid()])
            ->assertConflict()
            ->assertJsonPath('message', 'El folio ya está inactivo.');
    }

    public function test_solo_se_elimina_mientras_conserva_la_condicion_de_folio_manual(): void
    {
        $contexto = $this->crearContextoFrio(3, 'EF3');
        [$enCarga, $validado, $enMovimiento] = $contexto['folios'];
        $tokenAdmin = $contexto['administrador']->createToken('oficina-admin', ['oficina'])->plainTextToken;
        $eliminar = fn (Folio $folio) => $this->conToken($tokenAdmin)->postJson("/api/folios/{$folio->id}/eliminar-manual", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Pallet ubicado sin validar.',
        ]);

        $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$enCarga]);
        $eliminar($enCarga)->assertConflict()->assertJsonPath('message', 'El folio fue asignado a una carga y ya no puede eliminarse.');

        Folio::query()->whereKey($validado->id)->update(['origen_sistema' => 'validacion']);
        $eliminar($validado)->assertConflict()->assertJsonPath('message', 'Solo se eliminan folios creados a mano en cámara, sin Validación PT.');
        $this->conToken($tokenAdmin)->getJson("/api/consultas/folios/{$validado->id}")->assertOk()->assertJsonPath('acciones.eliminar_folio_manual', null);

        $plan = PlanOperacional::create(['temporada_id' => $enMovimiento->temporada_id, 'tipo' => 'recepcion_tunel', 'estado' => 'programado',
            'prioridad' => 'alta', 'titulo' => 'Reordenamiento', 'programado_at' => now(), 'creado_por_user_id' => $contexto['administrador']->id]);
        TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'secuencia' => 1, 'tipo_movimiento' => 'reubicacion',
            'estado' => 'asumida', 'prioridad' => 'alta', 'folio_id' => $enMovimiento->id, 'camara_origen_id' => $contexto['camara']->id,
            'camara_destino_id' => $contexto['camara']->id, 'responsable_user_id' => $contexto['operador']->id, 'dispositivo_id' => $contexto['dispositivo']->id]);
        $eliminar($enMovimiento)->assertConflict()->assertJsonPath('message', 'Un camarero está moviendo este folio. Espera a que termine la labor.');

        $this->assertSame(0, EliminacionFolioManual::query()->count());
        $this->assertSame(3, Folio::query()->where('activo', true)->count());
    }
}
