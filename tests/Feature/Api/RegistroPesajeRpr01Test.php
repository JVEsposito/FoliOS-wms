<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\FormatoRegistro;
use App\Models\RecepcionRomana;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistroPesajeRpr01Test extends TestCase
{
    use RefreshDatabase;

    private const CAMPOS = [
        'N° recepción', 'Ingreso', 'Salida / destare', 'Cliente', 'Código cliente',
        'Tipo recepción', 'Servicio / concepto', 'Guía de despacho', 'Envases declarados',
        'Patente camión', 'Patente carro', 'Conductor', 'RUT conductor',
        'Peso bruto', 'Tara camión / envases', 'PESO NETO',
    ];

    public function test_rpr01_respeta_campos_orden_firmas_y_logo_sin_incluir_datos_de_salida(): void
    {
        $operador = User::factory()->create(['name' => 'Ana Romana', 'rol' => RolUsuario::OperadorRomana]);
        $recepcion = $this->crearRecepcion($operador);
        $this->cerrar($recepcion, $operador);
        $pdf = $this->pdf($recepcion, $operador);
        $textos = $this->textos($pdf);
        $this->assertSame(self::CAMPOS, array_values(array_intersect($textos, self::CAMPOS)));
        foreach (['RPR-01', '1', '31-08-2026', 'Operador de romana', 'Transportista', 'Jefe Frigorífico', 'Ana Romana', 'María González', '30 Bins · 720 Totes · 10 Esponjas'] as $esperado) {
            $this->assertContains($esperado, $textos);
        }
        foreach (['Guía de salida', 'GS-NO-IMPRIMIR', 'Salida de envases', 'Envases de salida', 'Tipo de camión', 'Camión termo', 'Envases validados', 'tara/u'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, implode('\n', $textos));
        }
        // La tara efectiva incluye la diferencia de todos los envases: bruto - tara = neto.
        $this->assertContains('10.540,000 kg', $textos);
        $this->assertContains('18.000,000 kg', $textos);
        $this->assertStringContainsString('/Subtype /Image', $pdf);
        $this->assertStringContainsString('/DCTDecode', $pdf);
        $this->assertStringContainsString(file_get_contents(resource_path('images/logo-agrorosario.jpg')), $pdf);
    }

    public function test_conserva_version_localidad_y_fecha_al_reimprimir_y_nuevos_cierres_usan_version_publicada(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $primera = $this->crearRecepcion($operador);
        $operacion = $this->cerrar($primera, $operador);
        $historica = $this->crearRecepcion($operador);
        DB::table('recepciones_romana')->where('id', $historica->id)->update(['estado' => 'cerrado', 'salida_at' => now()]);
        $admin = User::factory()->create(['rol' => RolUsuario::Administrador]);
        $formato = FormatoRegistro::query()->where('codigo', 'RPR-01')->firstOrFail();
        $this->actingAs($admin, 'sanctum')->putJson('/api/administracion/formatos-registro/'.$formato->id, [
            'codigo' => 'RPR-01', 'nombre' => $formato->nombre, 'version' => '2',
            'fecha_vigencia' => '2026-10-02', 'localidad' => 'Nueva localidad', 'activo' => true,
            'actualizado_at_conocido' => $formato->updated_at->toAtomString(),
        ])->assertOk();
        $this->cerrar($primera, $operador, $operacion);
        $segunda = $this->crearRecepcion($operador);
        $this->cerrar($segunda, $operador);
        $this->assertSame('1', $primera->refresh()->formato_registro_version);
        $this->assertSame('2', $segunda->refresh()->formato_registro_version);
        $this->assertSame('2026-08-31', $primera->formato_registro_fecha_vigencia->format('Y-m-d'));
        $this->assertSame('2026-10-02', $segunda->formato_registro_fecha_vigencia->format('Y-m-d'));
        $primero = $this->textos($this->pdf($primera, $operador));
        $nuevo = $this->textos($this->pdf($segunda, $operador));
        $legado = $this->textos($this->pdf($historica, $operador));
        $this->assertContains('31-08-2026', $primero);
        $this->assertContains('31-08-2026', $legado);
        $this->assertNotContains('02-10-2026', $primero);
        $this->assertContains('02-10-2026', $nuevo);
        $this->assertStringContainsString('NUEVA LOCALIDAD', implode(' ', $nuevo));
        $this->assertStringNotContainsString('NUEVA LOCALIDAD', implode(' ', $primero));
        $this->assertNull($historica->refresh()->formato_registro_version); // Imprimir no reescribe el histórico.
    }

    public function test_recepcion_sin_destare_deja_salida_tara_y_neto_vacios_y_no_guarda_snapshot_al_imprimir(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $recepcion = $this->crearRecepcion($operador);
        // Un pesaje acumulativo abierto puede tener ceros de resumen; no son un destare.
        DB::table('recepciones_romana')->where('id', $recepcion->id)->update(['peso_tara' => 0, 'peso_neto' => 0]);
        $textos = $this->textos($this->pdf($recepcion, $operador));
        foreach (['Salida / destare', 'Tara camión / envases', 'PESO NETO'] as $campo) {
            $this->assertSame('', $textos[array_search($campo, $textos, true) + 1]);
        }
        $this->assertNotContains('0,000 kg', $textos);
        $this->assertNull($recepcion->refresh()->formato_registro_version);
    }

    public function test_formato_en_blanco_usa_mismo_encabezado_y_campos_del_registro(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $pdf = $this->actingAs($operador, 'sanctum')->get('/api/romana/registro-pesaje/en-blanco')
            ->assertOk()->getContent();
        $textos = $this->textos($pdf);
        $this->assertSame(self::CAMPOS, array_values(array_intersect($textos, self::CAMPOS)));
        foreach (['RPR-01', '1', '31-08-2026', 'Jefe Frigorífico'] as $esperado) {
            $this->assertContains($esperado, $textos);
        }
        foreach (self::CAMPOS as $campo) {
            $this->assertSame('', $textos[array_search($campo, $textos, true) + 1]);
        }
        $this->assertStringContainsString('/DCTDecode', $pdf);
    }

    private function crearRecepcion(User $usuario): RecepcionRomana
    {
        $temporada = Temporada::query()->where('activa', true)->firstOrFail();
        $cliente = Cliente::firstOrCreate(['codigo' => 'RPR-CLIENTE'], ['nombre' => 'Cliente de prueba', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $id = $this->actingAs($usuario, 'sanctum')->postJson('/api/romana/recepciones', [
            'operacion_id' => (string) Str::uuid(), 'temporada_id' => $temporada->id,
            'cliente_id' => $cliente->id, 'especie_validacion_id' => $especie->id,
            'tipo_recepcion' => 'fruta_con_envases', 'tipo_servicio' => 'prefrio',
            'envases' => [['tipo_envase' => 'bins', 'cantidad' => 30], ['tipo_envase' => 'totes', 'cantidad' => 720], ['tipo_envase' => 'esponjas', 'cantidad' => 10]],
            'numero_guia_despacho' => 'GD-'.Str::uuid(), 'patente_camion' => 'ABCD12',
            'tipo_camion' => 'termo', 'patente_carro' => 'WXYZ34',
            'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'María González',
            'peso_bruto' => 28540, 'observacion' => 'Carga sellada en origen.',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/romana/recepciones/'.$id.'/confirmar-ingreso', ['operacion_id' => (string) Str::uuid()])->assertOk();
        // Fixture de MP: las reglas del flujo de validación se cubren por su propia API.
        DB::table('detalles_envases_recepcion_romana')->where('recepcion_romana_id', $id)->update(['cantidad_validada' => DB::raw('cantidad_declarada')]);
        DB::table('recepciones_romana')->where('id', $id)->update(['estado_validacion_mp' => 'validada']);

        return RecepcionRomana::findOrFail($id);
    }

    private function cerrar(RecepcionRomana $recepcion, User $usuario, ?string $operacion = null): string
    {
        $operacion ??= (string) Str::uuid();
        $this->actingAs($usuario, 'sanctum')->postJson('/api/romana/recepciones/'.$recepcion->id.'/cerrar', [
            'operacion_id' => $operacion, 'modo_salida_envases' => 'mismos', 'numero_guia_salida' => 'GS-NO-IMPRIMIR',
            'peso_tara' => 10540, 'tipo_envase_calculo_neto' => 'bins',
            'taras_envases' => [['tipo_envase' => 'bins', 'tara_unitaria' => 40], ['tipo_envase' => 'totes', 'tara_unitaria' => 1], ['tipo_envase' => 'esponjas', 'tara_unitaria' => 0.1]],
        ])->assertOk();

        return $operacion;
    }

    private function pdf(RecepcionRomana $recepcion, User $usuario): string
    {
        return $this->actingAs($usuario, 'sanctum')->get('/api/romana/recepciones/'.$recepcion->id.'/aviso-recibo')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    }

    /** @return array<int, string> */
    private function textos(string $pdf): array
    {
        preg_match_all('/\(((?:\\\\.|[^\\\\()])*)\) Tj/', $pdf, $coincidencias);

        return array_map(fn (string $valor): string => iconv('Windows-1252', 'UTF-8', strtr($valor, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\'])), $coincidencias[1]);
    }
}
