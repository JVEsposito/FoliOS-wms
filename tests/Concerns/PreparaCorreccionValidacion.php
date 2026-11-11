<?php

namespace Tests\Concerns;

use App\Enums\RolUsuario;
use App\Models\Dispositivo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Catálogo mínimo, PDA y tokens para validar un pallet y corregirlo desde Oficina. */
trait PreparaCorreccionValidacion
{
    protected function contexto(): array
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
    protected function payloadValidacion(array $catalogo, string $folio): array
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
    protected function payloadCorreccion(array $catalogo, string $operacionId): array
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

    protected function tokenOficina(User $usuario): string
    {
        return $usuario->createToken('oficina-test', ['oficina'])->plainTextToken;
    }

    protected function conToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
