<?php

namespace Tests\Feature\Api;

use App\Enums\RolUsuario;
use App\Models\ClasificacionTemporada;
use App\Models\ClienteMaterial;
use App\Models\ItemMaterial;
use App\Models\Temporada;
use App\Models\User;
use App\Services\Temporadas\ServicioMigracionTemporada;
use App\Services\Temporadas\ServicioTemporadaGlobal;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClasificacionTemporadaApiTest extends TestCase
{
    use RefreshDatabase;

    /** Tablas que la clasificación puede tocar: la temporada misma y su historial. */
    private const TABLAS_DE_LA_CLASIFICACION = ['temporadas', 'clasificaciones_temporada'];

    public function test_migracion_copia_niveles_de_stock_de_los_items(): void
    {
        $admin = $this->administrador();
        $cliente = ClienteMaterial::where('codigo', 'GENERAL')->firstOrFail();
        $item = ItemMaterial::create(['cliente_material_id' => $cliente->id, 'codigo' => 'CON-NIVELES', 'nombre' => 'Film con niveles',
            'categoria_operacional' => 'insumo', 'unidad_medida' => 'kg', 'activo' => true, 'stock_minimo' => 10, 'punto_reorden' => 25, 'stock_maximo' => 100,
            'creado_por_user_id' => $admin->id, 'actualizado_por_user_id' => $admin->id]);
        $destino = $this->temporada('SIGUIENTE-01');
        app(ServicioMigracionTemporada::class)->migrar(Temporada::where('activa', true)->firstOrFail(), $destino, ['copiar_catalogo_materiales' => true], $admin);
        $copia = ItemMaterial::where('codigo', $item->codigo)->whereHas('cliente.temporada', fn ($q) => $q->where('temporada_id', $destino->id))->sole();
        $this->assertSame($item->only(['stock_minimo', 'punto_reorden', 'stock_maximo']), $copia->only(['stock_minimo', 'punto_reorden', 'stock_maximo']));
    }

    public function test_declarar_prueba_solo_cambia_el_tipo_y_deja_intactos_la_activa_y_materiales(): void
    {
        $administrador = $this->administrador();
        $ensayo = $this->temporada('ENSAYO-01', ['fecha_inicio' => '2025-10-01', 'fecha_fin' => '2026-02-28']);
        // Materiales migró desde la temporada de ensayo hacia la activa.
        app(ServicioMigracionTemporada::class)->migrar(
            $ensayo,
            Temporada::query()->where('activa', true)->firstOrFail(),
            ['copiar_catalogo_materiales' => true],
            $administrador,
        );
        $activa = Temporada::query()->where('activa', true)->firstOrFail()->getAttributes();
        $antes = $this->fotografia();

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$ensayo->id}/declarar-prueba", [
                'motivo' => 'Temporada creada para ensayos del sistema.',
            ])
            ->assertOk()
            ->assertJsonPath('data.tipo', 'prueba')
            ->assertJsonPath('data.clasificacion.tipo_nuevo', 'prueba')
            ->assertJsonPath('data.clasificacion.clasificado_por', $administrador->name);

        $this->assertSame($antes, $this->fotografia(), 'Declarar prueba no debe cambiar ninguna otra tabla.');
        $this->assertSame($activa, Temporada::query()->where('activa', true)->firstOrFail()->getAttributes());
        $this->assertDatabaseHas('temporadas_materiales', ['temporada_id' => $ensayo->id]);
        $this->assertDatabaseHas('migraciones_temporadas', ['temporada_origen_id' => $ensayo->id]);
        $this->assertDatabaseHas('clasificaciones_temporada', [
            'temporada_id' => $ensayo->id,
            'tipo_anterior' => 'productiva',
            'tipo_nuevo' => 'prueba',
            'clasificado_por_user_id' => $administrador->id,
        ]);
    }

    public function test_la_temporada_activa_no_se_declara_prueba_y_el_motivo_es_obligatorio(): void
    {
        $administrador = $this->administrador();
        $activa = Temporada::query()->where('activa', true)->firstOrFail();
        $ensayo = $this->temporada('ENSAYO-02');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$activa->id}/declarar-prueba", [
                'motivo' => 'Intento sobre la temporada vigente.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('codigo', 'regla_de_negocio');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$ensayo->id}/declarar-prueba", ['motivo' => 'corto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('motivo');

        $operador = User::factory()->create(['rol' => RolUsuario::OperadorRomana]);
        $this->actingAs($operador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$ensayo->id}/declarar-prueba", [
                'motivo' => 'Un operador no administra temporadas.',
            ])
            ->assertForbidden();

        $this->assertSame(0, ClasificacionTemporada::query()->count());
    }

    public function test_una_temporada_de_prueba_no_se_activa_ni_por_migracion(): void
    {
        $administrador = $this->administrador();
        $ensayo = $this->temporada('ENSAYO-03');
        $this->declarar($administrador, $ensayo, 'prueba');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$ensayo->id}/activar")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'La temporada ENSAYO-03 es de prueba y no puede activarse. Declárala productiva antes de activarla.');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$ensayo->id}/migrar", [
                'temporada_origen_id' => Temporada::query()->where('activa', true)->value('id'),
                'copiar_catalogo_validacion' => false,
                'copiar_catalogo_materiales' => true,
                'migrar_inventario_materiales' => false,
                'activar_destino' => true,
            ])
            ->assertUnprocessable();

        $this->assertFalse($ensayo->refresh()->activa);
    }

    public function test_activar_exige_prefijo_documental(): void
    {
        $administrador = $this->administrador();
        $siguiente = $this->temporada('2027-2028', [
            'fecha_inicio' => '2027-10-01',
            'fecha_fin' => '2028-02-28',
            'prefijo_documental' => null,
        ]);

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$siguiente->id}/activar")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Registra el prefijo documental de la temporada 2027-2028 antes de activarla.');

        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/temporadas/{$siguiente->id}", [
                'codigo' => '2027-2028',
                'nombre' => 'Temporada 2027-2028',
                'fecha_inicio' => '2027-10-01',
                'fecha_fin' => '2028-02-28',
                'prefijo_documental' => 't28',
            ])
            ->assertOk()
            ->assertJsonPath('data.prefijo_documental', 'T28');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$siguiente->id}/activar")
            ->assertOk()
            ->assertJsonPath('data.activa', true);
    }

    public function test_las_productivas_no_se_cruzan_y_una_de_prueba_si_puede(): void
    {
        $administrador = $this->administrador();
        $this->temporada('2026-2027', ['fecha_inicio' => '2026-10-01', 'fecha_fin' => '2027-02-28']);
        $cruce = [
            'codigo' => 'ENSAYO-CRUCE',
            'nombre' => 'Ensayo con fechas cruzadas',
            'fecha_inicio' => '2026-12-01',
            'fecha_fin' => '2027-01-31',
        ];

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', $cruce)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Las fechas se cruzan con la temporada productiva 2026-2027 (01-10-2026 a 28-02-2027). Ajusta las fechas o, si 2026-2027 fue de ensayo, declárala temporada de prueba.');

        $ensayoId = $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [...$cruce, 'tipo' => 'prueba'])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'prueba')
            ->json('data.id');

        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$ensayoId}/declarar-productiva", [
                'motivo' => 'Se intentó volver a productiva con fechas cruzadas.',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('temporadas', ['id' => $ensayoId, 'tipo' => 'prueba']);
    }

    public function test_productiva_requiere_fechas_y_el_tipo_solo_se_elige_al_crear(): void
    {
        $administrador = $this->administrador();

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', ['codigo' => 'SIN-FECHAS', 'nombre' => 'Sin fechas'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fecha_inicio', 'fecha_fin']);

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/administracion/temporadas', [
                'codigo' => 'ENSAYO-SIN-FECHAS',
                'nombre' => 'Ensayo sin vigencia',
                'tipo' => 'prueba',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'prueba')
            ->assertJsonPath('data.activa', false)
            ->assertJsonPath('data.fecha_inicio', null)
            ->assertJsonPath('data.fecha_fin', null);

        $ensayo = $this->temporada('ENSAYO-04');
        $this->actingAs($administrador, 'sanctum')
            ->putJson("/api/administracion/temporadas/{$ensayo->id}", [
                'codigo' => 'ENSAYO-04',
                'nombre' => 'Temporada ENSAYO-04',
                'fecha_inicio' => $ensayo->fecha_inicio->toDateString(),
                'fecha_fin' => $ensayo->fecha_fin->toDateString(),
                'tipo' => 'prueba',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tipo');
    }

    public function test_el_prefijo_es_unico_y_de_dos_a_seis_caracteres(): void
    {
        $administrador = $this->administrador();
        $this->temporada('2026-2027', ['prefijo_documental' => 'T27']);

        foreach (['T27', 't27', 'T-27', 'T', 'TEMPORADA'] as $prefijo) {
            $this->actingAs($administrador, 'sanctum')
                ->postJson('/api/administracion/temporadas', [
                    ...$this->vigenciaProductiva(),
                    'codigo' => 'PREFIJO-'.bin2hex(random_bytes(2)),
                    'nombre' => 'Temporada con prefijo inválido',
                    'prefijo_documental' => $prefijo,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('prefijo_documental');
        }
    }

    public function test_reportes_y_selectores_operacionales_omiten_las_temporadas_de_prueba(): void
    {
        $administrador = $this->administrador();
        $ensayo = $this->temporada('ENSAYO-05');
        $this->declarar($administrador, $ensayo, 'prueba');

        $romana = $this->actingAs($administrador, 'sanctum')->getJson('/api/romana/catalogos')->assertOk();
        $this->assertNotContains($ensayo->id, collect($romana->json('temporadas'))->pluck('id'));

        $envases = $this->actingAs($administrador, 'sanctum')->getJson('/api/envases/cuenta-corriente/catalogos')->assertOk();
        $this->assertNotContains($ensayo->id, collect($envases->json('temporadas'))->pluck('id'));

        $this->actingAs($administrador, 'sanctum')
            ->getJson("/api/gerencia/resumen?temporada_id={$ensayo->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('temporada_id');

        // En Accesos y en Materiales la temporada de prueba sigue visible.
        $accesos = $this->actingAs($administrador, 'sanctum')->getJson('/api/administracion/temporadas')->assertOk();
        $this->assertSame('prueba', collect($accesos->json('data'))->firstWhere('id', $ensayo->id)['tipo']);
        $materiales = $this->actingAs($administrador, 'sanctum')->getJson('/api/administracion/materiales/temporadas')->assertOk();
        $this->assertContains('ENSAYO-05', collect($materiales->json('data'))->pluck('codigo'));
    }

    public function test_revertir_a_productiva_queda_registrado_y_el_historial_es_inmutable(): void
    {
        $administrador = $this->administrador();
        $ensayo = $this->temporada('ENSAYO-06');
        $this->declarar($administrador, $ensayo, 'prueba');
        $this->declarar($administrador, $ensayo, 'productiva');

        $this->assertSame('productiva', $ensayo->refresh()->tipo->value);
        $this->assertSame(2, ClasificacionTemporada::query()->where('temporada_id', $ensayo->id)->count());

        $this->expectException(DomainException::class);
        ClasificacionTemporada::query()->firstOrFail()->update(['motivo' => 'Motivo reescrito después.']);
    }

    private function administrador(): User
    {
        return User::factory()->create(['rol' => RolUsuario::Administrador, 'activo' => true]);
    }

    /** @param array<string, mixed> $datos */
    private function temporada(string $codigo, array $datos = []): Temporada
    {
        return app(ServicioTemporadaGlobal::class)->guardar([
            ...$this->vigenciaProductiva(),
            'codigo' => $codigo,
            'nombre' => "Temporada {$codigo}",
            ...$datos,
        ]);
    }

    private function declarar(User $administrador, Temporada $temporada, string $tipo): void
    {
        $this->actingAs($administrador, 'sanctum')
            ->postJson("/api/administracion/temporadas/{$temporada->id}/declarar-{$tipo}", [
                'motivo' => "Declarada {$tipo} durante la prueba automatizada.",
            ])
            ->assertOk();
    }

    /**
     * Cantidad de filas y huella de su contenido para cada tabla de la base,
     * salvo las que la clasificación debe modificar.
     *
     * @return array<string, array{int, string}>
     */
    private function fotografia(): array
    {
        $tablas = collect(Schema::getTables(schema: DB::connection()->getDatabaseName()))
            ->pluck('name')
            ->reject(fn (string $tabla): bool => in_array($tabla, self::TABLAS_DE_LA_CLASIFICACION, true))
            ->sort()
            ->values();

        return $tablas->mapWithKeys(function (string $tabla): array {
            $filas = DB::table($tabla)->get()->map(fn (object $fila): array => (array) $fila)
                ->sortBy(fn (array $fila): string => json_encode($fila))
                ->values();

            return [$tabla => [$filas->count(), md5((string) json_encode($filas))]];
        })->all();
    }
}
