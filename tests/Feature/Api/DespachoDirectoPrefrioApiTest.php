<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Anden;
use App\Models\Carga;
use App\Models\Folio;
use App\Models\PosicionTunelPrefrio;
use App\Models\ProcesoPrefrio;
use App\Models\ProcesoPrefrioFolio;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DespachoDirectoPrefrioApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registra_salida_directa_con_hora_real_sin_inventar_ubicaciones_ni_movimientos(): void
    {
        $usuario = User::factory()->create([
            'rol' => RolUsuario::Despachador,
            'activo' => true,
        ]);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $anden = Anden::create([
            'codigo' => 'AND-DIRECTO',
            'nombre' => 'Andén directo',
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
        $terminoPrefrio = now()->toImmutable()->subHours(5)->startOfMinute();
        [$primerFolio, $segundoFolio] = $this->foliosAprobados(
            $usuario,
            $temporada,
            $terminoPrefrio,
        );

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/cargas/folios-salida-directa-prefrio?per_page=25')
            ->assertOk()
            ->assertJsonFragment(['numero_folio' => $primerFolio->numero_folio])
            ->assertJsonFragment(['numero_folio' => $segundoFolio->numero_folio])
            ->assertJsonFragment(['finalizado_at' => $terminoPrefrio->toAtomString()]);

        $salidaFisica = $terminoPrefrio->addHours(2);
        $operacion = (string) Str::uuid();
        $payload = [
            'operacion_id' => $operacion,
            'folios' => [$primerFolio->numero_folio],
            'numero_orden_externa' => 'CAMION-REAL-01',
            'prioridad' => 'alta',
            'anden_id' => $anden->id,
            'patente' => 'ab-cd-12',
            'conductor' => 'María Pérez',
            'ocurrido_at' => $salidaFisica->toAtomString(),
            'observacion' => 'Camión despachado físicamente antes del registro.',
        ];

        $respuesta = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/cargas/despacho-directo-prefrio', $payload)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'cerrada')
            ->assertJsonPath('data.modalidad_salida', 'directa_prefrio')
            ->assertJsonPath('data.cierre.patente', 'AB-CD-12')
            ->assertJsonPath('data.cierre.cerrada_at', $salidaFisica->toAtomString())
            ->assertJsonPath('data.total_folios', 1)
            ->assertJsonPath('data.folios.0.numero_folio', $primerFolio->numero_folio)
            ->assertJsonPath('data.folios.0.ubicacion', null);
        $cargaId = $respuesta->json('data.id');

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/cargas/despacho-directo-prefrio', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $cargaId);

        $this->assertSame(1, Carga::query()->where('operacion_cierre_id', $operacion)->count());
        $this->assertDatabaseHas('cargas', [
            'id' => $cargaId,
            'estado' => 'cerrada',
            'modalidad_salida' => 'directa_prefrio',
            'cerrada_at' => $salidaFisica->format('Y-m-d H:i:s'),
        ]);
        $this->assertNotNull(Carga::query()->findOrFail($cargaId)->cierre_registrado_at);
        $this->assertDatabaseHas('carga_folios', [
            'carga_id' => $cargaId,
            'folio_id' => $primerFolio->id,
            'estado' => 'despachado',
            'anden_id' => $anden->id,
            'enviado_anden_at' => $salidaFisica->format('Y-m-d H:i:s'),
            'finalizado_at' => $salidaFisica->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseHas('eventos_carga', [
            'carga_id' => $cargaId,
            'folio_id' => $primerFolio->id,
            'tipo' => 'folio_despachado',
        ]);
        $this->assertDatabaseHas('folios', [
            'id' => $primerFolio->id,
            'estado_operacional' => EstadoOperacionalFolio::Despachado->value,
            'activo' => false,
        ]);
        $this->assertDatabaseMissing('ubicaciones_actuales', ['folio_id' => $primerFolio->id]);
        $this->assertDatabaseMissing('movimientos', ['folio_id' => $primerFolio->id]);
        $this->assertDatabaseMissing('reservas_carga_folio', ['folio_id' => $primerFolio->id]);
        $this->assertDatabaseHas('eventos_carga', [
            'carga_id' => $cargaId,
            'tipo' => 'despacho_directo_prefrio',
        ]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/cargas/despacho-directo-prefrio', [
                ...$payload,
                'operacion_id' => (string) Str::uuid(),
                'numero_orden_externa' => 'CAMION-REAL-02',
                'folios' => [$segundoFolio->numero_folio],
                'ocurrido_at' => $terminoPrefrio->subMinute()->toAtomString(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'folios_no_asignables')
            ->assertJsonPath('errores.0.codigo', 'salida_antes_prefrio');
    }

    /**
     * @return array{Folio, Folio}
     */
    private function foliosAprobados(
        User $usuario,
        Temporada $temporada,
        \DateTimeInterface $terminoPrefrio,
    ): array {
        $tunel = TunelPrefrio::create([
            'codigo' => 'TUN-DIRECTO',
            'nombre' => 'Túnel salida directa',
            'capacidad_posiciones' => 2,
            'setpoint_habitual' => -1.5,
            'estado_administrativo' => 'activo',
            'estado_tecnico' => 'operativo',
            'version_configuracion' => 1,
            'creado_por_user_id' => $usuario->id,
        ]);
        $posiciones = collect([1, 2])->map(fn (int $numero) => PosicionTunelPrefrio::create([
            'tunel_prefrio_id' => $tunel->id,
            'numero' => $numero,
            'etiqueta' => "TUN-DIRECTO-P{$numero}",
            'activa' => true,
        ]));
        $proceso = ProcesoPrefrio::create([
            'temporada_id' => $temporada->id,
            'codigo' => 'PFR-DIRECTO',
            'operacion_id' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'prefrio-directo'),
            'tunel_prefrio_id' => $tunel->id,
            'estado' => 'aprobado',
            'setpoint' => -1.5,
            'version' => 5,
            'creado_por_user_id' => $usuario->id,
            'iniciado_por_user_id' => $usuario->id,
            'finalizado_por_user_id' => $usuario->id,
            'iniciado_at' => now()->subHours(10),
            'pendiente_verificacion_at' => $terminoPrefrio,
            'finalizado_at' => $terminoPrefrio,
        ]);

        return collect([1, 2])->map(function (int $numero) use (
            $usuario,
            $temporada,
            $terminoPrefrio,
            $proceso,
            $posiciones,
        ): Folio {
            $folio = Folio::create([
                'temporada_id' => $temporada->id,
                'numero_folio' => "PAL-DIRECTO-00{$numero}",
                'tipo_bulto' => TipoBulto::Pallet,
                'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio,
                'condicion_termica' => CondicionTermicaFolio::PrefrioAprobado,
                'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::Habilitado,
                'habilitado_almacenamiento_at' => $terminoPrefrio,
                'habilitado_almacenamiento_por_user_id' => $usuario->id,
                'fecha_ingreso' => now()->subDay()->addMinutes($numero),
                'activo' => true,
                'variedad' => 'Hayward',
                'calibre' => '42',
                'marca' => 'MACE',
            ]);
            ProcesoPrefrioFolio::create([
                'proceso_prefrio_id' => $proceso->id,
                'folio_id' => $folio->id,
                'posicion_tunel_prefrio_id' => $posiciones[$numero - 1]->id,
                'estado' => 'aprobado',
                'temperatura_inicial' => 8.5,
                'temperatura_final' => -0.5,
                'cargado_at' => now()->subHours(10),
                'retirado_at' => $terminoPrefrio,
                'cargado_por_user_id' => $usuario->id,
                'retirado_por_user_id' => $usuario->id,
            ]);

            return $folio;
        })->all();
    }
}
