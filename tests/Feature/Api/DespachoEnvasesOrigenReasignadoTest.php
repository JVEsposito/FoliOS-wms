<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\ProductorCsg;
use App\Models\ReasignacionEnvases;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\ServicioConciliacionSalidaEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DespachoEnvasesOrigenReasignadoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cliente $origen;

    private Cliente $destino;

    private Cliente $clienteDespacho;

    private RecepcionRomana $recepcion;

    private const CANTIDADES = ['bins' => 100, 'totes' => 2400, 'esponjas' => 100];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->actingAs($this->admin, 'sanctum');
        $this->origen = Cliente::create(['codigo' => 'ARR-ORIGEN', 'nombre' => 'Arrendador', 'activo' => true]);
        $this->destino = Cliente::create(['codigo' => 'DISFRUTA', 'nombre' => 'DISFRUTA', 'activo' => true]);
        $this->clienteDespacho = Cliente::create(['codigo' => 'FALCO', 'nombre' => 'FALCO FRUIT', 'activo' => true]);
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $this->recepcion = $this->recepcion($this->origen, $temporada, '2345');
        foreach (self::CANTIDADES as $tipo => $cantidad) {
            MovimientoEnvase::create([
                'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $this->origen->id,
                'recepcion_romana_id' => $this->recepcion->id, 'documento_tipo' => 'recepcion_romana',
                'documento_id' => $this->recepcion->id, 'numero_documento' => '2345', 'tipo_movimiento' => 'recepcion_arriendo',
                'tipo_envase' => $tipo, 'propiedad' => 'arrendada', 'cantidad' => $cantidad, 'signo_cuenta' => 1, 'signo_existencia' => 1,
                'ocurrido_at' => now()->subHours(2), 'ingreso_at' => now()->subHours(2),
                'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->admin->id,
            ]);
        }
    }

    public function test_dos_guias_nuevas_consumen_fifo_sin_heredar_el_bloqueo_de_la_recepcion_reasignada(): void
    {
        $this->reasignar('recepcion:'.$this->recepcion->id.':recibidos', '121328');
        foreach (['02578', '2588'] as $numero) {
            $guia = $this->despacho($numero);
            $movimientos = MovimientoEnvase::where('documento_id', $guia->id)->get();
            $this->assertCount(3, $movimientos);
            foreach ($movimientos as $movimiento) {
                $raiz = MovimientoEnvase::findOrFail($movimiento->movimiento_origen_id);
                $this->assertSame($this->recepcion->id, $raiz->recepcion_romana_id);
                $this->assertSame($this->clienteDespacho->id, $movimiento->cliente_id);
                $this->assertNull($movimiento->recepcion_romana_id);
            }
            // El reintento no duplica la salida ni los cargos.
            $this->postJson('/api/envases/guias-despacho/'.$guia->id.'/confirmar', ['numero_guia_despacho' => $numero])->assertOk();
            $this->assertSame(3, MovimientoEnvase::where('documento_id', $guia->id)->count());
        }
        $this->assertSame([90, 2160, 90], $this->saldos('signo_existencia'));
        $this->assertSame([-10, -240, -10], $this->saldos('signo_cuenta', $this->clienteDespacho));
        $this->assertSame([100, 2400, 100], $this->saldos('signo_cuenta', $this->destino));
        $this->assertSame([0, 0, 0], $this->saldos('signo_cuenta', $this->origen));
        $this->assertSame(1, ReasignacionEnvases::where('vigente', true)->count());
    }

    public function test_anular_otro_despacho_restaura_el_fifo_sin_deshacer_la_reasignacion_del_ingreso(): void
    {
        $guia = $this->despacho('2578');
        $this->reasignar('recepcion:'.$this->recepcion->id.':recibidos', '121328');
        $this->postJson('/api/envases/guias-despacho/'.$guia->id.'/anular', ['motivo' => 'El camión no realizó esta entrega.'])
            ->assertOk()->assertJsonPath('data.estado', 'anulada');
        $this->assertSame([100, 2400, 100], $this->saldos('signo_existencia'));
        $this->assertSame([0, 0, 0], $this->saldos('signo_cuenta', $this->clienteDespacho));
        $this->assertSame([100, 2400, 100], $this->saldos('signo_cuenta', $this->destino));
        $this->assertSame(1, ReasignacionEnvases::where('vigente', true)->count());
    }

    public function test_la_guia_del_propio_despacho_reasignado_sigue_bloqueada_para_anulacion(): void
    {
        $guia = $this->despacho('2578');
        $this->reasignar('despacho:'.$guia->id, '121328');
        $cantidad = MovimientoEnvase::count();
        $this->postJson('/api/envases/guias-despacho/'.$guia->id.'/anular', ['motivo' => 'El camión no realizó esta entrega.'])
            ->assertConflict()->assertJsonPath('message', fn ($m) => str_contains($m, '2578') && str_contains($m, 'DISFRUTA'));
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->assertSame([95, 2280, 95], $this->saldos('signo_existencia'));
    }

    public function test_conciliar_otra_salida_comprueba_el_movimiento_conciliado_y_no_su_origen_fifo(): void
    {
        $guia = $this->despacho('2578');
        $recepcion = $this->recepcion($this->clienteDespacho, $guia->temporada, 'INGRESO-FALCO');
        $recepcion->update(['numero_guia_salida' => '2578']);
        foreach (MovimientoEnvase::where('documento_id', $guia->id)->get() as $salida) {
            $movimiento = MovimientoEnvase::create([
                ...$salida->only(['temporada_id', 'cliente_id', 'tipo_envase', 'propiedad', 'cantidad', 'signo_cuenta', 'signo_existencia', 'ocurrido_at']),
                'operacion_id' => (string) Str::uuid(), 'recepcion_romana_id' => $recepcion->id,
                'documento_tipo' => 'recepcion_romana', 'documento_id' => $recepcion->id,
                'numero_documento' => '2578', 'tipo_movimiento' => 'salida_recepcion_fruta',
                'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->admin->id,
            ]);
            $recepcion->salidasEnvases()->create(['tipo_envase' => $salida->tipo_envase, 'cantidad' => $salida->cantidad,
                'tara_unitaria' => 1, 'movimiento_envase_id' => $movimiento->id]);
        }
        $this->reasignar('recepcion:'.$this->recepcion->id.':recibidos', '121328');
        $servicio = app(ServicioConciliacionSalidaEnvases::class);
        $plan = $servicio->previsualizar($recepcion, $guia, 'romana');
        $resultado = $servicio->aplicar($recepcion, $guia, 'romana', $plan['hash'], 'Salida duplicada verificada con los documentos.', $this->admin);
        $this->assertTrue($resultado['aplicada']);
        $this->assertSame([95, 2280, 95], $this->saldos('signo_existencia'));
        $this->assertSame([-5, -120, -5], $this->saldos('signo_cuenta', $this->clienteDespacho));
        $this->assertSame([100, 2400, 100], $this->saldos('signo_cuenta', $this->destino));
    }

    private function despacho(string $numero): GuiaDespachoEnvase
    {
        $guia = $this->postJson('/api/envases/guias-despacho', [
            'operacion_id' => (string) Str::uuid(), 'cliente_id' => $this->clienteDespacho->id, 'salida_at' => now()->toAtomString(),
            'detalles' => array_map(fn ($tipo, $cantidad) => ['tipo_envase' => $tipo, 'cantidad' => $cantidad / 20, 'propiedad' => 'arrendada'],
                array_keys(self::CANTIDADES), array_values(self::CANTIDADES)),
        ])->assertCreated()->json('data');
        $this->postJson('/api/envases/guias-despacho/'.$guia['id'].'/confirmar', ['numero_guia_despacho' => $numero])->assertOk();

        return GuiaDespachoEnvase::findOrFail($guia['id']);
    }

    private function reasignar(string $clave, string $csg): void
    {
        $productor = ProductorCsg::create([
            'codigo' => $csg, 'razon_social' => 'Agrícola destino', 'predio' => 'Predio destino', 'estado_sag' => 'activo',
            'tipo_codigo' => 'CSG', 'fuente_url' => 'https://example.test/sag', 'primera_verificacion_at' => now(),
            'ultima_verificacion_at' => now(), 'ultima_consulta_user_id' => $this->admin->id, 'respuesta_hash' => hash('sha256', $csg),
        ]);
        $this->destino->productoresCsg()->attach($productor->id, ['id' => (string) Str::uuid(), 'activo' => true,
            'asociado_por_user_id' => $this->admin->id, 'actualizado_por_user_id' => $this->admin->id]);
        $this->postJson('/api/envases/cuenta-corriente/reasignaciones', [
            'operacion_id' => (string) Str::uuid(), 'documento_clave' => $clave, 'cliente_destino_id' => $this->destino->id,
            'productor_csg_id' => $productor->id, 'motivo' => 'La guía pertenece a la cuenta y productor de destino.',
        ])->assertCreated();
    }

    private function recepcion(Cliente $cliente, Temporada $temporada, string $numero): RecepcionRomana
    {
        return RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $numero), 'numero_recepcion' => 'REC-'.$numero,
            'temporada_id' => $temporada->id, 'temporada_codigo_snapshot' => $temporada->codigo, 'temporada_nombre_snapshot' => $temporada->nombre,
            'cliente_id' => $cliente->id, 'cliente_codigo_snapshot' => $cliente->codigo, 'cliente_nombre_snapshot' => $cliente->nombre,
            'tipo_recepcion' => 'solo_envases', 'concepto_envases' => 'arriendo', 'tipo_servicio' => 'proceso',
            'cantidad_envases_declarados' => 2600, 'tipo_envase_declarado' => 'bins', 'peso_bruto' => 0,
            'numero_guia_despacho' => $numero, 'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano',
            'rut_conductor' => '12345678-5', 'nombre_conductor' => 'Conductor de prueba',
            'estado' => 'cerrado', 'estado_validacion_mp' => 'validada', 'ingreso_at' => now()->subHours(2),
            'salida_at' => now()->subHour(), 'creado_por_user_id' => $this->admin->id,
        ]);
    }

    private function saldos(string $signo, ?Cliente $cliente = null): array
    {
        return array_map(fn ($tipo) => (int) MovimientoEnvase::where('tipo_envase', $tipo)
            ->when($cliente, fn ($q) => $q->where('cliente_id', $cliente->id))->get()
            ->sum(fn ($m) => $m->cantidad * $m->{$signo}), array_keys(self::CANTIDADES));
    }
}
