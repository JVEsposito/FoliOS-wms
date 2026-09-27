<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoIntegracionFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\Repaletizaje;
use App\Models\RepaletizajeDetalle;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsultaFolioTrazabilidadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_explica_folio_agotado_por_repaletizaje_y_su_transicion(): void
    {
        $temporada = $this->crearTemporadaActivaPrueba([
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 1,
        ]);
        $usuario = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $agotado = $this->folio($temporada, '6000031695', 0, EstadoOperacionalFolio::Agotado, false);
        $resultado = $this->folio($temporada, '6000031569', 120, EstadoOperacionalFolio::Disponible);

        $agotado->update([
            'datos_externos' => array_merge($agotado->datos_externos, [
                'consumido_en_repaletizaje' => 'REPA-000023',
            ]),
        ]);

        $repa = Repaletizaje::create([
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'consulta-repa'),
            'codigo' => 'REPA-000023',
            'tipo_resultado' => 'pallet',
            'estrategia_folio' => 'conservar',
            'folio_resultante_id' => $resultado->id,
            'folio_conservado_id' => $resultado->id,
            'cantidad_objetivo' => 120,
            'cantidad_resultante' => 120,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado->value,
            'campos_mix' => [],
            'snapshot' => [],
            'estado' => 'confirmado',
            'user_id' => $usuario->id,
            'confirmado_at' => now(),
        ]);

        RepaletizajeDetalle::create([
            'repaletizaje_id' => $repa->id,
            'folio_origen_id' => $agotado->id,
            'orden' => 1,
            'es_folio_conservado' => false,
            'cajas_antes' => 40,
            'cajas_aportadas' => 40,
            'cajas_despues' => 0,
            'tipo_bulto_antes' => 'saldo',
            'tipo_bulto_despues' => 'saldo',
            'estado_antes' => 'disponible',
            'estado_despues' => 'agotado',
            'snapshot_antes' => [],
            'snapshot_despues' => [],
        ]);
        RepaletizajeDetalle::create([
            'repaletizaje_id' => $repa->id,
            'folio_origen_id' => $resultado->id,
            'orden' => 2,
            'es_folio_conservado' => true,
            'cajas_antes' => 80,
            'cajas_aportadas' => 80,
            'cajas_despues' => 0,
            'tipo_bulto_antes' => 'saldo',
            'tipo_bulto_despues' => 'pallet',
            'estado_antes' => 'disponible',
            'estado_despues' => 'disponible',
            'snapshot_antes' => [],
            'snapshot_despues' => [],
        ]);

        $respuesta = $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/consultas/folios/{$agotado->id}")
            ->assertOk()
            ->assertJsonPath('folio.numero', '6000031695')
            ->assertJsonPath('folio.estado_explicado', 'agotado_por_repaletizaje')
            ->assertJsonPath('folio.cantidad_cajas', 0)
            ->assertJsonPath('folio.repaletizaje_agotamiento', 'REPA-000023')
            ->assertJsonPath('totales.repaletizajes', 1)
            ->assertJsonPath('repaletizajes.0.folio_resultante', '6000031569')
            ->assertJsonPath('repaletizajes.0.origenes.0.cajas_antes', 40)
            ->assertJsonPath('repaletizajes.0.origenes.0.cajas_despues', 0);

        $eventos = collect($respuesta->json('timeline'));
        $evento = $eventos->firstWhere('tipo', 'repaletizaje');

        $this->assertSame('Agotado por repaletizaje', $evento['titulo']);
        $this->assertStringContainsString('40 cajas → 0', $evento['descripcion']);
        $this->assertStringContainsString('6000031569', $evento['descripcion']);

        $repa->update([
            'estado' => 'anulado',
            'operacion_anulacion_id' => (string) Str::uuid(),
            'anulado_por_user_id' => $usuario->id,
            'anulado_at' => now()->addMinute(),
            'motivo_anulacion' => 'Corrección de la composición.',
        ]);

        $respuestaAnulada = $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/consultas/folios/{$agotado->id}")
            ->assertOk()
            ->assertJsonPath('repaletizajes.0.estado', 'anulado');
        $eventoAnulado = collect($respuestaAnulada->json('timeline'))
            ->firstWhere('tipo', 'repaletizaje_anulado');

        $this->assertSame('Repaletizaje anulado', $eventoAnulado['titulo']);
        $this->assertSame('Corrección de la composición.', $eventoAnulado['meta']['Motivo']);
        $this->assertSame($usuario->name, $eventoAnulado['meta']['Anulado por']);
    }

    private function folio(
        Temporada $temporada,
        string $numero,
        int $cantidad,
        EstadoOperacionalFolio $estado,
        bool $activo = true,
    ): Folio {
        return Folio::create([
            'temporada_id' => $temporada->id,
            'numero_folio' => $numero,
            'tipo_bulto' => TipoBulto::Saldo,
            'estado_operacional' => $estado,
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fecha_ingreso' => now()->subHour(),
            'activo' => $activo,
            'variedad' => 'Hayward',
            'calibre' => '39',
            'marca' => 'MACE',
            'exportadora' => 'MACE',
            'origen_sistema' => 'validacion',
            'identificador_externo' => (string) Str::uuid(),
            'estado_integracion' => EstadoIntegracionFolio::NoVinculado,
            'datos_externos' => [
                'especie' => 'Cereza',
                'categoria' => 'Exportación',
                'envase' => 'Caja',
                'csg' => '12345',
                'predio' => 'Predio',
                'cuartel' => 'Cuartel',
                'cantidad_cajas' => $cantidad,
            ],
        ]);
    }
}
