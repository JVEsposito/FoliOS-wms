<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\EventoRecepcionRomana;
use App\Models\LoteMateriaPrima;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\TestCase;

class AjusteCuentaEnvasesAcumulativoTest extends TestCase
{
    use PreparaInspeccionesEnvases, RefreshDatabase;

    public function test_ajusta_la_cuenta_de_bins_totes_y_esponjas_sin_cambiar_el_pesaje_ni_los_lotes(): void
    {
        [$recepcion, $lote] = $this->recepcionAcumulativaValidada();
        $lote->update(['estado' => EstadoLoteMateriaPrima::EntregaParcialProceso]);
        $antes = $this->registroFisico($recepcion);
        $ruta = "/api/romana/recepciones/{$recepcion->id}";
        $this->getJson("{$ruta}/correccion-integral")->assertOk()
            ->assertJsonPath('data.cantidades_editables', false)
            ->assertJsonPath('data.cuenta_envases.disponible', true);
        $datos = [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion->version,
            'motivo' => 'Cuenta revisada con la guía y el conteo de envases del cliente.',
            'envases' => [
                ['tipo_envase' => 'bins', 'recibidos' => 3],
                ['tipo_envase' => 'totes', 'recibidos' => 47],
                ['tipo_envase' => 'esponjas', 'recibidos' => 2],
            ],
        ];
        $resultado = $this->putJson("{$ruta}/ajuste-cuenta-envases", $datos)->assertOk()->json('data');
        $cuenta = collect($resultado['correccion_integral']['cuenta_envases']['tipos'])->keyBy('tipo_envase');
        $this->assertSame([2, 3], [$cuenta['bins']['recibidos_registro'], $cuenta['bins']['recibidos_cuenta']]);
        $this->assertSame([48, 47], [$cuenta['totes']['recibidos_registro'], $cuenta['totes']['recibidos_cuenta']]);
        $this->assertSame([0, 2], [$cuenta['esponjas']['recibidos_registro'], $cuenta['esponjas']['recibidos_cuenta']]);
        $this->assertSame($antes, $this->registroFisico($recepcion));

        $ajustes = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'ajuste_cuenta')->get();
        $this->assertCount(3, $ajustes);
        foreach ($ajustes as $ajuste) {
            $this->assertSame(0, $ajuste->signo_existencia);
            $this->assertSame('CSG-ACUM', $ajuste->datos['ajuste_recepcion']['productor']['codigo']);
        }
        $evento = EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'ajuste_cuenta_envases')->sole();
        $this->assertSame($datos['motivo'], $evento->datos['motivo']);
        $this->assertSame(auth()->id(), $evento->user_id);

        // Un reintento de la misma operación conserva el ajuste y los registros físicos.
        $this->putJson("{$ruta}/ajuste-cuenta-envases", $datos)->assertOk();
        $this->assertSame(3, MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->where('tipo_movimiento', 'ajuste_cuenta')->count());
        $this->assertSame(1, EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'ajuste_cuenta_envases')->count());
        $this->assertSame($antes, $this->registroFisico($recepcion));

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum')
            ->putJson("{$ruta}/ajuste-cuenta-envases", [...$datos, 'operacion_id' => (string) Str::uuid()])->assertForbidden();
    }

    private function registroFisico(RecepcionRomana $recepcion): array
    {
        $recepcion = $recepcion->fresh();

        return [
            'recepcion' => $recepcion->only(['estado', 'estado_validacion_mp', 'peso_bruto', 'peso_tara', 'peso_neto',
                'peso_neto_por_envase', 'cantidad_envase_calculo_neto', 'tipo_envase_calculo_neto']),
            'tandas' => $recepcion->pesajesEnvases()->orderBy('id')->get()->toArray(),
            'envases' => $recepcion->detallesEnvases()->orderBy('id')->get()->toArray(),
            'guias' => $recepcion->guiasRecepcion()->orderBy('id')->get()->toArray(),
            'validacion' => $recepcion->validacionesMp()->with('segmentos.envases')->orderBy('id')->get()->toArray(),
            'lotes' => $recepcion->lotesMateriaPrima()->with('envasesDetalle')->orderBy('id')->get()->toArray(),
        ];
    }

    private function recepcionAcumulativaValidada(): array
    {
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $especie = EspecieValidacion::create(['temporada_id' => $temporada->id, 'nombre' => 'Cereza', 'activo' => true]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $recepcion = $this->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => Cliente::create(['codigo' => 'CLI-ACUM', 'nombre' => 'Cliente acumulativo', 'activo' => true])->id,
            'tipo_recepcion' => 'fruta_pesaje_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2, 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'cantidad' => 48, 'tara_unitaria' => 2]],
            'numero_guia_despacho' => '127', 'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor de prueba',
        ])->assertCreated()->json('data');
        $ruta = "/api/romana/recepciones/{$recepcion['id']}";
        $this->postJson("{$ruta}/pesajes-envases", [
            'operacion_id' => (string) Str::uuid(), 'peso_bruto' => 700,
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 48]],
        ])->assertOk();
        $this->postJson("{$ruta}/cerrar", $this->payloadConInspeccionRc02("{$ruta}/cerrar", [
            'operacion_id' => (string) Str::uuid(),
        ]))->assertOk();
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum');
        $validacion = $this->postJson("/api/validacion-mp/recepciones/{$recepcion['id']}/tomar", ['operacion_id' => (string) Str::uuid()])
            ->assertOk()->json('data');
        $confirmar = "/api/validacion-mp/validaciones/{$validacion['id']}/confirmar";
        $validada = $this->postJson($confirmar, $this->payloadConInspeccionRc02($confirmar, [
            'operacion_id' => (string) Str::uuid(), 'tarjas_verificadas' => true, 'requiere_segregacion' => false,
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 2], ['tipo_envase' => 'totes', 'cantidad_validada' => 48]],
            'csg_validacion_id' => CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-ACUM', 'activo' => true])->id,
            'variedad_validacion_id' => VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Lapins', 'activo' => true])->id,
        ]))->assertOk()->json('data');
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador]), 'sanctum');
        $lote = $this->postJson('/api/materia-prima/lotes', [
            'operacion_id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $validada['segmentos'][0]['id'],
            'numero_lote' => 'LOTE-ACUM', 'sdp' => '123', 'fecha_cosecha' => now()->toDateString(),
            'predio' => 'Fundo de prueba', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false,
        ])->assertCreated()->json('data.id');

        return [RecepcionRomana::findOrFail($recepcion['id']), LoteMateriaPrima::findOrFail($lote)];
    }
}
