<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Carga;
use App\Models\ControlDespacho;
use App\Models\Dispositivo;
use App\Models\FotoControlDespacho;
use App\Models\PerfilAcceso;
use App\Models\User;
use App\Services\ControlDespacho\FormularioControlDespacho;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;
use ZipArchive;

class ControlDespachoApiTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public function test_consolidacion_sube_pallets_del_anden_con_su_posicion_sin_cambiar_el_carguio_del_camarero(): void
    {
        $contexto = $this->crearContextoFrio(5, 'R24A');
        [$primero, $segundo, $tercero, $cuarto] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo, $tercero, $cuarto]);
        $otra = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$contexto['folios'][4]]);
        [$token] = $this->consolidador('R24A');

        // Antes de que algo llegue al andén la carga no aparece y la planilla no se abre.
        $this->conToken($token)->getJson('/api/control-despacho/cargas')->assertOk()->assertJsonCount(0, 'data');
        $this->conToken($token)->postJson("/api/control-despacho/cargas/{$carga->id}/control", ['operacion_id' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('message', "La carga {$carga->codigo} todavía no está en el andén: la planilla se abre cuando llega el camión o el primer pallet.");

        $this->enviarAlAnden($contexto, $carga, $primero);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        $this->enviarAlAnden($contexto, $otra, $contexto['folios'][4]);
        $this->conToken($token)->getJson('/api/control-despacho/cargas')->assertOk()
            ->assertJsonFragment(['codigo' => $carga->codigo, 'en_anden' => 2, 'control' => null]);

        $operacion = (string) Str::uuid();
        $control = $this->conToken($token)->postJson("/api/control-despacho/cargas/{$carga->id}/control", ['operacion_id' => $operacion])
            ->assertOk()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.encabezado.exportadora', 'Exportadora prueba')
            ->assertJsonPath('data.encabezado.frigorifico_tipo', 'agrorosario')
            ->assertJsonPath('data.totales.pallets', 4)
            ->assertJsonPath('data.totales.en_anden', 2)
            ->assertJsonPath('data.totales.en_camara', 2)
            ->json('data');
        // Reabrir desde otra PDA devuelve la misma planilla.
        $this->conToken($token)->postJson("/api/control-despacho/cargas/{$carga->id}/control", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.id', $control['id']);

        // El pallet que espera en el andén sube con la misma transición del camarero.
        $this->posicion($token, $control['id'], strtolower($primero->numero_folio), 1, ['codigo_pe' => 'p', 'temperatura' => 0.4])
            ->assertOk()
            ->assertJsonPath('data.totales.cargados', 1)
            ->assertJsonPath('data.totales.posicionados', 1)
            ->assertJsonPath('data.pallets.0.numero_folio', $primero->numero_folio)
            ->assertJsonPath('data.pallets.0.estado_carga', 'cargado')
            ->assertJsonPath('data.pallets.0.posicion.posicion', 1)
            ->assertJsonPath('data.pallets.0.posicion.codigo_pe', 'P')
            ->assertJsonPath('data.pallets.0.posicion.temperatura', 0.4);
        $this->posicion($token, $control['id'], $primero->numero_folio, 1, ['codigo_pe' => 'X'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'P/E es la orientación del pallet: P (punta) o E (espejo).');
        $evento = $carga->eventos()->where('tipo', 'folio_cargado')->sole();
        $this->assertSame('consolidacion', $evento->datos['registrado_desde']);
        $this->assertSame(1, $evento->datos['posicion']);

        // El camarero sigue cargando como siempre; consolidación solo agrega la posición.
        $this->conToken($contexto['token'])->postJson("/api/cargas/{$carga->id}/cargados", [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $segundo->numero_folio,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();
        $this->posicion($token, $control['id'], $segundo->numero_folio, 1)
            ->assertUnprocessable()
            ->assertJsonPath('message', "La posición 1 ya la ocupa el folio {$primero->numero_folio}. Confirma si ambos comparten la posición.");
        $this->posicion($token, $control['id'], $segundo->numero_folio, 2, ['cajas' => 70])
            ->assertOk()
            ->assertJsonPath('data.totales.posicionados', 2)
            ->assertJsonPath('data.totales.cajas_pcd', 70);
        $this->assertSame(2, $carga->eventos()->where('tipo', 'folio_cargado')->count());

        // Un pallet en cámara o de otra carga no sube.
        $this->posicion($token, $control['id'], $tercero->numero_folio, 3)
            ->assertUnprocessable()
            ->assertJsonPath('message', "El folio {$tercero->numero_folio} todavía no sale de la cámara: el camarero debe enviarlo al andén.");
        $this->posicion($token, $control['id'], $contexto['folios'][4]->numero_folio, 3)
            ->assertUnprocessable()
            ->assertJsonPath('message', "El folio {$contexto['folios'][4]->numero_folio} pertenece a la carga {$otra->codigo}, no a {$carga->codigo}. No lo subas a este camión.");
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $contexto['folios'][4]->id, 'estado' => 'en_anden']);

        // Corregir la posición no duplica el pallet; la misma lectura reenviada tampoco.
        $reenvio = (string) Str::uuid();
        $generadoAt = now()->toAtomString();
        $this->posicion($token, $control['id'], $primero->numero_folio, 5, ['temperatura' => -0.5], $reenvio, $generadoAt)->assertOk();
        $this->posicion($token, $control['id'], $primero->numero_folio, 5, ['temperatura' => -0.5], $reenvio, $generadoAt)
            ->assertOk()->assertJsonPath('data.totales.posicionados', 2);
        $this->assertDatabaseHas('posiciones_control_despacho', ['folio_id' => $primero->id, 'posicion' => 5, 'temperatura' => -0.5]);

        // Quitar de la planilla no baja el pallet del camión.
        $posicion = collect($this->conToken($token)->getJson("/api/control-despacho/controles/{$control['id']}")->json('data.pallets'))
            ->firstWhere('numero_folio', $segundo->numero_folio)['posicion'];
        $this->conToken($token)->deleteJson("/api/control-despacho/controles/{$control['id']}/posiciones/{$posicion['id']}", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.totales.posicionados', 1)->assertJsonPath('data.totales.cargados', 2);
        $this->assertDatabaseHas('carga_folios', ['folio_id' => $segundo->id, 'estado' => 'cargado']);
    }

    public function test_cerrar_exige_inspeccion_y_posiciones_y_congela_la_planilla(): void
    {
        $contexto = $this->crearContextoFrio(2, 'R24B');
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo]);
        $this->enviarAlAnden($contexto, $carga, $primero);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        [$token, $usuario] = $this->consolidador('R24B');
        $control = $this->iniciar($token, $carga);
        $this->posicion($token, $control['id'], $primero->numero_folio, 1)->assertOk();

        $this->cerrar($token, $control['id'], $control['version'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Antes de cerrar la planilla: Indica si es contenedor o thermo. Responde la inspección visual: faltan 13 puntos. Faltan 1 pallets por subir: '.$segundo->numero_folio.'.');

        $inspeccion = collect(FormularioControlDespacho::inspeccion())->map(fn (): string => 'si')->all();
        $inspeccion['horometro'] = 'na';
        $guardado = $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(),
            'version' => $control['version'],
            'encabezado' => ['tipo_camion' => 'contenedor', 'contenedor' => ' mscu1234567 ', 'empresa_consolidacion' => 'Consolida SpA', 'exportadora' => 'Exportadora corregida'],
            'inspeccion' => $inspeccion,
            'cierre' => ['jefe_seccion' => 'Jefa Turno', 'pallets_chep_1000' => 2],
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.encabezado.contenedor', 'MSCU1234567')
            // Lo confirmado por el equipo reemplaza la sugerencia de FoliOS.
            ->assertJsonPath('data.encabezado.exportadora', 'Exportadora corregida')
            ->assertJsonPath('data.sugerido.encabezado.exportadora', 'Exportadora prueba')
            ->assertJsonPath('data.inspeccion.horometro', 'na')
            ->json('data');

        // Otro equipo con la versión anterior no pisa lo guardado.
        $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(), 'version' => $control['version'], 'cierre' => ['sello_lavado' => 'X1'],
        ])->assertConflict();
        $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(), 'version' => $guardado['version'], 'inspeccion' => ['puertas' => 'quizas'],
        ])->assertUnprocessable()->assertJsonValidationErrors('inspeccion.puertas');

        $this->posicion($token, $control['id'], $segundo->numero_folio, 2, ['temperatura' => 1.2])->assertOk();
        $cerrada = $this->cerrar($token, $control['id'], $guardado['version'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'cerrado')
            ->assertJsonPath('data.cierre.responsable', $usuario->name)
            ->assertJsonPath('data.encabezado.empresa_consolidacion', 'Consolida SpA')
            ->assertJsonPath('data.faltantes_cierre', [])
            ->json('data');
        $registro = ControlDespacho::query()->findOrFail($control['id']);
        $this->assertSame('agrorosario', $registro->encabezado['frigorifico_tipo']);
        $this->assertSame(2, collect($registro->planilla_cerrada['pallets'])->whereNotNull('posicion')->count());

        // La T° de pulpa no bloquea el cierre, pero sí la impresión: se completa con la planilla cerrada.
        $this->assertSame(["Falta la T° de pulpa de 1 pallets: {$primero->numero_folio}."], $cerrada['faltantes_impresion']);
        $this->conToken($token)->get("/api/control-despacho/controles/{$control['id']}/planilla.xlsx")
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'faltan_datos_impresion')
            ->assertJsonPath('message', "Antes de imprimir: Falta la T° de pulpa de 1 pallets: {$primero->numero_folio}.");
        $pallets = collect($cerrada['pallets'])->keyBy('numero_folio');
        // Lo ya firmado no se cambia: solo se completan las que faltan.
        $this->conToken($token)->postJson("/api/control-despacho/controles/{$control['id']}/temperaturas", [
            'operacion_id' => (string) Str::uuid(),
            'temperaturas' => [['posicion_id' => $pallets[$segundo->numero_folio]['posicion']['id'], 'temperatura' => 0.1]],
        ])->assertUnprocessable()->assertJsonPath('message', "La T° de pulpa del folio {$segundo->numero_folio} ya está registrada: con la planilla cerrada solo se completan las que faltan.");
        // Oficina también completa, sin PDA.
        $this->actingAs($contexto['administrador'], 'sanctum')->postJson("/api/control-despacho/controles/{$control['id']}/temperaturas", [
            'operacion_id' => (string) Str::uuid(),
            'temperaturas' => [['posicion_id' => $pallets[$primero->numero_folio]['posicion']['id'], 'temperatura' => -0.3]],
        ])->assertOk()->assertJsonPath('data.faltantes_impresion', [])->assertJsonPath('data.estado', 'cerrado');
        $this->assertEquals(-0.3, collect(ControlDespacho::query()->findOrFail($control['id'])->planilla_cerrada['pallets'])->firstWhere('numero_folio', $primero->numero_folio)['posicion']['temperatura']);
        $this->conToken($token)->get("/api/control-despacho/controles/{$control['id']}/planilla.xlsx")->assertOk();

        $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(), 'version' => $cerrada['version'], 'cierre' => ['sello_lavado' => 'X1'],
        ])->assertUnprocessable()->assertJsonPath('message', 'La planilla está cerrada. Supervisión puede reabrirla con un motivo.');

        // Solo supervisión reabre, con motivo.
        $this->conToken($token)->postJson("/api/control-despacho/controles/{$control['id']}/reabrir", [
            'operacion_id' => (string) Str::uuid(), 'version' => $cerrada['version'], 'motivo' => 'Faltó el termógrafo',
        ])->assertForbidden();
        $this->actingAs($contexto['administrador'], 'sanctum')->postJson("/api/control-despacho/controles/{$control['id']}/reabrir", [
            'operacion_id' => (string) Str::uuid(), 'version' => $cerrada['version'], 'motivo' => 'Faltó el termógrafo de control',
        ])->assertOk()
            ->assertJsonPath('data.estado', 'borrador')
            ->assertJsonPath('data.reaperturas.0.motivo', 'Faltó el termógrafo de control');
    }

    public function test_la_planilla_xlsx_usa_la_plantilla_oficial_y_continua_en_otra_hoja(): void
    {
        $contexto = $this->crearContextoFrio(2, 'R24C');
        [$primero, $segundo] = $contexto['folios'];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$primero, $segundo]);
        $this->enviarAlAnden($contexto, $carga, $primero);
        $this->enviarAlAnden($contexto, $carga, $segundo);
        [$token] = $this->consolidador('R24C');
        $control = $this->iniciar($token, $carga);
        $this->posicion($token, $control['id'], $primero->numero_folio, 1, ['temperatura' => 0.5, 'cajas' => 80])->assertOk();
        $this->posicion($token, $control['id'], $segundo->numero_folio, 24, ['codigo_pe' => 'E'])->assertOk();
        $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(), 'version' => 1,
            'encabezado' => ['tipo_camion' => 'thermo', 'patente_camion' => 'abcd12'],
            'inspeccion' => ['puertas' => 'no'],
            'cierre' => ['observaciones' => 'Carga revisada sin observaciones relevantes para el cliente final.'],
        ])->assertOk();

        $this->conToken($token)->get("/api/control-despacho/controles/{$control['id']}/planilla.xlsx")
            ->assertUnprocessable()->assertJsonPath('codigo', 'faltan_datos_impresion');
        $posicion = collect($this->conToken($token)->getJson("/api/control-despacho/controles/{$control['id']}")->json('data.pallets'))
            ->firstWhere('numero_folio', $segundo->numero_folio)['posicion']['id'];
        $this->conToken($token)->postJson("/api/control-despacho/controles/{$control['id']}/temperaturas", [
            'operacion_id' => (string) Str::uuid(), 'temperaturas' => [['posicion_id' => $posicion, 'temperatura' => 1.5]],
        ])->assertOk()->assertJsonPath('data.faltantes_impresion', []);

        $respuesta = $this->conToken($token)->get("/api/control-despacho/controles/{$control['id']}/planilla.xlsx")->assertOk();
        $this->assertStringContainsString("R-24_{$carga->codigo}.xlsx", (string) $respuesta->headers->get('content-disposition'));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $libro = $zip->getFromName('xl/workbook.xml');
        $primera = $zip->getFromName('xl/worksheets/sheet1.xml');
        $segunda = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();

        $this->assertStringContainsString('PLANILLA CONTROL DESPACHO (2)', $libro);
        $this->assertStringContainsString($primero->numero_folio, $primera);
        $this->assertStringContainsString('ABCD12', $primera);
        $this->assertStringContainsString('<c r="AD6"', $primera);
        $this->assertMatchesRegularExpression('/<c r="AD6"[^>]*t="inlineStr"><is><t xml:space="preserve">X<\/t>/', $primera);
        $this->assertMatchesRegularExpression('/<c r="N27"[^>]*t="inlineStr"><is><t xml:space="preserve">X<\/t>/', $primera);
        $this->assertStringContainsString('BORRADOR: planilla sin cerrar', $primera);
        $this->assertStringNotContainsString($segundo->numero_folio, $primera);
        // La hoja 2 parte en 23 | 24: la posición 24 es la primera fila de la derecha.
        $this->assertMatchesRegularExpression('/<c r="T33"[^>]*t="inlineStr"><is><t xml:space="preserve">'.preg_quote($segundo->numero_folio, '/').'<\/t>/', $segunda);
        $this->assertMatchesRegularExpression('/<c r="Q33"[^>]*><v>24<\/v>/', $segunda);
        $this->assertMatchesRegularExpression('/<c r="B33"[^>]*><v>23<\/v>/', $segunda);
    }

    public function test_las_fotos_son_opcionales_e_idempotentes(): void
    {
        Storage::fake('local');
        $contexto = $this->crearContextoFrio(1, 'R24D');
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], $contexto['folios']);
        $this->enviarAlAnden($contexto, $carga, $contexto['folios'][0]);
        [$token] = $this->consolidador('R24D');
        $control = $this->iniciar($token, $carga);
        $catalogo = $this->conToken($token)->getJson('/api/control-despacho/catalogo')->assertOk()->json('data');
        $this->assertSame(
            ['contenedor', 'patente', 'temperatura_corte', 'temperatura_pulpa', 'termografos', 'primer_bloque', 'altura_carga', 'cierre_carga', 'general'],
            collect($catalogo['tipos_foto'])->pluck('clave')->all(),
        );
        $this->assertFalse($catalogo['exige_fotos']);
        $this->assertSame(['altura_carga', 'general'], collect($catalogo['tipos_foto'])->where('obligatoria', false)->pluck('clave')->values()->all());
        $this->assertSame(['P' => 'Punta', 'E' => 'Espejo'], collect($catalogo['orientaciones'])->pluck('nombre', 'clave')->all());

        $operacion = (string) Str::uuid();
        $archivo = UploadedFile::fake()->image('contenedor.jpg', 640, 480);
        $foto = $this->conToken($token)->post("/api/control-despacho/controles/{$control['id']}/fotos", ['tipo' => 'general', 'operacion_id' => $operacion, 'archivo' => $archivo])
            ->assertCreated()->json('data');
        $this->conToken($token)->post("/api/control-despacho/controles/{$control['id']}/fotos", ['tipo' => 'general', 'operacion_id' => $operacion, 'archivo' => $archivo])
            ->assertOk()->assertJsonPath('data.id', $foto['id']);
        $this->conToken($token)->get($foto['miniatura_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->conToken($token)->getJson("/api/control-despacho/controles/{$control['id']}")->assertOk()->assertJsonCount(1, 'data.fotos');

        $this->conToken($token)->deleteJson("/api/control-despacho/controles/{$control['id']}/fotos/{$foto['id']}")->assertNoContent();
        $this->conToken($token)->getJson("/api/control-despacho/controles/{$control['id']}")->assertOk()->assertJsonCount(0, 'data.fotos');
    }

    public function test_el_set_fotografico_marca_pendientes_y_solo_bloquea_el_cierre_cuando_se_exige(): void
    {
        Storage::fake('local');
        $contexto = $this->crearContextoFrio(1, 'R24F');
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);
        [$token] = $this->consolidador('R24F');
        $control = $this->iniciar($token, $carga);
        $this->posicion($token, $control['id'], $folio->numero_folio, 1, ['temperatura' => 0.2])->assertOk();
        $guardado = $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(), 'version' => 1,
            'encabezado' => ['tipo_camion' => 'contenedor', 'termografo_1' => 'TG-1', 'termografo_control' => 'TG-INT'],
            'inspeccion' => collect(FormularioControlDespacho::inspeccion())->map(fn (): string => 'si')->all(),
        ])->assertOk()->json('data');
        $pendientes = collect($guardado['fotos_pendientes'])->keyBy('tipo');
        $this->assertSame(['contenedor', 'patente', 'temperatura_corte', 'temperatura_pulpa', 'termografos', 'primer_bloque', 'cierre_carga'], $pendientes->keys()->all());
        // Una foto por termógrafo anotado.
        $this->assertSame(2, $pendientes['termografos']['requeridas']);

        $this->conToken($token)->post("/api/control-despacho/controles/{$control['id']}/fotos", [
            'tipo' => 'termografos', 'operacion_id' => (string) Str::uuid(), 'archivo' => UploadedFile::fake()->image('tg.jpg', 320, 240),
        ])->assertCreated();
        $detalle = $this->conToken($token)->getJson("/api/control-despacho/controles/{$control['id']}")->assertOk()->json('data');
        $this->assertSame(['requeridas' => 2, 'tomadas' => 1], collect(collect($detalle['fotos_pendientes'])->firstWhere('tipo', 'termografos'))->only(['requeridas', 'tomadas'])->all());
        $this->assertSame([], $detalle['faltantes_cierre'], 'Sin exigir el set, las fotos no bloquean el cierre.');

        config(['despacho.control_exige_fotos' => true]);
        $this->cerrar($token, $control['id'], $detalle['version'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Antes de cerrar la planilla: Faltan fotos: Número de contenedor, Placa patente, Temperatura de corte del equipo, Temperatura de pulpa, Postura de termógrafos (1 de 2), Primer bloque (mitad de carga), Cierre de carga.');
        config(['despacho.control_exige_fotos' => false]);
        $this->cerrar($token, $control['id'], $detalle['version'])->assertOk()->assertJsonPath('data.estado', 'cerrado');
    }

    public function test_al_cerrar_las_fotos_pasan_a_un_pdf_y_se_borran_los_archivos_sueltos(): void
    {
        Storage::fake('local');
        $contexto = $this->crearContextoFrio(1, 'R24G');
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);
        [$token] = $this->consolidador('R24G');
        $control = $this->iniciar($token, $carga);
        $this->posicion($token, $control['id'], $folio->numero_folio, 1, ['temperatura' => 0.2])->assertOk();
        $guardado = $this->conToken($token)->putJson("/api/control-despacho/controles/{$control['id']}", [
            'operacion_id' => (string) Str::uuid(), 'version' => 1,
            'encabezado' => ['tipo_camion' => 'thermo', 'patente_camion' => 'ABCD12'],
            'inspeccion' => collect(FormularioControlDespacho::inspeccion())->map(fn (): string => 'si')->all(),
        ])->assertOk()->json('data');

        // Al subir, la foto se guarda comprimida: JPEG de hasta 1280 px, no el original del equipo.
        $subir = fn (string $tipo, int $ancho, int $alto) => $this->conToken($token)->post("/api/control-despacho/controles/{$control['id']}/fotos", [
            'tipo' => $tipo, 'operacion_id' => (string) Str::uuid(), 'archivo' => UploadedFile::fake()->image("{$tipo}.png", $ancho, $alto),
        ])->assertCreated()->json('data');
        $patente = $subir('patente', 3200, 2400);
        $cierre = $subir('cierre_carga', 800, 1200);
        $this->assertSame([1280, 960], [$patente['ancho'], $patente['alto']]);
        $rutas = FotoControlDespacho::query()->pluck('ruta')->all();
        $this->assertCount(2, $rutas);
        foreach ($rutas as $ruta) {
            $this->assertStringEndsWith('.jpg', $ruta);
            $this->assertStringStartsWith("\xFF\xD8", Storage::disk('local')->get($ruta));
        }
        $this->conToken($token)->get("/api/control-despacho/controles/{$control['id']}/fotos.pdf")->assertUnprocessable();

        $cerrada = $this->cerrar($token, $control['id'], $guardado['version'])->assertOk()
            ->assertJsonPath('data.fotos_pdf.cantidad', 2)
            ->assertJsonPath('data.fotos.0.archivada', true)
            ->assertJsonPath('data.fotos.0.miniatura_url', null)
            ->json('data');
        // En el servidor queda solo el PDF.
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles("control-despacho/{$control['id']}"), fn (string $ruta): bool => ! str_ends_with($ruta, '.pdf'))));
        $this->assertCount(1, Storage::disk('local')->allFiles("control-despacho/{$control['id']}"));
        $this->conToken($token)->get($patente['miniatura_url'])->assertNotFound();
        $pdf = $this->conToken($token)->get("/api/control-despacho/controles/{$control['id']}/fotos.pdf")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->streamedContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Placa patente', $pdf);
        $this->assertStringContainsString('Cierre de carga', $pdf);
        $this->assertSame(1, preg_match_all('#/Type /Page /Parent#', $pdf));

        // Reabierta: se quita una foto ya archivada y se agrega otra; al cerrar, el PDF se rearma.
        $reabierta = $this->actingAs($contexto['administrador'], 'sanctum')->postJson("/api/control-despacho/controles/{$control['id']}/reabrir", [
            'operacion_id' => (string) Str::uuid(), 'version' => $cerrada['version'], 'motivo' => 'Falta la foto del primer bloque',
        ])->assertOk()->json('data');
        $this->conToken($token)->deleteJson("/api/control-despacho/controles/{$control['id']}/fotos/{$cierre['id']}")->assertNoContent();
        $subir('primer_bloque', 1000, 700);
        $anterior = ControlDespacho::query()->findOrFail($control['id'])->fotos_pdf_ruta;
        $recerrada = $this->cerrar($token, $control['id'], $reabierta['version'])->assertOk()->assertJsonPath('data.fotos_pdf.cantidad', 2)->json('data');
        $this->assertFalse(Storage::disk('local')->exists($anterior));
        $archivos = Storage::disk('local')->allFiles("control-despacho/{$control['id']}");
        $this->assertCount(1, $archivos);
        $nuevo = Storage::disk('local')->get($archivos[0]);
        $this->assertStringContainsString('Primer bloque', $nuevo);
        $this->assertStringNotContainsString('Cierre de carga', $nuevo);
        // La foto que venía del PDF anterior se rescató intacta.
        $archivada = FotoControlDespacho::query()->findOrFail($patente['id']);
        $this->assertStringStartsWith("\xFF\xD8", substr($nuevo, $archivada->pdf_offset, $archivada->pdf_longitud));
        $this->assertEqualsCanonicalizing(['patente', 'primer_bloque'], collect($recerrada['fotos'])->pluck('tipo')->all());
    }

    public function test_solo_consolidacion_registra_y_oficina_consulta_el_listado(): void
    {
        $contexto = $this->crearContextoFrio(1, 'R24E');
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->enviarAlAnden($contexto, $carga, $folio);

        // El camarero no tiene el módulo; el despachador sin perfil tampoco.
        $this->conToken($contexto['token'])->getJson('/api/control-despacho/cargas')->assertForbidden();
        $this->conToken($contexto['tokenOficina'])->postJson("/api/control-despacho/cargas/{$carga->id}/control", ['operacion_id' => (string) Str::uuid()])->assertForbidden();

        [$token] = $this->consolidador('R24E');
        $control = $this->iniciar($token, $carga);
        $this->posicion($token, $control['id'], $folio->numero_folio, 1)->assertOk();

        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta, 'activo' => true]);
        $consulta->perfilAcceso()->associate(PerfilAcceso::query()->create([
            'codigo' => 'CONSULTA_R24', 'nombre' => 'Consulta R-24', 'rol_base' => RolUsuario::Consulta,
            'modulos' => ['frigorifico.control-despacho'], 'modulos_tablet' => [], 'activo' => true,
        ]))->save();
        $this->actingAs($consulta, 'sanctum')->getJson('/api/control-despacho/controles?buscar='.$folio->numero_folio)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.carga.codigo', $carga->codigo)
            ->assertJsonPath('data.0.pallets', 1)
            ->assertJsonPath('data.0.estado', 'borrador');
        $this->actingAs($consulta, 'sanctum')->getJson('/api/control-despacho/controles?estado=cerrado')->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($consulta, 'sanctum')->postJson("/api/control-despacho/controles/{$control['id']}/cerrar", ['operacion_id' => (string) Str::uuid(), 'version' => 1])->assertForbidden();
    }

    /** @return array{0: string, 1: User} */
    private function consolidador(string $sufijo): array
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Despachador, 'activo' => true]);
        $usuario->perfilAcceso()->associate(PerfilAcceso::query()->where('codigo', 'CONSOLIDACION')->firstOrFail())->save();
        $dispositivo = Dispositivo::create(['codigo' => "PDA-{$sufijo}", 'nombre' => "PDA {$sufijo}"]);

        return [$usuario->crearTokenParaDispositivo($dispositivo, "pda-{$sufijo}", ['tablet:consolidacion'])->plainTextToken, $usuario];
    }

    /** @return array<string, mixed> */
    private function iniciar(string $token, Carga $carga): array
    {
        return $this->conToken($token)->postJson("/api/control-despacho/cargas/{$carga->id}/control", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data');
    }

    /** @param  array<string, mixed>  $extra */
    private function posicion(string $token, string $control, string $folio, int $posicion, array $extra = [], ?string $operacion = null, ?string $generadoAt = null): TestResponse
    {
        return $this->conToken($token)->postJson("/api/control-despacho/controles/{$control}/posiciones", [
            'operacion_id' => $operacion ?? (string) Str::uuid(),
            'numero_folio' => $folio,
            'posicion' => $posicion,
            'generado_dispositivo_at' => $generadoAt ?? now()->toAtomString(),
            ...$extra,
        ]);
    }

    private function cerrar(string $token, string $control, int $version): TestResponse
    {
        return $this->conToken($token)->postJson("/api/control-despacho/controles/{$control}/cerrar", ['operacion_id' => (string) Str::uuid(), 'version' => $version]);
    }
}
