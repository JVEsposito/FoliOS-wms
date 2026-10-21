<?php

namespace Tests\Feature\Comex;

use App\Comex\Models\BookingAsignacion;
use App\Comex\Models\BookingDespacho;
use App\Comex\Models\LugarComercial;
use App\Enums\RolUsuario;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingsTest extends TestCase
{
    use PreparaComercial;
    use PreparaOperacionComex;
    use RefreshDatabase;

    private array $nota;

    private array $datos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
        config(['comex.aprobador_distinto' => false]);
        $this->actingAs($this->administradorComex());
        $d = $this->datosNota();
        $this->nota = $this->pasoNota($this->pasoNota($this->postJson('/api/comex/comercial/notas', $d)->assertCreated()->json('data'), 'enviar_revision'), 'aprobar');
        $puertos = [];
        foreach (['CL', 'CN'] as $pais) {
            $puertos[] = LugarComercial::query()->create(['id' => (string) Str::uuid(), 'codigo' => Str::upper(Str::random(8)), 'tipo' => 'puerto', 'pais_iso2' => $pais, 'nombre' => 'Puerto de prueba '.$pais, 'localidad' => 'Localidad de prueba', 'activo' => true, 'version' => 1, 'respaldo' => 'Fuente del puerto verificada']);
        }
        $this->datos = ['operacion_id' => (string) Str::uuid(), 'version' => 0, 'codigo' => 'BK-'.Str::upper(Str::random(8)), 'cliente_operacion_id' => $d['cliente_operacion_id'], 'gestion' => 'servicio', 'naviera_id' => $d['partes']['vendedor'], 'forwarder_id' => $d['partes']['vendedor'], 'origen_id' => $puertos[0]->id, 'destino_id' => $puertos[1]->id, 'nave' => 'Nave de prueba', 'viaje' => 'Viaje ficticio', 'fecha_salida' => '2026-10-20', 'fecha_llegada' => '2026-11-20', 'cutoff_fisico' => '2026-10-18T15:00', 'cutoff_documental' => '2026-10-18T12:00', 'zona_horaria' => 'America/Santiago', 'respaldo' => 'Confirmación de reserva de prueba', 'contenedores' => [['referencia' => 'CN-1', 'numero' => 'MSKU1234567', 'tipo' => '40RH']]];
    }

    private function crear(array $cambios = []): array
    {
        return $this->postJson('/api/comex/comercial/bookings', [...$this->datos, 'operacion_id' => (string) Str::uuid(), 'codigo' => 'BK-'.Str::upper(Str::random(8)), ...$cambios])->assertCreated()->json('data');
    }

    private function solicitud(array $b, string $accion, array $extra = []): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'version' => $b['booking']['version'], 'accion' => $accion, 'motivo' => 'Acción de reserva documentada de prueba', ...$extra];
    }

    private function paso(array $b, string $accion, array $extra = []): array
    {
        return $this->postJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones', $this->solicitud($b, $accion, $extra))->assertOk()->json('data');
    }

    private function asignacion(array $b, string $cantidad): array
    {
        return ['nota_id' => $this->nota['nota']['id'], 'linea_id' => $this->nota['aprobada']['datos']['lineas'][0]['id'], 'contenedor_id' => $b['contenedores'][0]['id'], 'cantidad' => $cantidad];
    }

    public function test_reparto_parcial_entre_bookings_no_supera_saldo_decimal_y_reintento_no_duplica(): void
    {
        $b = $this->crear();
        $otro = $this->crear();
        $d = $this->solicitud($b, 'asignar', $this->asignacion($b, '50.125'));
        $url = '/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones';
        $b = $this->postJson($url, $d)->assertOk()->json('data');
        $this->postJson($url, $d)->assertOk()->assertJsonCount(1, 'data.asignaciones');
        $this->paso($otro, 'asignar', $this->asignacion($otro, '69.875'));
        $this->postJson($url, $this->solicitud($b, 'asignar', $this->asignacion($b, '0.000001')))->assertUnprocessable()->assertJsonValidationErrors('cantidad');
        $this->getJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/notas')->assertOk()->assertJsonPath('data.data.0.lineas.0.saldo', '0.000000');
        $this->assertSame(2, BookingAsignacion::query()->count());
    }

    public function test_retira_asignacion_sin_borrar_historia_y_libera_saldo(): void
    {
        $b = $this->crear();
        $b = $this->paso($b, 'asignar', $this->asignacion($b, '120'));
        $b = $this->paso($b, 'retirar_asignacion', ['asignacion_id' => $b['asignaciones'][0]['id']]);
        $this->assertFalse($b['asignaciones'][0]['activa']);
        $b = $this->paso($b, 'asignar', $this->asignacion($b, '120'));
        $this->assertCount(2, $b['asignaciones']);
    }

    public function test_reserva_impide_sustituir_o_anular_nota_y_editar_confirmada(): void
    {
        $b = $this->crear();
        $b = $this->paso($b, 'asignar', $this->asignacion($b, '40'));
        $n = $this->pasoNota($this->pasoNota($this->nota, 'nueva_revision'), 'enviar_revision');
        foreach (['aprobar', 'anular'] as $accion) {
            $this->postJson('/api/comex/comercial/notas/'.$n['nota']['id'].'/transiciones', ['operacion_id' => (string) Str::uuid(), 'version' => $n['nota']['version'], 'accion' => $accion, 'motivo' => 'Intento de reemplazo con cantidades reservadas'])->assertUnprocessable()->assertJsonValidationErrors('estado');
        }
        $b = $this->paso($b, 'confirmar');
        $this->putJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/borrador', [...$this->datos, 'version' => $b['booking']['version'], 'motivo' => 'Cambio de una reserva confirmada'])->assertUnprocessable();
    }

    public function test_despacho_solo_lee_operacion_conserva_copia_y_cierra_con_salida_real(): void
    {
        $b = $this->crear();
        $b = $this->paso($this->paso($b, 'asignar', $this->asignacion($b, '120')), 'confirmar');
        $e = $this->embarque($this->datos['cliente_operacion_id'], '2026-10-20', atributos: ['contenedor' => 'MSKU1234567']);
        $carga = $this->cargaParaEmbarque($e);
        $sqls = [];
        DB::listen(function (QueryExecuted $q) use (&$sqls): void {
            if ($q->connectionName === config('database.default')) {
                $sqls[] = $q->sql;
            }
        });
        $b = $this->paso($b, 'vincular', ['contenedor_id' => $b['contenedores'][0]['id'], 'embarque_id' => $e]);
        foreach ($sqls as $sql) {
            $this->assertMatchesRegularExpression('/^select\s/i', trim($sql));
        }
        $this->assertFalse($b['vinculos'][0]['cambio_operacion']);
        $this->postJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones', $this->solicitud($b, 'cerrar'))->assertUnprocessable();
        DB::table('cargas')->where('id', $carga)->update(['estado' => 'despachada']);
        $b = $this->paso($b, 'cerrar');
        $this->assertSame('cerrado', $b['booking']['estado']);
        $this->assertTrue($b['vinculos'][0]['cambio_operacion']);
        $this->assertFalse($b['vinculos'][0]['evidencia']['carga']['salio']);
        $this->assertTrue($b['vinculos'][0]['actual']['carga']['salio']);
    }

    public function test_no_repite_embarque_y_no_retira_despacho_que_salio(): void
    {
        $b = $this->crear();
        $b = $this->paso($this->paso($b, 'asignar', $this->asignacion($b, '120')), 'confirmar');
        $e = $this->embarque($this->datos['cliente_operacion_id'], '2026-10-20', atributos: ['contenedor' => 'MSKU1234567']);
        $this->cargaParaEmbarque($e, 'despachada');
        $extra = ['contenedor_id' => $b['contenedores'][0]['id'], 'embarque_id' => $e];
        $b = $this->paso($b, 'vincular', $extra);
        $url = '/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones';
        $this->postJson($url, $this->solicitud($b, 'vincular', $extra))->assertUnprocessable();
        $this->postJson($url, $this->solicitud($b, 'retirar_vinculo', ['vinculo_id' => $b['vinculos'][0]['id']]))->assertUnprocessable();
        $this->assertSame(1, BookingDespacho::query()->count());
    }

    public function test_notas_de_otro_cliente_y_fuera_de_vigencia_no_entran_a_reserva(): void
    {
        $b = $this->crear(['cliente_operacion_id' => $this->clienteOperacion('Otro cliente')]);
        $this->postJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones', $this->solicitud($b, 'asignar', $this->asignacion($b, '1')))->assertUnprocessable();
        $b = $this->crear(['fecha_salida' => '2027-04-01', 'fecha_llegada' => '2027-05-01']);
        $this->postJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones', $this->solicitud($b, 'asignar', $this->asignacion($b, '1')))->assertUnprocessable();
    }

    public function test_consulta_no_modifica_y_preparador_no_confirma_sin_permiso_de_decision(): void
    {
        $b = $this->crear();
        $b = $this->paso($b, 'asignar', $this->asignacion($b, '10'));
        $this->actingAs($this->conPerfil(RolUsuario::Consulta, ['comex.comercial']));
        $this->getJson('/api/comex/comercial/bookings')->assertOk();
        $this->postJson('/api/comex/comercial/bookings', $this->datos)->assertForbidden();
        $this->actingAs($this->conPerfil(RolUsuario::Despachador, ['comex.comercial']));
        $this->postJson('/api/comex/comercial/bookings/'.$b['booking']['id'].'/transiciones', $this->solicitud($b, 'confirmar'))->assertForbidden();
    }
}
