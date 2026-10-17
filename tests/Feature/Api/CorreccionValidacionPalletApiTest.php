<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\CorreccionValidacionPallet;
use App\Models\Dispositivo;
use App\Models\Folio;
use App\Models\TrazabilidadFolioOrigen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorreccionValidacionPalletApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrador_corrige_validacion_y_folio_antes_de_prefrio(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0001'),
            )
            ->assertCreated()
            ->json('data.id');
        $operacionId = (string) Str::uuid();
        $correccion = $this->payloadCorreccion($catalogo, $operacionId);

        $this->conToken($this->tokenOficina($usuario))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $correccion,
            )
            ->assertOk()
            ->assertJsonPath('data.catalogo.articulo.envase', 'Cartón 10 kg')
            ->assertJsonPath('data.cantidad_cajas', 118)
            ->assertJsonPath('data.linea_proceso', 2)
            ->assertJsonPath('data.turno', 'B')
            ->assertJsonPath(
                'data.correcciones.0.motivo',
                'El embalaje y la cantidad se ingresaron incorrectamente.',
            );

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0001')->firstOrFail();
        $this->assertSame('Cartón 10 kg', $folio->datos_externos['envase']);
        $this->assertSame(118, $folio->datos_externos['cantidad_cajas']);
        $this->assertSame('Santina', $folio->variedad);
        $this->assertDatabaseHas('validaciones_pallet', [
            'id' => $validacionId,
            'articulo_validacion_id' => $catalogo['articulo_corregido_id'],
            'cantidad_cajas' => 118,
            'linea_proceso' => 2,
            'turno' => 'B',
        ]);
        $this->assertDatabaseHas('correcciones_validacion_pallet', [
            'operacion_id' => $operacionId,
            'validacion_pallet_id' => $validacionId,
            'folio_id' => $folio->id,
            'corregido_por_user_id' => $usuario->id,
        ]);
    }

    public function test_la_correccion_es_idempotente_ante_reintentos(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0002'),
            )
            ->assertCreated()
            ->json('data.id');
        $payload = $this->payloadCorreccion($catalogo, (string) Str::uuid());
        $tokenOficina = $this->tokenOficina($usuario);

        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", $payload)
            ->assertOk();
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", $payload)
            ->assertOk();

        $this->assertSame(1, CorreccionValidacionPallet::query()->count());
    }

    public function test_permite_corregir_en_camara_sin_alterar_el_estado_operativo(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0003'),
            )
            ->assertCreated()
            ->json('data.id');

        Folio::query()
            ->where('numero_folio', 'PAL-CORR-0003')
            ->update([
                'estado_operacional' => 'disponible',
                'condicion_termica' => 'prefrio_aprobado',
            ]);

        $this->conToken($this->tokenOficina($usuario))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $this->payloadCorreccion($catalogo, (string) Str::uuid()),
            )
            ->assertOk()
            ->assertJsonPath('data.folio.estado_operacional', 'disponible')
            ->assertJsonPath('data.folio.condicion_termica', 'prefrio_aprobado')
            ->assertJsonPath('data.catalogo.articulo.envase', 'Cartón 10 kg');

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0003')->firstOrFail();
        $this->assertSame('disponible', $folio->estado_operacional->value);
        $this->assertSame('prefrio_aprobado', $folio->condicion_termica->value);

        $correccion = CorreccionValidacionPallet::query()->firstOrFail();
        $this->assertSame(
            'disponible',
            $correccion->datos_anteriores['folio']['estado_operacional'],
        );
        $this->assertSame(
            'disponible',
            $correccion->datos_nuevos['folio']['estado_operacional'],
        );
    }

    public function test_permite_corregir_un_folio_despachado_e_inactivo(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0005'),
            )
            ->assertCreated()
            ->json('data.id');

        Folio::query()
            ->where('numero_folio', 'PAL-CORR-0005')
            ->update([
                'estado_operacional' => 'despachado',
                'condicion_termica' => 'prefrio_aprobado',
                'activo' => false,
            ]);

        $this->conToken($this->tokenOficina($usuario))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $this->payloadCorreccion($catalogo, (string) Str::uuid()),
            )
            ->assertOk()
            ->assertJsonPath('data.folio.estado_operacional', 'despachado')
            ->assertJsonPath('data.folio.activo', false)
            ->assertJsonPath('data.puede_corregir', true);

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0005')->firstOrFail();
        $this->assertFalse($folio->activo);
        $this->assertSame('despachado', $folio->estado_operacional->value);
        $this->assertSame('prefrio_aprobado', $folio->condicion_termica->value);
    }

    public function test_la_correccion_reemplaza_la_composicion_por_csg_con_lote_y_proceso(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PAL-CORR-0101'))
            ->assertCreated()
            ->json('data.id');
        $tokenOficina = $this->tokenOficina($usuario);

        $contexto = $this->conToken($tokenOficina)
            ->getJson("/api/validacion/pallets/{$validacionId}/correccion")
            ->assertOk()
            ->assertJsonPath('validacion.numero_folio', 'PAL-CORR-0101')
            ->assertJsonPath('validacion.composicion.0.origen_validacion_id', $catalogo['origen_id'])
            ->assertJsonPath('validacion.composicion.0.cantidad_cajas', 120)
            ->assertJsonPath('catalogo.temporada.id', $catalogo['temporada_id'])
            ->assertJsonPath('exige_lote_proceso', false);
        $this->assertCount(3, $contexto->json('catalogo.origenes'));
        $this->assertCount(4, $contexto->json('catalogo.combinaciones'));

        // Se agrega un segundo CSG: 80 + 40 cajas, cada línea con su lote y proceso.
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'cantidad_cajas' => 120,
                'origen_validacion_id' => null,
                'composicion' => [
                    ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 80, 'lote_materia_prima' => ' l-100 ', 'proceso_packing' => 'p-1'],
                    ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 40, 'lote_materia_prima' => 'L-200', 'proceso_packing' => 'P-2'],
                ],
                'motivo_correccion' => 'El pallet trae fruta de dos CSG.',
            ])
            ->assertOk()
            ->assertJsonPath('data.cantidad_cajas', 120);

        $folio = Folio::query()->where('numero_folio', 'PAL-CORR-0101')->firstOrFail();
        $this->assertSame('MIX', $folio->datos_externos['csg']);
        $this->assertSame(['123225', '123226'], $folio->datos_externos['csgs']);
        $this->assertSame(
            [['123225', 80, 'L-100', 'P-1'], ['123226', 40, 'L-200', 'P-2']],
            collect($folio->datos_externos['composicion'])->map(fn (array $linea): array => [
                $linea['csg'], $linea['cantidad_cajas'], $linea['lote_materia_prima'], $linea['proceso_packing'],
            ])->all(),
        );
        $this->assertSame('Cartón 10 kg', $folio->datos_externos['envase']);
        $this->assertSame(
            ['123225' => 80, '123226' => 40],
            TrazabilidadFolioOrigen::query()->where('folio_id', $folio->id)->orderBy('csg')->pluck('cantidad_cajas', 'csg')->all(),
        );

        // Un bulto con varios CSG ya se puede corregir: vuelve a un solo CSG con otro total.
        $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'composicion' => [
                    ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 118, 'lote_materia_prima' => 'L-200', 'proceso_packing' => 'P-2'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.cantidad_cajas', 118);
        $folio->refresh();
        $this->assertSame(['123226', 'Fundo Los Robles', 118], [$folio->datos_externos['csg'], $folio->datos_externos['predio'], $folio->datos_externos['cantidad_cajas']]);
        $this->assertDatabaseHas('validaciones_pallet', ['id' => $validacionId, 'origen_validacion_id' => $catalogo['origen_dos_id']]);
        $this->assertSame(1, TrazabilidadFolioOrigen::query()->where('folio_id', $folio->id)->count());
    }

    public function test_la_composicion_corregida_cumple_las_reglas_de_la_pda(): void
    {
        [$catalogo, $usuario, $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson('/api/validacion/pallets', $this->payloadValidacion($catalogo, 'PAL-CORR-0102'))
            ->assertCreated()
            ->json('data.id');
        $tokenOficina = $this->tokenOficina($usuario);
        $corregir = fn (array $composicion, int $cajas, ?string $articulo = null) => $this->conToken($tokenOficina)
            ->putJson("/api/validacion/pallets/{$validacionId}/corregir", [
                ...$this->payloadCorreccion($catalogo, (string) Str::uuid()),
                'cantidad_cajas' => $cajas,
                'articulo_validacion_id' => $articulo ?? $catalogo['articulo_corregido_id'],
                'composicion' => $composicion,
            ]);

        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 80],
            ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 30],
        ], 120)->assertUnprocessable()->assertJsonPath('message', 'La composición por CSG suma 110 cajas y el bulto declara 120.');
        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60],
            ['origen_validacion_id' => $catalogo['origen_otro_cliente_id'], 'cantidad_cajas' => 60],
        ], 120)->assertUnprocessable()->assertJsonPath('message', 'Todos los CSG del bulto deben pertenecer al mismo cliente y marca.');
        // El embalaje original no está habilitado en el segundo CSG.
        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60],
            ['origen_validacion_id' => $catalogo['origen_dos_id'], 'cantidad_cajas' => 60],
        ], 120, $catalogo['articulo_original_id'])->assertUnprocessable()->assertJsonPath('message', 'El artículo corregido no está habilitado para todos los CSG del bulto.');
        $corregir([
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60, 'lote_materia_prima' => 'L-1'],
            ['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 60, 'lote_materia_prima' => 'l-1'],
        ], 120)->assertUnprocessable()->assertJsonValidationErrors(['composicion' => 'No repitas la misma combinación de CSG, lote y proceso en el bulto.']);

        config(['validacion.exigir_lote_proceso' => true]);
        $corregir([['origen_validacion_id' => $catalogo['origen_id'], 'cantidad_cajas' => 120]], 120)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['composicion.0.lote_materia_prima' => 'Ingresa el lote de materia prima de cada línea.']);

        $this->assertSame(0, DB::table('correcciones_validacion_pallet')->count());
        $this->assertSame(120, Folio::query()->where('numero_folio', 'PAL-CORR-0102')->firstOrFail()->datos_externos['cantidad_cajas']);
    }

    public function test_supervisor_no_puede_corregir_validacion_aprobada(): void
    {
        [$catalogo, , $tokenPda] = $this->contexto();
        $validacionId = $this->conToken($tokenPda)
            ->postJson(
                '/api/validacion/pallets',
                $this->payloadValidacion($catalogo, 'PAL-CORR-0004'),
            )
            ->assertCreated()
            ->json('data.id');
        $supervisor = User::factory()->create([
            'rol' => RolUsuario::SupervisorFrio,
        ]);

        $this->conToken($this->tokenOficina($supervisor))
            ->putJson(
                "/api/validacion/pallets/{$validacionId}/corregir",
                $this->payloadCorreccion($catalogo, (string) Str::uuid()),
            )
            ->assertForbidden();

        $this->assertDatabaseCount('correcciones_validacion_pallet', 0);
    }

    /**
     * @return array{array<string, string|int>, User, string}
     */
    private function contexto(): array
    {
        $temporadaId = (string) Str::uuid();
        $articuloOriginalId = (string) Str::uuid();
        $articuloCorregidoId = (string) Str::uuid();
        $origenId = (string) Str::uuid();
        $origenDosId = (string) Str::uuid();
        $origenOtroClienteId = (string) Str::uuid();
        $categoriaId = (string) Str::uuid();
        $ahora = now();

        $this->desactivarTemporadasDePrueba();
        DB::table('temporadas')->insert([
            'id' => $temporadaId,
            'codigo' => 'TEMP-CORR',
            'nombre' => 'Temporada correcciones',
            'activa' => true,
            'version_catalogo' => 4,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('articulos_validacion')->insert([
            [
                'id' => $articuloOriginalId,
                'temporada_id' => $temporadaId,
                'especie' => 'Cereza',
                'variedad' => 'Santina',
                'calibre' => '2J',
                'envase' => 'Caja 5 kg',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $articuloCorregidoId,
                'temporada_id' => $temporadaId,
                'especie' => 'Cereza',
                'variedad' => 'Santina',
                'calibre' => '2J',
                'envase' => 'Cartón 10 kg',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
        DB::table('origenes_validacion')->insert([
            [
                'id' => $origenId,
                'temporada_id' => $temporadaId,
                'cliente' => 'MACE',
                'marca' => 'MACE',
                'csg' => '123225',
                'predio' => 'Fundo Santa Elena',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $origenDosId,
                'temporada_id' => $temporadaId,
                'cliente' => 'MACE',
                'marca' => 'MACE',
                'csg' => '123226',
                'predio' => 'Fundo Los Robles',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => $origenOtroClienteId,
                'temporada_id' => $temporadaId,
                'cliente' => 'OTRO',
                'marca' => 'OTRA',
                'csg' => '123227',
                'predio' => 'Fundo Otro',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
        DB::table('categorias_validacion')->insert([
            'id' => $categoriaId,
            'temporada_id' => $temporadaId,
            'nombre' => 'Exportación',
            'activo' => true,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        DB::table('combinaciones_validacion')->insert([
            [
                'id' => (string) Str::uuid(),
                'temporada_id' => $temporadaId,
                'articulo_validacion_id' => $articuloOriginalId,
                'origen_validacion_id' => $origenId,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => (string) Str::uuid(),
                'temporada_id' => $temporadaId,
                'articulo_validacion_id' => $articuloCorregidoId,
                'origen_validacion_id' => $origenId,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            // El segundo CSG solo tiene habilitado el embalaje corregido.
            [
                'id' => (string) Str::uuid(),
                'temporada_id' => $temporadaId,
                'articulo_validacion_id' => $articuloCorregidoId,
                'origen_validacion_id' => $origenDosId,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'id' => (string) Str::uuid(),
                'temporada_id' => $temporadaId,
                'articulo_validacion_id' => $articuloCorregidoId,
                'origen_validacion_id' => $origenOtroClienteId,
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);

        $usuario = User::factory()->create([
            'rol' => RolUsuario::Administrador,
        ]);
        $dispositivo = Dispositivo::create([
            'codigo' => 'PDA-CORR-01',
            'nombre' => 'PDA correcciones',
            'plataforma' => 'android',
            'activo' => true,
        ]);

        return [[
            'temporada_id' => $temporadaId,
            'articulo_original_id' => $articuloOriginalId,
            'articulo_corregido_id' => $articuloCorregidoId,
            'origen_id' => $origenId,
            'origen_dos_id' => $origenDosId,
            'origen_otro_cliente_id' => $origenOtroClienteId,
            'categoria_id' => $categoriaId,
            'catalogo_version' => 4,
        ], $usuario, $usuario
            ->crearTokenParaDispositivo($dispositivo, 'test-correccion')
            ->plainTextToken];
    }

    /**
     * @param  array<string, string|int>  $catalogo
     * @return array<string, mixed>
     */
    private function payloadValidacion(array $catalogo, string $folio): array
    {
        return [
            'operacion_id' => (string) Str::uuid(),
            'numero_folio' => $folio,
            'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 120,
            'linea_proceso' => 1,
            'turno' => 'A',
            'temporada_id' => $catalogo['temporada_id'],
            'catalogo_version' => $catalogo['catalogo_version'],
            'articulo_validacion_id' => $catalogo['articulo_original_id'],
            'origen_validacion_id' => $catalogo['origen_id'],
            'categoria_validacion_id' => $catalogo['categoria_id'],
            'resultado' => 'aprobado',
            'motivo' => null,
            'observacion' => null,
            'generado_dispositivo_at' => now()->toAtomString(),
        ];
    }

    /**
     * @param  array<string, string|int>  $catalogo
     * @return array<string, mixed>
     */
    private function payloadCorreccion(array $catalogo, string $operacionId): array
    {
        return [
            'operacion_id' => $operacionId,
            'tipo_bulto' => 'pallet',
            'cantidad_cajas' => 118,
            'linea_proceso' => 2,
            'turno' => 'B',
            'articulo_validacion_id' => $catalogo['articulo_corregido_id'],
            'origen_validacion_id' => $catalogo['origen_id'],
            'categoria_validacion_id' => $catalogo['categoria_id'],
            'motivo_correccion' => 'El embalaje y la cantidad se ingresaron incorrectamente.',
        ];
    }

    private function tokenOficina(User $usuario): string
    {
        return $usuario->createToken('oficina-test', ['oficina'])->plainTextToken;
    }

    private function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
