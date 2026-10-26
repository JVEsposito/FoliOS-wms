<?php

use App\Services\Materiales\NombreClasificacionMaterial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ([
                'items_materiales' => ['nombre' => 180, 'categoria' => 100],
                'categorias_comerciales_materiales' => ['nombre' => 100],
                'tipos_items_materiales' => ['nombre' => 100],
                'unidades_materiales' => ['nombre' => 100],
            ] as $tabla => $campos) {
                DB::table($tabla)->select(['id', ...array_keys($campos)])->chunkById(500, function ($filas) use ($tabla, $campos): void {
                    foreach ($filas as $fila) {
                        $cambios = [];
                        foreach ($campos as $campo => $maximo) {
                            if ($fila->$campo === null) {
                                continue;
                            }
                            $nombre = NombreClasificacionMaterial::mayusculas($fila->$campo);
                            if (mb_strlen($nombre) > $maximo) {
                                throw new RuntimeException("Revisa el texto de {$tabla}.{$campo}, registro {$fila->id}: las mayúsculas exceden {$maximo} caracteres.");
                            }
                            if ($nombre !== $fila->$campo) {
                                $cambios[$campo] = $nombre;
                            }
                        }
                        if ($cambios !== []) {
                            DB::table($tabla)->where('id', $fila->id)->update($cambios);
                        }
                    }
                });
            }
            // Las habilitaciones de proveedores referencian la categoría por texto.
            DB::table('clientes_proveedores_materiales')->whereNotNull('categorias')->select(['id', 'categorias'])
                ->chunkById(500, function ($filas): void {
                    foreach ($filas as $fila) {
                        $categorias = json_decode($fila->categorias, true, flags: JSON_THROW_ON_ERROR);
                        $nuevas = array_map(NombreClasificacionMaterial::mayusculas(...), $categorias);
                        if ($nuevas !== $categorias) {
                            DB::table('clientes_proveedores_materiales')->where('id', $fila->id)
                                ->update(['categorias' => json_encode($nuevas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                        }
                    }
                });
        });
    }

    public function down(): void
    {
        // La caja original no es recuperable. Revertir código conserva nombres e identidades.
    }
};
