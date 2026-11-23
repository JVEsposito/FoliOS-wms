<?php

namespace Tests\Feature\Asistente;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\PreguntaAsistente;
use App\Models\User;
use App\Services\Asistente\CompuertaAsistente;
use App\Services\Asistente\EvaluadorBancoAsistente;
use App\Services\Autorizacion\CatalogoModulosAcceso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PreparaAsistente;
use Tests\TestCase;

/** Compuerta de la fase B: el registro real y el banco deciden si se conecta Haiku. */
class CompuertaAsistenteTest extends TestCase
{
    use PreparaAsistente, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-22 10:00', 'America/Santiago'));
        $this->prepararAsistente();
        Cliente::create(['codigo' => 'VAL', 'nombre' => 'Valle Alto', 'activo' => true]);
        $this->csg('104512');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_el_banco_no_tiene_respuestas_falsas_y_responde_lo_esperado(): void
    {
        $informe = app(EvaluadorBancoAsistente::class)->evaluar($this->administrador);
        $esperadas = count(array_filter(app(EvaluadorBancoAsistente::class)->preguntas(), fn (array $p): bool => $p['responde_hoy'] !== null));

        $this->assertSame(60, $informe['total']);
        $this->assertSame(0, $informe['falsas']);
        $this->assertSame(0, $informe['preguntas_de_mas']);
        $this->assertSame($esperadas, $informe['correctas']);
    }

    public function test_con_pocas_preguntas_no_decide(): void
    {
        $this->registrar('como esta el clima', 'sugerencias', 10);

        $informe = app(CompuertaAsistente::class)->medir($this->administrador);

        $this->assertSame('datos_insuficientes', $informe['decision']);
        $this->assertSame(10, $informe['registro']['total']);
    }

    public function test_sobre_el_umbral_conviene_conectar_haiku(): void
    {
        $this->registrar('kilos recibidos hoy', 'respondida', 50);
        $this->registrar('como esta el clima', 'sugerencias', 10);

        $informe = app(CompuertaAsistente::class)->medir($this->administrador);

        $this->assertSame('conectar_haiku', $informe['decision']);
        $this->assertSame(0.167, $informe['reinterpretado']['proporcion_sugerencias']);
        $this->assertSame([['texto' => 'como esta el clima', 'veces' => 10]], $informe['sin_respuesta']);
    }

    public function test_se_reinterpreta_con_las_reglas_de_hoy(): void
    {
        // Antes terminaron en sugerencias; hoy «camiones en planta» las responde.
        $this->registrar('que camiones hay en patio ahora', 'sugerencias', 50);
        $this->registrar('kilos recibidos hoy', 'respondida', 10);

        $informe = app(CompuertaAsistente::class)->medir($this->administrador);

        $this->assertSame(0.833, $informe['registro']['proporcion_sugerencias']);
        $this->assertSame(0.0, $informe['reinterpretado']['proporcion_sugerencias']);
        $this->assertSame('seguir_sin_ia', $informe['decision']);
    }

    public function test_solo_cuenta_el_periodo_y_omite_usuarios_inactivos(): void
    {
        $this->registrar('como esta el clima', 'sugerencias', 5, now()->subDays(10));
        $inactivo = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => false]);
        $this->registrar('como esta el clima', 'sugerencias', 3, now()->subHour(), $inactivo);

        $informe = app(CompuertaAsistente::class)->medir($this->administrador);

        $this->assertSame(3, $informe['registro']['total']);
        $this->assertSame(0, $informe['reinterpretado']['total']);
    }

    public function test_comandos_de_evaluacion_y_compuerta(): void
    {
        $this->artisan('asistente:evaluar')->expectsOutputToContain('0 falsas')->assertSuccessful();
        $this->artisan('asistente:evaluar --modo=haiku')->assertExitCode(2);
        $this->artisan('asistente:compuerta --dias=7')->expectsOutputToContain('Decisión: faltan datos')->assertSuccessful();
        $this->artisan('asistente:compuerta --dias=0')->assertExitCode(2);
    }

    public function test_api_de_compuerta_solo_para_quien_administra_el_asistente(): void
    {
        $usuario = $this->usuarioAsistente(RolUsuario::SupervisorFrio, [CatalogoModulosAcceso::OFICINA_ASISTENTE]);
        $this->actingAs($usuario, 'sanctum')->getJson('/api/asistente/compuerta')->assertForbidden();

        $this->actingAs($this->administrador, 'sanctum')->getJson('/api/asistente/compuerta?dias=7')
            ->assertOk()
            ->assertJsonPath('data.decision', 'datos_insuficientes')
            ->assertJsonPath('data.banco.falsas', 0);
        $this->actingAs($this->administrador, 'sanctum')->getJson('/api/asistente/compuerta?dias=500')->assertUnprocessable();
    }

    private function registrar(string $texto, string $estado, int $veces, $cuando = null, ?User $usuario = null): void
    {
        for ($i = 0; $i < $veces; $i++) {
            PreguntaAsistente::query()->create([
                'user_id' => ($usuario ?? $this->administrador)->id, 'texto' => $texto, 'interprete' => 'reglas',
                'estado' => $estado, 'confianza' => 0.5, 'created_at' => $cuando ?? now()->subHours(2),
            ]);
        }
    }
}
