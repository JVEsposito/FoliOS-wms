<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionTermicaFolio;
use App\Enums\EstadoOperacionalFolio;
use App\Enums\HabilitacionAlmacenamientoFolio;
use App\Enums\RolUsuario;
use App\Enums\TipoBulto;
use App\Models\Dispositivo;
use App\Models\EventoPrefrio;
use App\Models\Folio;
use App\Models\ProcesoPrefrio;
use App\Models\TunelPrefrio;
use App\Models\User;
use App\Services\Prefrio\RegistroPrefrioR41;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Registro de prefrío POEMP R-41 (12-11-2026): lecturas por hora en tres sectores
 * (ventilador, medio, puerta), exterior e interior a cada lado; parciales durante el ciclo y
 * completa al terminar el enfriado. La planilla sale en Excel desde la plantilla oficial.
 */
class RegistroPrefrioR41ApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private CarbonImmutable $inicio;

    protected function setUp(): void
    {
        parent::setUp();
        $usuario = User::factory()->create(['rol' => RolUsuario::OperadorPrefrio, 'name' => 'Rosa Prefrío']);
        $dispositivo = Dispositivo::create(['codigo' => 'TAB-PF-1', 'nombre' => 'Tablet túnel', 'plataforma' => 'android', 'activo' => true]);
        $this->token = $usuario->crearTokenParaDispositivo($dispositivo, 'r41')->plainTextToken;
        $this->inicio = CarbonImmutable::now()->subHours(6)->startOfMinute();
    }

    public function test_lecturas_parciales_cada_hora_y_lectura_final_completa_al_terminar(): void
    {
        $proceso = $this->procesoEnCurso();
        $ruta = "/api/prefrio/procesos/{$proceso['id']}";

        // Una lectura horaria puede quedar parcial.
        $proceso = $this->accionR41("{$ruta}/eventos/lectura", $proceso['version'], 1, ['sectores' => ['ventilador' => ['izq_ex' => '-0,5', 'der_ex' => 1.25]]])
            ->assertOk()
            ->assertJsonPath('data.registro_r41.lecturas', 1)
            ->assertJsonPath('data.registro_r41.ultima_lectura_completa', false)
            ->assertJsonPath('data.registro_r41.intervalo_lectura_minutos', 60)
            ->json('data');
        $this->assertSame($this->inicio->addHours(2)->toAtomString(), $proceso['registro_r41']['proxima_lectura_at']);
        $guardada = EventoPrefrio::query()->where('proceso_prefrio_id', $proceso['id'])->where('tipo', 'lectura')->sole()->datos['sectores'];
        $this->assertSame([-0.5, null, null, 1.3], [$guardada['ventilador']['izq_ex'], $guardada['ventilador']['izq_in'], $guardada['medio']['izq_ex'], $guardada['ventilador']['der_ex']]);

        foreach ([
            [['ventilador' => ['izq_ex' => 55]], 'Ventilador izq. exterior: la temperatura debe estar entre -10 y 40 °C.'],
            [['techo' => ['izq_ex' => 1]], 'Los sectores de la lectura son ventilador, medio y puerta.'],
            [['medio' => []], 'Ingresa al menos una temperatura de la lectura.'],
        ] as [$sectores, $mensaje]) {
            $this->accionR41("{$ruta}/eventos/lectura", $proceso['version'], 2, ['sectores' => $sectores])
                ->assertUnprocessable()->assertJsonPath('errors.sectores.0', $mensaje);
        }
        // Las tablets anteriores envían una sola temperatura: se conserva.
        $proceso = $this->accionR41("{$ruta}/eventos/lectura", $proceso['version'], 2, ['temperatura' => 2.5, 'unidad' => '°C'])->assertOk()->json('data');

        // Sin lectura final, terminar el enfriado se rechaza; con una incompleta, dice qué falta.
        $this->accionR41("{$ruta}/verificar", $proceso['version'], 3)
            ->assertUnprocessable()
            ->assertJsonPath('errors.lectura_final.0', 'Antes de terminar el enfriado registra la lectura final: los 3 sectores (ventilador, medio y puerta), interior y exterior de ambos lados.');
        $incompleta = PrefrioApiTest::lecturaCompleta();
        unset($incompleta['puerta']['der_in'], $incompleta['medio']['izq_ex']);
        $this->accionR41("{$ruta}/verificar", $proceso['version'], 3, ['lectura_final' => $incompleta])
            ->assertUnprocessable()
            ->assertJsonPath('errors.lectura_final.0', 'La lectura final lleva los 3 sectores, interior y exterior de ambos lados. Falta: Medio izq. exterior, Puerta der. interior.');

        $proceso = $this->accionR41("{$ruta}/verificar", $proceso['version'], 6, ['lectura_final' => PrefrioApiTest::lecturaCompleta(-0.4)])
            ->assertOk()
            ->assertJsonPath('data.estado', 'pendiente_verificacion')
            ->assertJsonPath('data.registro_r41.lecturas', 3)
            ->assertJsonPath('data.registro_r41.ultima_lectura_completa', true)
            ->assertJsonPath('data.registro_r41.proxima_lectura_at', null)
            ->json('data');

        // Datos de la planilla que no salen de la operación.
        $this->withToken($this->token)->putJson("{$ruta}/registro-r41", ['temperatura_objetivo' => '0,5', 'supervisor' => 'Juan Pérez'])
            ->assertUnprocessable()->assertJsonValidationErrors('temperatura_objetivo');
        $this->withToken($this->token)->putJson("{$ruta}/registro-r41", ['temperatura_objetivo' => 0.5, 'supervisor' => ' Juan Pérez ', 'servicio' => 'Prefrío exportación', 'jefe_frio' => null])
            ->assertOk()
            ->assertJsonPath('data.registro_r41.datos.supervisor', 'Juan Pérez')
            ->assertJsonPath('data.registro_r41.datos.servicio', 'Prefrío exportación')
            ->assertJsonPath('data.version', $proceso['version']);

        // La planilla: 22 posiciones son 11 profundidades, así que van dos hojas.
        $respuesta = $this->withToken($this->token)->get("{$ruta}/registro-r41.xlsx")->assertOk();
        $this->assertSame('attachment; filename=R-41_'.$proceso['codigo'].'.xlsx', $respuesta->headers->get('content-disposition'));
        $archivo = $respuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivo));
        $this->assertStringContainsString('<sheet name="REGISTRO PREFRIO (2)"', $zip->getFromName('xl/workbook.xml'));
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $segunda = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        $celda = fn (string $xml, string $ref): ?string => preg_match('/<c r="'.$ref.'"[^>]*>(?:<is><t[^>]*>(.*?)<\/t><\/is>|<v>(.*?)<\/v>)<\/c>/', $xml, $m) ? html_entity_decode(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '')) : null;
        $this->assertSame($proceso['codigo'], $celda($hoja, 'D5'));
        $this->assertSame('PK-77, PK-78', $celda($hoja, 'D6'));
        $this->assertSame('0,5 °C', $celda($hoja, 'D7'));
        $this->assertSame('-1,5 °C', $celda($hoja, 'D8'));
        $this->assertSame('2', $celda($hoja, 'D9'));
        $this->assertSame('Juan Pérez', $celda($hoja, 'J6'));
        $this->assertSame('Cereza', $celda($hoja, 'J8'));
        $this->assertSame('Lapins, Santina', $celda($hoja, 'J9'));
        $this->assertSame('6 h 00 min', $celda($hoja, 'U8'));
        // P01 a la izquierda y P02 a la derecha, en la primera profundidad (al fondo).
        $this->assertSame(['R41-0001', 'XL', 'CAJA 5 KG'], [$celda($hoja, 'H12'), $celda($hoja, 'C12'), $celda($hoja, 'E12')]);
        $this->assertSame(['R41-0002', 'J', 'CAJA 2,5 KG'], [$celda($hoja, 'M12'), $celda($hoja, 'T12'), $celda($hoja, 'Q12')]);
        // Lectura 1 parcial, 2 de una sola temperatura y la final completa en la columna 3.
        $this->assertSame(['-0.5', '1.3', null], [$celda($hoja, 'D26'), $celda($hoja, 'G26'), $celda($hoja, 'E26')]);
        $this->assertSame([' Final:', '-0.4', '-0.7', '0.2', '-0.1'], [$celda($hoja, 'L23'), $celda($hoja, 'L26'), $celda($hoja, 'O26'), $celda($hoja, 'L28'), $celda($hoja, 'O28')]);
        $this->assertStringContainsString('Lectura 2 ('.$this->hora($this->inicio->addHours(2)).'): 2,5 °C sin sector.', $celda($hoja, 'A41'));
        // La segunda hoja sigue con la profundidad 11.
        $this->assertSame(['11', '11'], [$celda($segunda, 'B12'), $celda($segunda, 'V12')]);
    }

    public function test_la_ultima_lectura_horaria_completa_sirve_de_lectura_final_y_la_exigencia_se_puede_suspender(): void
    {
        $proceso = $this->procesoEnCurso();
        $ruta = "/api/prefrio/procesos/{$proceso['id']}";
        $proceso = $this->accionR41("{$ruta}/eventos/lectura", $proceso['version'], 5, ['sectores' => PrefrioApiTest::lecturaCompleta()])->assertOk()->json('data');
        $this->accionR41("{$ruta}/verificar", $proceso['version'], 6)->assertOk()->assertJsonPath('data.estado', 'pendiente_verificacion');

        config(['prefrio.r41.exige_lectura_final' => false]);
        $otro = $this->procesoEnCurso('TUN-B');
        $this->accionR41("/api/prefrio/procesos/{$otro['id']}/verificar", $otro['version'], 6)->assertOk();
    }

    public function test_la_lectura_horaria_vence_una_hora_despues_de_la_ultima(): void
    {
        $proceso = ProcesoPrefrio::query()->findOrFail($this->procesoEnCurso()['id']);
        $estado = fn (CarbonImmutable $ahora): array => RegistroPrefrioR41::estado($proceso, $proceso->eventos()->get(), $ahora);
        $this->assertFalse($estado($this->inicio->addMinutes(59))['lectura_vencida']);
        $this->assertTrue($estado($this->inicio->addMinutes(60))['lectura_vencida']);
    }

    /** @return array<string, mixed> */
    private function procesoEnCurso(string $codigoTunel = 'TUN-A'): array
    {
        $administrador = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $tunelId = $this->actingAs($administrador, 'sanctum')->postJson('/api/administracion/prefrio/tuneles', [
            'nombre' => "Túnel {$codigoTunel}", 'capacidad_posiciones' => 22, 'setpoint_habitual' => -1.5, 'estado_tecnico' => 'operativo',
        ])->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $tunel = TunelPrefrio::query()->findOrFail($tunelId);
        $proceso = $this->withToken($this->token)->postJson('/api/prefrio/procesos', [
            'operacion_id' => (string) Str::uuid(), 'tunel_prefrio_id' => $tunel->id, 'setpoint' => -1.5, 'duracion_objetivo_minutos' => 720,
            'formato_referencia' => 'Granel 5 kg', 'ocurrido_at' => $this->inicio->subHour()->toAtomString(),
        ])->assertCreated()->json('data');
        foreach ([[1, 'XL', 'CAJA 5 KG', 'Lapins', 'PK-77'], [2, 'J', 'CAJA 2,5 KG', 'Santina', 'PK-78']] as [$numero, $calibre, $envase, $variedad, $procesoPacking]) {
            $folio = Folio::create([
                'numero_folio' => $codigoTunel === 'TUN-A' ? sprintf('R41-%04d', $numero) : sprintf('R41B-%04d', $numero), 'tipo_bulto' => TipoBulto::Pallet,
                'estado_operacional' => EstadoOperacionalFolio::PendientePrefrio, 'condicion_termica' => CondicionTermicaFolio::PendientePrefrio,
                'habilitacion_almacenamiento' => HabilitacionAlmacenamientoFolio::NoHabilitado, 'fecha_ingreso' => now(), 'activo' => true,
                'calibre' => $calibre, 'variedad' => $variedad,
                'datos_externos' => ['especie' => 'Cereza', 'envase' => $envase, 'composicion' => [['proceso_packing' => $procesoPacking, 'cantidad_cajas' => 80]]],
            ]);
            $proceso = $this->withToken($this->token)->postJson("/api/prefrio/procesos/{$proceso['id']}/folios", [
                'operacion_id' => (string) Str::uuid(), 'version_conocida' => $proceso['version'], 'folio_id' => $folio->id,
                'posicion_tunel_prefrio_id' => $tunel->posiciones()->where('numero', $numero)->value('id'),
                'ocurrido_at' => $this->inicio->subMinutes(40)->toAtomString(),
            ])->assertOk()->json('data');
        }
        $proceso = $this->accionR41("/api/prefrio/procesos/{$proceso['id']}/confirmar-armado", $proceso['version'], -0.5);

        return $this->accionR41("/api/prefrio/procesos/{$proceso->json('data.id')}/iniciar", $proceso->json('data.version'), 0)->assertOk()->json('data');
    }

    /** Acción de prefrío a la hora de inicio más $horas. */
    private function accionR41(string $ruta, int $version, float $horas, array $datos = []): TestResponse
    {
        return $this->withToken($this->token)->postJson($ruta, [
            'operacion_id' => (string) Str::uuid(), 'version_conocida' => $version,
            'ocurrido_at' => $this->inicio->addMinutes((int) round($horas * 60))->toAtomString(),
            ...($datos === [] ? [] : ['datos' => $datos]),
        ]);
    }

    private function hora(CarbonImmutable $momento): string
    {
        return $momento->setTimezone(config('app.operational_timezone', config('app.timezone')))->format('H:i');
    }
}
