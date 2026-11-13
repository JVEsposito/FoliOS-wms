<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Folio;
use App\Models\TunelPrefrio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorreccionProcesoPrefrioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_corrige_horas_y_composicion_sin_borrar_trazabilidad(): void
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $supervisor = User::factory()->create(['rol' => RolUsuario::SupervisorFrio]);
        $inicioBase = now()->toImmutable()->subHours(8)->startOfMinute();
        $tunelId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/prefrio/tuneles', [
                'nombre' => 'Túnel histórico',
                'capacidad_posiciones' => 20,
                'setpoint_habitual' => -1.5,
                'estado_tecnico' => 'operativo',
            ])
            ->assertCreated()
            ->json('data.id');
        $tunel = TunelPrefrio::query()->findOrFail($tunelId);
        $posiciones = $tunel->posiciones()->orderBy('numero')->take(2)->get();
        $folioOriginal = $this->folioPendiente('PAL-HIST-001');
        $folioAgregado = $this->folioPendiente('SAL-HIST-002', TipoBulto::Saldo);

        $proceso = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/prefrio/procesos', [
                'operacion_id' => (string) Str::uuid(),
                'tunel_prefrio_id' => $tunel->id,
                'setpoint' => -1.5,
                'duracion_objetivo_minutos' => 480,
                'formato_referencia' => 'Prueba histórica',
                'ocurrido_at' => $inicioBase->toAtomString(),
            ])
            ->assertCreated()
            ->json('data');
        $proceso = $this->accion($administrador, $proceso['id'], 'folios', [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 0,
            'folio_id' => $folioOriginal->id,
            'posicion_tunel_prefrio_id' => $posiciones[0]->id,
            'temperatura_inicial' => 8.5,
            'ocurrido_at' => $inicioBase->addMinutes(10)->toAtomString(),
        ]);
        $proceso = $this->accion($administrador, $proceso['id'], 'confirmar-armado', [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 1,
            'ocurrido_at' => $inicioBase->addMinutes(20)->toAtomString(),
        ]);
        $proceso = $this->accion($administrador, $proceso['id'], 'iniciar', [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 2,
            'ocurrido_at' => $inicioBase->addMinutes(30)->toAtomString(),
        ]);
        $proceso = $this->accion($administrador, $proceso['id'], 'eventos/inversion_registrada', [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 3,
            'observacion' => 'Inversión original.',
            'ocurrido_at' => $inicioBase->addHours(2)->toAtomString(),
        ]);
        $proceso = $this->accion($administrador, $proceso['id'], 'verificar', [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 4,
            'ocurrido_at' => $inicioBase->addHours(4)->toAtomString(),
            // POEMP R-41: el término del enfriado lleva la lectura final completa.
            'datos' => ['lectura_final' => ['ventilador' => ['izq_ex' => -0.4, 'izq_in' => -0.2, 'der_in' => -0.3, 'der_ex' => -0.5], 'medio' => ['izq_ex' => -0.1, 'izq_in' => 0.1, 'der_in' => 0.0, 'der_ex' => -0.2], 'puerta' => ['izq_ex' => 0.3, 'izq_in' => 0.5, 'der_in' => 0.4, 'der_ex' => 0.2]]],
        ]);
        $proceso = $this->accion($supervisor, $proceso['id'], 'aprobar', [
            'operacion_id' => (string) Str::uuid(),
            'version_conocida' => 5,
            'resultados' => [[
                'folio_id' => $folioOriginal->id,
                'temperatura_final' => -0.6,
            ]],
            'ocurrido_at' => $inicioBase->addHours(5)->toAtomString(),
        ]);

        $operacionId = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacionId,
            'version_conocida' => $proceso['version'],
            'motivo' => 'Corrección contra registro físico firmado.',
            'proceso' => [
                'setpoint' => -1.8,
                'duracion_objetivo_minutos' => 510,
                'formato_referencia' => 'Registro físico corregido',
                'observacion' => 'Corrección administrativa controlada.',
            ],
            'eventos' => collect($proceso['eventos'])
                ->map(function (array $evento) use ($inicioBase): array {
                    $momento = match ($evento['tipo']) {
                        'proceso_iniciado' => $inicioBase->addMinutes(45),
                        'inversion_registrada' => $inicioBase->addHours(2)->addMinutes(30),
                        default => $evento['ocurrido_at'],
                    };

                    return [
                        'id' => $evento['id'],
                        'ocurrido_at' => $momento instanceof \DateTimeInterface
                            ? $momento->format(DATE_ATOM)
                            : $momento,
                        'observacion' => $evento['tipo'] === 'inversion_registrada'
                            ? 'Inversión corregida.'
                            : $evento['observacion'],
                    ];
                })
                ->values()
                ->all(),
            'folios' => [[
                'id' => $proceso['folios'][0]['id'],
                'incluido' => false,
                'observacion' => 'Folio cargado por error en este túnel.',
            ]],
            'nuevo_folio' => [
                'numero_folio' => $folioAgregado->numero_folio,
                'posicion_tunel_prefrio_id' => $posiciones[1]->id,
                'cargado_at' => $inicioBase->addMinutes(15)->toAtomString(),
                'temperatura_inicial' => 7.9,
                'temperatura_final' => -0.4,
                'observacion' => 'Folio omitido en digitación original.',
            ],
        ];

        $corregido = $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/prefrio/procesos/{$proceso['id']}/corregir", $payload)
            ->assertOk()
            ->assertJsonPath('data.setpoint', -1.8)
            ->assertJsonPath('data.iniciado_at', $inicioBase->addMinutes(45)->toAtomString())
            ->json('data');

        $this->assertSame($proceso['version'] + 1, $corregido['version']);
        $this->assertDatabaseHas('procesos_prefrio_folios', [
            'id' => $proceso['folios'][0]['id'],
            'estado' => 'cancelado',
            'motivo_resultado' => 'correccion_administrativa',
        ]);
        $this->assertDatabaseHas('procesos_prefrio_folios', [
            'proceso_prefrio_id' => $proceso['id'],
            'folio_id' => $folioAgregado->id,
            'estado' => 'aprobado',
        ]);
        $this->assertDatabaseHas('folios', [
            'id' => $folioAgregado->id,
            'estado_operacional' => 'pendiente_ubicacion',
            'condicion_termica' => 'prefrio_aprobado',
            'habilitacion_almacenamiento' => 'habilitado',
            'fuente_habilitacion_almacenamiento' => 'prefrio_aprobado',
        ]);
        $this->assertDatabaseHas('historial_habilitaciones_almacenamiento', [
            'folio_id' => $folioAgregado->id,
            'estado_resultante' => 'habilitado',
            'condicion_termica' => 'prefrio_aprobado',
            'fuente' => 'prefrio_aprobado',
            'proceso_origen' => 'prefrio',
            'referencia_origen' => $proceso['id'],
            'user_id' => $administrador->id,
        ]);

        // Simula el dato heredado que ya fue corregido en el proceso, pero no en el folio.
        DB::table('folios')->where('id', $folioAgregado->id)->update([
            'estado_operacional' => 'pendiente_prefrio',
            'condicion_termica' => 'pendiente_prefrio',
            'habilitacion_almacenamiento' => 'no_habilitado',
            'fuente_habilitacion_almacenamiento' => null,
            'habilitado_almacenamiento_at' => null,
            'habilitado_almacenamiento_por_user_id' => null,
        ]);
        $migracion = require database_path(
            'migrations/2026_08_12_190000_sincronizar_folios_omitidos_prefrio.php',
        );
        $migracion->up();

        $this->assertDatabaseHas('folios', [
            'id' => $folioAgregado->id,
            'condicion_termica' => 'prefrio_aprobado',
            'habilitacion_almacenamiento' => 'habilitado',
            'fuente_habilitacion_almacenamiento' => 'prefrio_aprobado',
        ]);
        $this->assertDatabaseHas('historial_habilitaciones_almacenamiento', [
            'folio_id' => $folioAgregado->id,
            'proceso_origen' => 'prefrio',
            'referencia_origen' => $proceso['id'],
            'motivo' => 'Regularización automática de folio omitido en el registro de prefrío.',
        ]);
        $this->assertDatabaseHas('eventos_prefrio', [
            'proceso_prefrio_id' => $proceso['id'],
            'operacion_id' => $operacionId,
            'tipo' => 'correccion_administrativa',
            'user_id' => $administrador->id,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/prefrio/procesos/{$proceso['id']}/corregir", $payload)
            ->assertOk()
            ->assertJsonPath('data.version', $corregido['version']);

        $payload['operacion_id'] = (string) Str::uuid();
        $payload['version_conocida'] = $corregido['version'];
        $this->actingAs($supervisor, 'sanctum')
            ->putJson("/api/administracion/prefrio/procesos/{$proceso['id']}/corregir", $payload)
            ->assertForbidden();

        $folioCompatible = $this->folioPendiente('SAL-HIST-003', TipoBulto::Saldo);
        $folioCompatible->update([
            'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
            'fuente_habilitacion_almacenamiento' => 'prefrio_aprobado',
        ]);
        $folioAgregado->refresh();

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/validacion/repaletizajes', [
                'turno' => 'A',
                'operacion_id' => (string) Str::uuid(),
                'modalidad' => 'consolidacion',
                'tipo_resultado' => 'saldo',
                'estrategia_folio' => 'nuevo',
                'numero_folio_resultante' => 'SAL-HIST-REPA',
                'cantidad_objetivo' => 120,
                'origenes' => [
                    ['folio_id' => $folioAgregado->id, 'cantidad_aportada' => 10],
                    ['folio_id' => $folioCompatible->id, 'cantidad_aportada' => 10],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.folio_resultante.condicion_termica', 'prefrio_aprobado');
    }

    private function folioPendiente(
        string $numero,
        TipoBulto $tipo = TipoBulto::Pallet,
    ): Folio {
        return Folio::create([
            'numero_folio' => $numero,
            'tipo_bulto' => $tipo,
            'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
            'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
            'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado,
            'exportadora' => 'MACE',
            'marca' => 'MACE',
            'datos_externos' => [
                'especie' => 'KIWI',
                'cantidad_cajas' => 76,
                'csg' => '123225',
            ],
            'fecha_ingreso' => now(),
            'activo' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function accion(
        User $usuario,
        string $procesoId,
        string $accion,
        array $payload,
    ): array {
        return $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prefrio/procesos/{$procesoId}/{$accion}", $payload)
            ->assertOk()
            ->json('data');
    }
}
