<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoLoteMateriaPrima;
use App\Enums\RolUsuario;
use App\Models\DefectoRecepcionMp;
use App\Models\EventoRecepcionRomana;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\RecepcionRomana;
use App\Models\User;
use App\Models\ValidacionMp;
use App\Services\Envases\ServicioCuentaCorrienteEnvases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionValidadaCorregible;
use Tests\TestCase;

class CorreccionNumeroGuiaTest extends TestCase
{
    use PreparaInspeccionesEnvases, PreparaRecepcionValidadaCorregible, RefreshDatabase;

    private const MOTIVO = 'Se digitó 1511; la guía física dice 1521.';

    public function test_corrige_solo_el_numero_aunque_la_recepcion_este_cerrada_y_con_lotes_entregados(): void
    {
        [$recepcion, $segmentos] = $this->recepcionCerradaConDosGuias();
        $lote = $this->lote($segmentos[0]);
        $lote->update(['estado' => EstadoLoteMateriaPrima::EntregaParcialProceso]);
        $validacion = ValidacionMp::where('recepcion_romana_id', $recepcion->id)->sole();
        $defecto = DefectoRecepcionMp::create(['operacion_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64),
            'recepcion_romana_id' => $recepcion->id, 'validacion_mp_id' => $validacion->id, 'temporada_id' => $recepcion->temporada_id,
            'numero_recepcion_snapshot' => $recepcion->numero_recepcion, 'numero_guia_snapshot' => '1511 / 1512', 'cliente_nombre_snapshot' => 'Cliente',
            'categoria' => 'otro', 'tipo_envase' => 'bins', 'cantidad_afectada' => 1, 'descripcion' => 'Bin trizado',
            'registrado_por_user_id' => User::factory()->create()->id, 'registrado_at' => now()]);
        $estado = $this->comoAdministrador()->getJson("/api/romana/recepciones/{$recepcion->id}/correccion-integral")->json('data');
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/ajuste-cuenta-envases", ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $estado['version'], 'motivo' => 'Conteo real de bins: 3.',
            'envases' => [['tipo_envase' => 'bins', 'recibidos' => 3, 'entregados' => 4]]])->assertOk();
        $recepcion->refresh();
        $guia = GuiaRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('numero_guia_despacho', '1511')->sole();
        $otra = GuiaRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('numero_guia_despacho', '1512')->sole();
        $payload = ['operacion_id' => (string) Str::uuid(), 'version_conocida' => $recepcion->version, 'motivo' => self::MOTIVO,
            'guias' => [['id' => $guia->id, 'numero_guia_despacho' => ' 1521 '], ['id' => $otra->id, 'numero_guia_despacho' => '1512']]];

        $this->putJson("/api/romana/recepciones/{$recepcion->id}/numero-guia", $payload)->assertOk()
            ->assertJsonPath('data.numero_guia_despacho', '1521 / 1512')
            ->assertJsonPath('data.guias.0.numero_guia_despacho', '1521');

        // Solo el número: el lote, los kilos y los conteos no cambian.
        $this->assertSame(18000.0, (float) $recepcion->refresh()->peso_neto);
        $this->assertSame(EstadoLoteMateriaPrima::EntregaParcialProceso, $lote->refresh()->estado);
        // Llega a la cuenta de envases (ingresos y ajuste de recibidos) y al registro de defectos; la salida conserva su guía.
        $documentos = MovimientoEnvase::where('recepcion_romana_id', $recepcion->id)->get()
            ->groupBy(fn ($m) => $m->tipo_movimiento->value)->map(fn ($g) => $g->pluck('numero_documento')->unique()->values()->all())->all();
        $this->assertSame(['1521 / 1512'], $documentos['recepcion_fruta']);
        $this->assertSame(['1521 / 1512'], $documentos['ajuste_cuenta']);
        $this->assertSame(['GS-CORR-1'], $documentos['salida_recepcion_fruta']);
        $this->assertSame('1521 / 1512', $defecto->refresh()->numero_guia_snapshot);
        $cuenta = app(ServicioCuentaCorrienteEnvases::class)->estadoCuenta($recepcion->cliente, $recepcion->temporada, null, null);
        $this->assertContains('1521', array_column($cuenta['movimientos'], 'documento'));
        $this->assertNotContains('1511', array_column($cuenta['movimientos'], 'documento'));
        $evento = EventoRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->where('tipo', 'numero_guia_corregido')->sole();
        $this->assertSame([self::MOTIVO, 'Guía 1511 → 1521'], [$evento->datos['motivo'], $evento->datos['resumen']]);

        // Repetir la operación no la duplica; sin cambios se rechaza.
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/numero-guia", $payload)->assertOk();
        $this->assertSame(1, EventoRecepcionRomana::where('tipo', 'numero_guia_corregido')->count());
        $this->putJson("/api/romana/recepciones/{$recepcion->id}/numero-guia", [...$payload, 'operacion_id' => (string) Str::uuid(),
            'version_conocida' => $recepcion->refresh()->version])->assertConflict()->assertJsonPath('message', 'Los números de guía no presentan cambios.');
    }

    public function test_intercambia_numeros_y_valida_duplicados_permisos_y_version(): void
    {
        [$recepcion] = $this->recepcionCerradaConDosGuias(cerrar: false);
        $guias = GuiaRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->orderBy('secuencia')->get();
        $ruta = "/api/romana/recepciones/{$recepcion->id}/numero-guia";
        $payload = fn (array $numeros, ?int $version = null): array => ['operacion_id' => (string) Str::uuid(),
            'version_conocida' => $version ?? RecepcionRomana::findOrFail($recepcion->id)->version, 'motivo' => 'Las guías se digitaron cruzadas.',
            'guias' => [['id' => $guias[0]->id, 'numero_guia_despacho' => $numeros[0]], ['id' => $guias[1]->id, 'numero_guia_despacho' => $numeros[1]]]];
        // Otra recepción del mismo cliente ya usa la guía 9001.
        $otra = $recepcion->replicate(['numero_recepcion', 'operacion_id', 'guia_vigente'])->fill(['numero_recepcion' => 'REC-OTRA-0001',
            'operacion_id' => (string) Str::uuid(), 'numero_guia_despacho' => '9001']);
        $otra->save();
        GuiaRecepcionRomana::create([...$guias[0]->only(['temporada_id', 'cliente_id', 'envases']), 'recepcion_romana_id' => $otra->id,
            'secuencia' => 1, 'numero_guia_despacho' => '9001', 'vigente' => 1]);

        $this->actingAs(User::factory()->create(['rol' => RolUsuario::OperadorRomana, 'activo' => true]), 'sanctum')
            ->putJson($ruta, $payload(['1512', '1511']))->assertForbidden();
        $this->comoAdministrador()->putJson($ruta, $payload(['1512', '1511'], 1))->assertConflict();
        $this->putJson($ruta, $payload(['1513', '1513']))->assertUnprocessable()->assertJsonValidationErrors('guias');
        $this->putJson($ruta, $payload(['9001', '1512']))->assertUnprocessable()
            ->assertJsonPath('errors.guias.0', 'La guía 9001 ya está registrada para este cliente en REC-OTRA-0001.');
        $this->putJson($ruta, [...$payload(['1512', '1511']), 'guias' => [$payload(['1512', '1511'])['guias'][0]]])->assertUnprocessable();

        // Intercambiar los números entre las dos guías del camión no choca con la clave única.
        $this->putJson($ruta, $payload(['1512', '1511']))->assertOk()->assertJsonPath('data.numero_guia_despacho', '1512 / 1511');
        $this->assertSame(['1512', '1511'], GuiaRecepcionRomana::where('recepcion_romana_id', $recepcion->id)->orderBy('secuencia')->pluck('numero_guia_despacho')->all());
    }
}
