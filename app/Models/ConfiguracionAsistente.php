<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila única de configuración del asistente. La crea la migración; si faltara, se
 * recrea con los valores por defecto.
 */
#[Fillable(['margen_desviaciones', 'modo', 'tope_mensual_usd', 'limite_diario_usuario', 'usuarios_piloto'])]
class ConfiguracionAsistente extends Model
{
    use HasUuids;

    public const MODO_SIN_IA = 'sin_ia';

    /** Las reglas responden lo que entienden con certeza; el resto va a Haiku. */
    public const MODO_MIXTO = 'mixto';

    public const MODOS = [self::MODO_SIN_IA, self::MODO_MIXTO];

    protected $table = 'asistente_configuracion';

    protected function casts(): array
    {
        return [
            'margen_desviaciones' => 'float',
            'tope_mensual_usd' => 'float',
            'limite_diario_usuario' => 'integer',
            'usuarios_piloto' => 'array',
        ];
    }

    /** Tope de gasto en Haiku del mes calendario, en US$: el de Administración o el del .env. */
    public function topeMensual(): float
    {
        return $this->tope_mensual_usd ?? (float) config('asistente.topes.mensual_usd');
    }

    /** Llamadas a Haiku por usuario y jornada. */
    public function limiteDiario(): int
    {
        return $this->limite_diario_usuario ?? (int) config('asistente.topes.diario_usuario');
    }

    /** Sin lista de piloto, Haiku es para todos los usuarios del asistente. */
    public function enPiloto(User $usuario): bool
    {
        $piloto = array_map('intval', $this->usuarios_piloto ?? []);

        return $piloto === [] || in_array((int) $usuario->id, $piloto, true);
    }

    public static function actual(): self
    {
        return self::query()->oldest()->first()
            ?? self::query()->create(['margen_desviaciones' => 2, 'modo' => self::MODO_SIN_IA]);
    }
}
