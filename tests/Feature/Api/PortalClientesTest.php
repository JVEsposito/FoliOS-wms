<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\Dispositivo;
use App\Models\Embarque;
use App\Models\Folio;
use App\Models\RecepcionRomana;
use App\Models\SolicitudCargaCliente;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Clientes\ServicioCliente;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalClientesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Temporada $temporada;

    private Cliente $propio;

    private Cliente $ajeno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00:00', 'America/Santiago'));
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $this->temporada = Temporada::where('activa', true)->firstOrFail();
        $clientes = app(ServicioCliente::class);
        $this->propio = $clientes->guardarMaestro(['codigo' => 'AGU', 'nombre' => 'La Aguada'], $this->admin);
        $this->propio->forceFill(['codigo_folio_materiales' => 'LA'])->save();
        $this->ajeno = $clientes->guardarMaestro(['codigo' => 'FAL', 'nombre' => 'Falco Fruit'], $this->admin);
    }

    /** @return array{0: User, 1: string} */
    private function usuarioCliente(array $clientes = []): array
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/administracion/usuarios-clientes', [
            'nombre' => 'Comex La Aguada', 'email' => 'Comex@LaAguada.cl', 'password' => 'Temporal2026',
            'clientes' => $clientes ?: [$this->propio->id],
        ])->assertCreated()->assertJsonPath('usuario.debe_cambiar_password', true);
        $this->app['auth']->forgetGuards();
        $acceso = $this->postJson('/api/acceso-oficina', ['email' => 'comex@laaguada.cl', 'password' => 'Temporal2026'])->assertOk()
            ->assertJsonPath('usuario.es_cliente', true)->assertJsonPath('usuario.inicio', '/oficina/clientes')
            ->assertJsonPath('usuario.capacidades', []);

        return [User::where('email', 'comex@laaguada.cl')->sole(), $acceso->json('token')];
    }

    private function comoAdmin(): static
    {
        $this->withoutToken()->app['auth']->forgetGuards();

        return $this->actingAs($this->admin, 'sanctum');
    }

    private function portal(string $ruta, ?Cliente $cliente = null): string
    {
        return '/api/portal/clientes/'.($cliente ?? $this->propio)->id.$ruta;
    }

    public function test_un_usuario_cliente_solo_llega_al_portal(): void
    {
        [$usuario, $token] = $this->usuarioCliente();
        $this->withToken($token);
        foreach (['/api/administracion/accesos', '/api/embarques?desde=2026-11-10&hasta=2026-11-12', '/api/romana/recepciones',
            '/api/cargas', '/api/planes-operacionales', '/api/validacion/pendientes-origen/conciliaciones', '/api/oficina/contexto'] as $ruta) {
            $this->getJson($ruta)->assertForbidden()->assertJsonPath('codigo', 'solo_portal_clientes');
        }
        $this->postJson('/api/embarques', [])->assertForbidden();
        $this->getJson('/api/acceso-oficina')->assertOk()->assertJsonPath('usuario.clientes.0.nombre', 'La Aguada');
        $this->getJson('/api/portal/contexto')->assertOk()->assertJsonPath('usuario.clientes.0.id', $this->propio->id);
        $this->getJson($this->portal('/resumen'))->assertOk();
        // Un cliente no asignado no existe para esta cuenta.
        $this->getJson($this->portal('/resumen', $this->ajeno))->assertNotFound();
        $this->getJson($this->portal('/folios', $this->ajeno))->assertNotFound();
        $this->assertFalse($usuario->can('consultar-catalogo-cargas'));

        // Ni tablet ni portal para cuentas internas.
        $dispositivo = Dispositivo::create(['codigo' => 'TAB-1', 'nombre' => 'Tablet', 'plataforma' => 'android', 'activo' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken('')->postJson('/api/acceso-tablet', ['email' => 'comex@laaguada.cl', 'password' => 'Temporal2026', 'codigo_dispositivo' => $dispositivo->codigo])
            ->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/portal/contexto')->assertForbidden();
    }

    public function test_accesos_administra_usuarios_de_clientes_por_separado(): void
    {
        [$usuario, $token] = $this->usuarioCliente();
        $this->comoAdmin();
        $this->getJson('/api/administracion/accesos')->assertOk()->assertJsonMissing(['email' => 'comex@laaguada.cl']);
        $this->getJson('/api/administracion/usuarios-clientes')->assertOk()->assertJsonPath('usuarios.0.clientes.0.codigo', 'AGU');
        $this->putJson('/api/administracion/usuarios/'.$usuario->id, ['nombre' => 'X', 'email' => 'x@x.cl', 'rol' => 'consulta', 'activo' => true])->assertNotFound();
        $this->postJson('/api/administracion/usuarios', ['nombre' => 'X', 'email' => 'x@x.cl', 'password' => 'Temporal2026', 'rol' => 'cliente'])
            ->assertUnprocessable()->assertJsonValidationErrors('rol');
        $this->putJson('/api/administracion/usuarios-clientes/'.$this->admin->id, ['nombre' => 'X', 'email' => 'a@b.cl', 'activo' => true, 'clientes' => [$this->propio->id]])
            ->assertNotFound();
        $this->putJson('/api/administracion/usuarios-clientes/'.$usuario->id, ['nombre' => 'Comex', 'email' => 'comex@laaguada.cl',
            'activo' => true, 'clientes' => [$this->propio->id, $this->ajeno->id]])->assertOk()->assertJsonPath('sesiones_revocadas', true)
            ->assertJsonCount(2, 'usuario.clientes');
        $this->assertSame(0, $usuario->tokens()->count());
        $this->assertNotNull($token);
    }

    public function test_el_portal_muestra_solo_los_datos_del_cliente(): void
    {
        [, $token] = $this->usuarioCliente();
        foreach ([[$this->propio, 'REC-1', 12000, '1511'], [$this->propio, 'REC-2', 8000.5, '1512'], [$this->ajeno, 'REC-3', 99999, '9999']] as [$cliente, $numero, $neto, $guia]) {
            $this->recepcion($cliente, $numero, $neto, $guia);
        }
        foreach ([['000101', 'LA AGUADA', 'disponible'], ['000102', ' la aguada ', 'despachado'], ['000201', 'Falco Fruit', 'disponible']] as [$numero, $exportadora, $estado]) {
            Folio::create(['temporada_id' => $this->temporada->id, 'numero_folio' => $numero, 'tipo_bulto' => 'pallet', 'estado_operacional' => $estado,
                'fecha_ingreso' => now(), 'activo' => $estado !== 'despachado', 'exportadora' => $exportadora, 'variedad' => 'Santina', 'calibre' => '2J',
                'marca' => 'Marca A', 'datos_externos' => ['especie' => 'Cereza', 'cantidad_cajas' => 120,
                    'composicion' => [['csg' => '001234', 'cantidad_cajas' => 80], ['csg' => '001567', 'cantidad_cajas' => 40]]]]);
        }
        foreach ([$this->propio, $this->ajeno] as $cliente) {
            Embarque::create(['temporada_id' => $this->temporada->id, 'cliente_id' => $cliente->id, 'codigo' => 'EMB-'.$cliente->codigo,
                'numero_correlativo' => 1, 'fecha_programada' => '2026-11-12', 'hora_programada' => '10:00:00', 'intervalo_minutos' => 60,
                'modalidad' => 'maritimo', 'estado' => 'tentativo', 'version' => 1, 'creado_por_user_id' => $this->admin->id,
                'actualizado_por_user_id' => $this->admin->id]);
        }
        $this->withToken($token);

        $this->getJson($this->portal('/resumen'))->assertOk()->assertJsonPath('data.kilos_temporada', 20000.5)
            ->assertJsonPath('data.recepciones_temporada', 2)->assertJsonPath('data.folios_en_planta', 1)
            ->assertJsonPath('data.cajas_en_planta', 120)->assertJsonPath('data.embarques_proximos', 1);
        $recepciones = $this->getJson($this->portal('/recepciones'))->assertOk();
        $this->assertSame(['REC-2', 'REC-1'], array_column($recepciones->json('data.recepciones.data'), 'numero_recepcion'));
        $this->assertSame(20000.5, $recepciones->json('data.totales.kilos'));
        $this->assertSame(['1512'], $recepciones->json('data.recepciones.data.0.guias'));
        $folios = $this->getJson($this->portal('/folios'))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero_folio', '000101')->assertJsonPath('data.0.csg.0', ['csg' => '001234', 'cajas' => 80]);
        $this->assertArrayNotHasKey('ubicacion', $folios->json('data.0'));
        $this->getJson($this->portal('/folios?situacion=todos'))->assertJsonPath('meta.total', 2);
        $this->getJson($this->portal('/folios?situacion=todos&q=0002'))->assertJsonPath('meta.total', 0);
        $this->getJson($this->portal('/embarques'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', 'EMB-AGU');
        $this->getJson($this->portal('/materiales'))->assertOk()->assertJsonPath('data', []);
    }

    public function test_un_nombre_compartido_con_otro_cliente_no_entrega_sus_folios(): void
    {
        [, $token] = $this->usuarioCliente();
        // «FALCO FRUIT» identifica a otro cliente: aunque fuera alias de este, no se usa.
        $this->propio->aliases()->create(['origen' => 'prueba', 'codigo' => 'FALCO', 'nombre' => 'Falco Fruit']);
        Folio::create(['temporada_id' => $this->temporada->id, 'numero_folio' => '000201', 'tipo_bulto' => 'pallet', 'estado_operacional' => 'disponible',
            'fecha_ingreso' => now(), 'activo' => true, 'exportadora' => 'FALCO FRUIT']);
        $this->withToken($token)->getJson($this->portal('/folios'))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_solicitud_de_horario_reserva_el_bloque_y_despacho_la_resuelve(): void
    {
        [, $token] = $this->usuarioCliente();
        $solicitud = ['fecha' => '2026-11-12', 'hora' => '10:00', 'modalidad' => 'maritimo', 'cantidad_pallets' => 20, 'referencia' => 'BK-889', 'observacion' => 'Contenedor 40'];
        $operacion = (string) Str::uuid();
        $this->withToken($token);
        $creada = $this->postJson($this->portal('/solicitudes-carga'), [...$solicitud, 'operacion_id' => $operacion])->assertCreated()
            ->assertJsonPath('data.estado', 'solicitada')->assertJsonPath('data.codigo', 'SC-00001');
        $this->postJson($this->portal('/solicitudes-carga'), [...$solicitud, 'operacion_id' => $operacion])->assertOk();
        $this->postJson($this->portal('/solicitudes-carga'), [...$solicitud, 'operacion_id' => $operacion, 'cantidad_pallets' => 5])->assertConflict();
        $this->postJson($this->portal('/solicitudes-carga'), [...$solicitud, 'operacion_id' => (string) Str::uuid()])->assertConflict();
        $this->postJson($this->portal('/solicitudes-carga'), [...$solicitud, 'operacion_id' => (string) Str::uuid(), 'hora' => '10:30'])->assertUnprocessable();
        $this->postJson($this->portal('/solicitudes-carga'), [...$solicitud, 'operacion_id' => (string) Str::uuid(), 'fecha' => '2026-11-10', 'hora' => '07:00'])->assertUnprocessable();
        $bloques = collect($this->getJson($this->portal('/horarios?desde=2026-11-12&dias=1'))->assertOk()->json('data'))->keyBy('hora');
        $this->assertSame('propio', $bloques['10:00']['estado']);
        $this->assertSame('libre', $bloques['11:00']['estado']);
        $this->assertArrayNotHasKey('cliente', $bloques['10:00']);

        // Otro cliente ve el bloque ocupado, sin saber de quién es.
        $this->comoAdmin()->postJson('/api/administracion/usuarios-clientes', ['nombre' => 'Falco', 'email' => 'ops@falco.cl',
            'password' => 'Temporal2026', 'clientes' => [$this->ajeno->id]])->assertCreated();
        $this->withoutToken()->app['auth']->forgetGuards();
        $otro = $this->postJson('/api/acceso-oficina', ['email' => 'ops@falco.cl', 'password' => 'Temporal2026'])->json('token');
        $ajenos = collect($this->withToken($otro)->getJson($this->portal('/horarios?desde=2026-11-12&dias=1', $this->ajeno))->json('data'))->keyBy('hora');
        $this->assertSame('ocupado', $ajenos['10:00']['estado']);
        $this->getJson($this->portal('/solicitudes-carga', $this->ajeno))->assertJsonCount(0, 'data');

        // Despacho la ve en el calendario y la acepta: nace el embarque tentativo.
        $this->comoAdmin();
        $this->getJson('/api/solicitudes-carga-clientes')->assertOk()->assertJsonPath('data.0.cliente.nombre', 'La Aguada');
        $ventana = collect($this->getJson('/api/embarques?desde=2026-11-12&hasta=2026-11-12')->assertOk()->json('ventanas'))->firstWhere('hora', '10:00');
        $this->assertFalse($ventana['disponible']);
        $this->assertSame('SC-00001', $ventana['solicitudes'][0]['codigo']);
        $id = $creada->json('data.id');
        $this->postJson("/api/solicitudes-carga-clientes/{$id}/aceptar", ['version_esperada' => 2])->assertConflict();
        $this->postJson("/api/solicitudes-carga-clientes/{$id}/aceptar", ['version_esperada' => 1])->assertOk()->assertJsonPath('data.estado', 'aceptada');
        $embarque = Embarque::where('cliente_id', $this->propio->id)->sole();
        $this->assertSame('tentativo', $embarque->estado->value);
        $this->assertSame('BK-889', $embarque->referencia_correo);
        $this->assertSame(20, $embarque->instructivos()->sole()->cantidad_pallets);
        $this->postJson("/api/solicitudes-carga-clientes/{$id}/rechazar", ['version_esperada' => 2, 'motivo' => 'Tarde'])->assertConflict();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson($this->portal('/solicitudes-carga'))->assertJsonPath('data.0.embarque.codigo', $embarque->codigo);
        $this->getJson($this->portal('/embarques'))->assertJsonPath('data.0.codigo', $embarque->codigo);
    }

    public function test_el_cliente_anula_y_despacho_rechaza_con_motivo(): void
    {
        [, $token] = $this->usuarioCliente();
        $this->withToken($token);
        $pedir = fn (string $hora) => $this->postJson($this->portal('/solicitudes-carga'), ['operacion_id' => (string) Str::uuid(),
            'fecha' => '2026-11-13', 'hora' => $hora])->assertCreated()->json('data');
        $primera = $pedir('09:00');
        $segunda = $pedir('11:00');
        $this->postJson($this->portal('/solicitudes-carga/'.$primera['id'].'/anular'), ['version' => 1])->assertOk()->assertJsonPath('data.estado', 'anulada');
        $this->postJson($this->portal('/solicitudes-carga/'.$primera['id'].'/anular', $this->ajeno), ['version' => 2])->assertNotFound();
        // El bloque anulado vuelve a quedar libre.
        $bloques = collect($this->getJson($this->portal('/horarios?desde=2026-11-13&dias=1'))->json('data'))->keyBy('hora');
        $this->assertSame('libre', $bloques['09:00']['estado']);

        $this->comoAdmin();
        $this->postJson('/api/solicitudes-carga-clientes/'.$segunda['id'].'/rechazar', ['version_esperada' => 1, 'motivo' => 'Andén en mantención'])
            ->assertOk()->assertJsonPath('data.estado', 'rechazada');
        $this->assertSame(0, Embarque::count());
        $this->app['auth']->forgetGuards();
        $estados = collect($this->withToken($token)->getJson($this->portal('/solicitudes-carga'))->json('data'))->keyBy('codigo');
        $this->assertSame('Andén en mantención', $estados[$segunda['codigo']]['motivo_resolucion']);
        $this->assertSame(SolicitudCargaCliente::ANULADA, $estados[$primera['codigo']]['estado']);
    }

    private function recepcion(Cliente $cliente, string $numero, float $neto, string $guia): void
    {
        RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $numero), 'numero_recepcion' => $numero,
            'temporada_id' => $this->temporada->id, 'temporada_codigo_snapshot' => $this->temporada->codigo, 'temporada_nombre_snapshot' => $this->temporada->nombre,
            'cliente_id' => $cliente->id, 'cliente_codigo_snapshot' => $cliente->codigo, 'cliente_nombre_snapshot' => $cliente->nombre,
            'tipo_recepcion' => 'fruta_con_envases', 'tipo_servicio' => 'proceso', 'cantidad_envases_declarados' => 10, 'tipo_envase_declarado' => 'bins',
            'numero_guia_despacho' => $guia, 'peso_bruto' => $neto + 8000, 'peso_tara' => 8000, 'peso_neto' => $neto,
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo', 'rut_conductor' => '12345678-5', 'nombre_conductor' => 'Conductor',
            'estado' => 'cerrado', 'estado_validacion_mp' => 'validada', 'ingreso_at' => now()->subHours($numero === 'REC-1' ? 5 : 2),
            'salida_at' => now()->subHour(), 'creado_por_user_id' => $this->admin->id,
        ]);
    }
}
