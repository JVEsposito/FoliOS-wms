<?php

namespace Tests\Feature\Api;

use App\Enums\ContenidoCamara;
use App\Enums\EstadoCarga;
use App\Enums\EstadoCargaFolio;
use App\Enums\PrioridadCarga;
use App\Enums\RolUsuario;
use App\Models\AnulacionValidacionPallet;
use App\Models\Camara;
use App\Models\CambioAtributosPt;
use App\Models\Carga;
use App\Models\CargaFolio;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\ReservaCargaFolio;
use App\Models\RetencionOperacionalFolio;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Models\ValidacionPallet;
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

    private string $tokenPda = '';

    /** @return array{0: array<string, mixed>, 1: User, 2: Folio} */
    private function folioValidado(string $numero): array
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $this->tokenPda = $tokenPda;
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

    private function ubicarEnCamara(Folio $folio): UbicacionActual
    {
        $camara = Camara::query()->firstOrCreate(['codigo' => 'CAM-ATR'], [
            'nombre' => 'Cámara 9 · Cerezas', 'contenido' => ContenidoCamara::Productos,
            'cantidad_bandas' => 1, 'posiciones_por_banda' => 10, 'cantidad_niveles' => 1,
        ]);
        $posicion = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => Posicion::query()->count() + 1, 'nivel' => 1,
            'etiqueta' => 'B01-P0'.(Posicion::query()->count() + 1).'-N1']);
        $folio->update(['estado_operacional' => 'disponible', 'condicion_termica' => 'prefrio_aprobado', 'habilitacion_almacenamiento' => 'habilitado']);

        return UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $camara->id, 'posicion_id' => $posicion->id, 'ubicado_at' => now()]);
    }

    public function test_cambia_el_numero_de_un_folio_despues_de_prefrio_desde_el_modulo(): void
    {
        [$catalogo, , $folio] = $this->folioValidado('PT-ATR-NUM-1');
        $this->ubicarEnCamara($folio);
        $this->getJson(self::URL."/{$folio->id}/producto")->assertOk()->assertJsonPath('puede_cambiar_numero_folio', true);

        $this->postJson(self::URL."/{$folio->id}/producto", [
            ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
            'numero_folio' => 'PT-ATR-NUM-9', 'motivo_correccion' => 'Se digitó mal el folio; la etiqueta dice PT-ATR-NUM-9.',
        ])->assertOk()
            ->assertJsonPath('data.folio.numero_folio', 'PT-ATR-NUM-9')
            ->assertJsonPath('data.folio.estado_operacional', 'disponible')
            ->assertJsonPath('data.folio.ubicacion.camara', 'Cámara 9 · Cerezas')
            ->assertJsonPath('data.historial.0.antes.numero_folio', null);
        $this->assertSame($folio->id, Folio::query()->where('numero_folio', 'PT-ATR-NUM-9')->value('id'));
        $this->assertDatabaseHas('cambios_atributos_pt', ['folio_id' => $folio->id, 'numero_folio' => 'PT-ATR-NUM-9']);
    }

    public function test_anula_una_validacion_erronea_ya_ubicada_y_libera_su_numero_y_posicion(): void
    {
        [$catalogo, $usuario, $folio] = $this->folioValidado('PT-ATR-ANUL-1');
        $ubicacion = $this->ubicarEnCamara($folio);
        $this->getJson(self::URL."/{$folio->id}")->assertOk()->assertJsonPath('data.puede_anular', true);

        $operacion = (string) Str::uuid();
        $payload = ['operacion_id' => $operacion, 'motivo_categoria' => 'pallet_duplicado', 'motivo' => 'El pallet ya estaba validado con otro folio; este no existe.'];
        $this->postJson(self::URL."/{$folio->id}/anular", $payload)->assertOk()
            ->assertJsonPath('data.folio.estado_operacional', 'anulado')
            ->assertJsonPath('data.folio.activo', false)
            ->assertJsonPath('data.folio.numero_folio', 'PT-ATR-ANUL-1-ANUL-1')
            ->assertJsonPath('data.historial.0.tipo', 'anulacion')
            ->assertJsonPath('data.historial.0.antes.ubicacion.camara', 'Cámara 9 · Cerezas')
            ->assertJsonPath('message', 'Validación del folio PT-ATR-ANUL-1 anulada. El número PT-ATR-ANUL-1 quedó libre para validar el pallet correcto.');
        // Reintento: misma respuesta, sin una segunda anulación.
        $this->postJson(self::URL."/{$folio->id}/anular", $payload)->assertOk();

        $this->assertSame(1, AnulacionValidacionPallet::query()->count());
        $this->assertDatabaseMissing('ubicaciones_actuales', ['id' => $ubicacion->id]);
        $this->assertSame('anulada', ValidacionPallet::query()->where('folio_id', $folio->id)->sole()->estado->value);
        $anulacion = AnulacionValidacionPallet::query()->sole();
        $this->assertSame('PT-ATR-ANUL-1', $anulacion->numero_folio);
        $this->assertSame('B01-P01-N1', $anulacion->snapshot['ubicacion']['posicion']);

        // El pallet correcto se valida con ese número como un folio nuevo, sin conflicto.
        $this->conToken($this->tokenPda)->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PT-ATR-ANUL-1'))
            ->assertCreated()->assertJsonPath('data.resultado', 'aprobado');
        $nuevo = Folio::query()->where('numero_folio', 'PT-ATR-ANUL-1')->sole();
        $this->assertNotSame($folio->id, $nuevo->id);
        $this->assertTrue($nuevo->activo);
    }

    public function test_sin_actividad_usa_la_anulacion_normal_y_el_numero_se_puede_revalidar(): void
    {
        [, , $folio] = $this->folioValidado('PT-ATR-ANUL-2');
        $this->postJson(self::URL."/{$folio->id}/anular", [
            'operacion_id' => (string) Str::uuid(), 'motivo_categoria' => 'folio_incorrecto', 'motivo' => 'Folio digitado sobre otro pallet.',
        ])->assertOk()
            ->assertJsonPath('data.folio.numero_folio', 'PT-ATR-ANUL-2')
            ->assertJsonPath('data.folio.estado_operacional', 'anulado')
            ->assertJsonPath('message', 'Validación del folio PT-ATR-ANUL-2 anulada. Ese número puede volver a validarse.');
        $this->assertDatabaseHas('cambios_atributos_pt', ['folio_id' => $folio->id, 'tipo' => 'anulacion']);
    }

    public function test_no_anula_lo_que_otro_proceso_necesita(): void
    {
        [, $usuario, $folio] = $this->folioValidado('PT-ATR-ANUL-3');
        $this->ubicarEnCamara($folio);
        $carga = Carga::create([
            'temporada_id' => $folio->temporada_id, 'codigo' => 'CAR-ATR-9', 'estado' => EstadoCarga::Pendiente,
            'prioridad' => PrioridadCarga::Alta, 'version' => 1, 'creada_por_user_id' => $usuario->id, 'actualizada_por_user_id' => $usuario->id,
        ]);
        $asignacion = CargaFolio::create(['carga_id' => $carga->id, 'folio_id' => $folio->id, 'estado' => EstadoCargaFolio::Pendiente,
            'asignado_por_user_id' => $usuario->id, 'asignado_at' => now()]);
        ReservaCargaFolio::create(['folio_id' => $folio->id, 'carga_folio_id' => $asignacion->id]);

        $this->getJson(self::URL."/{$folio->id}")->assertOk()
            ->assertJsonPath('data.puede_anular', false)
            ->assertJsonPath('data.motivo_no_anulable', 'El pallet está en la carga CAR-ATR-9. Quítalo de la carga antes de anular su validación.');
        $this->postJson(self::URL."/{$folio->id}/anular", [
            'operacion_id' => (string) Str::uuid(), 'motivo_categoria' => 'otro', 'motivo' => 'Intento con el pallet en una carga.',
        ])->assertConflict();
        $this->assertTrue($folio->fresh()->activo);
        $this->assertSame(0, AnulacionValidacionPallet::query()->count());
    }
}
