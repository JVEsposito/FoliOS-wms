<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\ManiobraOperacional;
use App\Models\PlanOperacional;
use App\Models\PresenciaCamarero;
use App\Models\TareaMovimiento;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DisponibilidadCamarerosApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_latido_y_final_de_ultima_labor_producen_un_solo_aviso_y_el_operador_permanece_visible(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $presencia = PresenciaCamarero::sole();
        $this->assertSame('disponible', $presencia->estado);
        $this->assertNull($presencia->aviso_disponible_id);
        $tarea = $this->tarea($c);
        $this->assertSame('trabajando', $presencia->refresh()->estado);
        DB::transaction(fn () => $tarea->update(['estado' => 'completada', 'completada_at' => now()]));
        $this->assertSame('disponible', $presencia->refresh()->estado);
        $aviso = $presencia->aviso_disponible_id;
        $this->assertNotNull($aviso);
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $this->assertSame($aviso, $presencia->refresh()->aviso_disponible_id);
        $this->consulta($c)->assertJsonPath('data.disponibles', 1)->assertJsonCount(1, 'data.camareros')
            ->assertJsonPath('data.camareros.0.estado_presencia', 'disponible')->assertJsonPath('data.camareros.0.aviso_disponible_id', $aviso);
    }

    public function test_otras_labores_del_mismo_usuario_en_otra_tablet_impiden_anunciarlo_libre(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $a = $this->tarea($c);
        $b = $this->tarea($c);
        $b->update(['dispositivo_id' => Dispositivo::create(['codigo' => 'OTRO', 'nombre' => 'Otra tablet', 'activo' => true])->id]);
        $a->update(['estado' => 'completada', 'completada_at' => now()]);
        $this->assertSame('trabajando', PresenciaCamarero::sole()->estado);
        $this->consulta($c)->assertJsonPath('data.disponibles', 0);
    }

    public function test_maniobra_pausada_o_con_pasos_pendientes_no_se_confunde_con_disponibilidad(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $tarea = $this->tarea($c);
        $maniobra = ManiobraOperacional::create(['plan_operacional_id' => $tarea->plan_operacional_id,
            'creado_por_user_id' => $c['supervisor']->id, 'responsable_user_id' => $c['usuario']->id,
            'dispositivo_id' => $c['dispositivo']->id, 'estado' => 'pausada_discrepancia',
            'candidate_key' => (string) Str::uuid(), 'costo_movimientos' => 1, 'titulo' => 'Labor pausada']);
        $this->consulta($c)->assertJsonPath('data.camareros.0.estado_presencia', 'esperando');
        $tarea->update(['estado' => 'completada']);
        $this->assertSame('esperando', PresenciaCamarero::sole()->estado);
        $this->consulta($c)->assertJsonPath('data.disponibles', 0)->assertJsonPath('data.camareros.0.estado_presencia', 'esperando');
        $maniobra->update(['estado' => 'completada']);
        $this->assertSame('disponible', PresenciaCamarero::sole()->estado);
    }

    public function test_ausencia_de_latidos_y_cierre_de_sesion_quitan_la_disponibilidad_sin_escrituras_desde_el_get(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $antes = PresenciaCamarero::sole()->updated_at;
        $this->travel(80)->seconds();
        $this->consulta($c)->assertJsonPath('data.disponibles', 0)->assertJsonPath('data.camareros.0.estado_presencia', 'desconectado');
        $this->assertTrue($antes->equalTo(PresenciaCamarero::sole()->updated_at));
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $this->token($c['token'])->deleteJson('/api/acceso-tablet')->assertNoContent();
        $this->consulta($c)->assertJsonPath('data.disponibles', 0)->assertJsonPath('data.camareros.0.estado_presencia', 'desconectado');
    }

    public function test_permisos_y_temporadas_no_exponen_personal_ni_permiten_latidos_ajenos(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->getJson('/api/operacion-ahora/camareros')->assertForbidden();
        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta]);
        $soloConsulta = $consulta->crearTokenParaDispositivo($c['dispositivo'], 'consulta')->plainTextToken;
        $this->token($soloConsulta)->postJson('/api/operacion/camareros/presencia')->assertForbidden();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia', ['user_id' => $consulta->id])->assertNoContent();
        $this->assertDatabaseHas('presencias_camareros', ['user_id' => $c['usuario']->id]);
        $this->assertDatabaseMissing('presencias_camareros', ['user_id' => $consulta->id]);
        $c['temporada']->update(['activa' => false]);
        Temporada::create(['codigo' => 'OTRA', 'nombre' => 'Otra', 'fecha_inicio' => '2026-10-01', 'activa' => true]);
        $this->consulta($c)->assertJsonCount(0, 'data.camareros');
    }

    public function test_un_rollback_no_anuncia_disponibilidad_ni_pierde_la_labor(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $tarea = $this->tarea($c);
        try {
            DB::transaction(function () use ($tarea): void {
                $tarea->update(['estado' => 'completada']);
                throw new \RuntimeException('Revertir prueba');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('asumida', $tarea->refresh()->estado->value);
        $this->assertSame('trabajando', PresenciaCamarero::sole()->estado);
        $this->assertNull(PresenciaCamarero::sole()->aviso_disponible_id);
        $this->consulta($c)->assertJsonPath('data.disponibles', 0);
    }

    public function test_la_lectura_no_declara_libre_un_estado_cacheado_si_existe_otra_tarea_actual(): void
    {
        $c = $this->contexto();
        $this->token($c['token'])->postJson('/api/operacion/camareros/presencia')->assertNoContent();
        $this->tarea($c);
        PresenciaCamarero::query()->update(['estado' => 'disponible', 'disponible_desde' => now(), 'aviso_disponible_id' => (string) Str::uuid()]);
        $this->consulta($c)->assertJsonPath('data.disponibles', 0)->assertJsonPath('data.camareros.0.estado_presencia', 'trabajando')
            ->assertJsonPath('data.camareros.0.aviso_disponible_id', null);
    }

    private function contexto(): array
    {
        Temporada::query()->update(['activa' => false]);
        $temporada = Temporada::create(['codigo' => 'PRES', 'nombre' => 'Presencia', 'fecha_inicio' => '2026-09-01', 'activa' => true]);
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio, 'activo' => true]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio, 'activo' => true]);
        $dispositivo = Dispositivo::create(['codigo' => 'PRES-01', 'nombre' => 'Tablet presencia', 'activo' => true]);
        $token = $usuario->crearTokenParaDispositivo($dispositivo, 'presencia')->plainTextToken;
        $tokenOficina = $supervisor->createToken('oficina', ['oficina'])->plainTextToken;

        return compact('temporada', 'usuario', 'supervisor', 'dispositivo', 'token', 'tokenOficina');
    }

    private function tarea(array $c): TareaMovimiento
    {
        $plan = PlanOperacional::create(['temporada_id' => $c['temporada']->id, 'tipo' => 'almacenamiento_pallet',
            'estado' => 'en_ejecucion', 'titulo' => 'Labor', 'programado_at' => now(), 'creado_por_user_id' => $c['supervisor']->id]);
        $folio = Folio::create(['temporada_id' => $c['temporada']->id, 'numero_folio' => 'PRES-'.Str::random(8), 'tipo_bulto' => 'pallet', 'fecha_ingreso' => now()]);

        return TareaMovimiento::create(['plan_operacional_id' => $plan->id, 'folio_id' => $folio->id, 'tipo_movimiento' => 'retiro',
            'estado' => 'asumida', 'responsable_user_id' => $c['usuario']->id, 'dispositivo_id' => $c['dispositivo']->id, 'secuencia' => 1]);
    }

    private function token(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    private function consulta(array $c)
    {
        return $this->token($c['tokenOficina'])->getJson('/api/operacion-ahora/camareros')->assertOk();
    }
}
