<?php

namespace Tests\Concerns;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\CsgValidacion;
use App\Models\EspecieValidacion;
use App\Models\LoteMateriaPrima;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use App\Models\VariedadValidacion;
use Illuminate\Support\Str;

/** Recepción validada para las correcciones de administración. Requiere PreparaInspeccionesEnvases. */
trait PreparaRecepcionValidadaCorregible
{
    protected function comoAdministrador(): static
    {
        return $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]), 'sanctum');
    }

    protected function lote(array $segmento): LoteMateriaPrima
    {
        $id = $this->actingAs(User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]), 'sanctum')
            ->postJson('/api/materia-prima/lotes', ['operacion_id' => (string) Str::uuid(), 'segmento_validacion_mp_id' => $segmento['id'],
                'numero_lote' => 'LOTE-CORR-'.$segmento['secuencia'], 'sdp' => '123', 'fecha_cosecha' => now()->toDateString(),
                'predio' => 'Fundo de prueba', 'tipo_producto' => 'materia_prima', 'requiere_hidrocooler' => false])->assertCreated()->json('data.id');

        return LoteMateriaPrima::findOrFail($id);
    }

    /**
     * Camión con dos guías (1511 y 1512), validado en dos segmentos y con destare cerrado: 4 bins y 97 totes,
     * neto 18.000 kg. Con `$dosCsg` el segundo segmento es de otro productor (CSG-CORR-B).
     */
    protected function recepcionCerradaConDosGuias(bool $cerrar = true, bool $dosCsg = false): array
    {
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::firstOrCreate(['codigo' => 'CLI-CORR'], ['nombre' => 'Cliente corrección', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $envases = [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => 48]];
        $r = $this->postJson('/api/romana/recepciones', ['operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id, 'tipo_recepcion' => 'fruta_con_envases', 'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 4], ['tipo_envase' => 'totes', 'cantidad' => 96]],
            'guias' => [['numero_guia_despacho' => '1511', 'envases' => $envases], ['numero_guia_despacho' => '1512', 'envases' => $envases]],
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'termo', 'rut_conductor' => '12.345.678-5',
            'nombre_conductor' => 'Conductor de prueba', 'peso_bruto' => 28000])->assertCreated()->json('data');
        $this->postJson("/api/romana/recepciones/{$r['id']}/confirmar-ingreso", ['operacion_id' => (string) Str::uuid()])->assertOk();
        $csg = CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-CORR', 'activo' => true]);
        $otro = $dosCsg ? CsgValidacion::create(['temporada_id' => $temporada->id, 'codigo' => 'CSG-CORR-B', 'activo' => true]) : $csg;
        $variedad = VariedadValidacion::create(['especie_validacion_id' => $especie->id, 'nombre' => 'Santina', 'activo' => true]);
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::ValidadorMp]), 'sanctum');
        $v = $this->postJson("/api/validacion-mp/recepciones/{$r['id']}/tomar", ['operacion_id' => (string) Str::uuid()])->assertOk()->json('data');
        $ruta = "/api/validacion-mp/validaciones/{$v['id']}/confirmar";
        $validada = $this->postJson($ruta, $this->payloadConInspeccionRc02($ruta, ['operacion_id' => (string) Str::uuid(), 'tarjas_verificadas' => true,
            'requiere_segregacion' => true,
            'envases' => [['tipo_envase' => 'bins', 'cantidad_validada' => 4], ['tipo_envase' => 'totes', 'cantidad_validada' => 97]],
            'segmentos' => array_map(fn ($g, $i) => ['guia_recepcion_romana_id' => $g['id'], 'motivos' => ['guia'],
                'csg_validacion_id' => $i === 0 ? $csg->id : $otro->id, 'variedad_validacion_id' => $variedad->id,
                'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2], ['tipo_envase' => 'totes', 'cantidad' => $i === 0 ? 48 : 49]]], $r['guias'], [0, 1])]))
            ->assertOk()->json('data');
        if (! $cerrar) {
            return [RecepcionRomana::findOrFail($r['id']), $validada['segmentos']];
        }
        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana]), 'sanctum');
        $cierre = "/api/romana/recepciones/{$r['id']}/cerrar";
        $this->postJson($cierre, $this->payloadConInspeccionRc02($cierre, ['operacion_id' => (string) Str::uuid(),
            'modo_salida_envases' => 'mismos', 'numero_guia_salida' => 'GS-CORR-1',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 2]],
            'peso_tara' => 10000, 'tipo_envase_calculo_neto' => 'bins']))->assertOk()->assertJsonPath('data.peso_neto', 18000);

        return [RecepcionRomana::findOrFail($r['id']), $validada['segmentos']];
    }
}
