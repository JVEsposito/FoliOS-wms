<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\EventoLoteMateriaPrima;
use App\Models\LineaProceso;
use App\Models\LoteMateriaPrima;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;

class NumeroLoteMateriaPrimaTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function correccion(array $lote, string $numero): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $lote['version'], 'numero_lote' => $numero,
            'motivo' => 'Número informado incorrectamente en Digitación.'];
    }

    public function test_impide_crear_el_mismo_numero_con_otro_cliente_y_normaliza_mayusculas_y_espacios(): void
    {
        $primero = $this->loteMpEnCamara('UNO', ['numero_lote' => ' ab-10056 ']);
        $segundo = $this->loteMpEnCamara('DOS', ['numero_lote' => 'AB-10056'], prepararSinLote: true);
        $this->admin();
        $this->postJson('/api/materia-prima/lotes', $segundo['payload_lote'])->assertUnprocessable()->assertJsonValidationErrors('numero_lote');
        $this->assertSame('AB-10056', $primero['lote']['numero_lote']);
        $this->assertDatabaseCount('lotes_materia_prima', 1);
        $this->postJson('/api/materia-prima/lotes', [...$segundo['payload_lote'], 'numero_lote' => '10057'])->assertCreated();
    }

    public function test_corrige_un_duplicado_historico_por_id_sin_cambiar_el_otro_lote_ni_su_ejecucion(): void
    {
        $primero = $this->loteMpEnCamara('LA', ['numero_lote' => '10056']);
        $segundo = $this->loteMpEnCamara('DIS', ['numero_lote' => 'TEMPORAL']);
        $id = $segundo['lote']['id'];
        // Reproduce los datos que permitía el contrato anterior, sin recrear ni
        // eliminar ninguno de los lotes ya asignados a cámara.
        DB::table('lotes_materia_prima')->where('id', $id)->update(['numero_lote' => '10056',
            'clave_numero_vigente' => hash('sha256', $segundo['lote']['temporada']['id'].'|'.$segundo['cliente']->id.'|10056')]);
        $antes = LoteMateriaPrima::findOrFail($id)->getAttributes();
        $otro = LoteMateriaPrima::findOrFail($primero['lote']['id'])->getAttributes();
        $camaras = DB::table('asignaciones_camara_lote_materia_prima')->orderBy('id')->get()->toJson();
        $movimientos = DB::table('movimientos_envases')->orderBy('id')->get()->toJson();
        $admin = $this->admin();
        $ruta = "/api/materia-prima/lotes/{$id}/corregir-numero";
        $datos = $this->correccion($segundo['lote'], ' 10057 ');
        $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.numero_lote', '10057')->assertJsonPath('data.estado', 'asignado_camara');
        $this->putJson($ruta, $datos)->assertOk()->assertJsonPath('data.version', $segundo['lote']['version'] + 1);
        $despues = LoteMateriaPrima::findOrFail($id)->getAttributes();
        foreach (['numero_lote', 'clave_numero_vigente', 'version', 'actualizado_por_user_id', 'updated_at'] as $campo) {
            unset($antes[$campo], $despues[$campo]);
        }
        $this->assertSame($antes, $despues);
        $this->assertSame($otro, LoteMateriaPrima::findOrFail($primero['lote']['id'])->getAttributes());
        $this->assertSame($camaras, DB::table('asignaciones_camara_lote_materia_prima')->orderBy('id')->get()->toJson());
        $this->assertSame($movimientos, DB::table('movimientos_envases')->orderBy('id')->get()->toJson());
        $evento = EventoLoteMateriaPrima::where('operacion_id', $datos['operacion_id'])->sole();
        $this->assertSame('10056', $evento->datos['anterior']);
        $this->assertSame('10057', $evento->datos['nuevo']);
        $this->assertSame($admin->id, $evento->user_id);
        $this->assertSame(1, EventoLoteMateriaPrima::where('tipo', 'numero_lote_corregido')->count());
        $this->putJson($ruta, [...$datos, 'numero_lote' => '10058'])->assertConflict();
        $this->putJson($ruta, $this->correccion($segundo['lote'], '10059'))->assertConflict();
        $this->getJson('/api/materia-prima/fruta-proceso/lotes?buscar=10057')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/materia-prima/lotes?buscar=10056')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $primero['lote']['id']);
    }

    public function test_corrige_un_lote_entregado_sin_cambiar_viajes_ordenes_ni_kilos(): void
    {
        $contexto = $this->loteMpEnCamara('ENT', ['numero_lote' => '10056']);
        $id = $contexto['lote']['id'];
        $this->admin();
        $this->postJson("/api/materia-prima/fruta-proceso/lotes/{$id}/entregas", ['operacion_id' => (string) Str::uuid(),
            'camara_origen_id' => $contexto['camara']->id, 'cantidad_envases' => 48, 'linea_proceso' => 'Línea 1', 'turno' => 'A', 'numero_orden' => 'OP-NUMERO'])->assertOk();
        $lote = $this->getJson("/api/materia-prima/lotes/{$id}")->assertOk()->assertJsonPath('data.estado', 'entregado_proceso')->json('data');
        $entregas = DB::table('entregas_fruta_proceso')->get()->toJson();
        $this->admin();
        $this->putJson("/api/materia-prima/lotes/{$id}/corregir-numero", $this->correccion($lote, '10057'))->assertOk()
            ->assertJsonPath('data.estado', 'entregado_proceso')->assertJsonPath('data.pesos.kilos_netos_confirmados', 18000);
        $this->assertSame($entregas, DB::table('entregas_fruta_proceso')->get()->toJson());
    }

    public function test_solo_administrador_activo_y_no_admite_numero_ocupado_motivo_vacio_o_lote_anulado(): void
    {
        $lote = $this->loteMpEnCamara('PER')['lote'];
        $ocupado = $this->loteMpEnCamara('OCU')['lote'];
        $ruta = "/api/materia-prima/lotes/{$lote['id']}/corregir-numero";
        foreach ([RolUsuario::DigitadorMateriaPrima, RolUsuario::SupervisorFrio, RolUsuario::CamareroFrio, RolUsuario::Consulta] as $rol) {
            $this->actingAs(User::factory()->create(['rol' => $rol]), 'sanctum')->putJson($ruta, $this->correccion($lote, '10057'))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => false]), 'sanctum')
            ->putJson($ruta, $this->correccion($lote, '10057'))->assertForbidden();
        $this->admin();
        $this->putJson($ruta, $this->correccion($lote, $ocupado['numero_lote']))->assertUnprocessable()->assertJsonValidationErrors('numero_lote');
        $this->putJson($ruta, $this->correccion($lote, $lote['numero_lote']))->assertUnprocessable()->assertJsonValidationErrors('numero_lote');
        $this->putJson($ruta, [...$this->correccion($lote, '10057'), 'motivo' => '  '])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        DB::table('lotes_materia_prima')->where('id', $lote['id'])->update(['estado' => 'anulado', 'clave_numero_vigente' => null]);
        $this->putJson($ruta, $this->correccion($lote, '10057'))->assertConflict();
        $this->assertSame(0, EventoLoteMateriaPrima::where('tipo', 'numero_lote_corregido')->count());
    }

    public function test_migracion_conserva_duplicados_historicos_y_reserva_claves_globales_sin_renumerar(): void
    {
        $a = $this->loteMpEnCamara('MA', ['numero_lote' => '10056']);
        $b = $this->loteMpEnCamara('MB', ['numero_lote' => '10057']);
        foreach ([$a, $b] as $contexto) {
            DB::table('lotes_materia_prima')->where('id', $contexto['lote']['id'])->update(['numero_lote' => '10056',
                'clave_numero_vigente' => hash('sha256', $contexto['lote']['temporada']['id'].'|'.$contexto['cliente']->id.'|10056')]);
        }
        $migracion = require database_path('migrations/2026_11_08_120000_unificar_numero_lote_por_temporada.php');
        $migracion->up();
        $migracion->up();
        $this->assertSame(2, LoteMateriaPrima::where('numero_lote', '10056')->count());
        $this->assertSame(1, LoteMateriaPrima::where('clave_numero_vigente', hash('sha256', 'temporada|'.$a['lote']['temporada']['id'].'|10056'))->count());
        $this->admin();
        $this->putJson("/api/materia-prima/lotes/{$b['lote']['id']}/corregir-numero", $this->correccion($b['lote'], '10058'))->assertOk();
        $this->assertDatabaseCount('lotes_materia_prima', 2);
    }

    public function test_el_numero_puede_repetirse_en_otra_temporada_pero_un_alias_no_se_reasigna_al_mismo_cliente(): void
    {
        $primero = $this->loteMpEnCamara('ALIAS', ['numero_lote' => '10056']);
        $this->admin();
        $this->putJson("/api/materia-prima/lotes/{$primero['lote']['id']}/corregir-numero", $this->correccion($primero['lote'], '10057'))->assertOk();
        $pendiente = $this->loteMpEnCamara('ALIAS-NUEVO', ['numero_lote' => '10056'], cliente: $primero['cliente'], prepararSinLote: true);
        $this->admin();
        $this->postJson('/api/materia-prima/lotes', $pendiente['payload_lote'])->assertUnprocessable()->assertJsonValidationErrors('numero_lote');

        $this->crearTemporadaActivaPrueba(['codigo' => 'TEMP-NUM-OTRA', 'nombre' => 'Otra temporada']);
        $otra = $this->loteMpEnCamara('OTRA', ['numero_lote' => '10056']);
        $this->assertNotSame($primero['lote']['temporada']['id'], $otra['lote']['temporada']['id']);
        $this->assertSame('10056', $otra['lote']['numero_lote']);
    }

    public function test_editar_un_borrador_tambien_respeta_la_unicidad_global(): void
    {
        $ocupado = $this->loteMpEnCamara('EDIT-OCU', ['numero_lote' => '10056']);
        $pendiente = $this->loteMpEnCamara('EDIT-NUEVO', ['numero_lote' => '10057'], prepararSinLote: true);
        $this->admin();
        $borrador = $this->postJson('/api/materia-prima/lotes', $pendiente['payload_lote'])->assertCreated()->json('data');
        $ruta = "/api/materia-prima/lotes/{$borrador['id']}";
        $this->putJson($ruta, [...$pendiente['payload_lote'], 'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $borrador['version'], 'numero_lote' => $ocupado['lote']['numero_lote']])->assertUnprocessable()->assertJsonValidationErrors('numero_lote');
        $this->assertDatabaseHas('lotes_materia_prima', ['id' => $borrador['id'], 'numero_lote' => '10057', 'estado' => 'borrador']);
    }

    public function test_orden_publicada_conserva_su_snapshot_y_muestra_el_numero_actual_del_lote(): void
    {
        $lote = $this->loteMpEnCamara('ORD-NUM', ['numero_lote' => '10056'])['lote'];
        $this->admin();
        $orden = $this->postJson('/api/materia-prima/ordenes-proceso', ['operacion_id' => (string) Str::uuid(),
            'linea_proceso_id' => LineaProceso::query()->where('codigo', 'L1')->value('id'), 'fecha' => now()->toDateString(),
            'turno_inicio' => 'A', 'hora_inicio' => '08:00', 'lotes' => [['lote_materia_prima_id' => $lote['id'], 'bins_programados' => 24]]])->assertCreated()->json('data');
        $snapshot = DB::table('lotes_version_orden_mp')->get()->toJson();
        $reservas = DB::table('reservas_lote_orden_proceso_mp')->get()->toJson();
        $this->putJson("/api/materia-prima/lotes/{$lote['id']}/corregir-numero", $this->correccion($lote, '10057'))->assertOk();
        $this->assertSame($snapshot, DB::table('lotes_version_orden_mp')->get()->toJson());
        $this->assertSame($reservas, DB::table('reservas_lote_orden_proceso_mp')->get()->toJson());
        $this->getJson("/api/materia-prima/ordenes-proceso/{$orden['id']}")->assertOk()
            ->assertJsonPath('data.version_vigente.lotes.0.numero_lote', '10056')
            ->assertJsonPath('data.version_vigente.lotes.0.numero_lote_actual', '10057');
    }
}
