<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Captura\ServicioCapturaHechos;
use App\Finanzas\Models\CargoAcordado;
use App\Finanzas\Models\Hecho;
use App\Finanzas\Models\Lectura;
use App\Finanzas\Models\Moneda;
use App\Finanzas\Periodo;
use App\Finanzas\Prefactura\CalculadoraPrefactura;
use App\Finanzas\Tarifario\GestorTarifas;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Rrhh\Models\AsignacionPersonal;
use App\Rrhh\Models\Marcaje;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DotacionFinanzasTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [null, 'finanzas', 'rrhh'];

    private User $admin;

    private string $cliente;

    private string $servicio;

    private string $asignacion;

    protected function setUp(): void
    {
        parent::setUp();
        config(['rrhh.conexion_lectura' => config('database.default'), 'finanzas.conexion_lectura' => config('database.default'), 'finanzas.conexion_rrhh' => 'rrhh']);
        $this->travelTo(CarbonImmutable::parse('2026-12-05T12:00:00Z'));
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->actingAs($this->admin, 'sanctum');
        $this->cliente = (string) Str::uuid();
        DB::table('clientes')->insert(['id' => $this->cliente, 'codigo' => 'DOTACION', 'nombre' => 'Cliente dotación', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        $persona = $this->postJson('/api/rrhh/personas', ['codigo' => 'RR01', 'nombre' => 'Nombre privado de prueba', 'documento' => 'DOCUMENTO_PRIVADO', 'activa' => true, 'fuente_marcaje' => 'folios', 'pin' => '345678'])->assertCreated()->json('data.id');
        $this->asignacion = $this->postJson('/api/rrhh/asignaciones', ['operacion_id' => (string) Str::uuid(), 'persona_id' => $persona, 'cliente_id' => $this->cliente,
            'cargo' => 'Camarero', 'area' => 'Frigorífico', 'ceco' => 'FRIO', 'desde' => '2026-11-01T22:00:00-03:00', 'hasta' => '2026-11-02T06:00:00-03:00', 'descanso_programado_minutos' => 0])->assertCreated()->json('data.id');
        Moneda::query()->create(['codigo' => 'CLP', 'nombre' => 'Peso chileno', 'simbolo' => '$', 'decimales' => 0, 'activa' => true]);
        $this->servicio = $this->postJson('/api/finanzas/servicios', ['nombre' => 'Dotación contratada', 'fuente' => 'declarado', 'aplicacion' => 'declarado', 'unidad_libre' => 'hora persona', 'proceso_referencia' => 'RRHH', 'vigente_desde' => '2026-11-01', 'dia_desde' => 1])->assertCreated()->json('data.clave');
        app(GestorTarifas::class)->crear(['cliente_id' => null, 'servicio' => $this->servicio, 'unidad' => 'cantidad', 'moneda' => 'CLP', 'precio' => '3.5', 'vigente_desde' => '2026-11-01', 'cerrar_anterior' => false], $this->admin);
    }

    public function test_lleva_programacion_sin_asistencia_y_cancelar_rrhh_no_anula_un_cargo_aprobado(): void
    {
        $datos = $this->datos();
        $id = $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertCreated()->assertJsonPath('data.cantidad', '8.000000')->json('data.id');
        $this->assertSame(0, Hecho::query()->count());
        $this->postJson('/api/finanzas/cargos/'.$id.'/aprobar')->assertOk();
        $this->assertSame('28', $this->total());
        $this->patchJson('/api/rrhh/asignaciones/'.$this->asignacion.'/estado', ['version' => 1, 'estado' => 'cancelada', 'motivo' => 'Reserva de dotación cancelada por cliente.'])->assertOk();
        app(ServicioCapturaHechos::class)->capturar(Lectura::ORIGEN_MANUAL, $this->admin, ['declarado']);
        $this->assertSame('28', $this->total());
        $this->assertNull(CargoAcordado::query()->findOrFail($id)->anulado_at);
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertUnprocessable();
        $this->assertSame(1, CargoAcordado::query()->count());
    }

    public function test_un_mismo_concepto_y_asignacion_no_cobran_dos_veces_ni_programado_y_asistido(): void
    {
        $datos = $this->datos();
        $id = $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/finanzas/dotacion/cargos', [...$datos, 'medida' => 'jornadas_programadas'])->assertUnprocessable();
        $this->asistencia();
        $this->postJson('/api/finanzas/dotacion/cargos', [...$datos, 'medida' => 'horas_asistidas', 'base_cobro' => 'ejecucion'])->assertUnprocessable();
        $this->assertSame(1, CargoAcordado::query()->count());
    }

    public function test_la_asistencia_exige_marcajes_completos_y_turno_terminado(): void
    {
        $datos = $this->datos(['medida' => 'horas_asistidas', 'base_cobro' => 'ejecucion']);
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertUnprocessable()->assertJsonValidationErrors('medida');
        $this->asistencia();
        $this->travelTo(CarbonImmutable::parse('2026-11-02T08:30:00Z'));
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertUnprocessable()->assertJsonValidationErrors('medida');
        $this->travelTo(CarbonImmutable::parse('2026-12-05T12:00:00Z'));
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertCreated()->assertJsonPath('data.cantidad', '8.000000')->assertJsonPath('data.estado_ejecucion', 'realizado');
        $this->postJson('/api/finanzas/dotacion/cargos', $this->datos(['base_cobro' => 'ejecucion']))->assertUnprocessable();
    }

    public function test_corregir_asistencia_no_cambia_el_cargo_y_reemplazar_exige_anulacion_y_confirmacion(): void
    {
        $this->asistencia();
        $datos = $this->datos(['medida' => 'horas_asistidas', 'base_cobro' => 'ejecucion']);
        $id = $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertCreated()->json('data.id');
        $this->postJson('/api/finanzas/cargos/'.$id.'/aprobar')->assertOk();
        $salida = Marcaje::query()->where('tipo', 'salida')->firstOrFail();
        $this->postJson('/api/rrhh/marcajes/'.$salida->id.'/corregir', ['motivo' => 'Salida corregida con respaldo.', 'tipo' => 'salida', 'instante' => '2026-11-02T05:00:00-03:00'])->assertOk();
        $this->assertSame('8.000000', CargoAcordado::query()->findOrFail($id)->cantidad);
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertUnprocessable();
        $this->postJson('/api/finanzas/cargos/'.$id.'/anular', ['motivo' => 'Se revisó la corrección y corresponde reemplazar el cargo.'])->assertOk();
        $this->postJson('/api/finanzas/dotacion/cargos', $datos)->assertUnprocessable()->assertJsonValidationErrors('reemplaza_cargo_id');
        $nuevo = $this->postJson('/api/finanzas/dotacion/cargos', [...$datos, 'reemplaza_cargo_id' => $id])->assertCreated()->assertJsonPath('data.cantidad', '7.000000')->assertJsonPath('data.contexto.rrhh.revision', 2)->json('data.id');
        $this->postJson('/api/finanzas/dotacion/cargos', [...$datos, 'reemplaza_cargo_id' => $id])->assertCreated()->assertJsonPath('data.id', $nuevo);
        $this->assertNotSame($id, $nuevo);
        $this->assertSame(2, CargoAcordado::query()->count());
    }

    public function test_finanzas_lee_solo_asignaciones_y_marcas_y_no_copia_fichas_ni_costos_internos(): void
    {
        $consultas = [];
        DB::listen(function (QueryExecuted $q) use (&$consultas): void {
            if ($q->connectionName === 'rrhh') {
                $consultas[] = $q->sql;
            }
        });
        $respuesta = $this->getJson('/api/finanzas/dotacion?mes=2026-11&servicio='.$this->servicio)->assertOk();
        foreach (['Nombre privado', 'DOCUMENTO_PRIVADO', 'pin_hash', 'persona_id', 'costo_hora', 'configuracion'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $respuesta->getContent());
        }
        $this->postJson('/api/finanzas/dotacion/cargos', $this->datos())->assertCreated();
        $this->assertNotEmpty($consultas);
        foreach ($consultas as $sql) {
            $this->assertStringStartsWith('select', strtolower($sql));
            $this->assertStringNotContainsString('rh_personas', $sql);
        }
        $this->assertStringNotContainsString('DOCUMENTO_PRIVADO', json_encode(CargoAcordado::query()->get()));
    }

    public function test_requiere_permisos_de_finanzas_y_consulta_rrhh_y_confirmar_unidad(): void
    {
        $this->postJson('/api/finanzas/dotacion/cargos', $this->datos(['confirmar_unidad' => false]))->assertUnprocessable();
        $this->actingAs($this->perfil(['finanzas.prefactura']), 'sanctum')->getJson('/api/finanzas/dotacion?mes=2026-11')->assertForbidden();
        $this->actingAs($this->perfil(['finanzas.prefactura', 'rrhh.personal'], RolUsuario::Consulta), 'sanctum');
        $this->getJson('/api/finanzas/dotacion?mes=2026-11')->assertOk();
        $this->postJson('/api/finanzas/dotacion/cargos', $this->datos())->assertForbidden();
    }

    public function test_el_ultimo_dia_del_mes_y_el_cruce_de_mes_se_incluyen_sin_duplicar_cargos(): void
    {
        AsignacionPersonal::query()->where('id', $this->asignacion)->update(['desde_at' => '2026-12-01 01:00:00', 'hasta_at' => '2026-12-01 09:00:00']);
        $this->getJson('/api/finanzas/dotacion?mes=2026-11')->assertOk()->assertJsonCount(1, 'data.data');
        $id = $this->postJson('/api/finanzas/dotacion/cargos', $this->datos())->assertCreated()->json('data.id');
        $this->getJson('/api/finanzas/dotacion?mes=2026-12&servicio='.$this->servicio)->assertOk()->assertJsonPath('data.data.0.cargo_actual.id', $id);
    }

    private function datos(array $cambios = []): array
    {
        return ['asignacion_id' => $this->asignacion, 'servicio' => $this->servicio, 'medida' => 'horas_programadas', 'fecha' => '2026-11-02', 'base_cobro' => 'compromiso', 'respaldo' => 'Reserva de dotación según contrato de servicios.', 'confirmar_unidad' => true, ...$cambios];
    }

    private function asistencia(): void
    {
        foreach (['entrada' => '2026-11-02T01:00:00Z', 'salida' => '2026-11-02T09:00:00Z'] as $tipo => $fecha) {
            $this->travelTo(CarbonImmutable::parse($fecha));
            $this->postJson('/api/rrhh/marcajes', ['codigo' => 'RR01', 'pin' => '345678', 'tipo' => $tipo, 'operacion_id' => (string) Str::uuid()])->assertCreated();
        }
        $this->travelTo(CarbonImmutable::parse('2026-12-05T12:00:00Z'));
    }

    private function total(): string
    {
        return app(CalculadoraPrefactura::class)->calcular(Periodo::mes('2026-11'))['totales'][0]['monto'];
    }

    private function perfil(array $modulos, RolUsuario $rol = RolUsuario::Administrador): User
    {
        $p = PerfilAcceso::query()->create(['codigo' => 'DOT_'.Str::upper(Str::random(6)), 'nombre' => 'Perfil dotación', 'rol_base' => $rol, 'modulos' => $modulos, 'modulos_tablet' => [], 'activo' => true, 'predeterminado' => false, 'protegido' => false]);

        return User::factory()->create(['rol' => $rol, 'perfil_acceso_id' => $p->id, 'activo' => true]);
    }
}
