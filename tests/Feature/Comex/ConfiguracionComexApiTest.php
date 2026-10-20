<?php

namespace Tests\Feature\Comex;

use App\Comex\Models\ClienteComex;
use App\Comex\Models\TipoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracionComexApiTest extends TestCase
{
    use PreparaOperacionComex;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararComex();
    }

    /** @return array<string, mixed> */
    private function tipoValido(array $cambios = []): array
    {
        return [
            'nombre' => 'Certificado de calidad',
            'descripcion' => 'Lo pide el recibidor.',
            'momento' => 'despues_salida',
            'exigencia' => 'paises',
            'transportes' => ['maritimo', 'aereo', 'terrestre'],
            'gestiones' => ['servicio', 'propia'],
            'paises' => ['CN', 'kr'],
            'pide_numero' => true,
            'pide_fecha' => false,
            'pide_archivo' => true,
            'dato_operacion' => null,
            'activo' => true,
            ...$cambios,
        ];
    }

    public function test_crea_y_actualiza_un_documento_con_sus_destinos(): void
    {
        $admin = $this->administradorComex();

        $creado = $this->actingAs($admin)->postJson('/api/comex/configuracion/tipos', $this->tipoValido())
            ->assertCreated()->assertJsonPath('message', 'Documento «Certificado de calidad» agregado.')->json('data');
        $this->assertSame('certificado_de_calidad', $creado['codigo']);
        $this->assertSame([], $creado['transportes'], 'Todas las modalidades se guardan como «todas».');
        $this->assertSame([], $creado['gestiones']);
        $this->assertSame(['CN', 'KR'], array_column($creado['paises'], 'iso2'));
        $this->assertSame(1, $creado['version']);

        $actualizado = $this->actingAs($admin)->putJson("/api/comex/configuracion/tipos/{$creado['id']}", $this->tipoValido([
            'exigencia' => 'siempre', 'transportes' => ['maritimo'], 'gestiones' => ['propia'], 'version' => 1,
        ]))->assertOk()->json('data');
        $this->assertSame(['maritimo'], $actualizado['transportes']);
        $this->assertSame(['propia'], $actualizado['gestiones']);
        $this->assertSame([], $actualizado['paises'], 'Un documento exigido siempre no guarda destinos.');

        $this->actingAs($admin)->putJson("/api/comex/configuracion/tipos/{$creado['id']}", $this->tipoValido(['version' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_valida_destinos_nombres_y_listas(): void
    {
        $admin = $this->administradorComex();
        $this->actingAs($admin)->postJson('/api/comex/configuracion/tipos', $this->tipoValido(['paises' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['paises' => 'Elija al menos un país de destino que exija este documento.']);
        $this->actingAs($admin)->postJson('/api/comex/configuracion/tipos', $this->tipoValido(['paises' => ['ZZ']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['paises' => 'El país «ZZ» no está en el catálogo de países.']);
        $this->actingAs($admin)->postJson('/api/comex/configuracion/tipos', $this->tipoValido(['transportes' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['transportes' => 'Elija al menos una modalidad de transporte.']);

        $this->actingAs($admin)->postJson('/api/comex/configuracion/tipos', $this->tipoValido())->assertCreated();
        $this->actingAs($admin)->postJson('/api/comex/configuracion/tipos', $this->tipoValido(['nombre' => 'CERTIFICADO DE CALIDAD']))
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'Ya existe un documento con ese nombre.']);
        $this->assertSame(1, TipoDocumento::query()->count());
    }

    public function test_define_la_gestion_de_cada_cliente(): void
    {
        $admin = $this->administradorComex();
        $cliente = $this->clienteOperacion('EXPORTADORA DEMO SPA');

        $listado = collect($this->actingAs($admin)->getJson('/api/comex/configuracion')->assertOk()->json('clientes'))->keyBy('cliente_id');
        $this->assertSame('servicio', $listado[$cliente]['gestion']);
        $this->assertFalse($listado[$cliente]['configurado']);

        $this->actingAs($admin)->putJson("/api/comex/configuracion/clientes/{$cliente}", ['gestion' => 'propia', 'observacion' => 'Exportación de la empresa.', 'version' => 0])
            ->assertOk()->assertJsonPath('data.gestion', 'propia')->assertJsonPath('data.version', 1);
        $this->actingAs($admin)->putJson("/api/comex/configuracion/clientes/{$cliente}", ['gestion' => 'sin_seguimiento', 'version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('propia', ClienteComex::query()->findOrFail($cliente)->gestion);

        $this->actingAs($admin)->putJson('/api/comex/configuracion/clientes/'.fake()->uuid(), ['gestion' => 'propia', 'version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['cliente' => 'El cliente no existe en la operación.']);
    }
}
