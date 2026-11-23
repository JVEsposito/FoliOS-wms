<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fila única de configuración del asistente. La crea la migración; si faltara, se
 * recrea con los valores por defecto.
 */
#[Fillable(['margen_desviaciones', 'modo'])]
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
        return ['margen_desviaciones' => 'float'];
    }

    public static function actual(): self
    {
        return self::query()->oldest()->first()
            ?? self::query()->create(['margen_desviaciones' => 2, 'modo' => self::MODO_SIN_IA]);
    }
}
