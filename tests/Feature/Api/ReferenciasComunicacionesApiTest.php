<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoCarga;
use App\Enums\RolUsuario;
use App\Models\Carga;
use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\ProcesoPrefrio;
use App\Models\Temporada;
use App\Models\TunelPrefrio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreaFolioParaUbicar;
use Tests\TestCase;

/**
 * Citas en chat y correo (13-11-2026): folios, recepciones, cargas y demás operaciones con
 * «#», y menciones de personas con «@». Se cita sólo lo que el módulo permite ver.
 */
class ReferenciasComunicacionesApiTest extends TestCase
{
    use CreaFolioParaUbicar;
    use RefreshDatabase;

    private function usuario(string $nombre, RolUsuario $rol = RolUsuario::Administrador): User
    {
        return User::factory()->create(['name' => $nombre, 'rol' => $rol]);
    }

    private function global(User $usuario): int
    {
        return $this->actingAs($usuario, 'sanctum')->getJson('/api/comunicaciones/chat/conversaciones')->assertOk()->json('data.0.id');
    }

    private function enviar(int $conversacion, string $texto, ?string $operacion = null)
    {
        return $this->postJson("/api/comunicaciones/chat/conversaciones/{$conversacion}/mensajes", [
            'texto' => $texto, 'operacion_id' => $operacion ?? (string) Str::uuid(),
        ]);
    }

    private function carga(User $usuario, string $codigo): Carga
    {
        return Carga::create([
            'temporada_id' => Temporada::query()->where('activa', true)->value('id'), 'codigo' => $codigo,
            'numero_orden_externa' => 'OE-'.$codigo, 'estado' => EstadoCarga::Pendiente, 'prioridad' => 'normal',
            'creada_por_user_id' => $usuario->id, 'actualizada_por_user_id' => $usuario->id,
        ]);
    }

    private function prefrio(User $usuario, string $codigo): ProcesoPrefrio
    {
        $tunel = TunelPrefrio::create(['codigo' => 'T-'.$codigo, 'nombre' => 'Túnel '.$codigo, 'capacidad_posiciones' => 8, 'creado_por_user_id' => $usuario->id]);

        return ProcesoPrefrio::create([
            'temporada_id' => Temporada::query()->where('activa', true)->value('id'), 'codigo' => $codigo,
            'operacion_id' => (string) Str::uuid(), 'payload_hash' => hash('sha256', $codigo), 'tunel_prefrio_id' => $tunel->id,
            'estado' => 'borrador', 'setpoint' => -0.5, 'creado_por_user_id' => $usuario->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function recepcion(): array
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $operador = $this->usuario('Romana', RolUsuario::OperadorRomana);

        return $this->actingAs($operador, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => Cliente::firstOrCreate(['codigo' => 'FALCO'], ['nombre' => 'FALCO FRUIT', 'activo' => true])->id,
            'tipo_recepcion' => 'fruta_pesaje_envases', 'tipo_servicio' => 'proceso',
            'especie_validacion_id' => EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true])->id,
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 6, 'tara_unitaria' => 40]],
            'numero_guia_despacho' => '4821', 'patente_camion' => 'RTKL22', 'tipo_camion' => 'termo',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor',
        ])->assertCreated()->json('data');
    }

    public function test_cita_registros_y_menciona_personas_con_las_etiquetas_del_servidor(): void
    {
        $javier = $this->usuario('Javier Esposito', RolUsuario::SupervisorFrio);
        $recepcion = $this->recepcion();
        $lks = $this->usuario('L.K.S.');
        $carga = $this->carga($lks, 'CAR-2611-0007');
        $proceso = $this->prefrio($lks, 'PF-2026-000041');
        $folio = $this->folioParaUbicar('8802341');

        // Búsqueda: exacta en cualquier temporada y parcial en la activa; recepción por guía o patente.
        $this->actingAs($lks, 'sanctum')->getJson('/api/comunicaciones/referencias?buscar=CAR-2611')->assertOk()
            ->assertJsonPath('data.0.tipo', 'carga')->assertJsonPath('data.0.etiqueta', 'CAR-2611-0007')
            ->assertJsonPath('data.0.resumen', 'Pendiente · OE-CAR-2611-0007');
        $this->getJson('/api/comunicaciones/referencias?buscar=4821')->assertOk()
            ->assertJsonPath('data.0.tipo', 'recepcion')->assertJsonPath('data.0.etiqueta', $recepcion['numero_recepcion']);
        $this->getJson('/api/comunicaciones/referencias?buscar=%23RTKL')->assertOk()->assertJsonPath('data.0.id', $recepcion['id']);
        $this->getJson('/api/comunicaciones/referencias?buscar=8802341&tipo=folio')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/comunicaciones/referencias?buscar=%_')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/comunicaciones/referencias?buscar=x')->assertOk()->assertJsonCount(0, 'data');

        $global = $this->global($lks);
        $texto = "Revisen [[carga:{$carga->id}]] y [[recepcion:{$recepcion['id']}]] antes del turno, [[usuario:{$javier->id}]]. Ojo con [[proceso_prefrio:{$proceso->id}]] y [[folio:{$folio->id}]].";
        $operacion = (string) Str::uuid();
        $mensaje = $this->enviar($global, $texto, $operacion)->assertOk()
            ->assertJsonPath('data.texto', $texto)
            ->assertJsonPath('data.referencias.0', ['tipo' => 'carga', 'id' => $carga->id, 'etiqueta' => 'CAR-2611-0007', 'nombre_tipo' => 'Carga', 'soy_yo' => false])
            ->assertJsonPath('data.referencias.2.etiqueta', 'Javier Esposito')
            ->assertJsonPath('data.me_menciona', false)->json('data');
        $this->assertCount(5, $mensaje['referencias']);
        // Un reintento devuelve el mismo mensaje sin duplicar las citas.
        $this->enviar($global, $texto, $operacion)->assertOk()->assertJsonPath('data.id', $mensaje['id']);
        $this->assertDatabaseCount('com_referencias', 5);

        // El extracto de la conversación se lee con etiquetas, no con identificadores.
        $this->actingAs($javier, 'sanctum')->getJson('/api/comunicaciones/chat/conversaciones')->assertOk()
            ->assertJsonPath('data.0.extracto', 'Revisen #CAR-2611-0007 y #'.$recepcion['numero_recepcion'].' antes del turno, @Javier Esposito. Ojo con #PF-2026-000041 y')
            ->assertJsonPath('data.0.tiene_menciones', true);
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.menciones_sin_leer', 1);
        $this->getJson("/api/comunicaciones/chat/conversaciones/{$global}/mensajes")->assertOk()
            ->assertJsonPath('data.0.me_menciona', true)->assertJsonPath('data.0.referencias.2.soy_yo', true);
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/leer", ['hasta_id' => $mensaje['id']])->assertOk();
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.menciones_sin_leer', 0);
        $this->getJson('/api/comunicaciones/chat/conversaciones')->assertOk()->assertJsonPath('data.0.tiene_menciones', false);
    }

    public function test_solo_se_cita_lo_que_el_modulo_permite_y_registros_vigentes(): void
    {
        $lks = $this->usuario('L.K.S.');
        $carga = $this->carga($lks, 'CAR-2611-0100');
        $cliente = User::factory()->create(['name' => 'Cliente Portal', 'rol' => RolUsuario::Cliente]);
        $inactivo = User::factory()->create(['name' => 'Inactivo', 'rol' => RolUsuario::Consulta, 'activo' => false]);
        // Romana no consulta cargas ni prefrío: no los ve en la búsqueda ni puede citarlos.
        $romana = $this->usuario('Operador Romana', RolUsuario::OperadorRomana);
        $tipos = $this->actingAs($romana, 'sanctum')->getJson('/api/comunicaciones/referencias?buscar=CAR-2611')->assertOk()
            ->assertJsonCount(0, 'data')->json('tipos');
        $this->assertContains('recepcion', array_column($tipos, 'clave'));
        $this->assertNotContains('carga', array_column($tipos, 'clave'));
        $this->getJson('/api/comunicaciones/referencias?buscar=CAR-2611&tipo=carga')->assertUnprocessable();

        $global = $this->global($romana);
        $this->enviar($global, "Mira [[carga:{$carga->id}]]")->assertUnprocessable()->assertJsonPath('errors.texto.0', 'No tienes acceso para citar las cargas.');
        $this->enviar($global, '[[carga:'.Str::uuid().']]')->assertUnprocessable();
        $this->actingAs($lks, 'sanctum');
        $this->enviar($global, '[[carga:'.Str::uuid().']]')->assertUnprocessable()
            ->assertJsonPath('errors.texto.0', 'Carga citado ya no existe. Quita la cita y vuelve a buscarlo.');
        $this->enviar($global, '[[planilla:abc]]')->assertUnprocessable()->assertJsonPath('errors.texto.0', 'El mensaje tiene una cita que FoliOS no reconoce.');
        $this->enviar($global, "Hola [[usuario:{$cliente->id}]]")->assertUnprocessable();
        $this->enviar($global, "Hola [[usuario:{$inactivo->id}]]")->assertUnprocessable();
        $muchas = collect(range(1, 21))->map(fn () => $this->usuario('P'.Str::random(4)))->map(fn (User $u) => "[[usuario:{$u->id}]]")->implode(' ');
        $this->enviar($global, $muchas)->assertUnprocessable()->assertJsonPath('errors.texto.0', 'Puedes citar hasta 20 registros o personas por mensaje.');
        $this->assertDatabaseCount('com_mensajes', 0);
        $this->assertDatabaseCount('com_referencias', 0);

        // Texto con corchetes que no son citas se guarda tal cual.
        $this->enviar($global, 'Formato [[sin cita]] y [x]')->assertOk()->assertJsonPath('data.referencias', []);
    }

    public function test_la_ficha_usa_los_permisos_de_quien_la_abre(): void
    {
        $lks = $this->usuario('L.K.S.');
        $proceso = $this->prefrio($lks, 'PF-2026-000077');
        $this->actingAs($lks, 'sanctum')->getJson("/api/comunicaciones/referencias/proceso_prefrio/{$proceso->id}")->assertOk()
            ->assertJsonPath('data.etiqueta', 'PF-2026-000077')->assertJsonPath('data.modulo', 'Prefrío')
            ->assertJsonPath('data.enlace', "/oficina/prefrio?proceso={$proceso->id}")
            ->assertJsonPath('data.datos.0', ['etiqueta' => 'Túnel', 'valor' => 'T-PF-2026-000077'])
            ->assertJsonPath('data.datos.1', ['etiqueta' => 'Estado', 'valor' => 'Borrador']);
        $this->getJson('/api/comunicaciones/referencias/proceso_prefrio/'.Str::uuid())->assertNotFound()
            ->assertJsonPath('message', 'Prefrío citado ya no existe en FoliOS.');
        $this->getJson('/api/comunicaciones/referencias/planilla/1')->assertNotFound();

        $romana = $this->usuario('Operador Romana', RolUsuario::OperadorRomana);
        $this->actingAs($romana, 'sanctum')->getJson("/api/comunicaciones/referencias/proceso_prefrio/{$proceso->id}")->assertForbidden()
            ->assertJsonPath('message', 'No tienes acceso a los procesos de prefrío. Pide a quien lo citó más detalles.');
        $this->getJson("/api/comunicaciones/referencias/usuario/{$lks->id}")->assertOk()
            ->assertJsonPath('data.etiqueta', 'L.K.S.')->assertJsonPath('data.puede_privado', true);
        $this->getJson("/api/comunicaciones/referencias/usuario/{$romana->id}")->assertOk()->assertJsonPath('data.puede_privado', false);
        $cliente = User::factory()->create(['rol' => RolUsuario::Cliente]);
        $this->getJson("/api/comunicaciones/referencias/usuario/{$cliente->id}")->assertNotFound();
    }

    public function test_el_correo_cita_registros_y_se_encuentra_por_lo_citado(): void
    {
        $lks = $this->usuario('L.K.S.');
        $javier = $this->usuario('Javier Esposito', RolUsuario::SupervisorFrio);
        $luis = $this->usuario('Luis Admin', RolUsuario::Consulta);
        $carga = $this->carga($lks, 'CAR-2611-0042');
        $texto = "Confirmen [[carga:{$carga->id}]], [[usuario:{$javier->id}]].";
        $correo = $this->actingAs($lks, 'sanctum')->postJson('/api/comunicaciones/correos', [
            'destinatarios' => [$javier->id, $luis->id], 'asunto' => 'Despacho de hoy', 'texto' => $texto, 'operacion_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.texto', $texto)
            ->assertJsonPath('data.extracto', 'Confirmen #CAR-2611-0042, @Javier Esposito.')
            ->assertJsonPath('data.referencias.0.etiqueta', 'CAR-2611-0042')->json('data');

        $this->actingAs($javier, 'sanctum')->getJson('/api/comunicaciones/correos?buscar=%23CAR-2611-0042')->assertOk()
            ->assertJsonPath('data.0.id', $correo['id'])->assertJsonPath('data.0.me_menciona', true);
        $this->actingAs($luis, 'sanctum')->getJson("/api/comunicaciones/correos/{$correo['id']}")->assertOk()->assertJsonPath('data.me_menciona', false);
        $this->getJson('/api/comunicaciones/correos?buscar=Javier')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/comunicaciones/correos?buscar=CAR-9999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_los_limites_del_chat_no_consumen_los_de_otras_acciones(): void
    {
        $lks = $this->usuario('L.K.S.');
        $javier = $this->usuario('Javier Esposito');
        $global = $this->global($lks);
        foreach (range(1, 16) as $n) {
            $this->enviar($global, "Mensaje {$n}")->assertOk();
        }
        // Antes compartían el contador por usuario: 16 mensajes bloqueaban el correo (15/min).
        $this->postJson('/api/comunicaciones/correos', ['destinatarios' => [$javier->id], 'asunto' => 'Turno', 'texto' => 'Listo.', 'operacion_id' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/comunicaciones/chat/privados', ['usuario_id' => $javier->id])->assertOk();
    }
}
