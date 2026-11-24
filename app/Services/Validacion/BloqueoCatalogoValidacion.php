<?php

namespace App\Services\Validacion;

use App\Models\Temporada;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ordena las escrituras del catálogo de Validación sin bloquear la fila de la temporada.
 *
 * Casi todas las tablas operativas tienen una FK a temporadas, y cada inserción toma un
 * bloqueo compartido de esa fila. Si el catálogo la bloquea mientras recalcula sus
 * combinaciones, Romana, lotes y el resto de la planta esperan con él. Por eso:
 *
 * 1. `adquirir()` al empezar: espera a la escritura del catálogo en curso.
 * 2. `publicar()` como último paso: sube la versión, y la fila de la temporada queda
 *    bloqueada solo desde ahí hasta el commit, no durante el cálculo.
 *
 * Versión y proyección se confirman en la misma transacción, así que un lector ve las dos
 * o ninguna.
 */
class BloqueoCatalogoValidacion
{
    /** Devuelve la temporada leída después de obtener el bloqueo, con su versión vigente. */
    public function adquirir(string $temporadaId): Temporada
    {
        $this->exigirTransaccion();
        if (! $this->bloquear($temporadaId)) {
            // Una temporada creada después de la migración recibe su fila la primera vez.
            DB::table('bloqueos_catalogo_validacion')->insertOrIgnore(
                ['temporada_id' => $temporadaId, 'created_at' => now(), 'updated_at' => now()]);
            $this->bloquear($temporadaId);
        }

        return Temporada::query()->findOrFail($temporadaId);
    }

    public function publicar(Temporada $temporada): Temporada
    {
        $this->exigirTransaccion();
        $temporada->increment('version_catalogo');
        // La transacción ve su propia escritura: la versión devuelta es la que se confirma.
        $temporada->version_catalogo = (int) DB::table('temporadas')->where('id', $temporada->id)->value('version_catalogo');
        $temporada->syncOriginalAttribute('version_catalogo');

        return $temporada;
    }

    private function bloquear(string $temporadaId): bool
    {
        return DB::table('bloqueos_catalogo_validacion')->where('temporada_id', $temporadaId)
            ->lockForUpdate()->value('temporada_id') !== null;
    }

    private function exigirTransaccion(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El bloqueo del catálogo solo vale dentro de una transacción.');
        }
    }
}
