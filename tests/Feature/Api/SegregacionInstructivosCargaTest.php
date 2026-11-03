<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Camara;
use App\Models\CargaFolio;
use App\Models\Cliente;
use App\Models\Embarque;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\UbicacionActual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SegregacionInstructivosCargaTest extends TestCase
{
    use RefreshDatabase;

    private function embarque(int $instructivos = 2): array
    {
        $usuario = User::factory()->create(['rol' => RolUsuario::Despachador, 'activo' => true]);
        $cliente = Cliente::create(['codigo' => 'CLI-INST', 'nombre' => 'Cliente instructivos', 'codigo_folio_materiales' => 'IN', 'activo' => true]);
        $payload = ['cliente_id' => $cliente->id, 'fecha_programada' => '2026-11-03', 'hora_programada' => '18:00', 'modalidad' => 'aereo',
            'instructivos' => collect(range(1, $instructivos))->map(fn ($n) => ['numero_externo' => sprintf('F%03d', $n),
                'cantidad_pallets' => 7, 'cantidad_cajas' => 728, 'recibidor' => 'UPAM FRESH LLC', 'booking' => 'BOOK-'.$n])->all()];
        $creado = $this->actingAs($usuario, 'sanctum')->postJson('/api/embarques', $payload)->assertCreated()->json('data');
        $confirmado = $this->postJson('/api/embarques/'.$creado['id'].'/confirmar', ['version_esperada' => 1])->assertOk()->json('data');

        return [$confirmado, $payload];
    }

    private function folios(int $cantidad): array
    {
        $camara = Camara::firstOrCreate(['codigo' => 'CAM-INST'], ['nombre' => 'Cámara instructivos', 'cantidad_bandas' => 2,
            'posiciones_por_banda' => 14, 'cantidad_niveles' => 1]);

        return collect(range(1, $cantidad))->map(function () use ($camara): string {
            $folio = Folio::create(['numero_folio' => 'PAL-'.Str::upper(Str::random(10)),
                'tipo_bulto' => 'pallet', 'activo' => true, 'fecha_ingreso' => now()]);
            $numero = $camara->posiciones()->count() + 1;
            $posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => $numero, 'nivel' => 1,
                'etiqueta' => 'B01-P'.$numero.'-N1']);
            UbicacionActual::withoutEvents(fn () => UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $camara->id,
                'posicion_id' => $posicion->id, 'ubicado_at' => now()]));

            return $folio->numero_folio;
        })->all();
    }

    public function test_dos_instructivos_de_siete_pallets_se_preparan_en_una_sola_orden(): void
    {
        [$embarque] = $this->embarque();
        $carga = $embarque['carga']['id'];
        [$a, $b] = array_column($embarque['instructivos'], 'id');
        $primero = $this->folios(7);
        $segundo = $this->folios(7);
        $this->postJson("/api/cargas/{$carga}/folios", ['folios' => $primero, 'version_esperada' => 1, 'instructivo_embarque_id' => $a])
            ->assertOk()->assertJsonPath('data.total_programado', 14)->assertJsonPath('data.segregaciones.0.pallets_asignados', 7)
            ->assertJsonPath('data.segregaciones.0.pallets_pendientes', 0)->assertJsonPath('data.segregaciones.1.pallets_asignados', 0);
        $this->postJson("/api/cargas/{$carga}/folios", ['folios' => $segundo, 'version_esperada' => 2, 'instructivo_embarque_id' => $b])
            ->assertOk()->assertJsonPath('data.total_folios', 14)->assertJsonPath('data.segregaciones.1.pallets_asignados', 7)
            ->assertJsonPath('data.segregaciones.0.booking', 'BOOK-1')->assertJsonPath('data.segregaciones.1.booking', 'BOOK-2')
            ->assertJsonCount(0, 'data.folios_sin_instructivo');
        $this->assertDatabaseCount('cargas', 1);
        $this->assertDatabaseCount('reservas_carga_folio', 14);
        $this->postJson("/api/cargas/{$carga}/publicar", ['version_esperada' => 3])->assertOk()->assertJsonPath('data.estado', 'pendiente');
        $this->getJson('/api/cargas/pendientes')->assertOk()->assertJsonPath('data.0.segregaciones.1.pallets_asignados', 7);
    }

    public function test_exige_un_instructivo_del_embarque_y_respeta_su_cupo_sin_insertar_parcialmente(): void
    {
        [$embarque] = $this->embarque();
        $ruta = '/api/cargas/'.$embarque['carga']['id'].'/folios';
        $folios = $this->folios(8);
        foreach ([null, (string) Str::uuid()] as $id) {
            $this->postJson($ruta, ['folios' => [$folios[0]], 'version_esperada' => 1, 'instructivo_embarque_id' => $id])->assertUnprocessable();
        }
        $this->postJson($ruta, ['folios' => $folios, 'version_esperada' => 1, 'instructivo_embarque_id' => $embarque['instructivos'][0]['id']])->assertUnprocessable();
        $this->assertDatabaseCount('carga_folios', 0);
        $this->assertDatabaseCount('reservas_carga_folio', 0);
    }

    public function test_reclasifica_folios_existentes_con_version_y_sin_duplicar_reservas(): void
    {
        [$embarque] = $this->embarque();
        $ruta = '/api/cargas/'.$embarque['carga']['id'];
        [$a, $b] = array_column($embarque['instructivos'], 'id');
        $folios = $this->folios(2);
        $this->postJson($ruta.'/folios', ['folios' => $folios, 'version_esperada' => 1, 'instructivo_embarque_id' => $a])->assertOk();
        // Una orden anterior a la migración puede contener pallets sin clasificar.
        CargaFolio::query()->update(['instructivo_embarque_id' => null]);
        $this->postJson($ruta.'/publicar', ['version_esperada' => 2])->assertUnprocessable();
        $this->postJson($ruta.'/segregaciones', ['folios' => [$folios[0]], 'version_esperada' => 2, 'instructivo_embarque_id' => $a])->assertOk();
        $this->postJson($ruta.'/segregaciones', ['folios' => [$folios[1]], 'version_esperada' => 2, 'instructivo_embarque_id' => $b])->assertConflict();
        $this->postJson($ruta.'/segregaciones', ['folios' => [$folios[1], 'PAL-AJENO'], 'version_esperada' => 3, 'instructivo_embarque_id' => $b])->assertUnprocessable();
        $this->postJson($ruta.'/segregaciones', ['folios' => [$folios[1]], 'version_esperada' => 3, 'instructivo_embarque_id' => $b])
            ->assertOk()->assertJsonPath('data.segregaciones.0.pallets_asignados', 1)->assertJsonPath('data.segregaciones.1.pallets_asignados', 1);
        $this->assertDatabaseCount('carga_folios', 2);
        $this->assertDatabaseCount('reservas_carga_folio', 2);
    }

    public function test_un_instructivo_unico_se_asigna_automaticamente_y_un_reemplazo_lo_conserva(): void
    {
        [$embarque] = $this->embarque(1);
        $ruta = '/api/cargas/'.$embarque['carga']['id'];
        $folios = $this->folios(2);
        $this->postJson($ruta.'/folios', ['folios' => [$folios[0]], 'version_esperada' => 1])
            ->assertOk()->assertJsonPath('data.folios.0.instructivo_embarque_id', $embarque['instructivos'][0]['id']);
        $this->postJson($ruta.'/publicar', ['version_esperada' => 2])->assertOk();
        $saliente = Folio::query()->where('numero_folio', $folios[0])->firstOrFail();
        $this->postJson($ruta.'/folios/'.$saliente->id.'/cambiar', ['numero_folio_entrante' => $folios[1], 'version_esperada' => 3, 'motivo' => 'Cambio comercial de pallet'])
            ->assertOk()->assertJsonPath('data.folios.0.instructivo_embarque_id', $embarque['instructivos'][0]['id']);
    }

    public function test_editar_y_reordenar_instructivos_conserva_sus_pallets_y_no_permite_borrarlos(): void
    {
        [$embarque, $payload] = $this->embarque();
        $a = $embarque['instructivos'][0]['id'];
        $b = $embarque['instructivos'][1]['id'];
        $this->postJson('/api/cargas/'.$embarque['carga']['id'].'/folios', ['folios' => $this->folios(2), 'version_esperada' => 1, 'instructivo_embarque_id' => $a])->assertOk();
        $payload['instructivos'][0]['id'] = $a;
        $payload['instructivos'][1]['id'] = $b;
        $payload['instructivos'] = array_reverse($payload['instructivos']);
        $payload['instructivos'][1]['booking'] = 'BOOK-NUEVO';
        $payload['version_esperada'] = 2;
        $ruta = '/api/embarques/'.$embarque['id'];
        $this->putJson($ruta, $payload)->assertOk()->assertJsonPath('data.instructivos.0.id', $b)->assertJsonPath('data.instructivos.1.id', $a);
        $this->assertDatabaseHas('carga_folios', ['carga_id' => $embarque['carga']['id'], 'instructivo_embarque_id' => $a]);
        $payload['version_esperada'] = 3;
        $original = $payload;
        $payload['instructivos'][1]['cantidad_pallets'] = 1;
        $this->putJson($ruta, $payload)->assertUnprocessable();
        $payload = $original;
        array_pop($payload['instructivos']);
        $this->putJson($ruta, $payload)->assertUnprocessable();
        $payload = $original;
        $payload['instructivos'][0]['id'] = (string) Str::uuid();
        $this->putJson($ruta, $payload)->assertUnprocessable();
        $this->assertSame(3, Embarque::findOrFail($embarque['id'])->version);
    }
}
