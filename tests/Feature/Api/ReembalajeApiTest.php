<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClienteValidacion;
use App\Models\CondicionSag;
use App\Models\EnvaseValidacion;
use App\Models\EspecieValidacion;
use App\Models\Folio;
use App\Models\Repaletizaje;
use App\Models\Temporada;
use App\Models\User;
use App\Services\InspeccionSag\KilosDocumentoSag;
use App\Services\IntegridadOperacional\Reglas\ReglaBalanceRepaletizaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReembalajeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_convierte_una_caja_de_cinco_en_dos_de_dos_y_medio_conservando_folio_y_puede_anular(): void
    {
        [$folio, $envase] = $this->contexto();
        $payload = $this->payload($folio, $envase);
        $r = $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk()->assertJsonPath('data.modalidad', 'reembalaje')
            ->assertJsonPath('data.balance_kilos.kilos_entrada', '5.0000')->assertJsonPath('data.balance_kilos.kilos_salida', '5.0000')
            ->assertJsonPath('data.balance_kilos.kilos_merma', '0.0000')->json('data');
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk()->assertJsonPath('data.id', $r['id']);
        $this->assertDatabaseCount('repaletizajes', 1);
        $this->assertDatabaseCount('folios', 1);
        $this->assertSame(2, $folio->refresh()->datos_externos['cantidad_cajas']);
        $this->assertSame($envase->id, $folio->datos_externos['envase_validacion_id']);
        $this->assertSame(5.0, app(KilosDocumentoSag::class)->calcular($folio));
        $this->assertCount(0, app(ReglaBalanceRepaletizaje::class)->evaluar());
        $registro = $this->get('/api/validacion/repaletizajes/'.$r['id'].'/registro-reembalaje')->assertOk()->streamedContent();
        $this->assertStringContainsString('Entrada', $registro);
        $this->assertStringContainsString('5.0000', $registro);
        $this->assertStringContainsString('Salida', $registro);
        $this->assertSame([], $this->getJson('/api/validacion/repaletizajes/registro/rrpl-01/planillas')->assertOk()->json('data'));

        $this->postJson('/api/validacion/repaletizajes/'.$r['id'].'/anular', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Se revierte el reembalaje de prueba'])->assertOk();
        $this->assertSame(1, $folio->refresh()->datos_externos['cantidad_cajas']);
        $this->assertSame('Caja 5 kg', $folio->datos_externos['envase']);
    }

    public function test_exige_merma_explicita_y_motivo_cambia_folio_y_no_crea_kilos(): void
    {
        [$folio, $envase] = $this->contexto('2.0000');
        $payload = $this->payload($folio, $envase, 'NUEVO-2KG');
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertUnprocessable();
        $clave = $this->consulta($folio)['componentes'][0]['clave'];
        $payload['operacion_id'] = (string) Str::uuid();
        $payload['mermas'] = [['clave' => $clave, 'kilos' => '1.0000']];
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('repaletizajes', 0);
        $payload['operacion_id'] = (string) Str::uuid();
        $payload['motivo_merma'] = 'Descarte de fruta durante reembalaje';
        $r = $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk()->assertJsonPath('data.balance_kilos.kilos_merma', '1.0000')->json('data');
        $this->assertFalse($folio->refresh()->activo);
        $nuevo = Folio::where('numero_folio', 'NUEVO-2KG')->sole();
        $this->assertSame(2, $nuevo->datos_externos['cantidad_cajas']);
        $this->assertSame(4.0, app(KilosDocumentoSag::class)->calcular($nuevo));
        $this->assertCount(0, app(ReglaBalanceRepaletizaje::class)->evaluar());
        $this->postJson('/api/validacion/repaletizajes/'.$r['id'].'/anular', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Revertir operación de prueba'])->assertOk();
        $this->assertTrue($folio->refresh()->activo);
        $this->assertFalse($nuevo->refresh()->activo);
    }

    public function test_rechaza_pesos_desactualizados_folios_ocupados_y_exceso_de_salida(): void
    {
        [$folio, $envase] = $this->contexto();
        $payload = $this->payload($folio, $envase);
        $payload['resultados'][0]['cantidad_resultante'] = 3;
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertUnprocessable();
        $payload = $this->payload($folio, $envase);
        $payload['huella_reembalaje'] = str_repeat('0', 64);
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertConflict();
        $folio->update(['datos_externos' => [...$folio->datos_externos, 'cantidad_cajas' => 2]]);
        $this->postJson('/api/validacion/repaletizajes', $this->payload($folio, $envase))->assertUnprocessable();
        $this->assertDatabaseCount('repaletizajes', 0);
    }

    public function test_dos_formatos_con_un_folio_conservado_y_otro_nuevo_se_anulan_sin_dejar_stock_extra(): void
    {
        [$folio, $envase] = $this->contexto('2.0000');
        $otro = EnvaseValidacion::create(['especie_validacion_id' => $envase->especie_validacion_id,
            'cliente_validacion_id' => $envase->cliente_validacion_id, 'nombre' => 'Caja 3 kg', 'kilos_netos_por_caja' => '3.0000', 'activo' => true]);
        $payload = $this->payload($folio, $envase);
        $payload['resultados'][0]['cantidad_resultante'] = 1;
        $payload['resultados'][] = [...$payload['resultados'][0], 'numero_folio' => 'NUEVO-3KG', 'envase_validacion_id' => $otro->id];
        $r = $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk()->assertJsonCount(2, 'data.resultados')->json('data');
        $this->assertSame(2, $r['cantidad_resultante']);
        $this->postJson('/api/validacion/repaletizajes/'.$r['id'].'/anular', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Anular ambos resultados del reembalaje'])->assertOk();
        $this->assertSame(1, Folio::where('activo', true)->count());
        $this->assertSame(1, $folio->refresh()->datos_externos['cantidad_cajas']);
    }

    public function test_un_mixto_cuadra_por_csg_y_no_compensa_faltantes_entre_componentes(): void
    {
        [$folio, $envase] = $this->contexto();
        $folio->update(['datos_externos' => [...$folio->datos_externos, 'cantidad_cajas' => 2, 'csg' => 'MIX', 'composicion' => [
            ['csg' => '111', 'predio' => 'Fundo A', 'cantidad_cajas' => 1, 'envase' => 'Caja 5 kg'],
            ['csg' => '222', 'predio' => 'Fundo B', 'cantidad_cajas' => 1, 'envase' => 'Caja 5 kg'],
        ]]]);
        $contexto = $this->consulta($folio);
        $payload = $this->payload($folio, $envase);
        $payload['origenes'][0]['cantidad_aportada'] = 2;
        $payload['resultados'][0]['cantidad_resultante'] = 4;
        $payload['resultados'][0]['composicion'] = [
            ['clave' => $contexto['componentes'][0]['clave'], 'cantidad_cajas' => 3],
            ['clave' => $contexto['componentes'][1]['clave'], 'cantidad_cajas' => 1],
        ];
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertUnprocessable();
        $payload['operacion_id'] = (string) Str::uuid();
        foreach ($payload['resultados'][0]['composicion'] as &$linea) {
            $linea['cantidad_cajas'] = 2;
        }
        unset($linea);
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk()->assertJsonPath('data.balance_kilos.kilos_salida', '10.0000');
        $this->assertSame([2, 2], array_column($folio->refresh()->datos_externos['composicion'], 'cantidad_cajas'));
        $this->assertSame(['111', '222'], array_column($folio->datos_externos['composicion'], 'csg'));
        $envase->update(['kilos_netos_por_caja' => '3.0000']);
        $this->assertSame(10.0, app(KilosDocumentoSag::class)->calcular($folio->refresh()));
        $this->assertSame('10.0000', $this->consulta($folio)['kilos_entrada']);
    }

    public function test_no_permite_formatos_ajenos_inactivos_ni_origenes_sin_kilos_configurados(): void
    {
        [$folio, $envase] = $this->contexto();
        $ajeno = ClienteValidacion::create(['temporada_id' => $folio->temporada_id, 'nombre' => 'OTRO CLIENTE', 'activo' => true]);
        $otro = EnvaseValidacion::create(['especie_validacion_id' => $envase->especie_validacion_id, 'cliente_validacion_id' => $ajeno->id,
            'nombre' => 'Formato ajeno', 'kilos_netos_por_caja' => '2.5000', 'activo' => true]);
        $payload = $this->payload($folio, $otro);
        $this->assertNotContains($otro->id, array_column($this->consulta($folio)['formatos'], 'id'));
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertUnprocessable();
        $payload = $this->payload($folio, $envase);
        $envase->update(['activo' => false]);
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertConflict();
        EnvaseValidacion::where('nombre', 'Caja 5 kg')->update(['kilos_netos_por_caja' => null]);
        $this->assertNotEmpty($this->consulta($folio)['errores']);
        $this->assertDatabaseCount('repaletizajes', 0);
    }

    public function test_la_auditoria_detecta_cambios_de_cajas_aunque_los_totales_guardados_no_se_modifiquen(): void
    {
        [$folio, $envase] = $this->contexto();
        $r = $this->postJson('/api/validacion/repaletizajes', $this->payload($folio, $envase))->assertOk()->json('data');
        Repaletizaje::findOrFail($r['id'])->resultados()->update(['cantidad_resultante' => 3]);
        $this->assertCount(1, app(ReglaBalanceRepaletizaje::class)->evaluar());
    }

    public function test_retirar_la_condicion_sag_se_advierte_y_la_anulacion_restaura_el_formato_original(): void
    {
        [$folio, $envase] = $this->contexto();
        $condicion = CondicionSag::create(['codigo' => 'REPACK-SAG', 'nombre' => 'Condición previa', 'activo' => true]);
        $folio->update(['condicion_sag_id' => $condicion->id]);
        $this->assertNotEmpty($this->consulta($folio)['advertencias']);
        $r = $this->postJson('/api/validacion/repaletizajes', $this->payload($folio, $envase))->assertOk()->json('data');
        $this->assertNull($folio->refresh()->condicion_sag_id);
        $this->postJson('/api/validacion/repaletizajes/'.$r['id'].'/anular', ['operacion_id' => (string) Str::uuid(), 'motivo' => 'Anular y restaurar el formato original'])->assertOk();
        $this->assertSame($condicion->id, $folio->refresh()->condicion_sag_id);
    }

    public function test_un_reembalaje_posterior_invalida_la_huella_incluso_si_conserva_el_formato_y_las_cajas(): void
    {
        [$folio, $envase] = $this->contexto();
        $this->postJson('/api/validacion/repaletizajes', $this->payload($folio, $envase))->assertOk();
        $payload = $this->payload($folio->refresh(), $envase);
        $payload['origenes'][0]['cantidad_aportada'] = 2;
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk();
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertOk();
        $payload['operacion_id'] = (string) Str::uuid();
        $this->postJson('/api/validacion/repaletizajes', $payload)->assertConflict();
        $this->assertDatabaseCount('repaletizajes', 2);
    }

    private function contexto(string $peso = '2.5000'): array
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $t = Temporada::where('activa', true)->firstOrFail();
        $especie = EspecieValidacion::create(['temporada_id' => $t->id, 'nombre' => 'Cereza', 'activo' => true]);
        $cliente = ClienteValidacion::create(['temporada_id' => $t->id, 'nombre' => 'CLIENTE', 'activo' => true]);
        EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => 'Caja 5 kg', 'kilos_netos_por_caja' => '5.0000', 'activo' => true]);
        $envase = EnvaseValidacion::create(['especie_validacion_id' => $especie->id, 'cliente_validacion_id' => $cliente->id, 'nombre' => 'Formato destino', 'kilos_netos_por_caja' => $peso, 'activo' => true]);
        $folio = Folio::create(['temporada_id' => $t->id, 'numero_folio' => 'ORIGEN-5KG', 'tipo_bulto' => 'saldo', 'estado_operacional' => 'pendiente_prefrio',
            'condicion_termica' => 'pendiente_prefrio', 'habilitacion_almacenamiento' => 'no_habilitado', 'activo' => true, 'fecha_ingreso' => now(),
            'variedad' => 'Santina', 'calibre' => '2J', 'marca' => 'MARCA', 'exportadora' => 'CLIENTE', 'origen_sistema' => 'validacion',
            'datos_externos' => ['especie' => 'Cereza', 'envase' => 'Caja 5 kg', 'csg' => '111', 'predio' => 'Fundo', 'cantidad_cajas' => 1]]);

        return [$folio, $envase];
    }

    private function consulta(Folio $folio): array
    {
        return $this->getJson('/api/validacion/repaletizajes/folios/'.$folio->numero_folio.'?reembalaje=1')->assertOk()->json('reembalaje');
    }

    private function payload(Folio $folio, EnvaseValidacion $envase, ?string $numero = null): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'modalidad' => 'reembalaje', 'turno' => 'A', 'huella_reembalaje' => $this->consulta($folio)['huella'],
            'origenes' => [['folio_id' => $folio->id, 'cantidad_aportada' => 1]], 'resultados' => [['numero_folio' => $numero ?? $folio->numero_folio,
                'tipo_resultado' => 'saldo', 'cantidad_objetivo' => 120, 'cantidad_resultante' => 2, 'envase_validacion_id' => $envase->id]]];
    }
}
