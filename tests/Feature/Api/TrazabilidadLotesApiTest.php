<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\Folio;
use App\Models\LoteMateriaPrima;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Validacion\ProyeccionTrazabilidadFolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaLotesMpEnCamara;
use Tests\TestCase;
use ZipArchive;

class TrazabilidadLotesApiTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use PreparaLotesMpEnCamara;
    use RefreshDatabase;

    private string $temporadaId;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporadaId = (string) Str::uuid();
        DB::table('temporadas')->insert([
            'id' => $this->temporadaId,
            'codigo' => 'TRZ-2026',
            'nombre' => 'Temporada trazabilidad',
            'activa' => false,
            'version_catalogo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->administrador = User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
    }

    public function test_corregir_numero_conserva_los_pallets_ya_vinculados_y_las_etiquetas_anteriores_sin_cruzar_clientes(): void
    {
        $a = $this->loteMpEnCamara('TR-A', ['numero_lote' => '10056']);
        $b = $this->loteMpEnCamara('TR-B', ['numero_lote' => '10057']);
        $temporada = $a['lote']['temporada']['id'];
        DB::table('lotes_materia_prima')->where('id', $b['lote']['id'])->update(['numero_lote' => '10056',
            'clave_numero_vigente' => hash('sha256', $temporada.'|'.$b['cliente']->id.'|10056')]);
        $folioA = $this->folio('TRZ-CORREGIR-A', $this->origen($a['cliente'], $temporada), '10056', temporadaId: $temporada);
        $folioB = $this->folio('TRZ-CORREGIR-B', $this->origen($b['cliente'], $temporada), '10056', temporadaId: $temporada);
        $this->actingAs($this->administrador, 'sanctum')->putJson("/api/materia-prima/lotes/{$b['lote']['id']}/corregir-numero", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $b['lote']['version'], 'numero_lote' => '10058',
            'motivo' => 'Se corrige el número del lote de la segunda recepción.',
        ])->assertOk();
        foreach ([[$folioA, $a['lote']['id']], [$folioB, $b['lote']['id']]] as [$folio, $loteId]) {
            app(ProyeccionTrazabilidadFolio::class)->sincronizar($folio);
            $this->assertDatabaseHas('trazabilidad_folio_origenes', ['folio_id' => $folio->id,
                'numero_lote_materia_prima' => '10056', 'lote_materia_prima_id' => $loteId]);
        }
        $this->assertSame('10056', $folioB->fresh()->datos_externos['composicion'][0]['lote_materia_prima']);
        $this->assertDatabaseHas('lotes_materia_prima', ['id' => $b['lote']['id'], 'numero_lote' => '10058']);
        $this->getJson('/api/consultas/trazabilidad?q=10058&temporada_id='.$temporada)->assertOk()
            ->assertJsonPath('data.resumen.folios', 1)->assertJsonPath('data.resumen.cajas_coincidentes', 10)
            ->assertJsonPath('data.folios.0.lineas.0.lote_materia_prima', '10056')
            ->assertJsonPath('data.folios.0.lineas.0.lote_materia_prima_actual', '10058')
            ->assertJsonPath('data.folios.0.lineas.0.coincide', true);
    }

    public function test_no_afirma_la_recepcion_de_un_lote_de_otro_cliente_con_el_mismo_numero(): void
    {
        $norte = $this->cliente('NORTE');
        $sur = $this->cliente('SUR');
        $this->lote($sur, 'L-900');
        $this->folio('TRZ-OTRO-CLIENTE', $this->origen($norte), 'L-900');

        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'numero_lote_materia_prima' => 'L-900',
            'cliente_id' => $norte->id,
            'lote_materia_prima_id' => null,
        ]);
        $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/consultas/trazabilidad?q=L-900&temporada_id='.$this->temporadaId)
            ->assertOk()
            ->assertJsonPath('data.resumen.lineas_sin_lote_verificado', 1)
            ->assertJsonPath('data.folios.0.lineas.0.lote_verificado', false)
            ->assertJsonPath('data.folios.0.lineas.0.recepcion', null);
    }

    public function test_concilia_el_vinculo_cuando_el_lote_se_digita_despues_y_lo_retira_al_anularlo(): void
    {
        $norte = $this->cliente('NORTE');
        $this->folio('TRZ-ANTES-DEL-LOTE', $this->origen($norte), 'l-901 ');
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'numero_lote_materia_prima' => 'L-901',
            'lote_materia_prima_id' => null,
        ]);

        $lote = $this->lote($norte, 'L-901');
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'numero_lote_materia_prima' => 'L-901',
            'lote_materia_prima_id' => $lote->id,
        ]);

        Schema::disableForeignKeyConstraints();
        $lote->update(['estado' => EstadoLoteMateriaPrima::Anulado->value]);
        Schema::enableForeignKeyConstraints();
        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'numero_lote_materia_prima' => 'L-901',
            'lote_materia_prima_id' => null,
        ]);
    }

    public function test_el_resumen_cuenta_todos_los_folios_y_el_excel_los_incluye_sin_limite(): void
    {
        $origen = $this->origen($this->cliente('NORTE'));
        foreach (range(1, 60) as $numero) {
            $this->folio(sprintf('TRZ-%03d', $numero), $origen, 'L-77', 'P-5');
        }

        $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/consultas/trazabilidad?q=p-5&temporada_id='.$this->temporadaId)
            ->assertOk()
            ->assertJsonPath('data.resumen.folios', 60)
            ->assertJsonPath('data.resumen.cajas_coincidentes', 600)
            ->assertJsonPath('data.paginacion.paginas', 2)
            ->assertJsonCount(50, 'data.folios')
            ->assertJsonPath('data.folios.0.numero', 'TRZ-001');
        $this->getJson('/api/consultas/trazabilidad?q=P-5&pagina=2&temporada_id='.$this->temporadaId)
            ->assertOk()
            ->assertJsonPath('data.paginacion.pagina', 2)
            ->assertJsonCount(10, 'data.folios')
            ->assertJsonPath('data.folios.9.numero', 'TRZ-060');

        $respuesta = $this->get('/api/consultas/trazabilidad/exportar?q=P-5&temporada_id='.$this->temporadaId)->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($respuesta->baseResponse->getFile()->getPathname()));
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        foreach (range(1, 60) as $numero) {
            $this->assertStringContainsString(sprintf('TRZ-%03d', $numero), $hoja);
        }
    }

    public function test_un_mismo_numero_de_lote_en_otra_temporada_no_se_mezcla_y_el_historial_se_conserva(): void
    {
        $norte = $this->cliente('NORTE');
        $this->folio('TRZ-TEMP-A', $this->origen($norte), 'L-500');
        $activa = Temporada::query()->where('activa', true)->firstOrFail();
        $this->folio('TRZ-TEMP-B', $this->origen($norte, $activa->id), 'L-500', temporadaId: $activa->id);

        // Sin indicar temporada se consulta la activa; la cerrada sigue disponible.
        $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/consultas/trazabilidad?q=L-500')
            ->assertOk()
            ->assertJsonPath('data.temporada.id', $activa->id)
            ->assertJsonPath('data.resumen.folios', 1)
            ->assertJsonPath('data.folios.0.numero', 'TRZ-TEMP-B')
            ->assertJsonCount(Temporada::query()->count(), 'data.temporadas');
        $this->getJson('/api/consultas/trazabilidad?q=L-500&temporada_id='.$this->temporadaId)
            ->assertOk()
            ->assertJsonPath('data.temporada.codigo', 'TRZ-2026')
            ->assertJsonPath('data.resumen.folios', 1)
            ->assertJsonPath('data.folios.0.numero', 'TRZ-TEMP-A');
    }

    public function test_el_vinculo_con_el_lote_queda_en_la_temporada_de_origen_aunque_el_folio_cambie(): void
    {
        $norte = $this->cliente('NORTE');
        $loteOrigen = $this->lote($norte, 'L-600');
        $folio = $this->folio('TRZ-TRASLADO', $this->origen($norte), 'L-600');
        $otraTemporada = $this->temporada('TRZ-2027');
        $this->lote($norte, 'L-600', $otraTemporada);

        $folio->update(['temporada_id' => $otraTemporada]);

        $this->assertDatabaseHas('trazabilidad_folio_origenes', [
            'folio_id' => $folio->id,
            'temporada_id' => $this->temporadaId,
            'lote_materia_prima_id' => $loteOrigen->id,
        ]);
        $this->assertDatabaseCount('trazabilidad_folio_origenes', 1);
    }

    public function test_el_proceso_de_packing_muestra_los_lotes_entregados_a_esa_orden_en_la_temporada(): void
    {
        $norte = $this->cliente('NORTE');
        $lote = $this->lote($norte, 'L-700');
        $otraTemporada = $this->temporada('TRZ-2027');
        $loteOtraTemporada = $this->lote($norte, 'L-700', $otraTemporada);
        $this->entrega($lote, 'p-88');
        $this->entrega($lote, 'P-88', anulada: true);
        $this->entrega($loteOtraTemporada, 'P-88');
        $this->folio('TRZ-PROCESO', $this->origen($norte), 'L-700', 'P-88');

        $this->actingAs($this->administrador, 'sanctum')
            ->getJson('/api/consultas/trazabilidad?q=P-88&temporada_id='.$this->temporadaId)
            ->assertOk()
            ->assertJsonCount(1, 'data.entregas_proceso')
            ->assertJsonPath('data.entregas_proceso.0.lote', 'L-700')
            ->assertJsonPath('data.entregas_proceso.0.cliente', 'Exportadora NORTE')
            ->assertJsonPath('data.resumen.folios', 1);
    }

    public function test_el_excel_de_trazabilidad_requiere_acceso_a_consultas(): void
    {
        $romana = User::factory()->create(['rol' => RolUsuario::OperadorRomana, 'activo' => true]);

        $this->actingAs($romana, 'sanctum')
            ->get('/api/consultas/trazabilidad/exportar?q=P-5')
            ->assertForbidden();
    }

    private function cliente(string $codigo): Cliente
    {
        return Cliente::query()->create([
            'codigo' => $codigo,
            'nombre' => 'Exportadora '.$codigo,
            'activo' => true,
        ]);
    }

    private function temporada(string $codigo): string
    {
        $id = (string) Str::uuid();
        DB::table('temporadas')->insert([
            'id' => $id,
            'codigo' => $codigo,
            'nombre' => 'Temporada '.$codigo,
            'activa' => false,
            'version_catalogo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function origen(Cliente $cliente, ?string $temporadaId = null): string
    {
        $temporadaId ??= $this->temporadaId;
        $clienteValidacion = (string) Str::uuid();
        $origen = (string) Str::uuid();
        DB::table('clientes_validacion')->insert([
            'id' => $clienteValidacion,
            'temporada_id' => $temporadaId,
            'cliente_id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen,
            'temporada_id' => $temporadaId,
            'cliente_validacion_id' => $clienteValidacion,
            'cliente' => $cliente->codigo,
            'marca' => 'ATLAS',
            'csg' => '105410',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $origen;
    }

    private function folio(
        string $numero,
        string $origenId,
        string $lote,
        ?string $proceso = null,
        ?string $temporadaId = null,
    ): Folio {
        return Folio::query()->create([
            'temporada_id' => $temporadaId ?? $this->temporadaId,
            'numero_folio' => $numero,
            'tipo_bulto' => 'pallet',
            'estado_operacional' => 'disponible',
            'fecha_ingreso' => now(),
            'activo' => true,
            'datos_externos' => ['composicion' => [array_filter([
                'origen_validacion_id' => $origenId,
                'csg' => '105410',
                'cantidad_cajas' => 10,
                'lote_materia_prima' => $lote,
                'proceso_packing' => $proceso,
            ])]],
        ]);
    }

    private function entrega(LoteMateriaPrima $lote, string $numeroOrden, bool $anulada = false): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('entregas_fruta_proceso')->insert([
            'id' => (string) Str::uuid(),
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64),
            'lote_materia_prima_id' => $lote->id,
            'asignacion_camara_lote_id' => (string) Str::uuid(),
            'camara_id' => (string) Str::uuid(),
            'cantidad_envases' => 10,
            'kilos_enviados' => 4200,
            'saldo_anterior' => 10,
            'saldo_posterior' => 0,
            'linea_proceso' => '1',
            'turno' => 'A',
            'numero_orden' => $numeroOrden,
            'entregado_por_user_id' => $this->administrador->id,
            'entregado_at' => now(),
            'anulado_at' => $anulada ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
    }

    /** El lote se crea sin su recepción ni catálogo MP: esta prueba solo necesita número y cliente. */
    private function lote(Cliente $cliente, string $numero, ?string $temporadaId = null): LoteMateriaPrima
    {
        Schema::disableForeignKeyConstraints();

        try {
            return LoteMateriaPrima::query()->create([
                'operacion_id' => (string) Str::uuid(),
                'payload_hash' => str_repeat('a', 64),
                'segmento_validacion_mp_id' => (string) Str::uuid(),
                'recepcion_romana_id' => (string) Str::uuid(),
                'temporada_id' => $temporadaId ?? $this->temporadaId,
                'cliente_id' => $cliente->id,
                'numero_lote' => $numero,
                'estado' => EstadoLoteMateriaPrima::DisponibleProceso->value,
                'csg_validacion_id' => (string) Str::uuid(),
                'csg_snapshot' => '105410',
                'sdp' => '1',
                'ggn' => '1',
                'fecha_cosecha' => now()->toDateString(),
                'predio' => 'Predio',
                'especie_validacion_id' => (string) Str::uuid(),
                'especie_snapshot' => 'Cereza',
                'variedad_validacion_id' => (string) Str::uuid(),
                'variedad_snapshot' => 'Santina',
                'tipo_producto' => 'materia_prima',
                'envase_primario' => 'bins',
                'cantidad_envases_primarios' => 1,
                'kilos_brutos' => 1,
                'kilos_netos_calculados' => 1,
                'kilos_netos_confirmados' => 1,
                'creado_por_user_id' => $this->administrador->id,
                'actualizado_por_user_id' => $this->administrador->id,
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
