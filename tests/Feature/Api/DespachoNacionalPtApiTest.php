<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Cliente;
use App\Models\ClienteValidacion;
use App\Models\DespachoNacionalPt;
use App\Models\Folio;
use App\Models\OrigenValidacion;
use App\Models\Pais;
use App\Models\PartidaNacionalPt;
use App\Models\Repaletizaje;
use App\Models\RetencionLoteSag;
use App\Models\User;
use App\Services\Consultas\ServicioExpedienteFolio;
use App\Services\Validacion\ServicioRepaletizaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class DespachoNacionalPtApiTest extends TestCase
{
    use PreparaDespachoFrigorifico, RefreshDatabase;

    private const API = '/api/despacho-nacional-pt';

    public function test_separa_un_componente_y_despacha_junto_a_un_folio_completo_sin_duplicar_salidas(): void
    {
        $contexto = $this->crearContextoFrio(2, 'MN');
        [$mixto, $completo] = $contexto['folios'];
        $mixto->update(['datos_externos' => ['cantidad_cajas' => 100, 'composicion' => [
            ['csg' => '1001', 'cantidad_cajas' => 60, 'fecha_embalaje' => '2026-11-19'],
            ['csg' => '1002', 'cantidad_cajas' => 40, 'fecha_embalaje' => '2026-11-20'],
        ]]]);
        $completo->update(['datos_externos' => ['cantidad_cajas' => 20, 'csg' => '1003']]);
        $this->withToken($contexto['tokenOficina']);

        $parcial = $this->separacion($mixto, 12, '1002');
        $primera = $this->postJson(self::API.'/partidas', $parcial)->assertCreated()
            ->assertJsonPath('data.cajas', 12)->assertJsonPath('data.snapshot.cajas_restantes', 88)->json('data');
        $this->postJson(self::API.'/partidas', $parcial)->assertOk()->assertJsonPath('data.id', $primera['id']);
        $this->postJson(self::API.'/partidas', [...$parcial, 'motivo' => 'Un motivo diferente'])->assertConflict();

        $this->assertSame(88, $mixto->refresh()->datos_externos['cantidad_cajas']);
        $this->assertSame([60, 28], array_column($mixto->datos_externos['composicion'], 'cantidad_cajas'));
        $this->assertTrue($mixto->activo);
        $this->assertNotNull($mixto->ubicacionActual);
        $nacional = Folio::findOrFail($primera['folio_id']);
        $this->assertFalse($nacional->activo);
        $this->assertSame(EstadoOperacionalFolio::Bloqueado, $nacional->estado_operacional);
        $this->assertSame('1002', $nacional->datos_externos['composicion'][0]['csg']);
        $this->assertNull($nacional->ubicacionActual);

        $repa = Repaletizaje::query()->where('operacion_id', $parcial['operacion_id'])->firstOrFail();
        $this->withToken($contexto['administrador']->createToken('oficina', ['oficina'])->plainTextToken)
            ->postJson('/api/validacion/repaletizajes/'.$repa->id.'/anular', [
                'operacion_id' => (string) Str::uuid(), 'motivo' => 'Intento de anular separación',
            ])->assertConflict();
        $this->withToken($contexto['tokenOficina']);

        $version = $contexto['camara']->refresh()->version_plano;
        $segunda = $this->postJson(self::API.'/partidas', $this->separacion($completo, 20))->assertCreated()
            ->assertJsonPath('data.folio_id', $completo->id)->json('data');
        $this->assertNull($completo->refresh()->ubicacionActual);
        $this->assertSame($version + 1, $contexto['camara']->refresh()->version_plano);

        $this->getJson(self::API)->assertOk()->assertJsonPath('resumen.cajas_pendientes', 32)
            ->assertJsonPath('meta.total', 2);
        $salida = $this->salida([$primera['id'], $segunda['id']]);
        $registrada = $this->postJson(self::API.'/despachos', $salida)->assertCreated()
            ->assertJsonPath('data.patente', 'ABCD12')->json('data.id');
        $this->postJson(self::API.'/despachos', [...$salida, 'partidas' => array_reverse($salida['partidas'])])
            ->assertOk()->assertJsonPath('data.id', $registrada);
        $this->postJson(self::API.'/despachos', [...$salida, 'operacion_id' => (string) Str::uuid()])->assertConflict();

        $this->getJson(self::API.'?estado=despachada')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('resumen.cajas_pendientes', 0)->assertJsonPath('data.0.despacho.guia', '00123');
        $this->assertSame(1, DespachoNacionalPt::count());
        $this->assertSame(2, PartidaNacionalPt::count());
        $this->assertSame(EstadoOperacionalFolio::Despachado, $nacional->refresh()->estado_operacional);
        $this->assertSame(EstadoOperacionalFolio::Despachado, $completo->refresh()->estado_operacional);
        $eventos = app(ServicioExpedienteFolio::class)->obtener($mixto)['timeline'];
        $this->assertContains('Salida a mercado nacional', array_column($eventos, 'titulo'));
    }

    public function test_rechaza_cajas_excesivas_y_folios_reservados_y_respeta_los_permisos(): void
    {
        $contexto = $this->crearContextoFrio(1, 'MN-RES');
        $folio = $contexto['folios'][0];
        $folio->update(['datos_externos' => ['cantidad_cajas' => 10, 'csg' => '1003']]);
        $this->withToken($contexto['tokenOficina']);
        $datos = $this->separacion($folio, 11);
        $this->postJson(self::API.'/partidas', $datos)->assertConflict();
        $this->postJson(self::API.'/partidas', [...$datos, 'confirmado' => false])->assertUnprocessable();
        $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $this->getJson(self::API.'/folio?numero='.$folio->numero_folio)->assertConflict();
        $this->assertSame(10, $folio->fresh()->datos_externos['cantidad_cajas']);
        $this->assertDatabaseCount('partidas_nacionales_pt', 0);

        $consulta = User::factory()->create(['rol' => RolUsuario::Consulta, 'activo' => true]);
        $this->withToken($consulta->createToken('oficina', ['oficina'])->plainTextToken);
        $this->getJson(self::API)->assertOk()->assertJsonPath('permisos.separar', false)->assertJsonPath('permisos.despachar', false);
        $this->postJson(self::API.'/partidas', $datos)->assertForbidden();
        $this->postJson(self::API.'/despachos', $this->salida([(string) Str::uuid()]))->assertForbidden();
    }

    public function test_la_salida_revalida_retenciones_aplicables_a_chile(): void
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Despachador, 'activo' => true]);
        $this->withToken($usuario->createToken('oficina', ['oficina'])->plainTextToken);
        $folio = Folio::create([
            'numero_folio' => 'MN-PRODUCTO', 'tipo_bulto' => TipoBulto::Pallet, 'fecha_ingreso' => now(),
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
        ]);
        $cliente = Cliente::create(['codigo' => 'MN', 'nombre' => 'Exportadora MN', 'activo' => true]);
        $catalogo = ClienteValidacion::create([
            'cliente_id' => $cliente->id, 'temporada_id' => $folio->temporada_id, 'nombre' => $cliente->nombre, 'activo' => true,
        ]);
        $origen = OrigenValidacion::create([
            'temporada_id' => $folio->temporada_id, 'cliente_validacion_id' => $catalogo->id,
            'cliente' => $cliente->nombre, 'marca' => 'MN', 'csg' => '1004', 'activo' => true,
        ]);
        $folio->update(['exportadora' => $cliente->nombre, 'datos_externos' => [
            'cantidad_cajas' => 20, 'csg' => '1004', 'cliente_id' => $cliente->id,
            'composicion' => [['origen_validacion_id' => $origen->id, 'csg' => '1004', 'cantidad_cajas' => 20, 'lote_materia_prima' => 'LOTE-MN', 'cliente' => $cliente->nombre]],
        ]]);
        $partida = $this->postJson(self::API.'/partidas', $this->separacion($folio, 6))->assertCreated()->json('data');
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();
        $peru = Pais::query()->where('iso_alpha2', 'PE')->firstOrFail();
        $retencion = RetencionLoteSag::create([
            'temporada_id' => $folio->temporada_id, 'cliente_id' => $cliente->id, 'numero_lote' => 'LOTE-MN',
            'estado' => 'retenido', 'todos_destinos' => true, 'destinos' => [],
            'fecha_deteccion' => now(), 'observacion' => 'Verificación de lote',
            'creado_por_user_id' => $usuario->id, 'actualizado_por_user_id' => $usuario->id,
        ]);
        $salida = $this->salida([$partida['id']]);
        $this->postJson(self::API.'/despachos', $salida)->assertConflict()->assertJsonPath('message', 'Salida nacional impedida por la retención SAG del lote LOTE-MN.');
        $retencion->update(['todos_destinos' => false, 'destinos' => [['tipo' => 'pais', 'id' => $chile->id]]]);
        $this->postJson(self::API.'/despachos', $salida)->assertConflict();
        $this->assertDatabaseCount('despachos_nacionales_pt', 0);
        $retencion->update(['destinos' => [['tipo' => 'pais', 'id' => $peru->id]]]);
        $this->postJson(self::API.'/despachos', $salida)->assertCreated();
    }

    private function separacion(Folio $folio, int $cajas, ?string $csg = null): array
    {
        $linea = collect(app(ServicioRepaletizaje::class)->composicionFolio($folio))
            ->first(fn (array $linea): bool => $csg === null || $linea['csg'] === $csg);

        return [
            'operacion_id' => (string) Str::uuid(), 'folio_id' => $folio->id,
            'cajas_esperadas' => $folio->datos_externos['cantidad_cajas'],
            'composicion' => [['clave' => $linea['clave'], 'cajas' => $cajas]],
            'motivo' => 'Reclasificación a mercado nacional', 'confirmado' => true,
        ];
    }

    private function salida(array $partidas): array
    {
        return ['operacion_id' => (string) Str::uuid(), 'partidas' => $partidas,
            'guia' => '00123', 'destinatario' => 'Mercado Central', 'patente' => 'abcd12', 'confirmado' => true];
    }
}
