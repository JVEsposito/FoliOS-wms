<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ValidacionPalletReingresoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_consulta_un_folio_observado_con_referencias_suficientes_para_precargar_la_pda(): void
    {
        [$catalogo, $token] = $this->contexto();
        $observado = [
            ...$this->payload($catalogo, 'PAL-REINGRESO-01'),
            'resultado' => 'observado',
            'motivo' => 'csg_no_coincide',
            'observacion' => 'La etiqueta física informa otro CSG.',
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $observado)
            ->assertCreated()
            ->assertJsonPath('data.numero_intento', 1);

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?folio=pal-reingreso-01&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.numero_folio', 'PAL-REINGRESO-01')
            ->assertJsonPath('data.0.resultado', 'observado')
            ->assertJsonPath('data.0.estado', 'aceptada')
            ->assertJsonPath('data.0.temporada_id', $catalogo['temporada_id'])
            ->assertJsonPath('data.0.articulo_validacion_id', $catalogo['articulo_validacion_id'])
            ->assertJsonPath('data.0.origen_validacion_id', $catalogo['origen_validacion_id'])
            ->assertJsonPath('data.0.categoria_validacion_id', $catalogo['categoria_validacion_id'])
            ->assertJsonPath('data.0.tipo_bulto', 'pallet')
            ->assertJsonPath('data.0.cantidad_cajas', 120)
            ->assertJsonPath('data.0.motivo', 'csg_no_coincide')
            ->assertJsonPath('data.0.observacion', 'La etiqueta física informa otro CSG.');
    }

    public function test_historial_devuelve_la_resolucion_y_conserva_el_intento_observado(): void
    {
        [$catalogo, $token] = $this->contexto();
        $observado = [
            ...$this->payload($catalogo, 'PAL-REINGRESO-02'),
            'resultado' => 'observado',
            'motivo' => 'cantidad_cajas_incorrecta',
            'observacion' => 'La etiqueta indica 118 cajas.',
        ];

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $observado)
            ->assertCreated();

        $aprobado = $this->payload($catalogo, 'PAL-REINGRESO-02');
        $aprobado['cantidad_cajas'] = 118;

        $this->conToken($token)
            ->postJson('/api/validacion/pallets', $aprobado)
            ->assertCreated()
            ->assertJsonPath('data.numero_intento', 2)
            ->assertJsonPath('data.resultado', 'aprobado');

        $this->conToken($token)
            ->getJson('/api/validacion/pallets?folio=PAL-REINGRESO-02&per_page=10')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.numero_intento', 2)
            ->assertJsonPath('data.0.resultado', 'aprobado')
            ->assertJsonPath('data.0.cantidad_cajas', 118)
            ->assertJsonPath('data.1.numero_intento', 1)
            ->assertJsonPath('data.1.resultado', 'observado')
            ->assertJsonPath('data.1.cantidad_cajas', 120);
    }

    /** @return array{array<string, string|int>, string} */
    private function contexto(): array
    {
        $temporada = (string) Str::uuid();
        $articulo = (string) Str::uuid();
        $origen = (string) Str::uuid();
        $categoria = (string) Str::uuid();

        $this->desactivarTemporadasDePrueba();
        DB::table('temporadas')->insert([
            'id' => $temporada,
            'codigo' => '2026-2027',
            'nombre' => 'Temporada 2026-2027',
            'activa' => true,
            'version_catalogo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('articulos_validacion')->insert([
            'id' => $articulo,
            'temporada_id' => $temporada,
            'especie' => 'Cereza',
            'variedad' => 'Santina',
            'calibre' => '2J',
            'envase' => '5 kg',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('categorias_validacion')->insert([
            'id' => $categoria,
            'temporada_id' => $temporada,
            'nombre' => 'Exportación',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen,
            'temporada_id' => $temporada,
            'cliente' => 'DIS',
            'marca' => 'ATLAS',
            'csg' => '105410',
            'predio' => 'OLM',
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(),
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'activo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $usuario = User::factory()->create(['rol' => RolUsuario::Validador]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'VAL-REINGRESO',
            'nombre' => 'PDA reingreso',
            'plataforma' => 'android',
            'activo' => true,
        ]);
        $token = $usuario->crearTokenParaDispositivo($dispositivo, 'test-reingreso')->plainTextToken;

        return [[
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'categoria_validacion_id' => $categoria,
            'catalogo_version' => 1,
        ], $token];
    }

    /**
     * @param  array<string, string|int>  $catalogo
     * @return array<string, mixed>
     */
    private function payload(array $catalogo, string $folio): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $folio,
            'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 120,
            'linea_proceso' => 1,
            'turno' => 'A',
            ...$catalogo,
            'resultado' => 'aprobado',
            'motivo' => null,
            'observacion' => null,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
