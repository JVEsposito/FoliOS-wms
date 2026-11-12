<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\PesajeEnvaseRecepcionRomana;
use App\Models\RecepcionRomana;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use App\Services\MateriaPrima\ServicioLoteMateriaPrima;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

/**
 * Pesaje acumulativo de cereza en bins con sus totes (12-11-2026, REC-2611-0041).
 *
 * La guía trae 6 bins y 144 totes: cada bin lleva 24 totes y se pesa con ellos, en tandas de
 * 2 bins y 48 totes. La fruta está en los totes; el bin solo los contiene. Antes el neto de
 * cada tanda se repartía mitad para bins y mitad para totes según sus pesos de referencia.
 */
class PesajeAcumulativoBinsConTotesTest extends TestCase
{
    use PreparaInspeccionesEnvases;
    use RefreshDatabase;

    public function test_el_neto_de_las_tandas_de_bins_con_totes_es_de_los_totes(): void
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $recepcion = $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => Cliente::firstOrCreate(['codigo' => 'FALCO'], ['nombre' => 'FALCO FRUIT', 'activo' => true])->id,
            'tipo_recepcion' => 'fruta_pesaje_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 6, 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'cantidad' => 144, 'tara_unitaria' => 1.04]],
            'numero_guia_despacho' => '127', 'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor',
        ])->assertCreated()->json('data');
        $ruta = "/api/romana/recepciones/{$recepcion['id']}/pesajes-envases";
        $tanda = fn (int $totes, float $bruto) => $this->postJson($ruta, ['operacion_id' => (string) Str::uuid(),
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => $totes]], 'peso_bruto' => $bruto]);

        // 2 bins y 48 totes: tara 80 + 49,92; todo el neto es de los totes.
        $primera = $tanda(48, 536)->assertOk()->assertJsonPath('data.peso_neto', 406.08)->json('data');
        $this->assertEquals([0.0, 406.08], array_column($primera['lecturas_pesaje_envases'][0]['envases'], 'peso_neto'));

        // Una tanda registrada antes de la corrección quedó con el neto mitad y mitad: se recalcula.
        $pesaje = PesajeEnvaseRecepcionRomana::query()->where('recepcion_romana_id', $recepcion['id'])->sole();
        $pesaje->update(['envases' => array_map(fn (array $linea): array => [...$linea, 'peso_neto' => 203.04], $pesaje->envases)]);

        // Si se anotan menos totes de los que había, quedan totes pendientes: se anula y se registra bien.
        $equivocada = $tanda(24, 554.5)->assertOk()->assertJsonPath('data.pesaje_envases.tipos.1.cantidad_pendiente', 72)->json('data');
        $this->postJson("{$ruta}/{$equivocada['lecturas_pesaje_envases'][1]['id']}/anular", [
            'operacion_id' => (string) Str::uuid(), 'motivo' => 'Se anotaron 24 totes y eran 48.',
        ])->assertOk();
        $tanda(48, 554.5)->assertOk();
        $completa = $tanda(48, 556.5)->assertOk()
            ->assertJsonPath('data.pesaje_envases.completo', true)
            ->assertJsonPath('data.peso_tara', 389.76)
            ->assertJsonPath('data.peso_neto', 1257.24)
            ->json('data');
        // 1.257,24 kg en 144 totes: 8,731 kg por tote. Los bins no tienen neto propio.
        $tipos = collect($completa['pesaje_envases']['tipos'])->keyBy('tipo_envase');
        $this->assertEquals([6, 0.0], [$tipos['bins']['cantidad_pesada'], $tipos['bins']['peso_neto']]);
        $this->assertEquals([144, 1257.24, 8.731], [$tipos['totes']['cantidad_pesada'], $tipos['totes']['peso_neto'], $tipos['totes']['peso_neto_por_envase']]);
        $this->assertSame('totes', $completa['tipo_envase_calculo_neto']);
        $this->assertEquals(8.731, $completa['peso_neto_por_envase']);

        $this->postJson("/api/romana/recepciones/{$recepcion['id']}/cerrar", $this->payloadConInspeccionRc02("/api/romana/recepciones/{$recepcion['id']}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
        ]))->assertOk()->assertJsonPath('data.estado', 'cerrado');

        // Corregir el bruto de una tanda mantiene el bin sin neto.
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $cerrada = RecepcionRomana::query()->findOrFail($recepcion['id']);
        $this->actingAs($administrador, 'sanctum')->putJson("/api/romana/recepciones/{$recepcion['id']}/peso-bruto", [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $cerrada->version, 'pesaje_id' => $pesaje->id,
            'peso_bruto' => 540, 'motivo_correccion' => 'La primera tanda se leyó mal en la báscula.',
        ])->assertOk()->assertJsonPath('data.peso_neto', 1261.24);
        $this->assertEquals([0.0, 410.08], array_column($pesaje->refresh()->envases, 'peso_neto'));

        // Validación MP y lote: el lote recibe todo el neto con sus 144 totes.
        $validador = User::factory()->create(['rol' => RolUsuario::ValidadorMp]);
        $validacion = $this->actingAs($validador, 'sanctum')->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data');
        $ruta = "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar";
        $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, [
            'operacion_id' => (string) Str::uuid(), 'tarjas_verificadas' => true, 'requiere_segregacion' => false,
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 6], ['tipo_envase' => 'totes', 'cantidad_validada' => 144]],
            'csg_validacion_id' => CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-127', 'activo' => true])->id,
            'variedad_validacion_id' => VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Lapins', 'activo' => true])->id,
        ]))->assertOk();
        $recepcion = RecepcionRomana::query()->findOrFail($recepcion['id']);
        $this->assertEquals(['totes', 144, 8.759], [$recepcion->tipo_envase_calculo_neto, $recepcion->cantidad_envase_calculo_neto, (float) $recepcion->peso_neto_por_envase]);
        $segmento = SegmentoValidacionMp::query()->whereHas('validacion', fn ($q) => $q->where('recepcion_romana_id', $recepcion->id))->sole();
        $this->assertEqualsWithDelta(1261.24, app(ServicioLoteMateriaPrima::class)->netoEstimado($segmento, $recepcion), 0.0005);
    }
}
