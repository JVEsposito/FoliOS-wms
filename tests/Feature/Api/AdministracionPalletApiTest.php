<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoTareaMovimiento;
use App\Enums\PrioridadOperacional;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPlanOperacional;
use App\Models\BandaOperacional;
use App\Models\Camara;
use App\Models\CustodiaTemporalManiobra;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\Posicion;
use App\Models\TareaMovimiento;
use App\Models\TransicionOperacional;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\ServicioPlanesOperacionales;
use App\Services\Estiba\ServicioRecomendacionUbicacion;
use App\Services\Planificador\ServicioDestinosServidor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministracionPalletApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $indice = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        config(['planificador.mode' => 'off', 'planificador.horizon' => 'rolling']);
    }

    public function test_solo_administrador_activo_puede_consultar_intervenir_y_configurar_prioridades(): void
    {
        $f = $this->folio();
        [$c] = $this->camara();
        $this->getJson('/api/intervenciones-planificador/pallets?folio='.$f->numero_folio)->assertUnauthorized();
        foreach ([RolUsuario::SupervisorFrio, RolUsuario::CamareroFrio, RolUsuario::Consulta, RolUsuario::Cliente] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol, 'activo' => true]), 'sanctum');
            $this->getJson('/api/intervenciones-planificador/pallets?folio='.$f->numero_folio)->assertForbidden();
            $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, [])->assertForbidden();
            $this->patchJson('/api/configuracion/camaras/'.$c->id.'/prioridad-operacional', [])->assertForbidden();
        }
    }

    public function test_correccion_fisica_crea_movimiento_inmutable_con_origen_destino_motivo_y_reintento_unico(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $f = $this->folio();
        [$origen, $p1] = $this->camara();
        [$destino, $p2] = $this->camara();
        $ubicacion = $this->ocupar($f, $p1);
        $datos = $this->payload($f, $p2, 'corregir_ubicacion');
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertOk();
        $this->assertSame($p2->id, $ubicacion->refresh()->posicion_id);
        $m = Movimiento::where('folio_id', $f->id)->sole();
        $this->assertSame(TipoMovimiento::CorreccionAdministrativa, $m->tipo_movimiento);
        $this->assertSame($p1->id, $m->posicion_origen_id);
        $this->assertSame($p2->id, $m->posicion_destino_id);
        $this->assertNull($m->dispositivo_id);
        $this->assertNull($m->sesion_destino_id);
        $this->assertSame($this->admin->id, $m->user_id);
        $this->assertSame('Ubicación verificada por administración.', $m->motivo);
        $this->assertSame(2, $origen->refresh()->version_plano);
        $this->assertSame(2, $destino->refresh()->version_plano);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertOk()->assertJsonPath('data.movimiento_id', $m->id);
        $this->assertDatabaseCount('movimientos', 1);
        $t = TransicionOperacional::where('operacion_id', $datos['operacion_id'])->sole();
        $this->assertGreaterThanOrEqual(2, $t->cantidad_cambios);
        $this->getJson('/api/intervenciones-planificador/pallets?folio='.$f->numero_folio)->assertOk()
            ->assertJsonPath('data.historial.0.motivo', $datos['motivo']);
    }

    public function test_designar_destino_reemplaza_tarea_sin_cambiar_ubicacion_y_lo_fija_para_el_servidor(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $f = $this->folio();
        [, $p1] = $this->camara();
        [$c, $p2] = $this->camara();
        $ubicacion = $this->ocupar($f, $p1);
        $tarea = $this->plan($f)->tareas->first();
        $datos = $this->payload($f, $p2, 'designar_destino');
        $response = $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertOk();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $tarea->refresh()->estado);
        $this->assertSame($p1->id, $ubicacion->refresh()->posicion_id);
        $this->assertDatabaseCount('movimientos', 0);
        $this->assertDatabaseHas('tareas_movimiento', ['id' => $response->json('data.tarea_id'), 'posicion_destino_id' => $p2->id, 'estado' => 'pendiente']);
        $nueva = TareaMovimiento::findOrFail($response->json('data.tarea_id'));
        $this->assertTrue($nueva->contexto['destino_precalculado_inmutable']);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertOk();
        $this->assertSame(1, $f->tareasMovimiento()->where('estado', 'pendiente')->count());
    }

    public function test_liberar_no_borra_la_ubicacion_y_las_versiones_obsoletas_no_cancelan_tareas_nuevas(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $f = $this->folio();
        [, $p] = $this->camara();
        $this->ocupar($f, $p);
        $t = $this->plan($f)->tareas->first();
        $datos = $this->payload($f, null, 'liberar_tareas');
        $t->increment('version');
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertConflict();
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $t->refresh()->estado);
        $datos = $this->payload($f, null, 'liberar_tareas');
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertOk();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $t->refresh()->estado);
        $this->assertSame($p->id, $f->ubicacionActual->posicion_id);
    }

    public function test_no_suelta_movimiento_iniciado_pero_admite_correccion_tras_verificacion_fisica(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $f = $this->folio();
        [, $p1] = $this->camara();
        [, $p2] = $this->camara();
        $this->ocupar($f, $p1);
        $t = $this->plan($f)->tareas->first();
        $t->update(['estado' => 'en_proceso', 'iniciada_at' => now()]);
        $t->maniobraOperacional->update(['estado' => 'en_ejecucion']);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $this->payload($f, null, 'liberar_tareas'))->assertConflict();
        $datos = $this->payload($f, $p2, 'corregir_ubicacion');
        $datos['confirmacion_fisica'] = false;
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertUnprocessable();
        $datos['confirmacion_fisica'] = true;
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertOk();
        $this->assertSame(EstadoTareaMovimiento::Cancelada, $t->refresh()->estado);
        $this->assertSame($p2->id, $f->refresh()->ubicacionActual->posicion_id);
    }

    public function test_destino_ocupado_fuera_de_servicio_o_reservado_rechaza_sin_cancelar_la_tarea(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $f = $this->folio();
        [, $p1] = $this->camara();
        [, $p2] = $this->camara();
        $this->ocupar($f, $p1);
        $t = $this->plan($f)->tareas->first();
        $otra = $this->ocupar($this->folio(), $p2);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $this->payload($f, $p2))->assertConflict();
        $otra->delete();
        $p2->update(['estado' => 'fuera_servicio']);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $this->payload($f, $p2))->assertConflict();
        $p2->update(['estado' => 'activa']);
        $this->plan($this->folio(), $p2);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $this->payload($f, $p2))->assertConflict();
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $t->refresh()->estado);
        $this->assertDatabaseCount('movimientos', 0);
    }

    public function test_prioridad_por_camara_supera_afinidad_y_respeta_capacidad_y_version(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        [$c, $p] = $this->camara();
        [$otra, $op] = $this->camara(2);
        $f = $this->folio();
        $af = $this->folio();
        $this->ocupar($af, $op);
        $url = '/api/configuracion/camaras/'.$c->id.'/prioridad-operacional';
        $datos = ['operacion_id' => (string) Str::uuid(), 'version_prioridad' => 1, 'prioridad_operacional' => 'almacenamiento', 'motivo' => 'Cámara principal de almacenamiento.'];
        $this->patchJson($url, $datos)->assertOk()->assertJsonPath('data.anterior', 'general')->assertJsonPath('data.version_prioridad', 2);
        $this->patchJson($url, $datos)->assertOk()->assertJsonPath('data.version_prioridad', 2);
        $this->patchJson($url, [...$datos, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $r = app(ServicioRecomendacionUbicacion::class)->recomendar($f, $this->admin);
        $this->assertSame($c->id, $r['mejor']['camara']['id']);
        $this->ocupar($this->folio(), $p);
        $r = app(ServicioRecomendacionUbicacion::class)->recomendar($f, $this->admin);
        $this->assertSame($otra->id, $r['mejor']['camara']['id']);
    }

    public function test_salida_de_tunel_elige_su_camara_prioritaria(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        config(['planificador.mode' => 'guided', 'planificador.compute' => 'server', 'planificador.generacion_automatica' => true]);
        [$c, $p] = $this->camara();
        $c->update(['prioridad_operacional' => 'salida_tuneles']);
        [$otra] = $this->camara();
        $otra->update(['prioridad_operacional' => 'almacenamiento']);
        $f = $this->folio();
        $plan = $this->plan($f, tipo: TipoPlanOperacional::RecepcionTunel);
        $t = $plan->tareas->first();
        app(ServicioDestinosServidor::class)->preparar($t->maniobraOperacional, $this->admin);
        $this->assertSame($c->id, $t->refresh()->camara_destino_id);
    }

    public function test_no_interviene_custodia_temporal_ni_reutiliza_el_uuid_para_otro_destino(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $f = $this->folio();
        [, $p1] = $this->camara();
        [, $p2] = $this->camara();
        $this->ocupar($f, $p1);
        $t = $this->plan($f)->tareas->first();
        $device = Dispositivo::create(['codigo' => 'TABLET-CUSTODIA-ADMIN', 'nombre' => 'Tablet']);
        CustodiaTemporalManiobra::create(['maniobra_operacional_id' => $t->maniobra_operacional_id, 'folio_id' => $f->id,
            'tarea_extraccion_id' => $t->id, 'camara_origen_id' => $p1->camara_id, 'posicion_origen_id' => $p1->id,
            'banda_origen' => 1, 'posicion_origen' => 1, 'nivel_origen' => 1, 'estado' => 'activa', 'bloqueo_folio_id' => $f->id,
            'user_id' => $this->admin->id, 'dispositivo_id' => $device->id, 'extraido_at' => now()]);
        $datos = $this->payload($f, $p2);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$f->id, $datos)->assertConflict();
        $this->assertSame(EstadoTareaMovimiento::Pendiente, $t->refresh()->estado);
        $this->assertDatabaseCount('movimientos', 0);
        $otro = $this->folio();
        [, $p3] = $this->camara();
        $datos = $this->payload($otro, $p3);
        $this->postJson('/api/intervenciones-planificador/pallets/'.$otro->id, $datos)->assertOk();
        $this->postJson('/api/intervenciones-planificador/pallets/'.$otro->id, [...$datos, 'motivo' => 'Otro motivo con el mismo identificador.'])->assertConflict();
        $this->assertDatabaseCount('movimientos', 1);
    }

    private function folio(): Folio
    {
        return Folio::create(['numero_folio' => 'ADMIN-'.(++$this->indice), 'tipo_bulto' => TipoBulto::Pallet, 'estado_operacional' => 'disponible',
            'fecha_ingreso' => now(), 'activo' => true, 'exportadora' => 'Cliente común', 'marca' => 'Marca', 'datos_externos' => ['envase' => 'Caja 5 kg']]);
    }

    private function camara(int $posiciones = 1): array
    {
        $c = Camara::create(['codigo' => 'CAM-ADMIN-'.(++$this->indice), 'nombre' => 'Cámara '.$this->indice, 'tipo' => 'transito', 'contenido' => 'productos',
            'estado' => 'activa', 'version_plano' => 1, 'cantidad_bandas' => 1, 'posiciones_por_banda' => $posiciones, 'cantidad_niveles' => 1]);
        BandaOperacional::create(['camara_id' => $c->id, 'numero' => 1, 'modo' => 'operativa', 'usos_permitidos' => ['transito_pt'], 'version' => 1]);
        for ($n = 1; $n <= $posiciones; $n++) {
            $p = Posicion::create(['camara_id' => $c->id, 'banda' => 1, 'posicion' => $n, 'nivel' => 1, 'etiqueta' => 'B01-P0'.$n.'-N1', 'estado' => 'activa']);
        }

        return [$c, $p];
    }

    private function ocupar(Folio $f, Posicion $p): UbicacionActual
    {
        return UbicacionActual::create(['folio_id' => $f->id, 'camara_id' => $p->camara_id, 'posicion_id' => $p->id, 'ubicado_at' => now()]);
    }

    private function plan(Folio $f, ?Posicion $destino = null, TipoPlanOperacional $tipo = TipoPlanOperacional::AlmacenamientoPallet)
    {
        $u = $f->ubicacionActual;

        return app(ServicioPlanesOperacionales::class)->crear($f->temporada, $tipo, 'Pallet de prueba', $this->admin, [[
            'folio_id' => $f->id, 'tipo_movimiento' => $u ? TipoMovimiento::Reubicacion : TipoMovimiento::UbicacionInicial,
            'camara_origen_id' => $u?->camara_id, 'posicion_origen_id' => $u?->posicion_id,
            'camara_destino_id' => $destino?->camara_id ?? $u?->camara_id, 'posicion_destino_id' => $destino?->id,
        ]], PrioridadOperacional::Normal);
    }

    private function payload(Folio $f, ?Posicion $p, string $accion = 'corregir_ubicacion'): array
    {
        $data = $this->getJson('/api/intervenciones-planificador/pallets?folio='.$f->numero_folio)->assertOk()->json('data');

        return ['accion' => $accion, 'operacion_id' => (string) Str::uuid(), 'revision' => $data['revision'], 'motivo' => 'Ubicación verificada por administración.',
            ...($p ? ['camara_id' => $p->camara_id, 'banda' => $p->banda, 'posicion' => $p->posicion, 'nivel' => $p->nivel,
                'version_plano_destino' => $p->camara->version_plano, 'confirmacion_fisica' => true] : [])];
    }
}
