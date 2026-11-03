<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class PesajeAcumulativoVariosEnvasesTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    public function test_los_lotes_reciben_el_neto_pesado_de_cada_tipo_de_envase(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $cliente = Cliente::create(['codigo' => 'ACU-01', 'nombre' => 'Exportadora Acumulativa', 'activo' => true]);
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);

        $recepcion = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id, 'cliente_id' => $cliente->id,
            'tipo_recepcion' => 'fruta_pesaje_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => [
                ['tipo_envase' => 'bins', 'cantidad' => 2, 'propiedad' => 'cliente', 'tara_unitaria' => 40],
                ['tipo_envase' => 'totes', 'cantidad' => 20, 'propiedad' => 'cliente', 'tara_unitaria' => 1.5],
            ],
            'numero_guia_despacho' => 'GD-ACU-1', 'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor',
        ])->assertCreated()->json('data');
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/pesajes-envases";
        // Bins y totes se pesan en tandas separadas: el neto de cada tipo es exacto.
        $this->postJson($ruta, ['operacion_id' => (string) Str::uuid(), 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2]], 'peso_bruto' => 500])->assertOk();
        $this->postJson($ruta, ['operacion_id' => (string) Str::uuid(), 'envases' => [['tipo_envase' => 'totes', 'cantidad' => 20]], 'peso_bruto' => 202.5])->assertOk();
        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", $this->payloadConInspeccionRc02(
            "/api/romana/recepciones/{$recepcion['id']}/cerrar", ['operacion_id' => (string) Str::uuid()],
        ))->assertOk()->assertJsonPath('data.peso_neto', 592.5);

        $this->actingAs($validador, 'sanctum')->getJson('/api/validacion-mp/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.pesaje_envases.tipos.0.tipo_envase', 'bins')
            ->assertJsonPath('data.0.pesaje_envases.tipos.1.cantidad_pesada', 20);
        $validacion = $this->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-ACU', 'activo' => true]);
        $variedad = VariedadValidacion::firstOrCreate(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina'], ['activo' => true]);
        $segmento = fn (string $cuartel, string $tipo, int $cantidad): array => [
            'motivos' => ['cuartel'], 'cuartel' => $cuartel, 'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
            'envases' => [['tipo_envase' => $tipo, 'cantidad' => $cantidad]],
        ];
        $rutaConfirmar = "/api/validacion-mp/validaciones/{$validacion}/confirmar";
        $this->postJson($rutaConfirmar, $this->payloadConInspeccionRc02($rutaConfirmar, [
            'operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 2], ['tipo_envase' => 'totes', 'cantidad_validada' => 20]],
            'tarjas_verificadas' => true, 'requiere_segregacion' => true,
            'csg_validacion_id' => $csg->id, 'variedad_validacion_id' => $variedad->id,
            'segmentos' => [$segmento('C1', 'bins', 2), $segmento('C2', 'totes', 20)],
        ]))->assertOk();

        $segmentos = SegmentoValidacionMp::query()->whereHas('validacion', fn ($q) => $q->where('recepcion_romana_id', $recepcion['id']))
            ->orderBy('secuencia')->pluck('id');
        $digitador = User::factory()->create(['rol' => RolUsuario::DigitadorMateriaPrima]);
        $kilos = $segmentos->map(function (string $segmentoId) use ($digitador): float {
            $id = $this->actingAs($digitador, 'sanctum')->postJson('/api/materia-prima/lotes', [
                'operacion_id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $segmentoId, 'numero_lote' => 'L-'.Str::random(6),
                'sdp' => '123', 'fecha_cosecha' => now()->toDateString(), 'predio' => 'Fundo', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false,
            ])->assertCreated()->json('data.id');

            return (float) LoteMateriaPrima::query()->findOrFail($id)->kilos_netos_calculados;
        })->all();

        // Bins: 500 − 80 de tara; totes: 202,5 − 30 de tara. Cada lote recibe lo suyo, no un promedio.
        $this->assertEqualsWithDelta(420, $kilos[0], 0.001);
        $this->assertEqualsWithDelta(172.5, $kilos[1], 0.001);
    }
}
