<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\CalibreValidacion;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\GuiaRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\ProductorCsg;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\ValidacionMp;
use App\Models\VariedadValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InformeRecepcionesCsgTest extends TestCase
{
    use RefreshDatabase;

    private User $supervisor;

    private Temporada $temporada;

    private Cliente $cliente;

    private VariedadValidacion $variedad;

    private CalibreValidacion $calibre;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.operational_timezone' => 'America/Santiago']);
        $this->supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $this->temporada = Temporada::where('activa', true)->firstOrFail();
        $this->cliente = Cliente::create(['codigo' => 'FA-CSG', 'nombre' => 'Cliente del informe', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $this->temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $this->variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina'], ['activo' => true]);
        $this->calibre = CalibreValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => '28 mm'], ['activo' => true]);
    }

    public function test_un_dia_o_rango_cuadra_romana_y_atribuye_cada_lote_a_su_guia_y_csg(): void
    {
        $camion = $this->recepcion('REC-CSG-1', '2026-11-19 02:30:00', 1500.5);
        $primeraGuia = $this->guia($camion, 1, '1511');
        $segundaGuia = $this->guia($camion, 2, '1512');
        $productor = ProductorCsg::create([
            'codigo' => '001234', 'razon_social' => 'Productor Uno', 'predio' => 'Predio Uno', 'estado_sag' => 'vigente',
            'fuente_url' => 'https://ejemplo.invalid/csg', 'primera_verificacion_at' => now(), 'ultima_verificacion_at' => now(),
            'ultima_consulta_user_id' => $this->supervisor->id, 'respuesta_hash' => hash('sha256', '001234'),
        ]);
        $primero = CsgValidacion::create([
            'temporada_id' => $this->temporada->id, 'codigo' => '001234', 'predio' => 'Predio Uno',
            'productor_csg_id' => $productor->id, 'activo' => true,
        ]);
        $segundo = CsgValidacion::create([
            'temporada_id' => $this->temporada->id, 'codigo' => '86900', 'predio' => 'Predio Dos', 'activo' => true,
        ]);
        $this->lote($camion, $primeraGuia, $primero, 1, 900.125);
        $this->lote($camion, $segundaGuia, $segundo, 2, 500.25);
        $this->lote($camion, $primeraGuia, $primero, 3, 75.6, 'borrador');
        $this->lote($camion, $primeraGuia, $primero, 4, 24.4, 'anulado');

        $medianoche = $this->recepcion('REC-CSG-2', '2026-11-19 03:00:00', 800);
        $this->lote($medianoche, null, $primero, 5, 800.002);
        $this->recepcion('REC-ELIMINADA', '2026-11-18 18:00:00', 300, ['eliminada_at' => now()]);
        $this->recepcion('REC-PENDIENTE', '2026-11-18 19:00:00', 500, ['estado' => 'en_bascula_salida']);
        $this->recepcion('REC-SOLO-ENVASES', '2026-11-18 20:00:00', 250, ['tipo_recepcion' => 'solo_envases']);
        $otro = Cliente::create(['codigo' => 'OT-CSG', 'nombre' => 'Otra exportadora', 'activo' => true]);
        $this->recepcion('REC-OTRO-CLIENTE', '2026-11-18 22:00:00', 4200, [
            'cliente_id' => $otro->id, 'cliente_codigo_snapshot' => $otro->codigo, 'cliente_nombre_snapshot' => $otro->nombre,
        ]);

        $ruta = "/api/materia-prima/recepciones-csg/{$this->cliente->id}";
        $this->actingAs($this->supervisor, 'sanctum')->getJson($ruta.'?desde=2026-11-18')
            ->assertOk()
            ->assertJsonPath('data.periodo.hasta', '2026-11-18')
            ->assertJsonPath('data.totales.recepciones', 1)
            ->assertJsonPath('data.totales.kilos_netos', 1500.5)
            ->assertJsonPath('data.totales.kilos_con_csg', 1400.375)
            ->assertJsonPath('data.totales.kilos_pendientes', 100.125)
            ->assertJsonPath('data.resumen.0.csg', '001234')
            ->assertJsonPath('data.resumen.0.productor', 'Productor Uno')
            ->assertJsonPath('data.grafico.1.kilos', 500.25)
            ->assertJsonCount(3, 'data.detalle')
            ->assertJsonPath('data.detalle.0.fecha', '2026-11-18 23:30')
            ->assertJsonPath('data.detalle.0.guia', '1511')
            ->assertJsonPath('data.detalle.1.guia', '1512')
            ->assertJsonPath('data.detalle.2.rotulo_csg', 'Pendiente de CSG');

        $this->getJson($ruta.'?desde=2026-11-18&hasta=2026-11-19')->assertOk()
            ->assertJsonPath('data.totales.recepciones', 2)
            ->assertJsonPath('data.totales.kilos_netos', 2300.5)
            ->assertJsonPath('data.totales.kilos_excedentes', 0.002)
            ->assertJsonPath('data.resumen.0.lotes', 2)
            ->assertJsonPath('data.detalle.3.guia', $medianoche->numero_guia_despacho)
            ->assertJsonPath('data.detalle.4.kilos', -0.002);

        $pdf = $this->get($ruta.'/pdf?desde=2026-11-18')->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('recepcion-csg-fa-csg-2026-11-18.pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('(001234)', $pdf);
        $this->assertStringContainsString('(1512)', $pdf);
        $this->assertStringNotContainsString('REC-OTRO-CLIENTE', $pdf);
    }

    public function test_la_consulta_requiere_permiso_de_materia_prima_y_valida_el_periodo(): void
    {
        $ruta = "/api/materia-prima/recepciones-csg/{$this->cliente->id}";
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::CamareroMateriales]), 'sanctum')
            ->getJson($ruta.'?desde=2026-11-18')->assertForbidden();

        $this->actingAs($this->supervisor, 'sanctum')->getJson($ruta.'?desde=2026-11-19&hasta=2026-11-18')
            ->assertUnprocessable()->assertJsonValidationErrors('hasta');
        $this->getJson('/api/materia-prima/recepciones-csg/opciones')->assertOk()->assertJsonPath('data.clientes', []);
    }

    private function recepcion(string $numero, string $ingreso, float $neto, array $datos = []): RecepcionRomana
    {
        return RecepcionRomana::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', $numero),
            'numero_recepcion' => $numero,
            'temporada_id' => $this->temporada->id,
            'temporada_codigo_snapshot' => $this->temporada->codigo,
            'temporada_nombre_snapshot' => $this->temporada->nombre,
            'cliente_id' => $this->cliente->id,
            'cliente_codigo_snapshot' => $this->cliente->codigo,
            'cliente_nombre_snapshot' => $this->cliente->nombre,
            'tipo_recepcion' => 'fruta_con_envases',
            'tipo_servicio' => 'proceso',
            'cantidad_envases_declarados' => 10,
            'tipo_envase_declarado' => 'bins',
            'numero_guia_despacho' => 'GD-'.$numero,
            'patente_camion' => 'ABCD12',
            'tipo_camion' => 'termo',
            'rut_conductor' => '12345678-5',
            'nombre_conductor' => 'Transportista',
            'peso_bruto' => $neto + 8000,
            'peso_tara' => 8000,
            'peso_neto' => $neto,
            'estado' => 'cerrado',
            'estado_validacion_mp' => 'validada',
            'ingreso_at' => $ingreso,
            'creado_por_user_id' => $this->supervisor->id,
            ...$datos,
        ]);
    }

    private function guia(RecepcionRomana $recepcion, int $secuencia, string $numero): GuiaRecepcionRomana
    {
        return $recepcion->guiasRecepcion()->create([
            'temporada_id' => $recepcion->temporada_id,
            'cliente_id' => $recepcion->cliente_id,
            'secuencia' => $secuencia,
            'numero_guia_despacho' => $numero,
            'envases' => [],
            'vigente' => 1,
        ]);
    }

    private function lote(RecepcionRomana $recepcion, ?GuiaRecepcionRomana $guia, CsgValidacion $csg, int $numero, float $kilos, string $estado = 'entregado_proceso'): void
    {
        $validacion = ValidacionMp::firstOrCreate(['recepcion_romana_id' => $recepcion->id], [
            'temporada_id' => $this->temporada->id, 'operacion_toma_id' => (string) Str::uuid(),
            'estado' => 'validada', 'validador_user_id' => $this->supervisor->id, 'tomada_at' => now(),
        ]);
        $segmento = SegmentoValidacionMp::create([
            'validacion_mp_id' => $validacion->id, 'guia_recepcion_romana_id' => $guia?->id,
            'secuencia' => $numero, 'motivos' => [], 'csg_validacion_id' => $csg->id, 'csg_snapshot' => $csg->codigo,
        ]);

        LoteMateriaPrima::create([
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) $numero),
            'segmento_validacion_mp_id' => $segmento->id, 'recepcion_romana_id' => $recepcion->id,
            'temporada_id' => $this->temporada->id, 'cliente_id' => $this->cliente->id, 'numero_lote' => 'LOTE-'.$numero,
            'estado' => $estado, 'csg_validacion_id' => $csg->id, 'csg_snapshot' => $csg->codigo,
            'sdp' => '123456', 'ggn' => '1234567890123', 'fecha_cosecha' => '2026-11-17', 'predio' => 'Predio de prueba',
            'especie_validacion_id' => $this->variedad->especie_validacion_id, 'especie_snapshot' => 'Cereza',
            'variedad_validacion_id' => $this->variedad->id, 'variedad_snapshot' => $this->variedad->nombre,
            'calibre_validacion_id' => $this->calibre->id, 'calibre_snapshot' => $this->calibre->nombre,
            'cuartel' => '1', 'tipo_producto' => 'materia_prima', 'envase_primario' => 'bins', 'cantidad_envases_primarios' => 6,
            'kilos_brutos' => $kilos + 240, 'kilos_netos_calculados' => $kilos, 'kilos_netos_confirmados' => $kilos,
            'creado_por_user_id' => $this->supervisor->id, 'actualizado_por_user_id' => $this->supervisor->id,
        ]);
    }
}
