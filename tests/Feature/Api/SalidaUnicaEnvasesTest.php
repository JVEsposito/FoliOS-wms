<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoGuiaDespachoEnvase;
use App\Enums\EstadoRecepcionRomana;
use App\Enums\RolUsuario;
use App\Exceptions\ConflictoOperacion;
use App\Models\Cliente;
use App\Models\GuiaDespachoEnvase;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Envases\DocumentoSalidaEnvases;
use App\Services\Envases\ServicioConciliacionSalidaEnvases;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use App\Services\Envases\ServicioGuiaDespachoEnvases;
use App\Services\Envases\ServicioGuiaEmitidaEnvases;
use App\Services\Romana\ServicioAnulacionRecepcionRomana;
use App\Services\Romana\ServicioRecepcionRomana;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalidaUnicaEnvasesTest extends TestCase
{
    use RefreshDatabase;

    private Temporada $temporada;

    private Cliente $cliente;

    private User $admin;

    private RecepcionRomana $recepcion;

    private GuiaDespachoEnvase $guia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:00:00Z'));
        $this->temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $this->cliente = Cliente::create(['codigo' => 'EXP-DOBLE', 'nombre' => 'Cliente de prueba', 'activo' => true]);
        $this->admin = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
        $this->recepcion = RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'recepcion'), 'numero_recepcion' => 'REC-DOBLE-1',
            'temporada_id' => $this->temporada->id, 'temporada_codigo_snapshot' => $this->temporada->codigo, 'temporada_nombre_snapshot' => $this->temporada->nombre,
            'cliente_id' => $this->cliente->id, 'cliente_codigo_snapshot' => $this->cliente->codigo, 'cliente_nombre_snapshot' => $this->cliente->nombre,
            'tipo_recepcion' => 'fruta_con_envases', 'tipo_servicio' => 'proceso', 'cantidad_envases_declarados' => 1050, 'tipo_envase_declarado' => 'bins',
            'numero_guia_despacho' => '11893', 'numero_guia_salida' => '002.236', 'modo_salida_envases' => 'diferentes',
            'tipo_envase_calculo_neto' => 'bins', 'peso_tara_envases' => 0, 'peso_bruto' => 20000, 'peso_tara' => 8000, 'peso_neto' => 12000,
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo', 'rut_conductor' => '12345678-5', 'nombre_conductor' => 'Conductor de prueba',
            'estado' => 'cerrado', 'estado_validacion_mp' => 'validada', 'ingreso_at' => now()->subHours(2), 'salida_at' => now()->subHour(), 'creado_por_user_id' => $this->admin->id,
        ]);
        $this->guia = GuiaDespachoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'numero' => 'GDE-DOBLE-1', 'numero_guia_despacho' => '2236',
            'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id, 'estado' => 'confirmada',
            'salida_at' => now()->subHour(), 'creado_por_user_id' => $this->admin->id,
        ]);
        foreach (['bins' => [42, 42, 'arrendada'], 'totes' => [1002, 1009, 'arrendada'], 'esponjas' => [0, 14, 'propia']] as $tipo => [$romana, $despacho, $propiedad]) {
            $origen = $this->movimiento($tipo, 10000, $propiedad, $propiedad === 'propia' ? 'recepcion_compra' : 'recepcion_arriendo', 1, $propiedad === 'propia' ? 0 : 1);
            $this->movimiento($tipo, $despacho, $propiedad, 'despacho_cliente', -1, -1, ['documento_tipo' => 'guia_despacho_envases', 'documento_id' => $this->guia->id, 'movimiento_origen_id' => $origen->id]);
            if ($romana) {
                $m = $this->movimiento($tipo, $romana, $propiedad, 'salida_recepcion_fruta', -1, -1, ['recepcion_romana_id' => $this->recepcion->id, 'documento_tipo' => 'recepcion_romana', 'documento_id' => $this->recepcion->id, 'numero_documento' => '002.236']);
                $this->recepcion->salidasEnvases()->create(['tipo_envase' => $tipo, 'cantidad' => $romana, 'tara_unitaria' => 1, 'movimiento_envase_id' => $m->id]);
                $this->recepcion->detallesEnvases()->create(['tipo_envase' => $tipo, 'cantidad_declarada' => $romana, 'cantidad_validada' => $romana, 'propiedad' => $propiedad]);
            }
        }
        $this->actingAs($this->admin, 'sanctum');
    }

    private function movimiento(string $tipo, int $cantidad, string $propiedad, string $movimiento, int $existencia, int $cuenta, array $extra = []): MovimientoEnvase
    {
        return MovimientoEnvase::create([...[
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
            'documento_tipo' => 'prueba', 'numero_documento' => 'BASE', 'tipo_movimiento' => $movimiento, 'tipo_envase' => $tipo,
            'cantidad' => $cantidad, 'signo_existencia' => $existencia, 'signo_cuenta' => $cuenta, 'propiedad' => $propiedad,
            'ocurrido_at' => now()->subHour(), 'estado_revision' => 'pendiente', 'creado_por_user_id' => $this->admin->id,
        ], ...$extra]);
    }

    private function saldo(string $tipo, string $signo): int
    {
        return (int) MovimientoEnvase::query()->where('tipo_envase', $tipo)->get()->sum(fn ($m) => $m->cantidad * $m->{$signo});
    }

    private function conciliar(string $conservar = 'romana'): array
    {
        $s = app(ServicioConciliacionSalidaEnvases::class);
        $plan = $s->previsualizar($this->recepcion, $this->guia, $conservar);

        return $s->aplicar($this->recepcion, $this->guia, $conservar, $plan['hash'], 'Cantidades verificadas con el respaldo físico', $this->admin);
    }

    private function datosConciliacion(array $cambios = []): array
    {
        return [...['recepcion_id' => $this->recepcion->id, 'guia_id' => $this->guia->id, 'conservar' => 'romana'], ...$cambios];
    }

    public function test_oficina_lista_coincidencias_normalizadas_y_filtra_por_cliente_y_documento_sin_escribir(): void
    {
        $cantidad = MovimientoEnvase::count();
        $ruta = '/api/envases/cuenta-corriente/conciliaciones-salida?temporada_id='.$this->temporada->id;
        foreach (['', '&buscar=0002.236', '&buscar=11893', '&cliente_id='.$this->cliente->id] as $filtro) {
            $this->getJson($ruta.$filtro)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.recepcion_id', $this->recepcion->id)
                ->assertJsonPath('data.0.guia_id', $this->guia->id)->assertJsonPath('data.0.guia_ingreso', '11893');
        }
        $otro = Cliente::create(['codigo' => 'SIN-DOBLE', 'nombre' => 'Otro cliente', 'activo' => true]);
        $this->getJson($ruta.'&cliente_id='.$otro->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($ruta.'&buscar=NO-EXISTE')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame($cantidad, MovimientoEnvase::count());
    }

    public function test_oficina_restringe_listado_previsualizacion_y_aplicacion_a_administradores_activos(): void
    {
        $cantidad = MovimientoEnvase::count();
        foreach (RolUsuario::cases() as $rol) {
            if ($rol === RolUsuario::Administrador) {
                continue;
            }
            $this->actingAs(User::factory()->create(['rol' => $rol, 'activo' => true]), 'sanctum');
            $this->getJson('/api/envases/cuenta-corriente/conciliaciones-salida?temporada_id='.$this->temporada->id)->assertForbidden();
            foreach (['previsualizar', 'aplicar'] as $accion) {
                $this->postJson('/api/envases/cuenta-corriente/conciliaciones-salida/'.$accion, $this->datosConciliacion())->assertForbidden();
            }
        }
        $this->admin->update(['activo' => false]);
        $this->actingAs($this->admin->fresh(), 'sanctum');
        $this->postJson('/api/envases/cuenta-corriente/conciliaciones-salida/previsualizar', $this->datosConciliacion())->assertForbidden();
        $this->assertSame($cantidad, MovimientoEnvase::count());
    }

    public function test_oficina_exige_elegir_cantidades_y_previsualiza_sin_alterar_saldos(): void
    {
        $ruta = '/api/envases/cuenta-corriente/conciliaciones-salida/previsualizar';
        $cantidad = MovimientoEnvase::count();
        $this->postJson($ruta, $this->datosConciliacion(['conservar' => '']))->assertUnprocessable()->assertJsonValidationErrors('conservar');
        $respuesta = $this->postJson($ruta, $this->datosConciliacion())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $filas = collect($respuesta->json('data.filas'))->keyBy('envase_propiedad');
        $this->assertSame(7, $filas['totes|arrendada']['diferencia']);
        $this->assertSame(1002, $filas['totes|arrendada']['conservar']);
        $this->assertSame(1009, $filas['totes|arrendada']['compensacion']);
        $this->assertSame(14, $filas['esponjas|propia']['conservar']);
        $this->assertSame(0, $filas['esponjas|propia']['compensacion']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $respuesta->json('data.hash'));
        $this->assertSame($cantidad, MovimientoEnvase::count());
    }

    public function test_oficina_confirma_con_hash_y_cantidades_verificadas_y_audita_al_usuario_de_sesion(): void
    {
        $base = '/api/envases/cuenta-corriente/conciliaciones-salida';
        $hash = $this->postJson($base.'/previsualizar', $this->datosConciliacion())->assertOk()->json('data.hash');
        $cantidad = MovimientoEnvase::count();
        $datos = $this->datosConciliacion(['hash' => $hash, 'motivo' => 'Guía física verificada por administración', 'cantidades_verificadas' => true, 'usuario' => 'no-usar-este-actor@ejemplo.cl']);
        $this->postJson($base.'/aplicar', [...$datos, 'cantidades_verificadas' => false])->assertUnprocessable();
        $this->postJson($base.'/aplicar', [...$datos, 'motivo' => 'corto'])->assertUnprocessable();
        $this->postJson($base.'/aplicar', [...$datos, 'hash' => str_repeat('0', 64)])->assertConflict();
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->postJson($base.'/aplicar', $datos)->assertOk()->assertJsonPath('data.aplicada', true)
            ->assertJsonPath('data.compensaciones', 2)->assertJsonPath('data.auditoria.0.responsable', $this->admin->name)
            ->assertJsonPath('data.auditoria.0.motivo', $datos['motivo']);
        $this->assertSame($cantidad + 2, MovimientoEnvase::count());
        $this->assertSame(8998, $this->saldo('totes', 'signo_cuenta'));
        $this->assertSame(8998, $this->saldo('totes', 'signo_existencia'));
        $this->postJson($base.'/aplicar', $datos)->assertOk()->assertJsonPath('data.aplicada', true);
        $this->assertSame($cantidad + 2, MovimientoEnvase::count());
        $this->getJson($base.'?temporada_id='.$this->temporada->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($base.'/previsualizar', $this->datosConciliacion())->assertOk()->assertJsonPath('data.aplicada', true);
    }

    public function test_oficina_rechaza_un_hash_de_otra_fuente_o_un_documento_modificado_y_permite_conservar_despacho(): void
    {
        $base = '/api/envases/cuenta-corriente/conciliaciones-salida';
        $datos = $this->datosConciliacion(['motivo' => 'Cantidades físicas revisadas con la guía', 'cantidades_verificadas' => true]);
        $hash = $this->postJson($base.'/previsualizar', $datos)->assertOk()->json('data.hash');
        $cantidad = MovimientoEnvase::count();
        $this->postJson($base.'/aplicar', [...$datos, 'hash' => $hash, 'conservar' => 'despacho'])->assertConflict();
        $this->guia->increment('version');
        $this->postJson($base.'/aplicar', [...$datos, 'hash' => $hash])->assertConflict();
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $datos['conservar'] = 'despacho';
        $hash = $this->postJson($base.'/previsualizar', $datos)->assertOk()->json('data.hash');
        $this->postJson($base.'/aplicar', [...$datos, 'hash' => $hash])->assertOk();
        $this->assertSame(8991, $this->saldo('totes', 'signo_cuenta'));
        $this->assertSame(8991, $this->saldo('totes', 'signo_existencia'));
        $this->assertSame(-14, $this->saldo('esponjas', 'signo_cuenta'));
    }

    public function test_oficina_no_lista_documentos_incompatibles_ni_previsualiza_otra_temporada(): void
    {
        $base = '/api/envases/cuenta-corriente/conciliaciones-salida';
        $this->guia->update(['estado' => 'borrador']);
        $this->getJson($base.'?temporada_id='.$this->temporada->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($base.'/previsualizar', $this->datosConciliacion())->assertConflict();
        $this->guia->update(['estado' => 'confirmada', 'numero_guia_despacho' => '9999']);
        $this->getJson($base.'?temporada_id='.$this->temporada->id)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($base.'/previsualizar', $this->datosConciliacion())->assertConflict();
        $historica = Temporada::create([...$this->vigenciaProductiva(), 'codigo' => 'TEMP-HISTORICA', 'nombre' => 'Temporada histórica', 'activa' => false]);
        $this->guia->update(['temporada_id' => $historica->id]);
        $this->getJson($base.'?temporada_id='.$historica->id)->assertConflict();
        $this->postJson($base.'/previsualizar', $this->datosConciliacion())->assertConflict();
    }

    public function test_normaliza_historial_y_bloquea_confirmar_un_segundo_despacho_sin_crear_movimientos(): void
    {
        $this->assertSame('2236', $this->recepcion->fresh()->documento_salida_normalizado);
        $this->guia->update(['estado' => 'borrador', 'numero_guia_despacho' => null]);
        $cantidad = MovimientoEnvase::count();
        $this->postJson('/api/envases/guias-despacho/'.$this->guia->id.'/confirmar', ['numero_guia_despacho' => '0002.236'])
            ->assertConflict()->assertJsonPath('codigo', 'conflicto_operacional');
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->assertSame(EstadoGuiaDespachoEnvase::Borrador, $this->guia->fresh()->estado);
    }

    public function test_bloquea_destare_si_despacho_ya_cargo_la_guia(): void
    {
        $this->recepcion->update(['estado' => 'en_bascula_salida']);
        $cantidad = MovimientoEnvase::count();
        try {
            app(ServicioRecepcionRomana::class)->cerrar($this->recepcion, [
                'operacion_id' => (string) Str::uuid(), 'peso_tara' => 8000, 'modo_salida_envases' => 'mismos', 'numero_guia_salida' => '2.236',
            ], $this->admin);
            $this->fail('Debía rechazar el segundo descuento.');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('ya descontó', $e->getMessage());
        }
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->assertSame(EstadoRecepcionRomana::EnBasculaSalida, $this->recepcion->fresh()->estado);
    }

    public function test_backfill_de_numero_no_puede_crear_una_colision_con_romana(): void
    {
        $this->guia->update(['numero_guia_despacho' => null]);
        $this->expectException(ConflictoOperacion::class);
        app(ServicioGuiaEmitidaEnvases::class)->registrarNumero($this->guia, '002236', null, $this->admin);
    }

    public function test_diagnostico_muestra_diferencias_y_espumas_no_repetidas_sin_mutar_cuenta(): void
    {
        $cantidad = MovimientoEnvase::count();
        $plan = app(ServicioConciliacionSalidaEnvases::class)->previsualizar($this->recepcion, $this->guia, 'romana');
        $porTipo = collect($plan['filas'])->keyBy('envase_propiedad');
        $this->assertSame(['envase_propiedad' => 'totes|arrendada', 'romana' => 1002, 'despacho' => 1009, 'diferencia' => 7, 'conservar' => 1002], $porTipo['totes|arrendada']);
        $this->assertSame(14, $porTipo['esponjas|propia']['conservar']);
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->artisan('envases:conciliar-salida', ['recepcion' => '11893', 'guia' => '2236', '--conservar' => 'romana'])->assertSuccessful();
        $this->assertSame($cantidad, MovimientoEnvase::count());
    }

    public function test_conserva_romana_compensa_cuenta_stock_y_fifo_y_es_idempotente(): void
    {
        $resultado = $this->conciliar();
        $this->assertCount(2, $resultado['movimientos']);
        foreach (['signo_cuenta', 'signo_existencia'] as $signo) {
            $this->assertSame(9958, $this->saldo('bins', $signo));
            $this->assertSame(8998, $this->saldo('totes', $signo));
        }
        $this->getJson('/api/envases/guias-despacho/'.$this->guia->id)->assertOk()->assertJsonPath('data.conciliacion_salida.conservar', 'romana');
        $this->assertSame(-14, $this->saldo('esponjas', 'signo_cuenta'));
        $this->assertSame(9986, $this->saldo('esponjas', 'signo_existencia'));
        $inventario = app(ServicioGuiaDespachoEnvases::class)->inventario($this->temporada);
        $this->assertSame(8998, collect($inventario['origenes'])->firstWhere('tipo_envase', 'totes')['fisico']);
        $cantidad = MovimientoEnvase::count();
        $repetida = app(ServicioConciliacionSalidaEnvases::class)->aplicar($this->recepcion, $this->guia, 'romana', $resultado['hash'], 'Reintento del mismo respaldo físico', $this->admin);
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->assertTrue($repetida['aplicada']);
        $estado = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($this->cliente, $this->temporada, null, null);
        $totales = collect($estado['tipos'])->keyBy('tipo_envase');
        $this->assertSame(42, $totales['bins']['salidas']);
        $this->assertSame(1002, $totales['totes']['salidas']);
        $this->assertSame(14, $totales['esponjas']['salidas']);
        $this->assertSame(1002, array_sum(array_map(fn ($grupo) => collect($grupo['tipos'])->where('tipo_envase', 'totes')->sum('salidas'), $estado['resumen_csg'])));
    }

    public function test_anular_despacho_conservando_romana_no_abona_dos_veces(): void
    {
        $this->conciliar();
        app(ServicioGuiaDespachoEnvases::class)->anular($this->guia, 'Anulación del respaldo duplicado', $this->admin);
        $this->assertSame(9958, $this->saldo('bins', 'signo_cuenta'));
        $this->assertSame(8998, $this->saldo('totes', 'signo_cuenta'));
        $this->assertSame(0, $this->saldo('esponjas', 'signo_cuenta'));
        $this->assertSame(8998, $this->saldo('totes', 'signo_existencia'));
    }

    public function test_conserva_despacho_y_su_anulacion_restaura_la_salida_romana_una_sola_vez(): void
    {
        $this->conciliar('despacho');
        $this->assertSame(8991, $this->saldo('totes', 'signo_cuenta'));
        $this->assertSame(8991, $this->saldo('totes', 'signo_existencia'));
        app(ServicioGuiaDespachoEnvases::class)->anular($this->guia, 'Se anula el despacho conciliado', $this->admin);
        $this->assertSame(8998, $this->saldo('totes', 'signo_cuenta'));
        $this->assertSame(9958, $this->saldo('bins', 'signo_existencia'));
        $cantidad = MovimientoEnvase::count();
        app(ServicioGuiaDespachoEnvases::class)->anular($this->guia->fresh(), 'Reintento', $this->admin);
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $this->assertSame(8998, $this->saldo('totes', 'signo_existencia'));
    }

    public function test_plan_desactualizado_o_sin_autorizacion_no_cambia_saldos(): void
    {
        $s = app(ServicioConciliacionSalidaEnvases::class);
        $plan = $s->previsualizar($this->recepcion, $this->guia, 'romana');
        $this->guia->increment('version');
        $cantidad = MovimientoEnvase::count();
        try {
            $s->aplicar($this->recepcion, $this->guia, 'romana', $plan['hash'], 'Respaldo de prueba verificado', $this->admin);
            $this->fail('Debía rechazar un plan desactualizado.');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('cambiaron', $e->getMessage());
        }
        $this->assertSame($cantidad, MovimientoEnvase::count());
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->expectException(AuthorizationException::class);
        $s->aplicar($this->recepcion, $this->guia, 'romana', $plan['hash'], 'Respaldo de prueba verificado', $operador);
    }

    public function test_no_corrige_ni_elimina_aisladamente_una_recepcion_ya_conciliada(): void
    {
        $this->conciliar();
        $cantidad = MovimientoEnvase::count();
        $datos = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $this->recepcion->version,
            'motivo_correccion' => 'Corrección posterior de prueba', 'peso_tara' => 8000,
            'modo_salida_envases' => 'mismos', 'numero_guia_salida' => '2236'];
        foreach ([
            fn () => app(ServicioRecepcionRomana::class)->corregirSalidaEnvases($this->recepcion, $datos, $this->admin),
            fn () => app(ServicioAnulacionRecepcionRomana::class)->eliminar($this->recepcion, [
                'operacion_id' => (string) Str::uuid(), 'version_conocida' => $this->recepcion->version, 'motivo' => 'Eliminación posterior de prueba',
            ], $this->admin),
        ] as $accion) {
            try {
                $accion();
                $this->fail('La operación aislada debía bloquearse.');
            } catch (ConflictoOperacion $e) {
                $this->assertStringContainsString('concili', $e->getMessage());
            }
        }
        $this->assertSame($cantidad, MovimientoEnvase::count());
    }

    public function test_replica_una_guia_sin_insertar_su_columna_generada(): void
    {
        $copia = $this->guia->fresh()->replicate(['numero', 'operacion_id']);
        $copia->fill(['numero' => 'GDE-COPIA', 'operacion_id' => (string) Str::uuid(), 'numero_guia_despacho' => '2237']);
        $copia->save();
        $this->assertSame('2237', $copia->fresh()->documento_salida_normalizado);
    }

    public function test_no_compensa_un_despacho_historico_que_perdio_su_origen_fisico(): void
    {
        MovimientoEnvase::query()->where('documento_id', $this->guia->id)->where('tipo_envase', 'bins')->update(['movimiento_origen_id' => null]);
        $cantidad = MovimientoEnvase::count();
        try {
            app(ServicioConciliacionSalidaEnvases::class)->previsualizar($this->recepcion, $this->guia, 'romana');
            $this->fail('Un origen perdido debe revisarse antes de modificar la existencia.');
        } catch (ConflictoOperacion $e) {
            $this->assertStringContainsString('origen físico', $e->getMessage());
        }
        $this->assertSame($cantidad, MovimientoEnvase::count());
    }

    public function test_no_confunde_clientes_y_el_mutex_tiene_identidad_canonica(): void
    {
        $otro = Cliente::create(['codigo' => 'EXP-OTRO', 'nombre' => 'Otro cliente', 'activo' => true]);
        DB::transaction(function () use ($otro): void {
            $control = app(DocumentoSalidaEnvases::class);
            $control->bloquear($this->temporada->id, $otro->id, '002.236');
            $control->bloquear($this->temporada->id, $otro->id, '2236');
            $control->asegurarSinRomana($this->temporada->id, $otro->id, '2236');
            $control->asegurarSinDespacho($this->temporada->id, $otro->id, '2236');
        });
        $this->assertDatabaseCount('documentos_salida_envases', 1);
    }
}
