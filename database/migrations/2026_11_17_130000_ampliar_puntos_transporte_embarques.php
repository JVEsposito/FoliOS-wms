<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Amplía el catálogo de puertos, aeropuertos y pasos terrestres del calendario de embarques.
 * Solo agrega los códigos que faltan: no modifica puntos existentes ni los nombres guardados
 * como snapshot en embarques e instructivos.
 */
return new class extends Migration
{
    private const LOS_LIBERTADORES = 'PAS-LLI';

    private const NOMBRE_LOS_LIBERTADORES = 'Los Libertadores (Los Andes – Mendoza)';

    public function up(): void
    {
        $paises = DB::table('paises')->pluck('id', 'iso_alpha2');
        $codigos = DB::table('puertos')->pluck('codigo')->flip();
        $claves = DB::table('puertos')->get(['pais_id', 'nombre', 'tipo'])
            ->mapWithKeys(fn (object $puerto): array => [$this->clave($puerto->pais_id, $puerto->nombre, $puerto->tipo) => true]);
        $ahora = now();
        $filas = [];

        foreach ($this->catalogo() as $punto) {
            $paisId = $paises[$punto['pais_iso2']] ?? null;
            if ($paisId === null) {
                throw new RuntimeException("El punto {$punto['codigo']} referencia un país inexistente.");
            }
            $clave = $this->clave($paisId, $punto['nombre'], $punto['tipo']);
            if ($codigos->has($punto['codigo']) || $claves->has($clave)) {
                continue;
            }
            $codigos->put($punto['codigo'], true);
            $claves->put($clave, true);
            $filas[] = [
                'id' => (string) Str::uuid(),
                'pais_id' => $paisId,
                'codigo' => $punto['codigo'],
                'nombre' => $punto['nombre'],
                'tipo' => $punto['tipo'],
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        foreach (array_chunk($filas, 200) as $grupo) {
            DB::table('puertos')->insert($grupo);
        }

        // El paso existente ahora convive con su contraparte argentina (Cristo Redentor).
        DB::table('puertos')->where('codigo', self::LOS_LIBERTADORES)->where('nombre', 'Los Libertadores')
            ->update(['nombre' => self::NOMBRE_LOS_LIBERTADORES, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        DB::table('puertos')->where('codigo', self::LOS_LIBERTADORES)->where('nombre', self::NOMBRE_LOS_LIBERTADORES)
            ->update(['nombre' => 'Los Libertadores', 'updated_at' => now()]);

        $codigos = array_column($this->catalogo(), 'codigo');
        $usados = DB::table('embarques')->whereNotNull('puerto_embarque_id')->pluck('puerto_embarque_id')
            ->merge(DB::table('instructivos_embarque')->whereNotNull('puerto_destino_id')->pluck('puerto_destino_id'))
            ->unique()->all();

        foreach (array_chunk($codigos, 200) as $grupo) {
            DB::table('puertos')->whereIn('codigo', $grupo)->whereNotIn('id', $usados)->delete();
        }
    }

    /** @return list<array{codigo: string, nombre: string, pais_iso2: string, tipo: string}> */
    private function catalogo(): array
    {
        return json_decode(
            file_get_contents(database_path('data/puertos_embarque_ampliacion.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function clave(string $paisId, string $nombre, string $tipo): string
    {
        return $paisId.'|'.mb_strtolower(trim($nombre)).'|'.$tipo;
    }
};
