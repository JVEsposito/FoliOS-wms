<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Carga;
use App\Models\Cliente;
use App\Models\Embarque;
use App\Models\Pais;
use App\Models\Puerto;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmbarqueApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendario_expone_las_24_horas_y_no_asigna_el_siguiente_cupo(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'MC');
        Temporada::query()->where('activa', true)->update(['intervalo_embarques_minutos' => 45]);

        $creado = $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-12',
            'hora_programada' => '08:15',
            'modalidad' => 'maritimo',
            'instructivos' => [
                ['numero_externo' => 'INS-001', 'cantidad_pallets' => 18],
                ['numero_externo' => 'INS-002', 'cantidad_pallets' => 6],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.codigo', 'EMC0000001')
            ->assertJsonPath('data.hora_programada', '08:15')
            ->assertJsonPath('data.totales.instructivos', 2)
            ->assertJsonPath('data.totales.pallets', 24);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/embarques?desde=2026-08-12&hasta=2026-08-12')
            ->assertOk()
            ->assertJsonCount(32, 'ventanas')
            ->assertJsonPath('ventanas.0.hora', '00:00')
            ->assertJsonPath('ventanas.31.hora', '23:15')
            ->assertJsonFragment([
                'hora' => '08:15',
                'disponible' => false,
                'ocupada_por' => ['EMC0000001'],
            ]);

        $this->assertDatabaseHas('embarques', [
            'id' => $creado->json('data.id'),
            'fecha_programada' => '2026-08-12',
            'intervalo_minutos' => 45,
        ]);
    }

    public function test_sobrecupo_exige_supervisor_y_conserva_motivo_auditado(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $supervisor = $this->usuario(RolUsuario::SupervisorFrio);
        $cliente = $this->cliente($despachador, 'AG');
        $payload = [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-13',
            'hora_programada' => '10:00',
            'modalidad' => 'aereo',
            'instructivos' => [[]],
        ];

        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', $payload)
            ->assertCreated();
        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', $payload)
            ->assertConflict()
            ->assertJsonPath('codigo', 'conflicto_operacional');
        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            ...$payload,
            'autorizar_sobrecupo' => true,
            'motivo_sobrecupo' => 'Solicitud prioritaria autorizada por operaciones.',
        ])->assertForbidden()
            ->assertJsonPath('codigo', 'operacion_no_autorizada');

        $autorizado = $this->actingAs($supervisor, 'sanctum')->postJson('/api/embarques', [
            ...$payload,
            'autorizar_sobrecupo' => true,
            'motivo_sobrecupo' => 'Solicitud prioritaria autorizada por operaciones.',
        ])->assertCreated()
            ->assertJsonPath('data.codigo', 'EAG0000002')
            ->assertJsonPath('data.sobrecupo.autorizado_por', $supervisor->name);

        $this->assertDatabaseHas('eventos_embarque', [
            'embarque_id' => $autorizado->json('data.id'),
            'tipo' => 'sobrecupo_autorizado',
        ]);
    }

    public function test_recibidores_habituales_se_recuperan_por_cliente_y_temporada_fuera_de_la_semana_consultada(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'RC');
        $otroCliente = $this->cliente($despachador, 'OT');
        $payload = [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-01',
            'hora_programada' => '09:00',
            'modalidad' => 'maritimo',
            'instructivos' => [
                ['recibidor' => '  Frutas   Ñandú  '],
                ['recibidor' => 'frutas ñandú'],
                ['recibidor' => ''],
                ['recibidor' => null],
            ],
        ];
        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', $payload)->assertCreated();
        $this->postJson('/api/embarques', [
            ...$payload, 'cliente_id' => $otroCliente->id, 'hora_programada' => '10:00',
            'instructivos' => [['recibidor' => 'Recibidor otro cliente']],
        ])->assertCreated();

        $anterior = $this->postJson('/api/embarques', [
            ...$payload, 'hora_programada' => '11:00',
            'instructivos' => [['recibidor' => 'Recibidor otra temporada']],
        ])->assertCreated();
        $temporadaAnterior = Temporada::query()->create([
            ...$this->vigenciaProductiva(),
            'codigo' => 'REC-ANT', 'nombre' => 'Temporada anterior', 'activa' => false,
        ]);
        Embarque::query()->findOrFail($anterior->json('data.id'))->update(['temporada_id' => $temporadaAnterior->id]);

        $url = '/api/embarques?desde=2026-08-31&hasta=2026-08-31';
        $calendario = $this->getJson($url)->assertOk()->assertJsonCount(0, 'embarques')
            ->assertJsonCount(2, 'catalogos.recibidores');
        $habituales = collect($calendario->json('catalogos.recibidores'));
        $this->assertSame('frutas ñandú', mb_strtolower($habituales->firstWhere('cliente_id', $cliente->id)['nombre']));
        $this->assertSame('Recibidor otro cliente', $habituales->firstWhere('cliente_id', $otroCliente->id)['nombre']);

        $this->postJson('/api/embarques', [
            ...$payload, 'hora_programada' => '12:00',
            'instructivos' => [['recibidor' => 'Recibidor nuevo']],
        ])->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'catalogos.recibidores')
            ->assertJsonFragment(['cliente_id' => $cliente->id, 'nombre' => 'Recibidor nuevo']);
    }

    public function test_confirmar_un_embarque_crea_una_sola_orden_car_para_todos_sus_instructivos(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'GE');
        $embarqueId = $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-14',
            'hora_programada' => '18:00',
            'modalidad' => 'maritimo',
            'instructivos' => [
                ['numero_externo' => 'A', 'cantidad_pallets' => 20],
                ['numero_externo' => 'B', 'cantidad_pallets' => 4],
            ],
        ])->assertCreated()->json('data.id');

        $confirmado = $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/embarques/{$embarqueId}/confirmar", [
                'version_esperada' => 1,
                'prioridad' => 'alta',
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmado')
            ->assertJsonPath('data.carga.codigo', 'CAR-000001')
            ->assertJsonPath('data.carga.estado', 'borrador')
            ->assertJsonPath('data.totales.instructivos', 2);

        $this->assertDatabaseHas('cargas', [
            'codigo' => 'CAR-000001',
            'numero_orden_externa' => null,
        ]);
        $this->assertDatabaseCount('cargas', 1);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/'.$confirmado->json('data.carga.id'))
            ->assertOk()
            ->assertJsonPath('data.codigo', 'CAR-000001')
            ->assertJsonPath('data.embarque.codigo', 'EGE0000001')
            ->assertJsonPath('data.embarque.numeros_externos.0', 'A')
            ->assertJsonPath('data.embarque.numeros_externos.1', 'B');

        Carga::query()->findOrFail($confirmado->json('data.carga.id'))->update([
            'estado' => 'pendiente',
            'publicada_por_user_id' => $despachador->id,
            'publicada_at' => now(),
        ]);

        $bandeja = $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/cargas/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.embarque.codigo', 'EGE0000001')
            ->assertJsonPath('data.0.embarque.fecha_programada', '2026-08-14')
            ->assertJsonPath('data.0.embarque.hora_programada', '18:00')
            ->assertJsonPath('data.0.embarque.numeros_externos.0', 'A')
            ->assertJsonPath('data.0.embarque.numeros_externos.1', 'B');

        $etag = $bandeja->headers->get('ETag');
        Embarque::query()->findOrFail($embarqueId)->update(['hora_programada' => '19:30:00']);

        $this->actingAs($despachador, 'sanctum')
            ->withHeader('If-None-Match', $etag)
            ->getJson('/api/cargas/pendientes')
            ->assertOk()
            ->assertJsonPath('data.0.embarque.hora_programada', '19:30');
    }

    public function test_paises_y_puertos_se_seleccionan_desde_catalogo_relacionado(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'MC');
        $chile = Pais::query()->where('iso_alpha2', 'CL')->firstOrFail();
        $mexico = Pais::query()->where('iso_alpha2', 'MX')->firstOrFail();
        $sanAntonio = Puerto::query()->where('codigo', 'CLSAI')->firstOrFail();
        $manzanillo = Puerto::query()->where('codigo', 'MXZLO')->firstOrFail();

        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-15',
            'hora_programada' => '09:00',
            'modalidad' => 'maritimo',
            'puerto_embarque_id' => $sanAntonio->id,
            'instructivos' => [[
                'numero_externo' => '0035',
                'pais_destino_id' => $mexico->id,
                'puerto_destino_id' => $manzanillo->id,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.puerto_embarque', 'San Antonio')
            ->assertJsonPath('data.puerto_embarque_id', $sanAntonio->id)
            ->assertJsonPath('data.instructivos.0.destino_pais', 'México')
            ->assertJsonPath('data.instructivos.0.destino_ciudad', 'Manzanillo')
            ->assertJsonPath('data.instructivos.0.pais_destino_id', $mexico->id)
            ->assertJsonPath('data.instructivos.0.puerto_destino_id', $manzanillo->id);

        $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/embarques?desde=2026-08-15&hasta=2026-08-15')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $chile->id,
                'iso_alpha2' => 'CL',
                'nombre_es' => 'Chile',
            ])
            ->assertJsonFragment([
                'id' => $manzanillo->id,
                'pais_id' => $mexico->id,
                'codigo' => 'MXZLO',
                'nombre' => 'Manzanillo',
                'tipo' => 'maritimo',
            ]);
    }

    public function test_catalogo_ampliado_ofrece_puertos_aeropuertos_pasos_y_todos_los_paises(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'MC');
        $argentina = Pais::query()->where('iso_alpha2', 'AR')->firstOrFail();
        $libertadores = Puerto::query()->where('codigo', 'PAS-LLI')->firstOrFail();
        $cristoRedentor = Puerto::query()->where('codigo', 'PAS-AR-CRE')->firstOrFail();
        $ampliacion = json_decode(file_get_contents(database_path('data/puertos_embarque_ampliacion.json')), true);

        // Cada código de la ampliación quedó una sola vez, con su país y tipo.
        $this->assertSame(110 + count($ampliacion), Puerto::query()->count());
        foreach (['CNXMG' => ['CN', 'maritimo'], 'FRPOV' => ['FR', 'maritimo'], 'AIR-NLU' => ['MX', 'aereo'], 'PAS-JAM' => ['CL', 'terrestre']] as $codigo => [$iso, $tipo]) {
            $punto = Puerto::query()->with('pais')->where('codigo', $codigo)->firstOrFail();
            $this->assertSame([$iso, $tipo], [$punto->pais->iso_alpha2, $punto->tipo]);
        }
        $this->assertSame('Los Libertadores (Los Andes – Mendoza)', $libertadores->nombre);

        // Volver a ejecutar la migración no duplica ni pisa los puntos.
        (require database_path('migrations/2026_11_17_130000_ampliar_puntos_transporte_embarques.php'))->up();
        $this->assertSame(110 + count($ampliacion), Puerto::query()->count());

        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-16',
            'hora_programada' => '07:00',
            'modalidad' => 'terrestre',
            'puerto_embarque_id' => $libertadores->id,
            'instructivos' => [['pais_destino_id' => $argentina->id, 'puerto_destino_id' => $cristoRedentor->id]],
        ])->assertCreated()
            ->assertJsonPath('data.puerto_embarque', 'Los Libertadores (Los Andes – Mendoza)')
            ->assertJsonPath('data.instructivos.0.destino_ciudad', 'Cristo Redentor (Mendoza – Los Andes)');

        $calendario = $this->actingAs($despachador, 'sanctum')
            ->getJson('/api/embarques?desde=2026-08-16&hasta=2026-08-16')
            ->assertOk();
        // Un país sin puntos catalogados también se puede declarar como destino.
        $this->assertCount(Pais::query()->where('activo', true)->count(), $calendario->json('catalogos.paises'));
        $this->assertContains('MN', array_column($calendario->json('catalogos.paises'), 'iso_alpha2'));
        $this->assertCount(110 + count($ampliacion), $calendario->json('catalogos.puertos'));
        $this->assertArrayNotHasKey('pais', $calendario->json('catalogos.puertos.0'));
    }

    public function test_puerto_de_destino_debe_pertenecer_al_pais_seleccionado(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'MC');
        $mexico = Pais::query()->where('iso_alpha2', 'MX')->firstOrFail();
        $rotterdam = Puerto::query()->where('codigo', 'NLRTM')->firstOrFail();

        $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-15',
            'hora_programada' => '10:00',
            'modalidad' => 'maritimo',
            'instructivos' => [[
                'pais_destino_id' => $mexico->id,
                'puerto_destino_id' => $rotterdam->id,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('instructivos.0.puerto_destino_id');
    }

    public function test_intervalo_existente_no_cambia_al_editar_la_temporada_sin_el_parametro(): void
    {
        $administrador = $this->usuario(RolUsuario::Administrador);
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $temporada->update(['intervalo_embarques_minutos' => 30]);

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/temporadas/{$temporada->id}", [
                // Editar una temporada productiva exige su vigencia y su prefijo.
                ...$this->vigenciaProductiva(),
                'codigo' => $temporada->codigo,
                'nombre' => $temporada->nombre,
                'activa' => true,
            ])->assertOk()
            ->assertJsonPath('data.intervalo_embarques_minutos', 30);
    }

    public function test_no_confirma_un_embarque_de_temporada_inactiva_ni_crea_su_carga(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'GT');
        $embarqueId = $this->actingAs($despachador, 'sanctum')->postJson('/api/embarques', [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-14',
            'hora_programada' => '10:00',
            'modalidad' => 'maritimo',
            'instructivos' => [['numero_externo' => 'T-1', 'cantidad_pallets' => 20]],
        ])->assertCreated()->json('data.id');

        app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => 'EMB-NUEVA',
            'nombre' => 'Temporada nueva de embarques',
            'activa' => true,
        ], usuarioId: $despachador->id);

        $this->actingAs($despachador, 'sanctum')
            ->postJson("/api/embarques/{$embarqueId}/confirmar", ['version_esperada' => 1])
            ->assertConflict()
            ->assertJsonPath('codigo', 'temporada_no_activa');

        $this->assertDatabaseHas('embarques', ['id' => $embarqueId, 'estado' => 'tentativo', 'carga_id' => null]);
        $this->assertSame(0, Carga::query()->count());
    }


    public function test_calendario_es_solo_lectura_para_cargas_en_operacion_o_finalizadas_incluso_para_admin(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $administrador = $this->usuario(RolUsuario::Administrador);
        $cliente = $this->cliente($despachador, 'CR');
        $datos = [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-20',
            'hora_programada' => '09:00',
            'modalidad' => 'maritimo',
            'instructivos' => [['numero_externo' => 'INS-CR', 'cantidad_pallets' => 2]],
        ];

        $creado = $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/embarques', $datos)
            ->assertCreated()
            ->assertJsonPath('data.editable_calendario', true);
        $id = $creado->json('data.id');

        $confirmado = $this->postJson("/api/embarques/{$id}/confirmar", [
            'version_esperada' => $creado->json('data.version'),
        ])->assertOk()
            ->assertJsonPath('data.editable_calendario', true)
            ->assertJsonPath('data.carga.estado', 'borrador');

        $cargaId = $confirmado->json('data.carga.id');
        $edicion = [...$datos, 'version_esperada' => $confirmado->json('data.version'), 'observacion' => 'Edición permitida antes del despacho'];
        $this->putJson("/api/embarques/{$id}", $edicion)
            ->assertOk()
            ->assertJsonPath('data.editable_calendario', true);

        DB::table('cargas')->where('id', $cargaId)->update(['estado' => 'pendiente']);
        $this->getJson("/api/embarques/{$id}")
            ->assertOk()
            ->assertJsonPath('data.editable_calendario', true);

        $version = Embarque::findOrFail($id)->version;
        $intento = [...$datos, 'version_esperada' => $version, 'observacion' => 'No debe modificar un despacho'];
        foreach ([
            'en_preparacion', 'despacho_parcial', 'en_separacion',
            'separada', 'separacion_completa', 'despachada', 'cerrada',
            'cancelada', 'retornada', 'retorno_parcial',
        ] as $estado) {
            DB::table('cargas')->where('id', $cargaId)->update(['estado' => $estado]);

            $this->actingAs($despachador, 'sanctum')
                ->getJson("/api/embarques/{$id}")
                ->assertOk()
                ->assertJsonPath('data.editable_calendario', false)
                ->assertJsonPath('data.carga.estado', $estado);
            $this->getJson('/api/embarques?desde=2026-08-20&hasta=2026-08-20')
                ->assertOk()
                ->assertJsonPath('embarques.0.editable_calendario', false);

            foreach ([$despachador, $administrador] as $usuario) {
                $this->actingAs($usuario, 'sanctum')
                    ->putJson("/api/embarques/{$id}", $intento)
                    ->assertUnprocessable()
                    ->assertJsonPath('codigo', 'regla_de_negocio');
            }
            $this->assertDatabaseHas('embarques', [
                'id' => $id,
                'version' => $version,
                'observacion' => 'Edición permitida antes del despacho',
            ]);
        }
    }

    public function test_embarque_cancelado_queda_visible_pero_no_editable(): void
    {
        $despachador = $this->usuario(RolUsuario::Despachador);
        $cliente = $this->cliente($despachador, 'CN');
        $datos = [
            'cliente_id' => $cliente->id,
            'fecha_programada' => '2026-08-21',
            'hora_programada' => '10:00',
            'modalidad' => 'maritimo',
            'instructivos' => [['numero_externo' => 'INS-CN']],
        ];
        $creado = $this->actingAs($despachador, 'sanctum')
            ->postJson('/api/embarques', $datos)->assertCreated();
        $id = $creado->json('data.id');

        $cancelado = $this->postJson("/api/embarques/{$id}/cancelar", [
            'version_esperada' => $creado->json('data.version'),
            'motivo' => 'Anulado por decisión comercial.',
        ])->assertOk()->assertJsonPath('data.editable_calendario', false);

        $this->getJson("/api/embarques/{$id}")
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelado')
            ->assertJsonPath('data.editable_calendario', false);
        $this->putJson("/api/embarques/{$id}", [
            ...$datos,
            'version_esperada' => $cancelado->json('data.version'),
        ])->assertUnprocessable()->assertJsonPath('codigo', 'regla_de_negocio');
    }

    private function usuario(RolUsuario $rol): User
    {
        return User::factory()->create(['rol' => $rol, 'activo' => true]);
    }

    private function cliente(User $usuario, string $sigla): Cliente
    {
        return Cliente::query()->create([
            'codigo' => 'CLI-'.$sigla,
            'nombre' => 'Cliente '.$sigla,
            'codigo_folio_materiales' => $sigla,
            'activo' => true,
            'creado_por_user_id' => $usuario->id,
            'actualizado_por_user_id' => $usuario->id,
        ]);
    }
}
