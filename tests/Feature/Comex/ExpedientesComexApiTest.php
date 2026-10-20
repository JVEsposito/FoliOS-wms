<?php

namespace Tests\Feature\Comex;

use App\Comex\Models\ArchivoDocumento;
use App\Comex\Models\ClienteComex;
use App\Comex\Models\Documento;
use App\Comex\Models\PaisTipoDocumento;
use App\Enums\RolUsuario;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpedientesComexApiTest extends TestCase
{
    use PreparaOperacionComex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
        Storage::fake('local');
    }

    public function test_sin_documentos_configurados_el_panel_invita_a_cargar_los_sugeridos(): void
    {
        $admin = $this->administradorComex();
        $this->actingAs($admin)->getJson('/api/comex/expedientes')->assertOk()->assertJsonPath('configurado', false);

        $this->actingAs($admin)->postJson('/api/comex/configuracion/sugeridos')
            ->assertOk()->assertJsonPath('agregados', 12);
        $this->actingAs($admin)->postJson('/api/comex/configuracion/sugeridos')
            ->assertOk()->assertJsonPath('agregados', 0)->assertJsonPath('message', 'Los documentos sugeridos ya estaban configurados.');

        $this->actingAs($admin)->getJson('/api/comex/expedientes')->assertOk()->assertJsonPath('configurado', true);
        $this->actingAs($admin)->getJson('/api/comex/configuracion')->assertOk()->assertJsonPath('sugeridos_faltantes', []);
    }

    public function test_el_expediente_pide_documentos_segun_transporte_cliente_y_destino(): void
    {
        $this->cargarSugeridos();
        $origen = $this->tipo('certificado_origen');
        $origen->update(['exigencia' => 'paises']);
        PaisTipoDocumento::query()->create(['tipo_documento_id' => $origen->id, 'pais_iso2' => 'CN', 'pais_nombre' => 'China']);
        $servicio = $this->clienteOperacion('EXPORTADORA SERVICIO SPA');
        $propia = $this->clienteOperacion('EXPORTACION PROPIA SPA');
        ClienteComex::query()->create(['cliente_id' => $propia, 'cliente_nombre' => 'EXPORTACION PROPIA SPA', 'gestion' => 'propia']);

        $china = $this->embarque($servicio, $this->hoy(10), 'maritimo', [['CN', ['booking' => 'BK-778']]]);
        $aereo = $this->embarque($propia, $this->hoy(10), 'aereo', [['US']]);
        $sinDefinir = $this->embarque($servicio, $this->hoy(10), 'por_confirmar', [[null]]);
        $admin = $this->administradorComex();

        $documentos = collect($this->actingAs($admin)->getJson("/api/comex/expedientes/{$china}")->assertOk()->json('documentos'))->keyBy('tipo.codigo');
        $this->assertSame(
            ['instructivo', 'booking', 'planilla_sag', 'dus', 'guia_despacho', 'fitosanitario', 'bl', 'certificado_origen'],
            $documentos->where('exigencia', 'exigido')->keys()->all(),
        );
        $this->assertSame('opcional', $documentos['termografo']['exigencia']);
        $this->assertFalse($documentos->has('awb'));
        $this->assertFalse($documentos->has('factura_exportacion'));
        $this->assertSame(['etiqueta' => 'Booking', 'valores' => ['BK-778']], $documentos['booking']['referencia']);
        $this->assertSame('pendiente', $documentos['booking']['estado']);

        $documentos = collect($this->actingAs($admin)->getJson("/api/comex/expedientes/{$aereo}")->json('documentos'))->keyBy('tipo.codigo');
        $this->assertSame('exigido', $documentos['awb']['exigencia']);
        $this->assertSame('exigido', $documentos['factura_exportacion']['exigencia']);
        $this->assertFalse($documentos->has('bl'));
        $this->assertFalse($documentos->has('certificado_origen'), 'Estados Unidos no exige el certificado de origen.');

        $detalle = $this->actingAs($admin)->getJson("/api/comex/expedientes/{$sinDefinir}")->json();
        $documentos = collect($detalle['documentos'])->keyBy('tipo.codigo');
        $this->assertSame('por_definir', $documentos['booking']['exigencia']);
        $this->assertStringContainsString('la modalidad de transporte', $documentos['booking']['motivo_exigencia']);
        $this->assertSame('por_definir', $documentos['certificado_origen']['exigencia']);
        $this->assertStringContainsString('el país de destino', $documentos['certificado_origen']['motivo_exigencia']);
        // Sin modalidad no se sabe si va booking, CRT, BL o guía aérea; sin país, si va el certificado de origen.
        $this->assertSame(['booking', 'crt', 'bl', 'awb', 'certificado_origen'], $documentos->where('exigencia', 'por_definir')->keys()->values()->all());
        $this->assertSame(5, $detalle['resumen']['por_definir']);
    }

    public function test_registra_un_documento_con_su_respaldo_y_conserva_el_reemplazado(): void
    {
        $this->cargarSugeridos();
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(5));
        $booking = $this->tipo('booking');
        $usuario = $this->conPerfil(RolUsuario::Despachador, [CatalogoModulosAcceso::OFICINA_COMEX_EXPEDIENTES]);
        $ruta = "/api/comex/expedientes/{$embarque}/documentos/{$booking->id}";

        $this->actingAs($usuario)->post($ruta, ['estado' => 'listo', 'numero' => 'BK-1', 'version' => 0], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['archivo' => 'Para marcarlo listo, suba el respaldo (PDF o imagen).']);

        $primero = UploadedFile::fake()->createWithContent('booking.pdf', "%PDF-1.4\nreserva uno\n%%EOF");
        $respuesta = $this->actingAs($usuario)->post($ruta, ['estado' => 'listo', 'numero' => ' BK-1 ', 'version' => 0, 'archivo' => $primero], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Reserva de espacio (booking): listo.');
        $item = collect($respuesta->json('data.documentos'))->firstWhere('tipo.codigo', 'booking');
        $this->assertSame('listo', $item['estado']);
        $this->assertTrue($item['resuelto']);
        $this->assertSame('BK-1', $item['numero']);
        $this->assertSame(1, $item['version']);
        $this->assertSame('booking.pdf', $item['archivo']['nombre']);

        // El mismo archivo otra vez (doble clic) no se duplica ni cambia la versión.
        $repetido = UploadedFile::fake()->createWithContent('booking.pdf', "%PDF-1.4\nreserva uno\n%%EOF");
        $this->actingAs($usuario)->post($ruta, ['estado' => 'listo', 'numero' => 'BK-1', 'version' => 1, 'archivo' => $repetido], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(1, ArchivoDocumento::query()->count());
        $this->assertSame(1, Documento::query()->sole()->version);

        $segundo = UploadedFile::fake()->createWithContent('booking-corregido.pdf', "%PDF-1.4\nreserva dos\n%%EOF");
        $item = collect($this->actingAs($usuario)->post($ruta, ['estado' => 'listo', 'numero' => 'BK-1', 'version' => 1, 'archivo' => $segundo], ['Accept' => 'application/json'])
            ->assertOk()->json('data.documentos'))->firstWhere('tipo.codigo', 'booking');
        $this->assertSame(2, $item['version']);
        $this->assertSame('booking-corregido.pdf', $item['archivo']['nombre']);
        $this->assertCount(2, $item['archivos']);
        $this->assertNotNull($item['archivos'][1]['reemplazado_at']);
        $this->assertCount(2, Storage::disk('local')->allFiles("comex/documentos/{$embarque}"));

        $vigente = $this->actingAs($usuario)->get("{$ruta}/archivo")->assertOk();
        $this->assertSame('no-store, private', $vigente->headers->get('Cache-Control'));
        $this->assertStringContainsString('reserva dos', $vigente->streamedContent());
        $anterior = $this->actingAs($usuario)->get("/api/comex/archivos/{$item['archivos'][1]['id']}")->assertOk();
        $this->assertStringContainsString('reserva uno', $anterior->streamedContent());

        $historial = $this->actingAs($usuario)->getJson("/api/comex/expedientes/{$embarque}")->json('historial');
        $this->assertSame(['archivo_reemplazado', 'archivo_subido', 'registrado'], array_column($historial, 'tipo'));
        $this->assertSame([null, 'listo'], $historial[2]['datos']['cambios']['estado']);
        $this->assertSame([null, 'BK-1'], $historial[2]['datos']['cambios']['numero']);
    }

    public function test_una_version_desactualizada_no_pisa_lo_que_registro_otra_persona(): void
    {
        $this->cargarSugeridos();
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(5));
        $dus = $this->tipo('dus');
        $admin = $this->administradorComex();
        $ruta = "/api/comex/expedientes/{$embarque}/documentos/{$dus->id}";

        $this->actingAs($admin)->postJson($ruta, ['estado' => 'en_tramite', 'numero' => '123', 'version' => 0])->assertOk();
        $this->actingAs($admin)->postJson($ruta, ['estado' => 'en_tramite', 'numero' => '456', 'version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('123', Documento::query()->sole()->numero);
    }

    public function test_no_aplica_exige_motivo_y_resuelve_el_documento(): void
    {
        $this->cargarSugeridos();
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(5));
        $planilla = $this->tipo('planilla_sag');
        $admin = $this->administradorComex();
        $ruta = "/api/comex/expedientes/{$embarque}/documentos/{$planilla->id}";

        $this->actingAs($admin)->postJson($ruta, ['estado' => 'no_aplica', 'version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['motivo' => 'Indique por qué este documento no aplica al embarque.']);
        $item = collect($this->actingAs($admin)->postJson($ruta, ['estado' => 'no_aplica', 'motivo' => 'Mercado sin inspección en origen.', 'numero' => 'X', 'version' => 0])
            ->assertOk()->json('data.documentos'))->firstWhere('tipo.codigo', 'planilla_sag');
        $this->assertTrue($item['resuelto']);
        $this->assertSame('Mercado sin inspección en origen.', $item['motivo_no_aplica']);
    }

    public function test_el_panel_ordena_el_trabajo_con_alertas(): void
    {
        $this->cargarSugeridos();
        $cliente = $this->clienteOperacion('EXPORTADORA DEMO SPA');
        $sinSeguimiento = $this->clienteOperacion('CLIENTE SIN SEGUIMIENTO');
        ClienteComex::query()->create(['cliente_id' => $sinSeguimiento, 'cliente_nombre' => 'CLIENTE SIN SEGUIMIENTO', 'gestion' => 'sin_seguimiento']);

        $urgente = $this->embarque($cliente, $this->hoy(1));
        $pendiente = $this->embarque($cliente, $this->hoy(12));
        $salio = $this->embarque($cliente, $this->hoy(-2));
        $this->cargaParaEmbarque($salio, 'cerrada', now()->subDay()->toDateTimeString());
        $cancelado = $this->embarque($cliente, $this->hoy(3), atributos: ['estado' => 'cancelado']);
        $noSeguido = $this->embarque($sinSeguimiento, $this->hoy(3));
        $completo = $this->embarque($cliente, $this->hoy(4), 'terrestre', [['AR']]);
        foreach (['instructivo', 'crt', 'planilla_sag', 'dus', 'guia_despacho', 'fitosanitario'] as $codigo) {
            Documento::query()->create(['embarque_id' => $completo, 'embarque_codigo' => 'CX', 'tipo_documento_id' => $this->tipo($codigo)->id, 'estado' => 'no_aplica', 'motivo_no_aplica' => 'Prueba de panel.']);
        }

        $panel = $this->actingAs($this->administradorComex())->getJson('/api/comex/expedientes')->assertOk()->json();
        $alertas = collect($panel['data'])->pluck('alerta', 'id');
        $this->assertSame('urgente', $alertas[$urgente]);
        $this->assertSame('pendiente', $alertas[$pendiente]);
        $this->assertSame('salio_incompleto', $alertas[$salio]);
        $this->assertSame('cancelado', $alertas[$cancelado]);
        $this->assertSame('sin_seguimiento', $alertas[$noSeguido]);
        $this->assertSame('completo', $alertas[$completo]);
        $this->assertSame(['embarques' => 4, 'completos' => 1, 'pendientes' => 1, 'urgentes' => 1, 'salieron_incompletos' => 1], array_intersect_key($panel['resumen'], array_flip(['embarques', 'completos', 'pendientes', 'urgentes', 'salieron_incompletos'])));
        $this->assertSame(2, $panel['resumen']['no_seguidos']);
        $faltantes = collect($panel['data'])->firstWhere('id', $urgente)['faltantes'];
        $this->assertContains(['nombre' => 'DUS (Documento Único de Salida)', 'momento' => 'antes_salida', 'estado' => 'pendiente'], $faltantes);

        $this->actingAs($this->administradorComex())->getJson('/api/comex/expedientes?desde=2026-01-01&hasta=2026-12-31')
            ->assertUnprocessable()->assertJsonValidationErrors('hasta');
    }

    public function test_un_embarque_cancelado_no_se_edita(): void
    {
        $this->cargarSugeridos();
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(5), atributos: ['estado' => 'cancelado']);

        $this->actingAs($this->administradorComex())->postJson("/api/comex/expedientes/{$embarque}/documentos/{$this->tipo('dus')->id}", ['estado' => 'en_tramite', 'version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['embarque' => 'El embarque está cancelado: su expediente ya no se edita.']);
        $this->actingAs($this->administradorComex())->getJson('/api/comex/expedientes/'.fake()->uuid())->assertNotFound();
    }

    public function test_permisos_de_consulta_gestion_y_configuracion(): void
    {
        $this->cargarSugeridos();
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(5));
        $ruta = "/api/comex/expedientes/{$embarque}/documentos/{$this->tipo('dus')->id}";

        $consulta = $this->conPerfil(RolUsuario::Consulta, [CatalogoModulosAcceso::OFICINA_COMEX_EXPEDIENTES]);
        $this->actingAs($consulta)->getJson('/api/comex/expedientes')->assertOk()->assertJsonPath('permisos', ['gestionar' => false, 'configurar' => false]);
        $this->actingAs($consulta)->postJson($ruta, ['estado' => 'en_tramite', 'version' => 0])->assertForbidden();

        $sinModulo = $this->conPerfil(RolUsuario::Despachador, ['frigorifico.cargas']);
        $this->actingAs($sinModulo)->getJson('/api/comex/expedientes')->assertForbidden();
        $this->actingAs($sinModulo)->getJson('/api/comex/configuracion')->assertForbidden();

        $gestor = $this->conPerfil(RolUsuario::Despachador, [CatalogoModulosAcceso::OFICINA_COMEX_EXPEDIENTES]);
        $this->actingAs($gestor)->postJson($ruta, ['estado' => 'en_tramite', 'version' => 0])->assertOk();
        $this->actingAs($gestor)->postJson('/api/comex/configuracion/sugeridos')->assertForbidden();

        $configurador = $this->conPerfil(RolUsuario::Despachador, [CatalogoModulosAcceso::OFICINA_COMEX_CONFIGURACION]);
        $this->actingAs($configurador)->getJson('/api/comex/configuracion')->assertOk()->assertJsonPath('permisos.configurar', true);
        $this->actingAs($configurador)->postJson('/api/comex/configuracion/sugeridos')->assertOk();
    }

    public function test_comex_solo_consulta_la_base_operativa(): void
    {
        $this->cargarSugeridos();
        $embarque = $this->embarque($this->clienteOperacion('EXPORTADORA DEMO SPA'), $this->hoy(5));
        $carga = $this->cargaParaEmbarque($embarque);
        $this->folioEnCarga($carga, 'PT-0001', [['csg' => '100001', 'cantidad_cajas' => 120]]);
        $admin = $this->administradorComex();

        $sentencias = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $consulta) use (&$sentencias): void {
            if ($consulta->connectionName === DB::getDefaultConnection()) {
                $sentencias[] = $consulta->sql;
            }
        });
        $this->actingAs($admin)->getJson('/api/comex/expedientes')->assertOk();
        $this->actingAs($admin)->getJson("/api/comex/expedientes/{$embarque}")->assertOk();
        $this->actingAs($admin)->postJson("/api/comex/expedientes/{$embarque}/documentos/{$this->tipo('dus')->id}", ['estado' => 'en_tramite', 'version' => 0])->assertOk();
        $this->actingAs($admin)->get("/api/comex/expedientes/{$embarque}/packing-list")->assertOk();

        $operativas = array_filter($sentencias, fn (string $sql): bool => ! preg_match('/^\s*select\b/i', $sql)
            // La sesión de Sanctum y la caché de permisos no son datos operativos.
            && ! preg_match('/personal_access_tokens|sessions|cache/i', $sql));
        $this->assertNotEmpty($sentencias);
        $this->assertSame([], array_values($operativas), 'Comex solo consulta la base operativa.');
    }
}
