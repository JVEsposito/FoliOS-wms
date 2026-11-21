<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoOperacionalFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Posicion;
use App\Models\UbicacionActual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PreparaDespachoFrigorifico;
use Tests\TestCase;

class MovimientosFoliosDespachadosTest extends TestCase
{
    use PreparaDespachoFrigorifico;
    use RefreshDatabase;

    public static function salidas(): array
    {
        return [['en_anden'], ['cargado'], ['despachado']];
    }

    #[DataProvider('salidas')]
    public function test_un_pallet_ya_enviado_al_anden_no_reingresa_por_el_plano_sin_registrar_su_retorno(string $estadoSalida): void
    {
        config(['planificador.mode' => 'off']);
        $contexto = $this->crearContextoFrio(1, 'SALIDO');
        $folio = $contexto['folios'][0];
        $carga = $this->crearCargaPublicada($contexto['despachador'], $contexto['anden'], [$folio]);
        $asignacion = $carga->asignacionesActuales()->sole();
        $this->withToken($contexto['token'])->postJson("/api/cargas/asignaciones/{$asignacion->id}/enviar-anden", [
            'operacion_id' => (string) Str::uuid(), 'anden_id' => $contexto['anden']->id,
            'sesion_estiba_id' => $contexto['sesion']->id, 'version_camara_conocida' => $contexto['camara']->refresh()->version_plano,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertOk();
        // La carga ya se muestra despachada, pero el folio sigue activo hasta cerrar el camión.
        $this->assertSame('despachada', $carga->refresh()->estado->value);
        $this->assertTrue($folio->refresh()->activo);
        // También protege registros históricos con estado del folio desactualizado.
        $asignacion->update(['estado' => $estadoSalida]);
        $posicion = $contexto['camara']->posiciones()->sole();
        $version = $contexto['camara']->refresh()->version_plano;

        $this->withToken($contexto['token'])->postJson('/api/movimientos/ubicar', [
            'operacion_id' => (string) Str::uuid(), 'numero_folio' => $folio->numero_folio, 'tipo_bulto' => 'pallet',
            'posicion_destino_id' => $posicion->id, 'sesion_destino_id' => $contexto['sesion']->id,
            'version_destino_conocida' => $version, 'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertConflict()->assertJsonPath('codigo', 'conflicto_operacional');
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        $this->assertSame($estadoSalida, $asignacion->refresh()->estado->value);
        $this->assertSame($version, $contexto['camara']->refresh()->version_plano);
        $this->assertSame(2, $folio->movimientos()->count());
    }

    public static function operaciones(): array
    {
        return [
            'ubicar despachado inactivo' => ['ubicar', 'despachado', false],
            'ubicar despachado con activo heredado' => ['ubicar', 'despachado', true],
            'mover despachado inactivo' => ['mover', 'despachado', false],
            'mover despachado con activo heredado' => ['mover', 'despachado', true],
            'mover anulado' => ['mover', 'anulado', true],
            'mover agotado' => ['mover', 'agotado', true],
            'mover retirado definitivo' => ['mover', 'retirado_definitivo', true],
            'mover inactivo con estado heredado' => ['mover', 'disponible', false],
        ];
    }

    #[DataProvider('operaciones')]
    public function test_el_plano_rechaza_folios_fuera_de_operacion_sin_cambiar_su_ubicacion(string $accion, string $estado, bool $activo): void
    {
        config(['planificador.mode' => 'off']);
        $usuario = User::factory()->create(['rol' => RolUsuario::CamareroFrio]);
        $dispositivo = Dispositivo::create(['codigo' => 'TABLET-PLANO-DESPACHADOS', 'nombre' => 'Tablet']);
        $token = $usuario->crearTokenParaDispositivo($dispositivo, 'plano')->plainTextToken;
        $camara = Camara::create(['codigo' => 'CAM-DESPACHADOS', 'nombre' => 'Cámara', 'version_plano' => 0]);
        $origen = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 1, 'nivel' => 1, 'etiqueta' => 'B01-P01-N1']);
        $destino = Posicion::create(['camara_id' => $camara->id, 'banda' => 1, 'posicion' => 2, 'nivel' => 1, 'etiqueta' => 'B01-P02-N1']);
        $sesion = $this->withToken($token)->postJson("/api/camaras/{$camara->id}/sesiones")->assertCreated()->json('data.id');
        $folio = Folio::create(['numero_folio' => 'DESPACHADO-PLANO', 'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion, 'activo' => true, 'fecha_ingreso' => now()]);
        if ($accion !== 'ubicar') {
            // Una ubicación histórica incorrecta tampoco autoriza moverlo desde el plano.
            UbicacionActual::create(['folio_id' => $folio->id, 'camara_id' => $camara->id, 'posicion_id' => $origen->id, 'ubicado_at' => now()]);
        }
        $folio->update(['estado_operacional' => $estado, 'activo' => $activo]);
        $operacion = (string) Str::uuid();
        $payload = ['operacion_id' => $operacion, 'generado_dispositivo_at' => now()->subDay()->toAtomString(),
            'advertencias_confirmadas' => ['posiciones_fondo_libres', 'nivel_sin_soporte', 'nivel_superior_ocupado']];
        $payload += match ($accion) {
            'ubicar' => ['numero_folio' => $folio->numero_folio, 'tipo_bulto' => 'pallet', 'posicion_destino_id' => $destino->id,
                'sesion_destino_id' => $sesion, 'version_destino_conocida' => 0],
            'mover' => ['folio_id' => $folio->id, 'posicion_destino_id' => $destino->id, 'sesion_origen_id' => $sesion,
                'sesion_destino_id' => $sesion, 'version_origen_conocida' => 0, 'version_destino_conocida' => 0],
        };

        // Reintentar el mismo UUID offline conserva el rechazo y no genera movimientos.
        foreach (range(1, 2) as $intento) {
            $respuesta = $this->withToken($token)->postJson("/api/movimientos/{$accion}", $payload)->assertConflict();
            $this->assertStringContainsString($estado === 'despachado' ? 'despachado' : 'no está activo', $respuesta->json('message'));
        }
        $this->assertSame($estado, $folio->refresh()->estado_operacional->value);
        $this->assertSame($activo, $folio->activo);
        $this->assertSame(0, $camara->refresh()->version_plano);
        $this->assertDatabaseCount('movimientos', 0);
        $this->assertDatabaseHas('operaciones_sincronizacion', ['id' => $operacion, 'estado' => 'conflicto']);
        if ($accion === 'ubicar') {
            $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $folio->id]);
        } else {
            $this->assertDatabaseHas('ubicaciones_actuales', ['folio_id' => $folio->id, 'posicion_id' => $origen->id]);
        }
    }
}
