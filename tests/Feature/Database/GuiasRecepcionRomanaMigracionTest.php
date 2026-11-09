<?php

namespace Tests\Feature\Database;

use App\Enums\RolUsuario;
use App\Models\Cliente;
use App\Models\EspecieValidacion;
use App\Models\GuiaRecepcionRomana;
use App\Models\MovimientoEnvase;
use App\Models\SegmentoValidacionMp;
use App\Models\Temporada;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\EjecutaSolicitudesConcurrentes;
use Tests\Concerns\PreparaInspeccionesEnvases;
use Tests\Concerns\PreparaRecepcionEnvases;
use Tests\TestCase;

class GuiasRecepcionRomanaMigracionTest extends TestCase
{
    use DatabaseMigrations, EjecutaSolicitudesConcurrentes, PreparaInspeccionesEnvases, PreparaRecepcionEnvases;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            try {
                $this->refreshTestDatabase();
            } finally {
                RefreshDatabaseState::$migrated = false;
            }
        });
    }

    public function test_migra_textos_historicos_y_vincula_segmentos_sin_cambiar_pesos_cantidades_ni_movimientos(): void
    {
        $c = $this->preparar(['bins' => 4]);
        $borrada = $this->preparar(['bins' => 2], confirmar: false);
        $movimientos = MovimientoEnvase::orderBy('id')->get()->toArray();
        $migracion = require database_path('migrations/2026_11_09_140000_desglosar_guias_recepcion_romana.php');
        $migracion->down();
        DB::table('recepciones_romana')->where('id', $c['recepcion']->id)->update(['numero_guia_despacho' => '1511 - 1512']);
        DB::table('recepciones_romana')->where('id', $borrada['recepcion']->id)->update(['eliminada_at' => now(), 'guia_vigente' => null]);
        $anteriores = DB::table('recepciones_romana')->orderBy('id')->get()->toArray();
        $migracion->up();
        $guia = GuiaRecepcionRomana::where('recepcion_romana_id', $c['recepcion']->id)->sole();
        $this->assertSame('1511 - 1512', $guia->numero_guia_despacho);
        $this->assertEquals([['tipo_envase' => 'bins', 'cantidad' => 4]], $guia->envases);
        $this->assertEquals($guia->envases, $guia->envases_validados);
        $this->assertSame(2, SegmentoValidacionMp::where('guia_recepcion_romana_id', $guia->id)->count());
        $this->assertNull(GuiaRecepcionRomana::where('recepcion_romana_id', $borrada['recepcion']->id)->sole()->vigente);
        $this->assertEquals($anteriores, DB::table('recepciones_romana')->orderBy('id')->get()->toArray());
        $this->assertSame($movimientos, MovimientoEnvase::orderBy('id')->get()->toArray());
    }

    public function test_dos_camiones_simultaneos_no_pueden_compartir_una_guia_secundaria(): void
    {
        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana, 'activo' => true]);
        $token = $operador->createToken('romana-concurrente', ['oficina'])->plainTextToken;
        $temporada = Temporada::where('activa', true)->firstOrFail();
        $cliente = Cliente::create(['codigo' => 'GUIA-CONC', 'nombre' => 'Cliente concurrencia', 'activo' => true]);
        $especie = EspecieValidacion::firstOrCreate(['temporada_id' => $temporada->id, 'nombre' => 'Cereza'], ['activo' => true]);
        $envases = [['tipo_envase' => 'bins', 'cantidad' => 1]];
        $payload = ['temporada_id' => $temporada->id, 'cliente_id' => $cliente->id, 'tipo_recepcion' => 'fruta_con_envases',
            'especie_validacion_id' => $especie->id, 'tipo_servicio' => 'proceso', 'envases' => [['tipo_envase' => 'bins', 'cantidad' => 2]],
            'patente_camion' => 'ABCD12', 'tipo_camion' => 'plano', 'rut_conductor' => '12.345.678-5', 'nombre_conductor' => 'Conductor', 'peso_bruto' => 10000];
        $solicitudes = [];
        foreach (['PRIMERA-A', 'PRIMERA-B'] as $numero) {
            $solicitudes[] = ['POST', '/api/romana/recepciones', $token, [...$payload, 'operacion_id' => (string) Str::uuid(),
                'guias' => [['numero_guia_despacho' => $numero, 'envases' => $envases], ['numero_guia_despacho' => 'COMPARTIDA', 'envases' => $envases]]]];
        }
        $resultados = $this->solicitudesConcurrentes($solicitudes);
        $estados = array_column($resultados, 'status');
        sort($estados);
        $this->assertSame([201, 409], $estados, json_encode($resultados));
        $this->assertDatabaseCount('recepciones_romana', 1);
        $this->assertDatabaseCount('guias_recepcion_romana', 2);
    }
}
