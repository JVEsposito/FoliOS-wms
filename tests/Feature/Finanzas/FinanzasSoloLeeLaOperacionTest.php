<?php

namespace Tests\Feature\Finanzas;

use App\Enums\RolUsuario;
use App\Finanzas\Models\Hecho;
use App\Models\Dispositivo;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Finanzas bebe de la operación sin tocarla: captura lo que dejan los flujos reales y
 * sobre la base operativa solo ejecuta consultas.
 */
class FinanzasSoloLeeLaOperacionTest extends TestCase
{
    use PreparaOperacionFinanzas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararFinanzas();
    }

    public function test_captura_lo_que_registra_validacion_y_su_anulacion_solo_con_consultas(): void
    {
        $catalogo = $this->catalogoValidacion();
        $tokenValidador = $this->token(RolUsuario::Validador, 'FIN-VAL-01');
        $tokenSupervisor = $this->token(RolUsuario::SupervisorFrio, 'FIN-SUP-01');

        $validacionId = $this->withToken($tokenValidador)->postJson('/api/validacion/pallets', [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => 'FIN-PT-0001',
            'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 120,
            'linea_proceso' => 1,
            'turno' => 'A',
            ...$catalogo,
            'resultado' => 'aprobado',
            'motivo' => null,
            'observacion' => null,
            'generado_dispositivo_at' => now()->toAtomString(),
        ])->assertCreated()->json('data.id');

        $sentencias = $this->sentenciasOperacion(fn () => $this->capturar());

        $hecho = $this->hechos('validacion_pt')->sole();
        $this->assertSame($validacionId, $hecho->origen_id);
        $this->assertSame('FIN-PT-0001', $hecho->referencia);
        $this->assertSame('EXPORTADORA DEMO', $hecho->cliente_nombre);
        $this->assertSame(120, $hecho->medidas['cajas']);
        $this->assertNotEmpty($sentencias);
        $this->assertSame([], array_values(array_filter(
            $sentencias,
            fn (string $sql): bool => ! preg_match('/^\s*select\b/i', $sql),
        )), 'Finanzas solo consulta la base operativa.');

        $this->travel(5)->minutes();
        $this->withToken($tokenSupervisor)->postJson("/api/validacion/pallets/{$validacionId}/anular", [
            'operacion_id' => (string) Str::uuid(),
            'motivo_categoria' => 'folio_incorrecto',
            'motivo' => 'El número físico del pallet fue digitado incorrectamente.',
        ])->assertOk();

        $this->capturar();

        $versiones = $this->hechos('validacion_pt', soloVigentes: false);
        $this->assertSame([1, 2], $versiones->pluck('version')->all());
        $this->assertSame(Hecho::ESTADO_ANULADO, $versiones->last()->estado);
        $this->assertSame(Hecho::MOTIVO_ANULACION, $versiones->last()->motivo_version);
    }

    public function test_la_conexion_de_lectura_rechaza_escrituras(): void
    {
        $lectura = DB::connection('operacion_lectura');

        try {
            $this->assertGreaterThan(0, $lectura->table('temporadas')->count());

            try {
                $lectura->table('temporadas')->insert([
                    'id' => (string) Str::uuid(), 'codigo' => 'FIN-RO', 'nombre' => 'Solo lectura', 'activa' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->fail('La conexión de lectura permitió escribir.');
            } catch (QueryException $error) {
                // 1792: Cannot execute statement in a READ ONLY transaction.
                $this->assertSame(1792, (int) $error->errorInfo[1]);
            }
        } finally {
            $lectura->disconnect();
        }
    }

    /** @return list<string> */
    private function sentenciasOperacion(callable $accion): array
    {
        $sentencias = [];
        $conexion = config('database.default');
        Event::listen(QueryExecuted::class, function (QueryExecuted $consulta) use (&$sentencias, $conexion): void {
            if ($consulta->connectionName === $conexion) {
                $sentencias[] = $consulta->sql;
            }
        });

        $accion();

        return $sentencias;
    }

    private function token(RolUsuario $rol, string $codigo): string
    {
        $usuario = User::factory()->create(['rol' => $rol]);
        $dispositivo = Dispositivo::create(['codigo' => $codigo, 'nombre' => "PDA {$codigo}", 'plataforma' => 'android', 'activo' => true]);

        return $usuario->crearTokenParaDispositivo($dispositivo, "test-{$codigo}")->plainTextToken;
    }

    /** @return array<string, string|int> */
    private function catalogoValidacion(): array
    {
        $temporada = (string) Str::uuid();
        $articulo = (string) Str::uuid();
        $origen = (string) Str::uuid();
        $categoria = (string) Str::uuid();
        $this->desactivarTemporadasDePrueba();
        DB::table('temporadas')->insert([
            'id' => $temporada, 'codigo' => '2026-2027', 'nombre' => 'Temporada 2026-2027', 'activa' => true, 'version_catalogo' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('articulos_validacion')->insert([
            'id' => $articulo, 'temporada_id' => $temporada, 'especie' => 'Cereza', 'variedad' => 'Santina', 'calibre' => '2J',
            'envase' => 'Caja 5 kg', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('categorias_validacion')->insert([
            'id' => $categoria, 'temporada_id' => $temporada, 'nombre' => 'Exportación', 'activo' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('origenes_validacion')->insert([
            'id' => $origen, 'temporada_id' => $temporada, 'cliente' => 'EXPORTADORA DEMO', 'marca' => 'MARCA PRUEBA', 'csg' => '100001',
            'predio' => 'Fundo de prueba', 'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('combinaciones_validacion')->insert([
            'id' => (string) Str::uuid(), 'temporada_id' => $temporada, 'articulo_validacion_id' => $articulo, 'origen_validacion_id' => $origen,
            'activo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'temporada_id' => $temporada,
            'articulo_validacion_id' => $articulo,
            'origen_validacion_id' => $origen,
            'categoria_validacion_id' => $categoria,
            'catalogo_version' => 1,
        ];
    }
}
