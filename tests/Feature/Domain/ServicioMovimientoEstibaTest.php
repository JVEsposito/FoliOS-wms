<?php

namespace Tests\Feature\Domain;

use App\Enums\EstadoOperacionSincronizacion;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Enums\TipoMovimiento;
use App\Exceptions\ConflictoMovimiento;
use App\Models\Camara;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\Movimiento;
use App\Models\OperacionSincronizacion;
use App\Models\Posicion;
use App\Models\SesionEstiba;
use App\Models\UbicacionActual;
use App\Models\User;
use App\Services\Estiba\DetectorAdvertenciasMovimiento;
use App\Services\Estiba\ServicioMovimientoEstiba;
use App\Services\Estiba\ServicioSesionEstiba;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicioMovimientoEstibaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ubicacion_inicial_crea_el_folio_solo_en_una_carga_controlada_y_actualiza_el_plano_atomicamente(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camara, $posicion] = $this->crearCamara('CAM-01');
        $sesion = $this->abrirSesion($camara, $usuario, $dispositivo);
        $operacionId = (string) Str::uuid();
        $generadoAt = CarbonImmutable::parse('2026-07-13 10:00:00');

        // La tablet nunca crea un folio: un número desconocido se rechaza.
        try {
            $this->servicio()->ubicar(
                operacionId: (string) Str::uuid(),
                numeroFolio: 'FOLIO-0001',
                tipoBulto: TipoBulto::Pallet,
                posicionDestino: $posicion,
                sesionDestino: $sesion,
                usuario: $usuario,
                dispositivo: $dispositivo,
                versionDestinoConocida: 0,
                generadoDispositivoAt: $generadoAt,
            );
            $this->fail('Se esperaba el rechazo del folio inexistente.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Valida el pallet en Validación PT', $exception->getMessage());
        }
        $this->assertSame(0, Folio::query()->count());
        $this->assertSame(0, $camara->refresh()->version_plano);

        $movimiento = $this->servicio()->ubicar(
            operacionId: $operacionId,
            numeroFolio: 'FOLIO-0001',
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $usuario,
            dispositivo: $dispositivo,
            versionDestinoConocida: 0,
            generadoDispositivoAt: $generadoAt,
            datosFolio: [
                'variedad' => 'Royal Dawn',
                'calibre' => '2J',
                'marca' => 'Marca de prueba',
            ],
            permitirFolioNuevo: true,
        );

        $folio = Folio::query()->where('numero_folio', 'FOLIO-0001')->firstOrFail();
        $operacion = OperacionSincronizacion::query()->findOrFail($operacionId);

        $this->assertSame(TipoMovimiento::UbicacionInicial, $movimiento->tipo_movimiento);
        $this->assertSame($folio->id, $movimiento->folio_id);
        $this->assertSame(TipoBulto::Pallet, $folio->tipo_bulto);
        $this->assertSame('Royal Dawn', $folio->variedad);
        $this->assertSame($generadoAt->toDateTimeString(), $folio->fecha_ingreso->toDateTimeString());
        $this->assertDatabaseHas('ubicaciones_actuales', [
            'folio_id' => $folio->id,
            'posicion_id' => $posicion->id,
            'movimiento_id' => $movimiento->id,
        ]);
        $this->assertSame(1, $camara->refresh()->version_plano);
        $this->assertSame(EstadoOperacionSincronizacion::Aceptada, $operacion->estado);
        $this->assertSame(['destino' => 0], $operacion->versiones_conocidas);
        $this->assertSame(['destino' => 1], $operacion->versiones_resultantes);
        $this->assertNotNull($sesion->refresh()->ultima_actividad_at);
    }

    public function test_repetir_la_misma_operacion_devuelve_el_mismo_movimiento(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camara, $posicion] = $this->crearCamara('CAM-01');
        $sesion = $this->abrirSesion($camara, $usuario, $dispositivo);
        $operacionId = (string) Str::uuid();
        $generadoAt = CarbonImmutable::parse('2026-07-13 10:00:00');
        $argumentos = [
            'operacionId' => $operacionId,
            'numeroFolio' => 'FOLIO-IDEMPOTENTE',
            'tipoBulto' => TipoBulto::Pallet,
            'posicionDestino' => $posicion,
            'sesionDestino' => $sesion,
            'usuario' => $usuario,
            'dispositivo' => $dispositivo,
            'versionDestinoConocida' => 0,
            'generadoDispositivoAt' => $generadoAt,
            'datosFolio' => ['variedad' => 'Santina'],
        ];

        $primero = $this->servicio()->ubicar(...$argumentos, permitirFolioNuevo: true);
        $segundo = $this->servicio()->ubicar(...$argumentos, permitirFolioNuevo: true);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, Movimiento::query()->count());
        $this->assertSame(1, Folio::query()->count());
        $this->assertSame(1, OperacionSincronizacion::query()->count());
        $this->assertSame(1, $camara->refresh()->version_plano);
    }

    public function test_un_uuid_no_puede_reutilizarse_con_otro_payload(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camara, $posicionUno, $posicionDos] = $this->crearCamara('CAM-01', 2);
        $sesion = $this->abrirSesion($camara, $usuario, $dispositivo);
        $operacionId = (string) Str::uuid();
        $generadoAt = CarbonImmutable::parse('2026-07-13 10:00:00');

        $this->ubicar(
            $operacionId,
            'FOLIO-0001',
            $posicionUno,
            $sesion,
            $usuario,
            $dispositivo,
            0,
            $generadoAt,
        );

        try {
            $this->ubicar(
                $operacionId,
                'FOLIO-0001',
                $posicionDos,
                $sesion,
                $usuario,
                $dispositivo,
                1,
                $generadoAt,
            );
            $this->fail('Se esperaba un conflicto por reutilización del UUID.');
        } catch (ConflictoMovimiento $exception) {
            $this->assertStringContainsString('datos diferentes', $exception->getMessage());
        }

        $this->assertSame(1, Movimiento::query()->count());
        $this->assertSame(
            EstadoOperacionSincronizacion::Aceptada,
            OperacionSincronizacion::query()->findOrFail($operacionId)->estado,
        );
    }

    public function test_reubicar_en_la_misma_camara_actualiza_una_sola_version(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camara, $origen, $destino] = $this->crearCamara('CAM-01', 2);
        $sesion = $this->abrirSesion($camara, $usuario, $dispositivo);
        $inicial = $this->ubicar(
            (string) Str::uuid(),
            'FOLIO-0001',
            $origen,
            $sesion,
            $usuario,
            $dispositivo,
            0,
        );

        $movimiento = $this->servicio()->mover(
            operacionId: (string) Str::uuid(),
            folio: $inicial->folio,
            posicionDestino: $destino,
            sesionOrigen: $sesion,
            sesionDestino: $sesion,
            usuario: $usuario,
            dispositivo: $dispositivo,
            versionOrigenConocida: 1,
            versionDestinoConocida: 1,
            generadoDispositivoAt: now(),
            advertenciasConfirmadas: [
                DetectorAdvertenciasMovimiento::POSICIONES_FONDO_LIBRES,
            ],
        );

        $this->assertSame(TipoMovimiento::Reubicacion, $movimiento->tipo_movimiento);
        $this->assertSame(1, $movimiento->version_origen_anterior);
        $this->assertSame(2, $movimiento->version_origen_resultante);
        $this->assertSame(1, $movimiento->version_destino_anterior);
        $this->assertSame(2, $movimiento->version_destino_resultante);
        $this->assertSame(2, $camara->refresh()->version_plano);
        $this->assertSame(
            $destino->id,
            UbicacionActual::query()->where('folio_id', $inicial->folio_id)->value('posicion_id'),
        );
    }

    public function test_trasladar_entre_camaras_actualiza_ambos_planos(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camaraOrigen, $posicionOrigen] = $this->crearCamara('CAM-01');
        [$camaraDestino, $posicionDestino] = $this->crearCamara('CAM-02');
        $sesionOrigen = $this->abrirSesion($camaraOrigen, $usuario, $dispositivo);
        $sesionDestino = $this->abrirSesion($camaraDestino, $usuario, $dispositivo);
        $inicial = $this->ubicar(
            (string) Str::uuid(),
            'FOLIO-0001',
            $posicionOrigen,
            $sesionOrigen,
            $usuario,
            $dispositivo,
            0,
        );

        $movimiento = $this->servicio()->mover(
            operacionId: (string) Str::uuid(),
            folio: $inicial->folio,
            posicionDestino: $posicionDestino,
            sesionOrigen: $sesionOrigen,
            sesionDestino: $sesionDestino,
            usuario: $usuario,
            dispositivo: $dispositivo,
            versionOrigenConocida: 1,
            versionDestinoConocida: 0,
            generadoDispositivoAt: now(),
        );

        $this->assertSame(TipoMovimiento::TrasladoEntreCamaras, $movimiento->tipo_movimiento);
        $this->assertSame($camaraOrigen->id, $movimiento->camara_origen_id);
        $this->assertSame($camaraDestino->id, $movimiento->camara_destino_id);
        $this->assertSame(2, $camaraOrigen->refresh()->version_plano);
        $this->assertSame(1, $camaraDestino->refresh()->version_plano);
        $this->assertSame(
            $posicionDestino->id,
            UbicacionActual::query()->where('folio_id', $inicial->folio_id)->value('posicion_id'),
        );
    }

    public function test_una_version_desactualizada_no_modifica_la_ubicacion(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camara, $origen, $destino] = $this->crearCamara('CAM-01', 2);
        $sesion = $this->abrirSesion($camara, $usuario, $dispositivo);
        $inicial = $this->ubicar(
            (string) Str::uuid(),
            'FOLIO-0001',
            $origen,
            $sesion,
            $usuario,
            $dispositivo,
            0,
        );
        $operacionId = (string) Str::uuid();

        try {
            $this->servicio()->mover(
                operacionId: $operacionId,
                folio: $inicial->folio,
                posicionDestino: $destino,
                sesionOrigen: $sesion,
                sesionDestino: $sesion,
                usuario: $usuario,
                dispositivo: $dispositivo,
                versionOrigenConocida: 0,
                versionDestinoConocida: 0,
                generadoDispositivoAt: now(),
            );
            $this->fail('Se esperaba un conflicto por versión desactualizada.');
        } catch (ConflictoMovimiento $exception) {
            $this->assertStringContainsString('desactualizada', $exception->getMessage());
        }

        $operacion = OperacionSincronizacion::query()->findOrFail($operacionId);
        $this->assertSame(EstadoOperacionSincronizacion::Conflicto, $operacion->estado);
        $this->assertSame('conflicto_movimiento', $operacion->codigo_error);
        $this->assertSame(1, Movimiento::query()->count());
        $this->assertSame(1, $camara->refresh()->version_plano);
        $this->assertSame(
            $origen->id,
            UbicacionActual::query()->where('folio_id', $inicial->folio_id)->value('posicion_id'),
        );
    }

    public function test_una_posicion_ocupada_no_puede_recibir_otro_folio(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camara, $posicion] = $this->crearCamara('CAM-01');
        $sesion = $this->abrirSesion($camara, $usuario, $dispositivo);
        $this->ubicar(
            (string) Str::uuid(),
            'FOLIO-OCUPANTE',
            $posicion,
            $sesion,
            $usuario,
            $dispositivo,
            0,
        );
        $operacionId = (string) Str::uuid();

        try {
            $this->ubicar(
                $operacionId,
                'FOLIO-NUEVO',
                $posicion,
                $sesion,
                $usuario,
                $dispositivo,
                1,
            );
            $this->fail('Se esperaba un conflicto por posición ocupada.');
        } catch (ConflictoMovimiento $exception) {
            $this->assertStringContainsString('ocupada', $exception->getMessage());
        }

        $this->assertFalse(Folio::query()->where('numero_folio', 'FOLIO-NUEVO')->exists());
        $this->assertSame(1, Movimiento::query()->count());
        $this->assertSame(
            EstadoOperacionSincronizacion::Conflicto,
            OperacionSincronizacion::query()->findOrFail($operacionId)->estado,
        );
    }

    public function test_una_sesion_incorrecta_rechaza_el_traslado_sin_cambiar_el_plano(): void
    {
        [$usuario, $dispositivo] = $this->crearActor();
        [$camaraOrigen, $posicionOrigen] = $this->crearCamara('CAM-01');
        [$camaraDestino, $posicionDestino] = $this->crearCamara('CAM-02');
        $sesionOrigen = $this->abrirSesion($camaraOrigen, $usuario, $dispositivo);
        $inicial = $this->ubicar(
            (string) Str::uuid(),
            'FOLIO-0001',
            $posicionOrigen,
            $sesionOrigen,
            $usuario,
            $dispositivo,
            0,
        );
        $operacionId = (string) Str::uuid();

        try {
            $this->servicio()->mover(
                operacionId: $operacionId,
                folio: $inicial->folio,
                posicionDestino: $posicionDestino,
                sesionOrigen: $sesionOrigen,
                sesionDestino: $sesionOrigen,
                usuario: $usuario,
                dispositivo: $dispositivo,
                versionOrigenConocida: 1,
                versionDestinoConocida: 0,
                generadoDispositivoAt: now(),
            );
            $this->fail('Se esperaba el rechazo de la sesión de destino.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sesión de destino', $exception->getMessage());
        }

        $this->assertSame(
            EstadoOperacionSincronizacion::Rechazada,
            OperacionSincronizacion::query()->findOrFail($operacionId)->estado,
        );
        $this->assertSame(1, $camaraOrigen->refresh()->version_plano);
        $this->assertSame(0, $camaraDestino->refresh()->version_plano);
        $this->assertSame($posicionOrigen->id, $inicial->folio->ubicacionActual->posicion_id);
    }

    /**
     * @return array{User, Dispositivo}
     */
    private function crearActor(): array
    {
        return [
            User::factory()->create(['rol' => RolUsuario::CamareroFrio]),
            Dispositivo::create(['codigo' => 'TABLET-01', 'nombre' => 'Tablet 01']),
        ];
    }

    /**
     * @return array<int, Camara|Posicion>
     */
    private function crearCamara(string $codigo, int $cantidadPosiciones = 1): array
    {
        $camara = Camara::create([
            'codigo' => $codigo,
            'nombre' => "Cámara {$codigo}",
        ]);
        $resultado = [$camara];

        for ($numero = 1; $numero <= $cantidadPosiciones; $numero++) {
            $resultado[] = Posicion::create([
                'camara_id' => $camara->id,
                'banda' => 1,
                'posicion' => $numero,
                'nivel' => 1,
                'etiqueta' => "A-{$numero}-1",
            ]);
        }

        return $resultado;
    }

    private function abrirSesion(
        Camara $camara,
        User $usuario,
        Dispositivo $dispositivo,
    ): SesionEstiba {
        return app(ServicioSesionEstiba::class)->abrir($camara, $usuario, $dispositivo);
    }

    private function ubicar(
        string $operacionId,
        string $numeroFolio,
        Posicion $posicion,
        SesionEstiba $sesion,
        User $usuario,
        Dispositivo $dispositivo,
        int $versionConocida,
        ?CarbonImmutable $generadoAt = null,
    ): Movimiento {
        return $this->servicio()->ubicar(
            operacionId: $operacionId,
            numeroFolio: $numeroFolio,
            tipoBulto: TipoBulto::Pallet,
            posicionDestino: $posicion,
            sesionDestino: $sesion,
            usuario: $usuario,
            dispositivo: $dispositivo,
            versionDestinoConocida: $versionConocida,
            generadoDispositivoAt: $generadoAt ?? CarbonImmutable::now(),
            permitirFolioNuevo: true,
        );
    }

    private function servicio(): ServicioMovimientoEstiba
    {
        return app(ServicioMovimientoEstiba::class);
    }
}
