<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Models\CambioAtributosPt;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\ReservaCargaFolio;
use App\Models\RetencionOperacionalFolio;
use App\Models\User;
use App\Services\Consultas\ServicioExpedienteFolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\PreparaCorreccionValidacion;
use Tests\TestCase;

class CambioAtributosPtApiTest extends TestCase
{
    use PreparaCorreccionValidacion;
    use RefreshDatabase;

    private const URL = '/api/administracion/atributos-pt/folios';

    /** @return array{0: array<string, mixed>, 1: User, 2: Folio} */
    private function folioValidado(string $numero): array
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $this->conToken($tokenPda)->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, $numero))->assertCreated();
        $this->conToken($this->tokenOficina($usuario));

        return [$catalogo, $usuario, Folio::query()->where('numero_folio', $numero)->firstOrFail()];
    }

    public function test_administrador_cambia_embalaje_y_cajas_de_un_folio_en_camara_con_motivo(): void
    {
        [$catalogo, $usuario, $folio] = $this->folioValidado('PT-ATR-0001');
        $folio->update(['estado_operacional' => 'disponible', 'condicion_termica' => 'prefrio_aprobado', 'habilitacion_almacenamiento' => 'habilitado']);

        $this->getJson(self::URL.'?folio=PT-ATR-0001')->assertOk()
            ->assertJsonPath('data.folio.envase', 'Caja 5 kg')
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.puede_cambiar_producto', true);
        $this->getJson(self::URL."/{$folio->id}/producto")->assertOk()
            ->assertJsonPath('validacion.cantidad_cajas', 120);

        $operacionId = (string) Str::uuid();
        $payload = [...$this->payloadCorreccion($catalogo, $operacionId), 'motivo_correccion' => 'Se embaló en cartón de 10 kg y se contaron 118 cajas.'];
        $this->postJson(self::URL."/{$folio->id}/producto", $payload)->assertOk()
            ->assertJsonPath('data.folio.envase', 'Cartón 10 kg')
            ->assertJsonPath('data.folio.cantidad_cajas', 118)
            ->assertJsonPath('data.folio.estado_operacional', 'disponible')
            ->assertJsonPath('data.historial.0.tipo', 'producto')
            ->assertJsonPath('data.historial.0.antes.envase', 'Caja 5 kg')
            ->assertJsonPath('data.historial.0.despues.cantidad_cajas', 118)
            ->assertJsonPath('data.historial.0.motivo', $payload['motivo_correccion']);
        // Reintento con la misma operación: no duplica ni vuelve a corregir.
        $this->postJson(self::URL."/{$folio->id}/producto", $payload)->assertOk();

        $this->assertSame(1, CambioAtributosPt::query()->count());
        $this->assertDatabaseHas('correcciones_validacion_pallet', ['operacion_id' => $operacionId, 'folio_id' => $folio->id, 'corregido_por_user_id' => $usuario->id]);
        $this->assertSame('Cartón 10 kg', $folio->fresh()->datos_externos['envase']);
    }

    public function test_cambia_la_condicion_de_prefrio_en_todos_sus_sentidos_dejando_rastro(): void
    {
        [, , $folio] = $this->folioValidado('PT-ATR-0002');
        $cambiar = fn (string $condicion) => $this->postJson(self::URL."/{$folio->id}/condicion-termica", [
            'operacion_id' => (string) Str::uuid(), 'condicion' => $condicion, 'motivo' => "Administración fija {$condicion} tras revisión.",
        ]);

        $cambiar('prefrio_aprobado')->assertOk()
            ->assertJsonPath('data.folio.condicion_termica', 'prefrio_aprobado')
            ->assertJsonPath('data.folio.habilitacion_almacenamiento', 'habilitado')
            ->assertJsonPath('data.folio.estado_operacional', 'pendiente_ubicacion');
        $this->assertDatabaseHas('historial_habilitaciones_almacenamiento', ['folio_id' => $folio->id, 'fuente' => 'correccion_administrativa', 'proceso_origen' => 'cambio_atributos_pt']);

        $cambiar('retenido')->assertOk()
            ->assertJsonPath('data.folio.condicion_termica', 'retenido')
            ->assertJsonPath('data.folio.estado_operacional', 'bloqueado');
        $this->assertTrue(RetencionOperacionalFolio::query()->where('bloqueo_folio_id', $folio->id)->exists());

        // Liberar la retención con condición heredada: vuelve a quedar por ubicar.
        $cambiar('condicion_heredada')->assertOk()
            ->assertJsonPath('data.folio.condicion_termica', 'condicion_heredada')
            ->assertJsonPath('data.folio.estado_operacional', 'pendiente_ubicacion');
        $this->assertFalse(RetencionOperacionalFolio::query()->where('bloqueo_folio_id', $folio->id)->exists());

        $cambiar('requiere_reproceso')->assertOk()
            ->assertJsonPath('data.folio.condicion_termica', 'requiere_reproceso')
            ->assertJsonPath('data.folio.habilitacion_almacenamiento', 'no_habilitado')
            ->assertJsonPath('data.folio.estado_operacional', 'pendiente_prefrio');

        $cambiar('requiere_reproceso')->assertUnprocessable();
        $cambiar('en_proceso')->assertUnprocessable()->assertJsonValidationErrors('condicion');

        $historial = $this->getJson(self::URL."/{$folio->id}")->assertOk()->json('data.historial');
        $this->assertSame(['requiere_reproceso', 'condicion_heredada', 'retenido', 'prefrio_aprobado'], array_column(array_column($historial, 'despues'), 'condicion_termica'));
        $this->assertSame('pendiente_prefrio', $historial[3]['antes']['condicion_termica']);

        // El expediente del folio muestra cada cambio con su motivo.
        $eventos = collect(app(ServicioExpedienteFolio::class)->obtener($folio->fresh())['timeline'])->where('tipo', 'cambio_atributos')->values();
        $this->assertCount(4, $eventos);
        $this->assertSame('pendiente_prefrio → prefrio_aprobado', $eventos[0]['meta']['Prefrío']);
        $this->assertSame('Administración fija prefrio_aprobado tras revisión.', $eventos[0]['descripcion']);
    }

    public function test_avisa_la_carga_y_bloquea_folios_despachados(): void
    {
        [$catalogo, $usuario, $folio] = $this->folioValidado('PT-ATR-0003');
        $carga = Carga::create([
            'temporada_id' => $folio->temporada_id, 'codigo' => 'CAR-ATR-1', 'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Alta, 'version' => 1, 'creada_por_user_id' => $usuario->id, 'actualizada_por_user_id' => $usuario->id,
        ]);
        $asignacion = CargaFolio::create([
            'carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
            'asignado_por_user_id' => $usuario->id, 'asignado_at' => now(),
        ]);
        ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);

        $this->getJson(self::URL."/{$folio->id}")->assertOk()->assertJsonPath('data.carga.codigo', 'CAR-ATR-1');
        $this->postJson(self::URL."/{$folio->id}/condicion-termica", [
            'operacion_id' => (string) Str::uuid(), 'condicion' => 'prefrio_aprobado', 'motivo' => 'Prefrío hecho en otra planta, con registro.',
        ])->assertOk()->assertJsonPath('data.historial.0.carga_codigo', 'CAR-ATR-1');

        $folio->update(['estado_operacional' => 'despachado']);
        $this->getJson(self::URL."/{$folio->id}")->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.motivo_bloqueo', 'El folio está despachado y ya no admite cambios de atributos.');
        $this->postJson(self::URL."/{$folio->id}/producto", [
            ...$this->payloadCorreccion($catalogo, (string) Str::uuid()), 'motivo_correccion' => 'Intento después del despacho.',
        ])->assertConflict();
        $this->postJson(self::URL."/{$folio->id}/condicion-termica", [
            'operacion_id' => (string) Str::uuid(), 'condicion' => 'retenido', 'motivo' => 'Intento después del despacho.',
        ])->assertConflict();
        $this->assertSame(1, CambioAtributosPt::query()->count());
    }

    public function test_solo_administracion_y_siempre_con_motivo(): void
    {
        [, , $folio] = $this->folioValidado('PT-ATR-0004');
        $this->postJson(self::URL."/{$folio->id}/condicion-termica", [
            'operacion_id' => (string) Str::uuid(), 'condicion' => 'prefrio_aprobado', 'motivo' => 'corto',
        ])->assertUnprocessable()->assertJsonValidationErrors('motivo');
        $this->getJson(self::URL.'?folio=NO-EXISTE')->assertNotFound();

        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $this->conToken($this->tokenOficina($supervisor));
        $this->getJson(self::URL."/{$folio->id}")->assertForbidden();
        $this->postJson(self::URL."/{$folio->id}/condicion-termica", [
            'operacion_id' => (string) Str::uuid(), 'condicion' => 'prefrio_aprobado', 'motivo' => 'Sin permiso de administración.',
        ])->assertForbidden();
        $this->assertSame('pendiente_prefrio', $folio->fresh()->condicion_termica->value);
    }
}
