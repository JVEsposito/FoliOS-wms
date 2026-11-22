<?php

namespace App\Models;

use App\Services\Asistente\TextoNormalizado;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Rango normal de neto por envase con fruta. Un rango de la especie tiene prioridad
 * sobre el general (especie nula).
 */
#[Fillable(['tipo_envase', 'especie', 'minimo_kg', 'maximo_kg', 'activo'])]
class RangoTaraAsistente extends Model
{
    use HasUuids;

    protected $table = 'asistente_rangos_tara';

    protected function casts(): array
    {
        return [
            'minimo_kg' => 'float',
            'maximo_kg' => 'float',
            'activo' => 'boolean',
        ];
    }

    public static function vigentePara(string $tipoEnvase, ?string $especie): ?self
    {
        $rangos = self::query()->where('tipo_envase', $tipoEnvase)->where('activo', true)->get();
        $buscada = $especie === null ? null : TextoNormalizado::de($especie);

        if ($buscada !== null) {
            $propio = $rangos->first(fn (self $rango): bool => $rango->especie !== null
                && TextoNormalizado::de($rango->especie) === $buscada);
            if ($propio) {
                return $propio;
            }
        }

        return $rangos->first(fn (self $rango): bool => $rango->especie === null);
    }

    public function contiene(float $valor): bool
    {
        return $valor >= $this->minimo_kg && $valor <= $this->maximo_kg;
    }
}
