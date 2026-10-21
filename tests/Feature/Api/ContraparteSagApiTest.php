<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\CondicionSag;
use App\Models\Folio;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\InspeccionSag\ServicioExistenciasSag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContraparteSagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_corte_idempotente_y_salida_conservan_historial_sin_duplicar_por_destino(): void
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $condicion = CondicionSag::create(['codigo' => 'AO-TEST', 'nombre' => 'Aprobado origen', 'activo' => true]);
        $folio = Folio::create(['numero_folio' => '00000123', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'disponible',
            'condicion_sag_id' => $condicion->id, 'activo' => true, 'fecha_ingreso' => now(),
            'exportadora' => 'Cliente', 'datos_externos' => ['especie' => 'UVA', 'cantidad_cajas' => 180]]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/contraparte-sag/existencias/corte-inicial')->assertOk();
        $this->postJson('/api/contraparte-sag/existencias/corte-inicial')->assertOk();
        $this->assertDatabaseCount('movimientos_existencia_sag', 1);
        $this->getJson('/api/contraparte-sag/existencias')->assertOk()->assertJsonPath('cajas', 180)->assertJsonPath('unidades', 1)
            ->assertJsonPath('folios.data.0.snapshot.folio', '00000123');
        $folio->update(['activo' => false, 'estado_operacional' => 'despachado']);
        app(ServicioExistenciasSag::class)->sincronizar([$folio->id], 'despacho', 'CARGA-1');
        $this->getJson('/api/contraparte-sag/existencias')->assertJsonPath('cajas', 0)->assertJsonPath('unidades', 0);
        $this->assertDatabaseHas('movimientos_existencia_sag', ['referencia' => 'CARGA-1', 'variacion_cajas' => -180, 'variacion_unidades' => -1]);
    }

    public function test_lectura_no_escribe_y_consulta_no_puede_mutar(): void
    {
        $perfil = PerfilAcceso::create(['codigo' => 'CONSULTA-SAG', 'nombre' => 'Consulta SAG', 'rol_base' => 'consulta',
            'activo' => true, 'modulos' => ['frigorifico.contraparte-sag'], 'modulos_tablet' => []]);
        $consulta = User::factory()->create(['rol' => 'consulta', 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($consulta, 'sanctum')->getJson('/api/contraparte-sag/existencias')->assertOk();
        $this->assertDatabaseCount('saldos_existencia_sag', 0);
        $this->postJson('/api/contraparte-sag/existencias/corte-inicial')->assertForbidden();
        $this->putJson('/api/contraparte-sag/configuracion', [])->assertForbidden();
    }

    public function test_perfil_inactivo_y_sin_modulo_no_ingresan(): void
    {
        $perfil = PerfilAcceso::where('codigo', 'CONTRAPARTE_SAG')->firstOrFail();
        $usuario = User::factory()->create(['rol' => 'supervisor_frio', 'perfil_acceso_id' => $perfil->id]);
        $this->actingAs($usuario, 'sanctum')->getJson('/api/contraparte-sag/configuracion')->assertOk();
        $perfil->update(['activo' => false]);
        $usuario->unsetRelation('perfilAcceso');
        $this->getJson('/api/contraparte-sag/configuracion')->assertForbidden();
    }

    public function test_configuracion_versionada_y_responsable_tienen_auditoria(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'administrador']), 'sanctum');
        $datos = ['version' => 0, 'nombre_establecimiento' => 'Planta prueba', 'csp' => '000123',
            'region' => 'O’Higgins', 'provincia' => 'Cachapoal', 'comuna' => 'Rengo', 'oficina_sag' => 'Rengo'];
        $this->putJson('/api/contraparte-sag/configuracion', $datos)->assertOk()->assertJsonPath('version', 1);
        $this->putJson('/api/contraparte-sag/configuracion', $datos)->assertConflict();
        $r = $this->postJson('/api/contraparte-sag/responsables', ['version' => 0, 'nombre' => 'Responsable',
            'rut' => '1-9', 'cargo' => 'tecnica', 'referencia_autorizacion' => 'Curso y autorización de prueba', 'activo' => true])
            ->assertCreated()->assertJsonPath('version', 1)->json();
        $this->putJson('/api/contraparte-sag/responsables/'.$r['id'], [...$r, 'version' => 0])->assertConflict();
        $this->assertSame(2, DB::table('eventos_contraparte_sag')->count());
    }

    public function test_sin_temporada_activa_se_rechaza_corte(): void
    {
        $this->desactivarTemporadasDePrueba();
        $this->actingAs(User::factory()->create(['rol' => 'administrador']), 'sanctum')
            ->postJson('/api/contraparte-sag/existencias/corte-inicial')->assertUnprocessable();
    }
}
