<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ConversacionChat;
use App\Models\MensajeChat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ComunicacionesApiTest extends TestCase
{
    use RefreshDatabase;

    private function persona(string $nombre = 'Persona'): User
    {
        return User::factory()->create(['name' => $nombre, 'rol' => RolUsuario::Consulta]);
    }

    private function global(User $usuario): int
    {
        return $this->actingAs($usuario, 'sanctum')->getJson('/api/comunicaciones/chat/conversaciones')
            ->assertOk()->json('data.0.id');
    }

    private function privado(User $a, User $b): int
    {
        return $this->actingAs($a, 'sanctum')->postJson('/api/comunicaciones/chat/privados', ['usuario_id' => $b->id])
            ->assertOk()->json('data.id');
    }

    private function texto(int $conversacion, string $texto = 'Hola', ?string $operacion = null)
    {
        return $this->postJson("/api/comunicaciones/chat/conversaciones/{$conversacion}/mensajes", [
            'texto' => $texto, 'operacion_id' => $operacion ?? (string) Str::uuid(),
        ]);
    }

    private function correo(User $de, array $para, array $extra = [])
    {
        return $this->actingAs($de, 'sanctum')->postJson('/api/comunicaciones/correos', [
            'destinatarios' => array_map(fn ($u) => $u->id, $para), 'asunto' => 'Cambio de turno',
            'texto' => 'Favor confirmar recepción.', 'operacion_id' => (string) Str::uuid(), ...$extra,
        ]);
    }

    public function test_requiere_sesion_y_no_admite_cuentas_de_clientes_o_inactivas(): void
    {
        $this->getJson('/api/comunicaciones/resumen')->assertUnauthorized();
        $cliente = User::factory()->create(['rol' => RolUsuario::Cliente]);
        $this->actingAs($cliente, 'sanctum');
        foreach (['resumen', 'usuarios', 'stickers', 'chat/conversaciones', 'chat/conversaciones/1/mensajes', 'correos'] as $ruta) {
            $this->getJson('/api/comunicaciones/'.$ruta)->assertForbidden();
        }
        $this->postJson('/api/comunicaciones/chat/privados', ['usuario_id' => 1])->assertForbidden();
        $inactivo = $this->persona();
        $token = $inactivo->createToken('prueba', ['oficina'])->plainTextToken;
        $inactivo->update(['activo' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/comunicaciones/resumen')->assertUnauthorized();
    }

    public function test_directorio_excluye_clientes_inactivos_y_la_cuenta_propia_y_no_filtra_secretos(): void
    {
        $yo = $this->persona('Actual');
        $otra = $this->persona('Sharon B');
        User::factory()->create(['name' => 'Externo', 'rol' => RolUsuario::Cliente]);
        User::factory()->create(['name' => 'Inactivo', 'activo' => false]);
        $this->actingAs($yo, 'sanctum')->getJson('/api/comunicaciones/usuarios?buscar=Sharon')->assertOk()
            ->assertExactJson(['data' => [['id' => $otra->id, 'nombre' => 'Sharon B']], 'pagina' => 1, 'ultima_pagina' => 1]);
        $this->getJson('/api/comunicaciones/usuarios')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_el_chat_global_tiene_cursores_y_reintentos_sin_duplicar_el_envio(): void
    {
        $a = $this->persona('A');
        $b = $this->persona('B');
        $global = $this->global($a);
        $operacion = (string) Str::uuid();
        $primero = $this->texto($global, '0', $operacion)->assertOk()->json('data.id');
        $this->texto($global, '0', $operacion)->assertOk()->assertJsonPath('data.id', $primero);
        $this->assertDatabaseCount('com_mensajes', 1);
        $this->texto($global, 'Otro contenido', $operacion)->assertConflict();
        $this->texto($global, '   ')->assertUnprocessable();
        $segundo = $this->texto($global, '<img src=x onerror=alert(1)>')->assertOk()->json('data.id');
        $this->actingAs($b, 'sanctum')->getJson("/api/comunicaciones/chat/conversaciones/{$global}/mensajes?despues_id={$primero}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $segundo)
            ->assertJsonPath('data.0.texto', '<img src=x onerror=alert(1)>')->assertJsonPath('data.0.operacion_id', null);
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.chat_no_leido', true);
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/leer", ['hasta_id' => $primero])->assertOk();
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.chat_no_leido', true);
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/leer", ['hasta_id' => $segundo])->assertOk();
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/leer", ['hasta_id' => $primero])->assertOk();
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.chat_no_leido', false);
        $this->assertDatabaseHas('com_participantes', ['user_id' => $b->id, 'conversacion_id' => $global, 'ultimo_leido_id' => $segundo]);
    }

    public function test_los_privados_son_exclusivos_de_sus_dos_participantes_incluso_frente_al_admin(): void
    {
        $a = $this->persona('A');
        $b = $this->persona('B');
        $c = $this->persona('C');
        $privado = $this->privado($a, $b);
        $this->assertSame($privado, $this->privado($b, $a));
        $this->texto($privado, 'Solo para A y B')->assertOk();
        foreach ([$c, User::factory()->create(['rol' => RolUsuario::Administrador])] as $ajeno) {
            $this->actingAs($ajeno, 'sanctum')->getJson("/api/comunicaciones/chat/conversaciones/{$privado}/mensajes")->assertNotFound();
            $this->texto($privado, 'Intrusión')->assertNotFound();
            $this->postJson("/api/comunicaciones/chat/conversaciones/{$privado}/leer", ['hasta_id' => 0])->assertNotFound();
            $this->getJson('/api/comunicaciones/chat/conversaciones')->assertOk()->assertJsonCount(1, 'data');
            $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.chat_no_leido', false);
        }
        $global = $this->global($a);
        $idPrivado = MensajeChat::where('conversacion_id', $privado)->value('id');
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/leer", ['hasta_id' => $idPrivado])->assertUnprocessable();
        $b->update(['activo' => false]);
        $this->getJson("/api/comunicaciones/chat/conversaciones/{$privado}/mensajes")->assertOk()->assertJsonPath('conversacion.puede_enviar', false);
        $this->texto($privado, 'Nuevo mensaje')->assertUnprocessable();
        $this->postJson('/api/comunicaciones/chat/privados', ['usuario_id' => $a->id])->assertUnprocessable();
    }

    public function test_pagina_el_historial_y_los_mensajes_nuevos_sin_saltar_registros(): void
    {
        $usuario = $this->persona();
        $global = $this->global($usuario);
        $filas = [];
        foreach (range(1, 70) as $i) {
            $filas[] = ['conversacion_id' => $global, 'remitente_user_id' => $usuario->id,
                'operacion_id' => (string) Str::uuid(), 'texto' => 'Mensaje '.$i, 'created_at' => now(), 'updated_at' => now()];
        }
        MensajeChat::insert($filas);
        $ids = MensajeChat::where('conversacion_id', $global)->orderBy('id')->pluck('id')->all();
        ConversacionChat::whereKey($global)->update(['ultimo_mensaje_id' => end($ids)]);
        $base = "/api/comunicaciones/chat/conversaciones/{$global}/mensajes";
        $this->getJson($base.'?limite=20')->assertOk()->assertJsonPath('hay_mas_anteriores', true)
            ->assertJsonPath('data.0.id', $ids[50])->assertJsonPath('data.19.id', $ids[69]);
        $this->getJson($base.'?limite=20&antes_id='.$ids[50])->assertOk()
            ->assertJsonPath('data.0.id', $ids[30])->assertJsonPath('data.19.id', $ids[49]);
        $this->getJson($base.'?limite=20&despues_id='.$ids[49])->assertOk()->assertJsonPath('hay_mas_nuevos', false)->assertJsonCount(20, 'data');
        $this->getJson($base.'?antes_id=2&despues_id=1')->assertUnprocessable();
        $this->getJson($base.'?limite=1000')->assertUnprocessable();
    }

    public function test_stickers_compartidos_con_archivos_privados_reintentos_y_ocultacion_sin_perder_historia(): void
    {
        Storage::fake('local');
        $a = $this->persona('A');
        $b = $this->persona('B');
        $global = $this->global($a);
        $archivo = UploadedFile::fake()->image('feliz.gif', 128, 128);
        $datos = ['nombre' => 'Feliz', 'archivo' => $archivo, 'operacion_id' => (string) Str::uuid()];
        $subida = $this->postJson('/api/comunicaciones/stickers', $datos)->assertOk();
        $id = $subida->json('data.id');
        $this->postJson('/api/comunicaciones/stickers', $datos)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $op = (string) Str::uuid();
        $mensaje = ['sticker_id' => $id, 'operacion_id' => $op];
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/mensajes", $mensaje)->assertOk();
        $url = $subida->json('data.archivo_url');
        $this->actingAs($b, 'sanctum')->getJson('/api/comunicaciones/stickers')->assertOk()->assertJsonPath('data.0.puede_ocultar', false);
        $this->getJson($url)->assertOk()->assertHeader('Content-Type', 'image/gif')->assertHeader('Cache-Control', 'no-store, private');
        $this->deleteJson('/api/comunicaciones/stickers/'.$id)->assertNotFound();
        $this->actingAs($a, 'sanctum')->deleteJson('/api/comunicaciones/stickers/'.$id)->assertOk();
        $this->getJson('/api/comunicaciones/stickers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/mensajes", $mensaje)->assertOk();
        $this->postJson("/api/comunicaciones/chat/conversaciones/{$global}/mensajes", [...$mensaje, 'operacion_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson("/api/comunicaciones/chat/conversaciones/{$global}/mensajes")->assertOk()->assertJsonPath('data.0.sticker.id', $id);
        $this->getJson($url)->assertOk();
    }

    public function test_stickers_rechazan_svg_formatos_y_tamanos_invalidos_sin_guardar_archivos(): void
    {
        Storage::fake('local');
        $this->actingAs($this->persona(), 'sanctum');
        foreach ([UploadedFile::fake()->createWithContent('malo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->image('foto.jpg'), UploadedFile::fake()->image('ancho.png', 1025, 2),
            UploadedFile::fake()->image('pesado.png')->size(2049)] as $archivo) {
            $this->postJson('/api/comunicaciones/stickers', ['nombre' => 'Prueba', 'archivo' => $archivo, 'operacion_id' => (string) Str::uuid()])
                ->assertUnprocessable()->assertJsonValidationErrors('archivo');
        }
        $this->assertDatabaseCount('com_stickers', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_correo_privado_con_lectura_y_archivo_independientes_por_destinatario(): void
    {
        $a = $this->persona('A');
        $b = $this->persona('B');
        $c = $this->persona('C');
        $id = $this->correo($a, [$b, $c])->assertOk()->json('data.id');
        $this->getJson('/api/comunicaciones/correos?bandeja=enviados')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/comunicaciones/correos/{$id}/leer", ['leido' => true])->assertNotFound();
        $this->actingAs($b, 'sanctum')->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.correos_no_leidos', 1);
        $this->getJson("/api/comunicaciones/correos/{$id}")->assertOk()->assertJsonPath('data.texto', 'Favor confirmar recepción.');
        // Consultar no marca una lectura antes de que la interfaz muestre el correo.
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.correos_no_leidos', 1);
        $this->postJson("/api/comunicaciones/correos/{$id}/leer", ['leido' => true])->assertOk();
        $this->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.correos_no_leidos', 0);
        $this->actingAs($c, 'sanctum')->getJson('/api/comunicaciones/resumen')->assertOk()->assertJsonPath('data.correos_no_leidos', 1);
        $this->postJson("/api/comunicaciones/correos/{$id}/archivar", ['archivado' => true])->assertOk();
        $this->getJson('/api/comunicaciones/correos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/comunicaciones/correos?bandeja=archivados')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/comunicaciones/correos/{$id}/archivar", ['archivado' => false])->assertOk();
        $this->actingAs($b, 'sanctum')->getJson('/api/comunicaciones/correos')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.leido', true);
        foreach ([$this->persona('Ajeno'), User::factory()->create(['rol' => RolUsuario::Administrador])] as $ajeno) {
            $this->actingAs($ajeno, 'sanctum')->getJson("/api/comunicaciones/correos/{$id}")->assertNotFound();
            $this->postJson("/api/comunicaciones/correos/{$id}/leer", ['leido' => true])->assertNotFound();
            $this->postJson("/api/comunicaciones/correos/{$id}/archivar", ['archivado' => true])->assertNotFound();
        }
    }

    public function test_correo_reintenta_una_sola_entrega_y_responde_solo_a_correos_visibles(): void
    {
        $a = $this->persona('A');
        $b = $this->persona('B');
        $c = $this->persona('C');
        $operacion = (string) Str::uuid();
        $id = $this->correo($a, [$b, $c], ['operacion_id' => $operacion])->assertOk()->json('data.id');
        $this->correo($a, [$c, $b], ['operacion_id' => $operacion])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('com_correos', 1);
        $this->assertDatabaseCount('com_destinatarios', 2);
        $this->correo($a, [$b], ['operacion_id' => $operacion])->assertConflict();
        $this->correo($b, [$a], ['asunto' => 'Re: Cambio de turno', 'respuesta_a_id' => $id])->assertOk()->assertJsonPath('data.respuesta_a_id', $id);
        $d = $this->persona('D');
        $this->correo($d, [$a], ['respuesta_a_id' => $id])->assertNotFound();
        $this->assertDatabaseCount('com_correos', 2);
    }

    public function test_la_lista_de_privados_no_agrega_consultas_por_cada_conversacion(): void
    {
        $a = $this->persona('Actual');
        $this->privado($a, $this->persona('Primera'));
        $this->getJson('/api/comunicaciones/chat/conversaciones')->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/comunicaciones/chat/conversaciones')->assertOk();
        $pocas = count(DB::getQueryLog());
        DB::disableQueryLog();
        foreach (range(1, 10) as $i) {
            $this->privado($a, $this->persona('Persona '.$i));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/comunicaciones/chat/conversaciones')->assertOk()->assertJsonCount(12, 'data');
        $muchas = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($pocas, $muchas, 'Los interlocutores y los avances se cargan en bloque.');
    }

    public function test_correo_no_admite_destinatarios_inactivos_externos_o_repetidos(): void
    {
        $a = $this->persona();
        $inactivo = User::factory()->create(['activo' => false]);
        $cliente = User::factory()->create(['rol' => RolUsuario::Cliente]);
        $b = $this->persona('B');
        foreach ([[$a], [$inactivo], [$cliente], [$b, $b]] as $lista) {
            $this->correo($a, $lista)->assertUnprocessable();
        }
        $this->assertDatabaseCount('com_correos', 0);
        $this->assertDatabaseCount('com_destinatarios', 0);
    }
}
