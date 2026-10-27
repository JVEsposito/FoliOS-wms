<?php

namespace Tests\Feature\Domain;

use App\Enums\CondicionTermicaFolio;
use App\Enums\DominioTransicionOperacional;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\EstadoTransicionOperacional;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Exceptions\ConflictoOperacion;
use App\Models\CambioTransicionOperacional;
use App\Models\Folio;
use App\Models\TransicionOperacional;
use App\Models\User;
use App\Services\Transiciones\ComandoTransicionOperacional;
use App\Services\Transiciones\MotorTransicionesOperacionales;
use App\Services\Transiciones\NormalizadorTransicionOperacional;
use DomainException;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MotorTransicionesOperacionalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_y_audita_el_cambio_de_estado_en_una_sola_transaccion(): void
    {
        $usuario = $this->usuario();
        $folio = $this->folio('PAL-MOTOR-001');
        $operacionId = (string) Str::uuid();

        $resultado = $this->motor()->ejecutar(
            new ComandoTransicionOperacional(
                dominio: DominioTransicionOperacional::Prefrio,
                tipo: 'folio.aprobar',
                usuario: $usuario,
                payload: ['folio_id' => $folio->id, 'temperatura' => -0.7],
                operacionId: $operacionId,
                sujetoTipo: Folio::class,
                sujetoId: (string) $folio->id,
                referencia: $folio->numero_folio,
            ),
            function () use ($folio): Folio {
                $folio->update([
                    'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
                    'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
                ]);

                return $folio->refresh();
            },
        );

        $this->assertSame(
            CondicionTermicaFolio::PrefrioAprobado,
            $resultado->condicion_termica,
        );
        $transicion = TransicionOperacional::query()
            ->where('operacion_id', $operacionId)
            ->firstOrFail();
        $this->assertSame(EstadoTransicionOperacional::Aplicada, $transicion->estado);
        $this->assertSame(1, $transicion->cantidad_cambios);
        $this->assertNull($transicion->error_mensaje);

        $cambio = $transicion->cambios()->firstOrFail();
        $this->assertSame(Folio::class, $cambio->modelo_tipo);
        $this->assertSame((string) $folio->id, $cambio->modelo_id);
        $this->assertSame(
            CondicionTermicaFolio::PendientePrefrio->value,
            $cambio->datos_anteriores['condicion_termica'],
        );
        $this->assertSame(
            CondicionTermicaFolio::PrefrioAprobado->value,
            $cambio->datos_nuevos['condicion_termica'],
        );
    }

    public function test_rechazo_conserva_la_evidencia_pero_revierte_todos_los_cambios(): void
    {
        $usuario = $this->usuario();
        $folio = $this->folio('PAL-MOTOR-002');
        $operacionId = (string) Str::uuid();

        try {
            $this->motor()->ejecutar(
                new ComandoTransicionOperacional(
                    dominio: DominioTransicionOperacional::Prefrio,
                    tipo: 'folio.aprobar',
                    usuario: $usuario,
                    payload: ['folio_id' => $folio->id],
                    operacionId: $operacionId,
                    sujetoTipo: Folio::class,
                    sujetoId: (string) $folio->id,
                ),
                function () use ($folio): never {
                    $folio->update([
                        'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
                    ]);

                    throw new DomainException('La lectura final no cumple el límite térmico.');
                },
            );
            $this->fail('Se esperaba el rechazo de la transición.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'La lectura final no cumple el límite térmico.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            CondicionTermicaFolio::PendientePrefrio,
            $folio->refresh()->condicion_termica,
        );
        $transicion = TransicionOperacional::query()
            ->where('operacion_id', $operacionId)
            ->firstOrFail();
        $this->assertSame(EstadoTransicionOperacional::Rechazada, $transicion->estado);
        $this->assertSame(0, $transicion->cantidad_cambios);
        $this->assertSame('regla_dominio', $transicion->error_codigo);
        $this->assertDatabaseCount('cambios_transiciones_operacionales', 0);
    }

    public function test_transiciones_anidadas_atribuyen_cada_cambio_al_dominio_que_lo_ejecuta(): void
    {
        $usuario = $this->usuario();
        $folio = $this->folio('PAL-MOTOR-003');
        $motor = $this->motor();

        $motor->ejecutar(
            new ComandoTransicionOperacional(
                dominio: DominioTransicionOperacional::Despacho,
                tipo: 'folio.enviar_anden',
                usuario: $usuario,
                payload: ['folio_id' => $folio->id],
            ),
            function () use ($motor, $usuario, $folio): void {
                $motor->ejecutar(
                    new ComandoTransicionOperacional(
                        dominio: DominioTransicionOperacional::Estiba,
                        tipo: 'retiro',
                        usuario: $usuario,
                        payload: ['folio_id' => $folio->id],
                    ),
                    fn (): bool => $folio->update([
                        'estado_operacional' => EstadoOperacionalFolio::PendienteUbicacion,
                    ]),
                );
            },
        );

        $padre = TransicionOperacional::query()
            ->where('dominio', DominioTransicionOperacional::Despacho->value)
            ->firstOrFail();
        $hija = TransicionOperacional::query()
            ->where('dominio', DominioTransicionOperacional::Estiba->value)
            ->firstOrFail();

        $this->assertSame(0, $padre->cantidad_cambios);
        $this->assertSame(1, $hija->cantidad_cambios);
        $this->assertSame(
            $hija->id,
            CambioTransicionOperacional::query()->sole()->transicion_operacional_id,
        );
    }

    public function test_un_uuid_aplicado_no_crea_una_segunda_transicion(): void
    {
        $usuario = $this->usuario();
        $folio = $this->folio('PAL-MOTOR-004');
        $operacionId = (string) Str::uuid();
        $comando = new ComandoTransicionOperacional(
            dominio: DominioTransicionOperacional::Validacion,
            tipo: 'pallet.registrar',
            usuario: $usuario,
            payload: ['folio_id' => $folio->id],
            operacionId: $operacionId,
        );

        $this->motor()->ejecutar($comando, fn (): Folio => $folio->refresh());
        $repetida = $this->motor()->ejecutar($comando, fn (): Folio => $folio->refresh());

        $this->assertSame($folio->id, $repetida->id);
        $this->assertSame(
            1,
            TransicionOperacional::query()->where('operacion_id', $operacionId)->count(),
        );
    }

    public function test_un_fallo_tecnico_revierte_el_dominio_y_conserva_el_diagnostico(): void
    {
        $usuario = $this->usuario();
        $folio = $this->folio('PAL-MOTOR-005');
        $operacionId = (string) Str::uuid();

        try {
            $this->motor()->ejecutar(
                new ComandoTransicionOperacional(
                    dominio: DominioTransicionOperacional::Despacho,
                    tipo: 'folio.cerrar',
                    usuario: $usuario,
                    payload: ['folio_id' => $folio->id],
                    operacionId: $operacionId,
                    sujetoTipo: Folio::class,
                    sujetoId: (string) $folio->id,
                ),
                function () use ($folio): never {
                    $folio->update([
                        'estado_operacional' => EstadoOperacionalFolio::Despachado,
                    ]);

                    throw new RuntimeException('El proveedor externo no respondió.');
                },
            );
            $this->fail('Se esperaba el fallo técnico de la transición.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'El proveedor externo no respondió.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            EstadoOperacionalFolio::PendientePrefrio,
            $folio->refresh()->estado_operacional,
        );
        $transicion = TransicionOperacional::query()
            ->where('operacion_id', $operacionId)
            ->firstOrFail();
        $this->assertSame(EstadoTransicionOperacional::Fallida, $transicion->estado);
        $this->assertSame('fallo_no_controlado', $transicion->error_codigo);
        $this->assertSame(0, $transicion->cantidad_cambios);
        $this->assertDatabaseCount('cambios_transiciones_operacionales', 0);
    }

    public function test_un_deadlock_historico_se_rechaza_sin_reemitirlo_como_excepcion_mysql(): void
    {
        $usuario = $this->usuario();
        $folio = $this->folio('PAL-DEADLOCK-HISTORICO');
        $uuid = (string) Str::uuid();
        $payload = ['folio_id' => $folio->id];
        $normalizador = app(NormalizadorTransicionOperacional::class);
        $fallida = TransicionOperacional::create(['dominio' => DominioTransicionOperacional::Estiba,
            'tipo' => 'ubicacion_inicial', 'operacion_id' => $uuid, 'estado' => EstadoTransicionOperacional::Fallida,
            'user_id' => $usuario->id, 'payload' => $payload, 'payload_hash' => $normalizador->hash($payload),
            'cantidad_cambios' => 0, 'error_tipo' => DeadlockException::class,
            'error_codigo' => 'fallo_no_controlado', 'error_mensaje' => 'SQLSTATE[40001]: Deadlock found when trying to get lock',
            'ocurrido_at' => now(), 'finalizado_at' => now()]);
        $anterior = $fallida->refresh()->getRawOriginal();
        try {
            DB::transaction(fn () => $this->motor()->ejecutar(
                new ComandoTransicionOperacional(dominio: DominioTransicionOperacional::Estiba,
                    tipo: 'ubicacion_inicial', usuario: $usuario, payload: $payload, operacionId: $uuid),
                fn () => $this->fail('Un UUID finalizado no debe volver a ejecutar el cambio.')));
            $this->fail('Debe informar que este intento falló.');
        } catch (ConflictoOperacion $error) {
            $this->assertStringContainsString('nuevo intento', $error->getMessage());
            $this->assertStringNotContainsString('Deadlock', $error->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $error->getMessage());
        }
        $this->assertSame($anterior, $fallida->refresh()->getRawOriginal());
        $this->assertDatabaseCount('transiciones_operacionales', 1);
        $this->assertDatabaseCount('cambios_transiciones_operacionales', 0);
    }

    private function motor(): MotorTransicionesOperacionales
    {
        return app(MotorTransicionesOperacionales::class);
    }

    private function usuario(): User
    {
        return User::factory()->create([
            'rol' => RolUsuario::Administrador,
            'activo' => true,
        ]);
    }

    private function folio(string $numero): Folio
    {
        return Folio::create([
            'numero_folio' => $numero,
            'tipo_bulto' => TipoBulto::Pallet,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'fecha_ingreso' => now(),
            'activo' => true,
            'origen_sistema' => 'prueba_motor',
            'datos_externos' => ['cantidad_cajas' => 1],
        ]);
    }
}
