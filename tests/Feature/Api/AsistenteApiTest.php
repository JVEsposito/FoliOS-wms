<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\GuiaDespachoEnvase;
use App\Models\PreguntaAsistente;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

class AsistenteApiTest extends TestCase
{
    use PreparaAsistente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistente();
    }

    public function test_pregunta_tiempos_de_romana_hoy_responde_tiempos_romana(): void
    {
        $this->recepcion('REC-1', 9000);

        $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => '¿Cuáles son los tiempos de Romana de hoy?'])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'respuesta')
            ->assertJsonPath('data.modo', 'sin_ia')
            ->assertJsonPath('data.consulta', 'tiempos-romana')
            ->assertJsonPath('data.resultado.estado', 'respondida');
    }

    public function test_revisa_las_taras_de_hoy_responde_revision(): void
    {
        $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'revisa las taras de hoy'])
            ->assertOk()
            ->assertJsonPath('data.consulta', 'revisar-taras');
    }

    public function test_pregunta_con_codigo_rec_abre_expediente(): void
    {
        $this->recepcion('REC-2611-0108', 9000);

        $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'REC-2611-0108'])
            ->assertOk()
            ->assertJsonPath('data.consulta', 'expediente-por-codigo')
            ->assertJsonPath('data.resultado.filas.0.codigo', 'REC-2611-0108');
    }

    public function test_pregunta_sin_sentido_no_inventa_sugerencias_y_ofrece_busqueda(): void
    {
        $respuesta = $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'asdf qwerty 31-02'])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'sugerencias')
            ->assertJsonPath('data.busqueda', 'asdf qwerty 31-02');

        $this->assertSame([], $respuesta->json('data.sugerencias'));
        $this->assertStringContainsString('No tengo una consulta', $respuesta->json('data.mensaje'));
    }

    public function test_pregunta_ambigua_sugiere_solo_consultas_parecidas(): void
    {
        $claves = array_column($this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'tiempos de hoy'])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'sugerencias')
            ->json('data.sugerencias'), 'clave');

        sort($claves);
        $this->assertSame(['tiempos-cargas', 'tiempos-prefrio', 'tiempos-romana'], $claves);
    }

    public function test_respuesta_trae_linea_entendido_con_periodo_y_filtros(): void
    {
        $this->recepcion('REC-2611-0200', 9000);

        $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'kilos recibidos hoy de exportadora andina'])
            ->assertOk()
            ->assertJsonPath('data.consulta', 'kilos-recepciones')
            ->assertJsonPath('data.entendido.consulta', 'Kilos recibidos')
            ->assertJsonPath('data.entendido.fichas.1', 'Exportadora Andina')
            ->assertJsonPath('data.entendido.no_aplicados', []);
    }

    public function test_filtro_que_la_consulta_no_aplica_se_informa(): void
    {
        $entendido = $this->actingAs($this->administrador, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'tiempos de prefrio de ayer de exportadora andina'])
            ->assertOk()
            ->assertJsonPath('data.consulta', 'tiempos-prefrio')
            ->json('data.entendido');

        $this->assertNotContains('Exportadora Andina', $entendido['fichas']);
        $this->assertStringContainsString('No filtra por cliente (Exportadora Andina)', $entendido['no_aplicados'][0]);
    }

    public function test_numero_de_guia_encuentra_la_guia_de_despacho_de_envases(): void
    {
        GuiaDespachoEnvase::create([
            'operacion_id' => (string) Str::uuid(), 'numero' => 'GDE-2611-0163', 'numero_guia_despacho' => '2345',
            'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id,
            'cliente_codigo_snapshot' => $this->cliente->codigo, 'cliente_nombre_snapshot' => $this->cliente->nombre,
            'estado' => 'confirmada', 'salida_at' => now(), 'patente_camion' => 'ABCD12', 'version' => 1,
            'creado_por_user_id' => $this->administrador->id,
        ]);

        foreach (['busca la guia 2345', 'GDE-2611-0163'] as $texto) {
            $this->actingAs($this->administrador, 'sanctum')
                ->postJson('/api/asistente/preguntas', ['texto' => $texto])
                ->assertOk()
                ->assertJsonPath('data.consulta', 'expediente-por-codigo')
                ->assertJsonPath('data.resultado.filas.0.tipo', 'Guía de envases')
                ->assertJsonPath('data.resultado.filas.0.codigo', 'GDE-2611-0163 · guía 2345');
        }
    }

    public function test_texto_vacio_o_espacios_o_501_caracteres_da_422_y_no_registra(): void
    {
        foreach (['', '    ', str_repeat('a', 501)] as $texto) {
            $this->actingAs($this->administrador, 'sanctum')
                ->postJson('/api/asistente/preguntas', ['texto' => $texto])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('texto');
        }

        $this->assertSame(0, PreguntaAsistente::query()->count());
    }

    public function test_sugerencias_excluyen_consultas_sin_permiso(): void
    {
        $usuario = $this->usuarioAsistente(RolUsuario::OperadorRomana, [CatalogoModulosAcceso::OFICINA_ASISTENTE, 'materia-prima.romana']);

        $sugerencias = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/asistente/preguntas', ['texto' => 'tiempos de cargas'])
            ->assertOk()
            ->json('data.sugerencias');

        $claves = array_column($sugerencias, 'clave');
        $this->assertNotEmpty($claves);
        $this->assertEmpty(array_diff($claves, ['tiempos-romana', 'kilos-recepciones', 'revisar-taras']));
    }

    public function test_ejecutar_consulta_no_permitida_da_403(): void
    {
        $usuario = $this->usuarioAsistente(RolUsuario::OperadorRomana, [CatalogoModulosAcceso::OFICINA_ASISTENTE, 'materia-prima.romana']);

        $this->actingAs($usuario, 'sanctum')->postJson('/api/asistente/consultas/consumos-ot')->assertForbidden();
        $this->actingAs($usuario, 'sanctum')->postJson('/api/asistente/consultas/no-existe')->assertForbidden();
        $this->actingAs($usuario, 'sanctum')->postJson('/api/asistente/consultas/kilos-recepciones', ['desde' => '2026-11-01', 'hasta' => '2026-11-22'])
            ->assertOk()->assertJsonPath('data.consulta', 'kilos-recepciones');
    }

    public function test_usuario_sin_modulo_recibe_403(): void
    {
        $usuario = $this->usuarioAsistente(RolUsuario::OperadorRomana, ['materia-prima.romana']);

        $this->actingAs($usuario, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => 'kilos de hoy'])->assertForbidden();
        $this->actingAs($usuario, 'sanctum')->getJson('/api/asistente/consultas')->assertForbidden();
    }

    public function test_cliente_recibe_403(): void
    {
        $cliente = $this->usuarioAsistente(RolUsuario::Cliente, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);

        $this->actingAs($cliente, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => 'kilos de hoy'])->assertForbidden();
    }

    public function test_configuracion_solo_para_administrar_asistente_y_valida_minimo_menor_que_maximo(): void
    {
        $supervisor = $this->usuarioAsistente(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_ASISTENTE, CatalogoModulosAcceso::OFICINA_ASISTENTE_CONFIGURACION]);
        $this->actingAs($supervisor, 'sanctum')->getJson('/api/asistente/configuracion')->assertForbidden();

        $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/asistente/configuracion')
            ->assertOk()
            ->assertJsonPath('data.margen_desviaciones', 2)
            ->assertJsonPath('data.rangos.0.minimo_kg', 8.2);

        $this->putJson('/api/asistente/configuracion', [
            'margen_desviaciones' => 2.5,
            'rangos' => [['tipo_envase' => 'totes', 'especie' => null, 'minimo_kg' => 9.5, 'maximo_kg' => 8.2, 'activo' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('rangos.0.maximo_kg');

        $this->putJson('/api/asistente/configuracion', [
            'margen_desviaciones' => 2.5,
            'rangos' => [['tipo_envase' => 'totes', 'especie' => null, 'minimo_kg' => 8.0, 'maximo_kg' => 9.6, 'activo' => true]],
        ])->assertOk()->assertJsonPath('data.margen_desviaciones', 2.5)->assertJsonPath('data.rangos.0.maximo_kg', 9.6);
    }

    public function test_cada_pregunta_queda_registrada_con_estado(): void
    {
        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => 'kilos recibidos hoy'])->assertOk();
        $this->actingAs($this->administrador, 'sanctum')->postJson('/api/asistente/preguntas', ['texto' => 'zzzz'])->assertOk();

        $this->assertSame(['sin_datos', 'sugerencias'], PreguntaAsistente::query()->orderBy('created_at')->orderBy('estado')->pluck('estado')->all());
        $this->actingAs($this->administrador, 'sanctum')->getJson('/api/asistente/preguntas?estado=sugerencias')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.texto', 'zzzz');
    }
}
